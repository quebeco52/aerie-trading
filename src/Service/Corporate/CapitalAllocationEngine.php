<?php

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Macro\MacroEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

/**
 * Service responsible for returning cash to shareholders.
 * Handles Dividends and Share Repurchases (Buybacks).
 */
class CapitalAllocationEngine
{
    // --- Dividend Policy & Distress Thresholds ---
    /** EVA spread floor (-800 bps) below which standard companies enter deep structural distress. */
    private const DEEP_DISTRESS_EVA_SPREAD = -0.08;
    /** EVA spread floor (-400 bps) for moderate distress when operating cash buffer is depleted. */
    private const MODERATE_DISTRESS_EVA_SPREAD = -0.04;
    /** Tolerance multiplier (1.5x) for Dividend Aristocrats to absorb negative economic spreads before distress. */
    private const ARISTOCRAT_DISTRESS_MULTIPLIER = 1.5;
    /** Standard distress multiplier (1.0x) for non-Aristocrat dividend payers. */
    private const STANDARD_DISTRESS_MULTIPLIER = 1.0;
    /** Operating cash buffer multiple (1.5x) required to avoid moderate distress cash penalties. */
    private const CASH_BUFFER_SAFETY_MULT = 1.5;
    /** Rebased dividend retention floor (30%) for Aristocrats undergoing deep structural distress without liquidity failure. */
    private const ARISTOCRAT_DISTRESS_REBASE_RATIO = 0.30;
    /** Rebased dividend retention floor (50%) for moderate structural distress or critical cash rebasing. */
    private const MODERATE_DISTRESS_REBASE_RATIO = 0.50;

    // --- Dividend Policy & Lintner Model ---
    /** Maximum catch-up growth adjustment speed for Dividend Aristocrats when target payout exceeds current dividend. */
    private const ARISTOCRAT_MAX_CATCHUP_SPEED = 0.15;
    /** Catch-up ratio threshold (1.30x) where Aristocrats accelerate dividend adjustment speed. */
    private const ARISTOCRAT_CATCHUP_THRESHOLD = 1.30;

