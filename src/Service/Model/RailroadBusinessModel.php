<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Railroads & Civic Lines.
 * 
 * Financial Physics:
 * - Highly capital intensive but enjoys near-monopoly pricing power in captive areas.
 * - Revenue is a mix of highly sticky commuter subscription passes, volatile single-ride tickets, and cyclical real estate monetization.
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

    public function getSecularGrowthRate(Stock $stock): float { return 0.01; }
    
    public function getCapexCyclicality(): float { return 0.7; } // Massive fixed costs
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Stream Weights ---
    public const SUBSCRIPTION_WEIGHT = 0.60;
    public const WALKUP_WEIGHT = 0.25;
    public const REAL_ESTATE_WEIGHT = 0.15;

    // --- Physics ---
    public const SUBSCRIPTION_VARIANCE_SCALAR = 0.05; // Extremely stable
    public const WALKUP_VARIANCE_SCALAR = 0.80; // Volatile, weather/tourism dependent
    public const REAL_ESTATE_VARIANCE_SCALAR = 1.20; // Cyclical, RE market dependent

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);

        $macroBoost = $macroState->outputGapEma * 0.8; // Macro drives walk-up and real estate

        $subscriptionZ = $streams->generateZ('subscription_revenue', 0.50);
        $walkupZ       = $streams->generateZ('walk_up_tickets', 0.10); 
        $realEstateZ   = $streams->generateZ('real_estate_development', 0.30); 

        $subscriptionRevenue = $expectedRevenue * self::SUBSCRIPTION_WEIGHT * (1.0 + ($subscriptionZ * ($baselineVol * self::SUBSCRIPTION_VARIANCE_SCALAR)));
        $walkupRevenue       = $expectedRevenue * self::WALKUP_WEIGHT       * (1.0 + ($walkupZ * ($baselineVol * self::WALKUP_VARIANCE_SCALAR)) + ($macroBoost * 0.5));
        $realEstateRevenue   = $expectedRevenue * self::REAL_ESTATE_WEIGHT  * (1.0 + ($realEstateZ * ($baselineVol * self::REAL_ESTATE_VARIANCE_SCALAR)) + $macroBoost);

        $actualRevenue = max(0.0, $subscriptionRevenue + $walkupRevenue + $realEstateRevenue);
        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        // Max magnitude shock
        $primaryShockZ = $realEstateZ;
        if (abs($walkupZ) > abs($primaryShockZ)) $primaryShockZ = $walkupZ;
        if (abs($subscriptionZ) > abs($primaryShockZ)) $primaryShockZ = $subscriptionZ;

        $observableShockZ = (($subscriptionZ * self::SUBSCRIPTION_WEIGHT * self::SUBSCRIPTION_VARIANCE_SCALAR) * $baselineVol)
            + (($macroBoost * 0.5) * self::WALKUP_WEIGHT)
            + ($macroBoost * self::REAL_ESTATE_WEIGHT);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'subscription_revenue'    => $subscriptionRevenue,
                'walk_up_tickets'         => $walkupRevenue,
                'real_estate_development' => $realEstateRevenue,
            ],
        );
    }
}
