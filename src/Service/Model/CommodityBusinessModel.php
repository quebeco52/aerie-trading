<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Heavy Extractors and Refiners (Oil, Copper, Steel).
 * 
 * Financial Physics:
 * - Ultimate "Price Takers." They have zero ability to set their own prices.
 * - Revenue and margins are violently driven by global supply and demand (The Commodity Supercycle).
 * - INFLATION IS A BLESSING: While standard corporates get crushed by supply chain inflation, 
 *   commodities *are* the supply chain, meaning their margins explode upwards during inflationary spikes.
 */
class CommodityBusinessModel extends StandardCorporateBusinessModel
{
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        // Physical scale is dictated by their massive invested capital (Mines, Oil Rigs, Blast Furnaces)
        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => max(0.01, (float) $stock->getBaselineRoic())
        ];
    }

    public function getMacroPhysics(Stock $stock, array $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        // Commodities are ultimate price takers. They perfectly capture supply chain inflation directly into top-line revenue.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();
        
        $physics['pricing_power_multiplier'] = 1.0 + ($inflation * max(0.5, $beta) * 1.5);
        
        return $physics;
    }

    /**
     * Commodity revenues are highly volatile due to wild swings in global spot prices.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Higher top-line variance compared to standard retail/manufacturing
        $revenueShock = $revenueZ * ($baselineVol * 0.25);
        
        // The Inflation Blessing:
        // While standard corporates get crushed by supply chain inflation, commodities *are* the supply chain. 
        // Their margins explode upwards during inflationary spikes as spot prices rise.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $inflationBonus = max(0.0, ($inflation - 0.02) * abs((float) $stock->getBeta()) * 2.0);
        
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock + $inflationBonus);
        
        $actualVariableCosts = $actualRevenue * $realizedVariableMargin;
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }
}