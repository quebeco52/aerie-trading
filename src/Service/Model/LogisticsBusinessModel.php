<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Integrated Freight & Logistics.
 * 
 * Financial Physics:
 * - Extremely sensitive to global GDP and physical trade volumes.
 * - Revenue is a mix of high-volume parcel/freight shipping and highly lucrative algorithmic surge pricing.
 * - Margins expand aggressively when supply chains are constrained and demand is high (surge pricing kicks in).
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
    
    public function getCapexCyclicality(): float { return 0.6; } // High capex for fleets/warehouses
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Stream Weights ---
    public const PARCEL_WEIGHT = 0.80;
    public const SURGE_WEIGHT = 0.20;

    // --- Physics ---
    public const PARCEL_VARIANCE_SCALAR = 0.40; // Cyclical
    public const SURGE_VARIANCE_SCALAR = 2.50; // Extremely volatile

    public const SURGE_PRICING_Z_SCORE = 1.75;
    public const SURGE_PRICING_MULT = 1.80; 

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Logistics is perfectly correlated to the output gap (economic expansion)
        $macroBoost = $macroState->outputGapEma * 1.5;

        $parcelZ = $mathUtility->generatePersistentZ($momentum['parcel_volume'] ?? 0.0, 0.30) + $macroBoost;
        $surgeZ = $mathUtility->generatePersistentZ($momentum['algorithmic_surge_pricing'] ?? 0.0, 0.05); 

        $parcelRevenue = $expectedRevenue * self::PARCEL_WEIGHT * (1.0 + ($parcelZ * ($baselineVol * self::PARCEL_VARIANCE_SCALAR)));
        $surgeRevenue = $expectedRevenue * self::SURGE_WEIGHT * (1.0 + ($surgeZ * ($baselineVol * self::SURGE_VARIANCE_SCALAR)));

        $eventType = null;

        if ($surgeZ > self::SURGE_PRICING_Z_SCORE) {
            $surgeRevenue *= self::SURGE_PRICING_MULT;
            $eventType = ShockEvent::LOGISTICS_SURGE_PRICING ?? 'logistics_surge_pricing';
        }

        $actualRevenue = max(0.0, $parcelRevenue + $surgeRevenue);
        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        $primaryShockZ = abs($surgeZ) > abs($parcelZ) ? $surgeZ : $parcelZ;
        $observableShockZ = ($parcelZ * self::PARCEL_WEIGHT * self::PARCEL_VARIANCE_SCALAR) * $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'parcel_volume'               => $parcelZ,
                'algorithmic_surge_pricing'   => $surgeZ,
            ],
            streamRevenue: [
                'parcel_volume'               => $parcelRevenue,
                'algorithmic_surge_pricing'   => $surgeRevenue,
            ],
        );
    }
}
