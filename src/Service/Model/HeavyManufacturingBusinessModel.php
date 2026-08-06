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
}
