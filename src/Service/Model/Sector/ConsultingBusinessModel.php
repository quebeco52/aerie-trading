<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for Consulting Services (strategy advice, technology programmes, risk and restructuring work).
 *
 * Financial Physics:
 * - Booked Work: a consultancy sells its staff's time under signed engagements. Each line of work books into its own
 *   backlog and bills as the work is done (ASC 606 over-time recognition), so the cycle shows in book-to-bill before
 *   it shows in revenue.
 * - Three Engagement Lengths: strategy advice is weeks of work bought from discretionary budgets, on the cycle and
 *   deal flow; technology programmes and managed services run for years against IT budgets set a year ahead; risk,
 *   regulatory and restructuring mandates follow credit stress and corporate failures, against the cycle.
 * - The Bench: the firm staffs to the work it has in hand and its payroll is committed. When bookings fall, billable
 *   hours fall at once and headcount follows over the quarters after, so margin moves ahead of revenue.
 * - Talent Cost: consultants' pay is most of the cost base and follows the labour market; rate cards follow services
 *   inflation.
 */
class ConsultingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of bookings to the macro cycle (1.0 = one for one with each line's cycle sensitivity). */
    public const OPERATING_CYCLICALITY = 1.00;
    /**
     * Own-price elasticity of demand: volume lost per unit of real price increase. Clients tender large programmes
     * between a handful of firms, but switch strategy advisers on reputation more than rate.
     */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::CONSULTING;

    // --- Services Pricing ---
    /** Elasticity of rate cards to services (supercore) inflation: day rates track professional services prices. */
    public const PRICING_ELASTICITY = 0.85;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';
    /** Rate-card leverage: a strong brand on strategy work, offshore competition on technology delivery. */
    public const PRICING_POWER_INDEX = 0.70;

    // --- Balance Sheet Realism ---
    /**
     * Capitalized office leases as a fraction of annual revenue (6%): consultants spend most of their week at clients.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.06;

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (35%): bookings and backlog are disclosed every quarter. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;
    /** Standard deviation of the consensus forecast error (0.04): the backlog bills most of next quarter. */
    public const BASE_COVERAGE_ERROR = 0.04;

    // --- Lines of Work ---
    /** Baseline fraction of revenue from strategy and operations advice. */
    public const STRATEGY_ADVISORY_WEIGHT = 0.35;
    /** Baseline fraction of revenue from technology transformation programmes and managed services. */
    public const TECHNOLOGY_PROGRAMME_WEIGHT = 0.45;
    /** Baseline fraction of revenue from risk, regulatory and restructuring mandates. */
    public const RISK_RESTRUCTURING_WEIGHT = 0.20;

    // --- Engagement Length ---
    /** Share of the strategy book billed each quarter (60%): engagements of eight to twelve weeks. */
    public const STRATEGY_BURN_RATE = 0.60;
    /** Share of the technology book billed each quarter (15%): programmes and service contracts of several years. */
    public const TECHNOLOGY_BURN_RATE = 0.15;
    /** Share of the risk and restructuring book billed each quarter (35%): workouts and remediation of about a year. */
    public const RISK_BURN_RATE = 0.35;

    // --- Booking Volatility ---
    /** Volatility multiplier on strategy bookings: engagements are won and lost one client at a time. */
    public const STRATEGY_VARIANCE_SCALAR = 0.30;
    /** Volatility multiplier on technology bookings: a single programme can be a quarter's intake. */
    public const TECHNOLOGY_VARIANCE_SCALAR = 0.50;
    /** Volatility multiplier on risk and restructuring bookings: a few large mandates make the year. */
    public const RISK_VARIANCE_SCALAR = 0.40;

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach the technology book: IT budgets are set in an annual round and
     * programmes contracted against them. Strategy and restructuring read the economy as it is.
     */
    public const DEMAND_LAG_YEARS = 1.00;

    // --- Booking Cycle ---
    /** Strategy bookings per unit of output gap, both ways: a -3% gap cuts them 9% at beta 1. */
    public const STRATEGY_GAP_SENSITIVITY = 3.00;
    /** Strategy bookings per unit of deal flow over its trend, both ways: deals 30% short cut them 6%. */
    public const STRATEGY_DEAL_SENSITIVITY = 0.20;
    /** Technology bookings per unit of the output gap the budget round saw, both ways: a -3% gap cuts them 6%. */
    public const TECHNOLOGY_GAP_SENSITIVITY = 2.00;
    /** Risk and restructuring bookings per unit of credit spread over its trend, both ways: +1pp adds 6%. */
    public const RISK_SPREAD_SENSITIVITY = 6.00;
    /**
     * Risk and restructuring bookings per unit of the corporate default rate over its trend, relative, both ways:
     * double the trend adds 25%.
     */
    public const RISK_DEFAULT_SENSITIVITY = 0.25;

    // --- Secular Demand ---
    /**
     * Computer systems design plus the other professional, scientific and technical services, consulting among them,
     * as a share of US nominal GDP in 1997 (BEA GDP by Industry, value added: 0.9% and 3.7%).
     */
    public const SECULAR_SHARE_1997 = 0.046;
    /** The same share in 2019 (1.8% and 4.6%). */
    public const SECULAR_SHARE_2019 = 0.064;

    // --- Return Reversion ---
    /** Excess return over the required return the moat sustains (2pp): client relationships and reputation. */
    public const MOAT_SPREAD = 0.020;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.3): laptops, offices and delivery centres. */
    public const CAPEX_CYCLICALITY = 0.30;
    /** Net working capital as a share of revenue (12%): clients pay two to three months after the work is billed. */
    public const WORKING_CAPITAL_INTENSITY = 0.12;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: the spring quarter has the most billable
     * days; summer holidays and the year-end break thin the second half.
     */
    public const SEASONALITY_FACTORS = [1.00, 1.02, 0.99, 0.99];

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (3x). */
    public const MIN_ICR = 3.0;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (2x). */
    public const DIVIDEND_CRISIS_ICR = 2.0;
    /** Interest coverage below which the firm stops repurchasing stock (3x). */
    public const BUYBACK_MIN_ICR = 3.0;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // The cycle reaches each line of work through its bookings in calculateSectorPhysics().
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /**
     * The hours the firm is staffed to bill: the work in hand across its three books as the quarter opens, at target
     * weights. Payroll follows this through the committed cost base, which is where the bench costs.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $weights = $this->resolveTargetWeights($stock);
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $shift = 0.0;
        foreach (self::burnRates() as $key => $burnRate) {
            $shift += $weights[$key] * (StreamContext::openingWorkload($momentum, $key, $burnRate) - 1.0);
        }

        return $shift / $totalWeight;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $activeWeights = $streams->resolveActiveStreamWeights($this->resolveTargetWeights($stock));

        // Strategy wins persist a little; technology deals come through a pipeline the sales force works for quarters.
        $draws = [
            'strategy_advisory'      => $streams->generateZ('strategy_advisory', 0.30) * self::STRATEGY_VARIANCE_SCALAR,
            'technology_programmes'  => $streams->generateZ('technology_programmes', 0.50) * self::TECHNOLOGY_VARIANCE_SCALAR,
            'risk_and_restructuring' => $streams->generateZ('risk_and_restructuring', 0.40) * self::RISK_VARIANCE_SCALAR,
        ];
        $bookingShifts = $this->resolveBookingShifts($stock, $macroState);

        $streamRevenues = [];
        $kpis = [];
        $orders = 0.0;
        $backlog = 0.0;
        foreach (self::burnRates() as $key => $burnRate) {
            $book = $streams->recognizeBacklog(
                $key,
                $expectedRevenue * $activeWeights[$key],
                max(0.0, 1.0 + ($draws[$key] * $baselineVol) + $bookingShifts[$key]),
                $burnRate,
            );
            $streamRevenues[$key] = $book['revenue'];
            $orders += $book['orders'];
            $backlog += $book['backlog'];
            $kpis[$key . '_book_to_bill'] = $book['book_to_bill'];
            $kpis[$key . '_backlog_quarters'] = $book['backlog_quarters'];
        }

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Consultant pay above trend reaches the variable cost base and is won back on rate cards at the firm's
        // pricing power.
        $pricingPower = $this->resolvePricingPower($stock);
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $this->clampMargin($realizedVariableMargin + $inputCostDrag),
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'book_to_bill'     => $actualRevenue > 0.0 ? $orders / $actualRevenue : 1.0,
                'backlog_quarters' => $backlog / max(1.0, $expectedRevenue),
                ...$kpis,
            ],
        );
    }

    /**
     * Each line's bookings over their steady rate, every driver both ways: strategy on the output gap and deal flow;
     * technology on the gap a year back, when this year's IT budgets were set; risk and restructuring on credit
     * spreads and corporate failures over their trends. Restructuring reads distress rather than the gap, so a slowdown
     * without credit stress brings no workouts.
     *
     * @return array{strategy_advisory: float, technology_programmes: float, risk_and_restructuring: float}
     */
    private function resolveBookingShifts(Stock $stock, MacroStateDTO $macroState): array
    {
        $beta = $this->getOperatingCyclicality($stock);
        $spreadShift = $macroState->macroCreditSpreadEma - $macroState->macroCreditSpreadTrend;

        return [
            'strategy_advisory'      => ($macroState->outputGapEma * self::STRATEGY_GAP_SENSITIVITY * $beta)
                + ($macroState->dealActivityDeviation() * self::STRATEGY_DEAL_SENSITIVITY),
            'technology_programmes'  => $this->resolveLaggedOutputGap($macroState) * self::TECHNOLOGY_GAP_SENSITIVITY * $beta,
            'risk_and_restructuring' => ($spreadShift * self::RISK_SPREAD_SENSITIVITY)
                + ($macroState->corporateDefaultDeviation() * self::RISK_DEFAULT_SENSITIVITY),
        ];
    }

    /** @return array{strategy_advisory: float, technology_programmes: float, risk_and_restructuring: float} */
    private function resolveTargetWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::StrategyAdvisoryWeight->value    => self::STRATEGY_ADVISORY_WEIGHT,
            ModelParam::TechnologyProgrammeWeight->value => self::TECHNOLOGY_PROGRAMME_WEIGHT,
            ModelParam::RiskRestructuringWeight->value   => self::RISK_RESTRUCTURING_WEIGHT,
        ]);

        return [
            'strategy_advisory'      => (float) $params[ModelParam::StrategyAdvisoryWeight],
            'technology_programmes'  => (float) $params[ModelParam::TechnologyProgrammeWeight],
            'risk_and_restructuring' => (float) $params[ModelParam::RiskRestructuringWeight],
        ];
    }

    /** @return array{strategy_advisory: float, technology_programmes: float, risk_and_restructuring: float} */
    private static function burnRates(): array
    {
        return [
            'strategy_advisory'      => self::STRATEGY_BURN_RATE,
            'technology_programmes'  => self::TECHNOLOGY_BURN_RATE,
            'risk_and_restructuring' => self::RISK_BURN_RATE,
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
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'exchange_rate_index_ema',
            'import_price_level',
            'macro_credit_spread_ema',
            'output_gap_ema',
            'output_gap_lag_12m',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