    // --- Regulatory Capital Conservation Buffer (Basel III / Solvency II) ---
    /** Leverage overshoot ratio (1.05x) triggering Tier 1 Capital Conservation Buffer restriction (max 60% payout). */
    private const REGULATORY_BUFFER_TIER_1_THRESHOLD = 1.05;
    /** Maximum target payout ratio allowed when operating under Tier 1 capital buffer restrictions. */
    private const REGULATORY_BUFFER_TIER_1_PAYOUT_CAP = 0.60;
    /** Leverage overshoot ratio (1.15x) triggering Tier 2 Capital Conservation Buffer restriction (max 30% payout). */
    private const REGULATORY_BUFFER_TIER_2_THRESHOLD = 1.15;
    /** Maximum target payout ratio allowed when operating under Tier 2 capital buffer restrictions. */
    private const REGULATORY_BUFFER_TIER_2_PAYOUT_CAP = 0.30;
    /** Leverage overshoot ratio (1.25x) triggering severe Tier 3 regulatory dividend prohibition (0% payout). */
    private const REGULATORY_BUFFER_TIER_3_THRESHOLD = 1.25;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CorporateMetrics $corporateMetrics,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private TreasuryEngine $treasuryEngine
    ) {}

    /**
     * Allocates quarterly capital via dividends and buybacks, and updates the balance sheet.
     */
    public function allocateCapital(
        Stock $stock,
        float $actualAnnualEps,
        float $quarterlyFcfPerShare,
        float $currentPrice,
        float $sharesOutstanding,
        \App\DTO\MacroStateDTO $macroState,
        float $actualTotalNetIncome = 0.0
    ): array {
        $events = [];
        $oldShares = $sharesOutstanding;
        $quarterlyEps = $actualAnnualEps / 4.0;

        // $actualTotalNetIncome passed from EarningsEngine is ALREADY QUARTERLY. Do not divide by 4.
        $quarterlyNetIncome = $actualTotalNetIncome != 0.0 ? $actualTotalNetIncome : ($quarterlyEps * $oldShares);

        $currentTreasury = (float) $stock->getCorporateTreasury();
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $investedCapital = $stock->getInvestedCapital();

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';

        $realEstateAppreciation = 0.0;
        if ($businessModel === 'reit') {
            $customDepreciation = (float) $stock->getDepreciationRate();
            $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);
            $absoluteDepreciation = $investedCapital * $depRate;
            // Real Estate Appreciation: Offset GAAP depreciation so book value doesn't bleed to zero
            $realEstateAppreciation = $absoluteDepreciation / 4.0;
        }

        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);

        $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
        $corporateTaxRate = $macroState->corporateTaxRate;
        $nopat = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;
        $quarterlyNopat = $nopat / 4.0;

        // CALCULATE BASELINE CASH CHANGES
        $totalFcfGenerated = $quarterlyFcfPerShare * $oldShares;
        $newTreasury = $currentTreasury + $totalFcfGenerated;

        // EXECUTE DIVIDENDS
        $divData = $this->executeDividends($stock, $quarterlyEps, $quarterlyFcfPerShare, $oldShares, $currentPrice, $newTreasury, $operatingBase, $investedCapital, $quarterlyNopat, $health, $actualTotalNetIncome);
        if ($divData['event']) $events[] = $divData['event'];

        $newTreasury -= $divData['total_paid'];

        // CORPORATE STRATEGY (CapEx & Debt Expansion)
        // We calculate pre-buyback equity to pass to Treasury for leverage ratios
        $currentEquity = (float) $stock->getTotalEquity();
        $preBuybackEquity = $currentEquity + $quarterlyNetIncome + $realEstateAppreciation - $divData['total_paid'];

        $strategyEvents = $this->treasuryEngine->executeCorporateStrategy(
            $stock,
            $preBuybackEquity,
            $operatingBase,
            $quarterlyNopat,
            $newTreasury,
            $macroState,
            $health
        );

        if (!empty($strategyEvents['events'])) {
            $events = array_merge($events, $strategyEvents['events']);
        }

        // Update Treasury with post-strategy cash (includes newly issued debt minus CapEx)
        $newTreasury = $strategyEvents['new_treasury'];
        $organicCapex = $strategyEvents['organic_capex'] ?? 0.0;
        $bankApy = $strategyEvents['bank_apy'] ?? null;

        // CALCULATE EXCESS CASH
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $targetOperatingCash = $strategy->calculateTargetOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, $newTreasury - $targetOperatingCash);

        $currentPE = $actualAnnualEps > 0 ? ($currentPrice / $actualAnnualEps) : 9999.0;
        $retainedEarningsThisQuarter = max(0.0, $quarterlyNetIncome - $divData['total_paid']);

        // THE TRADING DESK: EXECUTE BUYBACKS
        if ($businessModel === 'reit') {
            $buybackData = ['new_shares' => $oldShares, 'total_cash_spent' => 0.0, 'event' => null];
        } else {
            $buybackData = $this->executeBuybacks(
                $stock,
                $newTreasury,
                $targetOperatingCash,
                $oldShares,
                $currentPrice,
                $currentPE,
                $operatingBase,
                $investedCapital,
                $quarterlyNopat,
                $health,
                $macroState,
                $actualTotalNetIncome,
                $retainedEarningsThisQuarter
            );
        }
        if ($buybackData['event']) $events[] = $buybackData['event'];

        $newTreasury -= $buybackData['total_cash_spent'];

        // FINALIZE LIQUIDITY & EQUITY
        $debtActionTaken = $strategyEvents['debt_action_taken'] ?? false;
        $liquidityEvents = $this->treasuryEngine->finalizeLiquidity(
            $stock,
            $quarterlyNetIncome,
            $divData['total_paid'],
            $buybackData['total_cash_spent'],
            $operatingBase,
            $newTreasury,
            $macroState,
            $health,
            $realEstateAppreciation,
            $debtActionTaken
        );

        if (!empty($liquidityEvents['events'])) {
            $events = array_merge($events, $liquidityEvents['events']);
        }

        return [
            'new_shares' => $buybackData['new_shares'],
            'dividend_paid' => $divData['dividend_per_share'],
            'total_paid' => $divData['total_paid'],
            'total_cash_spent' => $buybackData['total_cash_spent'],
            'bank_apy' => $bankApy,
            'organic_capex' => $organicCapex,
            'events' => $events
        ];
    }

    private function executeDividends(Stock $stock, float $quarterlyEps, float $quarterlyFcfPerShare, float $shares, float $currentPrice, float $availableTreasury, float $operatingBase, float $investedCapital, float $quarterlyNopat, array $health, float $actualTotalNetIncome = 0.0): array
    {
        $targetPayout = (float) $stock->getTargetPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $isAristocrat = $speed <= 0.03;

        $targetPayout = $archetypeStrategy->modifyTargetPayoutRatio($targetPayout);

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        $customDepreciation = (float) $stock->getDepreciationRate();
        $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        $sustainableBase = $strategy->getSustainableDividendBase($stock, $quarterlyEps, $investedCapital, $depRate);
        if ($sustainableBase <= 0.0 && $quarterlyFcfPerShare > 0.0) {
            $sustainableBase = min($quarterlyFcfPerShare, $lastDividend / max(0.01, $targetPayout));
        }
        $calculatedTarget = $sustainableBase > 0 ? ($sustainableBase * $targetPayout) : 0.0;
        $targetDividend = $isAristocrat ? max($calculatedTarget, $lastDividend) : $calculatedTarget;

        $isRegulatoryDividendHalt = false;
        $trueReturn = $isFinancial ? (float) $stock->getRoeTtm() : (float) $stock->getRoicTtm();
        if ($trueReturn === 0.0) {
            $trueReturn = $strategy->calculateEconomicReturn($stock, $isFinancial ? $actualTotalNetIncome : $quarterlyNopat, $investedCapital);
        }
        if ($isFinancial) {
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;

            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['equity_limit'] ?? 10.0;
            $leverageRatio = (float) $stock->getDebtToEquityRatio();
            $leverageOvershoot = $equityLimit > 0.0 ? ($leverageRatio / $equityLimit) : 1.0;

            if ($leverageOvershoot >= self::REGULATORY_BUFFER_TIER_3_THRESHOLD) {
                $isRegulatoryDividendHalt = true;
            } elseif ($leverageOvershoot >= self::REGULATORY_BUFFER_TIER_2_THRESHOLD) {
                $targetDividend = min($targetDividend, $sustainableBase * min($targetPayout, self::REGULATORY_BUFFER_TIER_2_PAYOUT_CAP));
            } elseif ($leverageOvershoot >= self::REGULATORY_BUFFER_TIER_1_THRESHOLD) {
                $targetDividend = min($targetDividend, $sustainableBase * min($targetPayout, self::REGULATORY_BUFFER_TIER_1_PAYOUT_CAP));
            }
        } else {
            $hurdleRate = $health['wacc'];
        }

        $evaSpread = $trueReturn - $hurdleRate;

        $distressMultiplier = $isAristocrat ? self::ARISTOCRAT_DISTRESS_MULTIPLIER : self::STANDARD_DISTRESS_MULTIPLIER;

        $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hasCashBuffer = $availableTreasury > ($minOperatingCash * self::CASH_BUFFER_SAFETY_MULT);
        $isCriticalCash = $availableTreasury < $minOperatingCash;

        $isDeepDistress = $evaSpread < (self::DEEP_DISTRESS_EVA_SPREAD * $distressMultiplier);
        $isModerateDistressNoCash = ($evaSpread < (self::MODERATE_DISTRESS_EVA_SPREAD * $distressMultiplier)) && !$hasCashBuffer;

        $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
        $crisisThreshold = $modelThresholds['dividend_crisis_icr'];
        $isLiquidityCrisis = $health['interest_coverage'] < 1.0 || ($health['interest_coverage'] < $crisisThreshold && !$hasCashBuffer);

        $resistsCut = $archetypeStrategy->shouldResistDividendCut($isLiquidityCrisis, $isRegulatoryDividendHalt, $isDeepDistress);

        if ($resistsCut) {
            // The CEO explicitly defends the payout: clamp target dividend to prevent silent erosion below last dividend
            $targetDividend = max($targetDividend, $lastDividend);
        } else {
            if ($isLiquidityCrisis || $isRegulatoryDividendHalt) {
                // Immediate liquidity failure or regulatory prohibition forces total elimination ($0.00)
                $targetDividend = 0.0;
                $speed = 1.0;
            } elseif ($isDeepDistress) {
                // Structural deep distress without immediate liquidity failure: Aristocrats rebase to preserve signaling rather than zeroing
                $targetDividend = $isAristocrat ? min($calculatedTarget, $lastDividend * self::ARISTOCRAT_DISTRESS_REBASE_RATIO) : 0.0;
                $speed = min(1.0, $speed + 0.25);
            } elseif ($isModerateDistressNoCash || ($isCriticalCash && $calculatedTarget < $lastDividend)) {
                // Moderate distress or critical cash without CEO resistance: Rebase dividend to preserve capital without total elimination
                $targetDividend = min($calculatedTarget, $lastDividend * self::MODERATE_DISTRESS_REBASE_RATIO);
                $speed = min(1.0, $speed + 0.15);
            }
        }

        if ($lastDividend > 0 && $isAristocrat) {
            $catchUpRatio = $calculatedTarget / $lastDividend;
            if ($catchUpRatio > self::ARISTOCRAT_CATCHUP_THRESHOLD) {
                $speed = min(self::ARISTOCRAT_MAX_CATCHUP_SPEED, $speed + (($catchUpRatio - self::ARISTOCRAT_CATCHUP_THRESHOLD) * 0.10));
            }
        }

        $newDividend = max(0.0, $lastDividend + ($speed * ($targetDividend - $lastDividend)));

        $usableCash = max(0.0, $availableTreasury - $minOperatingCash);
        $distributableSurplus = max(0.0, (float) $stock->getRetainedEarnings() + ($quarterlyEps * $shares));

        $maxCashDividendPerShare = $shares > 0 ? ($usableCash / $shares) : 0.0;
        $maxLegalDividendPerShare = $shares > 0 ? ($distributableSurplus / $shares) : 0.0;

        $newDividend = min($newDividend, $maxCashDividendPerShare, $maxLegalDividendPerShare);
        $totalPaid = $newDividend * $shares;
        $event = null;

        if ($newDividend > 0.0) {
            $this->entityManager->getConnection()->executeStatement(
                "UPDATE users u
                 INNER JOIN (
                     SELECT user_id, SUM(total_qty) AS total_shares
                     FROM (
                         SELECT user_id, quantity AS total_qty FROM user_stocks WHERE stock_id = :stock_id
                         UNION ALL
                         SELECT user_id, quantity AS total_qty FROM trade_orders WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'
                     ) combined_shares
                     GROUP BY user_id
                 ) holdings ON u.id = holdings.user_id
                 SET u.cash_balance = u.cash_balance + (holdings.total_shares * :dividend)",
                ['dividend' => $newDividend, 'stock_id' => $stock->getId(), 'ticker' => $stock->getTicker()]
            );

            $stock->setLastDividend((string) $newDividend);
            $yield = (($newDividend * 4) / max($currentPrice, 0.01)) * 100;
            $totalPaidStr = $totalPaid >= 1_000_000_000 ? number_format($totalPaid / 1_000_000_000, 2) . 'B' : number_format($totalPaid / 1_000_000, 2) . 'M';

            $event = ['description' => "Paid $" . number_format($newDividend, 2) . "/share div (\${$totalPaidStr} total, " . number_format($yield, 2) . "% yield).", 'shock' => 0.0];
        } else {
            $stock->setLastDividend('0.00');
        }

        return ['dividend_per_share' => $newDividend, 'total_paid' => $totalPaid, 'event' => $event];
    }

    private function executeBuybacks(Stock $stock, float $treasury, float $targetOperatingCash, float $shares, float $currentPrice, float $currentPE, float $operatingBase, float $investedCapital, float $quarterlyNopat, array $health, \App\DTO\MacroStateDTO $macroState, float $actualTotalNetIncome = 0.0, float $retainedEarningsThisQuarter = 0.0): array
    {
        $excessCash = max(0.0, $treasury - $targetOperatingCash);
        $canEasilyCoverDebt = $excessCash > ((float) $stock->getTotalDebt() * 2.0);
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        if ($isFinancial) {
            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['equity_limit'] ?? 10.0;

            // Optimal leverage is structurally max(1.0, EquityLimit - 1.0). 
            // We allow buybacks up to a safe 0.5x overshoot before locking them out to preserve capital.
            $buybackLockoutThreshold = max(1.0, $equityLimit - 1.0) + 0.5;

            if ((float)$stock->getDebtToEquityRatio() > $buybackLockoutThreshold) {
                return ['new_shares' => $shares, 'total_cash_spent' => 0.0, 'event' => null];
            }
        }

        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $hoardStatus = $strategy->evaluateHoardingStatus($treasury, $targetOperatingCash, $operatingBase, (float) $stock->getTotalDebt());
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $isLiquidityCrisis = $health['interest_coverage'] < 1.0;
        $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
        $minBuybackIcr = $modelThresholds['buyback_min_icr'];

        if ($isLiquidityCrisis || (!$isHoarder && (($health['wants_to_paydown_debt'] && !$canEasilyCoverDebt) || $health['interest_coverage'] < $minBuybackIcr))) {
            return ['new_shares' => $shares, 'total_cash_spent' => 0.0, 'event' => null];
        }

        $totalCashSpent = 0.0;
        $event = null;

        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $trueReturn = $isFinancial ? (float) $stock->getRoeTtm() : (float) $stock->getRoicTtm();
        if ($trueReturn === 0.0) {
            $trueReturn = $strategy->calculateEconomicReturn($stock, $isFinancial ? $actualTotalNetIncome : $quarterlyNopat, $investedCapital);
        }
        $hurdleRate = $isFinancial ? ($health['cost_of_equity'] ?? 0.10) : $health['wacc'];
        $economicSpread = $trueReturn - $hurdleRate;

        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($hurdleRate, $trueReturn, 0.02);

        if (($economicSpread > 0.02 && $currentPE < ($fairValuePE + 3.0)) || $isHoarder) {
            $maxWillingSpend = $strategy->calculateMaxBuybackSpend($excessCash, $retainedEarningsThisQuarter, $isMegaHoarder);

            $marketCap = $shares * max($currentPrice, 0.01);
            $maxRegulatorySpend = $marketCap * ($isMegaHoarder ? 0.075 : ($isHoarder ? 0.05 : 0.015));

            // CAPITAL STRUCTURE MAINTENANCE (Modigliani-Miller / Trade-Off Theory): 
            // If the company is structurally under-leveraged (positive WACC arbitrage and ample ICR safety cushion),
            // they can aggressively deploy excess cash to buy back stock and optimize capital efficiency.
            $isUnderLeveraged = $health['is_under_leveraged'] ?? false;

            if ($isUnderLeveraged && $excessCash > 0) {
                $maxWillingSpend = max($maxWillingSpend, $excessCash * 0.50);
                $maxRegulatorySpend = max($maxRegulatorySpend, $marketCap * 0.05); // Allow 5% of market cap per quarter for recapitalization
            }

            $absoluteMaxSpend = min($maxWillingSpend, $maxRegulatorySpend);

            // SECURITY MEASURE: Do not allow buybacks to drive Book Equity below 0 (causes simulation math failure).
            $currentEquity = (float) $stock->getTotalEquity();
            $maxEquitySpend = max(0.0, $currentEquity * 0.50); // Never spend more than 50% of remaining equity
            $absoluteMaxSpend = min($absoluteMaxSpend, $maxEquitySpend);

            $valuationDiscount = max(0.0, ($fairValuePE - $currentPE) / max(1.0, $fairValuePE));
            // Hoarders ignore valuation discounts and always buy aggressively
            $aggression = $isMegaHoarder ? 1.0 : min(1.0, 0.50 + $valuationDiscount);
            $aggression = $archetypeStrategy->modifyBuybackAggression($aggression);

            // Hoarders bypass the random execution dampener
            $actualSpend = $absoluteMaxSpend * $aggression * ($isHoarder ? 1.0 : (mt_rand(50, 100) / 100.0));
            $sharesRepurchased = (int) floor($actualSpend / max($currentPrice, 0.01));

            if ($sharesRepurchased > 0) {
                $totalCashSpent = $sharesRepurchased * max($currentPrice, 0.01);
                $sharesStr = \bcsub((string) $stock->getSharesOutstanding(), (string) $sharesRepurchased, 8);
                $stock->setSharesOutstanding($sharesStr);
                $shares = (float) $sharesStr;

                $pctRetired = ($sharesRepurchased / ($shares + $sharesRepurchased)) * 100;
                $event = [
                    'description' => "Bought back " . number_format($sharesRepurchased) . " shares.",
                    'shock' => $pctRetired * 0.5
                ];
            }
        }

        return ['new_shares' => $shares, 'total_cash_spent' => $totalCashSpent, 'event' => $event];
    }
}
