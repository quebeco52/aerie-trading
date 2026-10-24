<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;

/**
 * The industry readings: capacity utilisation (Federal Reserve G.17), the manufacturing purchasing managers' index and
 * the stage-of-processing producer price index.
 */
final class IndustryIndicators
{
    // --- ISM Manufacturing Purchasing Managers' Index (PMI) ---
    /**
     * PMI points per unit CU deviation (~0.5 per pp): the level of capacity strain feeds supplier deliveries and
     * prices, a secondary channel next to growth.
     */
    public const PMI_CU_SENSITIVITY = 50.0;
    /**
     * PMI points per unit of annualized real growth over potential (~3.3 per pp): ISM's published mapping of the
     * headline index to annualized real GDP growth, ~0.3pp of growth per index point. A diffusion index measures
     * change, so a V-shaped recovery reads in the high 50s while the level of activity is still below trend.
     */
    public const PMI_GROWTH_SENSITIVITY = 330.0;
    /** Sensitivity of manufacturing PMI to Metzler inventory restocking demand (shortfall stimulates new orders). */
    public const PMI_INVENTORY_SENSITIVITY = 20.0;
    /**
     * Mean-reversion speed (kappa) of manufacturing PMI toward fundamental business conditions (half-life ~6 weeks): a
     * monthly survey reprices within a month or two, so the index is coincident with quarterly growth.
     */
    public const PMI_KAPPA = 6.0;
    /** Stochastic diffusion volatility of manufacturing PMI survey sentiment. */
    public const PMI_SIGMA = 1.2;
    /** Asymptotic lower bound floor for manufacturing PMI during severe industrial depressions. */
    public const MIN_PMI = 30.0;
    /** Asymptotic upper bound ceiling for manufacturing PMI during hyper-expansionary booms. */
    public const MAX_PMI = 70.0;

    // --- Producer Price Index (PPI) Stage-of-Processing ---
    /** Weight of agricultural raw food commodities in intermediate producer prices. */
    public const PPI_AGRI_WEIGHT = 0.15;
    /** Wholesale PPI per standard deviation of supply-chain pressure (0.3pp). */
    public const PPI_GSCPI_SENSITIVITY = 0.00285;
    /** Weight of Unit Labor Cost (ULC = Wage Growth - TFP Growth) in wholesale producer processing costs. */
    public const PPI_ULC_WEIGHT = 0.25;
    /**
     * Weight of the rest of the cost base (purchased services, rents, margins), repricing with expected inflation: what
     * the named shares leave, so the stage weights sum to one.
     */
    public const PPI_OTHER_COST_WEIGHT = 1.0 - MacroConstants::PPI_METALS_WEIGHT - MacroConstants::PPI_ENERGY_WEIGHT - self::PPI_AGRI_WEIGHT - self::PPI_ULC_WEIGHT;
    /** Sensitivity of wholesale producer price margins to cyclical GDP output gap demand. */
    public const PPI_DEMAND_SENSITIVITY = 0.35;
    /** Lower bound floor on annual producer price deflation. */
    public const MIN_PPI_INFLATION = -0.06;
    /** Share of a metals price move reaching factory-gate prices; the fastest of the three. */
    public const PPI_METALS_PASS_THROUGH = 0.50;
    /** Share of an energy price move reaching factory-gate prices, damped by hedging and regulated tariffs. */
    public const PPI_ENERGY_PASS_THROUGH = 0.40;
    /**
     * Share of a farm price move reaching factory-gate prices; the lowest, since processing dominates food producer
     * cost.
     */
    public const PPI_AGRI_PASS_THROUGH = 0.30;
    /** Upper bound ceiling on runaway wholesale producer price inflation. */
    public const MAX_PPI_INFLATION = 0.25;

    public function __construct(
        private readonly MathUtility $mathUtility,
    ) {}

