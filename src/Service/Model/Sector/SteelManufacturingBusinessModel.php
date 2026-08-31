<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Steel & Raw Metal Manufacturing.
 * 
 * Financial Physics:
 * - Brutally cyclical and capital-intensive (blast furnaces must run continuously).
 * - Revenue is a mix of stable, long-term OEM supply contracts (Automotive, Heavy Machinery) 
 *   and highly volatile Hot-Rolled Coil (HRC) spot market trading.
 * - Metal Spread & Energy Drag: Steel production is exposed to raw metallurgical coal, iron ore,
 *   and electricity costs. When energy prices spike, metal spreads compress.
 */
class SteelManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for industrial steel analysts. */
    public const BASE_COVERAGE_VISIBILITY = 0.50;
    /** Base coverage forecasting error given metal spread volatility. */
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Stream Weights ---
    /** Baseline fraction of revenue from long-term contracted automotive and industrial steel. */
    public const CONTRACTED_OEM_WEIGHT = 0.55;
    /** Baseline fraction of revenue from volatile spot hot-rolled coil (HRC) metal markets. */
    public const SPOT_HRC_WEIGHT       = 0.45;

    // --- Physics & Variances ---
    /** Idiosyncratic revenue variance scalar for contracted OEM steel supply. */
    public const CONTRACT_VARIANCE_SCALAR = 0.20;
    /** Idiosyncratic revenue variance scalar for volatile spot hot-rolled coil market. */
    public const SPOT_VARIANCE_SCALAR     = 0.60;
    /** Energy and metallurgical coal input cost drag scalar for blast furnaces and EAFs. */
    public const ENERGY_INPUT_DRAG_SCALAR = 0.80;

    // --- Blast Furnace Aging & EAF Reinvestment Physics ---
    /** Quarterly margin decay rate per unit of underinvestment below blast furnace relining replacement CapEx. */
    public const BLAST_FURNACE_DECAY_RATE = 0.020;
    /** Quarterly margin gain scalar per unit of electric arc furnace (EAF) and automation overinvestment. */
    public const EAF_MODERNIZATION_GAIN_RATE = 0.010;
    /** Structural minimum operating margin floor under severe blast furnace wear and high scrap costs. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.04;
    /** Structural maximum operating margin ceiling for optimized modern electric arc furnace steelmakers. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.28;

    public function getWholesaleLeverageLimit(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.01; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.2; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.35; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.01;
    }

    public function getCapexCyclicality(): float
    {
        return 0.80; // Massive fixed blast furnace infrastructure
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.40, 'revenue_weight' => 0.60];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ContractOemWeight->value => self::CONTRACTED_OEM_WEIGHT,
            ModelParam::SpotHrcWeight->value     => self::SPOT_HRC_WEIGHT,
        ]);

        $contractWeight = $params[ModelParam::ContractOemWeight];
        $spotWeight     = $params[ModelParam::SpotHrcWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta     = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'contracted_oem_steel' => $params[ModelParam::ContractOemWeight],
            'spot_hrc_market'      => $params[ModelParam::SpotHrcWeight],
        ]);

        $contractWeight = $activeWeights['contracted_oem_steel'];
        $spotWeight     = $activeWeights['spot_hrc_market'];

        // Strongly tied to macro output gap and energy prices
        $macroBoost = $macroState->outputGapEma * 1.5 * $beta;
        $energyDrag = max(0.0, $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION) * self::ENERGY_INPUT_DRAG_SCALAR;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;

        $contractZ = $streams->generateZ('contracted_oem_steel', 0.35);
        $spotZ     = $streams->generateZ('spot_hrc_market', 0.15);

        $contractRevenue = max(0.0, $expectedRevenue * $contractWeight * (1.0 + ($contractZ * ($baselineVol * self::CONTRACT_VARIANCE_SCALAR)) + ($macroBoost * 0.5)));
        $spotRevenue     = max(0.0, $expectedRevenue * $spotWeight     * (1.0 + ($spotZ     * ($baselineVol * self::SPOT_VARIANCE_SCALAR)) + ($metalsShift * 0.50) + ($macroBoost * 0.5)));

        $streamRevenues = [
            'contracted_oem_steel' => $contractRevenue,
            'spot_hrc_market'      => $spotRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Cyclical Metal Spread, Energy & Freight Logistics Compression
        $freightShift = max(0.0, ($macroState->freightRateIndexEma - 100.0) / 100.0);
        $freightDrag = $freightShift * 0.05;
        $clampedMargin = $this->clampMargin($realizedVariableMargin + ($energyDrag * 0.40) + $freightDrag);

        $primaryShockZ = $streams->resolveDominantShockZ([$spotZ, $contractZ]);
        $observableShockZ = ($contractZ * $contractWeight * self::CONTRACT_VARIANCE_SCALAR * $baselineVol)
            + ($spotZ * $spotWeight * self::SPOT_VARIANCE_SCALAR * $baselineVol)
            + ($macroBoost * self::SPOT_HRC_WEIGHT);

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

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Cyclical steel manufacturers anchor to Book Value (replacement cost) during trough earnings and mid-cycle earnings during expansions
        $bookWeight = $normalizedEps < 0 ? 0.70 : 0.40;
        $earningsWeight = 1.0 - $bookWeight;

        $baseConsensus = ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * FinancialConstants::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Blast furnace wear & refractory thermal degradation toward floor
            $decayRate = self::BLAST_FURNACE_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // EAF efficiency & automated rolling mill modernization expands margin ceiling
            $modGain = self::EAF_MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
