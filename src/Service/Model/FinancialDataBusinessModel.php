<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Financial Data & Stock Exchanges (Rating Agencies, Market Data).
 * 
 * Financial Physics:
 * - Asset-light, ultra-high margin monopolies.
 * - Revenue is incredibly sticky due to mandatory recurring subscriptions and licensing fees.
 * - Immune to supply chain inflation (they sell digital data, not physical goods).
 * - Exceptionally low idiosyncratic variance.
 */
class FinancialDataBusinessModel extends StandardCorporateBusinessModel
{
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Subscription Stickiness:
        // Revenue variance is drastically reduced because institutions are locked into multi-year data contracts.
        // Drops the volatility impact to just 5% of standard variance.
        $revenueShock = $revenueZ * ($baselineVol * 0.05);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Pricing Power (Immunity to Inflation):
        // Unlike physical corporates, data monopolies have zero supply chain costs.
        // They pass inflation directly to consumers without margin compression, so we omit the inflation penalty entirely.
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }
}