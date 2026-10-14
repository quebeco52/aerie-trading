<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\Service\Math\MacroTransmission;
use App\Service\Model\BusinessModelInterface;

use App\Service\Model\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
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
 * - Trade Protection: an import tariff shifts domestic tonnage and steel prices toward the home mills
 *   (USITC 2023, Section 232).
 */
class SteelManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Construction and auto steel demand swings with the cycle. */
    public const OPERATING_CYCLICALITY = 1.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::STEEL_MANUFACTURING;
    /** Blast furnaces and electric arc furnaces are price takers on ore, scrap and power: raw inputs reprice at spot. */
    public const PRICING_POWER_INDEX = 0.40;

    // --- Demand Transmission Lag ---
    /** Years for a move in the output gap to reach the order book. Contracted tonnage and mill scheduling carry the order book well past a turn in demand. */
    public const DEMAND_LAG_YEARS = 0.75;

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
    /** Sensitivity of steel mill order demand to manufacturing PMI survey shifts. */
    public const PMI_DEMAND_SENSITIVITY   = 0.50;
    /** Tonnage per unit of the lagged output gap beyond cyclicality: steel goes into investment goods, which swing harder than output (Clark 1917 accelerator). */
    public const INVESTMENT_ACCELERATOR_MULTIPLIER = 1.50;
    /** Spot tonnage per unit of the foreign bloc's output gap: export orders, which fall away first when the world slows. */
    public const FOREIGN_DEMAND_SENSITIVITY = 1.50;
    /** Share of spot order volume exposed to foreign currency competition and overseas steel dumping. */
    public const FX_REVENUE_EXPOSURE = 0.20;

    // --- Trade Protection (USITC 2023, Section 232 and 301 Tariffs, Pub. 5405) ---
    /** Domestic tonnage per unit of import tariff: the 25% Section 232 duty raised US steel production 1.9% (2018-21). */
    public const TARIFF_VOLUME_ELASTICITY = 0.019 / 0.25;
    /** Domestic steel price per unit of import tariff: the same duty raised US steel prices 2.4%. */
    public const TARIFF_PRICE_ELASTICITY = 0.024 / 0.25;
    /** Sensitivity of blast furnace fixed cost absorption to industrial capacity utilization. */
    public const CU_MARGIN_ABSORPTION_SENSITIVITY = 0.12;

    // --- Valuation ---
    /** Book (replacement cost) weight in fair value when normalised EPS is negative: trough cyclicals trade on assets. */
    public const TROUGH_BOOK_WEIGHT = 0.70;
    /** Book weight in fair value through the rest of the cycle. */
    public const MID_CYCLE_BOOK_WEIGHT = 0.40;

    public function getWholesaleLeverageLimit(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.01; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.2; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.35; }

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Nucor tons shipped to outside customers
     * by quarter, 2017-19 (10-Q, 8-K), each year over its own mean. Mills run through the year; the spring
     * construction season lifts Q2 slightly and the holidays trim Q4.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [1.00, 1.02, 1.00, 0.98];
    }

    public function getCapexCyclicality(): float
    {
        return 0.80; // Massive fixed blast furnace infrastructure
    }

    /**
     * The cycle reaches tonnage once, inside each stream's physics; the root shift carries no demand term
     * (the exchange rate is the spot stream's import-dumping term).
     */
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /**
     * The tonnage the mill is staffed to run: the target-mix volume shift of its streams. Hot-rolled coil
     * repricing is price and needs no crew, so the metals term stays out.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ContractOemWeight->value => self::CONTRACTED_OEM_WEIGHT,
            ModelParam::SpotHrcWeight->value     => self::SPOT_HRC_WEIGHT,
        ]);
        $contractWeight = $params[ModelParam::ContractOemWeight];
        $spotWeight = $params[ModelParam::SpotHrcWeight];
        $totalWeight = $contractWeight + $spotWeight;
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $cycleShift = $this->resolveCycleVolumeShift($stock, $macroState) + $this->resolveTariffVolumeShift($macroState);

        return (($contractWeight * $cycleShift) + ($spotWeight * ($cycleShift + $this->resolveImportDumpingShift($macroState)))) / $totalWeight;
    }

    /** Domestic tonnage a tariff diverts from imports to the home mills, on the trade-lagged tariff. */
    private function resolveTariffVolumeShift(MacroStateDTO $macroState): float
    {
        return $macroState->tariffTradeLag * self::TARIFF_VOLUME_ELASTICITY;
    }

    /** Domestic tonnage on the lagged output gap through the investment accelerator, led by the manufacturing PMI. */
    private function resolveCycleVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $beta = $this->getOperatingCyclicality($stock);
        $pmiShift = MacroTransmission::calculatePmiDemandShift($macroState->manufacturingPmiEma, MacroEngine::PMI_BASELINE, self::PMI_DEMAND_SENSITIVITY);

        return ($this->resolveLaggedOutputGap($macroState) * $beta * self::INVESTMENT_ACCELERATOR_MULTIPLIER) + ($pmiShift * $beta);
    }

    /**
     * Export tonnage clears in the spot market: foreign activity expands orders, while a foreign slowdown with a
     * strong domestic currency invites import dumping.
     */
    private function resolveImportDumpingShift(MacroStateDTO $macroState): float
    {
        return MacroTransmission::calculateForeignDemandShift($macroState->foreignOutputGapEma, sensitivity: self::FOREIGN_DEMAND_SENSITIVITY)
            + $this->resolveFxDemandShift($macroState, self::FX_REVENUE_EXPOSURE);
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ContractOemWeight->value => self::CONTRACTED_OEM_WEIGHT,
            ModelParam::SpotHrcWeight->value     => self::SPOT_HRC_WEIGHT,
        ]);

        $pricingPower = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'contracted_oem_steel' => $params[ModelParam::ContractOemWeight],
            'spot_hrc_market'      => $params[ModelParam::SpotHrcWeight],
        ]);

        $contractWeight = $activeWeights['contracted_oem_steel'];
        $spotWeight     = $activeWeights['spot_hrc_market'];

        // The cycle reaches both books once, at the same tonnage elasticity; spot adds export orders and import dumping.
        // A tariff moves tonnage on both books and lifts the domestic price on all of it.
        $cycleShift = $this->resolveCycleVolumeShift($stock, $macroState) + $this->resolveTariffVolumeShift($macroState);
        $tariffPriceShift = $macroState->tariffTradeLag * self::TARIFF_PRICE_ELASTICITY;
        $importDumpingShift = $this->resolveImportDumpingShift($macroState);
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;

        $contractZ = $streams->generateZ('contracted_oem_steel', 0.35);
        $spotZ     = $streams->generateZ('spot_hrc_market', 0.15);

        $contractRevenue = max(0.0, $expectedRevenue * $contractWeight * (1.0 + ($contractZ * ($baselineVol * self::CONTRACT_VARIANCE_SCALAR)) + $cycleShift + $tariffPriceShift));
        $spotRevenue     = max(0.0, $expectedRevenue * $spotWeight     * (1.0 + ($spotZ     * ($baselineVol * self::SPOT_VARIANCE_SCALAR)) + ($metalsShift * 0.50) + $cycleShift + $importDumpingShift + $tariffPriceShift));
        // Hot-rolled coil repricing and the tariff's price lift on the same tonnage are price: the mill's variable
        // cost per tonne does not follow either.
        $priceRevenue    = ($expectedRevenue * $spotWeight * ($metalsShift * 0.50)) + ($expectedRevenue * ($contractWeight + $spotWeight) * $tariffPriceShift);

        $streamRevenues = [
            'contracted_oem_steel' => $contractRevenue,
            'spot_hrc_market'      => $spotRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Metal spread: ore, scrap, metallurgical coal and power reach the furnace at spot; the spread is what is left
        // after the mill has recovered what its contract and spot pricing allow.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        // Blast furnace fixed overhead absorption via capacity utilization
        $cuShift = MacroTransmission::calculateCapacityUtilizationShift($macroState->capacityUtilizationRateEma, MacroEngine::CU_BASELINE, self::CU_MARGIN_ABSORPTION_SENSITIVITY);
        $cuMarginAdjustment = -$cuShift; // Higher CU improves margin

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag + $cuMarginAdjustment);

        $primaryShockZ = $streams->resolveDominantShockZ([$spotZ, $contractZ]);
        $observableShockZ = ($contractZ * $contractWeight * self::CONTRACT_VARIANCE_SCALAR * $baselineVol)
            + ($spotZ * $spotWeight * self::SPOT_VARIANCE_SCALAR * $baselineVol)
            + ($cycleShift * $spotWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            priceRevenue: $priceRevenue,
        );
    }

    /** Steelmakers anchor to book (replacement cost) at trough earnings and to mid-cycle earnings through expansions. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return $normalizedEps < 0 ? self::TROUGH_BOOK_WEIGHT : self::MID_CYCLE_BOOK_WEIGHT;
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'capacity_utilization_rate_ema',
            'exchange_rate_index_ema',
            'foreign_output_gap_ema',
            'industrial_metals_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_lag_9m',
            'tariff_trade_lag',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
