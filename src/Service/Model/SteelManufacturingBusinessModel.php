<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Steel & Raw Metal Manufacturing.
 * 
 * Financial Physics:
 * - Brutally cyclical and extremely capital-intensive (blast furnaces must run 24/7).
 * - Revenue is a mix of stable domestic supply contracts and volatile "export dumping" where surplus is sold at spot prices.
 * - Margins compress aggressively during recessions when global demand drops.
 */
class SteelManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50;
    public const BASE_COVERAGE_ERROR = 0.08;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.5,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.10, 'moat_spread' => 0.010, 'nwc_intensity' => 0.20, 'capex_completion_rate' => 0.35];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.01;
    }

    public function getCapexCyclicality(): float
    {
        return 0.8;
    } // Massive fixed infrastructure

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.40, 'revenue_weight' => 0.60];
    }

    // --- Stream Weights ---
    public const DOMESTIC_WEIGHT = 0.60;
    public const EXPORT_WEIGHT = 0.40;

    // --- Physics ---
    public const DOMESTIC_VARIANCE_SCALAR = 0.40; // Cyclical but contracted
    public const EXPORT_VARIANCE_SCALAR = 1.50; // Highly volatile spot market

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);

        // Strongly tied to macro output gap and energy prices
        $macroBoost = $macroState->outputGapEma * 1.5;
        $energyDrag = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0) * 1.00; // Blast furnaces use massive energy

        $domesticZ = $streams->generateZ('domestic_production', 0.25);
        $exportZ   = $streams->generateZ('export_dumping', 0.10);

        $domesticRevenue = $expectedRevenue * self::DOMESTIC_WEIGHT * (1.0 + ($domesticZ * ($baselineVol * self::DOMESTIC_VARIANCE_SCALAR)) + $macroBoost - $energyDrag);
        $exportRevenue   = $expectedRevenue * self::EXPORT_WEIGHT   * (1.0 + ($exportZ   * ($baselineVol * self::EXPORT_VARIANCE_SCALAR)) + ($macroBoost * 1.5));

        $actualRevenue = max(0.0, $domesticRevenue + $exportRevenue);

        // Cyclical Margin Compression
        $clampedMargin = $this->clampMargin($realizedVariableMargin - ($energyDrag * 0.5));

        $primaryShockZ = abs($exportZ) > abs($domesticZ) ? $exportZ : $domesticZ;
        $observableShockZ = (($domesticZ * self::DOMESTIC_WEIGHT * self::DOMESTIC_VARIANCE_SCALAR) * $baselineVol)
            + (($macroBoost - $energyDrag) * self::DOMESTIC_WEIGHT)
            + (($macroBoost * 1.5) * self::EXPORT_WEIGHT);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'domestic_production' => $domesticRevenue,
                'export_dumping'      => $exportRevenue,
            ],
        );
    }
}
