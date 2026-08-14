<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Conglomerates.
 * 
 * Financial Physics:
 * - A mix of diverse, unrelated business lines.
 * - Revenue is split between cyclical industrial manufacturing, defensive consumer products, and volatile financial investments.
 * - Incredibly low baseline variance because the diversified streams naturally hedge each other.
 */
class ConglomerateBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.06;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.50, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.75, 'buyback_min_icr' => 2.50, 'reversion_speed' => 0.10, 'moat_spread' => 0.015, 'nwc_intensity' => 0.10, 'capex_completion_rate' => 0.15];
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.02; }
    
    public function getCapexCyclicality(): float { return 0.4; } // Heavily dragged by the industrial arm
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.50, 'revenue_weight' => 0.50]; }

    // --- Stream Weights ---
    public const INDUSTRIAL_WEIGHT = 0.40;
    public const CONSUMER_WEIGHT = 0.40;
    public const FINANCIAL_WEIGHT = 0.20;

    // --- Physics ---
    public const INDUSTRIAL_VARIANCE_SCALAR = 0.50; // Cyclical
    public const CONSUMER_VARIANCE_SCALAR = 0.10; // Defensive
    public const FINANCIAL_VARIANCE_SCALAR = 1.00; // Volatile, tied to market yield

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);

        // Industrial is heavily macro-correlated
        $macroBoost = $macroState->outputGapEma * 0.8;
        // Consumer is immune to macro, but maybe slightly dragged by inflation
        $inflationDrag = ($macroState->inflationEma - MacroEngine::TARGET_INFLATION) * 2.0;

        $industrialZ = $streams->generateZ('industrial_manufacturing', 0.25);
        $consumerZ   = $streams->generateZ('consumer_products', 0.50);
        $financialZ  = $streams->generateZ('financial_investments', 0.10); 

        $industrialRevenue = $expectedRevenue * self::INDUSTRIAL_WEIGHT * (1.0 + ($industrialZ * ($baselineVol * self::INDUSTRIAL_VARIANCE_SCALAR)) + $macroBoost);
        $consumerRevenue   = $expectedRevenue * self::CONSUMER_WEIGHT   * (1.0 + ($consumerZ * ($baselineVol * self::CONSUMER_VARIANCE_SCALAR)) - $inflationDrag);
        $financialRevenue  = $expectedRevenue * self::FINANCIAL_WEIGHT  * (1.0 + ($financialZ * ($baselineVol * self::FINANCIAL_VARIANCE_SCALAR)));

        $actualRevenue = max(0.0, $industrialRevenue + $consumerRevenue + $financialRevenue);
        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        // Max magnitude shock
        $primaryShockZ = $industrialZ;
        if (abs($consumerZ) > abs($primaryShockZ)) $primaryShockZ = $consumerZ;
        if (abs($financialZ) > abs($primaryShockZ)) $primaryShockZ = $financialZ;

        // Observable shock is blended
        $observableShockZ = (($industrialZ * self::INDUSTRIAL_WEIGHT * self::INDUSTRIAL_VARIANCE_SCALAR) +
                            ($consumerZ * self::CONSUMER_WEIGHT * self::CONSUMER_VARIANCE_SCALAR) +
                            ($financialZ * self::FINANCIAL_WEIGHT * self::FINANCIAL_VARIANCE_SCALAR)) * $baselineVol
                            + ($macroBoost * self::INDUSTRIAL_WEIGHT)
                            - ($inflationDrag * self::CONSUMER_WEIGHT);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null, // Conglomerates rarely have single events that rock the whole ship
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'industrial_manufacturing' => $industrialRevenue,
                'consumer_products'        => $consumerRevenue,
                'financial_investments'    => $financialRevenue,
            ],
        );
    }
}
