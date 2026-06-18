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
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        // The Regulated Rate Base:
        // Regulators legally guarantee utilities a steady baseline ROIC (usually 8-10%) on their physical assets.
        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => max(0.01, (float) $stock->getBaselineRoic())
        ];
    }

    public function getMacroPhysics(Stock $stock, array $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        // Regulated Utilities are virtually immune to economic output gaps (people always need power/water)
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $beta = (float) $stock->getBeta();
        $physics['macro_demand_shift'] = $outputGap * $beta * 0.10;
        
        return $physics;
    }

    /**
     * Utility revenues are incredibly predictable. Weather causes minor fluctuations, but otherwise flat.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Volatility impact is sliced to just 3% of standard variance. (Highly stable)
        $revenueShock = $revenueZ * ($baselineVol * 0.03);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Regulatory Lag: 
        // It takes months/years to get rate hikes approved. During high inflation, their margins get temporarily compressed.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $regulatoryLagPenalty = $inflation > 0.03 ? ($inflation - 0.03) * 0.8 : 0.0;
        
        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin + $regulatoryLagPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }
}