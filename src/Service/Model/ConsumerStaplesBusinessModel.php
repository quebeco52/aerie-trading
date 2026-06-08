<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Consumer Staples (Food, Tobacco, Household Goods).
 * 
 * Financial Physics:
 * - Inelastic demand: Consumers must buy these products regardless of the economic cycle.
 * - High pricing power: They can pass supply chain inflation directly to consumers without losing sales volume.
 * - Extremely low top-line volatility compared to discretionary retail.
 */
class ConsumerStaplesBusinessModel extends StandardCorporateBusinessModel
{
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Inelastic Demand:
        // Top-line variance is heavily muted because people always buy groceries and tobacco.
        // Volatility impact is sliced to just 5% of standard variance.
        $revenueShock = $revenueZ * ($baselineVol * 0.05); 
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Absolute Pricing Power:
        // Unlike standard corporates, consumer staples do not suffer an inflation penalty.
        // They simply raise prices at the grocery store, preserving their margins perfectly.
        
        // Tail Risk: Product Recalls and Health Regulations
        $eventZ = $mathUtility->generateStandardNormal();
        $eventLore = null;
        $recallPenalty = 0.0;
        
        if ($eventZ < -2.5) {
            $recallPenalty = 0.08; // 8% margin hit for massive product recall and write-offs
            $eventLore = "Suffered a massive product recall due to severe supply chain contamination.";
        } elseif ($eventZ < -2.0) {
            $eventLore = "Faced sudden regulatory scrutiny and fines over product health concerns.";
        }

        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin + $recallPenalty));
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
