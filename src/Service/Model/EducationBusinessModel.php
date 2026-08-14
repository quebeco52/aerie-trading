<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Education & Training.
 * 
 * Financial Physics:
 * - Revenue is a mix of highly sticky, guaranteed corporate/government subsidies and highly cyclical talent placement fees.
 * - Subsidies provide a high floor, but placement fees drive the massive upside during economic expansions.
 */
class EducationBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    public const BASE_COVERAGE_ERROR = 0.06;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.50, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.75, 'buyback_min_icr' => 2.50, 'reversion_speed' => 0.10, 'moat_spread' => 0.015, 'nwc_intensity' => 0.05, 'capex_completion_rate' => 0.20];
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.02; }
    
    public function getCapexCyclicality(): float { return 0.1; }
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Stream Weights ---
    public const SUBSIDY_WEIGHT = 0.60;
    public const PLACEMENT_WEIGHT = 0.40;

    // --- Physics ---
    public const SUBSIDY_VARIANCE_SCALAR = 0.05; // Extremely stable
    public const PLACEMENT_VARIANCE_SCALAR = 2.00; // Highly cyclical

    public const PLACEMENT_BOOM_Z_SCORE = 1.80;
    public const PLACEMENT_BOOM_MULT = 1.50; 

    public const SUBSIDY_CUT_Z_SCORE = -2.00;
    public const SUBSIDY_CUT_MULT = 0.75; 

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);

        // Placement correlates heavily to macro hiring
        $macroBoost = $macroState->outputGapEma * 0.8;

        $subsidyZ   = $streams->generateZ('corporate_subsidies', 0.40);
        $placementZ = $streams->generateZ('talent_placement_fees', 0.20); 

        $subsidyRevenue   = $expectedRevenue * self::SUBSIDY_WEIGHT   * (1.0 + ($subsidyZ * ($baselineVol * self::SUBSIDY_VARIANCE_SCALAR)));
        $placementRevenue = $expectedRevenue * self::PLACEMENT_WEIGHT * (1.0 + ($placementZ * ($baselineVol * self::PLACEMENT_VARIANCE_SCALAR)) + $macroBoost);

        $eventType = null;

        if ($placementZ > self::PLACEMENT_BOOM_Z_SCORE) {
            $placementRevenue *= self::PLACEMENT_BOOM_MULT;
            $eventType = ShockEvent::TALENT_PLACEMENT_BOOM ?? 'talent_placement_boom';
        } elseif ($subsidyZ < self::SUBSIDY_CUT_Z_SCORE) {
            $subsidyRevenue *= self::SUBSIDY_CUT_MULT;
            $eventType = ShockEvent::SUBSIDY_CUT ?? 'subsidy_cut';
        }

        $actualRevenue = max(0.0, $subsidyRevenue + $placementRevenue);
        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        $primaryShockZ = abs($placementZ) > abs($subsidyZ) ? $placementZ : $subsidyZ;
        $observableShockZ = ($subsidyZ * self::SUBSIDY_WEIGHT * ($baselineVol * self::SUBSIDY_VARIANCE_SCALAR)) + ($macroBoost * self::PLACEMENT_WEIGHT);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'corporate_subsidies'   => $subsidyRevenue,
                'talent_placement_fees' => $placementRevenue,
            ],
        );
    }
}
