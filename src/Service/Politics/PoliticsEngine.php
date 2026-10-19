<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use Psr\Log\LoggerInterface;

/**
 * The Aerie Diet: eight parties, a vote on the fixed election calendar, seats, the talks that follow, and the
 * government.
 *
 * The vote is the economic vote as proportional-representation electorates cast it: every cabinet's share falls with
 * inflation over the term, and a party governing alone also gains with growth over the campaign, while a coalition's
 * parties are held to no account for growth (Powell & Whitten 1993's clarity of responsibility; ParlGov votes since
 * 1971, var/harness/politics/vote_europe_fit.py). The opposition takes what the government loses in proportion to its
 * own shares.
 * Governing costs the governing parties a share of the vote each term, the cost of ruling (Nannestad & Paldam 2002)
 * the Diet's parties pay on top of what they were elected on running off; governments are formed by parties riding a
 * short-term swing or a lasting lead, so the loss a government is seen to take is larger. A party supporting a
 * minority cabinet from outside bears part of that cost (Thürk & Klüver 2024). No party family gains or loses on the
 * economy beyond that: in ParlGov's votes since 1946 the families' shares move with growth, inflation, unemployment and
 * financial crises alike once the governing parties' loss is taken out (var/harness/politics/profile_fit.py). Each
 * party has a normal vote, its share at the founding (Converse 1966): its lasting support strays from it and drifts
 * back at the pace real parties' do, and each party also has a short-term swing -- candidates, campaigns, scandals --
 * which lasts only the one vote. Both are sized to the Nordic parties since 1945 (ParlGov), in proportion to a party's
 * size, as real vote shares vary. Seats are D'Hondt over the whole Diet.
 *
 * Each party is fixed on the axes it is defined by (size of state, openness, the Council, or the environment). On the
 * others it strays from its home between elections and is pulled back toward it, at the pace real parties move in the
 * Chapel Hill expert survey: an AR(1) around each party's own place, not a random walk, since parties keep their
 * family's positions for decades (Budge, Ezrow & McDonald 2010). After the vote the parties negotiate a government
 * (CoalitionFormation); until it takes office the outgoing cabinet stays on as caretaker and passes no budget. Between
 * votes a cabinet can fall, at the rate real cabinets of its kind have (ParlGov): the parties then talk again on the
 * same seats, with no election.
 *
 * The government then legislates at the budget rounds after the one it took office on. Its cabinet's position, its
 * members' weighted by seats (Gamson's law), sets its levers between the policies real parties at the ends of each axis
 * have enacted: the corporate rate, the stamp duty on share trades and the bank levy on the size-of-state axis, the
 * tariff and the immigration regime on the openness axis, the utility commission's grants on the Council axis, and on
 * the environment axis the green belt, the carbon price and the rules on extraction, none of them for a cabinet that
 * puts growth first and the strictest on record at the Tideline Accord's place. Each party also runs on a debt plank,
 * the debt its budget steers to and how fast (AerieDiet::DEBT_PLANKS), which a cabinet weighs by seats. A minority
 * cabinet proposes and its support parties can refuse: each accepts a budget it likes no less than the levers in force,
 * weighing the whole package, so a supporter can give ground on one lever for what it gains on another but is never
 * made worse off (Romer & Rosenthal 1978; Tsebelis 2002), and on the question it owns it moves no lever further from
 * its own than the one in force (Budge & Farlie 1983). The bond market, not the Council, prices what the Diet borrows.
 *
 * Politics runs beside the economy, not inside it: the ticker advances it after each macro tick on that tick's
 * snapshot, and the economy reads back only what the government hands it (PoliticsStateDTO::policy()), on the next
 * tick.
 */
class PoliticsEngine
{
    // --- Persistence ---
    /** Redis key the politics state is kept under, beside the macro state. */
    public const REDIS_POLITICS_STATE = 'politics_state';

    // --- The Diet's Election (ParlGov x World Bank, var/harness/politics/vote_europe_fit.py; Powell & Whitten 1993;
    // Nannestad & Paldam 2002) ---
    /** Length of the fixed electoral term in years: the vote falls on the tick each term ends. */
    public const ELECTION_TERM_YEARS = 4.0;
    /**
     * Final stretch of the term whose growth voters weigh (Fair's G: the first three quarters of the election year).
     */
    public const ELECTION_CAMPAIGN_WINDOW_YEARS = 0.75;
    /**
     * Vote share a party governing alone gains per unit of real growth per head over the year to the vote above its
     * trend: 0.51 (se 0.30) in 199 votes in 21 established PR democracies 1971-2020; a coalition's share does not move
     * with growth (Powell & Whitten 1993's clarity of responsibility; -0.09, se 0.40, before the euro-area
     * consolidations of 2008-2020).
     */
    public const ELECTION_SINGLE_PARTY_GROWTH_SLOPE = 0.506;
    /**
     * Vote share any cabinet loses per unit of annualised GDP-deflator inflation over the term above target: 0.10 (se
     * 0.08), same fit (Fair's US presidential slope is 0.72).
     */
    public const ELECTION_INFLATION_SLOPE = 0.100;
    /**
     * Government-wide swing in its share that the economy does not explain, common to its parties (Fair: standard error
     * 2.95 pp); each party's own swings are drawn on top of it, sized with it to the Nordic record.
     */
    public const ELECTION_RESIDUAL_SD = 0.0295;
    /**
     * Vote share the average government has lost over a term in the record (Nannestad & Paldam 2002: 282 elections in
     * 19 democracies), the reference the Diet's own cost is read against.
     */
    public const ELECTION_RECORDED_COST_OF_RULING = 0.0225;
    /**
     * Vote share governing costs the governing parties each term, beyond what they were elected on drifting back:
     * governments are formed by parties riding a short-term swing or a lasting lead, and both run off. With those, the
     * outgoing cabinet's parties lose 2.2 points a term, the record's 2.25 (var/harness/politics/vote_sim.py, 2,500
     * elections; ParlGov since 1945, measured the same way: Scandinavia 1.8, Western Europe 3.9).
     */
    public const ELECTION_COST_OF_RULING = 0.005;
    /**
     * Each party's own short-term swing, drawn at every vote and gone by the next (Converse 1966 short-term forces), as
     * the variance of its log vote share times its normal vote: real vote shares vary in proportion to their size
     * (ParlGov, West European parties since 1945: log variance on log share, slope -0.99). Nordic parties since 1945
     * (var/harness/politics/vote_fit.py): a party of a quarter swings 7% either way, one of a twentieth 15%.
     */
    public const ELECTION_SHORT_TERM_SWING_VARIANCE = 0.0012;
    /**
     * Share of a party's lasting lead or deficit on its normal vote still there a year later: real parties drift back
     * toward their usual share, a half-life of seven years (ParlGov, Nordic parties since 1945,
     * var/harness/politics/vote_fit.py; West European parties 0.911 over elections up to 20 years apart).
     */
    public const ELECTION_NORMAL_VOTE_PERSISTENCE = 0.905;
    /**
     * Share of a cabinet party's electoral cost of governing a support party bears: 1.99 points lost against 2.81 for
     * cabinet parties (Thürk & Klüver 2024, Table 1 Model 1: support -1.991, prime minister's party -2.815, junior
     * partner -2.799; 304 elections in 31 democracies since 1980).
     */
    public const ELECTION_SUPPORT_ACCOUNTABILITY = 1.991 / ((2.815 + 2.799) / 2.0);

