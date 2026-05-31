<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Regulated Utilities (Water, Power, Gas).
 * 
 * Financial Physics:
 * - Legal monopolies with "Rate Base" regulation.
 * - ROIC is practically legally capped. They grow absolute earnings by deploying massive CapEx.
 * - Revenue is hyper-stable (zero correlation to economic output gaps).
 * - Authorized rate hikes lag behind inflation, causing temporary margin compression during high inflation.
 */
class UtilityBusinessModel implements BusinessModelInterface
{
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        // Utilities operate on a "Rate Base" (Invested Capital). 
        // Regulators guarantee them a steady baseline ROIC (usually 8-10%) on this physical base.
        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => max(0.01, (float) $stock->getBaselineRoic())
        ];
    }

    /**
     * Utilities are isolated from the broader economy (Output Gap) because citizens always need water/power.
     * However, they suffer from "Regulatory Lag". When inflation spikes, their costs go up immediately, 
     * but they have to wait months for the government to approve a rate hike to compensate.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        
        // Inflation over 3% starts compressing margins due to regulatory lag.
        // They have almost zero pricing power during economic booms because their rates are capped.
        $regulatoryLagPenalty = $inflation > 0.03 ? ($inflation - 0.03) * 0.8 : 0.0;
        
        return -$regulatoryLagPenalty;
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
        
        $actualVariableCosts = $actualRevenue * $realizedVariableMargin;
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }

    // Uses Standard Corporate Logic for the rest
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        return (new StandardCorporateBusinessModel())->calculateInterestIncome($stock, $macroState, $mathUtility);
    }
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        return (new StandardCorporateBusinessModel())->updateDynamicRoic($stock, $actualTotalNetIncome, $investedCapital, $ebit, $corporateTaxRate);
    }
    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }
}