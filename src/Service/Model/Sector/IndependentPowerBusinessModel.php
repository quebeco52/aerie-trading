<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for independent power producers: merchant generators with no rate base.
 *
 * Financial Physics:
 * - Revenue is output sold into the wholesale market. A rolling four-quarter ladder sells most of next year's output
 *   forward, and output above or below what was sold settles at spot: a dry year bought back at a high price costs twice.
 * - Merit order: the gas-fired share earns the clean spark spread, its gas bought forward with the power it sells; the
 *   zero-fuel share (hydro, tidal) keeps the whole price, so a gas rise that lifts power adds to its margin.
 * - Load follows the output gap. The zero-fuel plant runs whatever the load, so the swing falls on the gas stations.
 * - Capacity payments for available plant are indexed to inflation and barely move.
 * - A large unit out for repair loses its output and its capacity payment until it returns.
 * - No rate base, rate case or allowed return: the firm is priced on earnings.
 */
class IndependentPowerBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Electricity load per unit of output gap: industry and commerce follow activity, households barely (short-run
     * income elasticities of electricity demand mostly run 0.2-0.7).
     */
    public const OPERATING_CYCLICALITY = 0.50;
    /**
     * Own-price elasticity of the firm's demand: a generator bidding above the pool price is simply not dispatched, so
     * residual demand is very elastic. Inert while the price is the pool's.
     */
    public const PRICE_ELASTICITY_OF_DEMAND = 2.50;
    /**
     * Share of an idiosyncratic revenue gain taken from same-industry peers: a generator's extra MWh displaces the
     * marginal plant in the pool, most of it the fringe's and imports.
     */
    public const INDUSTRY_SUBSTITUTABILITY = 0.30;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts; fuel, carbon and
     * purchased power are priced in the physics below.
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::INDEPENDENT_POWER;
    /** The pool sets the price: none of the firm's own input inflation is recovered in what it charges. */
    public const PRICING_POWER_INDEX = 0.00;
    /** Capacity contracts are indexed to inflation in full. */
    public const PRICING_ELASTICITY = 1.00;
    /** Capacity contracts reprice once a year. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.00;
    /** Capacity contracts escalate with the consumer price index. */
    public const PRICING_INFLATION_BASIS = 'inflation_ema';

    // --- Analyst Visibility & Error ---
    /**
     * Base coverage visibility of the fleet's own output; the published prices and the disclosed hedge book are read in
     * full beside it.
     */
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    /** Base coverage forecasting error given the swings in the power price. */
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue: the stations and the sites under them
     * are owned.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.03;
    /**
     * Working capital intensity: a month of receivables, fuel stocks and the collateral posted against the hedge book.
     */
    public const WORKING_CAPITAL_INTENSITY = 0.10;
    /**
     * Share of construction in progress completed each quarter: a gas station or a turbine refit takes two to three
     * years from financial close to service.
     */
    public const CAPEX_COMPLETION_RATE = 0.10;
    /**
     * Quarterly share of the fixed-rate debt that matures and reprices: term loans and high-yield notes of about seven
     * years.
     */
    public const DEBT_MATURITY_ROLLOVER_RATE = 0.035;

    // --- Revenue Mix ---
    /** Default share of revenue from power sold into the wholesale market. */
    public const WHOLESALE_GENERATION_WEIGHT = 0.80;
    /**
     * Default share of revenue from capacity payments for keeping plant available, about a fifth in a market that pays
     * for capacity.
     */
    public const CAPACITY_PAYMENT_WEIGHT = 0.20;

    // --- Fleet & Load ---
    /** Default share of output from gas-fired plant: the US utility-scale share, 43.1% in 2023 (EIA). */
    public const GAS_FLEET_SHARE = UtilityBusinessModel::MERCHANT_GAS_FLEET_SHARE;
    /**
     * Share of the system's generation from gas, the plant that follows load: the zero-fuel fleet runs whatever the
     * load, so a load swing reaches gas output divided by this share (US utility-scale, 43.1% in 2023, EIA).
     */
    public const SYSTEM_GAS_GENERATION_SHARE = UtilityBusinessModel::MERCHANT_GAS_FLEET_SHARE;

    // --- Hedge Book ---
    /**
     * Default share of next year's wholesale output sold forward; merchant generators commonly sell well over half of
     * the coming year.
     */
    public const POWER_HEDGE_RATIO = 0.60;
    /**
     * Quarterly tranches in the rolling forward ladder: each quarter one tranche is locked for each of the next four
     * quarters.
     */
    public const HEDGE_LADDER_TRANCHES = 4;

    // --- Revenue Shock Physics ---
    /**
     * Volatility multiplier on the baseline volatility for zero-fuel output: one sigma is about 7.5% of it at 30%
     * volatility, as US hydro generation swings about a tenth from year to year.
     */
    public const RESOURCE_VARIANCE_SCALAR = 0.25;
    /** Quarterly persistence of the water and tide resource: dry and wet spells last seasons. */
    public const RESOURCE_PERSISTENCE = 0.50;
    /** Volatility multiplier for capacity payments: contracted, so a sigma moves them a fraction of a percent. */
    public const CAPACITY_VARIANCE_SCALAR = 0.02;
    /** Quarterly persistence of capacity payment shocks (contract resets and availability bonuses). */
    public const CAPACITY_PERSISTENCE = 0.30;
    /** Quarterly persistence of the firm's own tail-event draw. */
    public const EVENT_SHOCK_PERSISTENCE = 0.10;

    // --- Unit Outage ---
    /** Z-score below which a large unit trips or is found unsafe: 2.9% a quarter, about once in eight or nine years. */
    public const OUTAGE_Z_SCORE = -1.90;
    /** Quarterly probability the unit returns (repairs take about two and a half quarters). */
    public const OUTAGE_EXIT_HAZARD = 0.40;
    /** Share of the fleet's output and available capacity one large unit carries, in a fleet of eight or so. */
    public const OUTAGE_OUTPUT_SHARE = 0.12;
    /** Repair bill in the quarter the unit fails, as a share of revenue at baseline prices. */
    public const OUTAGE_REPAIR_COST = 0.03;
    /** Regime key for a unit out of service. */
    public const REGIME_UNIT_OUTAGE = 'unit_outage';

    /**
     * The wholesale price lives in the index the physics reads and carries no inflation of its own, so only the
     * capacity contracts take the engine's escalator: both multipliers carry it on the capacity share alone, and the
     * physics hands it back to that stream in full.
     */
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $capacityShare = $this->resolveRevenueMix($stock)['capacity'];
        $multipliers = $this->resolvePricingMultipliers($stock, $macroState);

        return [
            // Power is sold into the District's own pool at the pool price: the currency reaches it through gas alone.
            'macro_demand_shift' => 0.0,
            'pricing_power_multiplier' => 1.0 + ($capacityShare * ($multipliers['pricing_power_multiplier'] - 1.0)),
            'input_cost_multiplier' => 1.0 + ($capacityShare * ($multipliers['input_cost_multiplier'] - 1.0)),
        ];
    }

    /** The gas stations carry the load swing; the plant is staffed to the output it runs. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $mix = $this->resolveRevenueMix($stock);

        return $mix['wholesale'] * $mix['gas_fleet'] * $this->resolveGasDispatchShift($stock, $macroState);
    }

    /** Share of revenue at baseline prices sold at the wholesale power price. */
    public function resolveWholesalePowerRevenueShare(Stock $stock): float
    {
        return $this->resolveRevenueMix($stock)['wholesale'];
    }

    /** @return array<int, float> */
    public function getSeasonalityFactors(): array
    {
        return self::powerSeasonFactors(self::WHOLESALE_GENERATION_WEIGHT / (self::WHOLESALE_GENERATION_WEIGHT + self::CAPACITY_PAYMENT_WEIGHT));
    }

    /** @return array<int, float> */
    public function resolveSeasonalityFactors(Stock $stock): array
    {
        return self::powerSeasonFactors($this->resolveRevenueMix($stock)['wholesale']);
    }

    /** The season is in the power price, not in the stations' activity: staff and maintenance run through the year. */
    public function getSeasonalFixedCostShare(): float
    {
        return 0.0;
    }

    /**
     * The firm-level season of a revenue mix whose wholesale share sells at the power price and whose capacity payments
     * are flat: the power season as the smoothed index carries it in the quarter the report is filed, the one after the
     * quarter it covers, since the physics reads the price at filing.
     *
     * @return array<int, float>
     */
    private static function powerSeasonFactors(float $wholesaleShare): array
    {
        $raw = [];
        for ($quarter = 0; $quarter < (int) FinancialConstants::QUARTERS_PER_YEAR; ++$quarter) {
            $filingMidpoint = ($quarter + 1.5) * FinancialConstants::QUARTER_YEARS;
            $raw[] = (1.0 - $wholesaleShare) + ($wholesaleShare * CommodityLogisticsSubsystem::smoothedPowerSeason($filingMidpoint));
        }
        $mean = array_sum($raw) / count($raw);

        return array_map(static fn(float $factor): float => $factor / $mean, $raw);
    }

    /**
     * Revenue mix, fleet and hedge ratio for this firm, ticker override first; the two streams are normalised to a
     * whole.
     *
     * @return array{wholesale: float, capacity: float, gas_fleet: float, hedge_ratio: float}
     */
    public function resolveRevenueMix(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::WholesaleGenerationWeight->value => self::WHOLESALE_GENERATION_WEIGHT,
            ModelParam::CapacityPaymentWeight->value     => self::CAPACITY_PAYMENT_WEIGHT,
            ModelParam::MerchantGasFleetShare->value     => self::GAS_FLEET_SHARE,
            ModelParam::PowerHedgeRatio->value           => self::POWER_HEDGE_RATIO,
        ]);
        $wholesale = max(0.0, $params[ModelParam::WholesaleGenerationWeight]);
        $capacity = max(0.0, $params[ModelParam::CapacityPaymentWeight]);
        $total = $wholesale + $capacity;

        return [
            'wholesale'   => $total > 0.0 ? $wholesale / $total : 1.0,
            'capacity'    => $total > 0.0 ? $capacity / $total : 0.0,
            'gas_fleet'   => max(0.0, min(1.0, $params[ModelParam::MerchantGasFleetShare])),
            'hedge_ratio' => max(0.0, min(1.0, $params[ModelParam::PowerHedgeRatio])),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        ['wholesale' => $wholesaleWeight, 'capacity' => $capacityWeight, 'gas_fleet' => $gasShare, 'hedge_ratio' => $hedgeRatio] = $this->resolveRevenueMix($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $capacityZ = $streams->generateZ('capacity_payments', self::CAPACITY_PERSISTENCE);
        $resourceZ = $streams->generateExogenousZ('wholesale_generation', self::RESOURCE_PERSISTENCE);
        $eventZ = $streams->generateExogenousZ('event', self::EVENT_SHOCK_PERSISTENCE);

        // --- Unit Outage ---
        $outageElapsed = $streams->evolveRegime(self::REGIME_UNIT_OUTAGE, 0.0, self::OUTAGE_EXIT_HAZARD);
        $eventType = null;
        $repairCost = 0.0;
        if ($eventZ < self::OUTAGE_Z_SCORE && $outageElapsed === 0) {
            $outageElapsed = $streams->startRegime(self::REGIME_UNIT_OUTAGE);
            $eventType = ShockEvent::GENERATION_OUTAGE;
            $repairCost = self::OUTAGE_REPAIR_COST;
        }
        $availability = $outageElapsed > 0 ? 1.0 - self::OUTAGE_OUTPUT_SHARE : 1.0;

        // --- Output, in units of the wholesale output expected ---
        // Water and tide set the zero-fuel plant's output; the gas stations follow the load it leaves them.
        $zeroFuelOutput = (1.0 - $gasShare) * max(0.0, 1.0 + ($resourceZ * $baselineVol * self::RESOURCE_VARIANCE_SCALAR)) * $availability;
        $gasOutput = $gasShare * max(0.0, 1.0 + $this->resolveGasDispatchShift($stock, $macroState)) * $availability;
        $output = $zeroFuelOutput + $gasOutput;

        // --- Prices: the forward ladders and spot ---
        $powerStrike = $streams->rollHedgeLadder(FinancialConstants::STATE_POWER_STRIKE_SUM_PREFIX, self::HEDGE_LADDER_TRANCHES, static fn(int $quarters): float => CommodityLogisticsSubsystem::powerForwardRelative($macroState, $quarters * FinancialConstants::QUARTER_YEARS));
        $gasStrike = $streams->rollHedgeLadder(FinancialConstants::STATE_GAS_STRIKE_SUM_PREFIX, self::HEDGE_LADDER_TRANCHES, static fn(int $quarters): float => CommodityLogisticsSubsystem::gasForwardRelative($macroState, $quarters * FinancialConstants::QUARTER_YEARS));
        $powerSpot = max(0.0, $macroState->wholesalePowerPriceIndexEma) / MacroConstants::WHOLESALE_POWER_BASELINE;
        $gasSpot = max(0.0, $macroState->naturalGasPriceIndexEma) / MacroConstants::NATURAL_GAS_BASELINE;

        // What was sold forward is delivered at its strike; output above or below it settles at spot, so a shortfall is
        // power bought back at today's price. The gas for the gas-fired part was bought forward alongside it.
        $wholesalePriced = ($hedgeRatio * $powerStrike) + (($output - $hedgeRatio) * $powerSpot);
        $gasHedged = $hedgeRatio * $gasShare;
        $gasPaid = $gasOutput > 0.0 ? (($gasHedged * $gasStrike) + (($gasOutput - $gasHedged) * $gasSpot)) / $gasOutput : $gasSpot;

        // --- Revenue ---
        // The engine priced the capacity share's escalator over the whole firm; it belongs to the contracts alone. It
        // also carried the declared season as volume, which is in the power price the stations sell at, so it is
        // taken back out.
        $passThrough = (float) ($stock->getInflationPassThrough() ?? 0.0);
        $quarter = $this->reportedCalendarQuarter();
        $firmSeason = $quarter !== null ? $this->resolveSeasonalityFactors($stock)[$quarter] : 1.0;
        $baseRevenue = $expectedRevenue / (1.0 + ($capacityWeight * $passThrough)) / $firmSeason;
        $streamRevenues = [
            'wholesale_generation' => max(0.0, $baseRevenue * $wholesaleWeight * $wholesalePriced),
            'capacity_payments'    => max(0.0, $baseRevenue * $capacityWeight * (1.0 + $passThrough) * $availability
                * (1.0 + ($capacityZ * $baselineVol * self::CAPACITY_VARIANCE_SCALAR))),
        ];
        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Per-Unit Cost Base ---
        // Revenue at the prices the cost base was sized for, so the ratio below is per MWh. Fuel follows the gas
        // stations' output and the rest of the variable cost the whole fleet's; fuel and carbon are then priced at what
        // the gas cost and what the carbon price charges.
        $volumeRevenue = $baseRevenue * (($wholesaleWeight * $output) + ($capacityWeight * $availability));
        $priceRelative = $volumeRevenue > 0.0 ? $actualRevenue / $volumeRevenue : 1.0;
        $fuelShare = FirmEconomics::gasFuelShareOfPowerPrice();
        $nonFuelCostRatio = max(0.0, $realizedVariableMargin - ($wholesaleWeight * $gasShare * $fuelShare));
        $fuelAtBase = $baseRevenue * $wholesaleWeight * $gasOutput * $fuelShare;
        $fuelChange = $baseRevenue * $wholesaleWeight * $gasOutput * FirmEconomics::gasFiredFuelCostChange($gasPaid, $macroState->carbonPrice);

        // Station crews, maintenance parts and imported spares reach the cost base at spot; the pool recovers none of
        // it.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $nonFuelCostRatio);
        $costRatioAtBase = $volumeRevenue > 0.0
            ? (($nonFuelCostRatio * $volumeRevenue) + $fuelAtBase + $fuelChange) / $volumeRevenue + $inputCostDrag
            : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin(FirmEconomics::calculatePerUnitCostRatio($costRatioAtBase, $priceRelative));

        // What the carbon price's power uplift adds to earnings, per unit of it: the uplift on every MWh sold, less the
        // carbon the gas stations pay.
        $streams->registerState(
            FinancialConstants::STATE_CARBON_POWER_EARNINGS_SHARE,
            $actualRevenue > 0.0 && $output > 0.0
                ? $baseRevenue * $wholesaleWeight * $output * FirmEconomics::carbonUpliftEarningsPerUnit($gasOutput / $output) / $actualRevenue
                : 0.0,
        );

        $hedgeGain = $baseRevenue * $wholesaleWeight * $hedgeRatio * ($powerStrike - $powerSpot);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'generation_index'    => $output,
                'power_price_index'    => $output > 0.0 ? $wholesalePriced / $output : $powerSpot,
                'clean_spark_spread'  => $powerSpot - ($fuelShare * $gasSpot) - FirmEconomics::gasFiredFuelCostChange(1.0, $macroState->carbonPrice),
                'hedge_gain'          => $actualRevenue > 0.0 ? $hedgeGain / $actualRevenue : 0.0,
                'availability'        => $availability,
            ],
            specialCharge: $repairCost * $baseRevenue,
        );
    }

    /**
     * The gas stations' output against expected: load follows the cycle, and the zero-fuel plant leaves the whole swing
     * to gas.
     */
    private function resolveGasDispatchShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return $macroState->outputGapEma * $this->getOperatingCyclicality($stock) / self::SYSTEM_GAS_GENERATION_SHARE;
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
            'carbon_price',
            'energy_base_price',
            'energy_price_index_ema',
            'energy_supply_ema',
            'gas_oil_ratio_log',
            'global_demand_gap_ema',
            'import_price_level',
            'inflation_ema',
            'natural_gas_price_index_ema',
            'output_gap_ema',
            'real_wage_gap',
            'tips_breakeven_ema',
            'wholesale_power_price_index_ema',
        ];
    }
}
