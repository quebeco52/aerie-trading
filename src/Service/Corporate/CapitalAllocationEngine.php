<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Macro\MacroEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\DTO\CapitalAllocationContext;
use App\DTO\MacroStateDTO;

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
        MacroStateDTO $macroState,
        float $actualTotalNetIncome = 0.0
    ): array {
        $ctx = new CapitalAllocationContext(
            $stock,
            $macroState,
            $actualAnnualEps,
            $quarterlyFcfPerShare,
            $currentPrice,
            $sharesOutstanding,
            $actualTotalNetIncome
        );

        $this->initializeContext($ctx);
        $this->executeDividends($ctx);
        $this->executeCorporateStrategy($ctx);
        $this->executeBuybacks($ctx);
        $this->finalizeLiquidity($ctx);

        return [
            'new_shares' => $ctx->newShares,
            'dividend_paid' => $ctx->newDividend,
            'total_paid' => $ctx->totalPaid,
            'total_cash_spent' => $ctx->totalCashSpent,
            'bank_apy' => $ctx->bankApy,
            'organic_capex' => $ctx->organicCapex,
            'events' => $ctx->events
        ];
    }

    private function initializeContext(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        
        $ctx->quarterlyEps = $ctx->actualAnnualEps / 4.0;
        $ctx->quarterlyNetIncome = $ctx->actualTotalNetIncome != 0.0 ? $ctx->actualTotalNetIncome : ($ctx->quarterlyEps * $ctx->sharesOutstanding);
        
        $ctx->currentTreasury = (float) $stock->getCorporateTreasury();
        $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
        $ctx->customerDeposits = (float) $stock->getCustomerDeposits();
        
        $ctx->operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $ctx->investedCapital = $stock->getInvestedCapital();
        
        $ctx->industry = $stock->getIndustry() ?: 'General';
        $ctx->businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$ctx->industry]['business_model'] ?? 'none';
        $ctx->isFinancial = \App\Data\Sectors::isFinancial($ctx->businessModel);
        $ctx->strategy = \App\Data\Sectors::getBusinessModelStrategy($ctx->businessModel);
        
        $dt = 0.25;
        $ctx->physicalAssetAppreciation = $ctx->investedCapital * ($ctx->macroState->inflationEma * $dt);
        
        if ($ctx->businessModel === 'reit') {
            $customDepreciation = (float) $stock->getDepreciationRate();
            $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($ctx->industry);
            $absoluteDepreciation = $ctx->investedCapital * $depRate;
            $ctx->physicalAssetAppreciation += ($absoluteDepreciation / 4.0);
        }
        
        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState);
        
        $ebit = $ctx->health->rawMetrics->ebit ?? 0.0;
        $nopat = $ebit > 0 ? $ebit * (1.0 - $ctx->macroState->corporateTaxRate) : $ebit;
        $ctx->quarterlyNopat = $nopat / 4.0;
        
        $totalFcfGenerated = $ctx->quarterlyFcfPerShare * $ctx->sharesOutstanding;
        $ctx->newTreasury = $ctx->currentTreasury + $totalFcfGenerated;
    }

    private function executeDividends(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        
        $targetPayout = (float) $stock->getTargetPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $isAristocrat = $speed <= 0.03;
        $targetPayout = $archetypeStrategy->modifyTargetPayoutRatio($targetPayout);

        $customDepreciation = (float) $stock->getDepreciationRate();
        $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($ctx->industry);

        $sustainableBase = $ctx->strategy->getSustainableDividendBase($stock, $ctx->quarterlyEps, $ctx->investedCapital, $depRate);
        if ($sustainableBase <= 0.0 && $ctx->quarterlyFcfPerShare > 0.0) {
            $sustainableBase = min($ctx->quarterlyFcfPerShare, $lastDividend / max(0.01, $targetPayout));
        }
        $calculatedTarget = $sustainableBase > 0 ? ($sustainableBase * $targetPayout) : 0.0;
        $targetDividend = $isAristocrat ? max($calculatedTarget, $lastDividend) : $calculatedTarget;

        $isRegulatoryDividendHalt = false;
        $trueReturn = $ctx->isFinancial ? (float) $stock->getRoeTtm() : (float) $stock->getRoicTtm();
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->isFinancial ? $ctx->actualTotalNetIncome : $ctx->quarterlyNopat, $ctx->investedCapital);
        }
        
        if ($ctx->isFinancial) {
            $hurdleRate = $ctx->health->costOfEquity ?? 0.10;

            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$ctx->industry]['equity_limit'] ?? 10.0;
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
            $hurdleRate = $ctx->health->wacc;
        }

        $evaSpread = $trueReturn - $hurdleRate;
        $distressMultiplier = $isAristocrat ? self::ARISTOCRAT_DISTRESS_MULTIPLIER : self::STANDARD_DISTRESS_MULTIPLIER;

        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hasCashBuffer = $ctx->newTreasury > ($minOperatingCash * self::CASH_BUFFER_SAFETY_MULT);
        $isCriticalCash = $ctx->newTreasury < $minOperatingCash;

        $isDeepDistress = $evaSpread < (self::DEEP_DISTRESS_EVA_SPREAD * $distressMultiplier);
        $isModerateDistressNoCash = ($evaSpread < (self::MODERATE_DISTRESS_EVA_SPREAD * $distressMultiplier)) && !$hasCashBuffer;

        $modelThresholds = \App\Data\Sectors::getModelThresholds($ctx->businessModel);
        $crisisThreshold = $modelThresholds['dividend_crisis_icr'];
        $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0 || ($ctx->health->interestCoverage < $crisisThreshold && !$hasCashBuffer);

        $resistsCut = $archetypeStrategy->shouldResistDividendCut($isLiquidityCrisis, $isRegulatoryDividendHalt, $isDeepDistress);

        if ($resistsCut) {
            $targetDividend = max($targetDividend, $lastDividend);
        } else {
            if ($isLiquidityCrisis || $isRegulatoryDividendHalt) {
                $targetDividend = 0.0;
                $speed = 1.0;
            } elseif ($isDeepDistress) {
                $targetDividend = $isAristocrat ? min($calculatedTarget, $lastDividend * self::ARISTOCRAT_DISTRESS_REBASE_RATIO) : 0.0;
                $speed = min(1.0, $speed + 0.25);
            } elseif ($isModerateDistressNoCash || ($isCriticalCash && $calculatedTarget < $lastDividend)) {
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

        $ctx->newDividend = max(0.0, $lastDividend + ($speed * ($targetDividend - $lastDividend)));

        $usableCash = max(0.0, $ctx->newTreasury - $minOperatingCash);
        $distributableSurplus = max(0.0, (float) $stock->getRetainedEarnings() + ($ctx->quarterlyEps * $ctx->sharesOutstanding));

        $maxCashDividendPerShare = $ctx->sharesOutstanding > 0 ? ($usableCash / $ctx->sharesOutstanding) : 0.0;
        $maxLegalDividendPerShare = $ctx->sharesOutstanding > 0 ? ($distributableSurplus / $ctx->sharesOutstanding) : 0.0;

        $ctx->newDividend = min($ctx->newDividend, $maxCashDividendPerShare, $maxLegalDividendPerShare);
        $ctx->totalPaid = $ctx->newDividend * $ctx->sharesOutstanding;

        if ($ctx->newDividend > 0.0) {
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
                ['dividend' => $ctx->newDividend, 'stock_id' => $stock->getId(), 'ticker' => $stock->getTicker()]
            );

            $stock->setLastDividend((string) $ctx->newDividend);
            $yield = (($ctx->newDividend * 4) / max($ctx->currentPrice, 0.01)) * 100;
            $totalPaidStr = $ctx->totalPaid >= 1_000_000_000 ? number_format($ctx->totalPaid / 1_000_000_000, 2) . 'B' : number_format($ctx->totalPaid / 1_000_000, 2) . 'M';

            $ctx->events[] = ['description' => "Paid $" . number_format($ctx->newDividend, 2) . "/share div (\${$totalPaidStr} total, " . number_format($yield, 2) . "% yield).", 'shock' => 0.0];
        } else {
            $stock->setLastDividend('0.00');
        }

        $ctx->newTreasury -= $ctx->totalPaid;
        $ctx->retainedEarningsThisQuarter = max(0.0, $ctx->quarterlyNetIncome - $ctx->totalPaid);
    }

    private function executeCorporateStrategy(CapitalAllocationContext $ctx): void
    {
        $this->treasuryEngine->executeCorporateStrategy($ctx);
        
        $ctx->targetOperatingCash = $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, (float) $ctx->stock->getCustomerDeposits(), (float) $ctx->stock->getWholesaleDebt());
        $ctx->excessCash = max(0.0, $ctx->newTreasury - $ctx->targetOperatingCash);
        
        if ($ctx->actualAnnualEps > 0) {
            $ctx->currentPE = $ctx->currentPrice / $ctx->actualAnnualEps;
        } else {
            $actualRevenue = (float) $ctx->stock->getTotalRevenue();
            $salesPerShare = $ctx->sharesOutstanding > 0 ? $actualRevenue / $ctx->sharesOutstanding : 1.0;
            $priceToSales = $salesPerShare > 0 ? $ctx->currentPrice / $salesPerShare : 1.0;
            $structuralAfterTaxMargin = max(0.01, (float) $ctx->stock->getOperatingMargin() * (1.0 - $ctx->macroState->corporateTaxRate));
            $ctx->currentPE = $priceToSales * (1.0 / $structuralAfterTaxMargin);
        }
    }

    private function executeBuybacks(CapitalAllocationContext $ctx): void
    {
        if ($ctx->businessModel === 'reit') {
            $ctx->newShares = $ctx->sharesOutstanding;
            $ctx->totalCashSpent = 0.0;
            return;
        }

        $stock = $ctx->stock;
        
        $canEasilyCoverDebt = $ctx->excessCash > ((float) $stock->getTotalDebt() * 2.0);

        if ($ctx->isFinancial) {
            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$ctx->industry]['equity_limit'] ?? 10.0;
            $buybackLockoutThreshold = max(1.0, $equityLimit - 1.0) + 0.5;

            if ((float)$stock->getDebtToEquityRatio() > $buybackLockoutThreshold) {
                $ctx->newShares = $ctx->sharesOutstanding;
                return;
            }
        }

        $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->newTreasury, $ctx->targetOperatingCash, $ctx->operatingBase, (float) $stock->getTotalDebt());
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0;
        $modelThresholds = \App\Data\Sectors::getModelThresholds($ctx->businessModel);
        $minBuybackIcr = $modelThresholds['buyback_min_icr'];

        if ($isLiquidityCrisis || (!$isHoarder && (($ctx->health->wantsToPaydownDebt && !$canEasilyCoverDebt) || $ctx->health->interestCoverage < $minBuybackIcr))) {
            $ctx->newShares = $ctx->sharesOutstanding;
            return;
        }

        $trueReturn = $ctx->isFinancial ? (float) $stock->getRoeTtm() : (float) $stock->getRoicTtm();
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->isFinancial ? $ctx->actualTotalNetIncome : $ctx->quarterlyNopat, $ctx->investedCapital);
        }
        $hurdleRate = $ctx->isFinancial ? ($ctx->health->costOfEquity ?? 0.10) : $ctx->health->wacc;
        $economicSpread = $trueReturn - $hurdleRate;

        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($hurdleRate, $trueReturn, 0.02);

        $ctx->newShares = $ctx->sharesOutstanding;

        if (($economicSpread > 0.02 && $ctx->currentPE < ($fairValuePE + 3.0)) || $isHoarder) {
            $maxWillingSpend = $ctx->strategy->calculateMaxBuybackSpend($excessCash, $ctx->retainedEarningsThisQuarter, $isMegaHoarder);

            $marketCap = $ctx->sharesOutstanding * max($ctx->currentPrice, 0.01);
            $maxRegulatorySpend = $marketCap * ($isMegaHoarder ? 0.075 : ($isHoarder ? 0.05 : 0.015));

            $isUnderLeveraged = $ctx->health->isUnderLeveraged ?? false;

            if ($isUnderLeveraged && $excessCash > 0) {
                $maxWillingSpend = max($maxWillingSpend, $excessCash * 0.50);
                $maxRegulatorySpend = max($maxRegulatorySpend, $marketCap * 0.05);
            }

            $absoluteMaxSpend = min($maxWillingSpend, $maxRegulatorySpend);

            $currentEquity = (float) $stock->getTotalEquity();
            $maxEquitySpend = max(0.0, $currentEquity * 0.50);
            $absoluteMaxSpend = min($absoluteMaxSpend, $maxEquitySpend);

            $valuationDiscount = max(0.0, ($fairValuePE - $ctx->currentPE) / max(1.0, $fairValuePE));
            $aggression = $isMegaHoarder ? 1.0 : min(1.0, 0.50 + $valuationDiscount);
            $aggression = $archetypeStrategy->modifyBuybackAggression($aggression);

            $actualSpend = $absoluteMaxSpend * $aggression * ($isHoarder ? 1.0 : (mt_rand(50, 100) / 100.0));
            $sharesRepurchased = (int) floor($actualSpend / max($ctx->currentPrice, 0.01));

            if ($sharesRepurchased > 0) {
                $ctx->totalCashSpent = $sharesRepurchased * max($ctx->currentPrice, 0.01);
                $sharesStr = \bcsub((string) $stock->getSharesOutstanding(), (string) $sharesRepurchased, 8);
                $stock->setSharesOutstanding($sharesStr);
                $ctx->newShares = (float) $sharesStr;

                $pctRetired = ($sharesRepurchased / ($ctx->sharesOutstanding + $sharesRepurchased)) * 100;
                $ctx->events[] = [
                    'description' => "Bought back " . number_format($sharesRepurchased) . " shares.",
                    'shock' => $pctRetired * 0.5
                ];
            }
        }
        
        $ctx->newTreasury -= $ctx->totalCashSpent;
    }

    private function finalizeLiquidity(CapitalAllocationContext $ctx): void
    {
        $this->treasuryEngine->finalizeLiquidity($ctx);
    }
}
