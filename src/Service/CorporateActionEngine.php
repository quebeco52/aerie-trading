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
        private \Redis $redis
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

        if ($newPrice >= 400.0) {
            $result = $this->executeForwardSplit($stock, $newPrice, $sharesOutstanding);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        } elseif ($newPrice < 2.0) {
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
        while ($newPrice >= 400.0) {
            $newPrice = $newPrice / 4.0;
            $splitFactor *= 4;
        }

        $sharesOutstanding *= $splitFactor;

        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $oldDiv = (float) $stock->getLastDividend();
        $stock->setLastDividend((string) ($oldDiv / $splitFactor));

        $desc = "{$stock->getName()} has executed a {$splitFactor}-for-1 stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'SPLIT', $desc, 0.00);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_stocks SET quantity = quantity * :factor, version = version + 1 WHERE stock_id = :stock_id',
            ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE stock_history SET price = GREATEST(price / :factor, 0.00000001) WHERE stock_id = :stock_id',
            ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
        );

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

        while ($newPrice < 2.0) {
            $newPrice = $newPrice * 10.0;
            $reverseFactor *= 10;
        }

        $sharesOutstanding = max(1.0, $sharesOutstanding / $reverseFactor);

        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $oldDiv = (float) $stock->getLastDividend();
        $stock->setLastDividend((string) ($oldDiv * $reverseFactor));

        $desc = "{$stock->getName()} executed a 1-for-{$reverseFactor} reverse split.";
        $splitEvent = $this->marketEvent->publish($stock, 'REVSPLIT', $desc, 0.00);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE users u
             INNER JOIN user_stocks us ON u.id = us.user_id
             SET u.cash_balance = u.cash_balance + ((us.quantity % :factor) * :pre_split_price)
             WHERE us.stock_id = :stock_id',
            ['factor' => $reverseFactor, 'pre_split_price' => $preSplitPrice, 'stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_stocks SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE stock_id = :stock_id',
            ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
            ['stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE stock_history SET price = LEAST(price * :factor, 900000000000.0) WHERE stock_id = :stock_id',
            ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
        );

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
     * @param float $liveTargetPE      The live target P/E ratio for the stock's sector.
     * @return array{new_shares: float, dividend_paid: float, events: array<mixed>} Data regarding the capital allocation.
     */
    public function allocateCapital(
        Stock $stock,
        float $actualAnnualEps,
        float $quarterlyFcfPerShare,
        float $currentPrice,
        float $sharesOutstanding,
        float $liveTargetPE,
        array $macroState
    ): array {
        $events = [];
        $oldShares = $sharesOutstanding;
        $quarterlyEps = $actualAnnualEps / 4.0;
        $currentTreasury = (float) $stock->getCorporateTreasury();

        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);

        // CALCULATE BASELINE CASH CHANGES
        // Add the FCF generated this quarter to the treasury immediately so we know what we can spend
        $totalFcfGenerated = $quarterlyFcfPerShare * $oldShares;
        $newTreasury = $currentTreasury + $totalFcfGenerated;

        // EXECUTE DIVIDENDS
        $equity = (float) $stock->getTotalEquity();
        $divData = $this->executeDividends($stock, $quarterlyEps, $oldShares, $currentPrice, $newTreasury, $equity, $health);
        if ($divData['event']) $events[] = $divData['event'];

        // Subtract the dividend cash from our working treasury
        $newTreasury -= $divData['total_paid'];

        // CALCULATE EXCESS CASH (The War Chest)
        $targetOperatingCash = $equity * 0.05; // 5% of Equity is the required buffer
        $excessCash = max(0.0, $newTreasury - $targetOperatingCash);

        $currentPE = $actualAnnualEps > 0 ? ($currentPrice / $actualAnnualEps) : 9999.0;


        // THE TRADING DESK: EXECUTE BUYBACKS
        $buybackData = $this->executeBuybacks(
            $stock,
            $excessCash,
            $oldShares,
            $currentPrice,
            $currentPE,
            $liveTargetPE,
            $health
        );
        if ($buybackData['event']) $events[] = $buybackData['event'];

        // Subtract the buyback cash from our working treasury
        $newTreasury -= $buybackData['total_cash_spent'];

        // UPDATE THE BALANCE SHEET
        $bsEvents = $this->updateBalanceSheet(
            stock: $stock,
            quarterlyNetIncome: $quarterlyEps * $oldShares,
            totalDividendsPaid: $divData['total_paid'],
            totalBuybackCash: $buybackData['total_cash_spent'],
            newTreasury: $newTreasury,
            macroState: $macroState,
            health: $health
        );

        if (!empty($bsEvents)) {
            $events = array_merge($events, $bsEvents);
        }

        return [
            'new_shares' => $buybackData['new_shares'],
            'dividend_paid' => $divData['dividend_per_share'],
            'total_paid' => $divData['total_paid'],
            'total_cash_spent' => $buybackData['total_cash_spent'],
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
     * @param float $equity               The total equity of the company (used for safety buffers).
     * @return array{dividend_per_share: float, total_paid: float, event: array|null} Data regarding the dividend execution.
     */
    private function executeDividends(Stock $stock, float $quarterlyEps, float $shares, float $currentPrice, float $availableTreasury, float $equity, array $health): array
    {
        $targetPayout = (float) $stock->getTargetPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();

        // Target is based on EPS (Net Income)
        $targetDividend = $quarterlyEps > 0 ? ($quarterlyEps * $targetPayout) : 0.0;

        // Emergency Liquidity Preservation
        $roic = (float) $stock->getCurrentRoic();
        $wacc = $health['wacc'];
        $evaSpread = $roic - $wacc;

        if ($evaSpread < -0.02) {
            $targetDividend = 0.0;
            $speed = 1.0; // Override the Lintner smoothing model. Cut it immediately!
        }


        $newDividend = $lastDividend + ($speed * ($targetDividend - $lastDividend));
        $newDividend = max(0.0, $newDividend);

        // Cap the dividend to what we can physically pay from cash on hand (minus a 3% operating safety buffer)
        $minOperatingCash = $equity * 0.03;
        $usableCash = max(0.0, $availableTreasury - $minOperatingCash);
        $maxDividendPerShare = $shares > 0 ? ($usableCash / $shares) : 0.0;
        
        $newDividend = min($newDividend, $maxDividendPerShare);

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
            $event = $this->marketEvent->publish($stock, 'DIVIDEND', "{$stock->getTicker()} distributed a quarterly dividend of $" . number_format($newDividend, 2) . "/share (Yield: " . number_format($yield, 2) . "%).", 0.0);
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
     * @param float $currentAuth  The currently authorized buyback dollar amount.
     * @param float $excessCash   The excess cash available in the treasury.
     * @param float $shares       The current number of outstanding shares.
     * @param float $currentPrice The current stock price.
     * @param float $currentPE    The current Price-to-Earnings ratio.
     * @param float $targetPE     The target Price-to-Earnings ratio for the sector.
     * @return array{new_shares: float, total_cash_spent: float, event: array|null} Data regarding the buyback execution.
     */
    private function executeBuybacks(Stock $stock, float $excessCash, float $shares, float $currentPrice, float $currentPE, float $targetPE, array $health): array
    {
        $totalCashSpent = 0.0;
        $event = null;

        // Use EVA (Economic Value Added) spread instead of the EPS accretion mirage
        $roic = (float) $stock->getCurrentRoic();
        $wacc = $health['wacc'];
        $economicSpread = $roic - $wacc;
        $equity = (float) $stock->getTotalEquity();
        
        $isMegaHoarder = $excessCash > ($equity * 0.50);

        // Require positive EVA and fair valuation, OR force buybacks if sitting on a massive dead cash hoard.
        if (($economicSpread > 0.02 && $currentPE < ($targetPE + 3.0)) || $isMegaHoarder) {

            // The CFO's Cash Limit (Max 50% of Excess Cash)
            $maxWillingSpend = $excessCash * 0.50;

            // The SEC/Regulatory Limit (Max 2% of total float)
            $maxSharesToRetire = $shares * 0.02;
            $maxRegulatorySpend = $maxSharesToRetire * max($currentPrice, 0.01);

            // The Absolute Maximum Plan
            $absoluteMaxSpend = min($maxWillingSpend, $maxRegulatorySpend);

            // THE TRADING DESK: Execute the buyback over the quarter.
            $actualSpend = $absoluteMaxSpend * (mt_rand(25, 100) / 100.0);

            $sharesRepurchased = (int) floor($actualSpend / max($currentPrice, 0.01));

            if ($sharesRepurchased > 0) {
                $totalCashSpent = $sharesRepurchased * max($currentPrice, 0.01);

                $shares -= $sharesRepurchased;
                $stock->setSharesOutstanding((string) $shares);

                $pctRetired = ($sharesRepurchased / ($shares + $sharesRepurchased)) * 100;
                $event = $this->marketEvent->publish($stock, 'BUYBACK', "{$stock->getTicker()} executed a stock buyback, retiring " . number_format($sharesRepurchased) . " shares.", $pctRetired * 0.5);
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
     * @param float $newTreasury        The projected treasury balance before debt management.
     * @param array $macroState         The current macroeconomic state (influences debt tolerance).
     */
    private function updateBalanceSheet(
        Stock $stock,
        float $quarterlyNetIncome,
        float $totalDividendsPaid,
        float $totalBuybackCash,
        float $newTreasury,
        array $macroState,
        array $health
    ): array {
        $events = [];
        $totalCashSpent = $totalDividendsPaid + $totalBuybackCash;

        // RETAINED EARNINGS
        $currentRetained = (float) $stock->getRetainedEarnings();
        $newRetained = $currentRetained + $quarterlyNetIncome - $totalDividendsPaid;
        $stock->setRetainedEarnings((string) max(0.0, $newRetained));

        // TOTAL EQUITY (Clean Surplus Accounting)
        $currentEquity = (float) $stock->getTotalEquity();
        $newEquity = max(1000.0, $currentEquity + $quarterlyNetIncome - $totalCashSpent);
        $stock->setTotalEquity((string) $newEquity);
        
        $newDebtIssued = 0.0;

        // DEBT MANAGEMENT (MACRO TOLERANCE)
        $currentDebt = (float) $stock->getTotalDebt();
        $targetOperatingCash = $newEquity * 0.05;
        $minOperatingCash = $newEquity * 0.03;

        $roic = (float) $stock->getCurrentRoic();
        $wacc = $health['wacc'];

        if ($roic > $wacc && $health['can_issue_debt']) {
            
            $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
            $newBorrowingRate = $health['raw_metrics']['current_market_rate'] ?? 0.05;

            // Income Statement Constraint (ICR)
            $minimumIcr = 3.0; // Minimum 3x interest coverage
            $maxTolerableInterest = max(0.0, $ebit / $minimumIcr);
            
            // Approximate future interest run-rate
            $currentInterestExpense = $currentDebt * $newBorrowingRate; 
            $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
            
            $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;

            // Balance Sheet Constraint (Macro Tolerance)
            $balanceSheetCapacity = max(0.0, ($newEquity * $health['debt_tolerance']) - $currentDebt);

            // The True Capacity is the most conservative metric
            $trueExpansionCapacity = min($incomeStatementCapacity, $balanceSheetCapacity);

            // Only borrow if there is a safe, justifiable reason to do so
            if ($trueExpansionCapacity > 0) {
                $spreadMultiplier = min(1.0, max(0.0, ($roic - $wacc) * 10.0)); // 10% spread = 1.0 max aggressiveness
                $aggressiveness = 0.02 + (0.13 * $spreadMultiplier);
                
                $newDebtIssued = $trueExpansionCapacity * $aggressiveness;
                $newTotalDebt = $currentDebt + $newDebtIssued;

                // Blend the interest rate so they don't get free debt
                $oldHistoricalRate = (float) $stock->getHistoricalFixedRate();
                $weightedRate = (($currentDebt * $oldHistoricalRate) + ($newDebtIssued * $newBorrowingRate)) / $newTotalDebt;
                $stock->setHistoricalFixedRate((string) $weightedRate);

                $currentDebt = $newTotalDebt;
                $stock->setTotalDebt((string) $currentDebt);
                
                // The new debt injects raw cash into the corporate treasury
                $newTreasury += $newDebtIssued;
                
                if ($newDebtIssued > 500_000_000.0) {
                    $amtB = number_format($newDebtIssued / 1_000_000_000, 2);
                    $events[] = $this->marketEvent->publish($stock, 'DEBT ISSUANCE', "{$stock->getTicker()} issued \${$amtB}B in corporate bonds to fund strategic expansion.", 0.5);
                }
            }
        }

        // ORGANIC BUSINESS EXPANSION (Internal CapEx)
        // Reinvesting excess cash to grow core operations. This is a balance sheet asset swap 
        // (Cash decreases, Physical Assets/IP increase). Total Equity is unchanged, but Invested Capital grows!

        $targetCashReservs = $newEquity * 0.08;

        if ($roic > $wacc && $newTreasury > $targetCashReservs && !$health['wants_to_paydown_debt']) {
            $spreadMultiplier = min(1.0, max(0.0, ($roic - $wacc) * 10.0));
            
            // Deploy between 2% and 15% of organic excess treasury into growth this quarter
            $organicSpend = ($newTreasury - $targetCashReservs) * (0.02 + (0.13 * $spreadMultiplier));
            
            // If we specifically issued debt for expansion, force the deployment of that cash immediately!
            $expansionSpend = max($organicSpend, $newDebtIssued * 0.75);
            
            // Failsafe: Don't spend cash we physically do not have
            $expansionSpend = min($expansionSpend, max(0.0, $newTreasury - $targetCashReservs));

            if ($expansionSpend > 0) {
                $newTreasury -= $expansionSpend;
                
                if ($expansionSpend > 1_000_000_000.0) { // Only announce massive investments >$1B
                    $amtB = number_format($expansionSpend / 1_000_000_000, 2);
                    $events[] = $this->marketEvent->publish($stock, 'CAPEX EXPANSION', "{$stock->getTicker()} deployed \${$amtB}B of cash into organic business expansion.", 0.5);
                }
            }
        }

        // If debt is severely expensive, pay it down!
        if ($health['wants_to_paydown_debt'] && $currentDebt > 0 && $newTreasury > $targetOperatingCash) {
            
            $arbitragePaydown = ($newTreasury - $targetOperatingCash) * 0.50;
            $actualPaydown = min($arbitragePaydown, $currentDebt);

            $currentDebt -= $actualPaydown;
            $stock->setTotalDebt((string) $currentDebt);
            $newTreasury -= $actualPaydown;
            
            if ($actualPaydown > 500_000_000.0) {
                $amtB = number_format($actualPaydown / 1_000_000_000, 2);
                $events[] = $this->marketEvent->publish($stock, 'DEBT REDUCTION', "{$stock->getTicker()} paid down \${$amtB}B of expensive debt to escape negative carry.", 1.0);
            }
        }

        // THE DEBT TRAP (Liquidity Crisis)
        if ($newTreasury < $minOperatingCash) {
            $cashShortfall = $minOperatingCash - $newTreasury;
            $newTotalDebt = $currentDebt + $cashShortfall;
            
            // Re-price the interest rate for this emergency borrowing!
            if ($newTotalDebt > 0) {
                $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
                $dynamicSpread = $health['raw_metrics']['dynamic_spread'] ?? (float) $stock->getCreditSpread();
                
                // Emergency debt comes with a severe penalty (e.g., +200 bps)
                $costOfEmergencyDebt = $policyRate + $dynamicSpread + 0.02;
                
                $oldHistoricalRate = (float) $stock->getHistoricalFixedRate();
                $weightedRate = (($currentDebt * $oldHistoricalRate) + ($cashShortfall * $costOfEmergencyDebt)) / $newTotalDebt;
                $stock->setHistoricalFixedRate((string) $weightedRate);
            }

            $currentDebt = $newTotalDebt;
            $stock->setTotalDebt((string) $currentDebt);
            $newTreasury = $minOperatingCash;
            
            if ($cashShortfall > 10_000_000.0) {
                $amtB = number_format($cashShortfall / 1_000_000_000, 2);
                $events[] = $this->marketEvent->publish($stock, 'LIQUIDITY CRISIS', "{$stock->getTicker()} suffered a severe cash shortfall, forced to borrow \${$amtB}B at penalty rates.", -5.0);
            }
        }
        // THE DELEVERAGING SWEEP (Macro-Driven Cash Management)
        elseif ($currentDebt > 0.0 && $newTreasury > $targetOperatingCash) {
            $excessCash = $newTreasury - $targetOperatingCash;
            $currentDebtRatio = $currentDebt / max(1.0, $newEquity);

            // TOLERANCE FROM THE CENTRALIZED BRAIN
            $macroDebtTolerance = $health['debt_tolerance'];

            // Detect if M&A or equity destruction pushed them into the penalty box
            $isJunkBondStatus = $currentDebtRatio > 2.0;

            if ($currentDebtRatio > $macroDebtTolerance || $isJunkBondStatus) {
                $targetRatio = $isJunkBondStatus ? 1.80 : max(0.10, $macroDebtTolerance - 0.20);
                $targetDebt = $newEquity * $targetRatio;
                $debtToPayOff = min($excessCash, max(0.0, $currentDebt - $targetDebt));

                if ($debtToPayOff > 0) {
                    $currentDebt -= $debtToPayOff;
                    $stock->setTotalDebt((string) $currentDebt);
                    $newTreasury -= $debtToPayOff;
                    
                    if ($debtToPayOff > 500_000_000.0) {
                        $amtB = number_format($debtToPayOff / 1_000_000_000, 2);
                        $events[] = $this->marketEvent->publish($stock, 'DELEVERAGING', "{$stock->getTicker()} swept \${$amtB}B in excess cash to aggressively pay down debt.", 2.0);
                    }
                }
            }
        }

        // SAVE FINAL TREASURY
        $stock->setCorporateTreasury((string) $newTreasury);
        
        return $events;
    }
}
