<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Psr\Log\LoggerInterface;

/**
 * The Aerie Diet: eight parties, a vote on the fixed election calendar, seats, the talks that follow, and the government.
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
 * Each party is fixed on the axes it is defined by (size of state, openness, or the Council). On the others it strays
 * from its home between elections and is pulled back toward it, at the pace real parties move in the Chapel Hill expert
 * survey: an AR(1) around each party's own place, not a random walk, since parties keep their family's positions for
 * decades (Budge, Ezrow & McDonald 2010). After the vote the parties negotiate a government (CoalitionFormation); until
 * it takes office the outgoing cabinet stays on as caretaker and passes no budget. Between votes a cabinet can fall, at
 * the rate real cabinets of its kind have (ParlGov): the parties then talk again on the same seats, with no election.
 *
 * The government then legislates at the budget rounds after the one it took office on. Its cabinet's position, its
 * members' weighted by seats (Gamson's law), sets four levers between the policies real parties at the ends of each
 * axis have enacted: the corporate rate on the size-of-state axis, the tariff and the immigration regime on the
 * openness axis, and merger review on the Council axis, from the populists' 2023 guidelines to the technocrats' 2010
 * ones. Size of state moves no purchases: in the US record the purchases process is fitted to, the party in
 * power shifts civilian purchases the wrong way (-2% under Democratic presidents, var/harness/partisan_fit.py), and in
 * 21 European PR democracies since 1990 a cabinet's place on the state-market scale has moved government consumption
 * by nothing (+0.3% per unit of the axis, se 4.6%), nor total spending, though it did before (+11%, se 7%;
 * var/harness/politics/spend_europe_fit.py; Potrafke 2017). A minority cabinet proposes and its support parties can
 * refuse: each accepts a lever no further from its own policy than the one in force, so a supporter can stop the
 * cabinet moving away from it but cannot pull policy its way (Romer & Rosenthal 1978). Above the 90% debt line the
 * Council would veto a bill that cuts revenue, and the Diet, knowing it, tables none, unless the government and its
 * supporters, less the Council's own loyalists, hold the three quarters that could remove the Council.
 *
 * Politics runs beside the economy, not inside it: the ticker advances it after each macro tick on that tick's snapshot,
 * and the economy reads back only what the government hands it (PoliticsStateDTO::policy()), on the next tick.
 */
class PoliticsEngine
{
    // --- Persistence ---
    /** Redis key the politics state is kept under, beside the macro state. */
    public const REDIS_POLITICS_STATE = 'politics_state';

    // --- The Diet's Election (ParlGov x World Bank, var/harness/politics/vote_europe_fit.py; Powell & Whitten 1993; Nannestad & Paldam 2002) ---
    /** Length of the fixed electoral term in years: the vote falls on the tick each term ends. */
    public const ELECTION_TERM_YEARS = 4.0;
    /** Final stretch of the term whose growth voters weigh (Fair's G: the first three quarters of the election year). */
    public const ELECTION_CAMPAIGN_WINDOW_YEARS = 0.75;
    /** Vote share a party governing alone gains per unit of real growth per head over the year to the vote above its trend: 0.51 (se 0.30) in 199 votes in 21 established PR democracies 1971-2020; a coalition's share does not move with growth (Powell & Whitten 1993's clarity of responsibility; -0.09, se 0.40, before the euro-area consolidations of 2008-2020). */
    public const ELECTION_SINGLE_PARTY_GROWTH_SLOPE = 0.506;
    /** Vote share any cabinet loses per unit of annualised GDP-deflator inflation over the term above target: 0.10 (se 0.08), same fit (Fair's US presidential slope is 0.72). */
    public const ELECTION_INFLATION_SLOPE = 0.100;
    /** Government-wide swing in its share that the economy does not explain, common to its parties (Fair: standard error 2.95 pp); each party's own swings are drawn on top of it, sized with it to the Nordic record. */
    public const ELECTION_RESIDUAL_SD = 0.0295;
    /** Vote share the average government has lost over a term in the record (Nannestad & Paldam 2002: 282 elections in 19 democracies), the reference the Diet's own cost is read against. */
    public const ELECTION_RECORDED_COST_OF_RULING = 0.0225;
    /** Vote share governing costs the governing parties each term, beyond what they were elected on drifting back: governments are formed by parties riding a short-term swing or a lasting lead, and both run off. With those, the outgoing cabinet's parties lose 2.2 points a term, the record's 2.25 (var/harness/politics/vote_sim.py, 2,500 elections; ParlGov since 1945, measured the same way: Scandinavia 1.8, Western Europe 3.9). */
    public const ELECTION_COST_OF_RULING = 0.005;
    /** Each party's own short-term swing, drawn at every vote and gone by the next (Converse 1966 short-term forces), as the variance of its log vote share times its normal vote: real vote shares vary in proportion to their size (ParlGov, West European parties since 1945: log variance on log share, slope -0.99). Nordic parties since 1945 (var/harness/politics/vote_fit.py): a party of a quarter swings 7% either way, one of a twentieth 15%. */
    public const ELECTION_SHORT_TERM_SWING_VARIANCE = 0.0012;
    /** Share of a party's lasting lead or deficit on its normal vote still there a year later: real parties drift back toward their usual share, a half-life of seven years (ParlGov, Nordic parties since 1945, var/harness/politics/vote_fit.py; West European parties 0.911 over elections up to 20 years apart). */
    public const ELECTION_NORMAL_VOTE_PERSISTENCE = 0.905;
    /** Share of a cabinet party's electoral cost of governing a support party bears: 1.99 points lost against 2.81 for cabinet parties (Thürk & Klüver 2024, Table 1 Model 1: support -1.991, prime minister's party -2.815, junior partner -2.799; 304 elections in 31 democracies since 1980). */
    public const ELECTION_SUPPORT_ACCOUNTABILITY = 1.991 / ((2.815 + 2.799) / 2.0);

