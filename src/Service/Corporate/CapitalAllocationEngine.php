<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Macro\MacroEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
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
        private CorporateLedgerService $corporateLedgerService,
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
        float $actualTotalNetIncome = 0.0,
        float $stockCompensation = 0.0
    ): array {
        $ctx = new CapitalAllocationContext(
            $stock,
            $macroState,
            $actualAnnualEps,
            $quarterlyFcfPerShare,
            $currentPrice,
            $sharesOutstanding,
            $actualTotalNetIncome,
            $stockCompensation
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
        $isAristocrat = $speed <= 0.03;

        $customDepreciation = (float) $stock->getDepreciationRate();
        $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($ctx->industry);

        $trueReturn = (float) $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->strategy->getReturnBasisIncome($stock, $ctx->quarterlyNopat, $ctx->actualTotalNetIncome), $ctx->investedCapital);
        }

        // Life-Cycle Payout Target Expansion (DeAngelo & DeAngelo 2006 / Jensen 1986)
        // As a firm approaches market saturation, internal reinvestment slows and target payout scales toward cash cow levels.
        $evaluationCapital = $ctx->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $ctx->investedCapital);
        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $ctx->macroState);
        $saturationSeverity = $this->corporateMetrics->calculateSaturationSeverity($saturationPenalty, $trueReturn);
        $effectiveTargetPayout = $this->corporateMetrics->calculateLifeCyclePayoutRatio($targetPayout, $saturationSeverity);

        // Life-cycle gate (Dickinson 2011): a pre-profit firm funding itself with outside capital does not
        // initiate distributions; every dollar goes back into the business until operations turn cash positive.
        $lastStage = $stock->getLifecycleStage();
        if ($lastStage !== null && !$lastStage->initiatesDistributions()) {
            $effectiveTargetPayout = 0.0;
        }

        $sustainableBase = $ctx->strategy->getSustainableDividendBase($stock, $ctx->quarterlyEps, $ctx->investedCapital, $depRate);
        if ($sustainableBase <= 0.0 && $ctx->quarterlyFcfPerShare > 0.0) {
            $sustainableBase = min($ctx->quarterlyFcfPerShare, $lastDividend / max(0.01, $effectiveTargetPayout));
        }
        $calculatedTarget = $sustainableBase > 0 ? ($sustainableBase * $effectiveTargetPayout) : 0.0;
        $targetDividend = $isAristocrat ? max($calculatedTarget, $lastDividend) : $calculatedTarget;

        $isRegulatoryDividendHalt = false;
        
        $regulatoryCap = $ctx->strategy->getRegulatoryDividendCap($stock, $ctx->newTreasury);
        if ($regulatoryCap !== null) {
            if ($regulatoryCap <= 0.0) {
                $isRegulatoryDividendHalt = true;
            } else {
                $targetDividend = min($targetDividend, $sustainableBase * min($effectiveTargetPayout, $regulatoryCap));
            }
        }

        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);

        $evaSpread = $trueReturn - $hurdleRate;
        $distressMultiplier = $isAristocrat ? self::ARISTOCRAT_DISTRESS_MULTIPLIER : self::STANDARD_DISTRESS_MULTIPLIER;

        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hasCashBuffer = $ctx->newTreasury > ($minOperatingCash * self::CASH_BUFFER_SAFETY_MULT);
        $isCriticalCash = $ctx->newTreasury < $minOperatingCash;

        $isDeepDistress = $evaSpread < (self::DEEP_DISTRESS_EVA_SPREAD * $distressMultiplier);
        $isModerateDistressNoCash = ($evaSpread < (self::MODERATE_DISTRESS_EVA_SPREAD * $distressMultiplier)) && !$hasCashBuffer;

        $crisisThreshold = $ctx->strategy->getDividendCrisisIcr();
        $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0 || ($ctx->health->interestCoverage < $crisisThreshold && !$hasCashBuffer);

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

        if ($lastDividend > 0 && $isAristocrat) {
            $catchUpRatio = $calculatedTarget / $lastDividend;
            if ($catchUpRatio > self::ARISTOCRAT_CATCHUP_THRESHOLD) {
                $speed = min(self::ARISTOCRAT_MAX_CATCHUP_SPEED, $speed + (($catchUpRatio - self::ARISTOCRAT_CATCHUP_THRESHOLD) * 0.10));
            }
        } elseif (!$isAristocrat && $saturationSeverity > 0.20 && $calculatedTarget > $lastDividend) {
            $speed = min(1.0, $speed + ($saturationSeverity * 0.10));
        }

        $ctx->newDividend = max(0.0, $lastDividend + ($speed * ($targetDividend - $lastDividend)));

        $usableCash = max(0.0, $ctx->newTreasury - $minOperatingCash);
        $distributableSurplus = max(0.0, (float) $stock->getRetainedEarnings() + ($ctx->quarterlyEps * $ctx->sharesOutstanding));

        $maxCashDividendPerShare = $ctx->sharesOutstanding > 0 ? ($usableCash / $ctx->sharesOutstanding) : 0.0;
        $maxLegalDividendPerShare = $ctx->sharesOutstanding > 0 ? ($distributableSurplus / $ctx->sharesOutstanding) : 0.0;

        $ctx->newDividend = min($ctx->newDividend, $maxCashDividendPerShare, $maxLegalDividendPerShare);
        $ctx->totalPaid = $ctx->newDividend * $ctx->sharesOutstanding;

        if ($ctx->newDividend > 0.0) {
            $this->corporateLedgerService->processDividendPayment($stock, $ctx->newDividend);

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

        if ($ctx->strategy->checkBuybackRegulatoryLockout($stock, $ctx->newTreasury)) {
            $ctx->newShares = $ctx->sharesOutstanding;
            return;
        }

        $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->newTreasury, $ctx->targetOperatingCash, $ctx->operatingBase, (float) $stock->getTotalDebt());
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        $isUnderLeveraged = $ctx->health->isUnderLeveraged ?? false;

        $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0;
        $minBuybackIcr = $ctx->strategy->getBuybackMinIcr();

        if ($isLiquidityCrisis || (!$isHoarder && (($ctx->health->wantsToPaydownDebt && !$canEasilyCoverDebt) || $ctx->health->interestCoverage < $minBuybackIcr))) {
            if (!($ctx->strategy->isFinancial() && $isUnderLeveraged)) {
                $ctx->newShares = $ctx->sharesOutstanding;
                return;
            }
        }

        $trueReturn = (float) $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->strategy->getReturnBasisIncome($stock, $ctx->quarterlyNopat, $ctx->actualTotalNetIncome), $ctx->investedCapital);
        }
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $economicSpread = $trueReturn - $hurdleRate;

        $evaluationCapital = $ctx->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $ctx->investedCapital);
        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $ctx->macroState);
        $saturationSeverity = $this->corporateMetrics->calculateSaturationSeverity($saturationPenalty, $trueReturn);

        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($hurdleRate, $trueReturn, 0.02);

        $ctx->newShares = $ctx->sharesOutstanding;

        if (($economicSpread > 0.02 && $ctx->currentPE < ($fairValuePE + 3.0)) || $isHoarder || $isUnderLeveraged || $saturationSeverity > 0.20) {
            $maxWillingSpend = $ctx->strategy->calculateMaxBuybackSpend($excessCash, $ctx->retainedEarningsThisQuarter, $isMegaHoarder);

            // Saturation Buyback Unlock: Mature firms distribute non-reinvestable excess cash
            if ($saturationSeverity > 0.05) {
                $saturationSpendRatio = $isMegaHoarder
                    ? FinancialConstants::BUYBACK_SPEND_MEGA_SATURATED_RATIO
                    : FinancialConstants::BUYBACK_SPEND_SATURATED_RATIO;
                $saturationWillingSpend = $excessCash * $saturationSpendRatio * $saturationSeverity;
                $maxWillingSpend = max($maxWillingSpend, $saturationWillingSpend);
            }

            $marketCap = $ctx->sharesOutstanding * max($ctx->currentPrice, 0.01);
            $baseRegulatoryPct = $isMegaHoarder ? 0.075 : ($isHoarder ? 0.05 : 0.015);
            $effectiveRegulatoryPct = $baseRegulatoryPct + (FinancialConstants::MAX_REGULATORY_SPEND_SATURATED - $baseRegulatoryPct) * $saturationSeverity;
            $maxRegulatorySpend = $marketCap * $effectiveRegulatoryPct;

            if ($isUnderLeveraged) {
                // Under-leveraged financials must crush equity bloat via buybacks to restore ROE.
                // They can fund this from retained earnings even when idle excess cash is zero.
                $recapBudget = max($excessCash, $ctx->retainedEarningsThisQuarter);
                $maxWillingSpend = max($maxWillingSpend, $recapBudget);
                $maxRegulatorySpend = max($maxRegulatorySpend, $marketCap * 0.05);
            }

            $absoluteMaxSpend = min($maxWillingSpend, $maxRegulatorySpend);

            $currentEquity = (float) $stock->getTotalEquity();
            $maxEquitySpend = max(0.0, $currentEquity * 0.50);
            $absoluteMaxSpend = min($absoluteMaxSpend, $maxEquitySpend);

            // Hard Solvency Constraint: Cannot spend more cash than physically available in treasury above min operating buffer
            $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
            $availableCashBuffer = max(0.0, $ctx->newTreasury - $minOperatingCash);
            $absoluteMaxSpend = min($absoluteMaxSpend, $availableCashBuffer);

            $valuationDiscount = max(0.0, ($fairValuePE - $ctx->currentPE) / max(1.0, $fairValuePE));
            $aggression = $isMegaHoarder ? 1.0 : min(1.0, 0.50 + $valuationDiscount);

            $actualSpend = $absoluteMaxSpend * $aggression * ($isHoarder ? 1.0 : (mt_rand(50, 100) / 100.0));
            $sharesRepurchased = (int) floor($actualSpend / max($ctx->currentPrice, 0.01));
            
            // Cap buybacks at 95% of currently outstanding shares per quarter.
            $maxSharesToBuy = (int) floor($ctx->sharesOutstanding * 0.95);
            $sharesRepurchased = min($sharesRepurchased, $maxSharesToBuy);

            if ($sharesRepurchased > 0) {
                $ctx->totalCashSpent = $sharesRepurchased * max($ctx->currentPrice, 0.01);
                $newSharesVal = max(1.0, (float) $stock->getSharesOutstanding() - $sharesRepurchased);
                $sharesStr = (string) $newSharesVal;
                $stock->setSharesOutstanding($sharesStr);
                $ctx->newShares = $newSharesVal;

                $pctRetired = ($sharesRepurchased / $ctx->sharesOutstanding) * 100;
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
