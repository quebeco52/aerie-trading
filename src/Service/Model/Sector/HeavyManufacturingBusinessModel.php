<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\Service\Math\MacroTransmission;
use App\Service\Model\BusinessModelInterface;

use App\Service\Model\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Heavy Manufacturing and Heavy Industry (e.g. Autos, Heavy Machinery, Specialty Chemicals, Steel).
 * 
 * Financial Physics:
 * - High Operating Leverage: Massive fixed costs mean margins compress violently during recessions but explode during booms.
 * - Pro-Cyclical: Highly sensitive to the macro output gap (GDP).
 * - High CapEx Cyclicality: Huge reinvestment requirements that scale with cycles.
 */
class HeavyManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Capital equipment orders are among the most cyclical volumes. */
    public const OPERATING_CYCLICALITY = 1.40;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.70;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::HEAVY_MANUFACTURING;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Heavy equipment is a globally traded good bid against foreign builders on delivered price. */
    public const FX_REVENUE_EXPOSURE = 0.15;

    // --- Demand Transmission Lag ---
    /** Years for a move in the output gap to reach the order book. Capital equipment is ordered against next year's capacity plan, not this quarter's demand. */
    public const DEMAND_LAG_YEARS = 1.00;
    /** Engineered equipment carries spec lock-in and steel escalator clauses on long builds, but competes bid-by-bid on new orders. */
    public const PRICING_POWER_INDEX = 0.55;

    // --- Inventory Cycle ---
    /** Order sensitivity to the economy-wide inventory-to-sales gap (Metzler cycle): overhangs trigger destocking, shortfalls restocking. Dealer and fleet inventories gate OEM equipment orders. */
    public const INVENTORY_CYCLE_SENSITIVITY = 0.80;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: spring construction and farm machinery deliveries.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.96, 1.04, 1.00, 1.00];
    }

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    public const BASE_COVERAGE_ERROR = 0.08;
        public function getReversionSpeed(): float { return 0.12; }
    public function getMoatSpread(): float { return 0.01; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.15; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.125; }
    // --- CapEx & Asset Physics ---
    public function getCapexCyclicality(): float
    {
        return 4.0;
    } // Much higher than standard 1.5
    // --- Secular Demand ---
    /** Business investment in industrial equipment as a share of US nominal GDP in 1997 (BEA NIPA Table 5.3.5, private fixed investment). */
    public const SECULAR_SHARE_1997 = 0.0164;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0122;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroEngine::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from large-ticket OEM capital equipment manufacturing. */
    public const OEM_EQUIPMENT_WEIGHT = 0.65;
    /** Baseline fraction of revenue derived from high-margin aftermarket MRO parts and service contracts. */
    public const AFTERMARKET_MRO_WEIGHT = 0.35;

    // --- Backlog & Variance Physics ---
    /** Fraction of the OEM order backlog (opening backlog plus new orders) executed and recognized each quarter (~1.9 quarters of coverage). */
    public const OEM_BACKLOG_BURN_RATE = 0.35;
    /** Volatility multiplier for OEM capital equipment sales shocks. */
    public const OEM_VARIANCE_SCALAR = 0.40;
    /** Volatility multiplier for defensive aftermarket MRO consumables and repairs. */
    public const MRO_VARIANCE_SCALAR = 0.10;
    /** Structural variable cost ratio of high-margin aftermarket MRO parts. */
    public const MRO_VARIABLE_COST_RATIO = 0.20;

    // --- Pricing Power & Macro Physics ---
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.80; // Highly sensitive to macro shifts
    /** Sensitivity of OEM capital equipment orders to aggregate industrial capital capacity overhang. */
    public const CAPITAL_OVERHANG_SCALAR = 0.15;
    /** Sensitivity of OEM heavy equipment orders to manufacturing PMI survey shifts. */
    public const PMI_DEMAND_SENSITIVITY = 0.50;

    // --- Revenue & Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR = 0.35; // Volatile sales

    // --- Capacity Utilization & Global Supply Chain Physics ---
    /** Sensitivity of heavy factory fixed overhead absorption and variable margin to capacity utilization deviations. */
    public const CU_MARGIN_ABSORPTION_SENSITIVITY = 0.15;
    /** Variable margin cost drag per standard deviation of global supply chain pressure (GSCPI). */
    public const GSCPI_COST_PENALTY_SCALAR        = 0.020;

    // --- Valuation ---
    /** Book (replacement cost) weight in fair value when normalised EPS is negative: trough cyclicals trade on assets. */
    public const TROUGH_BOOK_WEIGHT = 0.70;
    /** Book weight in fair value through the rest of the cycle. */
    public const MID_CYCLE_BOOK_WEIGHT = 0.40;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Heavy manufacturing is extremely sensitive to the output gap and manufacturing PMI
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);
        $pmiShift = MacroTransmission::calculatePmiDemandShift($macroState->manufacturingPmiEma, MacroEngine::PMI_BASELINE, self::PMI_DEMAND_SENSITIVITY);

        // Amplify the output gap impact with leading ISM manufacturing PMI activity
        $physics['macro_demand_shift'] = ($outputGap * $beta * 1.50) + ($pmiShift * $beta);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value   => self::MIN_BETA_PRICING_POWER_FLOOR,
            ModelParam::OemEquipmentWeight->value  => self::OEM_EQUIPMENT_WEIGHT,
            ModelParam::AftermarketMroWeight->value => self::AFTERMARKET_MRO_WEIGHT,
        ]);

        $oemWeight     = $params[ModelParam::OemEquipmentWeight];
        $mroWeight     = $params[ModelParam::AftermarketMroWeight];
        $pricingPower  = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'oem_equipment'   => $params[ModelParam::OemEquipmentWeight],
            'aftermarket_mro' => $params[ModelParam::AftermarketMroWeight],
        ]);

        $oemWeight = $activeWeights['oem_equipment'];
        $mroWeight = $activeWeights['aftermarket_mro'];

        // Independent stream Z-scores
        $oemZ = $streams->generateZ('oem_equipment', 0.20);
        $mroZ = $streams->generateZ('aftermarket_mro', 0.40);

        $mroShock = $mroZ * ($baselineVol * self::MRO_VARIANCE_SCALAR);

        $overhangDrag = $macroState->capitalStockOverhangEma * self::CAPITAL_OVERHANG_SCALAR;

        // OEM equipment is booked into a multi-quarter order backlog and recognized over time: a demand shock
        // hits orders in full but reaches revenue only at the backlog burn rate, and the rest persists.
        // Metzler inventory cycle: dealer lots and fleet stocks are drawn down before new OEM orders are placed.
        $inventoryCycleShift = -$macroState->inventoryStockGapEma * self::INVENTORY_CYCLE_SENSITIVITY;
        $oemOrderMultiplier = max(0.0, 1.0 + ($oemZ * ($baselineVol * self::OEM_VARIANCE_SCALAR)) + $this->resolveFxDemandShift($macroState) - $overhangDrag + $inventoryCycleShift);
        $oemBook = $streams->recognizeBacklog('oem_equipment', $expectedRevenue * $oemWeight, $oemOrderMultiplier, self::OEM_BACKLOG_BURN_RATE);
        $oemRevenue = $oemBook['revenue'];
        $mroRevenue = max(0.0, $expectedRevenue * $mroWeight * (1.0 + $mroShock));
        $streamRevenues = [
            'oem_equipment'   => $oemRevenue,
            'aftermarket_mro' => $mroRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Structural Margin Blending:
        // MRO consumables operate at structurally low variable cost (high margin).
        // OEM equipment carries the heavy direct manufacturing and metal/assembly cost.
        $expectedMroRevenue = $expectedRevenue * $mroWeight;
        $expectedOemRevenue = $expectedRevenue * $oemWeight;
        $mroBaselineCosts   = $expectedMroRevenue * self::MRO_VARIABLE_COST_RATIO;
        $targetTotalCosts   = $expectedRevenue * $realizedVariableMargin;
        $oemBaselineCosts   = max(0.0, $targetTotalCosts - $mroBaselineCosts);
        $oemVariableMargin  = $expectedOemRevenue > 0 ? ($oemBaselineCosts / $expectedOemRevenue) : $realizedVariableMargin;

        $actualVariableCosts = ($mroRevenue * self::MRO_VARIABLE_COST_RATIO) + ($oemRevenue * $oemVariableMargin);

        // Input cost basket: energy, metals, freight, wholesale components and shop-floor payroll, recovered in
        // equipment pricing at the firm's pricing power. Supply chain bottlenecks (expediting, air freight) add on top.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
        $gscpiShift = max(0.0, $macroState->supplyChainPressureIndexEma);
        $gscpiCostDrag = $gscpiShift * self::GSCPI_COST_PENALTY_SCALAR;

        // Capacity Utilization Overhead Absorption:
        // High industrial capacity utilization improves factory fixed overhead absorption, expanding margins.
        $cuDeviation = $macroState->capacityUtilizationRateEma - MacroEngine::CU_BASELINE;
        $cuMarginAdjustment = - ($cuDeviation * self::CU_MARGIN_ABSORPTION_SENSITIVITY);

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin + $inputCostDrag + $gscpiCostDrag + $cuMarginAdjustment);

        $primaryShockZ = $streams->resolveDominantShockZ([$oemZ, $mroZ]);
        $observableShockZ = ($oemZ * $oemWeight * self::OEM_VARIANCE_SCALAR * self::OEM_BACKLOG_BURN_RATE) +
            ($mroZ * $mroWeight * self::MRO_VARIANCE_SCALAR);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
                    kpis: ['book_to_bill' => $oemBook['book_to_bill'], 'backlog_quarters' => $oemBook['backlog_quarters']],
        );
    }

    /** Heavy manufacturers anchor to book (replacement cost) at trough earnings and to mid-cycle earnings through expansions. */
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
            'capital_stock_overhang_ema',
            'exchange_rate_index_ema',
            'industrial_metals_index_ema',
            'inventory_stock_gap_ema',
            'manufacturing_pmi_ema',
            'output_gap_lag_12m',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
