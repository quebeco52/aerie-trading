<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for Specialty Industrial Machinery.
 *
 * Financial Physics:
 * - High Moat: Specialty products mean higher margins and pricing power.
 * - Backlog-Buffered Cyclicality: Equipment sales are driven by the macro output gap, cushioned by multi-quarter order
 *   backlogs.
 * - Sticky Demand: Once integrated, customers rarely switch, reducing idiosyncratic volatility.
 * - The Razor/Razorblade: High-margin aftermarket services cushion recessions organically.
 */
class SpecialtyIndustrialMachineryBusinessModel extends HeavyManufacturingBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Backlog-cushioned
     * capital equipment demand.
     */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::SPECIALTY_INDUSTRIAL_MACHINERY;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Capital equipment is quoted
     * internationally and competes with European and Japanese builders on price.
     */
    public const FX_REVENUE_EXPOSURE = 0.10;

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach the order book. A capex budget is approved a year before the order is
     * placed and longer before it ships.
     */
    public const DEMAND_LAG_YEARS = 1.25;
    /**
     * An installed base tied to consumable and spare-parts revenue prices close to a monopoly on the aftermarket, which
     * is where the margin sits.
     */
    public const PRICING_POWER_INDEX = 0.70;

    // --- Dual-Stream Revenue Weights ---
    /** Baseline fraction of revenue from heavy equipment and automated machinery sales. */
    public const EQUIPMENT_WEIGHT = 0.70;
    /** Baseline fraction of revenue from high-margin aftermarket parts, consumables, and maintenance. */
    public const SERVICES_WEIGHT  = 0.30;

    // --- Razor/Razorblade Margin Architecture ---
    /** Structural variable cost ratio of the high-margin aftermarket parts and services. */
    public const SERVICES_VARIABLE_COST_RATIO = 0.15;

    // --- Macro & Backlog Physics ---
    /**
     * Fraction of the equipment order backlog (opening backlog plus new orders) executed and recognized each quarter
     * (~1.9 quarters of coverage).
     */
    public const EQUIPMENT_BACKLOG_BURN_RATE = 0.35;
    /** Volatility multiplier for equipment sales variance. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Volatility reduction factor for aftermarket services relative to equipment sales. */
    public const SERVICES_VARIANCE_RATIO = 0.20;
    /** Low scalar sensitivity of machinery equipment orders to aggregate industrial capital capacity overhang. */
    public const CAPITAL_OVERHANG_SCALAR = 0.15;

    // --- Supply Chain Physics ---
    /** Variable margin cost per standard deviation of supply-chain pressure about normal, both ways (0.71pp). */
    public const GSCPI_MARGIN_PENALTY_SCALAR        = 0.0071;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating a severe specialty supply chain or parts disruption. */
    public const SUPPLY_CHAIN_DISRUPTION_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited sourcing for disrupted supply chains. */
    public const SUPPLY_CHAIN_DISRUPTION_PENALTY = 0.06;
    /** Revenue multiplier applied during supply chain delays. */
    public const SUPPLY_CHAIN_REVENUE_DROP_MULT   = 0.90;
    /** Positive z-score threshold indicating a massive multi-year infrastructure or factory project win. */
    public const MEGA_INFRASTRUCTURE_DEAL_Z_SCORE = 2.40;
    /** Top-line equipment revenue multiplier for a mega infrastructure deal win. */
    public const MEGA_INFRASTRUCTURE_DEAL_MULT    = 1.20;

    // --- Secular Demand ---
    /**
     * Business investment in industrial equipment as a share of US nominal GDP in 1997 (BEA NIPA Table 5.3.5, private
     * fixed investment).
     */
    public const SECULAR_SHARE_1997 = 0.0164;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0122;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.1 a year). */
    public const ROIC_REVERSION_SPEED = 0.1;
    /** Excess return over the required return the moat sustains (2.5pp). */
    public const MOAT_SPREAD = 0.025;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (2.5). */
    public const CAPEX_CYCLICALITY = 2.5;
    /** Net working capital as a share of revenue (18%). */
    public const WORKING_CAPITAL_INTENSITY = 0.18;

    // --- Seasonality ---
    /** Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 customer capex budget flush. */
    public const SEASONALITY_FACTORS = [0.97, 1.01, 0.99, 1.03];

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    /**
     * The plant is staffed to the equipment it has in hand and the fleet it services: the opening workload of the
     * equipment backlog, since orders reach revenue by percentage of completion, not through macro_demand_shift, and
     * the aftermarket's loading of the order swing, at their target weights.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EquipmentWeight->value => self::EQUIPMENT_WEIGHT,
            ModelParam::ServicesWeight->value  => self::SERVICES_WEIGHT,
        ]);
        $totalWeight = $params[ModelParam::EquipmentWeight] + $params[ModelParam::ServicesWeight];
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $workload = StreamContext::openingWorkload($stock->getEarningsMomentumZ() ?? [], 'equipment_sales', self::EQUIPMENT_BACKLOG_BURN_RATE);

        return (($params[ModelParam::EquipmentWeight] * ($workload - 1.0))
            + ($params[ModelParam::ServicesWeight] * self::MRO_CYCLE_LOADING * $this->resolveEquipmentOrderShift($stock, $macroState))) / $totalWeight;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EquipmentWeight->value    => self::EQUIPMENT_WEIGHT,
            ModelParam::ServicesWeight->value     => self::SERVICES_WEIGHT,
        ]);

        $pricingPower    = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Revenue Mix ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'equipment_sales'      => $params[ModelParam::EquipmentWeight],
            'aftermarket_services' => $params[ModelParam::ServicesWeight],
        ]);

        $equipmentWeight = $activeWeights['equipment_sales'];
        $servicesWeight  = $activeWeights['aftermarket_services'];

        // Independent stream Z-scores
        $equipmentZ = $streams->generateZ('equipment_sales', 0.20);
        $servicesZ  = $streams->generateZ('aftermarket_services', 0.05);
        $eventZ     = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Sensitivities ---
        // A capital good's accelerator is the same whoever makes it: the cycle hits ORDERS in full and the order
        // backlog below cushions recognised revenue, while the services the installed fleet buys take
        // MRO_CYCLE_LOADING of the same swing at once. The services mix is what makes the firm less cyclical.
        $orderShift = $this->resolveEquipmentOrderShift($stock, $macroState);
        $overhangDrag = $macroState->capitalStockOverhangEma * self::CAPITAL_OVERHANG_SCALAR;

        // --- Tail Risk Events ---
        $dealMultiplier = 1.0;
        $eventType = null;
        $supplyChainPenalty = 0.0;

        if ($eventZ < self::SUPPLY_CHAIN_DISRUPTION_Z_SCORE) {
            $supplyChainPenalty = self::SUPPLY_CHAIN_DISRUPTION_PENALTY;
            $eventType = ShockEvent::COMPONENT_SHORTAGE;
            $dealMultiplier = self::SUPPLY_CHAIN_REVENUE_DROP_MULT;
        } elseif ($eventZ > self::MEGA_INFRASTRUCTURE_DEAL_Z_SCORE) {
            $dealMultiplier = self::MEGA_INFRASTRUCTURE_DEAL_MULT;
            $eventType = ShockEvent::MAJOR_EQUIPMENT_ORDER;
        }

        // --- Clamped Stream Revenue Calculation --- Equipment orders enter a multi-quarter backlog and are recognized
        // at the burn rate (percentage of completion).
        $equipmentShock = $equipmentZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $servicesShock  = $servicesZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::SERVICES_VARIANCE_RATIO);
        $equipmentOrderMultiplier = max(0.0, (1.0 + $equipmentShock + $orderShift - $overhangDrag + $this->resolveFxDemandShift($macroState)) * $dealMultiplier);
        $equipmentBook = $streams->recognizeBacklog('equipment_sales', $expectedRevenue * $equipmentWeight, $equipmentOrderMultiplier, self::EQUIPMENT_BACKLOG_BURN_RATE);
        $equipmentRevenue = $equipmentBook['revenue'];
        $servicesRevenue  = max(0.0, $expectedRevenue * $servicesWeight * (1.0 + $servicesShock + (self::MRO_CYCLE_LOADING * $orderShift)));

        $streamRevenues = [
            'equipment_sales'      => $equipmentRevenue,
            'aftermarket_services' => $servicesRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Razor/Razorblade Margin Blending ---
        $expectedServicesRevenue  = $expectedRevenue * $servicesWeight;
        $expectedEquipmentRevenue = $expectedRevenue * $equipmentWeight;

        $servicesBaselineCosts  = $expectedServicesRevenue * self::SERVICES_VARIABLE_COST_RATIO;
        $targetTotalCosts       = $expectedRevenue * $realizedVariableMargin;
        $equipmentBaselineCosts = max(0.0, $targetTotalCosts - $servicesBaselineCosts);

        $equipmentVariableMargin = $expectedEquipmentRevenue > 0 ? ($equipmentBaselineCosts / $expectedEquipmentRevenue) : $realizedVariableMargin;
        $actualVariableCosts = ($servicesRevenue * self::SERVICES_VARIABLE_COST_RATIO) + ($equipmentRevenue * $equipmentVariableMargin);

        // --- Input Cost Basket ---
        // Energy, metals, wholesale components and payroll, recovered in specialty pricing at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $gscpiCostDrag = ($macroState->supplyChainPressureIndexEma - MacroConstants::GSCPI_BASELINE) * self::GSCPI_MARGIN_PENALTY_SCALAR;

        // Effective blended variable cost ratio
        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $rawMargin = $effectiveMargin + $supplyChainPenalty + $inputCostDrag + $gscpiCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: ['book_to_bill' => $equipmentBook['book_to_bill'], 'backlog_quarters' => $equipmentBook['backlog_quarters']],
        );
    }

    // --- Analyst Coverage ---
    // Niche B2B order books are thinly covered; a large order or plant event is announced.
    /** Share of routine shocks analysts see (15%). */
    public const BASE_COVERAGE_VISIBILITY = 0.15;
    /** Standard deviation of the consensus forecast error (0.05). */
    public const BASE_COVERAGE_ERROR = 0.05;
    /** Floor on routine visibility (0%). */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.00;
    /** Visibility of a quarter with an announced event (60%). */
    public const EVENT_BASE_VISIBILITY = 0.60;
    /** Floor on visibility in an announced-event quarter (30%). */
    public const EVENT_MIN_VISIBILITY = 0.30;

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'capital_stock_overhang_ema',
            'exchange_rate_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_lag_15m',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
