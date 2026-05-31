<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Heavy Extractors and Refiners (Oil, Copper, Steel).
 * 
 * Financial Physics:
 * - Ultimate "Price Takers." They have zero ability to set their own prices.
 * - Revenue and margins are violently driven by global supply and demand (The Commodity Supercycle).
 * - INFLATION IS A BLESSING: While standard corporates get crushed by supply chain inflation, 
 *   commodities *are* the supply chain, meaning their margins explode upwards during inflationary spikes.
 */
class CommodityBusinessModel implements BusinessModelInterface
{
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        // Physical scale is dictated by their massive invested capital (Mines, Oil Rigs, Blast Furnaces)
        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => max(0.01, (float) $stock->getBaselineRoic())
        ];
    }

    /**
     * The Commodity Supercycle. 
     * High inflation and economic booms cause massive raw material demand, sending margins soaring.
     * Conversely, during a recession (negative output gap), raw material spot prices collapse.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();
        
        // Unlike normal companies that suffer an inflation penalty, commodities capture it.
        // They absorb systemic inflation directly into their bottom line.
        $inflationCapture = max(0.0, ($inflation - 0.02) * abs($beta) * 2.0);
        
        return ($outputGap * $beta * 1.5) + $inflationCapture;
    }

    /**
     * Commodity revenues are highly volatile due to wild swings in global spot prices.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Higher top-line variance compared to standard retail/manufacturing
        $revenueShock = $revenueZ * ($baselineVol * 0.25);
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

    // Uses Standard Corporate Logic for the rest (Taxes, Liquidity, and ROIC calculation)
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