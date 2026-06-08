<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Defense Contractors & Government Security.
 * 
 * Financial Physics:
 * - Revenue is locked into multi-decade government budgets.
 * - Cost-Plus Contracts: Inflation is actually a positive, because the government guarantees 
 *   a fixed percentage margin ON TOP of whatever the materials cost.
 * - Immune to consumer recessions.
 */
class DefenseContractorBusinessModel extends StandardCorporateBusinessModel
{
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Government Contracts: Extremely low base volatility
        $revenueShock = $revenueZ * ($baselineVol * 0.05);
        
        // Cost-Plus Contracting (The Inflation Blessing):
        // If inflation drives up the cost of building a fighter jet, the contractor's absolute profit 
        // goes UP, because their margin is a guaranteed percentage of the total inflated cost.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $costPlusBonus = max(0.0, ($inflation - 0.02) * 1.5);
        
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock + $costPlusBonus);
        
        // Tail Risk: Geopolitical Contract Wins/Losses
        $eventZ = $mathUtility->generateStandardNormal();
        $eventLore = null;
        
        if ($eventZ < -2.5) {
            $actualRevenue *= 0.90; // Lost a massive 10% contract
            $eventLore = "Lost a multi-billion dollar next-generation government defense contract to a rival.";
        } elseif ($eventZ > 2.5) {
            $actualRevenue *= 1.10; // Won a massive 10% contract
            $eventLore = "Secured a massive, multi-decade international defense contract.";
        }

        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => abs($eventZ) > abs($revenueZ) ? $eventZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }
}
