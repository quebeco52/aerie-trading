<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Advertising Agencies.
 * 
 * Financial Physics:
 * - Asset light, human-capital intensive.
 * - Revenue driven by steady long-term corporate retainers (media buying, brand management).
 * - Sudden surges in revenue driven by highly lucrative, counter-cyclical crisis management mandates.
 */
class AdvertisingAgencyBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.25;
    public const BASE_COVERAGE_ERROR = 0.06;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 3.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.5,  'dividend_crisis_icr' => 2.00, 'buyback_min_icr' => 3.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.05, 'capex_completion_rate' => 0.20];
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.015; }
    
    public function getCapexCyclicality(): float { return 0.1; }
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.70, 'revenue_weight' => 0.30]; }

    // --- Stream Weights ---
    public const RETAINER_WEIGHT = 0.85;
    public const CRISIS_MANAGEMENT_WEIGHT = 0.15;

    // --- Physics ---
    public const RETAINER_VARIANCE_SCALAR = 0.15; // Stable but tied to corporate ad budgets
    public const CRISIS_VARIANCE_SCALAR = 4.00; // Extremely volatile

    public const CRISIS_BOOM_Z_SCORE = 2.00;
    public const CRISIS_BOOM_MULT = 2.50; // +150% on this stream during a major corporate disaster

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Retainers correlate slightly to macro (ad budgets expand in booms)
        $macroBoost = $macroState->outputGapEma * 0.5;

        $retainerZ = $mathUtility->generatePersistentZ($momentum['retainer_media_buying'] ?? 0.0, 0.40) + $macroBoost;
        // Crisis management is completely random/uncorrelated
        $crisisZ = $mathUtility->generatePersistentZ($momentum['crisis_management'] ?? 0.0, 0.05); 

        $retainerRevenue = $expectedRevenue * self::RETAINER_WEIGHT * (1.0 + ($retainerZ * ($baselineVol * self::RETAINER_VARIANCE_SCALAR)));
        $crisisRevenue = $expectedRevenue * self::CRISIS_MANAGEMENT_WEIGHT * (1.0 + ($crisisZ * ($baselineVol * self::CRISIS_VARIANCE_SCALAR)));

        $eventType = null;

        if ($crisisZ > self::CRISIS_BOOM_Z_SCORE) {
            $crisisRevenue *= self::CRISIS_BOOM_MULT;
            $eventType = ShockEvent::CRISIS_MANAGEMENT_BOOM ?? 'crisis_management_boom';
        }

        $actualRevenue = max(0.0, $retainerRevenue + $crisisRevenue);
        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        $primaryShockZ = abs($crisisZ) > abs($retainerZ) ? $crisisZ : $retainerZ;
        $observableShockZ = $retainerZ * self::RETAINER_WEIGHT * ($baselineVol * self::RETAINER_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'retainer_media_buying' => $retainerZ,
                'crisis_management'     => $crisisZ,
            ],
            streamRevenue: [
                'retainer_media_buying' => $retainerRevenue,
                'crisis_management'     => $crisisRevenue,
            ],
        );
    }
}
