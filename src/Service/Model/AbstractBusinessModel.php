<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\StockModelTuning;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Base class for all Business Models to reduce code duplication for standard financial physics.
 */
abstract class AbstractBusinessModel implements BusinessModelInterface
{

    // --- ROIC & Return Smoothing ---
    /** Weight for newly realized return when smoothing TTM metrics. */
    public const TTM_SMOOTHING_NEW_WEIGHT = 0.25;
    /** Weight for historical return when smoothing TTM metrics. */
    public const TTM_SMOOTHING_OLD_WEIGHT = 0.75;

    // --- Capital Allocation & Buybacks ---
    /** Maximum fraction of excess cash spent on buybacks by normal cash hoarders. */
    public const BUYBACK_SPEND_NORMAL_RATIO       = 0.10;
    /** Maximum fraction of excess cash spent on buybacks by mega hoarders. */
    public const BUYBACK_SPEND_MEGA_HOARDER_RATIO = 0.30;
    /** Minimum fraction of debt proceeds allocated to organic capex. */
    public const ORGANIC_CAPEX_DEBT_RATIO         = 0.75;

    // --- Debt Expansion & Leverage Rules ---
    /** Base probability of debt expansion when spreads are favorable. */
    public const DEBT_EXPANSION_BASE_PROB = 0.40;
    /** Multiplier scaling debt expansion probability with spread attractiveness. */
    public const DEBT_EXPANSION_PROB_MULT = 0.50;
    /** Base aggressiveness fraction for debt issuance. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.05;
    /** Multiplier scaling debt issuance aggressiveness with spread attractiveness. */
    public const DEBT_EXPANSION_AGGR_MULT = 0.35;
    /** Required risk premium buffer between after-tax cost of debt and cost of equity. */
    public const WACC_ARBITRAGE_BUFFER    = 0.01;
    /** Threshold fraction of target debt tolerance below which financial firms are considered under-leveraged. */
    public const FINANCIAL_UNDERLEVERAGED_RATIO = 0.80;
    /** Threshold fraction of target debt tolerance below which standard corporates are considered under-leveraged. */
    public const CORPORATE_UNDERLEVERAGED_RATIO = 0.75;
    /** Minimum ICR safety multiplier over sector baseline required before adding leverage. */
    public const REQUIRED_ICR_SAFETY_MULT       = 1.50;
    /** Absolute minimum ICR buffer required for non-financial leverage expansion. */
    public const MIN_ABSOLUTE_ICR_BUFFER        = 2.00;

    // --- Valuation & Margins ---
    /** Weight given to earnings valuation in standard corporate fair value calculations. */
    public const FAIR_VALUE_EARNINGS_WEIGHT = 0.90;
    /** Weight given to book value in standard corporate fair value calculations. */
    public const FAIR_VALUE_BOOK_WEIGHT     = 0.10;
    /** Weight given to Dividend Discount Model yield support when blending standard corporate fair value. */
    public const FAIR_VALUE_DDM_WEIGHT      = 0.15;
    /** Default operating margin mean reversion speed (quarters). */
    public const DEFAULT_MARGIN_REVERSION_SPEED = 4.0;

    // --- Margin Clamping ---
    /** Upper bound ceiling clamp for variable operating margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower bound floor clamp for variable operating margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    /**
     * Clamps raw variable margin within allowable bounds.
     */
    public function clampMargin(
        float $rawMargin,
        float $minMargin = self::MIN_VARIABLE_MARGIN_CLAMP,
        float $maxMargin = self::MAX_VARIABLE_MARGIN_CLAMP
    ): float {
        return min($maxMargin, max($minMargin, $rawMargin));
    }

