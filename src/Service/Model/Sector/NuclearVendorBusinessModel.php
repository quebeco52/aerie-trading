<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for nuclear reactor vendors: the designers and builders of reactors, who fuel and service the fleet
 * they built.
 *
 * Financial Physics:
 * - Fuel: every reactor of the vendor's design reloads with its fuel every year or two under long contracts with
 *   escalators, so fuel sales follow the fleet, not the economy.
 * - Plant Services: refuelling outages, inspections and upgrades, bunched into the spring and autumn outage seasons.
 * - New Build: plant contracts arrive one at a time and bill over about five years of construction. They come more
 *   often when energy is dear, when a carbon price lifts the District's power price and when long rates are low, and
 *   less often when a strong currency prices the vendor out of tenders abroad.
 * - Fixed-Price Overruns: a plant in trouble runs at a loss until it is delivered; contract prices carry the average
 *   overrun, so the years without one earn it back.
 * - Accidents: a serious accident anywhere freezes most new orders for years, idles part of the fleet and sets off a
 *   round of safety retrofits.
 */
class NuclearVendorBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (0.2): reactors run flat out whatever the economy does. */
    public const OPERATING_CYCLICALITY = 0.20;
    /** Own-price elasticity of demand: a reactor reloads with its designer's fuel. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.10;
    /**
     * Share of an idiosyncratic revenue gain taken from same-industry peers: plant tenders are won from rival vendors.
     */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::NUCLEAR_VENDOR;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate (21%): the plant tenders won
     * abroad against foreign vendors. Fuel and service are locked in to the design.
     */
    public const FX_REVENUE_EXPOSURE = 0.21;

    // --- Pricing ---
    /** Escalators in the fuel and service contracts recover input costs; fixed-price plant contracts do not. */
    public const PRICING_POWER_INDEX = 0.60;

    // --- Balance Sheet Realism ---
    /** Capitalized office and warehouse leases as a fraction of annual revenue (2%): the fuel plants are owned. */
    public const LEASE_LIABILITY_INTENSITY = 0.02;

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (55%): the outage calendar and the plant contracts are public. */
    public const BASE_COVERAGE_VISIBILITY = 0.55;
    /** Standard deviation of the consensus forecast error (0.05). */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Lines of Business ---
    /** Baseline fraction of revenue from fuel for the reactors of the vendor's design. */
    public const FUEL_WEIGHT = 0.40;
    /** Baseline fraction of revenue from outages, inspections, upgrades and life extensions. */
    public const PLANT_SERVICES_WEIGHT = 0.35;
    /** Baseline fraction of revenue from building new plants. */
    public const NEW_BUILD_WEIGHT = 0.25;

    // --- Line Margins ---
    /**
     * Variable cost of plant construction per dollar relative to the firm's average (1.25x): building a plant is
     * low-margin work, and the vendor earns its profit fuelling and servicing it for sixty years.
     */
    public const NEW_BUILD_COST_MULTIPLE = 1.25;

    // --- New Build Orders ---
    /** Fraction of the plant backlog built and billed each quarter (5%): about five years of construction. */
    public const BUILD_BURN_RATE = 0.05;
    /** Quarterly chance of a plant contract at neutral conditions (25%): about one a year. */
    public const ORDER_PROBABILITY = 0.25;
    /** Order chance per unit of the log energy price over its decade trend: a 30% rise brings about 26% more. */
    public const ORDER_ENERGY_SENSITIVITY = 1.0;
    /**
     * Order chance per unit of the ten-year yield over its neutral level, both ways: a plant is mostly financing, so
     * one point on long rates costs a fifth of the orders.
     */
    public const ORDER_RATE_SENSITIVITY = 20.0;
    /** Share of plant orders placed by the District's own utilities (15%); the rest are tendered abroad. */
    public const HOME_ORDER_SHARE = 0.15;
    /** Home order chance per unit of the carbon price's uplift on the District's power price. */
    public const ORDER_CARBON_SENSITIVITY = 1.0;

    // --- Fixed-Price Overruns ---
    /** Regime key for a plant in overrun (two-state Markov). */
    public const REGIME_PLANT_OVERRUN = 'plant_overrun';
    /** Quarterly probability a plant under construction goes into overrun (3%): about one every eight years. */
    public const OVERRUN_ONSET_HAZARD = 0.03;
    /** Quarterly probability the troubled plant is delivered (12.5%): about two years of losses. */
    public const OVERRUN_EXIT_HAZARD = 0.125;
    /** Extra cost per dollar of plant construction billed while a plant is in overrun (25%). */
    public const OVERRUN_COST = 0.25;

    // --- Accidents ---
    /** Regime key for the years after a serious accident somewhere in the world (two-state Markov). */
    public const REGIME_ACCIDENT = 'reactor_accident';
    /**
     * Quarterly probability of a serious accident anywhere (1.25%): about one in twenty years, as Three Mile Island
     * (1979), Chernobyl (1986) and Fukushima (2011) were.
     */
    public const ACCIDENT_ONSET_HAZARD = 0.0125;
    /** Quarterly probability the freeze on new plants lifts (10%): about two and a half years of reviews. */
    public const ACCIDENT_EXIT_HAZARD = 0.10;
    /** Share of plant orders an accident freezes while the reviews last (70%). */
    public const ACCIDENT_ORDER_CUT = 0.70;
    /** Share of the plants contracted or under construction their owners cancel when an accident happens (20%). */
    public const ACCIDENT_BACKLOG_CANCELLATION = 0.20;
    /** Share of the fleet's fuel lost to reactors idled pending review (15%). */
    public const ACCIDENT_FUEL_CUT = 0.15;
    /** Service work added by the safety retrofits the regulators order (4%). */
    public const ACCIDENT_RETROFIT_LIFT = 0.04;

    // --- Revenue Volatility ---
    /** Volatility multiplier on fuel sales: the timing of reloads. */
    public const FUEL_VARIANCE_SCALAR = 0.08;
    /** Volatility multiplier on service sales: outages that run long or short, upgrades won or lost. */
    public const SERVICES_VARIANCE_SCALAR = 0.10;

    // --- Return Reversion ---
    /**
     * Excess return over the required return the moat sustains (3pp): licensed designs and a fleet that needs its fuel.
     */
    public const MOAT_SPREAD = 0.03;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.5): fuel plants and workshops. */
    public const CAPEX_CYCLICALITY = 0.50;
    /** Net working capital as a share of revenue (6%): fuel in the pipeline, less customer advances on plants. */
    public const WORKING_CAPITAL_INTENSITY = 0.06;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: refuelling outages are taken in spring and
     * autumn, when power demand is lowest.
     */
    public const SEASONALITY_FACTORS = [0.97, 1.03, 0.97, 1.03];

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (3x). */
    public const MIN_ICR = 3.0;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (2x). */
    public const DIVIDEND_CRISIS_ICR = 2.0;
    /** Interest coverage below which the firm stops repurchasing stock (3x). */
    public const BUYBACK_MIN_ICR = 3.0;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Fuel and service follow the fleet, and plant orders reach revenue through the backlog in
        // calculateSectorPhysics(), where the exchange rate prices the tenders abroad.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /** The work the vendor is staffed for: the plants it has in hand, at the new-build weight. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return 0.0;
        }
        $workload = StreamContext::openingWorkload($stock->getEarningsMomentumZ() ?? [], 'new_build', self::BUILD_BURN_RATE);

        return $weights['new_build'] * ($workload - 1.0) / $total;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        $plan = $total > 0.0 ? $expectedRevenue / $total : 0.0;

        $fuelZ = $streams->generateZ('fuel', 0.20);
        $servicesZ = $streams->generateZ('plant_services', 0.40);

        // --- Accident and overrun: each measured against its stationary share, so the years without one carry what
        // it costs ---
        $accidentElapsed = $streams->evolveRegime(self::REGIME_ACCIDENT, self::ACCIDENT_ONSET_HAZARD, self::ACCIDENT_EXIT_HAZARD);
        $accident = ($accidentElapsed > 0 ? 1.0 : 0.0) - StochasticProcesses::twoStateStationaryShare(self::ACCIDENT_ONSET_HAZARD, self::ACCIDENT_EXIT_HAZARD);
        $overrunElapsed = $streams->evolveRegime(self::REGIME_PLANT_OVERRUN, self::OVERRUN_ONSET_HAZARD, self::OVERRUN_EXIT_HAZARD);
        $overrun = ($overrunElapsed > 0 ? 1.0 : 0.0) - StochasticProcesses::twoStateStationaryShare(self::OVERRUN_ONSET_HAZARD, self::OVERRUN_EXIT_HAZARD);

        // --- Fleet: reloads less the reactors an accident idles, outages plus the retrofits it orders ---
        $fuel = $plan * $weights['fuel'] * max(0.0, 1.0 - (self::ACCIDENT_FUEL_CUT * $accident) + ($fuelZ * $baselineVol * self::FUEL_VARIANCE_SCALAR));
        $services = $plan * $weights['plant_services'] * max(0.0, 1.0 + (self::ACCIDENT_RETROFIT_LIFT * $accident) + ($servicesZ * $baselineVol * self::SERVICES_VARIANCE_SCALAR));

        // --- New build: a plant contract is a year of construction billing, booked whole and built over five; an
        // accident cancels part of the book, which the contracts won between accidents make up ---
        $orderProbability = $this->resolveOrderProbability($macroState) * (1.0 - (self::ACCIDENT_ORDER_CUT * $accident));
        $ordered = $mathUtility->checkProbability(min(1.0, max(0.0, $orderProbability)));
        $book = $streams->recognizeBacklog(
            'new_build',
            $plan * $weights['new_build'],
            $ordered ? self::cancellationCompensator() / self::ORDER_PROBABILITY : 0.0,
            self::BUILD_BURN_RATE,
            $accidentElapsed === 1 ? self::ACCIDENT_BACKLOG_CANCELLATION : 0.0,
        );

        $streamRevenues = [
            'fuel'           => $fuel,
            'plant_services' => $services,
            'new_build'      => $book['revenue'],
        ];
        $actualRevenue = array_sum($streamRevenues);

        // --- Costs: construction dearer per dollar than the fleet's fuel and service, and dearer still while a plant
        // is in overrun ---
        $newBuildShare = $total > 0.0 ? $weights['new_build'] / $total : 0.0;
        $fleetCostRatio = $newBuildShare < 1.0 ? $realizedVariableMargin * (1.0 - ($newBuildShare * self::NEW_BUILD_COST_MULTIPLE)) / (1.0 - $newBuildShare) : 0.0;
        $newBuildCostRatio = ($realizedVariableMargin * self::NEW_BUILD_COST_MULTIPLE) + (self::OVERRUN_COST * $overrun);
        $variableCosts = (max(0.0, $fleetCostRatio) * ($fuel + $services)) + (max(0.0, $newBuildCostRatio) * $book['revenue']);

        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $eventType = null;
        if ($accidentElapsed === 1) {
            $eventType = ShockEvent::REACTOR_ACCIDENT;
        } elseif ($overrunElapsed === 1) {
            $eventType = ShockEvent::REACTOR_PLANT_OVERRUN;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $this->clampMargin(($actualRevenue > 0.0 ? $variableCosts / $actualRevenue : $realizedVariableMargin) + $inputCostDrag),
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'book_to_bill'     => $book['book_to_bill'],
                'backlog_quarters' => $expectedRevenue > 0.0 ? $book['backlog'] / $expectedRevenue : 0.0,
            ],
        );
    }

    /**
     * Quarterly chance of a plant contract before any accident. Dear energy, a carbon price at home and cheap long-term
     * finance bring orders forward; a strong currency loses tenders abroad.
     */
    public function resolveOrderProbability(MacroStateDTO $macroState): float
    {
        // The energy price over its decade trend: how dear the gas a reactor would displace is against what it has
        // been.
        $energyPrice = $macroState->energyPriceIndexDecadeTrend > 0.0 && $macroState->energyPriceIndexEma > 0.0
            ? log($macroState->energyPriceIndexEma / $macroState->energyPriceIndexDecadeTrend)
            : 0.0;
        // The ten-year yield over the neutral policy rate and the steady term premium: what financing a plant costs
        // against normal.
        $financing = $macroState->yield10yEma - $macroState->perceivedNeutralRate - MacroConstants::NS_BASE_TERM_PREMIUM;
        $attractiveness = 1.0
            + (self::ORDER_ENERGY_SENSITIVITY * $energyPrice)
            - (self::ORDER_RATE_SENSITIVITY * $financing)
            + (self::HOME_ORDER_SHARE * self::ORDER_CARBON_SENSITIVITY * CommodityLogisticsSubsystem::carbonPowerPriceUplift($macroState->carbonPrice))
            + $this->resolveFxDemandShift($macroState, 1.0 - self::HOME_ORDER_SHARE);

        return self::ORDER_PROBABILITY * max(0.0, $attractiveness);
    }

    /**
     * Order intake that keeps plant billing on plan net of the work accidents cancel. A book that loses a share c of
     * itself each quarter in expectation bills burn / (burn + c - burn c) of its intake at steady state, so the
     * contracts won between accidents are 1 + c (1 - burn) / burn times the plan.
     */
    private static function cancellationCompensator(): float
    {
        $accidentShare = StochasticProcesses::twoStateStationaryShare(self::ACCIDENT_ONSET_HAZARD, self::ACCIDENT_EXIT_HAZARD);
        $cancelledPerQuarter = self::ACCIDENT_ONSET_HAZARD * (1.0 - $accidentShare) * self::ACCIDENT_BACKLOG_CANCELLATION;

        return 1.0 + ($cancelledPerQuarter * (1.0 - self::BUILD_BURN_RATE) / self::BUILD_BURN_RATE);
    }

    /**
     * @return array{fuel: float, plant_services: float, new_build: float}
     */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ReactorFuelWeight->value   => self::FUEL_WEIGHT,
            ModelParam::PlantServicesWeight->value => self::PLANT_SERVICES_WEIGHT,
            ModelParam::NewBuildWeight->value      => self::NEW_BUILD_WEIGHT,
        ]);

        return [
            'fuel'           => (float) $params[ModelParam::ReactorFuelWeight],
            'plant_services' => (float) $params[ModelParam::PlantServicesWeight],
            'new_build'      => (float) $params[ModelParam::NewBuildWeight],
        ];
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
            'energy_cost_push_lag',
            'energy_price_index_decade_trend',
            'energy_price_index_ema',
            'exchange_rate_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'output_gap_ema',
            'perceived_neutral_rate',
            'real_wage_gap',
            'tips_breakeven_ema',
            'yield_10y_ema',
        ];
    }
}
