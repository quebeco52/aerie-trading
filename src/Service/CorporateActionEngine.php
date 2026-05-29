<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing corporate actions.
 * Handles Splits, Dividends, Buybacks, and Clean Surplus Balance Sheet updates.
 */
class CorporateActionEngine
{
    // Split Physics Constants
    private const FORWARD_SPLIT_THRESHOLD = 400.0;
    private const FORWARD_SPLIT_FACTOR = 4.0;
    private const REVERSE_SPLIT_THRESHOLD = 2.0;
    private const REVERSE_SPLIT_FACTOR = 10.0;
    private const MIN_SHARES_REVERSE_SPLIT = 10.0;
    private const MAX_SPLIT_MULTIPLIER = 1_000_000;

    // Corporate Cash & Liquidity Buffers
    private const TARGET_OPERATING_CASH = 0.05; // 5% of physical operating base
    private const MIN_OPERATING_CASH = 0.03;    // 3% absolute minimum before emergency borrowing

    /**
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEvent            $marketEvent   Service for publishing market events.
     * @param \Redis                 $redis         The Redis connection for managing live chart buffers.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
        private DebtEngine $debtEngine,
        private \Redis $redis,
        private MathUtility $mathUtility
    ) {}


    /**
     * Evaluates and processes potential stock splits based on the current price.
     *
     * Triggers a forward split if the price exceeds $400, or a reverse split
     * if the price falls below $2. Ensures the stock remains highly liquid and
     * tradeable without altering the underlying corporate value.
     *
     * @param Stock $stock             The stock entity to evaluate.
     * @param float $newPrice          The proposed new price.
     * @param float $sharesOutstanding The current number of shares outstanding.
     * @return array{price: float, shares: float, event: array|null} The adjusted price, shares, and any generated event.
     */
    public function processSplits(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $splitEvent = null;

        if ($newPrice >= self::FORWARD_SPLIT_THRESHOLD) {
            $result = $this->executeForwardSplit($stock, $newPrice, $sharesOutstanding);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        } elseif ($newPrice < self::REVERSE_SPLIT_THRESHOLD && $sharesOutstanding >= self::MIN_SHARES_REVERSE_SPLIT) {
            $result = $this->executeReverseSplit($stock, $newPrice, $sharesOutstanding);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        }

        return [
            'price' => $newPrice,
            'shares' => $sharesOutstanding,
            'event' => $splitEvent
        ];
    }


