<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Heavy Engineering & Construction.
 * 
 * Financial Physics:
 * - Operates on massive, multi-year timelines.
 * - Revenue is a mix of highly sticky, government-backed infrastructure contracts and highly cyclical private development.
 * - Margin Squeeze: Contracts are often fixed-price. Supply chain inflation or energy spikes severely compress margins.
 * - Capital expenditure is astronomical, meaning free cash flow only booms at the very peak of economic expansions.
 */
class ConstructionBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    public const BASE_COVERAGE_ERROR = 0.08;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.5,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.10, 'moat_spread' => 0.015, 'nwc_intensity' => 0.25, 'capex_completion_rate' => 0.40];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.015;
    }

    public function getCapexCyclicality(): float
    {
        return 0.75;
    } // Massive fixed infrastructure

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.50, 'revenue_weight' => 0.50];
    }

    // --- Stream Weights ---
    public const INFRASTRUCTURE_WEIGHT = 0.50;
    public const PRIVATE_DEV_WEIGHT = 0.50;

    // --- Physics ---
    public const INFRASTRUCTURE_VARIANCE_SCALAR = 0.15; // Sticky, multi-year contracts
    public const PRIVATE_DEV_VARIANCE_SCALAR = 1.20; // Highly cyclical corporate spending
    public const INFLATION_PENALTY_SCALAR = 1.50; // Extremely vulnerable to fixed-price contract cost overruns

    // --- Tail Risk & Shock Events ---
    public const COST_OVERRUN_Z_SCORE = -2.00;
    public const COST_OVERRUN_PENALTY = 0.06; // Margin hit from severe project delays
    public const MEGA_PROJECT_Z_SCORE = 2.20;
    public const MEGA_PROJECT_MULT = 1.15; // Top-line boost from a landmark infrastructure win

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift to avoid double-dipping, as macro volume shocks 
        // are handled discretely per-stream in calculateSectorPhysics.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $beta = abs((float) $stock->getBeta());

        // Independent stream Z-scores
        $infrastructureZ = $mathUtility->generatePersistentZ($momentum['infrastructure_contracts'] ?? 0.0, 0.40);
        $privateZ = $mathUtility->generatePersistentZ($momentum['private_development'] ?? 0.0, 0.20);
        $eventZ = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.10);

        // --- Macro Demand Sensitivities ---
        $macroBoost = $macroState->outputGapEma * 1.5 * $beta;
        $creditDrag = max(0.0, ($macroState->policyRateEma - MacroEngine::NATURAL_RATE) * 2.0 * $beta);
        $nominalBoost = max(0.0, ($macroState->nominalGdpIndex - 1.0) * 0.4); // Gov contracts inflate nominally over time

        // --- Tail Risk Events ---
        $dealMultiplier = 1.0;
        $eventType = null;
        $costOverrunDrag = 0.0;

        if ($eventZ > self::MEGA_PROJECT_Z_SCORE) {
            $dealMultiplier = self::MEGA_PROJECT_MULT;
            $eventType = ShockEvent::INFRASTRUCTURE_BILL_WIN ?? 'mega_project_win';
        } elseif ($eventZ < self::COST_OVERRUN_Z_SCORE) {
            $costOverrunDrag = self::COST_OVERRUN_PENALTY;
            $eventType = ShockEvent::PROJECT_DELAY ?? 'cost_overrun';
        }

        // --- Dual-Stream Revenue Calculation ---
        $infrastructureRevenue = max(0.0, $expectedRevenue * self::INFRASTRUCTURE_WEIGHT * (1.0 + ($infrastructureZ * ($baselineVol * self::INFRASTRUCTURE_VARIANCE_SCALAR)) + $nominalBoost) * $dealMultiplier);
        $privateRevenue = max(0.0, $expectedRevenue * self::PRIVATE_DEV_WEIGHT * (1.0 + ($privateZ * ($baselineVol * self::PRIVATE_DEV_VARIANCE_SCALAR)) + $macroBoost - $creditDrag));

        $actualRevenue = $infrastructureRevenue + $privateRevenue;

        // --- Fixed-Price Contract Margin Squeeze ---
        // Construction uses massive amounts of diesel, steel, and cement.
        $inflation = $macroState->inflationEma;
        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);

        $baseInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * $beta * self::INFLATION_PENALTY_SCALAR
            : 0.0;

        $materialCostDrag = $baseInflationPenalty + ($energyShift * 0.10);

        // Apply raw margin penalties
        $effectiveMargin = $actualRevenue > 0 ? ($realizedVariableMargin * $expectedRevenue) / max(1.0, $actualRevenue) : $realizedVariableMargin;
        $rawMargin = $effectiveMargin - $materialCostDrag - $costOverrunDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = abs($privateZ) > abs($infrastructureZ) ? $privateZ : $infrastructureZ;
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // Infrastructure contract wins are highly public, private development is harder to forecast
        $observableShockZ = ($infrastructureZ * self::INFRASTRUCTURE_WEIGHT * self::INFRASTRUCTURE_VARIANCE_SCALAR * 0.8) +
            ($privateZ * self::PRIVATE_DEV_WEIGHT * self::PRIVATE_DEV_VARIANCE_SCALAR * 0.3);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'infrastructure_contracts' => $infrastructureZ,
                'private_development'      => $privateZ,
                'event'                    => $eventZ,
            ],
            streamRevenue: [
                'infrastructure_contracts' => $infrastructureRevenue,
                'private_development'      => $privateRevenue,
            ],
        );
    }
}