    // --- Cabinets Falling Between Votes (ParlGov, West European cabinets since 1945, var/harness/politics/termination_fit.py) ---
    /** Yearly hazard a single-party minority cabinet falls between votes, replaced by another cabinet without an election: 21 falls in 199 cabinet-years (a constant hazard, King, Alt, Burns & Laver 1990). */
    public const CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY = 0.106;
    /** Yearly hazard a minority coalition falls between votes: 27 falls in 125 cabinet-years, twice a single party's, as a partner can walk out as well as a supporter. */
    public const CABINET_FALL_HAZARD_MINORITY_COALITION = 0.216;
    /** Yearly hazard a majority coalition falls between votes: 91 falls in 763 cabinet-years. A party governing alone with its own majority has no partner or supporter to lose, and none fell in 242 cabinet-years. */
    public const CABINET_FALL_HAZARD_MAJORITY_COALITION = 0.119;

    // --- Election Uncertainty (Julio & Yook 2012; Bernhard & Leblang 2006) ---
    /** Days per term the talks after a cabinet falls hold the election pulse above where the term's ramp would put it: falls in 41% of terms, 40 days of talks each (var/harness/politics/formation_report.py). */
    public const FALL_TALK_DAYS_PER_TERM = 19.5;
    /** Long-run mean of nearness to the vote: the final-year ramp's half year a term, plus the talks at the peak after the vote and after any fall. */
    public const MEAN_ELECTION_PROXIMITY = (0.5 + ((CoalitionFormation::FORMATION_MEAN_DAYS + self::FALL_TALK_DAYS_PER_TERM) / FinancialConstants::DAYS_PER_YEAR)) / self::ELECTION_TERM_YEARS;

    // --- Party Positions (Chapel Hill expert survey 1999-2024, var/harness/politics/ches_fit.py) ---
    /** Share of a party's distance from its home still there a year later, by axis: economic left-right for size of state, European integration for openness, anti-elite rhetoric for the Council; Western parties against their country's mean. */
    public const POSITION_ANNUAL_PERSISTENCE = [AerieDiet::AXIS_STATE => 0.872, AerieDiet::AXIS_OPENNESS => 0.958, AerieDiet::AXIS_COUNCIL => 0.846];
    /** How far a party strays from its home on each axis, the standard deviation around it, from the same fit (a full expert scale is two units). */
    public const POSITION_WITHIN_SD = [AerieDiet::AXIS_STATE => 0.125, AerieDiet::AXIS_OPENNESS => 0.265, AerieDiet::AXIS_COUNCIL => 0.188];

