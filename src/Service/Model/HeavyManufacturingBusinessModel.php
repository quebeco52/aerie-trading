<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
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
    public function getCapexCyclicality(): float
    {
        return 4.0;
    } // Much higher than standard 1.5
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.015;
    } // Slightly lower secular growth, highly cyclical

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from large-ticket OEM capital equipment manufacturing. */
    public const OEM_EQUIPMENT_WEIGHT = 0.65;
    /** Baseline fraction of revenue derived from high-margin aftermarket MRO parts and service contracts. */
    public const AFTERMARKET_MRO_WEIGHT = 0.35;

    // --- Backlog & Variance Physics ---
    /** Fraction of OEM revenue shocks absorbed by multi-quarter order backlogs. */
    public const BACKLOG_DAMPING_FACTOR = 0.50;
    /** Volatility multiplier for OEM capital equipment sales shocks. */
    public const OEM_VARIANCE_SCALAR = 0.40;
    /** Volatility multiplier for defensive aftermarket MRO consumables and repairs. */
    public const MRO_VARIANCE_SCALAR = 0.10;
    /** Structural variable cost ratio of high-margin aftermarket MRO parts. */
    public const MRO_VARIABLE_COST_RATIO = 0.20;

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
            ModelParam::PricingPowerIndex->value   => self::MIN_BETA_PRICING_POWER_FLOOR,
            ModelParam::OemEquipmentWeight->value  => self::OEM_EQUIPMENT_WEIGHT,
            ModelParam::AftermarketMroWeight->value => self::AFTERMARKET_MRO_WEIGHT,
        ]);

        $oemWeight     = $params[ModelParam::OemEquipmentWeight];
        $mroWeight     = $params[ModelParam::AftermarketMroWeight];
        $pricingPower  = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'oem_equipment'   => $params[ModelParam::OemEquipmentWeight],
            'aftermarket_mro' => $params[ModelParam::AftermarketMroWeight],
        ]);

        $oemWeight = $activeWeights['oem_equipment'];
        $mroWeight = $activeWeights['aftermarket_mro'];

        // Independent stream Z-scores
        $oemZ = $streams->generateZ('oem_equipment', 0.20);
        $mroZ = $streams->generateZ('aftermarket_mro', 0.40);

        $dampedOemShock = ($oemZ * ($baselineVol * self::OEM_VARIANCE_SCALAR)) * (1.0 - self::BACKLOG_DAMPING_FACTOR);
        $mroShock       = $mroZ * ($baselineVol * self::MRO_VARIANCE_SCALAR);

        $oemRevenue = max(0.0, $expectedRevenue * $oemWeight * (1.0 + $dampedOemShock));
        $mroRevenue = max(0.0, $expectedRevenue * $mroWeight * (1.0 + $mroShock));
        $streamRevenues = [
            'oem_equipment'   => $oemRevenue,
            'aftermarket_mro' => $mroRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Structural Margin Blending:
        // MRO consumables operate at structurally low variable cost (high margin).
        // OEM equipment carries the heavy direct manufacturing and metal/assembly cost.
        $expectedMroRevenue = $expectedRevenue * $mroWeight;
        $expectedOemRevenue = $expectedRevenue * $oemWeight;
        $mroBaselineCosts   = $expectedMroRevenue * self::MRO_VARIABLE_COST_RATIO;
        $targetTotalCosts   = $expectedRevenue * $realizedVariableMargin;
        $oemBaselineCosts   = max(0.0, $targetTotalCosts - $mroBaselineCosts);
        $oemVariableMargin  = $expectedOemRevenue > 0 ? ($oemBaselineCosts / $expectedOemRevenue) : $realizedVariableMargin;

        $actualVariableCosts = ($mroRevenue * self::MRO_VARIABLE_COST_RATIO) + ($oemRevenue * $oemVariableMargin);

        // Supply Chain Energy Penalty: Heavy industry relies heavily on energy and commodities.
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;
        $baseInflationPenalty = $energyShift > 0 ? $energyShift * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin + $inflationPenalty);

        $primaryShockZ = abs($oemZ) > abs($mroZ) ? $oemZ : $mroZ;
        $observableShockZ = ($oemZ * $oemWeight * self::OEM_VARIANCE_SCALAR * (1.0 - self::BACKLOG_DAMPING_FACTOR)) +
            ($mroZ * $mroWeight * self::MRO_VARIANCE_SCALAR);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }
}