    /**
     * Federal Reserve G.17 Industrial Capacity Utilization Index.
     *
     * Evaluates real aggregate physical factory, mining, and utility capacity utilization (CU_t)
     * based on macroeconomic output gap demand and capital stock overhang.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateCapacityUtilization(MacroState $state): void
    {
        $state->capacityUtilizationRate = FirmEconomics::calculateCapacityUtilization(
            outputGap: $state->outputGap,
            capitalStockOverhang: $state->capitalStockOverhang,
            baselineCu: MacroConstants::CU_BASELINE,
            gapSensitivity: MacroConstants::CU_GAP_SENSITIVITY,
            overhangSensitivity: MacroConstants::CU_OVERHANG_SENSITIVITY,
        );
    }

    /**
     * ISM / S&P Global Manufacturing Purchasing Managers' Index (PMI).
     *
     * Evaluates the headline diffusion index centered at 50.0. A diffusion index counts the share of firms
     * reporting improvement, so it tracks the rate of change of activity, not its level: ISM maps the headline
     * to annualized real GDP growth at ~0.3pp per index point. Real growth over potential is the annualized
     * output gap momentum, read off the gap's distance from its quarter-horizon EMA; capacity utilization and
     * Metzler inventory restocking demand are secondary level channels:
     *   Target = 50 + beta_g * (d(OutputGap)/dt) + beta_CU * (CU - CU*) + beta_inv * (-InventoryGap)
     * Bank credit standards are not among ISM's components; they reach factories through the demand the growth and
     * utilization legs already carry.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time step in years.
     */
    public function calculateManufacturingPmi(MacroState $state, float $dt): void
    {
        $cuDeviation = $state->capacityUtilizationRate - MacroConstants::CU_BASELINE;
        // Brown (1963) real GDP growth momentum derived from EMA output gap time-derivative.
        $excessGrowth = ($state->outputGap - $state->outputGapEma) / MovingAverages::STANDARD_EMA_HORIZON_YEARS;
        $inventoryDemand = -$state->inventoryStockGap; // Shortfall stimulates orders

        $drivers = [
            ['deviation' => $excessGrowth, 'sensitivity' => self::PMI_GROWTH_SENSITIVITY],
            ['deviation' => $cuDeviation, 'sensitivity' => self::PMI_CU_SENSITIVITY],
            ['deviation' => $inventoryDemand, 'sensitivity' => self::PMI_INVENTORY_SENSITIVITY],
        ];

        $targetPmi = MacroTransmission::calculateDiffusionIndex(
            baseline: MacroConstants::PMI_BASELINE,
            drivers: $drivers,
            min: self::MIN_PMI,
            max: self::MAX_PMI,
        );

        // Gillespie (1996) exact Ornstein-Uhlenbeck discretization for PMI diffusion index.
        $dW = $this->mathUtility->generateStandardNormal();
        $decay = exp(-self::PMI_KAPPA * $dt);
        $drift = (1.0 - $decay) * ($targetPmi - $state->manufacturingPmi);
        $diffusion = self::PMI_SIGMA * sqrt((1.0 - ($decay ** 2)) / (2.0 * self::PMI_KAPPA)) * $dW;
        $newPmi = $state->manufacturingPmi + $drift + $diffusion;

        $state->manufacturingPmi = max(self::MIN_PMI, min(self::MAX_PMI, $newPmi));
    }

    /**
     * Stage-of-Processing Producer Price Index (PPI) Wholesale Inflation Pipeline (Clark 1995).
     *
     * Computes wholesale factory-gate price inflation driven by primary commodity input price shocks
     * (metals, energy, agriculture), ocean freight/logistics bottlenecks (GSCPI), Unit Labor Costs (ULC),
     * and cyclical output gap demand pressure.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Productivity growth potential output is built on (trend plus absorbed shocks).
     * @param float      $dt            Time step in years.
     */
    public function calculateProducerPriceInflation(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $unitLaborCost = $state->wageGrowth - $tfpGrowthRate;

        $weights = [
            'metals' => MacroConstants::PPI_METALS_WEIGHT,
            'energy' => MacroConstants::PPI_ENERGY_WEIGHT,
            'agri' => self::PPI_AGRI_WEIGHT,
            'gscpi' => self::PPI_GSCPI_SENSITIVITY,
            'ulc' => self::PPI_ULC_WEIGHT,
            'other' => self::PPI_OTHER_COST_WEIGHT,
            'demand' => self::PPI_DEMAND_SENSITIVITY,
        ];

        $targetPpi = MacroTransmission::calculateStageOfProcessingPpi(
            metalsInflation: $this->commodityInflation($state->industrialMetalsIndex, $state->industrialMetalsIndexTrend, self::PPI_METALS_PASS_THROUGH),
            energyInflation: $this->commodityInflation($state->energyPriceIndex, $state->energyPriceIndexTrend, self::PPI_ENERGY_PASS_THROUGH),
            agriInflation: $this->commodityInflation($state->agriculturalCommodityIndex, $state->agriculturalCommodityIndexTrend, self::PPI_AGRI_PASS_THROUGH),
            gscpiZ: $state->supplyChainPressureIndex,
            unitLaborCost: $unitLaborCost,
            otherCostInflation: $state->tipsBreakeven,
            outputGap: $state->outputGap,
            weights: $weights,
            min: self::MIN_PPI_INFLATION,
            max: self::MAX_PPI_INFLATION,
        );

        $state->producerPriceInflation = $targetPpi;
    }

    /**
     * Nominal inflation rate of one commodity input, at the share reaching factory-gate prices.
     *
     * The stage-of-processing legs (Clark 1995) take RATES, and each index is a stationary Schwartz-Smith
     * log-price whose long-run inflation is zero. The rate is its deviation from trend over the trend's
     * horizon, exact by Brown (1963) linear exponential smoothing, and needs no baseline constant.
     *
     * @param float $index       Current index level.
     * @param float $trend       The index's own long-run average, or 0.0 before the first observation.
     * @param float $passThrough Share of the move that reaches factory-gate prices.
     * @return float Annual nominal inflation rate contributed by this input.
     */
    private function commodityInflation(float $index, float $trend, float $passThrough): float
    {
        if ($trend <= 0.0) {
            return MacroConstants::TARGET_INFLATION;
        }

        $growthRate = (($index - $trend) / $trend) / MacroConstants::COMMODITY_TREND_HORIZON_YEARS;

        return ($growthRate * $passThrough) + MacroConstants::TARGET_INFLATION;
    }
}
