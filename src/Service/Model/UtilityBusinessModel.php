<?php

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

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Regulated Utilities are virtually immune to economic output gaps (people always need power/water)
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $beta = (float) $stock->getBeta();
        $physics['macro_demand_shift'] = $outputGap * $beta * 0.25;

        // Regulatory Lag: Utilities do get rate hikes to cover inflation, but they are delayed.
        // We give them a very small fraction of normal pricing power (0.25x vs the standard 0.5x minimum)
        // so their nominal revenue grows slowly, but they still suffer the margin compression penalty 
        // during inflationary spikes because costs rise much faster than this tiny revenue bump.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $physics['pricing_power_multiplier'] = 1.0 + ($inflation * 0.25);

        return $physics;
    }

    /**
     * Utility revenues are incredibly predictable. Weather causes minor fluctuations, but otherwise flat.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        // Volatility impact is sliced to just 3% of standard variance. (Highly stable)
        $revenueShock = $revenueZ * ($baselineVol * 0.03);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);

        // Regulatory Lag: 
        // It takes months/years to get rate hikes approved. During high inflation, their margins get temporarily compressed.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        // Utilities lag slightly, so penalty kicks in slightly above target
        $lagThreshold = MacroEngine::TARGET_INFLATION + 0.01;
        $regulatoryLagPenalty = $inflation > $lagThreshold ? ($inflation - $lagThreshold) * 0.8 : 0.0;

        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin + $regulatoryLagPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'ebit' => $ebit,
            'primary_shock_z' => $revenueZ
        ];
    }
}