    // --- Vote Shares ---
    /** Smallest vote share a party is carried at, so a collapse leaves it a rump rather than a negative share. */
    public const MIN_VOTE_SHARE = 0.005;
    /** Each party's own lasting swing each term, as the variance of its log vote share times its normal vote: with the government's swings, it spreads the parties around their normal votes as far as the Nordic parties since 1945, 0.019 (ParlGov, var/harness/politics/vote_fit.py against vote_sim.py). */
    public const LASTING_SWING_VARIANCE = 0.0118;

    // --- The Budget ---
    /** The levers a budget sets, and whether cutting each costs revenue the Council guards. */
    public const REVENUE_LEVERS = ['corporateTax' => true, 'tariff' => true, 'laborGrowth' => false, 'mergerReviewLeniency' => false];

    // --- Platforms: Corporate Tax (Osterloh & Debus 2012) ---
    /** Gap between the corporate rates the big-state and small-state manifestos set, the parties at the ends of the size-of-state axis: 7 points (US: the 2020 Democratic platform's 28% against the 21% of the 2017 Republican act; UK 2019: Labour's 26% against the Conservatives' 19%). Enacted rates follow manifesto ideology (Osterloh & Debus 2012, European panel). */
    public const POLICY_MANIFESTO_CORPORATE_TAX_GAP = 0.07;

    // --- Platforms: Tariffs (Amiti, Redding & Weinstein 2019; Fajgelbaum et al. 2020) ---
    /** Average effective tariff the protectionist end of the openness axis enacts over the District's free port: the US's 2025 rise, 2.5% to 17.9% (Yale Budget Lab, State of U.S. Tariffs, 26 September 2025). Duties pass fully into import prices at the border (Amiti, Redding & Weinstein 2019; Fajgelbaum et al. 2020). */
    public const POLICY_PROTECTIONIST_TARIFF = 0.154;

