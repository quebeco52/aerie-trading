<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Regulated Utilities (Water, Power, Gas).
 * 
 * Financial Physics:
 * - Legal monopolies with "Rate Base" regulation.
 * - ROIC is practically legally capped. They grow absolute earnings by deploying massive CapEx.
 * - Revenue is hyper-stable (zero correlation to economic output gaps).
 * - Authorized rate hikes lag behind inflation, causing temporary margin compression during high inflation.
 */
class UtilityBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Utility Rate Architecture ---
    /** Baseline fraction of revenue derived from regulated rate-base monopoly tariff distribution. */
    public const REGULATED_BASE_WEIGHT       = 0.85;
    /** Baseline fraction of revenue derived from unregulated merchant power generation and renewable PPAs. */
    public const UNREGULATED_MERCHANT_WEIGHT = 0.15;

    // --- Regulatory Lag & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to output gap for essential utility monopolies. */
    public const MACRO_DEMAND_SCALAR       = 0.25;
    /** Pricing power multiplier applied to inflation reflecting delayed rate hike approvals. */
    public const PRICING_POWER_LAG_SCALAR  = 0.25;
    /** Inflation buffer above target inflation before regulatory lag penalties begin compressing margins. */
    public const REGULATORY_LAG_BUFFER     = 0.01;
    /** Variable margin penalty multiplier applied to inflation exceeding the lag threshold. */
    public const REGULATORY_LAG_PENALTY    = 0.80;

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in hyper-stable utility models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.03;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into weather and minor regulatory earnings shocks. */
    public const ANALYST_BASE_VISIBILITY   = 0.20;
    /** Standard deviation of analyst estimation error for minor utility revenue shocks. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;

    // --- Rate Base Moat ---
    /** Operating margin mean reversion speed: slower speed reflects regulated rate of return structures. */
    public const REGULATED_REVERSION_SPEED = 2.0;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Regulated Utilities are virtually immune to economic output gaps (people always need power/water)
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $beta = (float) $stock->getBeta();
        $physics['macro_demand_shift'] = $outputGap * $beta * self::MACRO_DEMAND_SCALAR;

        // Regulatory Lag: Utilities do get rate hikes to cover inflation, but they are delayed.
        // We give them a very small fraction of normal pricing power (0.25x vs the standard 0.5x minimum)
        // so their nominal revenue grows slowly, but they still suffer the margin compression penalty 
        // during inflationary spikes because costs rise much faster than this tiny revenue bump.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $physics['pricing_power_multiplier'] = 1.0 + ($inflation * self::PRICING_POWER_LAG_SCALAR);

        return $physics;
    }

    /**
     * Utility revenues are incredibly predictable. Weather causes minor fluctuations, but otherwise flat.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $params = $this->resolveModelParameters($stock, [
            'regulated_base_weight'       => self::REGULATED_BASE_WEIGHT,
            'unregulated_merchant_weight' => self::UNREGULATED_MERCHANT_WEIGHT,
        ]);

        $regulatedWeight   = $params['regulated_base_weight'];
        $unregulatedWeight = $params['unregulated_merchant_weight'];

        // Independent stream Z-scores
        $regulatedZ   = $mathUtility->generateStandardNormal(); // Regulated tariff distribution volume (weather / seasonal)
        $unregulatedZ = $mathUtility->generateStandardNormal(); // Merchant wholesale electricity & PPA trading

        $regulatedRevenue   = $expectedRevenue * $regulatedWeight * (1.0 + ($regulatedZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $unregulatedRevenue = $expectedRevenue * $unregulatedWeight * (1.0 + ($unregulatedZ * ($baselineVol * (self::REVENUE_VARIANCE_SCALAR * 3.0))));
        $actualRevenue      = max(0.0, $regulatedRevenue + $unregulatedRevenue);

        // Regulatory Lag:
        // Applies specifically to regulated tariff distribution ($regulatedWeight).
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $lagThreshold = MacroEngine::TARGET_INFLATION + self::REGULATORY_LAG_BUFFER;
        $regulatoryLagPenalty = $inflation > $lagThreshold ? ($inflation - $lagThreshold) * self::REGULATORY_LAG_PENALTY * $regulatedWeight : 0.0;

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $regulatoryLagPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + (($regulatedZ * $regulatedWeight + $unregulatedZ * $unregulatedWeight) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR) * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $regulatoryLagPenalty));

        $primaryShockZ = abs($unregulatedZ) > abs($regulatedZ) ? $unregulatedZ : $regulatedZ;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $ebit,
            'primary_shock_z'                 => $primaryShockZ
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        return self::REGULATED_REVERSION_SPEED; // Regulated utility rate of return structures resist margin compression
    }
}

