<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Heavy Manufacturing and Heavy Industry (e.g. Autos, Heavy Machinery, Specialty Chemicals, Steel).
 * 
 * Financial Physics:
 * - High Operating Leverage: Massive fixed costs mean margins compress violently during recessions but explode during booms.
 * - Pro-Cyclical: Highly sensitive to the macro output gap (GDP).
 * - High CapEx Cyclicality: Huge reinvestment requirements that scale with cycles.
 */
class HeavyManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    public const BASE_COVERAGE_ERROR = 0.08;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.12, 'moat_spread' => 0.010, 'nwc_intensity' => 0.15, 'capex_completion_rate' => 0.125];
    }
    // --- CapEx & Asset Physics ---
    public function getCapexCyclicality(): float { return 4.0; } // Much higher than standard 1.5
    public function getSecularGrowthRate(Stock $stock): float { return 0.015; } // Slightly lower secular growth, highly cyclical

    // --- Pricing Power & Macro Physics ---
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.80; // Highly sensitive to macro shifts

    // --- Revenue & Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR = 0.35; // Volatile sales
    public const INFLATION_PENALTY_SCALAR = 0.80; // Input costs hit hard

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        // Heavy manufacturing is extremely sensitive to the output gap (pro-cyclical)
        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();
        
        // Amplify the output gap impact significantly
        $physics['macro_demand_shift'] = $outputGap * $beta * 1.75; 
        
        return $physics;
    }
    
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'pricing_power_index' => self::MIN_BETA_PRICING_POWER_FLOOR, 
        ]);

        $pricingPower = max(0.0, min(1.0, $params['pricing_power_index']));
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0); 
        $macroSensitivityMultiplier = 0.5 + $pricingPower;

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $revenueZ = $mathUtility->generatePersistentZ($momentum['revenue'] ?? 0.0, 0.25);
        
        $revenueShock = ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR));
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);

        // Supply Chain Energy Penalty
        // Heavy industry relies heavily on energy and commodities. We use energyPriceIndexEma instead of general inflation.
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;
        $baseInflationPenalty = $energyShift > 0 ? $energyShift * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;
        
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inflationPenalty);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $revenueZ,
            observableShockZ: $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR),
            eventType: null,
            streamZ: [
                'revenue' => $revenueZ,
            ],
            streamRevenue: [
                'core_business' => $actualRevenue,
            ],
        );
    }
}