    // --- Platforms: Immigration (UN WPP via World Bank, 2000-2019) ---
    /** Net migration a closed immigration regime admits, a year as a share of population: Japan's 0.11% (World Bank SM.POP.NETM over SP.POP.TOTL, 2000-2019 mean). */
    public const MIGRATION_CLOSED_REGIME = 0.0011;
    /** Net migration an open immigration regime admits: Canada's 0.73% and Australia's 0.86%, averaged (the same series). Midway between the two regimes sits the US's 0.50%, the structural labour growth the District opens with. */
    public const MIGRATION_OPEN_REGIME = (0.00728 + 0.00859) / 2.0;

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
            $state->termStartDeflator = $macro->gdpDeflator;
        }
        if ($state->campaignStartedAt < 0.0
            || MathUtility::crossedSimulatedBoundary($state->totalTime + self::ELECTION_CAMPAIGN_WINDOW_YEARS, $dt, self::ELECTION_TERM_YEARS)) {
            $state->campaignStartedAt = $state->totalTime;
            $state->campaignStartRealGdp = $realGdp;
        }

        // Snapped to the tick grid, so the vote falls on the same tick as every other boundary of the calendar (a budget
        // round) rather than one tick late when accumulated time lands a hair short of the term.
        if (MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, self::ELECTION_TERM_YEARS)) {
            $state->lastElectionAt = $state->totalTime;
            $this->holdElection($state, $macro, $realGdp);

            $state->termStartedAt = $state->totalTime;
            $state->termStartDeflator = $macro->gdpDeflator;
        }

        if ($state->cabinetFallsAt >= 0.0 && $state->totalTime >= $state->cabinetFallsAt) {
            $this->fall($state);
        }

        if ($state->coalitionTakesOfficeAt >= 0.0 && $state->totalTime >= $state->coalitionTakesOfficeAt) {
            self::takeOffice($state);
        }

        // A sitting cabinet without a fall date draws one: on taking office, or on the first tick of a state that has none.
        if ($state->cabinetFallsAt < 0.0 && $state->coalitionTakesOfficeAt < 0.0) {
            $hazard = self::fallHazard(AerieDiet::governingParties($state->governingCoalition), $state->dietSeats);
            if ($hazard > 0.0) {
                $state->cabinetFallsAt = $state->totalTime + ($this->mathUtility->generateExponential() / $hazard);
            }
        }

        // A government's first budget is the round after the one it took office on; a caretaker passes none.
        if ($state->coalitionTakesOfficeAt < 0.0
            && $state->coalitionFormedAt < $state->totalTime
            && MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, MacroEngine::BUDGET_ROUND_PERIOD_YEARS)) {
            self::enactBudget($state, $macro->sovereignDebtToGdp);
        }

        $state->eventType = self::headline($state);
    }

    /**
     * The tick's political headline, the vote above all: a cabinet falling, then one taking office, then a budget round
     * that changed a lever. A single-tick pulse, gone on the next tick.
     */
    public static function headline(PoliticsState $state): ?string
    {
        return match (true) {
            $state->lastElectionAt === $state->totalTime => ShockEvent::ELECTION_HELD,
            $state->lastCabinetFellAt === $state->totalTime => ShockEvent::GOVERNMENT_FELL,
            $state->lastGovernmentFormedAt === $state->totalTime => ShockEvent::GOVERNMENT_FORMED,
            $state->lastBudgetEnactedAt === $state->totalTime => ShockEvent::BUDGET_ENACTED,
            default => null,
        };
    }

    /**
     * The election calendar's pull on policy uncertainty, centred on its long-run mean: nearness to the vote, rising over
     * the final year of the term (Julio & Yook 2012), held at its peak on the day of the vote and through the talks after
     * it or after a fall, since only a seated government settles the regime (Bernhard & Leblang 2006).
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
     * @param array<string, float> $position A position by axis (coalitionPosition(), or a party's).
     * @return array{corporateTax: float, tariff: float, laborGrowth: float, mergerReviewLeniency: float} The corporate
     *         rate's shift from the neutral rate, the average tariff on imports, labour force growth, and where merger
     *         review stands between the 2023 guidelines (0) and the 2010 guidelines (1).
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
        $state = max($smallState, min($bigState, $position[AerieDiet::AXIS_STATE] ?? 0.0));
        $openness = max($closed, min($open, $position[AerieDiet::AXIS_OPENNESS] ?? 0.0));
        $council = max($populist, min($technocratic, $position[AerieDiet::AXIS_COUNCIL] ?? 0.0));
        $stateSpan = $bigState - $smallState;
        $opennessSpan = $open - $closed;

        return [
            'corporateTax' => self::POLICY_MANIFESTO_CORPORATE_TAX_GAP * $state / $stateSpan,
            'tariff' => self::POLICY_PROTECTIONIST_TARIFF * max(0.0, $openness / $closed),
            'laborGrowth' => MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE
                + ((self::MIGRATION_OPEN_REGIME - self::MIGRATION_CLOSED_REGIME) * $openness / $opennessSpan),
            // The Common Lot's anti-cartel review is the 2023 guidelines, the Chartists' the 2010 ones
            // (App\Service\Corporate\MergerAndAcquisitionEngine::reviewScreens).
            'mergerReviewLeniency' => ($council - $populist) / ($technocratic - $populist),
        ];
    }

    /**
     * What a budget round would enact: the cabinet's platform, as far as its support parties and the Council let it.
     *
     * The cabinet proposes and each support party accepts a lever no further from its own policy than the one in force
     * (Romer & Rosenthal 1978), so the proposal is the cabinet's platform held inside every supporter's acceptable
     * range, which always holds the lever in force. Above the debt line the Council's veto is a red line on revenue,
     * and the Diet tables nothing it would veto: a lever that would cut revenue stays where it stands. A government
     * whose seats and supporters', less the Council's loyalists, reach the three quarters that could remove
     * councillors is not held.
     *
     * @param list<string>                        $cabinet   The cabinet's parties.
     * @param list<string>                        $support   Its support parties.
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param array{corporateTax: float, tariff: float, laborGrowth: float, mergerReviewLeniency: float} $standing The levers in force.
     * @return array{levers: array{corporateTax: float, tariff: float, laborGrowth: float, mergerReviewLeniency: float}, platform: array{corporateTax: float, tariff: float, laborGrowth: float, mergerReviewLeniency: float}, supportHeld: array<string, bool>, councilHeld: array<string, bool>, councilGuards: bool}
     *         What the round enacts, the cabinet's own platform, which levers the supporters and the Council hold short
     *         of it, and whether the Council's brake is on.
     */
    public static function budget(array $cabinet, array $support, array $seats, array $positions, array $standing, float $debtToGdp): array
    {
        $platform = self::platform(self::coalitionPosition(AerieDiet::membership($cabinet), $seats, $positions));
        $supporterPlatforms = array_map(static fn(string $party): array => self::platform(AerieDiet::position($party, $positions)), $support);

        $removalSeats = CoalitionFormation::coalitionSeats(array_values(array_diff(array_merge($cabinet, $support), AerieDiet::COUNCIL_LOYALISTS)), $seats);
        $councilGuards = $debtToGdp > MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD && $removalSeats < AerieDiet::SUPERMAJORITY_SEATS;

        $levers = $platform;
        $supportHeld = [];
        $councilHeld = [];
        foreach (self::REVENUE_LEVERS as $lever => $revenue) {
            $low = -INF;
            $high = INF;
            foreach ($supporterPlatforms as $own) {
                $reach = abs($standing[$lever] - $own[$lever]);
                $low = max($low, $own[$lever] - $reach);
                $high = min($high, $own[$lever] + $reach);
            }
            $levers[$lever] = max($low, min($high, $platform[$lever]));
            $supportHeld[$lever] = $levers[$lever] !== $platform[$lever];

            $councilHeld[$lever] = $revenue && $councilGuards && $levers[$lever] < $standing[$lever];
            if ($councilHeld[$lever]) {
                $levers[$lever] = $standing[$lever];
            }
        }

        return ['levers' => $levers, 'platform' => $platform, 'supportHeld' => $supportHeld, 'councilHeld' => $councilHeld, 'councilGuards' => $councilGuards];
    }

    /**
     * A budget round: budget() becomes law, and the economy reads the levers from the next tick.
     *
     * @param PoliticsState $state     The politics, advanced in place.
     * @param float         $debtToGdp Sovereign debt over GDP, which the Council's brake reads.
     */
    public static function enactBudget(PoliticsState $state, float $debtToGdp): void
    {
        $standing = [
            'corporateTax' => $state->corporateTaxPolicyShift,
            'tariff' => $state->importTariffRate,
            'laborGrowth' => $state->laborForceGrowthRate,
            'mergerReviewLeniency' => $state->mergerReviewLeniency,
        ];
        $budget = self::budget(
            AerieDiet::governingParties($state->governingCoalition),
            AerieDiet::governingParties($state->supportParties),
            $state->dietSeats,
            $state->partyPositions,
            $standing,
            $debtToGdp
        );
        $levers = $budget['levers'];

        if (in_array(true, $budget['councilHeld'], true)) {
            $state->lastCouncilBrakeAt = $state->totalTime;
        }

        if ($levers !== $standing) {
            $state->lastBudgetEnactedAt = $state->totalTime;
        }
        $state->corporateTaxPolicyShift = $levers['corporateTax'];
        $state->importTariffRate = $levers['tariff'];
        $state->laborForceGrowthRate = $levers['laborGrowth'];
        $state->mergerReviewLeniency = $levers['mergerReviewLeniency'];
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
        // The last vote's short-term forces are spent.
        $shares = self::applyShortTermShocks($previous, array_map(static fn(float $shock): float => -$shock, $state->partyShortTermShocks));

        $lasting = [];
        foreach (AerieDiet::PARTIES as $party) {
            $lasting[$party] = self::swingSd(self::LASTING_SWING_VARIANCE, $party) * $this->mathUtility->generateStandardNormal();
        }
        $shares = self::revertToNormalVote($shares, $lasting);

        $growthGap = self::annualisedLogChange($state->campaignStartRealGdp, $realGdp, $state->totalTime - $state->campaignStartedAt);
        $growthGap = $growthGap === null ? 0.0 : $growthGap - $macro->laborForceGrowthRate - MacroEngine::TFP_DRIFT;
        $inflationGap = self::annualisedLogChange($state->termStartDeflator, $macro->gdpDeflator, $state->totalTime - $state->termStartedAt);
        $inflationGap = $inflationGap === null ? 0.0 : $inflationGap - MacroEngine::TARGET_INFLATION;

        $alone = count(AerieDiet::governingParties($state->governingCoalition)) === 1;
        $swing = self::economicVote($growthGap, $inflationGap, $this->mathUtility->generateStandardNormal(), $alone);
        $shares = self::applyIncumbentSwing($shares, $state->governingCoalition, $swing, $state->supportParties);

        $shocks = [];
        foreach (AerieDiet::PARTIES as $party) {
            $shocks[$party] = self::swingSd(self::ELECTION_SHORT_TERM_SWING_VARIANCE, $party) * $this->mathUtility->generateStandardNormal();
        }
        $shares = self::normaliseShares(self::applyShortTermShocks($shares, $shocks));
        $state->partyShortTermShocks = $shocks;

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
                    $positions[$party][$axis] = self::movePosition($positions[$party][$axis], AerieDiet::HOME_POSITIONS[$party][$axis], $axis,
                        $this->mathUtility->generateStandardNormal());
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
     * The cabinet loses the Diet between votes: it stays on as caretaker and the parties talk, on the seats the last vote
     * gave them, for a cabinet other than the one that fell. No election is called; the calendar is fixed.
     */
    private function fall(PoliticsState $state): void
    {
        $fallen = AerieDiet::governingParties($state->governingCoalition);
        $state->lastCabinetFellAt = $state->totalTime;
        $state->cabinetFallsAt = -1.0;
        self::beginTalks($state, CoalitionFormation::talks($state->dietSeats, $state->dietVoteShares, $state->partyPositions, [], $state->dietBlocs, $this->mathUtility, $fallen));
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
     * since 1945): a coalition can lose a partner, and a minority cabinet its supporters; a party governing alone with its
     * own majority has neither to lose.
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
     * the residual. Inflation costs every cabinet; growth is credited only to a party governing alone, since voters hold
     * a coalition's parties to no account for it (Powell & Whitten 1993).
     *
     * @param float $growthGap    Annualised real per-capita growth over the campaign, less its trend.
     * @param float $inflationGap Annualised inflation over the term, less the target.
     * @param float $residualDraw Standard normal draw for everything the economy does not explain.
     * @param bool  $alone        Whether one party forms the cabinet.
     */
    public static function economicVote(float $growthGap, float $inflationGap, float $residualDraw, bool $alone): float
    {
        return ($alone ? self::ELECTION_SINGLE_PARTY_GROWTH_SLOPE * $growthGap : 0.0)
            - (self::ELECTION_INFLATION_SLOPE * $inflationGap)
            - self::ELECTION_COST_OF_RULING
            + (self::ELECTION_RESIDUAL_SD * $residualDraw);
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
        $swing = max(-($governing - self::MIN_VOTE_SHARE), min($opposition - self::MIN_VOTE_SHARE, $swing));

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
     * Pulls each party's lasting share back toward its normal vote, the share it won at the founding (Converse 1966),
     * and moves it by its own lasting swing: its log share against its normal vote decays for a term at the persistence
     * real parties show and takes the swing, the shares renormalised to the whole electorate (additive logistic form,
     * Katz & King 1999).
     *
     * @param array<string, float> $shares Lasting vote shares by party, short-term swings given back.
     * @param array<string, float> $swings Lasting log swing by party; a party without one only drifts back.
     * @return array<string, float> Vote shares by party.
     */
    public static function revertToNormalVote(array $shares, array $swings): array
    {
        $persistence = self::ELECTION_NORMAL_VOTE_PERSISTENCE ** self::ELECTION_TERM_YEARS;

        $moved = [];
        foreach (AerieDiet::PARTIES as $party) {
            $normal = AerieDiet::SEED_VOTE_SHARES[$party];
            $share = max(self::MIN_VOTE_SHARE, $shares[$party] ?? $normal);
            $moved[$party] = $normal * exp(($persistence * log($share / $normal)) + ($swings[$party] ?? 0.0));
        }
        $total = array_sum($moved);

        return array_map(static fn(float $share): float => $share / $total, $moved);
    }

    /**
     * The standard deviation of a party's swing in log vote share: the variance scale over its normal vote, since real
     * vote shares vary in proportion to their size.
     *
     * @param float $varianceScale Variance of the log share times the normal vote.
     */
    public static function swingSd(float $varianceScale, string $party): float
    {
        return sqrt($varianceScale / AerieDiet::SEED_VOTE_SHARES[$party]);
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
     * @return array{state: float, openness: float, council: float}
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

        /** @var array{state: float, openness: float, council: float} $weighted */
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
