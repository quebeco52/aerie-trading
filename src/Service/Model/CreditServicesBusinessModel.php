<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Credit Services (Credit Cards, Consumer Finance).
 * 
 * Financial Physics:
 * - Hybrid model relying on interest spreads (like a bank) and transaction volume (like a brokerage).
 * - Revenue directly benefits from inflation because interchange/swipe fees are a percentage of total price.
 * - Highly vulnerable to economic downturns (negative output gap) due to a spike in unsecured loan defaults.
 * - Evaluated on Return on Equity (ROE).
 */
class CreditServicesBusinessModel extends CommercialBankBusinessModel
{
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // The Inflation Bonus (Interchange Fees):
        // Credit Services capture inflation directly into their REVENUE. 
        // Because swipe fees (Visa/Mastercard) are a percentage of the total transaction size, higher prices = higher revenue.
        $inflation = $macroState['inflation_ema'] ?? ($macroState['inflation'] ?? 0.02);
        $inflationBonus = max(0.0, ($inflation - 0.02) * abs((float) $stock->getBeta()));
        
        // Moderate the random revenue fluctuations (higher than banks, but not pure chaos)
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.20)) + $inflationBonus);
        
        $defaultZ = $mathUtility->generateStandardNormal();
        
        // Unsecured Default Shock:
        // Credit card debt is unsecured. During recessions, consumers default on cards long before they default on mortgages.
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $macroDefaultDrag = $outputGap < 0.0 ? abs($outputGap) * 0.8 : 0.0;
        
        $lossProvisionShock = ($defaultZ < -1.5 ? abs($defaultZ) * 0.10 : ($defaultZ > 1.0 ? -0.02 : 0.0)) + $macroDefaultDrag;
        
        // Net Interest Margin (NIM) Squeeze.
        // 0.5x higher then banks
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? 0.04);
        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? 0.03);
        
        $bankSpread = $yield10y - $yield2y;
        $nimSqueeze = (0.005 - $bankSpread) * 1.5;
        
        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin + $lossProvisionShock + $nimSqueeze));
        
        $eventLore = null;
        if ($defaultZ < -2.0) {
            $eventLore = "Took massive provisions for unsecured credit defaults as consumer health deteriorated.";
        } elseif ($defaultZ < -1.5) {
            $eventLore = "Elevated credit card defaults compressed quarterly margins.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }
}