    // --- Cabinets Falling Between Votes (ParlGov, West European cabinets since 1945,
    // var/harness/politics/termination_fit.py) ---
    /**
     * Yearly hazard a single-party minority cabinet falls between votes, replaced by another cabinet without an
     * election: 21 falls in 199 cabinet-years (a constant hazard, King, Alt, Burns & Laver 1990).
     */
    public const CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY = 0.106;
    /**
     * Yearly hazard a minority coalition falls between votes: 27 falls in 125 cabinet-years, twice a single party's, as
     * a partner can walk out as well as a supporter.
     */
    public const CABINET_FALL_HAZARD_MINORITY_COALITION = 0.216;
    /**
     * Yearly hazard a majority coalition falls between votes: 91 falls in 763 cabinet-years. A party governing alone
     * with its own majority has no partner or supporter to lose, and none fell in 242 cabinet-years.
     */
    public const CABINET_FALL_HAZARD_MAJORITY_COALITION = 0.119;

    // --- Election Uncertainty (Julio & Yook 2012; Bernhard & Leblang 2006) ---
    /**
     * Days per term the talks after a cabinet falls hold the election pulse above where the term's ramp would put it:
     * falls in 41% of terms, 40 days of talks each (var/harness/politics/formation_report.py).
     */
    public const FALL_TALK_DAYS_PER_TERM = 19.5;
    /**
     * Long-run mean of nearness to the vote: the final-year ramp's half year a term, plus the talks at the peak after
     * the vote and after any fall.
     */
    public const MEAN_ELECTION_PROXIMITY = (0.5 + ((CoalitionFormation::FORMATION_MEAN_DAYS + self::FALL_TALK_DAYS_PER_TERM) / FinancialConstants::DAYS_PER_YEAR)) / self::ELECTION_TERM_YEARS;

    // --- Party Positions (Chapel Hill expert survey 1999-2024, var/harness/politics/ches_fit.py) ---
    /**
     * Share of a party's distance from its home still there a year later, by axis: economic left-right for size of
     * state, European integration for openness, anti-elite rhetoric for the Council, environment against growth for the
     * environment (2010-2024); Western parties against their country's mean.
     */
    public const POSITION_ANNUAL_PERSISTENCE = [AerieDiet::AXIS_STATE => 0.872, AerieDiet::AXIS_OPENNESS => 0.958, AerieDiet::AXIS_COUNCIL => 0.846, AerieDiet::AXIS_ENVIRONMENT => 0.933];
    /**
     * How far a party strays from its home on each axis, the standard deviation around it, from the same fit (a full
     * expert scale is two units).
     */
    public const POSITION_WITHIN_SD = [AerieDiet::AXIS_STATE => 0.125, AerieDiet::AXIS_OPENNESS => 0.265, AerieDiet::AXIS_COUNCIL => 0.188, AerieDiet::AXIS_ENVIRONMENT => 0.170];

    // --- Vote Shares ---
    /** Smallest vote share a party is carried at, so a collapse leaves it a rump rather than a negative share. */
    public const MIN_VOTE_SHARE = 0.005;
    /**
     * Each party's own lasting swing each term, as the variance of its log vote share times its normal vote: with the
     * government's swings, it spreads the parties around their normal votes as far as the Nordic parties since 1945,
     * 0.019 (ParlGov, var/harness/politics/vote_fit.py against vote_sim.py), less what the normal vote's drift now adds
     * over a term, so the vote moves as far from one election to the next as before.
     */
    public const LASTING_SWING_VARIANCE = 0.0118 - (self::ELECTION_TERM_YEARS * self::NORMAL_VOTE_DRIFT_VARIANCE / 2.0
        * (1.0 - (self::ELECTION_NORMAL_VOTE_PERSISTENCE ** (2.0 * self::ELECTION_TERM_YEARS))) / (1.0 - (self::ELECTION_NORMAL_VOTE_PERSISTENCE ** self::ELECTION_TERM_YEARS)));
    /**
     * Yearly variance of a party's log normal vote times its normal vote: Western European parties' shares carry a
     * random walk beyond their lasting swing, half of it 0.341e-3 a year (ParlGov 1945-2020, parties averaging 5% or
     * more, the Diet's short and lasting swing held; bootstrap 95% 0.09e-3 to 0.65e-3,
     * var/harness/politics/walk_fit.py), so realignments build over decades as they do in the West rather than
     * levelling off as the Nordic votes do.
     */
    public const NORMAL_VOTE_DRIFT_VARIANCE = 2.0 * 0.000341;

    // --- The Budget ---
    /** The state field each lever is kept in, in the same order. */
    public const LEVER_FIELDS = [
        'publicSpending' => 'publicSpendingShare',
        'corporateTax' => 'corporateTaxPolicyShift',
        'tariff' => 'importTariffRate',
        'laborGrowth' => 'laborForceGrowthRate',
        'foreignBuyerTax' => 'foreignBuyerTaxRate',
        'greenBeltStringency' => 'greenBeltStringency',
        'carbonPrice' => 'carbonPrice',
        'extractionStringency' => 'extractionStringency',
        'stampDutyRate' => 'stampDutyRate',
        'bankLevyRate' => 'bankLevyRate',
        'reserveDrawShare' => 'reserveDrawShare',
        'consolidationTaxShare' => 'consolidationTaxShare',
        'reserveHomeShare' => 'reserveHomeEquityShare',
        'utilityRateGrant' => 'utilityRateCaseGrantShare',
        'dividendTax' => 'dividendTaxRate',
        'debtTarget' => 'debtTarget',
        'debtPace' => 'debtPace',
    ];
    /** The question each lever's platform reads, in the same order. */
    public const LEVER_AXES = [
        'publicSpending' => AerieDiet::AXIS_STATE,
        'corporateTax' => AerieDiet::AXIS_STATE,
        'tariff' => AerieDiet::AXIS_OPENNESS,
        'laborGrowth' => AerieDiet::AXIS_OPENNESS,
        'foreignBuyerTax' => AerieDiet::AXIS_OPENNESS,
        'greenBeltStringency' => AerieDiet::AXIS_ENVIRONMENT,
        'carbonPrice' => AerieDiet::AXIS_ENVIRONMENT,
        'extractionStringency' => AerieDiet::AXIS_ENVIRONMENT,
        'stampDutyRate' => AerieDiet::AXIS_STATE,
        'bankLevyRate' => AerieDiet::AXIS_STATE,
        'reserveDrawShare' => AerieDiet::AXIS_STATE,
        'consolidationTaxShare' => AerieDiet::AXIS_STATE,
        'reserveHomeShare' => AerieDiet::AXIS_OPENNESS,
        'utilityRateGrant' => AerieDiet::AXIS_COUNCIL,
        'dividendTax' => AerieDiet::AXIS_STATE,
        'debtTarget' => AerieDiet::AXIS_STATE,
        'debtPace' => AerieDiet::AXIS_STATE,
    ];

    // --- Platforms: Public Spending (Singapore, Workers' Party and PAP, 2020-2025) ---
    /**
     * Log gap between the public spending the big-state and small-state ends plan: the 0.7% of GDP Singapore's
     * opposition would raise from the reserves' returns and land sales in place of the 2023-24 GST rise (Workers' Party
     * manifesto 2025; Ministry of Finance, 21 November 2022), over the founding purchases share, small enough that the
     * share and its log coincide. The opposition costs no extra spending with it, so this is the most the two sides'
     * budgets part by.
     */
    public const POLICY_MANIFESTO_PUBLIC_SPENDING_LOG_GAP = 0.007 / MacroConstants::PUBLIC_PURCHASES_TO_GDP;