    /**
     * Executes a recursive forward split (e.g., 4-for-1) to bring the price back below $400.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the upper threshold.
     * @param float $sharesOutstanding The current shares outstanding.
     * @return array{price: float, shares: float, event: array}
     */
    private function executeForwardSplit(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $splitFactor = 1;
        while ($newPrice >= self::FORWARD_SPLIT_THRESHOLD && $splitFactor <= self::MAX_SPLIT_MULTIPLIER && !is_infinite($newPrice)) {
            $newPrice = $newPrice / self::FORWARD_SPLIT_FACTOR;
            $splitFactor *= self::FORWARD_SPLIT_FACTOR;
        }

        $sharesOutstanding *= $splitFactor;

        $oldDiv = (float) $stock->getLastDividend();
        $oldEps = (float) $stock->getEarningsPerShare();
        $oldFcf = (float) $stock->getFreeCashFlowPerShare();

        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $stock->setLastDividend((string) ($oldDiv / $splitFactor));
        $stock->setEarningsPerShare((string) ($oldEps / $splitFactor));
        $stock->setFreeCashFlowPerShare((string) ($oldFcf / $splitFactor));

        $desc = "{$stock->getName()} has executed a {$splitFactor}-for-1 stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'SPLIT', $desc, 0.00);

        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'UPDATE user_stocks SET quantity = quantity * :factor, version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'UPDATE stock_history SET price = GREATEST(price / :factor, 0.00000001) WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->adjustRedisBuffer($stock->getTicker(), $splitFactor, 'divide');

        return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => $splitEvent];
    }


    /**
     * Executes a recursive reverse split (e.g., 1-for-10) to bring the price back above $2.
     * Cashes out fractional shares directly to user accounts to prevent loss of wealth.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the lower threshold.
     * @param float $sharesOutstanding The current shares outstanding.
     * @return array{price: float, shares: float, event: array}
     */
    private function executeReverseSplit(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $reverseFactor = 1;
        $preSplitPrice = $newPrice;

        while ($newPrice < self::REVERSE_SPLIT_THRESHOLD && $reverseFactor <= self::MAX_SPLIT_MULTIPLIER && $newPrice > 0.0) {
            // Prevent reverse splitting if it drops shares below 1.0 (creates magical wealth and breaks per-share metrics)
            if (($sharesOutstanding / ($reverseFactor * self::REVERSE_SPLIT_FACTOR)) < 1.0) {
                break;
            }
            $newPrice = $newPrice * self::REVERSE_SPLIT_FACTOR;
            $reverseFactor *= self::REVERSE_SPLIT_FACTOR;
        }

        if ($reverseFactor === 1) {
            return ['price' => $preSplitPrice, 'shares' => $sharesOutstanding, 'event' => null];
        }

        $sharesOutstanding = $sharesOutstanding / $reverseFactor;

        $oldDiv = (float) $stock->getLastDividend();
        $oldEps = (float) $stock->getEarningsPerShare();
        $oldFcf = (float) $stock->getFreeCashFlowPerShare();

        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $stock->setLastDividend((string) ($oldDiv * $reverseFactor));
        $stock->setEarningsPerShare((string) ($oldEps * $reverseFactor));
        $stock->setFreeCashFlowPerShare((string) ($oldFcf * $reverseFactor));

        $desc = "{$stock->getName()} executed a 1-for-{$reverseFactor} reverse split.";
        $splitEvent = $this->marketEvent->publish($stock, 'REVSPLIT', $desc, 0.00);

        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON u.id = us.user_id
                 SET u.cash_balance = u.cash_balance + ((us.quantity % :factor) * :pre_split_price)
                 WHERE us.stock_id = :stock_id',
                ['factor' => $reverseFactor, 'pre_split_price' => $preSplitPrice, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'UPDATE user_stocks SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
                ['stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'UPDATE stock_history SET price = LEAST(price * :factor, 900000000000.0) WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->adjustRedisBuffer($stock->getTicker(), $reverseFactor, 'multiply');

        return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => $splitEvent];
    }


    /**
     * Mutates the live Redis chart buffer to prevent massive visual vertical spikes on the UI during a split.
     *
     * @param string $ticker The stock ticker symbol.
     * @param float $factor The split factor.
     * @param string $operation The mathematical operation to apply ('divide' for forward splits, 'multiply' for reverse splits).
     */
    private function adjustRedisBuffer(string $ticker, float $factor, string $operation): void
    {
        $cacheKey = "chart_buffer:{$ticker}";
        $redisData = $this->redis->lRange($cacheKey, 0, -1);

        if (empty($redisData)) return;

        $this->redis->del($cacheKey);

        foreach (array_reverse($redisData) as $jsonStr) {
            $point = json_decode($jsonStr, true);
            if ($operation === 'divide') {
                $point['price'] = max(0.01, $point['price'] / $factor);
            } else {
                $point['price'] = $point['price'] * $factor;
            }
            $this->redis->lPush($cacheKey, json_encode($point));
        }
    }


    /**
     * Allocates quarterly capital via dividends and buybacks, and updates the balance sheet.
     *
     * This method acts as the CFO, determining how much of the generated Free Cash Flow
     * should be returned to shareholders versus retained for future stability.
     *
     * @param Stock $stock             The stock entity allocating capital.
     * @param float $actualAnnualEps   The actual annualized EPS calculated this quarter.
     * @param float $quarterlyFcfPerShare The generated Free Cash Flow per share for the quarter.
     * @param float $currentPrice      The current market price of the stock.
     * @param float $sharesOutstanding The total shares currently outstanding.
     * @return array{new_shares: float, dividend_paid: float, events: array<mixed>} Data regarding the capital allocation.
     */
    public function allocateCapital(
        Stock $stock,
        float $actualAnnualEps,
        float $quarterlyFcfPerShare,
        float $currentPrice,
        float $sharesOutstanding,
        array $macroState,
        float $actualTotalNetIncome = 0.0
    ): array {
        $events = [];
        $oldShares = $sharesOutstanding;
        $quarterlyEps = $actualAnnualEps / 4.0;
        $quarterlyNetIncome = $actualTotalNetIncome != 0.0 ? ($actualTotalNetIncome / 4.0) : ($quarterlyEps * $oldShares);
        $currentTreasury = (float) $stock->getCorporateTreasury();
        $operatingBase = $this->mathUtility->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $investedCapital = $stock->getInvestedCapital();

        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);

        $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $nopat = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        // CALCULATE BASELINE CASH CHANGES
        // Add the FCF generated this quarter to the treasury immediately so we know what we can spend
        $totalFcfGenerated = $quarterlyFcfPerShare * $oldShares;
        $newTreasury = $currentTreasury + $totalFcfGenerated;

        // EXECUTE DIVIDENDS
        $equity = (float) $stock->getTotalEquity();
        $divData = $this->executeDividends($stock, $quarterlyEps, $oldShares, $currentPrice, $newTreasury, $operatingBase, $investedCapital, $nopat, $health, $actualTotalNetIncome);
        if ($divData['event']) $events[] = $divData['event'];

        // Subtract the dividend cash from our working treasury
        $newTreasury -= $divData['total_paid'];

        // CALCULATE EXCESS CASH (The War Chest)
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';
        $targetOperatingCash = $this->mathUtility->calculateTargetOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt(), $leverageType);
        $excessCash = max(0.0, $newTreasury - $targetOperatingCash);

        $currentPE = $actualAnnualEps > 0 ? ($currentPrice / $actualAnnualEps) : 9999.0;

        // Calculate exactly how much money was made THIS quarter that hasn't already been spent on dividends
        $retainedEarningsThisQuarter = max(0.0, $quarterlyNetIncome - $divData['total_paid']);

        // THE TRADING DESK: EXECUTE BUYBACKS
        $buybackData = $this->executeBuybacks(
            $stock,
            $excessCash,
            $oldShares,
            $currentPrice,
            $currentPE,
            $operatingBase,
            $investedCapital,
            $nopat,
            $health,
            $macroState,
            $actualTotalNetIncome,
            $retainedEarningsThisQuarter
        );
        if ($buybackData['event']) $events[] = $buybackData['event'];

        // Subtract the buyback cash from our working treasury
        $newTreasury -= $buybackData['total_cash_spent'];

        // UPDATE THE BALANCE SHEET
        $bsEvents = $this->updateBalanceSheet(
            stock: $stock,
            quarterlyNetIncome: $quarterlyNetIncome,
            totalDividendsPaid: $divData['total_paid'],
            totalBuybackCash: $buybackData['total_cash_spent'],
            operatingBase: $operatingBase,
            nopat: $nopat,
            newTreasury: $newTreasury,
            macroState: $macroState,
            health: $health
        );

        if (!empty($bsEvents['events'])) {
            $events = array_merge($events, $bsEvents['events']);
        }

        return [
            'new_shares' => $buybackData['new_shares'],
            'dividend_paid' => $divData['dividend_per_share'],
            'total_paid' => $divData['total_paid'],
            'total_cash_spent' => $buybackData['total_cash_spent'],
            'bank_apy' => $bsEvents['bank_apy'] ?? null,
            'organic_capex' => $bsEvents['organic_capex'] ?? 0.0,
            'events' => $events
        ];
    }

    /**
     * Calculates and distributes the quarterly dividend.
     * Uses the Lintner model to smoothly adjust the dividend towards the target payout ratio,
     * bounded by the actual Free Cash Flow generated.
     *
     * @param Stock $stock                The stock distributing the dividend.
     * @param float $quarterlyEps         The quarterly earnings per share.
     * @param float $shares               The number of outstanding shares.
     * @param float $currentPrice         The current stock price.
     * @param float $availableTreasury    The total cash currently available to the company.
     * @param float $operatingBase        The operating base size of the company (used for safety buffers).
     * @param float $investedCapital      The true physical capital invested in the firm.
     * @param float $nopat                Net Operating Profit After Tax.
     * @return array{dividend_per_share: float, total_paid: float, event: array|null} Data regarding the dividend execution.
     */
    private function executeDividends(Stock $stock, float $quarterlyEps, float $shares, float $currentPrice, float $availableTreasury, float $operatingBase, float $investedCapital, float $nopat, array $health, float $actualTotalNetIncome = 0.0): array
    {
        $targetPayout = (float) $stock->getTargetPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();

        // Calculate what the dividend *should* be based on current earnings
        $calculatedTarget = $quarterlyEps > 0 ? ($quarterlyEps * $targetPayout) : 0.0;

        // Asymmetric Smoothing:
        // "Aristocrats" (speed <= 5%) fiercely defend their streak and maintain the old payout even if earnings dip.
        // "Variable Payout" companies (speed > 5%) don't care about streaks and let the dividend float with earnings.
        if ($speed > 0.05) {
            $targetDividend = $calculatedTarget;
        } else {
            $targetDividend = max($calculatedTarget, $lastDividend);
        }

        // Emergency Liquidity Preservation
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';
        
        $isRegulatoryDividendHalt = false;
        if ($isLeveraged) {
            $trueReturn = (float)$stock->getTotalEquity() > 0 ? ($actualTotalNetIncome / (float)$stock->getTotalEquity()) : 0.0;
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;
            
            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['equity_limit'] ?? 10.0;
            $currentDebtToEquity = (float)$stock->getDebtToEquityRatio();
            
            if ($currentDebtToEquity > $equityLimit) {
                $isRegulatoryDividendHalt = true;
            }
        } else {
            $trueReturn = $investedCapital > 0 ? ($nopat / $investedCapital) : 0.0;
            $hurdleRate = $health['wacc'];
        }
        
        $evaSpread = $trueReturn - $hurdleRate;

        // Titans are much more stubborn about cutting dividends to save face
        $isTitan = in_array($stock->getSystemicImportance(), ['titan']);
        $isAristocrat = $speed <= 0.05;

        $distressMultiplier = 1.0;
        if ($isTitan) {
            $distressMultiplier += 0.2;
        }
        if ($isAristocrat) {
            $distressMultiplier += 0.2;
        }

        $hasCashBuffer = $availableTreasury > ($operatingBase * 0.10); // 10% buffer gives them massive confidence
        $isCriticalCash = $availableTreasury < ($operatingBase * 0.05); // 5% means they are getting dangerously close to the 3% operating limit

        // Deep distress requires a massive EVA collapse
        $isDeepDistress = $evaSpread < (-0.08 * $distressMultiplier);
        $isModerateDistressNoCash = ($evaSpread < (-0.04 * $distressMultiplier)) && !$hasCashBuffer;

        // A true liquidity crisis means operating income can't cover interest AND there's no cash to bridge the gap.
        // An ICR below 1.0 is an active cash-burn emergency regardless of reserves.
        $isLiquidityCrisis = $health['interest_coverage'] < 1.0 || ($health['interest_coverage'] < 1.5 && !$hasCashBuffer);

        if ($isDeepDistress || $isModerateDistressNoCash || $isLiquidityCrisis || $isRegulatoryDividendHalt) {
            $targetDividend = 0.0;
            $speed = ($isLiquidityCrisis || $isRegulatoryDividendHalt) ? 1.0 : min(1.0, $speed + 0.25);
        } elseif ($isCriticalCash && $calculatedTarget < $lastDividend) {
            // If they are forced to cut due to low cash, the Aristocrat streak is dead.
            $targetDividend = $calculatedTarget;
            if ($speed <= 0.05) {
                // Rip the band-aid off: Once the streak is broken, cut heavily to protect the balance sheet.
                $speed = 0.50;
            }
        }

        // The Aristocrats dividend catch up
        // Smoothly accelerate Aristocrats so they don't get trapped with a micro-yield
        if ($lastDividend > 0 && $speed <= 0.05) {
            $catchUpRatio = $calculatedTarget / $lastDividend;

            // If the target is at least 30% higher, start smoothly scaling the speed up to a max of 15%
            if ($catchUpRatio > 1.30) {
                // For every 10% above 1.30, add 0.010, capped at 0.15
                $speed = min(0.15, $speed + (($catchUpRatio - 1.30) * 0.10));
            }
        }


        $newDividend = $lastDividend + ($speed * ($targetDividend - $lastDividend));
        $newDividend = max(0.0, $newDividend);

        // Cap the dividend to what we can physically pay from cash on hand (minus a 3% operating safety buffer)
        $minOperatingCash = $this->mathUtility->calculateMinOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt(), $leverageType);
        $usableCash = max(0.0, $availableTreasury - $minOperatingCash);
        $maxDividendPerShare = $shares > 0 ? ($usableCash / $shares) : 0.0;

        // A company cannot authorize a regular dividend that obliterates its own market cap.
        // Cap the quarterly dividend to 10% of the current stock price (A massive 40% annualized yield limit).
        $maxMarketDividend = max(0.0, $currentPrice * 0.10);

        $newDividend = min($newDividend, $maxDividendPerShare, $maxMarketDividend);

        $totalPaid = $newDividend * $shares;
        $event = null;

        if ($newDividend > 0.0) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON u.id = us.user_id
                 SET u.cash_balance = u.cash_balance + (us.quantity * :dividend)
                 WHERE us.stock_id = :stock_id',
                ['dividend' => $newDividend, 'stock_id' => $stock->getId()]
            );

            $stock->setLastDividend((string) $newDividend);
            $yield = (($newDividend * 4) / max($currentPrice, 0.01)) * 100;

            $totalPaidStr = $totalPaid >= 1_000_000_000
                ? number_format($totalPaid / 1_000_000_000, 2) . 'B'
                : number_format($totalPaid / 1_000_000, 2) . 'M';

            $event = [
                'description' => "Paid $" . number_format($newDividend, 2) . "/share div (\${$totalPaidStr} total, " . number_format($yield, 2) . "% yield).",
                'shock' => 0.0
            ];
        } else {
            $stock->setLastDividend('0.00');
        }

        return ['dividend_per_share' => $newDividend, 'total_paid' => $totalPaid, 'event' => $event];
    }

    /**
     * Executes share repurchases
     * Respects volume pacing limits and SEC-style regulations (max 2% of float per quarter)
     * to realistically drain the authorized buyback pool.
     *
     * @param Stock $stock        The stock repurchasing shares.
     * @param float $excessCash   The excess cash available in the treasury.
     * @param float $shares       The current number of outstanding shares.
     * @param float $currentPrice The current stock price.
     * @param float $currentPE    The current Price-to-Earnings ratio.
     * @param float $operatingBase The operating base for hoard calculation.
     * @param float $investedCapital The physical capital invested in the firm.
     * @param float $nopat        Net Operating Profit After Tax.
     * @param array $health       The current debt health metrics.
     * @param array $macroState   The current macroeconomic state.
     * @return array{new_shares: float, total_cash_spent: float, event: array|null} Data regarding the buyback execution.
     */
    private function executeBuybacks(Stock $stock, float $excessCash, float $shares, float $currentPrice, float $currentPE, float $operatingBase, float $investedCapital, float $nopat, array $health, array $macroState, float $actualTotalNetIncome = 0.0, float $retainedEarningsThisQuarter = 0.0): array
    {
        // If they want to pay down debt, normally they pause buybacks. 
        // BUT if they are sitting on a cash pile large enough to easily cover their entire debt, they can do both!
        $canEasilyCoverDebt = $excessCash > ((float) $stock->getTotalDebt() * 2.0);

        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';
        
        if ($isLeveraged) {
            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['equity_limit'] ?? 10.0;
            $currentDebtToEquity = (float)$stock->getDebtToEquityRatio();
            
            // Tier 1 Capital Constraint: Stop buybacks if Debt/Equity hits 85% of the regulatory max limit
            if ($currentDebtToEquity > ($equityLimit * 0.85)) {
                return ['new_shares' => $shares, 'total_cash_spent' => 0.0, 'event' => null];
            }
        }
        
        $totalDebt = (float) $stock->getTotalDebt();
        
        $hoardStatus = $this->mathUtility->evaluateHoardingStatus($excessCash, $operatingBase, $totalDebt, $leverageType);
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        $minBuybackIcr = $isLeveraged ? 1.35 : 2.0;

        // If they are a Mega Hoarder, bypass the debt paydown block! The cash must be deployed.
        if (!$isMegaHoarder && (($health['wants_to_paydown_debt'] && !$canEasilyCoverDebt) || $health['interest_coverage'] < $minBuybackIcr)) {
            return ['new_shares' => $shares, 'total_cash_spent' => 0.0, 'event' => null];
        }

        $totalCashSpent = 0.0;
        $event = null;

        // Use EVA (Economic Value Added) spread instead of the EPS accretion mirage
        if ($isLeveraged) {
            $trueReturn = (float)$stock->getTotalEquity() > 0 ? ($actualTotalNetIncome / (float)$stock->getTotalEquity()) : 0.0;
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;
        } else {
            $trueReturn = $investedCapital > 0 ? ($nopat / $investedCapital) : 0.0;
            $hurdleRate = $health['wacc'];
        }
        $economicSpread = $trueReturn - $hurdleRate;


        // Calculate intrinsic Fair Value P/E to benchmark buybacks
        $riskFreeRate = $macroState['policy_rate'] ?? 0.04;
        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($riskFreeRate, $economicSpread);

        // Require positive EVA and fair valuation, OR force buybacks if sitting on a massive dead cash hoard.
        if (($economicSpread > 0.02 && $currentPE < ($fairValuePE + 3.0)) || $isMegaHoarder) {

            // The CFO's Cash Pacing Limit
            if ($isMegaHoarder) {
                // Mega-hoarders are explicitly trying to drain accumulated dead cash
                $maxWillingSpend = $excessCash * 0.30;
            } else {
                if ($isLeveraged) {
                    // STRICT RULE: Leveraged companies can ONLY use cash generated this quarter (minus dividends paid)
                    // This permanently prevents debt-funded or old-hoard-draining buybacks for healthy banks!
                    $maxWillingSpend = min($excessCash * 0.10, $retainedEarningsThisQuarter);
                } else {
                    $maxWillingSpend = $excessCash * 0.10;
                }
            }

            /// The SEC/Regulatory Market Volume Limit (Max 1.5% of total Market Cap per 90 days to avoid market manipulation)
            $marketCap = $shares * max($currentPrice, 0.01);
            $maxCapPct = $isMegaHoarder ? 0.03 : 0.015;
            $maxRegulatorySpend = $marketCap * $maxCapPct;

            // The Absolute Maximum Plan
            $absoluteMaxSpend = min($maxWillingSpend, $maxRegulatorySpend);

            // THE TRADING DESK: Execute the buyback over the quarter.
            // CFOs scale their aggression based on how "cheap" the stock is relative to its intrinsic Fair P/E
            $valuationDiscount = max(0.0, ($fairValuePE - $currentPE) / max(1.0, $fairValuePE));
            $aggression = min(1.0, 0.50 + $valuationDiscount); // Base 50% execution + up to 50% more if undervalued

            $actualSpend = $absoluteMaxSpend * $aggression * (mt_rand(50, 100) / 100.0);

            $sharesRepurchased = (int) floor($actualSpend / max($currentPrice, 0.01));

            if ($sharesRepurchased > 0) {
                $totalCashSpent = $sharesRepurchased * max($currentPrice, 0.01);

                $shares -= $sharesRepurchased;
                $stock->setSharesOutstanding((string) $shares);

                $pctRetired = ($sharesRepurchased / ($shares + $sharesRepurchased)) * 100;
                $event = [
                    'description' => "Bought back " . number_format($sharesRepurchased) . " shares.",
                    'shock' => $pctRetired * 0.5
                ];
            }
        }

        return ['new_shares' => $shares, 'total_cash_spent' => $totalCashSpent, 'event' => $event];
    }

    /**
     * Updates the corporate balance sheet using Clean Surplus Accounting principles.
     * Handles retained earnings, total equity, and dynamically manages debt levels
     * (triggering emergency borrowing during liquidity crises or sweeping excess cash to pay down debt).
     *
     * @param Stock $stock              The stock whose balance sheet is being updated.
     * @param float $quarterlyNetIncome The total net income generated this quarter.
     * @param float $totalDividendsPaid The total cash distributed as dividends.
     * @param float $totalBuybackCash   The total cash spent on share repurchases.
     * @param float $operatingBase      The stable physical size of the business.
     * @param float $nopat              Net Operating Profit After Tax.
     * @param float $newTreasury        The projected treasury balance before debt management.
     * @param array $macroState         The current macroeconomic state (influences debt tolerance).
     */
    private function updateBalanceSheet(
        Stock $stock,
        float $quarterlyNetIncome,
        float $totalDividendsPaid,
        float $totalBuybackCash,
        float $operatingBase,
        float $nopat,
        float $newTreasury,
        array $macroState,
        array $health
    ): array {
        $events = [];
        $totalCashSpent = $totalDividendsPaid + $totalBuybackCash;

        // RETAINED EARNINGS
        $currentRetained = (float) $stock->getRetainedEarnings();
        $newRetained = $currentRetained + $quarterlyNetIncome - $totalDividendsPaid;
        $stock->setRetainedEarnings((string) $newRetained); // Allow negative Accumulated Deficit

        // TOTAL EQUITY (Clean Surplus Accounting)
        $currentEquity = (float) $stock->getTotalEquity();
        // Allow negative equity to accurately track accumulated deficits.
        $newEquity = $currentEquity + $quarterlyNetIncome - $totalCashSpent;
        $stock->setTotalEquity((string) $newEquity);

        // STATE MANAGER FOR MUTATIONS
        $state = [
            'treasury' => $newTreasury,
            'wholesaleDebt' => (float) $stock->getWholesaleDebt(),
            'customerDeposits' => (float) $stock->getCustomerDeposits(),
            'debtIssued' => 0.0,
            'organicCapex' => 0.0,
            'debtActionTaken' => false,
            'bank_apy' => null,
            'events' => []
        ];

        // SYSTEMIC M2 MONEY SUPPLY GROWTH
        $this->processPassiveLiabilityGrowth($stock, $macroState, $state);

        // DEBT MANAGEMENT (MACRO TOLERANCE)
        $this->processDebtExpansion($stock, $newEquity, $nopat, $macroState, $health, $state);

        //  ORGANIC BUSINESS EXPANSION (Internal CapEx)
        $this->processOrganicCapex($stock, $newEquity, $nopat, $operatingBase, $macroState, $health, $state);

        // THE DEBT TRAP (Liquidity Crisis)
        $this->processEmergencyBorrowing($stock, $operatingBase, $macroState, $health, $state);

        // ARBITRAGE PAYDOWN (Escape negative carry)
        $this->processArbitragePaydown($stock, $operatingBase, $health, $state);

        // THE DELEVERAGING SWEEP (Macro-Driven Cash Management)
        $this->processDeleveragingSweep($stock, $newEquity, $operatingBase, $health, $state);

        // SAVE FINAL TREASURY
        $stock->setCorporateTreasury((string) $state['treasury']);

        return [
            'events' => $state['events'], 
            'organic_capex' => $state['organicCapex'],
            'bank_apy' => $state['bank_apy']
        ];
    }

    /**
     * Evaluates and executes strategic debt issuance for corporate expansion.
     *
     * This method acts as the CFO determining if the company has the balance sheet
     * capacity and the operating cash flow to safely issue new debt. It strictly
     * checks that the True ROIC is higher than the WACC (positive EVA) before borrowing.
     *
     * @param Stock $stock      The stock entity.
     * @param float $newEquity  The projected new equity (Book Value) for the quarter.
     * @param float $nopat      Net Operating Profit After Tax.
     * @param array $macroState The macroeconomic state influencing growth/debt tolerance.
     * @param array $health     The debt health metrics from the DebtEngine.
     * @param array &$state     The mutable state array holding treasury, debt, and events.
     */
    private function processDebtExpansion(Stock $stock, float $newEquity, float $nopat, array $macroState, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $liveInvestedCapital = $this->mathUtility->calculateLiveInvestedCapital($newEquity, $totalDebt, $state['treasury']);

        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';

        if ($isLeveraged) {
            // For Banks, Cash is inventory. Return on Equity (ROE) is the true metric of expansion.
            $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
            $interest = $health['raw_metrics']['interest_expense'] ?? 0.0;
            $preTaxIncome = max(0.0, $ebit - $interest);
            
            $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
            $netIncomeProxy = $preTaxIncome * (1.0 - $corporateTaxRate);
            
            $trueReturn = $newEquity > 0 ? ($netIncomeProxy / $newEquity) : 0.0;
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;
        } else {
            // Normal companies use ROIC vs WACC
            $trueReturn = $liveInvestedCapital > 0 ? ($nopat / $liveInvestedCapital) : 0.0;
            $hurdleRate = $health['wacc'];
        }

        $evaluationCapital = $isLeveraged ? ($newEquity + $state['wholesaleDebt']) : $liveInvestedCapital;

        if ($trueReturn > $hurdleRate && $health['can_issue_debt']) {
            $newBorrowingRate = $health['raw_metrics']['current_market_rate'] ?? 0.05;

            if ($isLeveraged) {
                $evalDebt = $state['wholesaleDebt'];
                $evalTolerance = 2.0; // Max 2x Equity in pure wholesale bonds for Banks
            } else {
                $evalDebt = $totalDebt;
                $evalTolerance = $health['debt_tolerance'];
            }
            
            $balanceSheetCapacity = max(0.0, ($newEquity * $evalTolerance) - $evalDebt);

            if ($isLeveraged) {
                // Banks scale based on Regulatory Capital (Balance Sheet), not Interest Coverage.
                // Their interest expense scales symmetrically with interest income, so ICR is a false bottleneck for growth.
                $incomeStatementCapacity = $balanceSheetCapacity; 
            } else {
                // Normal companies must strictly prove their Operating Income can afford the new interest payments
                $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
                $minimumIcr = 3.5; 
                $maxTolerableInterest = max(0.0, $ebit / $minimumIcr);
                $currentInterestExpense = $health['raw_metrics']['interest_expense'] ?? 0.0;
                $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
                
                $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;
            }

            // Take the stricter of the two limits
            $trueExpansionCapacity = min($incomeStatementCapacity, $balanceSheetCapacity);
            
            // Safety Valve: No company can physically grow its entire capital base by more than 15% in a single 90-day quarter
            $trueExpansionCapacity = min($trueExpansionCapacity, $liveInvestedCapital * 0.15);

            if ($trueExpansionCapacity > 0) {
                $spreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 10.0));

                if ($isLeveraged) {
                    // BANKS: Leverage is their core product. A 5% EVA spread is massive for a bank.
                    // We multiply by 20.0 so a 5% spread achieves maximum growth aggression.
                    $bankSpreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 20.0));
                    
                    // Banks issue wholesale bonds to match loan demand, but prudently.
                    $borrowProbability = 0.85 + ($bankSpreadMultiplier * 0.15); // 85% to 100% chance
                    $aggressiveness = 0.05 + (0.15 * $bankSpreadMultiplier); // Deploy up to 20% of capital capacity

                    if ($leverageType === 'commercial_bank') {
                        // DYNAMIC DEPOSIT CONSTRAINT
                        // If a bank is funding its loan book predominantly with expensive wholesale debt,
                        // the CFO will hit the brakes on expansion until the deposit base catches up.
                        $depositRatio = $totalDebt > 0 ? ($state['customerDeposits'] / $totalDebt) : 0.0;
                        if ($depositRatio < 0.70) {
                            // Smoothly throttle growth: 100% speed at 70% deposits, 0% speed at 40% deposits.
                            $depositConstraint = max(0.0, ($depositRatio - 0.40) / 0.30);
                            $borrowProbability *= $depositConstraint;
                            $aggressiveness *= $depositConstraint;
                        }
                    }
                } else {
                    // NORMAL COMPANIES: Chunky bond issuances based on standard EVA spreads
                    $aggressiveness = 0.05 + (0.35 * $spreadMultiplier);
                    $borrowProbability = 0.40 + ($spreadMultiplier * 0.50); // 40% to 90% chance
                }

                // Banks don't pause inventory (debt) acquisition due to market share.
                if (!$isLeveraged) {
                    $saturationPenalty = $this->mathUtility->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);
                    $borrowProbability *= max(0.10, 1.0 - $saturationPenalty);
                }

                if ((mt_rand() / mt_getrandmax()) < $borrowProbability) {
                    $newDebtIssued = $trueExpansionCapacity * $aggressiveness;
                    $newTotalDebt = $totalDebt + $newDebtIssued;

                    $oldHistoricalRate = (float) $stock->getHistoricalFixedRate();
                    $newWholesaleDebt = $state['wholesaleDebt'] + $newDebtIssued;
                    if ($newWholesaleDebt > 0) {
                        $weightedRate = (($state['wholesaleDebt'] * $oldHistoricalRate) + ($newDebtIssued * $newBorrowingRate)) / $newWholesaleDebt;
                        $stock->setHistoricalFixedRate((string) $weightedRate);
                    }

                    $state['wholesaleDebt'] += $newDebtIssued;
                    $stock->setWholesaleDebt((string) $state['wholesaleDebt']);
                    $state['treasury'] += $newDebtIssued;
                    $state['debtIssued'] = $newDebtIssued;
                    $state['debtActionTaken'] = true;

                    if ($newDebtIssued > 500_000_000.0) {
                        $amtB = number_format($newDebtIssued / 1_000_000_000, 2);
                        $state['events'][] = [
                            'description' => "Issued \${$amtB}B in bonds for expansion.",
                            'shock' => 0.5
                        ];
                    }
                }
            }
        }
    }

    /**
     * Executes internal organic capital expenditure (reinvesting cash into physical growth).
     *
     * Scales physical expansion probability based on the spread between ROIC and WACC.
     * Implements a soft ceiling as the company approaches its Total Addressable Market (TAM)
     * limit to simulate monopolistic stagnation. Organic CapEx acts as an asset swap
     * (cash for physical assets), diluting immediate ROIC until those assets generate earnings.
     *
     * @param Stock $stock         The stock entity.
     * @param float $newEquity     The projected new equity (Book Value).
     * @param float $nopat         Net Operating Profit After Tax.
     * @param float $operatingBase The physical scale of the business.
     * @param array $macroState    The macroeconomic state.
     * @param array $health        The debt health metrics.
     * @param array &$state        The mutable state array holding treasury, debt, and events.
     */
    private function processOrganicCapex(Stock $stock, float $newEquity, float $nopat, float $operatingBase, array $macroState, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';
        $targetCashReservs = $this->mathUtility->calculateTargetOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt'], $leverageType) * 1.20;
        $liveInvestedCapital = $this->mathUtility->calculateLiveInvestedCapital($newEquity, $totalDebt, $state['treasury']);
        

        if ($isLeveraged) {
            // For Banks, Cash is inventory. Return on Equity (ROE) is the true metric.
            $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
            $interest = $health['raw_metrics']['interest_expense'] ?? 0.0;
            $preTaxIncome = max(0.0, $ebit - $interest);
            
            $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
            $netIncomeProxy = $preTaxIncome * (1.0 - $corporateTaxRate);
            $trueReturn = $newEquity > 0 ? ($netIncomeProxy / $newEquity) : 0.0;
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;
        } else {
            $trueReturn = $liveInvestedCapital > 0 ? ($nopat / $liveInvestedCapital) : 0.0;
            $hurdleRate = $health['wacc'];
        }

        $evaluationCapital = $isLeveraged ? ($newEquity + $state['wholesaleDebt']) : $liveInvestedCapital;

        $investmentProbability = min(0.95, max(0.10, 0.20 + ($trueReturn * 2.0)));

        // TAM SATURATION GRAVITY (Diminishing Marginal Returns)
        // As a company consumes its Total Addressable Market, the marginal return on new physical capital collapses.
        $saturationPenalty = $this->mathUtility->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);
        $investmentProbability *= max(0.10, 1.0 - $saturationPenalty);

        $fundInvestmentOpportunity = (mt_rand() / mt_getrandmax()) < $investmentProbability;

        if (($trueReturn > $hurdleRate && $state['treasury'] > $targetCashReservs && !$health['wants_to_paydown_debt'] && $fundInvestmentOpportunity) || $state['debtActionTaken']) {
            $spreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 10.0));

            $organicSpend = ($state['treasury'] - $targetCashReservs) * (0.02 + (0.13 * $spreadMultiplier));
            
            if ($isLeveraged) {
                // For a Bank, "CapEx" is actually the act of expanding their Loan Book.
                // They take the cash from the vault (Treasury) and lend it out to the economy.
                // Draining the Treasury mathematically shifts the value into Invested Capital (Equity + Debt - Treasury).
                $expansionSpend = max($organicSpend, $state['debtIssued'] * 0.95);
            } else {
                // Normal companies burn newly issued debt on physical infrastructure (factories, warehouses)
                $expansionSpend = max($organicSpend, $state['debtIssued'] * 0.75);
            }
            
            $expansionSpend = min($expansionSpend, max(0.0, $state['treasury'] - $targetCashReservs));
            $maxGrowthSpeed = $isLeveraged ? 0.08 : 0.05; // Banks are capped at 8% loan book growth per quarter
            $expansionCapBasis = $isLeveraged ? ($newEquity + $totalDebt) : $liveInvestedCapital;
            $expansionSpend = min($expansionSpend, $expansionCapBasis * $maxGrowthSpeed);

            if ($expansionSpend > 0) {
                $state['organicCapex'] = $expansionSpend;
                $state['treasury'] -= $expansionSpend;

                $liveInvestedCapital = $this->mathUtility->calculateLiveInvestedCapital($newEquity, $totalDebt, $state['treasury']);
                $expansionRatio = $expansionSpend / max(1.0, $liveInvestedCapital);

                // LAW OF DIMINISHING RETURNS
                // Expanding physical infrastructure makes the core business slightly less efficient to operate over time.
                // We drag the structural baseline down, creating a natural gravity that prevents infinite exponential ROIC.
                if (!$isLeveraged) {
                    $baselineRoic = (float) $stock->getBaselineRoic();
                    $roicDrag = $baselineRoic * $expansionRatio * 0.02; 
                    $stock->setBaselineRoic((string) max(0.03, $baselineRoic - $roicDrag));
                } else {
                    $baselineRoe = (float) $stock->getBaselineRoe();
                    $roeDrag = $baselineRoe * $expansionRatio * 0.02;
                    $stock->setBaselineRoe((string) max(0.03, $baselineRoe - $roeDrag));
                }

                if ($expansionSpend > 1_000_000_000.0) {
                    $amtB = number_format($expansionSpend / 1_000_000_000, 2);
                    $actionText = $isLeveraged ? "loan book expansion" : "organic expansion";
                    $state['events'][] = [
                        'description' => "Deployed \${$amtB}B in {$actionText}.",
                        'shock' => 0.5
                    ];
                }
            }
        }
    }

    /**
     * The Debt Trap: Forces emergency penalty-rate borrowing if the company faces a liquidity crisis.
     *
     * If the corporate treasury falls below the absolute minimum operating requirement (3%),
     * the company is forced to issue emergency debt at severe penalty rates to survive.
     * This simulates bridging a cash shortfall during deep recessions or massive operational blunders.
     *
     * @param Stock $stock         The stock entity.
     * @param float $operatingBase The physical scale of the business.
     * @param array $macroState    The macroeconomic state.
     * @param array $health        The debt health metrics.
     * @param array &$state        The mutable state array holding treasury, debt, and events.
     */
    private function processEmergencyBorrowing(Stock $stock, float $operatingBase, array $macroState, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';
        $minOperatingCash = $this->mathUtility->calculateMinOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt'], $leverageType);

        if ($state['treasury'] < $minOperatingCash) {
            $cashShortfall = $minOperatingCash - $state['treasury'];
            $newWholesaleDebt = $state['wholesaleDebt'] + $cashShortfall;

            if ($newWholesaleDebt > 0) {
                $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
                $dynamicSpread = $health['raw_metrics']['dynamic_spread'] ?? (float) $stock->getCreditSpread();

                $costOfEmergencyDebt = $policyRate + $dynamicSpread + 0.02;

                $oldHistoricalRate = (float) $stock->getHistoricalFixedRate();
                $weightedRate = (($state['wholesaleDebt'] * $oldHistoricalRate) + ($cashShortfall * $costOfEmergencyDebt)) / $newWholesaleDebt;
                $stock->setHistoricalFixedRate((string) $weightedRate);
            }

            $state['wholesaleDebt'] += $cashShortfall;
            $stock->setWholesaleDebt((string) $state['wholesaleDebt']);
            $state['treasury'] = $minOperatingCash;

            if ($cashShortfall > 10_000_000.0) {
                $amtB = number_format($cashShortfall / 1_000_000_000, 2);
                $state['events'][] = [
                    'description' => "Forced to borrow \${$amtB}B at penalty rates due to cash shortfall.",
                    'shock' => -5.0
                ];
            }

            $state['debtActionTaken'] = true;
        }
    }

    /**
     * Retires existing debt to escape negative carry or survive severe liquidity crises.
     *
     * If the gross cost of debt is significantly higher than the yield the company earns
     * on its cash (Negative Carry), or if interest coverage is dangerously low, the CFO
     * will sweep excess cash to retire bonds early.
     *
     * @param Stock $stock         The stock entity.
     * @param float $operatingBase The physical scale of the business.
     * @param array $health        The debt health metrics.
     * @param array &$state        The mutable state array holding treasury, debt, and events.
     */
    private function processArbitragePaydown(Stock $stock, float $operatingBase, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';
        $targetOperatingCash = $this->mathUtility->calculateTargetOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt'], $leverageType);

        if (!$state['debtActionTaken'] && $health['wants_to_paydown_debt'] && $state['wholesaleDebt'] > 0 && $state['treasury'] > $targetOperatingCash) {
            $liquidityCrisisThreshold = $isLeveraged ? 1.15 : 2.0;
            
            $isLiquidityCrisis = $health['interest_coverage'] < $liquidityCrisisThreshold;
            $paydownProbability = $isLiquidityCrisis ? 1.0 : 0.15;

            if ((mt_rand() / mt_getrandmax()) < $paydownProbability) {
                $sweepPercentage = $isLiquidityCrisis ? 0.50 : 0.10;
                $arbitragePaydown = ($state['treasury'] - $targetOperatingCash) * $sweepPercentage;
                $maxRetireableDebt = $state['wholesaleDebt'] * ($isLiquidityCrisis ? 0.15 : 0.05);
                $actualPaydown = min($arbitragePaydown, $state['wholesaleDebt'], $maxRetireableDebt);

                if ($actualPaydown > 0) {
                    $state['wholesaleDebt'] -= $actualPaydown;
                    $stock->setWholesaleDebt((string) $state['wholesaleDebt']);
                    $state['treasury'] -= $actualPaydown;
                    $state['debtActionTaken'] = true;

                    if ($actualPaydown > 500_000_000.0) {
                        $amtB = number_format($actualPaydown / 1_000_000_000, 2);
                        $reason = $isLiquidityCrisis ? "survive a liquidity crisis" : "escape negative carry";
                        $state['events'][] = [
                            'description' => "Paid down \${$amtB}B of debt to {$reason}.",
                            'shock' => 1.0
                        ];
                    }
                }
            }
        }
    }

    /**
     * Executes macro-driven deleveraging sweeps to appease the bond market.
     *
     * If the company exceeds the CFO's target Debt-to-Equity tolerance, or if the
     * bond market imposes a Junk Bond penalty spread, the company will aggressively
     * sweep excess cash to pay down debt and restore a healthy balance sheet ratio.
     *
     * @param Stock $stock         The stock entity.
     * @param float $newEquity     The projected new equity (Book Value).
     * @param float $operatingBase The physical scale of the business.
     * @param array $health        The debt health metrics.
     * @param array &$state        The mutable state array holding treasury, debt, and events.
     */
    private function processDeleveragingSweep(Stock $stock, float $newEquity, float $operatingBase, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';
        $targetOperatingCash = $this->mathUtility->calculateTargetOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt'], $leverageType);

        if (!$state['debtActionTaken'] && $state['wholesaleDebt'] > 0.0 && $state['treasury'] > $targetOperatingCash) {
            $excessCash = $state['treasury'] - $targetOperatingCash;
            $macroDebtTolerance = $health['debt_tolerance'];
            
            // Banks evaluate leverage sweeps strictly on Wholesale Debt (not Deposits)
            $evalDebt = $isLeveraged ? $state['wholesaleDebt'] : $totalDebt;
            $evalLimit = $isLeveraged ? 2.0 : $macroDebtTolerance;

            $currentDebtRatio = $evalDebt / max(1.0, $newEquity);

            $baselineSpread = (float) $stock->getCreditSpread();
            $dynamicSpread = $health['raw_metrics']['dynamic_spread'] ?? $baselineSpread;
            $isJunkBondStatus = $dynamicSpread > ($baselineSpread + 0.0011);

            if ($currentDebtRatio > $evalLimit || $isJunkBondStatus) {
                $targetRatio = $isJunkBondStatus ? max(0.10, $evalLimit * 0.75) : max(0.10, $evalLimit - 0.05);

                $targetTotalDebt = $newEquity * $targetRatio;
                $debtToPayOff = min($excessCash, max(0.0, $evalDebt - $targetTotalDebt));
                $debtToPayOff = min($debtToPayOff, $state['wholesaleDebt']);

                if ($debtToPayOff > 0) {
                    $state['wholesaleDebt'] -= $debtToPayOff;
                    $stock->setWholesaleDebt((string) $state['wholesaleDebt']);
                    $state['treasury'] -= $debtToPayOff;

                    if ($debtToPayOff > 500_000_000.0) {
                        $amtB = number_format($debtToPayOff / 1_000_000_000, 2);
                        $state['events'][] = [
                            'description' => "Swept \${$amtB}B cash to aggressively deleverage.",
                            'shock' => 2.0
                        ];
                    }
                }
            }
        }
    }

    /**
     * PASSIVE LIABILITY GROWTH (M2 Money Supply & Systemic Growth)
     * - Banks: Grow deposits via APY competition and yield flight.
     * - Insurance: Grow "The Float" via nominal GDP, inflation, and policy sales.
     */
    private function processPassiveLiabilityGrowth(Stock $stock, array $macroState, array &$state): void
    {
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';

        if ($leverageType !== 'commercial_bank' && $leverageType !== 'insurance') {
            return;
        }

        $currentLiabilities = $state['customerDeposits']; // Serves as Deposits OR Float
        if ($currentLiabilities <= 0) return;

        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = $state['wholesaleDebt'] + $currentLiabilities;
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        
        $realGdpGrowth = 0.02 + ($outputGap > 0.0 ? $outputGap * 0.5 : $outputGap * 2.0); 

        // ---------------------------------------------------------
        // 1. COMMERCIAL BANK PHYSICS (APY & Yield Flight)
        // ---------------------------------------------------------
        if ($leverageType === 'commercial_bank') {
            $depositApyBeta = $this->mathUtility->calculateDepositBeta($totalDebt, $equity, $equityLimit, $currentLiabilities);
            $bankApy = max(0.001, $policyRate * $depositApyBeta);
            $state['bank_apy'] = $bankApy; // Saved for the frontend
            
            $moneyMarketYield = max(0.0, $policyRate - 0.01);
            $yieldSpread = max(0.0, $moneyMarketYield - $bankApy);
            $yieldFlightPenalty = $yieldSpread * 1.0; 
            
            $systemicGrowthAnnual = $inflation + $realGdpGrowth - $yieldFlightPenalty; 
            $systemicGrowthQuarterly = $systemicGrowthAnnual / 4.0;

            $betaSensitivity = max(0.8, min(1.2, abs((float) $stock->getBeta())));
            $competitiveAdvantage = $depositApyBeta / 0.20; 
            
            if ($systemicGrowthQuarterly > 0) {
                $baseGrowth = $systemicGrowthQuarterly * $betaSensitivity * $competitiveAdvantage;
            } else {
                $baseGrowth = $systemicGrowthQuarterly * $betaSensitivity / max(0.1, $competitiveAdvantage);
            }
            
            $randomSwing = $this->mathUtility->generateStandardNormal() * 0.005;
            $finalGrowthRate = max(-0.15, min(0.15, $baseGrowth + $randomSwing));
            $liabilityChange = $currentLiabilities * $finalGrowthRate;

            $eventLoreOut = "customer deposit flight";
            $eventLoreIn  = "new customer deposits";

        // ---------------------------------------------------------
        // 2. INSURANCE PHYSICS (Nominal GDP & Underwriting Cycles)
        // ---------------------------------------------------------
        } else {
            // Float grows purely with M2/Nominal GDP. No APY, no Yield Flight.
            $systemicGrowthAnnual = $inflation + $realGdpGrowth;
            $systemicGrowthQuarterly = $systemicGrowthAnnual / 4.0;
            
            // Insurance is highly cyclical. Beta controls how much they capture.
            $betaSensitivity = max(0.5, min(1.5, abs((float) $stock->getBeta())));
            $baseGrowth = $systemicGrowthQuarterly * $betaSensitivity;
            
            // Variance: Insurance sees lumpier quarters than banks due to catastrophes vs clean underwriting
            $randomSwing = $this->mathUtility->generateStandardNormal() * 0.015; 
            
            $finalGrowthRate = max(-0.15, min(0.15, $baseGrowth + $randomSwing));
            $liabilityChange = $currentLiabilities * $finalGrowthRate;

            $eventLoreOut = "net claim payouts";
            $eventLoreIn  = "new premium Float";
        }

        // ---------------------------------------------------------
        // ACCOUNTING & EVENT PUBLISHING (Shared Physics)
        // ---------------------------------------------------------
        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;
            
            // LIQUIDITY CRISIS (Bank Run OR Catastrophe)
            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $state['treasury'] = 0.0;
                $state['wholesaleDebt'] += $liquidityShortfall; // Emergency borrowing

                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $lore = $leverageType === 'commercial_bank' 
                    ? "Suffered a bank run. Forced to borrow \${$amtB}B to cover deposit flight."
                    : "Catastrophe claim payouts exceeded cash reserves. Forced to borrow \${$amtB}B.";

                $state['events'][] = ['description' => $lore, 'shock' => -5.0];
            }

            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));

            $percentageChange = $liabilityChange / $currentLiabilities;
            if ($percentageChange < -0.005) { 
                $amtB = number_format(abs($liabilityChange) / 1_000_000_000, 2);
                $state['events'][] = ['description' => "Suffered \${$amtB}B in {$eventLoreOut}.", 'shock' => -2.0];
            } elseif ($percentageChange > 0.005) { 
                $amtB = number_format($liabilityChange / 1_000_000_000, 2);
                $state['events'][] = ['description' => "Captured \${$amtB}B in {$eventLoreIn}.", 'shock' => 0.5];
            }
        }
    }
}
