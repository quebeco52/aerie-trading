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

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        // CRITICAL FIX: Commodities are absolute price takers. They have zero traditional pricing power.
        // We set this to 1.0 because their top-line revenue is already dynamically forced up and down 
        // by global spot prices ($inflationBonus) during the Idiosyncratic Shock phase. 
        // Setting this higher would result in massive, compounded double-dipping on inflation.
        $physics['pricing_power_multiplier'] = 1.0;
        
        return $physics;
    }

    /**
     * Commodity revenues are highly volatile due to wild swings in global spot prices.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Higher top-line variance compared to standard retail/manufacturing
        $revenueShock = $revenueZ * ($baselineVol * 0.25);
        
        // The Inflation Exposure:
        // While standard corporates get crushed by supply chain inflation, commodities *are* the supply chain. 
        // Their margins explode upwards during inflationary spikes as spot prices rise, and violently contract during deflation.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $inflationBonus = ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * 1.0;
        
        // The physical volume of commodities extracted/sold (subject to standard operational variance)
        $physicalVolumeRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Actual revenue explodes upward during inflation and crashes during deflation due to spot prices
        $actualRevenue = max(0.0, $physicalVolumeRevenue + ($expectedRevenue * $inflationBonus));
        
        // CRITICAL FINANCIAL FIX: 
        // Variable extraction costs (labor, diesel, equipment) scale with the physical volume produced,
        // NOT the wildly fluctuating global spot price of the refined commodity. 
        // By decoupling variable costs from the inflation premium, operating margins properly explode 
        // during a commodity supercycle, just like real life.
        $actualVariableCosts = $physicalVolumeRevenue * $realizedVariableMargin;
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }
}