    // --- Platforms: Corporate Tax (financial centres: Singapore, Luxembourg) ---
    /**
     * Gap between the corporate rates the big-state and small-state ends set: 2 points, the financial centres' larger
     * single cuts, Singapore's 20% to 18% (Budget 2008) and Luxembourg's 21% to 19% (2017 tax reform); their parties
     * more often part by a point or keep the same rate (Luxembourg 2025, 17% to 16%; Workers' Party manifesto 2025).
     * Enacted rates follow manifesto ideology (Osterloh & Debus 2012).
     */
    public const POLICY_MANIFESTO_CORPORATE_TAX_GAP = 0.02;

    // --- Platforms: Tariffs (Amiti, Redding & Weinstein 2019; Fajgelbaum et al. 2020) ---
    /**
     * Average effective tariff the protectionist end of the openness axis enacts over the District's free port: the
     * US's 2025 rise, 2.5% to 17.9% (Yale Budget Lab, State of U.S. Tariffs, 26 September 2025). Duties pass fully into
     * import prices at the border (Amiti, Redding & Weinstein 2019; Fajgelbaum et al. 2020).
     */
    public const POLICY_PROTECTIONIST_TARIFF = 0.154;

    // --- Platforms: Immigration (UN WPP via World Bank, 2000-2019) ---
    /**
     * Net migration a closed immigration regime admits, a year as a share of population: Japan's 0.11% (World Bank
     * SM.POP.NETM over SP.POP.TOTL, 2000-2019 mean).
     */
    public const MIGRATION_CLOSED_REGIME = 0.0011;
    /**
     * Net migration an open immigration regime admits: Canada's 0.73% and Australia's 0.86%, averaged (the same
     * series). Between the two regimes sits the US's 0.50%, the rate the District's average party keeps
     * (MacroConstants::STRUCTURAL_NET_MIGRATION_RATE).
     */
    public const MIGRATION_OPEN_REGIME = (0.00728 + 0.00859) / 2.0;

    // --- Platforms: Foreign Buyers of Homes (Singapore Additional Buyer's Stamp Duty) ---
    /**
     * Tax on a home bought by a buyer from outside the District the closed end of the openness axis enacts, a share of
     * the price: Singapore's 60% Additional Buyer's Stamp Duty on foreigners (since 27 April 2023), the strictest on
     * record. The open end taxes none, as the District did at its founding.
     */
    public const POLICY_PROTECTIONIST_FOREIGN_BUYER_TAX = 0.60;

    // --- Platforms: Stamp Duty (Hong Kong, 2021-2023; Colliard & Hoffmann 2017) ---
    /**
     * Duty on each side of a share trade the big-state end of the axis enacts: Hong Kong's 0.13% a side from 1 August
     * 2021, cut back to 0.1% from 17 November 2023 (Inland Revenue Department). The small-state end keeps the founding
     * 0.05% a side (FinancialConstants::STAMP_DUTY_RATE); either way it is paid into the reserve fund, not the budget.
     */
    public const POLICY_BIG_STATE_STAMP_DUTY = 0.0013;

    // --- Platforms: Bank Levy (Ireland, 2024-2025) ---
    /**
     * Bank levy the big-state end of the axis enacts, a year: Ireland's 0.122% of the banks' eligible deposits (Revenue
     * eBrief 272/23), the only bank levy among the financial centres the District is set against, charged here on the
     * base the levy has always had (short-term funding, half on long-term funding and uninsured deposits). The
     * small-state end levies none, as Singapore, Hong Kong, Luxembourg and Switzerland do not.
     */
    public const POLICY_BIG_STATE_BANK_LEVY = 0.00122;

    // --- Platforms: The Reserve Draw (Singapore Net Investment Returns Contribution) ---
    /**
     * Share of the reserve fund's expected long-term real return the big-state end of the axis spends: 60%, the
     * Workers' Party of Singapore's proposal to raise the Net Investment Returns Contribution from 50% (manifestos 2020
     * and 2025). The small-state end keeps the founding half (MacroConstants::RESERVE_DRAW_CEILING), which the fund
     * must consent to any budget exceeding.
     */
    public const POLICY_BIG_STATE_RESERVE_DRAW_SHARE = 0.60;

    // --- Platforms: Who Pays to Cut the Debt (IFS 2010 General Election briefing) ---
    /**
     * Gap between the shares of a deficit cut the big-state and small-state manifestos raise by tax: Labour's 2:1 ratio
     * of spending cuts to tax rises (£24bn of £71bn) against the Conservatives' 4:1 (£14bn of £71bn), IFS, April 2010.
     * Enacted plans show no partisan gap (deficit-cutting plans against ParlGov cabinets, 15 countries, 0.002 ± 0.018 a
     * left-right point), so the gap is the manifestos'.
     */
    public const POLICY_MANIFESTO_CONSOLIDATION_TAX_SHARE_GAP = (24.0 - 14.0) / 71.0;

    // --- Platforms: The Tax on Dividends (Singapore's one-tier system) ---
    /**
     * Tax on dividends the big-state end of the axis enacts: it keeps the founding 15%
     * (Valuation::FOUNDING_DIVIDEND_TAX_RATE), as no financial centre's left proposes a dividend tax (Singapore's
     * Workers' Party taxes wealth instead, manifesto 2025).
     */
    public const POLICY_BIG_STATE_DIVIDEND_TAX = Valuation::FOUNDING_DIVIDEND_TAX_RATE;
    /**
     * Tax on dividends the small-state end enacts: none, as Singapore's one-tier system taxes a company's profits once
     * and its dividends not at all (IRAS).
     */
    public const POLICY_SMALL_STATE_DIVIDEND_TAX = 0.0;

    // --- Platforms: The Reserve Fund's Home Share (GPIF 2014; Norway's GPFG) ---
    /**
     * Share of the reserve fund's equities the closed end of the openness axis keeps at home: GPIF's half, 25% domestic
     * equities in a 50% equity mix, after the October 2014 reallocation doubled its home weight from 12%. The open end
     * keeps none, as Norway's fund invests only abroad "so that the Norwegian economy does not overheat" (NBIM).
     */
    public const POLICY_PROTECTIONIST_RESERVE_HOME_SHARE = 0.25 / 0.50;

    // --- Platforms: The Environment (World Bank Carbon Pricing Dashboard) ---
    /**
     * Carbon price on power and industry the environment-first end of the axis enacts, in dollars a tonne of CO2: the
     * EU Emissions Trading System's, $70.37 on 1 April 2025 (World Bank Carbon Pricing Dashboard). Sweden's $125.56 tax
     * covers heating and transport fuels, which the District's economy does not price, so it is not the reference. A
     * cabinet that puts growth first prices none, as the US federal government does not.
     */
    public const POLICY_GREEN_CARBON_PRICE = 70.37;

    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Advances the politics by one tick on the economy's snapshot of the same tick, and keeps the result.
     *
     * @param MacroStateDTO $macro The economy as this tick left it.
     * @param float         $dt    Time increment in years.
     */
    public function updatePolitics(MacroStateDTO $macro, float $dt): PoliticsStateDTO
    {
        $state = $this->loadState();
        $this->advance($state, $macro, $dt);
        $this->saveState($state);

        return PoliticsStateDTO::fromState($state);
    }

