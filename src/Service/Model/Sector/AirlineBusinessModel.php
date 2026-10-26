<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Airlines (scheduled passenger and belly cargo carriers).
 *
 * Financial Physics:
 * - Schedules Set Ahead: seats chase the traffic with a half-year lag, since timetables and crews are planned months
 *   out. When demand turns, the planes fill or empty and fares follow at once; the seats catch up over the half year
 *   after.
 * - A Flight Costs the Same Full or Empty: fuel, crews, landing fees and maintenance follow the seats flown, not the
 *   passengers in them, so the fare and load on a scheduled flight are price, and a full plane goes almost straight
 *   to profit.
 * - Fuel: jet fuel reaches the cost base a quarter after the oil market (part is bought forward), and fares win part
 *   of it back over the year.
 * - Traffic: leisure travel follows household demand and mood, with visitors from the mainland and abroad; business
 *   travel follows the economy and deal flow; belly cargo follows world trade and freight rates; miles sold to card
 *   issuers follow card spending.
 * - Paid in Advance: tickets and miles are paid for before anyone flies, so customers fund the working capital.
 * - Crew Strikes: a rare walkout grounds the fleet for part of a quarter.
 */
class AirlineBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of passenger demand to the macro cycle (1.0 = each line's own sensitivity one for one). */
    public const OPERATING_CYCLICALITY = 1.00;
    /** Own-price elasticity of demand: volume lost per unit of real fare increase (leisure travellers shop on fare). */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.80;
    /**
     * Share of an idiosyncratic revenue gain taken from same-industry peers: passengers are won from rival carriers.
     */
    public const INDUSTRY_SUBSTITUTABILITY = 0.70;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::AIRLINE;
    /** Years for an oil move to reach the fuel bill (0.25): a quarter's fuel is bought forward. */
    public const INPUT_COST_LAG_YEARS = 0.25;

    // --- Services Pricing ---
    /** Elasticity of fares to services (supercore) inflation. */
    public const PRICING_ELASTICITY = 0.70;
    /** Fares are transport services: priced off core services inflation ex-housing. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';
    /** Fare leverage: the hub's slots are scarce, but leisure travellers shop on price. */
    public const PRICING_POWER_INDEX = 0.40;

    // --- Balance Sheet Realism ---
    /**
     * Capitalized aircraft and terminal leases as a fraction of annual revenue (15%): about half the fleet is leased.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.15;

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (30%): traffic and load factors are published every month. */
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    /** Standard deviation of the consensus forecast error (0.06): fares and fuel move inside the quarter. */
    public const BASE_COVERAGE_ERROR = 0.06;

    // --- Lines of Business ---
    /** Baseline fraction of revenue from leisure and visiting passengers. */
    public const LEISURE_PASSENGER_WEIGHT = 0.45;
    /** Baseline fraction of revenue from business and premium passengers. */
    public const BUSINESS_PASSENGER_WEIGHT = 0.32;
    /** Baseline fraction of revenue from belly cargo. */
    public const AIR_CARGO_WEIGHT = 0.10;
    /** Baseline fraction of revenue from miles sold to card issuers and partners. */
    public const LOYALTY_MILES_WEIGHT = 0.13;

    // --- Passenger Demand ---
    /** Leisure trips per unit of the domestic demand gap, both ways: a -3% gap cuts them 6%. */
    public const LEISURE_DEMAND_SENSITIVITY = 2.00;
    /** Leisure trips per unit of the confidence residual over the cycle, both ways. */
    public const LEISURE_MOOD_SENSITIVITY = 0.50;
    /** Share of leisure passengers who are visitors from the mainland and abroad (20%): a home-market carrier. */
    public const VISITOR_SHARE = 0.20;
    /** Visitor trips per unit of the foreign output gap, both ways. */
    public const VISITOR_FOREIGN_SENSITIVITY = 2.00;
    /** Business trips per unit of output gap, both ways: travel budgets are cut early, a -3% gap cuts them 9%. */
    public const BUSINESS_GAP_SENSITIVITY = 3.00;
    /** Business trips per unit of deal flow over its trend, both ways: deals 30% short cut them 4.5%. */
    public const BUSINESS_DEAL_SENSITIVITY = 0.15;

    // --- Cargo and Miles ---
    /** Belly cargo volume per unit of the foreign output gap, both ways. */
    public const CARGO_FOREIGN_SENSITIVITY = 2.00;
    /** Cargo yield per unit of freight rates over their baseline, both ways: freight 40% up lifts it 20%. */
    public const CARGO_FREIGHT_SENSITIVITY = 0.50;
    /** Miles bought per unit of the domestic demand gap, both ways: card spending earns them. */
    public const LOYALTY_SPENDING_SENSITIVITY = 1.00;

    // --- Schedule and Fares ---
    /** Load factor the schedule is planned at (84%). */
    public const PLANNED_LOAD_FACTOR = 0.84;
    /** Highest load factor a schedule can carry (92%): past it, demand is turned away. */
    public const MAXIMUM_LOAD_FACTOR = 0.92;
    /**
     * Fares per unit of passenger demand over seats (1.0): with the load moving one for one, a seat's revenue moves
     * twice the gap, the most carriers lose per seat of excess capacity.
     */
    public const FARE_LOAD_ELASTICITY = 1.00;
    /** Years for the schedule to close a gap to passenger demand (0.5): timetables and crews are planned months out. */
    public const SCHEDULE_LAG_YEARS = 0.50;

    // --- Crew Strikes ---
    /** Z-score of the labour draw below which crews walk out this quarter (-2.17, ~1.5%): about one a 17 years. */
    public const STRIKE_Z_SCORE = -2.17;
    /** Share of the quarter's flying a strike grounds (15%): two weeks of thirteen. */
    public const STRIKE_GROUNDED_SHARE = 0.15;

    // --- Revenue Volatility ---
    /** Volatility multiplier on leisure traffic: holiday bookings, weather and rival fare sales. */
    public const LEISURE_VARIANCE_SCALAR = 0.15;
    /** Volatility multiplier on business traffic: a few large corporate accounts. */
    public const BUSINESS_VARIANCE_SCALAR = 0.15;
    /** Volatility multiplier on cargo: shippers switch between air and sea on the spread. */
    public const CARGO_VARIANCE_SCALAR = 0.25;
    /** Volatility multiplier on miles: contracted volumes with the card partners. */
    public const LOYALTY_VARIANCE_SCALAR = 0.05;

    // --- Secular Demand ---
    /** Air transportation value added as a share of US nominal GDP in 1997 (BEA via FRED USAIRTRANNGSP and GDPA). */
    public const SECULAR_SHARE_1997 = 0.0064;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0065;

    // --- Return Reversion ---
    /** Excess return over the required return the moat sustains (1pp): the hub's slots and gates. */
    public const MOAT_SPREAD = 0.010;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (1.0): aircraft are ordered in booms. */
    public const CAPEX_CYCLICALITY = 1.00;
    /** Net working capital as a share of revenue (-10%): tickets and miles are paid for before anyone flies. */
    public const WORKING_CAPITAL_INTENSITY = -0.10;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: summer holidays fill the middle of the
     * year, the winter after the holidays is the thinnest.
     */
    public const SEASONALITY_FACTORS = [0.88, 1.06, 1.06, 1.00];

    // --- Persisted State ---
    /** Seats the schedule flies this quarter over plan, set the quarter before. */
    public const STATE_SCHEDULED_SEATS = 'state:scheduled_seats';

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (2.5x). */
    public const MIN_ICR = 2.5;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (1.5x). */
    public const DIVIDEND_CRISIS_ICR = 1.5;
    /** Interest coverage below which the firm stops repurchasing stock (3x). */
    public const BUYBACK_MIN_ICR = 3.0;

    /** Belly cargo flies in the hold of the passenger aircraft. */
    public function jointProductLines(): array
    {
        return ['air_cargo'];
    }

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // The cycle reaches traffic and fares in calculateSectorPhysics(); the seats flown follow the schedule.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /** The flying the airline is staffed for: the schedule's seats over plan, on its passenger share. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return 0.0;
        }

        return self::passengerShare($weights) * ($this->resolveOpeningSeats($stock, $macroState) - 1.0);
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        $plan = $total > 0.0 ? $expectedRevenue / $total : 0.0;

        $leisureZ = $streams->generateZ('leisure_passengers', 0.30);
        $businessZ = $streams->generateZ('business_passengers', 0.40);
        $cargoZ = $streams->generateZ('air_cargo', 0.30);
        $loyaltyZ = $streams->generateZ('loyalty_miles', 0.70);
        $labourZ = $streams->generateExogenousZ('crew_labour', 0.0);

        // --- Schedule: the seats planned, and the share of its flights that fly ---
        // A strike cancels flights already sold, with their passengers, belly cargo and costs. The expected loss is in
        // the plan, so the quarters without one fly what the struck ones give up.
        $isStrike = $labourZ < self::STRIKE_Z_SCORE;
        $strikeCompensation = StochasticProcesses::bernoulliLossCompensator(Distributions::standardNormalCdf(self::STRIKE_Z_SCORE), self::STRIKE_GROUNDED_SHARE);
        $flown = $strikeCompensation * ($isStrike ? 1.0 - self::STRIKE_GROUNDED_SHARE : 1.0);

        // --- Passenger demand against the seats scheduled: one load and one fare across the cabins ---
        $demand = $this->resolvePassengerDemand($stock, $macroState);
        $seats = $this->resolveOpeningSeats($stock, $macroState);
        $demandPerSeat = $seats > 0.0 ? $demand['passengers'] / $seats : 0.0;
        // Past the most a schedule carries, demand is turned away; the fare prices what is left of each seat.
        $carried = $demandPerSeat > 0.0 ? min(1.0, (self::MAXIMUM_LOAD_FACTOR / self::PLANNED_LOAD_FACTOR) / $demandPerSeat) : 0.0;
        $fare = max(0.0, 1.0 + (self::FARE_LOAD_ELASTICITY * ($demandPerSeat - 1.0)));
        $streams->registerState(self::STATE_SCHEDULED_SEATS, $seats + (TimeSeries::smoothingWeight(FinancialConstants::QUARTER_YEARS, self::SCHEDULE_LAG_YEARS) * ($demand['passengers'] - $seats)));

        // --- Belly cargo rides the passenger flights; miles are sold to the card partners ---
        $cargoShift = MacroTransmission::calculateForeignDemandShift($macroState->foreignOutputGapEma, sensitivity: self::CARGO_FOREIGN_SENSITIVITY)
            + (self::CARGO_FREIGHT_SENSITIVITY * ($macroState->freightRateIndexEma - 100.0) / 100.0);
        $loyaltyShift = $macroState->domesticDemandGapEma * self::LOYALTY_SPENDING_SENSITIVITY;

        $streamRevenues = [
            'leisure_passengers'  => max(0.0, $plan * $weights['leisure_passengers'] * $demand['leisure'] * $carried * $fare * $flown
                * (1.0 + ($leisureZ * $baselineVol * self::LEISURE_VARIANCE_SCALAR))),
            'business_passengers' => max(0.0, $plan * $weights['business_passengers'] * $demand['business'] * $carried * $fare * $flown
                * (1.0 + ($businessZ * $baselineVol * self::BUSINESS_VARIANCE_SCALAR))),
            'air_cargo'           => max(0.0, $plan * $weights['air_cargo'] * $flown * (1.0 + $cargoShift + ($cargoZ * $baselineVol * self::CARGO_VARIANCE_SCALAR))),
            'loyalty_miles'       => max(0.0, $plan * $weights['loyalty_miles'] * (1.0 + $loyaltyShift + ($loyaltyZ * $baselineVol * self::LOYALTY_VARIANCE_SCALAR))),
        ];
        $actualRevenue = array_sum($streamRevenues);

        // Fuel and crews above trend reach the cost of each seat flown; fares win part back at the firm's pricing
        // power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        // What the flying costs follows the seats flown; the load, the fare, the belly space and the miles sold on
        // them are price.
        $flownRevenue = $expectedRevenue * ((self::passengerShare($weights) * $seats * $flown) + (1.0 - self::passengerShare($weights)));

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $this->clampMargin($realizedVariableMargin + $inputCostDrag),
            eventType: $isStrike ? ShockEvent::FLIGHT_CREW_STRIKE : null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'seat_capacity_index' => $seats * $flown,
                'load_factor'         => self::PLANNED_LOAD_FACTOR * $demandPerSeat * $carried,
            ],
            priceRevenue: $actualRevenue - $flownRevenue,
        );
    }

    /**
     * Passenger demand over plan by cabin and across them: leisure on household demand and mood, with visitors on the
     * foreign cycle; business on the economy and deal flow.
     *
     * @return array{leisure: float, business: float, passengers: float}
     */
    private function resolvePassengerDemand(Stock $stock, MacroStateDTO $macroState): array
    {
        $weights = $this->resolveLineWeights($stock);
        $beta = $this->getOperatingCyclicality($stock);
        $leisure = max(0.0, 1.0
            + ((1.0 - self::VISITOR_SHARE) * (($macroState->domesticDemandGapEma * self::LEISURE_DEMAND_SENSITIVITY) + ($macroState->sentimentResidual() * self::LEISURE_MOOD_SENSITIVITY)) * $beta)
            + (self::VISITOR_SHARE * MacroTransmission::calculateForeignDemandShift($macroState->foreignOutputGapEma, sensitivity: self::VISITOR_FOREIGN_SENSITIVITY)));
        $business = max(0.0, 1.0
            + ($macroState->outputGapEma * self::BUSINESS_GAP_SENSITIVITY * $beta)
            + ($macroState->dealActivityDeviation() * self::BUSINESS_DEAL_SENSITIVITY));
        $passengerWeight = $weights['leisure_passengers'] + $weights['business_passengers'];

        return [
            'leisure'    => $leisure,
            'business'   => $business,
            'passengers' => $passengerWeight > 0.0 ? (($weights['leisure_passengers'] * $leisure) + ($weights['business_passengers'] * $business)) / $passengerWeight : 1.0,
        ];
    }

    /**
     * Seats the schedule flies this quarter over plan, as set the quarter before. A schedule with no history opens on
     * the demand in front of it, so its first quarter books no phantom load.
     */
    private function resolveOpeningSeats(Stock $stock, MacroStateDTO $macroState): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];

        return max(0.0, (float) ($momentum[self::STATE_SCHEDULED_SEATS] ?? $this->resolvePassengerDemand($stock, $macroState)['passengers']));
    }

    /**
     * @param array{leisure_passengers: float, business_passengers: float, air_cargo: float, loyalty_miles: float} $weights
     */
    private static function passengerShare(array $weights): float
    {
        $total = array_sum($weights);

        return $total > 0.0 ? ($weights['leisure_passengers'] + $weights['business_passengers']) / $total : 0.0;
    }

    /**
     * @return array{leisure_passengers: float, business_passengers: float, air_cargo: float, loyalty_miles: float}
     */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::LeisurePassengerWeight->value  => self::LEISURE_PASSENGER_WEIGHT,
            ModelParam::BusinessPassengerWeight->value => self::BUSINESS_PASSENGER_WEIGHT,
            ModelParam::AirCargoWeight->value          => self::AIR_CARGO_WEIGHT,
            ModelParam::LoyaltyMilesWeight->value      => self::LOYALTY_MILES_WEIGHT,
        ]);

        // Cargo is a market of its own (Segments): with plant kept by segment its lines take the share of the quarter
        // its plant sells; on one plant it leans by its industry's outlook against the blend.
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $weights = [
            'leisure_passengers'  => (float) $params[ModelParam::LeisurePassengerWeight],
            'business_passengers' => (float) $params[ModelParam::BusinessPassengerWeight],
            'air_cargo'           => (float) $params[ModelParam::AirCargoWeight],
            'loyalty_miles'       => (float) $params[ModelParam::LoyaltyMilesWeight],
        ];
        $split = SegmentBooks::splitToTargets($stock->getTicker(), $momentum, $weights);

        return [
            'leisure_passengers'  => $split['leisure_passengers'] ?? $weights['leisure_passengers'] * SegmentOutlook::tilt($momentum, 'leisure_passengers'),
            'business_passengers' => $split['business_passengers'] ?? $weights['business_passengers'] * SegmentOutlook::tilt($momentum, 'business_passengers'),
            'air_cargo'           => $split['air_cargo'] ?? $weights['air_cargo'] * SegmentOutlook::tilt($momentum, 'air_cargo'),
            'loyalty_miles'       => $split['loyalty_miles'] ?? $weights['loyalty_miles'] * SegmentOutlook::tilt($momentum, 'loyalty_miles'),
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
            'consumer_sentiment_index_ema',
            'deal_activity_index_ema',
            'domestic_demand_gap_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'foreign_output_gap_ema',
            'freight_rate_index_ema',
            'import_price_level',
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
