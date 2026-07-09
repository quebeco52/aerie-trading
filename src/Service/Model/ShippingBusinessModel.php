<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Global Maritime Shipping and Freight Logistics.
 * 
 * Financial Physics:
 * - Hyper-Cyclical Spot-Rate Operational Leverage: Revenues are violently levered to global GDP and the macro output gap.
 * - During economic expansions (+Output Gap), spot freight rates surge exponentially against fixed fleet overhead, producing massive Free Cash Flow explosions.
 * - During economic contractions (-Output Gap), capacity gluts and falling container rates cause severe operating losses.
 * - Heavy Physical Depreciation: Vessels and containers rust and degrade rapidly, requiring consistent, non-discretionary CapEx.
 */
class ShippingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Maritime Charter Architecture ---
    /** Baseline fraction of revenue derived from volatile spot market freight and short-term voyage charters. */
    public const SPOT_CHARTER_WEIGHT     = 0.50;
    /** Baseline fraction of revenue derived from long-term contracted time charters and dedicated logistics. */
    public const CONTRACT_CHARTER_WEIGHT = 0.50;

    // --- Hyper-Cyclical Spot Rate Physics ---
    /** Macroeconomic demand shift sensitivity to global trade output gaps. */
    public const MACRO_DEMAND_SCALAR       = 1.80;
    /** Minimum beta floor applied when calculating inflation pricing power. */
    public const MIN_PRICING_BETA_FLOOR    = 0.50;
    /** Volatility multiplier for top-line revenue shocks driven by maritime freight spot rates. */
    public const REVENUE_VARIANCE_SCALAR   = 0.25;
    /** Positive output gap threshold triggering exponential spot rate boom multipliers. */
    public const SPOT_BOOM_GAP_THRESHOLD   = 0.015;
    /** Output gap multiplier scaling spot freight rate surges during global trade booms. */
    public const SPOT_BOOM_RATE_MULT       = 4.00;
    /** Negative output gap threshold triggering vessel capacity glut penalties. */
    public const SPOT_GLUT_GAP_THRESHOLD   = -0.015;
    /** Output gap multiplier scaling rate collapses during trade slowdowns and capacity gluts. */
    public const SPOT_GLUT_RATE_MULT       = 3.00;

    // --- Fuel & Bunker Inflation Rails ---
    /** Variable cost penalty multiplier scaling bunker fuel inflation with stock beta. */
    public const BUNKER_INFLATION_SCALAR   = 0.80;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Event Lore Thresholds ---
    /** Positive z-score threshold required during trade booms to trigger port congestion lore. */
    public const LORE_CONGESTION_Z_SCORE   = 1.50;
    /** Negative z-score threshold required during trade gluts to trigger operating loss lore. */
    public const LORE_GLUT_Z_SCORE         = -1.50;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into daily public maritime freight indices like Baltic Dry or Harpex. */
    public const ANALYST_BASE_VISIBILITY   = 0.75;
    /** Minimum allowable analyst visibility floor for public shipping spot rate indices. */
    public const MIN_ANALYST_VISIBILITY    = 0.50;
    /** Standard deviation of analyst estimation error for quarterly shipping freight revenues. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;

    // --- Spot Cycle Reversion Moat ---
    /** Operating margin mean reversion speed: fast speed reflects rapid shipbuilding order responses. */
    public const SPOT_REVERSION_SPEED      = 4.0;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $beta = (float) $stock->getBeta();

        // Extreme sensitivity to global economic momentum and trade volume
        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_PRICING_BETA_FLOOR, $beta)),
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $params = $this->resolveModelParameters($stock, [
            'spot_charter_weight'     => self::SPOT_CHARTER_WEIGHT,
            'contract_charter_weight' => self::CONTRACT_CHARTER_WEIGHT,
        ]);

        $spotWeight     = $params['spot_charter_weight'];
        $contractWeight = $params['contract_charter_weight'];

        // Independent stream Z-scores
        $spotZ     = $mathUtility->generateStandardNormal(); // Spot ocean freight / Baltic Dry variance
        $contractZ = $mathUtility->generateStandardNormal(); // Multi-year contracted logistics lines

        // Spot Rate Super-Cycle vs. Capacity Glut
        // Crucially, spot rate boom/glut multipliers apply specifically to spot charter revenue ($spotWeight).
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $spotRateMultiplier = 0.0;
        $eventLore = null;

        if ($outputGap > self::SPOT_BOOM_GAP_THRESHOLD) {
            $spotRateMultiplier = $outputGap * self::SPOT_BOOM_RATE_MULT;
            if ($spotZ > self::LORE_CONGESTION_Z_SCORE) {
                $eventLore = "Capitalized on severe global port congestion with record-breaking container spot rates.";
            }
        } elseif ($outputGap < self::SPOT_GLUT_GAP_THRESHOLD) {
            $spotRateMultiplier = $outputGap * self::SPOT_GLUT_RATE_MULT;
            if ($spotZ < self::LORE_GLUT_Z_SCORE) {
                $eventLore = "Suffered operating losses due to a severe global vessel capacity glut and collapsing freight rates.";
            }
        }

        $spotRevenue     = $expectedRevenue * $spotWeight * (1.0 + ($spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $spotRateMultiplier);
        $contractRevenue = $expectedRevenue * $contractWeight * (1.0 + ($contractZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $actualRevenue   = max(0.0, $spotRevenue + $contractRevenue);

        // Fuel and Bunker Cost Inflation:
        // Shipping is directly exposed to crude oil and commodity inflation.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $bunkerInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION ? ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::BUNKER_INFLATION_SCALAR : 0.0;

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $bunkerInflationPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Global shipping spot indices (e.g., Baltic Dry Index, Harpex) are public daily data (~75% visibility).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(self::MIN_ANALYST_VISIBILITY, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + (($spotZ * $spotWeight + $spotRateMultiplier * $spotWeight) * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + ($bunkerInflationPenalty * $dynamicVisibility)));

        $primaryShockZ = abs($spotZ) > abs($contractZ) ? $spotZ : $contractZ;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $ebit,
            'primary_shock_z'                 => $primaryShockZ,
            'event_lore'                      => $eventLore
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        // Spot rate booms attract new shipbuilding orders, causing margins to mean-revert aggressively once new vessels launch
        return self::SPOT_REVERSION_SPEED;
    }
}