    /** The politics as last kept, or the founding Diet when nothing has been. */
    public function liveState(): PoliticsStateDTO
    {
        return PoliticsStateDTO::fromState($this->loadState());
    }

    private function loadState(): PoliticsState
    {
        $raw = $this->redis->get(self::REDIS_POLITICS_STATE);
        if (!is_string($raw) || trim($raw) === '') {
            return new PoliticsState();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger?->error('Failed to decode the politics state from Redis: ' . $e->getMessage());

            return new PoliticsState();
        }

        return is_array($decoded) ? PoliticsState::fromArray($decoded) : new PoliticsState();
    }

    private function saveState(PoliticsState $state): void
    {
        try {
            $this->redis->set(self::REDIS_POLITICS_STATE, json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to persist the politics state to Redis: ' . $e->getMessage());
        }
    }

    /**
     * Keeps the campaign and term marks, holds the vote on the tick a term ends, seats the government the talks produce
     * when its day comes, passes the budget at each round a government sits through, and names the tick's headline.
     *
     * @param PoliticsState $state The politics, advanced in place.
     * @param MacroStateDTO $macro The economy as this tick left it: the vote reads its real GDP and deflator.
     * @param float         $dt    Time increment in years.
     */
    public function advance(PoliticsState $state, MacroStateDTO $macro, float $dt): void
    {
        $state->totalTime = $macro->totalTime;
        $realGdp = self::realGdp($macro);

        // A state that predates the marks opens them where it stands and measures from there.
        if ($state->termStartedAt < 0.0) {
            $state->termStartedAt = $state->totalTime;
            $state->termStartDeflator = $macro->consumerPriceLevel;
        }
        if (
            $state->campaignStartedAt < 0.0
            || TimeSeries::crossedSimulatedBoundary($state->totalTime + self::ELECTION_CAMPAIGN_WINDOW_YEARS, $dt, self::ELECTION_TERM_YEARS)
        ) {
            $state->campaignStartedAt = $state->totalTime;
            $state->campaignStartRealGdp = $realGdp;
        }

        // Snapped to the tick grid, so the vote falls on the same tick as every other boundary of the calendar (a
        // budget round) rather than one tick late when accumulated time lands a hair short of the term.
        if (TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, self::ELECTION_TERM_YEARS)) {
            $state->lastElectionAt = $state->totalTime;
            $this->holdElection($state, $macro, $realGdp);

            $state->termStartedAt = $state->totalTime;
            $state->termStartDeflator = $macro->consumerPriceLevel;
        }

        // Support is brought up to date before any change of cabinet, so the month's swing lands on those who governed
        // it.
        OpinionPolls::advance($state, $macro, $dt, $this->mathUtility);

        if ($state->cabinetFallsAt >= 0.0 && $state->totalTime >= $state->cabinetFallsAt) {
            $this->fall($state);
        }

        if ($state->coalitionTakesOfficeAt >= 0.0 && $state->totalTime >= $state->coalitionTakesOfficeAt) {
            self::takeOffice($state);
        }

        // A sitting cabinet without a fall date draws one: on taking office, or on the first tick of a state that has
        // none.
        if ($state->cabinetFallsAt < 0.0 && $state->coalitionTakesOfficeAt < 0.0) {
            $hazard = self::fallHazard(AerieDiet::governingParties($state->governingCoalition), $state->dietSeats);
            if ($hazard > 0.0) {
                $state->cabinetFallsAt = $state->totalTime + ($this->mathUtility->generateExponential() / $hazard);
            }
        }

        // A government's first budget is the round after the one it took office on; a caretaker passes none.
        if (
            $state->coalitionTakesOfficeAt < 0.0
            && $state->coalitionFormedAt < $state->totalTime
            && TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, MacroConstants::BUDGET_ROUND_PERIOD_YEARS)
        ) {
            self::enactBudget($state, MacroEngine::recessionDeclared($macro));
        }

        CouncilAppointments::advance($state, $this->mathUtility);
        MonetaryAuthority::advance($state, $macro, $dt, $this->mathUtility);
        FinancialRegulator::advance($state, $this->mathUtility);
        SovereignReserveFund::advance($state, $this->mathUtility);
        Exchequer::advance($state, $this->mathUtility);
        FairTradingBoard::advance($state, $this->mathUtility);
        SecuritiesCommission::advance($state, $this->mathUtility);
        SecurityBureau::advance($state, $this->mathUtility);
        CoastGuard::advance($state, $this->mathUtility);
        StateGuard::advance($state, $this->mathUtility);
        PartyLeaders::advance($state, $dt, $this->mathUtility);
        ElectionForecast::advance($state, MacroEngine::recessionDeclared($macro));

