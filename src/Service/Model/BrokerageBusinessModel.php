<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Brokerages & Capital Markets.
 * 
 * Financial Physics:
 * - Highly leveraged, transaction-driven business model.
 * - Revenue scales off trading volume, investment banking advisory, and margin loans.
 * - Evaluated on Return on Equity (ROE).
 */
class BrokerageBusinessModel extends AssetManagementBusinessModel
{
    /**
     * Idiosyncratic shock applied to retail trading volume and institutional deal flow.
     * Capital Markets have higher top-line variance compared to sticky Asset Managers.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // The Volatility Bonus (Trading Volume):
        // Brokerage revenues are hyper-sensitive to the VIX (Systemic Market Volatility). 
        // High Volatility = Massive trading volume (panic selling or euphoria buying) which generates massive fees.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? 0.20);
        $volatilityBonus = max(0.0, ($vixEma - 0.20) * 0.5); // Direct revenue boost from average quarterly trading volume
        
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.20)) + $volatilityBonus);

        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin));

        $eventLore = null;
        if ($vixEma > 0.30) {
            $eventLore = "Record trading volumes driven by extreme market volatility resulted in massive fee generation.";
        } elseif ($revenueZ < -2.0) {
            $eventLore = "Suffered a steep decline in investment banking deal flow and advisory fees.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => $revenueZ,
            'event_lore' => $eventLore
        ];
    }
}