    /**
     * Template Method orchestrating sector physics execution and margin clamping.
     * Analyst consensus is no longer computed here — it is the sole responsibility of MarketConsensusEngine.
     */
    public final function computeActualFinancials(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): ActualFinancialsDTO {
        $physics = $this->calculateSectorPhysics(
            $stock,
            $expectedRevenue,
            $realizedVariableMargin,
            $fixedCosts,
            $baselineVol,
            $macroState,
            $mathUtility
        );

        $clampedMargin       = $this->clampMargin($physics->rawVariableMargin);
        $actualVariableCosts = $physics->actualRevenue * $clampedMargin;
        $ebit                = $physics->actualRevenue - $fixedCosts - $actualVariableCosts;

        return new ActualFinancialsDTO(
            actualRevenue: $physics->actualRevenue,
            actualVariableCosts: $actualVariableCosts,
            clampedMargin: $clampedMargin,
            ebit: $ebit,
            primaryShockZ: $physics->primaryShockZ,
            observableShockZ: $physics->observableShockZ,
            eventType: $physics->eventType,
            eventContext: $physics->eventContext,
            isPublicEvent: $physics->isPublicEvent,
        );
    }

    /**
     * Returns the analyst coverage profile for this sector.
     * Override in child classes to declare sector-specific visibility parameters.
     */
    public function getCoverageProfile(): SectorCoverageProfile
    {
        return new SectorCoverageProfile(
            baseVisibility: 0.20,
            errorStdDev: 0.06,
        );
    }

    abstract protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult;

    public function getEffectiveTaxRate(float $macroTaxRate): float

    {
        return $macroTaxRate;
    }