        $state->eventType = self::headline($state);
    }

    /**
     * The tick's political headline, the vote above all: a cabinet falling, then one taking office, then a budget round
     * that changed a lever, then the Council naming a governor, then the Financial Regulator's head, then the Sovereign
     * Reserve Fund's head, then the Exchequer's head, then the Fair Trading Board's head, then the Securities
     * Commission's head, then the Security Bureau's director, then the Coast Guard's commandant, then the State Guard's
     * commander, then an appointment tipping the rate committee into or out of a supermajority, then the Council
     * electing a councillor, then a rate meeting that moved the rate a full step or split its committee. A single-tick
     * pulse, gone on the next tick.
     */
    public static function headline(PoliticsState $state): ?string
    {
        return match (true) {
            $state->lastElectionAt === $state->totalTime => ShockEvent::ELECTION_HELD,
            $state->lastCabinetFellAt === $state->totalTime => ShockEvent::GOVERNMENT_FELL,
            $state->lastGovernmentFormedAt === $state->totalTime => ShockEvent::GOVERNMENT_FORMED,
            $state->lastLeaderChangeAt === $state->totalTime && $state->lastLeaderChangeParty === PartyLeaders::primeMinisterParty($state) => ShockEvent::PRIME_MINISTER_CHANGED,
            $state->lastBudgetEnactedAt === $state->totalTime => ShockEvent::BUDGET_ENACTED,
            $state->lastGovernorAppointedAt === $state->totalTime => ShockEvent::GOVERNOR_APPOINTED,
            $state->lastRegulatorAppointedAt === $state->totalTime => ShockEvent::REGULATOR_APPOINTED,
            $state->lastFundHeadAppointedAt === $state->totalTime => ShockEvent::FUND_HEAD_APPOINTED,
            $state->lastExchequerHeadAppointedAt === $state->totalTime => ShockEvent::EXCHEQUER_HEAD_APPOINTED,
            $state->lastFairTradingHeadAppointedAt === $state->totalTime => ShockEvent::FAIR_TRADING_HEAD_APPOINTED,
            $state->lastSecuritiesHeadAppointedAt === $state->totalTime => ShockEvent::SECURITIES_HEAD_APPOINTED,
            $state->lastSecurityDirectorAppointedAt === $state->totalTime => ShockEvent::SECURITY_DIRECTOR_APPOINTED,
            $state->lastCoastGuardCommandantAppointedAt === $state->totalTime => ShockEvent::COAST_GUARD_COMMANDANT_APPOINTED,
            $state->lastStateGuardCommanderAppointedAt === $state->totalTime => ShockEvent::STATE_GUARD_COMMANDER_APPOINTED,
            $state->lastMajorityShiftAt === $state->totalTime => ShockEvent::AUTHORITY_MAJORITY_SHIFT,
            $state->lastCouncillorSeatedAt === $state->totalTime => ShockEvent::COUNCILLOR_SEATED,
            $state->lastLeaderChangeAt === $state->totalTime => ShockEvent::PARTY_LEADER_CHANGED,
            MonetaryAuthority::meetingMadeNews($state) => ShockEvent::MONETARY_DECISION,
            default => null,
        };
    }

    /**
     * The election calendar's pull on policy uncertainty, centred on its long-run mean: nearness to the vote, rising
     * over the final year of the term (Julio & Yook 2012), held at its peak on the day of the vote and through the
     * talks after it or after a fall, since only a seated government settles the regime (Bernhard & Leblang 2006).
     *
     * @param float $totalTime              Simulation time.
     * @param float $lastElectionAt         When the last vote was held.
     * @param float $coalitionTakesOfficeAt When the cabinet the talks produced takes office (-1: no talks pending).
     */
    public static function electionPulse(float $totalTime, float $lastElectionAt, float $coalitionTakesOfficeAt): float
    {
        $yearsToElection = self::ELECTION_TERM_YEARS - fmod($totalTime, self::ELECTION_TERM_YEARS);
        $proximity = ($lastElectionAt === $totalTime || $coalitionTakesOfficeAt > $totalTime) ? 1.0 : max(0.0, 1.0 - $yearsToElection);

        return $proximity - self::MEAN_ELECTION_PROXIMITY;
    }

    /**
     * The levers a government at this position legislates, each between the policies real parties at the two ends of
     * its axis have enacted. A government leaning further than the party at the end of an axis enacts that party's
     * policy and no more: nothing on record goes further.
     *
     * The environment levers are none at all for a cabinet at or past the axis's middle toward growth, and rise to the
     * strictest on record at the Tideline Accord's place, as the tariff rises from an open cabinet's none to the
     * protectionists'.
     *
     * @param array<string, float> $position A position by axis (coalitionPosition(), or a party's).
     * @return array<string, float>
     *         Government purchases as a share of GDP, the corporate rate's shift from the neutral rate, the average tariff on imports, labour force growth,
     *         the tax on homes bought from outside the District,
     *         how far the green belt stands between the founding planning regime (0) and the strictest (1), the carbon price in dollars a
     *         tonne, how far the rules on extraction stand between the founding ones (0) and the strictest (1), the
     *         stamp duty on each side of a share trade, the bank levy on short-term funding, and the share of the
     *         reserve fund's expected return the budget spends.
     */
    public static function platform(array $position): array
    {
        // Each axis runs between the parties defined at its two ends, which stand for the real policies.
        $smallState = AerieDiet::FIXED_POSITIONS[AerieDiet::VANGUARD][AerieDiet::AXIS_STATE];
        $bigState = AerieDiet::FIXED_POSITIONS[AerieDiet::CIVIC][AerieDiet::AXIS_STATE];
        $closed = AerieDiet::FIXED_POSITIONS[AerieDiet::IRON_HARBOR][AerieDiet::AXIS_OPENNESS];
        $open = AerieDiet::FIXED_POSITIONS[AerieDiet::EXCHANGE][AerieDiet::AXIS_OPENNESS];
        $populist = AerieDiet::FIXED_POSITIONS[AerieDiet::COMMON_LOT][AerieDiet::AXIS_COUNCIL];
        $technocratic = AerieDiet::FIXED_POSITIONS[AerieDiet::CHARTISTS][AerieDiet::AXIS_COUNCIL];
        $green = AerieDiet::FIXED_POSITIONS[AerieDiet::TIDELINE][AerieDiet::AXIS_ENVIRONMENT];
        $stringency = max(0.0, min($green, $position[AerieDiet::AXIS_ENVIRONMENT] ?? 0.0)) / $green;
        $state = max($smallState, min($bigState, $position[AerieDiet::AXIS_STATE] ?? 0.0));
        $openness = max($closed, min($open, $position[AerieDiet::AXIS_OPENNESS] ?? 0.0));
        $council = max($populist, min($technocratic, $position[AerieDiet::AXIS_COUNCIL] ?? 0.0));
        $stateSpan = $bigState - $smallState;
        // How far toward its own end of the size-of-state axis the position leans, -1 at the small-state end to +1 at
        // the big-state end, each side scaled to its own end: a law the manifestos set either side of the District's
        // average party moves half their gap each way, however far from the average each end party stands.
        $stateLean = $state >= 0.0 ? $state / $bigState : -$state / $smallState;
        $opennessSpan = $open - $closed;

        return [
            // Purchases move in proportion, the neutral position keeping the founding share
            // (MacroConstants::PUBLIC_PURCHASES_TO_GDP).
            'publicSpending' => MacroConstants::PUBLIC_PURCHASES_TO_GDP * exp(self::POLICY_MANIFESTO_PUBLIC_SPENDING_LOG_GAP / 2.0 * $stateLean),
            'corporateTax' => self::POLICY_MANIFESTO_CORPORATE_TAX_GAP / 2.0 * $stateLean,
            'tariff' => self::POLICY_PROTECTIONIST_TARIFF * max(0.0, $openness / $closed),
            // The resident population grows the labour force whatever the law; the average party admits the structural
            // newcomers on top, and each end its own regime.
            'laborGrowth' => MacroConstants::NATURAL_LABOR_FORCE_GROWTH + MacroConstants::STRUCTURAL_NET_MIGRATION_RATE
                + ((($openness >= 0.0 ? self::MIGRATION_OPEN_REGIME : self::MIGRATION_CLOSED_REGIME) - MacroConstants::STRUCTURAL_NET_MIGRATION_RATE) * ($openness >= 0.0 ? $openness / $open : $openness / $closed)),
            'foreignBuyerTax' => self::POLICY_PROTECTIONIST_FOREIGN_BUYER_TAX * max(0.0, $openness / $closed),
            'greenBeltStringency' => $stringency,
            'carbonPrice' => self::POLICY_GREEN_CARBON_PRICE * $stringency,
            'extractionStringency' => $stringency,
            'stampDutyRate' => FinancialConstants::STAMP_DUTY_RATE
                + ((self::POLICY_BIG_STATE_STAMP_DUTY - FinancialConstants::STAMP_DUTY_RATE) * ($state - $smallState) / $stateSpan),
            'bankLevyRate' => self::POLICY_BIG_STATE_BANK_LEVY * ($state - $smallState) / $stateSpan,
            'reserveDrawShare' => MacroConstants::RESERVE_DRAW_CEILING
                + ((self::POLICY_BIG_STATE_RESERVE_DRAW_SHARE - MacroConstants::RESERVE_DRAW_CEILING) * ($state - $smallState) / $stateSpan),
            // The neutral position keeps the average plan's mix
            // (CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE).
            'consolidationTaxShare' => CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE + (self::POLICY_MANIFESTO_CONSOLIDATION_TAX_SHARE_GAP / 2.0 * $stateLean),
            'reserveHomeShare' => self::POLICY_PROTECTIONIST_RESERVE_HOME_SHARE * ($open - $openness) / $opennessSpan,
            // The technocratic half keeps the appointed commission; toward the populist end it grants as an elected
            // one.
            'utilityRateGrant' => FirmEconomics::APPOINTED_COMMISSION_GRANTED_SHARE
                * (1.0 - ((1.0 - FirmEconomics::ELECTED_COMMISSION_GRANT_RATIO) * max(0.0, $council / $populist))),
            // The neutral position keeps the founding rate; each end enacts its own side's plan.
            'dividendTax' => Valuation::FOUNDING_DIVIDEND_TAX_RATE
                + ((self::POLICY_BIG_STATE_DIVIDEND_TAX - Valuation::FOUNDING_DIVIDEND_TAX_RATE) * max(0.0, $state / $bigState))
                + ((self::POLICY_SMALL_STATE_DIVIDEND_TAX - Valuation::FOUNDING_DIVIDEND_TAX_RATE) * max(0.0, $state / $smallState)),
        ];
    }

    /**
     * The levers in force, keyed and ordered as platform() writes them, so a budget that changes nothing compares
     * equal.
     *
     * @return array<string, float>
     */
    public static function standingLevers(PoliticsState|PoliticsStateDTO $state): array
    {
        $standing = [];
        foreach (self::LEVER_FIELDS as $lever => $field) {
            $standing[$lever] = $state->$field;
        }

        return $standing;
    }

    /**
     * A set of parties' debt plank, their planks weighted by seats as their positions are (Gamson's law): the gross
     * debt the budget steers to and the points of GDP a year it moves debt there (AerieDiet::DEBT_PLANKS).
     *
     * @param list<string>             $parties The parties.
     * @param array<string, int|float> $seats   Seats by party.
     * @return array{debtTarget: float, debtPace: float}
     */
    public static function debtPlanks(array $parties, array $seats): array
    {
        $total = 0.0;
        $target = 0.0;
        $pace = 0.0;
        foreach ($parties as $party) {
            $weight = max(0.0, (float) ($seats[$party] ?? 0.0));
            $total += $weight;
            $target += $weight * AerieDiet::DEBT_PLANKS[$party]['target'];
            $pace += $weight * AerieDiet::DEBT_PLANKS[$party]['pace'];
        }

        return $total > 0.0
            ? ['debtTarget' => $target / $total, 'debtPace' => $pace / $total]
            : ['debtTarget' => CreditFiscalSubsystem::FOUNDING_DEBT_TARGET, 'debtPace' => CreditFiscalSubsystem::FOUNDING_DEBT_PACE];
    }

    /**
     * A party's whole programme: the levers its position legislates (platform()) and its own debt plank.
     *
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array<string, float>
     */
    public static function partyProgramme(string $party, array $positions): array
    {
        return self::platform(AerieDiet::position($party, $positions)) + self::debtPlanks([$party], [$party => 1.0]);
    }

    /**
     * What a budget round would enact: the cabinet's programme, as far as its support parties let it.
     *
     * The cabinet proposes and each support party accepts a budget it likes no less than the levers in force, weighing
     * the whole package, but moves no lever on the question it owns further from its own than the one in force
     * (BudgetBargain): the cabinet enacts the budget closest to its programme that all its supporters accept, which the
     * levers in force always are. The reserve fund holds the second key on its draw: a
     * budget spends more than half the fund's expected return only with its consent, which it gives while the
     * statistics office has a recession declared (MacroEngine::recessionDeclared()), as Singapore's President consented
     * to drawing on the reserves in 2009 and 2020 and in no other year. Out of a recession the share above the ceiling
     * lapses.
     *
     * @param list<string>                        $cabinet   The cabinet's parties.
     * @param list<string>                        $support   Its support parties.
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param array<string, float>                $standing  The levers in force (standingLevers()).
     * @param bool                                $recession Whether a recession is declared, in which the fund
     *                                                       consents.
     * @return array{levers: array<string, float>, platform: array<string, float>, supportHeld: array<string, bool>, fundHeld: bool}
     *         What the round enacts, the cabinet's own programme, which levers the supporters hold short of it, and
     *         whether the fund held the draw at its ceiling.
     */
    public static function budget(array $cabinet, array $support, array $seats, array $positions, array $standing, bool $recession = false): array
    {
        $platform = self::platform(self::coalitionPosition(AerieDiet::membership($cabinet), $seats, $positions)) + self::debtPlanks($cabinet, $seats);
        $supporters = array_map(static fn(string $party): array => [
            'programme' => self::partyProgramme($party, $positions),
            'redLines' => BudgetBargain::redLines($party),
        ], $support);

        $proposal = [];
        foreach (array_keys(self::LEVER_FIELDS) as $lever) {
            $proposal[$lever] = $platform[$lever];
        }
        // Out of a recession every party knows the fund will not consent above the ceiling, so the bargain is struck
        // within it.
        $caps = $recession ? [] : ['reserveDrawShare' => MacroConstants::RESERVE_DRAW_CEILING];
        $levers = BudgetBargain::winSetPoint($proposal, $standing, $supporters, $caps);
        $fundHeld = !$recession && $platform['reserveDrawShare'] > MacroConstants::RESERVE_DRAW_CEILING
            && $levers['reserveDrawShare'] >= MacroConstants::RESERVE_DRAW_CEILING * (1.0 - 1e-12);
        if ($fundHeld) {
            $levers['reserveDrawShare'] = MacroConstants::RESERVE_DRAW_CEILING;
        }
        $supportHeld = [];
        foreach ($levers as $lever => $value) {
            $supportHeld[$lever] = $value !== $platform[$lever] && !($lever === 'reserveDrawShare' && $fundHeld);
        }

        return ['levers' => $levers, 'platform' => $platform, 'supportHeld' => $supportHeld, 'fundHeld' => $fundHeld];
    }

    /**
     * A budget round: budget() becomes law, and the economy reads the levers from the next tick.
     *
     * @param PoliticsState $state     The politics, advanced in place.
     * @param bool          $recession Whether a recession is declared, in which the fund consents to a draw above its
     *                                 ceiling.
     */
    public static function enactBudget(PoliticsState $state, bool $recession = false): void
    {
        $standing = self::standingLevers($state);
        $levers = self::budget(
            AerieDiet::governingParties($state->governingCoalition),
            AerieDiet::governingParties($state->supportParties),
            $state->dietSeats,
            $state->partyPositions,
            $standing,
            $recession,
        )['levers'];

        if ($levers !== $standing) {
            $state->lastBudgetEnactedAt = $state->totalTime;
        }
        foreach (self::LEVER_FIELDS as $lever => $field) {
            $state->$field = $levers[$lever];
        }
    }

    /**
     * The government the talks produced takes office; the caretaker steps down.
     */
    public static function takeOffice(PoliticsState $state): void
    {
        $state->governingCoalition = $state->pendingCoalition;
        $state->supportParties = $state->pendingSupport;
        $state->coalitionFormedAt = $state->totalTime;
        $state->lastGovernmentFormedAt = $state->totalTime;
        $state->coalitionTakesOfficeAt = -1.0;
    }

    /**
     * Votes, seats, positions, and the talks for the next government.
     */
    private function holdElection(PoliticsState $state, MacroStateDTO $macro, float $realGdp): void
    {
        $previous = $state->dietVoteShares;
        $outgoingSeats = $state->dietSeats;
        // The vote is support on the day: the lasting drift, the government's swing and the short-term swings of the
        // term, which the polls have been sampling (App\Service\Politics\OpinionPolls).
        OpinionPolls::update($state, $macro, $this->mathUtility);
        $shares = self::normaliseShares(OpinionPolls::support($state));
        OpinionPolls::settle($state);

        // For the record: the economy the voters weighed.
        $growthGap = self::annualisedLogChange($state->campaignStartRealGdp, $realGdp, $state->totalTime - $state->campaignStartedAt);
        $growthGap = $growthGap === null ? 0.0 : $growthGap - $macro->laborForceGrowthRate - MacroConstants::TFP_DRIFT;
        $inflationGap = self::annualisedLogChange($state->termStartDeflator, $macro->consumerPriceLevel, $state->totalTime - $state->termStartedAt);
        $inflationGap = $inflationGap === null ? 0.0 : $inflationGap - MacroConstants::TARGET_INFLATION;

        $state->electionGrowthGap = $growthGap;
        $state->electionInflationGap = $inflationGap;
        $state->electionIncumbentSwing = self::blocShare($shares, $state->governingCoalition, $state->supportParties)
            - self::blocShare($previous, $state->governingCoalition, $state->supportParties);

        $swings = [];
        foreach (AerieDiet::PARTIES as $party) {
            $swings[$party] = $shares[$party] - ($previous[$party] ?? 0.0);
        }
        $state->dietVoteSwings = $swings;
        $state->dietVoteShares = $shares;

        $seats = self::dHondt($shares, AerieDiet::SEATS);
        $state->dietSeats = array_map('floatval', $seats);

        $positions = [];
        foreach (AerieDiet::PARTIES as $party) {
            $positions[$party] = AerieDiet::position($party, $state->partyPositions);
            foreach (AerieDiet::AXES as $axis) {
                if (!AerieDiet::isFixed($party, $axis)) {
                    $positions[$party][$axis] = self::movePosition(
                        $positions[$party][$axis],
                        AerieDiet::HOME_POSITIONS[$party][$axis],
                        $axis,
                        $this->mathUtility->generateStandardNormal(),
                    );
                }
            }
        }
        $state->partyPositions = $positions;
        // The parties declared their blocs on the platforms they ran on and the seats they went into the vote with.
        $state->dietBlocs = CoalitionFormation::declareBlocs($outgoingSeats, $positions, $state->dietBlocs);

        // A cabinet that fell just before the vote goes into it as caretaker, with no incumbency to weigh.
        $statusQuo = $state->coalitionTakesOfficeAt >= 0.0 ? [] : AerieDiet::governingParties($state->governingCoalition);
        $state->electionOutgoingCabinet = $state->governingCoalition;
        $state->cabinetFallsAt = -1.0;
        self::beginTalks($state, CoalitionFormation::talks($seats, $shares, $positions, $statusQuo, $state->dietBlocs, $this->mathUtility));
    }

    /**
     * The cabinet loses the Diet between votes: it stays on as caretaker and the parties talk, on the seats the last
     * vote gave them. No election is called; the calendar is fixed. The parties that fell may form again, as at least
     * 42% of West European successors between votes do (ParlGov 1945-2020, 185 cases), but without the incumbency
     * weight a sitting cabinet carries into talks after a vote: with it, two in three falls re-formed the same cabinet.
     */
    private function fall(PoliticsState $state): void
    {
        $state->lastCabinetFellAt = $state->totalTime;
        $state->cabinetFallsAt = -1.0;
        self::beginTalks($state, CoalitionFormation::talks($state->dietSeats, $state->dietVoteShares, $state->partyPositions, [], $state->dietBlocs, $this->mathUtility));
    }

    /**
     * Sets the talks' outcome pending until the day it takes office.
     *
     * @param array{cabinet: list<string>, support: list<string>, days: float, log: list<array{day: float, formateur: string, formed: bool, cabinet: list<string>, support: list<string>}>} $talks
     */
    private static function beginTalks(PoliticsState $state, array $talks): void
    {
        $state->pendingCoalition = AerieDiet::membership($talks['cabinet']);
        $state->pendingSupport = AerieDiet::membership($talks['support']);
        $state->talksStartedAt = $state->totalTime;
        $state->coalitionTakesOfficeAt = $state->totalTime + ($talks['days'] / FinancialConstants::DAYS_PER_YEAR);
        $state->formationLog = $talks['log'];
    }

    /**
     * The yearly hazard a cabinet falls between votes, replaced without an election (ParlGov, West European cabinets
     * since 1945): a coalition can lose a partner, and a minority cabinet its supporters; a party governing alone with
     * its own majority has neither to lose.
     *
     * @param list<string>             $cabinet The cabinet's parties.
     * @param array<string, int|float> $seats   Seats by party.
     */
    public static function fallHazard(array $cabinet, array $seats): float
    {
        $minority = CoalitionFormation::coalitionSeats($cabinet, $seats) < AerieDiet::MAJORITY_SEATS;

        return match (true) {
            $minority && count($cabinet) === 1 => self::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY,
            $minority => self::CABINET_FALL_HAZARD_MINORITY_COALITION,
            count($cabinet) > 1 => self::CABINET_FALL_HAZARD_MAJORITY_COALITION,
            default => 0.0,
        };
    }

    /**
     * The government's change in vote share: the economy as real PR electorates weigh it, less the cost of ruling, plus
     * the residual. Inflation costs every cabinet; growth is credited only to a party governing alone, since voters
     * hold a coalition's parties to no account for it (Powell & Whitten 1993).
     *
     * Over part of a term (App\Service\Politics\OpinionPolls), the cost of ruling accrues in proportion and the
     * residual as a random walk, so the parts of a term add up to the whole.
     *
     * @param float $growthGap    Annualised real per-capita growth over the campaign, less its trend.
     * @param float $inflationGap Annualised inflation over the term, less the target.
     * @param float $residualDraw Standard normal draw for everything the economy does not explain.
     * @param bool  $alone        Whether one party forms the cabinet.
     * @param float $termShare    Share of the term the swing covers.
     */
    public static function economicVote(float $growthGap, float $inflationGap, float $residualDraw, bool $alone, float $termShare = 1.0): float
    {
        return ($alone ? self::ELECTION_SINGLE_PARTY_GROWTH_SLOPE * $growthGap : 0.0)
            - (self::ELECTION_INFLATION_SLOPE * $inflationGap)
            - (self::ELECTION_COST_OF_RULING * $termShare)
            + (self::ELECTION_RESIDUAL_SD * sqrt($termShare) * $residualDraw);
    }

    /**
     * Moves a swing between the government and the opposition, each side's parties in proportion to their shares.
     *
     * A support party's share sits partly on each side, in the proportion of the cost of governing it bears
     * (self::ELECTION_SUPPORT_ACCOUNTABILITY), so it gains or loses with the government by that much less.
     *
     * @param array<string, float> $shares    Vote shares by party.
     * @param array<string, float> $coalition 1.0 for a cabinet party.
     * @param float                $swing     Change in the government's combined share.
     * @param array<string, float> $support   1.0 for a support party.
     * @return array<string, float> Vote shares by party.
     */
    public static function applyIncumbentSwing(array $shares, array $coalition, float $swing, array $support = []): array
    {
        $governing = self::blocShare($shares, $coalition, $support);
        $opposition = array_sum($shares) - $governing;
        if ($governing <= 0.0 || $opposition <= 0.0) {
            return $shares;
        }

        // Neither side can lose more than it holds above the floor of its parties.
        $swing = max(- ($governing - self::MIN_VOTE_SHARE), min($opposition - self::MIN_VOTE_SHARE, $swing));

        $result = [];
        foreach (AerieDiet::PARTIES as $party) {
            $share = $shares[$party] ?? 0.0;
            $weight = self::governmentWeight($party, $coalition, $support);
            $result[$party] = $share
                + ($swing * $weight * $share / $governing)
                - ($swing * (1.0 - $weight) * $share / $opposition);
        }

        return $result;
    }
    /**
     * Moves each party's share by its own log swing, the shares renormalised to the whole electorate (additive logistic
     * form, Katz & King 1999). Applying the negated swings undoes it exactly.
     *
     * @param array<string, float> $shares Vote shares by party.
     * @param array<string, float> $shocks Log swing by party; a party without one is unmoved.
     * @return array<string, float> Vote shares by party.
     */
    public static function applyShortTermShocks(array $shares, array $shocks): array
    {
        if ($shocks === []) {
            return $shares;
        }

        $moved = [];
        foreach (AerieDiet::PARTIES as $party) {
            $moved[$party] = ($shares[$party] ?? 0.0) * exp($shocks[$party] ?? 0.0);
        }
        $total = array_sum($moved);

        return array_map(static fn(float $share): float => $share / $total, $moved);
    }

    /**
     * The standard deviation of a party's swing in log vote share: the variance scale over its normal vote, since real
     * vote shares vary in proportion to their size.
     *
     * @param float $varianceScale Variance of the log share times the normal vote.
     * @param float $normal        The party's normal vote.
     */
    public static function swingSd(float $varianceScale, float $normal): float
    {
        return sqrt($varianceScale / $normal);
    }

    /**
     * D'Hondt highest averages: each seat goes to the party with the largest share over (seats won + 1).
     *
     * @param array<string, float> $shares Vote shares (or votes) by party.
     * @param int                  $seats  Seats to allocate.
     * @return array<string, int> Seats by party, in PARTIES order for the parties given.
     */
    public static function dHondt(array $shares, int $seats): array
    {
        $won = array_fill_keys(array_keys($shares), 0);
        for ($seat = 0; $seat < $seats; ++$seat) {
            $best = null;
            $bestQuotient = -1.0;
            foreach ($shares as $party => $share) {
                $quotient = $share / ($won[$party] + 1);
                // Ties go to the larger vote, then to declaration order.
                if ($quotient > $bestQuotient || ($best !== null && $quotient === $bestQuotient && $share > $shares[$best])) {
                    $best = $party;
                    $bestQuotient = $quotient;
                }
            }
            if ($best === null) {
                break;
            }
            ++$won[$best];
        }

        return $won;
    }

    /**
     * A cabinet's policy: its members' positions weighted by their seats (Gamson's law).
     *
     * @param array<string, float>                $coalition 1.0 for a member.
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array{state: float, openness: float, council: float, environment: float}
     */
    public static function coalitionPosition(array $coalition, array $seats, array $positions): array
    {
        $weighted = array_fill_keys(AerieDiet::AXES, 0.0);
        $total = 0.0;
        foreach (AerieDiet::governingParties($coalition) as $party) {
            $weight = (float) ($seats[$party] ?? 0.0);
            $point = AerieDiet::position($party, $positions);
            foreach (AerieDiet::AXES as $axis) {
                $weighted[$axis] += $weight * $point[$axis];
            }
            $total += $weight;
        }
        if ($total > 0.0) {
            foreach (AerieDiet::AXES as $axis) {
                $weighted[$axis] /= $total;
            }
        }

        /** @var array{state: float, openness: float, council: float, environment: float} $weighted */
        return $weighted;
    }

    /**
     * Total electoral volatility (Pedersen 1979): half the sum of the parties' absolute changes in share.
     *
     * @param array<string, float> $swings Change in vote share by party.
     */
    public static function pedersenVolatility(array $swings): float
    {
        return 0.5 * array_sum(array_map('abs', $swings));
    }

    /**
     * Real GDP as an index: potential times the gap.
     */
    public static function realGdp(MacroStateDTO $macro): float
    {
        return $macro->potentialGdpIndex * (1.0 + $macro->outputGap);
    }

    /**
     * The government's share of the vote: its cabinet's, and the part of its supporters' that sits with it.
     *
     * @param array<string, float> $shares    Vote shares by party.
     * @param array<string, float> $coalition 1.0 for a cabinet party.
     * @param array<string, float> $support   1.0 for a support party.
     */
    private static function blocShare(array $shares, array $coalition, array $support = []): float
    {
        $total = 0.0;
        foreach (AerieDiet::PARTIES as $party) {
            $total += self::governmentWeight($party, $coalition, $support) * ($shares[$party] ?? 0.0);
        }

        return $total;
    }

    /**
     * How much of a party's share sits with the government: all of a cabinet party's, the accountable part of a
     * support party's, none of the opposition's.
     *
     * @param array<string, float> $coalition 1.0 for a cabinet party.
     * @param array<string, float> $support   1.0 for a support party.
     */
    private static function governmentWeight(string $party, array $coalition, array $support): float
    {
        return match (true) {
            ($coalition[$party] ?? 0.0) > 0.5 => 1.0,
            ($support[$party] ?? 0.0) > 0.5 => self::ELECTION_SUPPORT_ACCOUNTABILITY,
            default => 0.0,
        };
    }

    /**
     * Holds every party at the floor and the shares at a whole electorate.
     *
     * @param array<string, float> $shares Vote shares by party.
     * @return array<string, float>
     */
    private static function normaliseShares(array $shares): array
    {
        $floored = [];
        foreach (AerieDiet::PARTIES as $party) {
            $floored[$party] = max(self::MIN_VOTE_SHARE, $shares[$party] ?? 0.0);
        }
        $total = array_sum($floored);

        return array_map(static fn(float $share): float => $share / $total, $floored);
    }

    /**
     * Annualised log change between two readings, or null when either is missing or the span is too short to annualise.
     */
    private static function annualisedLogChange(float $from, float $to, float $years): ?float
    {
        if ($from <= 0.0 || $to <= 0.0 || $years < 0.25) {
            return null;
        }

        return log($to / $from) / $years;
    }

    /**
     * A party's position on an axis it is not defined by, one term on: what is left of its distance from home, plus the
     * term's shock, sized so that a party left alone strays from home by POSITION_WITHIN_SD.
     *
     * @param float  $position Its position at the last vote.
     * @param float  $home     Its home on the axis.
     * @param string $axis     The axis.
     * @param float  $draw     Standard normal draw.
     */
    public static function movePosition(float $position, float $home, string $axis, float $draw): float
    {
        $persistence = self::POSITION_ANNUAL_PERSISTENCE[$axis] ** self::ELECTION_TERM_YEARS;

        return self::reflect($home + ($persistence * ($position - $home))
            + (self::POSITION_WITHIN_SD[$axis] * sqrt(1.0 - ($persistence ** 2)) * $draw));
    }

    /**
     * Reflects a position back inside the axis at either end.
     */
    private static function reflect(float $position): float
    {
        if ($position > 1.0) {
            $position = 2.0 - $position;
        } elseif ($position < -1.0) {
            $position = -2.0 - $position;
        }

        return max(-1.0, min(1.0, $position));
    }
}
