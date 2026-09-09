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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Construction and auto steel demand swings with the cycle. */
    public const OPERATING_CYCLICALITY = 1.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.30, 'metals' => 0.35, 'freight' => 0.05, 'ppi' => 0.05, 'labor' => 0.15];
    /** Blast furnaces and electric arc furnaces are price takers on ore, scrap and power: raw inputs reprice at spot. */
    public const PRICING_POWER_INDEX = 0.40;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Mill labor shares overhead with furnace energy, refractories and maintenance. */
    public const FIXED_COST_LABOR_SHARE = 0.40;

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
    /** Sensitivity of blast furnace fixed cost absorption to industrial capacity utilization. */
    public const CU_MARGIN_ABSORPTION_SENSITIVITY = 0.12;

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

    public function getSeasonalityFactors(): array
    {
        return [0.85, 1.15, 1.15, 0.85]; // Construction & industrial manufacturing weather alignment
    }

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

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $outputGap = $this->resolveLaggedOutputGap($stock, $macroState);
        $beta = $this->getOperatingCyclicality($stock);
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, MacroEngine::PMI_BASELINE, self::PMI_DEMAND_SENSITIVITY);

        $physics['macro_demand_shift'] = ($outputGap * $beta * 1.50) + ($pmiShift * $beta);
        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ContractOemWeight->value => self::CONTRACTED_OEM_WEIGHT,
            ModelParam::SpotHrcWeight->value     => self::SPOT_HRC_WEIGHT,
            ModelParam::PricingPowerIndex->value => 0.40,
        ]);

        $contractWeight = $params[ModelParam::ContractOemWeight];
        $spotWeight     = $params[ModelParam::SpotHrcWeight];
        $pricingPower   = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta     = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'contracted_oem_steel' => $params[ModelParam::ContractOemWeight],
            'spot_hrc_market'      => $params[ModelParam::SpotHrcWeight],
        ]);

        $contractWeight = $activeWeights['contracted_oem_steel'];
        $spotWeight     = $activeWeights['spot_hrc_market'];

        // Strongly tied to macro output gap, manufacturing PMI, and energy prices
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, MacroEngine::PMI_BASELINE, self::PMI_DEMAND_SENSITIVITY);
        $macroBoost = ($macroState->outputGapEma * 1.2 * $beta) + ($pmiShift * $beta);
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;

        $contractZ = $streams->generateZ('contracted_oem_steel', 0.35);
        $spotZ     = $streams->generateZ('spot_hrc_market', 0.15);

        $contractRevenue = max(0.0, $expectedRevenue * $contractWeight * (1.0 + ($contractZ * ($baselineVol * self::CONTRACT_VARIANCE_SCALAR)) + ($macroBoost * 0.5)));
        $spotRevenue     = max(0.0, $expectedRevenue * $spotWeight     * (1.0 + ($spotZ     * ($baselineVol * self::SPOT_VARIANCE_SCALAR)) + ($metalsShift * 0.50) + ($macroBoost * 0.5)));
        // Hot-rolled coil repricing on the same tonnage is price: the mill's variable cost per tonne does not follow the spot quote.
        $priceRevenue    = $expectedRevenue * $spotWeight * ($metalsShift * 0.50);

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
        $cuShift = MathUtility::calculateCapacityUtilizationShift($macroState->capacityUtilizationRateEma, MacroEngine::CU_BASELINE, self::CU_MARGIN_ABSORPTION_SENSITIVITY);
        $cuMarginAdjustment = -$cuShift; // Higher CU improves margin

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag + $cuMarginAdjustment);

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
            priceRevenue: $priceRevenue,
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

    /** Blast furnace wear & refractory thermal degradation toward floor */
    public function getDepreciationDecayRate(): float
    {
        return self::BLAST_FURNACE_DECAY_RATE;
    }

    /** EAF efficiency & automated rolling mill modernization expands margin ceiling */
    public function getModernizationGainRate(): float
    {
        return self::EAF_MODERNIZATION_GAIN_RATE;
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
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'industrial_metals_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
