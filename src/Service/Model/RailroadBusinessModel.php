<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Class 1 Freight Railroads & Rail Infrastructure.
 * 
 * Financial Physics:
 * - High Barrier to Entry / Geographic Duopolies: Captive track networks grant immense pricing power.
 * - Tri-Stream Freight Architecture:
 *      1. Intermodal Freight (Containers, Consumer Goods): Highly GDP & trade cyclical.
 *      2. Bulk Commodities (Grain, Coal, Fertilizer): Inelastic agricultural & energy harvest cycles.
 *      3. Industrial Carloads (Automotive, Steel, Chemicals): Heavy manufacturing volume.
 * - Dynamic Operating Ratio (OR) & Fuel Surcharge Lag: Diesel fuel price spikes cause temporary
 *   margin compression before contractual fuel surcharges pass the cost through.
 * - High Capital Intensity: Massive rail line, locomotive, and switching yard maintenance CapEx.
 */
class RailroadBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.60;
    public const BASE_COVERAGE_ERROR = 0.08;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.5,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.020, 'nwc_intensity' => 0.15, 'capex_completion_rate' => 0.30];
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.015; }
    
    public function getCapexCyclicality(): float { return 0.70; } // Heavy rail maintenance CapEx
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from cyclical intermodal container shipping. */
    public const INTERMODAL_WEIGHT = 0.40;
    /** Baseline fraction of revenue derived from inelastic agricultural and energy bulk carloads. */
    public const BULK_COMMODITIES_WEIGHT = 0.40;
    /** Baseline fraction of revenue derived from heavy industrial and automotive carloads. */
    public const INDUSTRIAL_CARLOAD_WEIGHT = 0.20;

    // --- Physics & Variances ---
    public const INTERMODAL_VARIANCE_SCALAR = 0.30;
    public const BULK_VARIANCE_SCALAR       = 0.15;
    public const INDUSTRIAL_VARIANCE_SCALAR = 0.25;

    // --- Fuel Surcharge & Operating Ratio ---
    public const FUEL_SURCHARGE_LAG_PENALTY = 0.06;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::IntermodalFreightWeight->value  => self::INTERMODAL_WEIGHT,
            ModelParam::BulkCommoditiesWeight->value    => self::BULK_COMMODITIES_WEIGHT,
            ModelParam::IndustrialCarloadsWeight->value => self::INDUSTRIAL_CARLOAD_WEIGHT,
        ]);

        $intermodalWeight = $params[ModelParam::IntermodalFreightWeight];
        $bulkWeight       = $params[ModelParam::BulkCommoditiesWeight];
        $industrialWeight = $params[ModelParam::IndustrialCarloadsWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta     = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'intermodal_freight'  => $params[ModelParam::IntermodalFreightWeight],
            'bulk_commodities'    => $params[ModelParam::BulkCommoditiesWeight],
            'industrial_carloads' => $params[ModelParam::IndustrialCarloadsWeight],
        ]);

        $intermodalWeight = $activeWeights['intermodal_freight'];
        $bulkWeight       = $activeWeights['bulk_commodities'];
        $industrialWeight = $activeWeights['industrial_carloads'];

        // Independent stream Z-scores
        $intermodalZ = $streams->generateZ('intermodal_freight', 0.20);
        $bulkZ       = $streams->generateZ('bulk_commodities', 0.40);
        $industrialZ = $streams->generateZ('industrial_carloads', 0.30);

        // Macro cyclicality
        $freightShift = ($macroState->freightRateIndexEma - 100.0) / 100.0;
        $agriShift = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;

        $intermodalMacroShift = ($macroState->outputGapEma * 1.6 * $beta) + ($freightShift * 0.20);
        $industrialMacroShift = $macroState->outputGapEma * 1.2 * $beta;
        $bulkMacroShift = $agriShift * 0.30;

        $intermodalRevenue = max(0.0, $expectedRevenue * $intermodalWeight * (1.0 + ($intermodalZ * ($baselineVol * self::INTERMODAL_VARIANCE_SCALAR)) + $intermodalMacroShift));
        $bulkRevenue       = max(0.0, $expectedRevenue * $bulkWeight       * (1.0 + ($bulkZ * ($baselineVol * self::BULK_VARIANCE_SCALAR)) + $bulkMacroShift));
        $industrialRevenue = max(0.0, $expectedRevenue * $industrialWeight * (1.0 + ($industrialZ * ($baselineVol * self::INDUSTRIAL_VARIANCE_SCALAR)) + $industrialMacroShift));

        $streamRevenues = [
            'intermodal_freight'  => $intermodalRevenue,
            'bulk_commodities'    => $bulkRevenue,
            'industrial_carloads' => $industrialRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Diesel fuel surcharge lag: Railroads consume massive quantities of diesel.
        // Spikes in energy price index create temporary margin compression before 60-day fuel surcharges adjust.
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;
        $fuelLagDrag = $energyShift > 0 ? $energyShift * self::FUEL_SURCHARGE_LAG_PENALTY : 0.0;

        $clampedMargin = $this->clampMargin($realizedVariableMargin - $fuelLagDrag);

        // Max magnitude shock
        $primaryShockZ = abs($intermodalZ) > abs($bulkZ) ? $intermodalZ : $bulkZ;
        if (abs($industrialZ) > abs($primaryShockZ)) {
            $primaryShockZ = $industrialZ;
        }

        $observableShockZ = ($intermodalZ * $intermodalWeight * self::INTERMODAL_VARIANCE_SCALAR) +
            ($bulkZ * $bulkWeight * self::BULK_VARIANCE_SCALAR) +
            ($intermodalMacroShift * $intermodalWeight);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }
}
