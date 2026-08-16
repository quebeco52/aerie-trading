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
 * Earnings strategy for Integrated Freight & Contract Logistics (3PL).
 * 
 * Financial Physics:
 * - Extremely sensitive to global GDP, supply chain flow, and physical trade volumes.
 * - Tri-Stream Logistics Engine:
 *      1. Dedicated Fleet Contracts: Long-term committed freight volume with fuel surcharges.
 *      2. Spot Freight Brokerage: Highly volatile market-clearing rates; explodes during supply chain crunches.
 *      3. Value-Added Warehousing & 3PL: High-margin cross-docking, fulfillment, and automated inventory storage.
 * - Operating Leverage: Spot freight surges expand margins non-linearly over fixed fleet/warehouse overhead.
 */
class LogisticsBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50;
    public const BASE_COVERAGE_ERROR = 0.08;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.50, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.75, 'buyback_min_icr' => 2.50, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.15, 'capex_completion_rate' => 0.25];
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.025; }
    
    public function getCapexCyclicality(): float { return 0.60; } // Fleets, sorting hubs, and automated fulfillment centers
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from long-term dedicated fleet contracts. */
    public const DEDICATED_FLEET_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from volatile spot freight brokerage. */
    public const SPOT_BROKERAGE_WEIGHT  = 0.30;
    /** Baseline fraction of revenue derived from 3PL warehousing and automated cross-docking. */
    public const WAREHOUSING_3PL_WEIGHT = 0.10;

    // --- Physics & Variances ---
    public const DEDICATED_VARIANCE_SCALAR  = 0.20; // Stable contracted freight
    public const SPOT_VARIANCE_SCALAR       = 0.60; // Highly volatile spot market
    public const WAREHOUSING_VARIANCE_SCALAR = 0.10; // Sticky long-term storage

    // --- Fuel Surcharge & Energy Physics ---
    /** Margin penalty scalar applied to fleet operations when energy/diesel prices spike faster than fuel surcharges adjust. */
    public const FUEL_SURCHARGE_LAG_PENALTY = 0.35;

    public const SPOT_SURGE_Z_SCORE = 1.75;
    public const SPOT_SURGE_MULT    = 1.40; 

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::DedicatedFleetWeight->value => self::DEDICATED_FLEET_WEIGHT,
            ModelParam::SpotBrokerageWeight->value  => self::SPOT_BROKERAGE_WEIGHT,
            ModelParam::Warehousing3plWeight->value => self::WAREHOUSING_3PL_WEIGHT,
        ]);

        $fleetWeight = $params[ModelParam::DedicatedFleetWeight];
        $spotWeight  = $params[ModelParam::SpotBrokerageWeight];
        $whWeight    = $params[ModelParam::Warehousing3plWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta     = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'dedicated_fleet_contracts' => $params[ModelParam::DedicatedFleetWeight],
            'spot_freight_brokerage'    => $params[ModelParam::SpotBrokerageWeight],
            'value_added_warehousing'   => $params[ModelParam::Warehousing3plWeight],
        ]);

        $fleetWeight = $activeWeights['dedicated_fleet_contracts'];
        $spotWeight  = $activeWeights['spot_freight_brokerage'];
        $whWeight    = $activeWeights['value_added_warehousing'];

        // Macro GDP sensitivity
        $macroBoost = $macroState->outputGapEma * 1.5 * $beta;

        $fleetZ = $streams->generateZ('dedicated_fleet_contracts', 0.40);
        $spotZ  = $streams->generateZ('spot_freight_brokerage', 0.15);
        $whZ    = $streams->generateZ('value_added_warehousing', 0.50);

        $spotMultiplier = 1.0;
        $eventType = null;
        if ($spotZ > self::SPOT_SURGE_Z_SCORE) {
            $spotMultiplier = self::SPOT_SURGE_MULT;
            $eventType = ShockEvent::LOGISTICS_SURGE_PRICING ?? 'logistics_surge_pricing';
        }

        $fleetRevenue = max(0.0, $expectedRevenue * $fleetWeight * (1.0 + ($fleetZ * ($baselineVol * self::DEDICATED_VARIANCE_SCALAR)) + ($macroBoost * 0.8)));
        $spotRevenue  = max(0.0, $expectedRevenue * $spotWeight  * (1.0 + ($spotZ  * ($baselineVol * self::SPOT_VARIANCE_SCALAR)) + ($macroBoost * 1.5)) * $spotMultiplier);
        $whRevenue    = max(0.0, $expectedRevenue * $whWeight    * (1.0 + ($whZ    * ($baselineVol * self::WAREHOUSING_VARIANCE_SCALAR))));

        $streamRevenues = [
            'dedicated_fleet_contracts' => $fleetRevenue,
            'spot_freight_brokerage'    => $spotRevenue,
            'value_added_warehousing'   => $whRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Fuel Surcharge Lag Penalty: Fleet transport operations consume substantial diesel.
        // When energy prices spike (> 0), margins compress temporarily before customer surcharges adjust.
        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);
        $fuelLagDrag = $energyShift * self::FUEL_SURCHARGE_LAG_PENALTY * ($fleetWeight + $spotWeight);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $fuelLagDrag);

        $primaryShockZ = abs($spotZ) > abs($fleetZ) ? $spotZ : $fleetZ;
        if (abs($whZ) > abs($primaryShockZ)) {
            $primaryShockZ = $whZ;
        }

        $observableShockZ = ($fleetZ * $fleetWeight * self::DEDICATED_VARIANCE_SCALAR * $baselineVol) +
            ($spotZ * $spotWeight * self::SPOT_VARIANCE_SCALAR * $baselineVol) +
            ($macroBoost * $fleetWeight) -
            ($fuelLagDrag * 0.5);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }
}
