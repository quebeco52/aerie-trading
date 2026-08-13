<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Law Firms & Elite Legal Services.
 * 
 * Financial Physics:
 * - Asset light, entirely driven by human capital.
 * - Revenue is split between highly sticky, defensive B2B corporate retainers and extremely volatile, lumpy litigation settlements.
 * - Minimal CapEx requirements, very high free cash flow conversion.
 */
class LawFirmBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.15;
    public const BASE_COVERAGE_ERROR = 0.06;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 3.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.5,  'dividend_crisis_icr' => 2.00, 'buyback_min_icr' => 3.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.05, 'capex_completion_rate' => 0.20];
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.02; }
    
    public function getCapexCyclicality(): float { return 0.1; } // Almost no physical capex cyclicality
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.70, 'revenue_weight' => 0.30]; }

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from highly sticky corporate retainers. */
    public const RETAINER_WEIGHT = 0.70;
    /** Baseline fraction of revenue derived from volatile, lumpy litigation settlements. */
    public const LITIGATION_WEIGHT = 0.30;

    // --- Physics ---
    /** Volatility multiplier for retainer revenue (highly stable). */
    public const RETAINER_VARIANCE_SCALAR = 0.10; 
    /** Volatility multiplier for litigation revenue (highly volatile). */
    public const LITIGATION_VARIANCE_SCALAR = 3.00; 

    /** Z-Score threshold required to trigger a massive litigation win. */
    public const LITIGATION_WIN_Z_SCORE = 1.80;
    /** Revenue multiplier applied during a massive litigation win. */
    public const LITIGATION_WIN_MULT = 1.50;

    /** Z-Score threshold required to trigger a massive litigation loss/drought. */
    public const LITIGATION_LOSS_Z_SCORE = -1.80;
    /** Revenue multiplier applied during a massive litigation loss. */
    public const LITIGATION_LOSS_MULT = 0.80;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores
        // Retainers are highly persistent
        $retainerZ = $mathUtility->generatePersistentZ($momentum['retainer'] ?? 0.0, 0.40);
        // Litigation is lumpy, low persistence
        $litigationZ = $mathUtility->generatePersistentZ($momentum['litigation'] ?? 0.0, 0.10); 

        $retainerRevenue = $expectedRevenue * self::RETAINER_WEIGHT * (1.0 + ($retainerZ * ($baselineVol * self::RETAINER_VARIANCE_SCALAR)));
        $litigationRevenue = $expectedRevenue * self::LITIGATION_WEIGHT * (1.0 + ($litigationZ * ($baselineVol * self::LITIGATION_VARIANCE_SCALAR)));

        $eventType = null;

        if ($litigationZ > self::LITIGATION_WIN_Z_SCORE) {
            $litigationRevenue *= self::LITIGATION_WIN_MULT;
            $eventType = ShockEvent::LITIGATION_SETTLEMENT_WIN;
        } elseif ($litigationZ < self::LITIGATION_LOSS_Z_SCORE) {
            $litigationRevenue *= self::LITIGATION_LOSS_MULT;
            $eventType = ShockEvent::LITIGATION_SETTLEMENT_LOSS;
        }

        $actualRevenue = max(0.0, $retainerRevenue + $litigationRevenue);

        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        $primaryShockZ = abs($litigationZ) > abs($retainerZ) ? $litigationZ : $retainerZ;
        
        // Retainers are somewhat predictable, litigation is surprise
        $observableShockZ = $retainerZ * self::RETAINER_WEIGHT * ($baselineVol * self::RETAINER_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'corporate_retainers'    => $retainerZ,
                'litigation_settlements' => $litigationZ,
            ],
            streamRevenue: [
                'corporate_retainers'    => $retainerRevenue,
                'litigation_settlements' => $litigationRevenue,
            ],
        );
    }
}
