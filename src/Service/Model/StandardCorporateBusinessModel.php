<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for normal, non-financial companies.
 * 
 * Financial Physics:
 * - Evaluated on Return on Invested Capital (ROIC).
 * - Subject to supply chain inflation and physical depreciation.
 * - Operating scale is based on physical assets, not financial leverage.
 */
class StandardCorporateBusinessModel extends AbstractBusinessModel
{
    // --- ROIC & Target Metrics ---
    /** Weight given to historical baseline ROIC when blending with TTM ROIC. */
    public const BASELINE_ROIC_WEIGHT = 0.70;
    /** Weight given to TTM ROIC when blending with historical baseline ROIC. */
    public const TTM_ROIC_WEIGHT      = 0.30;

    // --- Pricing Power & Macro Physics ---
    /** Minimum beta floor applied when calculating pricing power resistance to inflation. */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.50;

    // --- Revenue & Shock Physics ---
    /** Variance scalar applied to baseline volatility for sales volume shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;
    /** Sensitivity scalar for supply chain inflation cost penalties during high CPI/PPI regimes. */
    public const INFLATION_PENALTY_SCALAR = 0.50;
    /** Upper clamp for realized variable margin under severe supply chain inflation. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into product demand shocks via high-frequency retail foot traffic. */
    public const ANALYST_BASE_VISIBILITY = 0.20;
    /** Standard deviation of analyst estimation error for volume shocks. */
    public const ANALYST_ERROR_STD_DEV   = 0.05;

    // --- DCF & Valuation Rails ---
    /** Assumed perpetual terminal growth rate for DCF fair value estimation. */
    public const DCF_TERMINAL_GROWTH_RATE = 0.02;
    /** Cap on DCF valuation relative to P/E fair value to prevent infinite perpetual expansion. */
    public const MAX_DCF_TO_PE_CAP_MULT   = 1.50;
    /** Valuation discount applied when FCF is negative due to heavy capex or burn. */
    public const NEGATIVE_FCF_VAL_DISCOUNT = 0.75;

    /**
     * Physical businesses evaluate their true structural scale based on Invested Capital 
     * (Total Equity + Debt - Cash), requiring physical assets to turn a profit.
     */
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        $ttmRoic = (float) $stock->getRoicTtm();
        if ($ttmRoic !== 0.0) {
            $baselineRoic = ($baselineRoic * self::BASELINE_ROIC_WEIGHT) + ($ttmRoic * self::TTM_ROIC_WEIGHT);
        }

        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => $baselineRoic
        ];
    }

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();

        return [
            'macro_demand_shift' => $outputGap * $beta,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_BETA_PRICING_POWER_FLOOR, $beta)),
        ];
    }

    /**
     * Idiosyncratic variance is applied directly to sales volume.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $revenueShock = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);

        // Supply Chain Inflation Penalty:
        // Physical companies get squeezed by inflation because raw material and labor costs rise 
        // faster than they can safely raise prices on consumers without destroying demand.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $inflationPenalty = $inflation > MacroEngine::TARGET_INFLATION ? ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $inflationPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Supply chain inflation is fully visible via CPI/PPI reports. 
        // Individual product demand shocks are partially visible via retail foot traffic (~20% visibility).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + ($revenueShock * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $inflationPenalty));

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'analyst_expected_revenue' => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit' => $ebit,
            'primary_shock_z' => $revenueZ
        ];
    }


    /**
     * Normal physical companies are evaluated on NOPAT / Invested Capital (ROIC).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        // NOPAT (Net Operating Profit After Tax) strips out interest expense to measure 
        // the pure operating efficiency of the physical business assets.
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * 4.0;

        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::TTM_SMOOTHING_NEW_WEIGHT) + ($oldTtm * self::TTM_SMOOTHING_OLD_WEIGHT);
        $stock->setRoicTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Standard corporates pay market interest rates on ALL of their debt. 
        // They do not get the benefit of cheap customer deposits like banks do.
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;

        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $wholesaleRate];
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, self::DCF_TERMINAL_GROWTH_RATE);
            // The FCF passed from EarningsEngine is Quarterly. We MUST annualize it!
            $annualFcf = $fcfPerShare * 4.0;
            // Cap the DCF so a temporary lack of CapEx doesn't cause an infinite perpetual valuation.
            $dcfFairValue = min(max(0.01, $annualFcf * $multiplier), $peFairValue * self::MAX_DCF_TO_PE_CAP_MULT);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue) * self::NEGATIVE_FCF_VAL_DISCOUNT : max($revenueFloorValue, $peFairValue);
    }
}

