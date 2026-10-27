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
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for Correctional Services (prisons, remand and immigration detention run under concession).
 *
 * Financial Physics:
 * - Beds Under Concession: the District pays a daily rate for each occupied bed, with a guaranteed minimum occupancy,
 *   so revenue is take-or-pay: below the guarantee empty beds are paid for and cost nothing to run; above full,
 *   prisoners go to the District's own prisons.
 * - Custody: the prison population follows crime, which rises with unemployment, but only as fast as sentences run
 *   out, so it moves slowly and long after the cycle.
 * - Detention: detainees for the Trade & Migration Office follow arrivals, which rise in booms and fall in slumps, and
 *   stay weeks rather than years.
 * - Concession Rates: a tenth of the concessions are re-let each year at the rate the Diet's budget then allows, so a
 *   cut to the justice budget reaches the daily rate slowly and stays.
 * - Escort & Tagging: court escort and electronic tagging sold to bail insurers, on the courts' caseload.
 * - Tail Risk: a riot or an escape brings fines, repairs and settlements, and bail insurers move their tagging away.
 */
class CorrectionalServicesBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (0.2): the District fills the prisons, not the shops. */
    public const OPERATING_CYCLICALITY = 0.20;
    /**
     * Own-price elasticity of demand: volume lost per unit of real price increase. The District has few alternatives.
     */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.10;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers: a concession won is one a rival lost. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.30;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel: custody officers are guards, so BEA 561600. */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::SECURITY_PROTECTION;
    /** Concession rates carry an inflation escalator, but the District sets the budget it is paid from. */
    public const PRICING_POWER_INDEX = 0.75;

    // --- Services Pricing ---
    /** Elasticity of the daily rate to services (supercore) inflation: officers' pay is most of the cost. */
    public const PRICING_ELASTICITY = 0.80;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (50%): the District publishes its prison population. */
    public const BASE_COVERAGE_VISIBILITY = 0.50;
    /** Standard deviation of the consensus forecast error (0.03). */
    public const BASE_COVERAGE_ERROR = 0.03;

    // --- Return Reversion ---
    /** Excess return over the required return the moat sustains (1.5pp): prisons are slow to build and to permit. */
    public const MOAT_SPREAD = 0.015;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.3): new prisons follow concessions, not the cycle. */
    public const CAPEX_CYCLICALITY = 0.30;
    /** Net working capital as a share of revenue (10%): the District pays a quarter in arrears. */
    public const WORKING_CAPITAL_INTENSITY = 0.10;

    // --- Seasonality ---
    /** Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: prisons are full all year. */
    public const SEASONALITY_FACTORS = [1.0, 1.0, 1.0, 1.0];

    // --- Lines of Business ---
    /** Baseline fraction of revenue from prisons and remand centres run for the District. */
    public const CUSTODY_WEIGHT = 0.72;
    /** Baseline fraction of revenue from immigration detention for the Trade & Migration Office. */
    public const DETENTION_WEIGHT = 0.18;
    /** Baseline fraction of revenue from court escort and electronic tagging for bail insurers. */
    public const ESCORT_TAGGING_WEIGHT = 0.10;

    // --- Occupancy ---
    /** Share of contracted beds the plan expects filled (92%). */
    public const PLANNED_OCCUPANCY = 0.92;
    /** Share of contracted beds the District pays for however many are filled (85%). */
    public const MINIMUM_OCCUPANCY_GUARANTEE = 0.85;
    /** Most of its contracted beds a prison can fill (every one); the rest go to the District's own prisons. */
    public const MAXIMUM_OCCUPANCY = 1.0;

    // --- Custody ---
    /** Prisoners per unit of the unemployment gap, both ways: a 1pp rise in unemployment adds 2% to the population. */
    public const CRIME_UNEMPLOYMENT_ELASTICITY = 2.0;
    /** Average time served (1.5 years): how slowly the population follows crime. */
    public const CUSTODY_STAY_YEARS = 1.5;

    // --- Detention ---
    /** Detainees per unit of arrivals against their structural rate (1.0): detention is a share of who arrives. */
    public const DETENTION_ARRIVALS_ELASTICITY = 1.0;
    /** Average time in detention (3 months). */
    public const DETENTION_STAY_YEARS = 0.25;

    // --- Concession Rates ---
    /**
     * Daily rate per unit of the District's spending index over its founding level, once every concession is re-let.
     */
    public const RATE_BUDGET_SCALAR = 0.50;
    /** Length of a concession (10 years): a tenth of them are re-let each year. */
    public const CONCESSION_TERM_YEARS = 10.0;

    // --- Revenue Volatility ---
    /** Volatility multiplier on the custody population: transfers and court backlogs. */
    public const CUSTODY_VARIANCE_SCALAR = 0.03;
    /** Volatility multiplier on the detention population: a few boats make the quarter. */
    public const DETENTION_VARIANCE_SCALAR = 0.10;
    /** Volatility multiplier on escort and tagging: a bail insurer won or lost. */
    public const ESCORT_TAGGING_VARIANCE_SCALAR = 0.08;

    // --- Disturbances ---
    /** Z-score below which a prison riots or a prisoner escapes (0.6% a quarter, about once in 40 years). */
    public const DISTURBANCE_Z_SCORE = -2.50;
    /** Charge on the quarter's revenue for fines, repairs and settlements (5%). */
    public const DISTURBANCE_CHARGE = 0.05;
    /** Escort and tagging revenue kept in the quarter a disturbance sends bail insurers to rivals (85%). */
    public const DISTURBANCE_TAGGING_REV_MULT = 0.85;

    // --- Persisted State ---
    /** The custody population as a share of contracted beds, carried between quarters. */
    private const STATE_CUSTODY_POPULATION = 'state:custody_population';
    /** The detention population as a share of contracted beds, carried between quarters. */
    private const STATE_DETENTION_POPULATION = 'state:detention_population';
    /** The daily rate the concessions in force pay, relative to the founding rate. */
    private const STATE_CONCESSION_RATE = 'state:concession_rate';

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // The beds fill on crime and arrivals in calculateSectorPhysics(), not on the shops' cycle.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /**
     * The officers the firm is staffed for: the prisoners and detainees it holds over plan, and the courts' caseload.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return 0.0;
        }

        $custody = min(self::MAXIMUM_OCCUPANCY, (float) ($momentum[self::STATE_CUSTODY_POPULATION] ?? self::PLANNED_OCCUPANCY)) / self::PLANNED_OCCUPANCY;
        $detention = min(self::MAXIMUM_OCCUPANCY, (float) ($momentum[self::STATE_DETENTION_POPULATION] ?? self::PLANNED_OCCUPANCY)) / self::PLANNED_OCCUPANCY;

        return (($weights['custody'] * ($custody - 1.0))
            + ($weights['detention'] * ($detention - 1.0))
            + ($weights['escort_tagging'] * $this->resolveCrimeShift($macroState))) / $total;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $weights = $streams->resolveActiveStreamWeights($this->resolveLineWeights($stock));

        $custodyZ = $streams->generateZ('custody', 0.70);
        $detentionZ = $streams->generateZ('detention', 0.40);
        $taggingZ = $streams->generateZ('escort_tagging', 0.40);
        $disturbanceZ = $streams->generateExogenousZ('disturbance', 0.0);
        $isDisturbance = $disturbanceZ < self::DISTURBANCE_Z_SCORE;

        // --- Populations: each moves toward its demand as fast as its stays run out ---
        $custodyPopulation = $this->stepPopulation($streams, self::STATE_CUSTODY_POPULATION, 1.0 + $this->resolveCrimeShift($macroState), self::CUSTODY_STAY_YEARS);
        $detentionPopulation = $this->stepPopulation($streams, self::STATE_DETENTION_POPULATION, 1.0 + $this->resolveArrivalsShift($macroState), self::DETENTION_STAY_YEARS);

        // --- Concession rates: the share re-let this quarter takes the rate the budget now allows ---
        $rate = $streams->getPersistedState(self::STATE_CONCESSION_RATE, 1.0);
        $budgetRate = 1.0 + (self::RATE_BUDGET_SCALAR * ($macroState->governmentSpendingIndexEma - 100.0) / 100.0);
        $streams->registerState(self::STATE_CONCESSION_RATE, TimeSeries::calculateDistributedLag($rate, $budgetRate, FinancialConstants::QUARTER_YEARS, self::CONCESSION_TERM_YEARS));

        // --- Take-or-pay beds: paid at least the guarantee, held at most full ---
        $custody = $this->bedsOf($custodyPopulation * (1.0 + ($custodyZ * $baselineVol * self::CUSTODY_VARIANCE_SCALAR)));
        $detention = $this->bedsOf($detentionPopulation * (1.0 + ($detentionZ * $baselineVol * self::DETENTION_VARIANCE_SCALAR)));
        $tagging = max(0.0, 1.0 + $this->resolveCrimeShift($macroState) + ($taggingZ * $baselineVol * self::ESCORT_TAGGING_VARIANCE_SCALAR))
            * ($isDisturbance ? self::DISTURBANCE_TAGGING_REV_MULT : 1.0);

        $streamRevenues = [
            'custody'        => $expectedRevenue * $weights['custody'] * $custody['paid'] * $rate,
            'detention'      => $expectedRevenue * $weights['detention'] * $detention['paid'] * $rate,
            'escort_tagging' => $expectedRevenue * $weights['escort_tagging'] * $tagging,
        ];
        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Officers, food and care cost the prisoners held; an empty bed paid for and the rate the concession sets cost
        // nothing more.
        $workDone = $expectedRevenue * (($weights['custody'] * $custody['held']) + ($weights['detention'] * $detention['held']) + ($weights['escort_tagging'] * $tagging));
        $costRatio = $actualRevenue > 0.0 ? $realizedVariableMargin * $workDone / $actualRevenue : $realizedVariableMargin;
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $clampedMargin = $this->clampMargin($costRatio + $inputCostDrag);
        $this->registerPublicSpendingExposure($streams, $expectedRevenue * ($weights['custody'] + $weights['detention']) * self::RATE_BUDGET_SCALAR, 0.0, $actualRevenue);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $isDisturbance ? ShockEvent::PRISON_DISTURBANCE : null,
            isPublicEvent: $isDisturbance ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            specialCharge: $isDisturbance ? self::DISTURBANCE_CHARGE * $actualRevenue : 0.0,
            kpis: [
                'prison_occupancy' => min(self::MAXIMUM_OCCUPANCY, $custodyPopulation * self::PLANNED_OCCUPANCY),
                'concession_rate_index' => $rate,
            ],
        );
    }

    /** Prisoners over plan from crime: the unemployment gap, both ways. */
    private function resolveCrimeShift(MacroStateDTO $macroState): float
    {
        return ($macroState->unemploymentRateEma - $macroState->nairuEma) * self::CRIME_UNEMPLOYMENT_ELASTICITY;
    }

    /** Detainees over plan from arrivals: the migration the jobs market draws, against the structural intake. */
    private function resolveArrivalsShift(MacroStateDTO $macroState): float
    {
        $structuralIntake = $macroState->laborForceGrowthRate - MacroConstants::NATURAL_LABOR_FORCE_GROWTH;

        return $structuralIntake > 0.0 ? self::DETENTION_ARRIVALS_ELASTICITY * $macroState->cyclicalMigrationRate / $structuralIntake : 0.0;
    }

    /**
     * Moves a population (relative to plan) one quarter toward its demand at the rate stays run out, and persists it.
     * Returns the population before the step, so a quarter holds who was there as it opened.
     */
    private function stepPopulation(StreamContext $streams, string $key, float $demand, float $stayYears): float
    {
        $population = $streams->getPersistedState($key, self::PLANNED_OCCUPANCY) / self::PLANNED_OCCUPANCY;
        $next = $population + (TimeSeries::smoothingWeight(FinancialConstants::QUARTER_YEARS, $stayYears) * (max(0.0, $demand) - $population));
        $streams->registerState($key, $next * self::PLANNED_OCCUPANCY);

        return $population;
    }

    /**
     * The beds paid for and the beds held, relative to plan, from the population that wants them.
     *
     * @return array{paid: float, held: float}
     */
    private function bedsOf(float $population): array
    {
        $occupancy = max(0.0, $population) * self::PLANNED_OCCUPANCY;
        $held = min(self::MAXIMUM_OCCUPANCY, $occupancy);

        return [
            'paid' => max(self::MINIMUM_OCCUPANCY_GUARANTEE, $held) / self::PLANNED_OCCUPANCY,
            'held' => $held / self::PLANNED_OCCUPANCY,
        ];
    }

    /**
     * @return array{custody: float, detention: float, escort_tagging: float}
     */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CustodyWeight->value       => self::CUSTODY_WEIGHT,
            ModelParam::DetentionWeight->value     => self::DETENTION_WEIGHT,
            ModelParam::EscortTaggingWeight->value => self::ESCORT_TAGGING_WEIGHT,
        ]);

        return [
            'custody'        => max(0.0, (float) $params[ModelParam::CustodyWeight]),
            'detention'      => max(0.0, (float) $params[ModelParam::DetentionWeight]),
            'escort_tagging' => max(0.0, (float) $params[ModelParam::EscortTaggingWeight]),
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
            'cyclical_migration_rate',
            'exchange_rate_index_ema',
            'government_spending_index_ema',
            'import_price_level',
            'labor_force_growth_rate',
            'nairu_ema',
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'unemployment_rate_ema',
            'real_wage_gap',
        ];
    }
}