    /**
     * Calculates the annualized economic return (ROIC or ROE).
     * @param float $quarterlyNopatOrIncome Quarterly NOPAT (for non-financials) or Quarterly Net Income (for financials).
     * @param float $investedCapital Annual/structural invested capital or equity base.
     */
    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopatOrIncome, float $investedCapital): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        if (\App\Data\Sectors::isFinancial($businessModel)) {
            $equity = (float) $stock->getTotalEquity();
            return $equity > 0 ? ($quarterlyNopatOrIncome / $equity) * 4.0 : 0.0;
        }
        return $investedCapital > 0 ? ($quarterlyNopatOrIncome / $investedCapital) * 4.0 : 0.0;
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $kappa = \App\Data\Sectors::getModelThresholds($businessModel)['reversion_speed'] ?? 0.20;

        if (\App\Data\Sectors::isFinancial($businessModel)) {
            $equity = (float) $stock->getTotalEquity();
            $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * 4.0 : 0.0;

            $stock->setCurrentRoe((string) max(-0.50, min(1.0, $truePostTaxReturn)));

            $oldTtm = (float) $stock->getRoeTtm();
            $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::TTM_SMOOTHING_NEW_WEIGHT) + ($oldTtm * self::TTM_SMOOTHING_OLD_WEIGHT);
            // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
            $effectiveKappa = $kappa / (defined('static::TTM_ROE_WEIGHT') ? static::TTM_ROE_WEIGHT : 0.50);
            $newTtm += $effectiveKappa * ($wacc - $newTtm) * 0.25;
            $stock->setRoeTtm((string) max(-0.50, min(1.0, $newTtm)));

            return $truePostTaxReturn;
        }

        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;
        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * 4.0;

        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::TTM_SMOOTHING_NEW_WEIGHT) + ($oldTtm * self::TTM_SMOOTHING_OLD_WEIGHT);
        // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
        $effectiveKappa = $kappa / (defined('static::TTM_ROIC_WEIGHT') ? static::TTM_ROIC_WEIGHT : 0.50);
        $newTtm += $effectiveKappa * ($wacc - $newTtm) * 0.25;
        $stock->setRoicTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }

    protected function getOperatingBase(Stock $stock): float
    {
        return max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH);
    }

    /**
     * Resolves model parameters by merging class baseline defaults with any company-specific tuning overrides.
     *
     * @param array<string, float> $defaults
     * @return array<string, float>
     */
    protected function resolveModelParameters(Stock $stock, array $defaults = []): array
    {
        return StockModelTuning::resolve($stock->getTicker(), $defaults);
    }

    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $operatingBase = $this->getOperatingBase($stock);

        // Default behavior: Cash above target operating cash earns money-market yields.
        $targetCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, $cash - $targetCash);

        return $excessCash * $this->calculateCashYield($macroState);
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * FinancialConstants::TARGET_OPERATING_CASH_RATIO;
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * FinancialConstants::MIN_OPERATING_CASH_RATIO;
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($operatingBase * FinancialConstants::HOARDER_THRESHOLD_RATIO),
            'is_mega_hoarder' => $excessCash > ($operatingBase * FinancialConstants::MEGA_HOARDER_THRESHOLD_RATIO),
        ];
    }

    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float
    {
        return 0.0;
    }

    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float
    {
        return 1.0;
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * self::BUYBACK_SPEND_MEGA_HOARDER_RATIO : $excessCash * self::BUYBACK_SPEND_NORMAL_RATIO;
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
    {
        return $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);
    }

    public function calculateCashYield(MacroStateDTO $macroState): float
    {
        return max(0.0, $macroState->policyRateEma - MacroEngine::CASH_YIELD_SPREAD);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        return [
            'probability' => self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT),
            'aggressiveness' => self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier)
        ];
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return max($organicSpend, $debtIssued * self::ORGANIC_CAPEX_DEBT_RATIO);
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Real financial theory dictates that companies optimize their WACC by maintaining a target capital structure.
        // They do NOT self-fund CapEx with cash if issuing debt lowers their cost of capital.
        // Instead, they issue debt to maintain their optimal Debt/Equity ratio, and use excess cash to buy back shares.
        return $baseCapacity;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        $baseConsensus = ($earningsValue * self::FAIR_VALUE_EARNINGS_WEIGHT) + ($pbFairValue * self::FAIR_VALUE_BOOK_WEIGHT);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - self::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * self::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
    }

    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float
    {
        return $quarterlyEps;
    }

    public function getMarginReversionSpeed(): float
    {
        return self::DEFAULT_MARGIN_REVERSION_SPEED;
    }

    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void
    {
        // No-op by default
    }

    public function isUnderLeveraged(bool $isFinancial, float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // 1. WACC Arbitrage & Tax Shield Principle (Modigliani-Miller / Trade-Off Theory):
        // Leverage is only value-accretive if the after-tax Cost of Debt is cheaper than the Cost of Equity.
        // We require a minimum 1.0% (0.01) risk premium buffer between Ke and Kd(after-tax).
        if ($costOfEquity <= ($effectiveCostOfDebt + self::WACC_ARBITRAGE_BUFFER)) {
            return false;
        }

        if ($isFinancial) {
            // For financial institutions, debt is raw material (deposits & wholesale borrowing).
            // They are under-leveraged when their leverage ratio is safely below capital adequacy limits
            // and equity optimization requires deploying cheaper wholesale debt.
            return $currentDebtRatio < ($targetDebtTolerance * self::FINANCIAL_UNDERLEVERAGED_RATIO);
        }

        // 2. Operating Cash Flow Serviceability Principle (ICR Safety Buffer):
        // Even with low balance sheet debt, a non-financial firm must not recapitalize if operating cash flows
        // cannot comfortably cover interest obligations. We require ICR to be at least 1.5x the sector minimum.
        $requiredIcrBuffer = max(self::MIN_ABSOLUTE_ICR_BUFFER, $minIcr * self::REQUIRED_ICR_SAFETY_MULT);
        if ($interestCoverage < $requiredIcrBuffer) {
            return false;
        }

        // 3. Target Capital Structure Deficit Principle:
        // A firm is under-leveraged when its Debt/Equity ratio is below 75% of its CFO-modified target tolerance.
        return $currentDebtRatio < ($targetDebtTolerance * self::CORPORATE_UNDERLEVERAGED_RATIO);
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.05; // Standard baseline net working capital intensity (5% of incremental revenue)
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        // By default, standard service/corporate businesses experience minimal asset capacity decay.
        // Capital-intensive heavy industries override this method with physical plant decay physics.
    }
}
