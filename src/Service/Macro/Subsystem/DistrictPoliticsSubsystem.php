<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\Data\AerieDiet;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * The Aerie Diet: eight parties, a vote on the fixed election calendar, seats, the talks that follow, and the government.
 *
 * The vote is the economic vote: the government's share moves with growth over the campaign and inflation over the term
 * on Fair's presidential vote-equation slopes, with the opposition taking what it loses in proportion to its own shares.
 * Governing costs the governing parties a share of the vote each term, the cost of ruling (Nannestad & Paldam 2002)
 * the Diet's parties pay on top of what they were elected on running off; governments are formed by parties riding a
 * short-term swing or a lasting lead, so the loss a government is seen to take is larger. A party supporting a
 * minority cabinet from outside bears part of that cost (Thürk & Klüver 2024). A financial crisis in the five years
 * before the vote lifts the closed-economy party, the one that names the outside world as the contagion, by the 30%
 * Funke, Schularick & Trebesch (2016) find for the far right after financial crises, and gives it back once the
 * crisis leaves that window. Each party has a normal vote, its share at the founding (Converse 1966): its lasting support
 * strays from it and drifts back at the pace real parties' do, and each party also has a short-term swing --
 * candidates, campaigns, scandals -- which lasts only the one vote. Both are sized to the Nordic parties since 1945
 * (ParlGov), in proportion to a party's size, as real vote shares vary. Seats are D'Hondt over the whole Diet.
 *
 * Each party is fixed on the axes it is defined by (size of state, openness, or the Council). On the others it strays
 * from its home between elections and is pulled back toward it, at the pace real parties move in the Chapel Hill expert
 * survey: an AR(1) around each party's own place, not a random walk, since parties keep their family's positions for
 * decades (Budge, Ezrow & McDonald 2010). After the vote the parties negotiate a government (CoalitionFormation); until
 * it takes office the outgoing cabinet stays on as caretaker and passes no budget. Between votes a cabinet can fall, at
 * the rate real cabinets of its kind have (ParlGov): the parties then talk again on the same seats, with no election.
 *
 * The calendar is CreditFiscalSubsystem's: the vote is held on the tick its election falls on. A run built by hand (a
 * harness, a unit test) has none.
 *
 * The government then legislates at the budget rounds after the one it took office on. Its cabinet's position, its
 * members' weighted by seats (Gamson's law), sets four levers between the policies real parties at the ends of each
 * axis have enacted: the corporate rate on the size-of-state axis, the tariff and the immigration regime on the
 * openness axis, and merger review on the Council axis, from the populists' 2023 guidelines to the technocrats' 2010
 * ones. Size of state moves no purchases: in the US record the purchases process is fitted to, the party in
 * power shifts civilian purchases the wrong way (-2% under Democratic presidents, var/harness/partisan_fit.py), and the
 * panel evidence has faded since the 1990s (Potrafke 2017). A minority cabinet proposes and its support parties can
 * refuse: each accepts a lever no further from its own policy than the one in force, so a supporter can stop the
 * cabinet moving away from it but cannot pull policy its way (Romer & Rosenthal 1978). Above the 90% debt line the
 * Council would veto a bill that cuts revenue, and the Diet, knowing it, tables none, unless the government and its
 * supporters, less the Council's own loyalists, hold the three quarters that could remove the Council.
 */
class DistrictPoliticsSubsystem
{
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

    public function __construct(private readonly MathUtility $mathUtility) {}

    /**
     * Keeps the campaign and term marks, holds the vote on the tick the calendar puts it on, seats the government the
     * talks produce when its day comes, and passes the budget at each round a government sits through.
     *
     * Runs after real GDP and the deflator are struck, so the vote reads this tick's economy.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function update(MacroState $state, float $dt): void
    {
        $realGdp = self::realGdp($state);

        // A state that predates the marks opens them where it stands and measures from there.
        if ($state->termStartedAt < 0.0) {
            $state->termStartedAt = $state->totalTime;
            $state->termStartDeflator = $state->gdpDeflator;
        }
        if ($state->campaignStartedAt < 0.0
            || MathUtility::crossedSimulatedBoundary($state->totalTime + MacroEngine::ELECTION_CAMPAIGN_WINDOW_YEARS, $dt, MacroEngine::ELECTION_TERM_YEARS)) {
            $state->campaignStartedAt = $state->totalTime;
            $state->campaignStartRealGdp = $realGdp;
        }

        if ($state->lastElectionAt === $state->totalTime) {
            $this->holdElection($state, $realGdp);

            $state->termStartedAt = $state->totalTime;
            $state->termStartDeflator = $state->gdpDeflator;
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
            self::enactBudget($state);
        }
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
            'corporateTax' => MacroEngine::POLICY_MANIFESTO_CORPORATE_TAX_GAP * $state / $stateSpan,
            'tariff' => MacroEngine::POLICY_PROTECTIONIST_TARIFF * max(0.0, $openness / $closed),
            'laborGrowth' => MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE
                + ((MacroEngine::MIGRATION_OPEN_REGIME - MacroEngine::MIGRATION_CLOSED_REGIME) * $openness / $opennessSpan),
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
     * A budget round: budget() becomes law. A tariff's change moves productivity by Furceri et al.'s (2018) output
     * loss, a level potential absorbs over the years that follow.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public static function enactBudget(MacroState $state): void
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
            $state->sovereignDebtToGdp
        );
        $levers = $budget['levers'];

        if (in_array(true, $budget['councilHeld'], true)) {
            $state->lastCouncilBrakeAt = $state->totalTime;
        }

        $productivityLoss = -MacroEngine::TARIFF_OUTPUT_LOSS * ($levers['tariff'] - $state->importTariffRate);
        $state->tfpShockLevel += $productivityLoss;
        $state->totalFactorProductivityIndex *= exp($productivityLoss);

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
    public static function takeOffice(MacroState $state): void
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
    private function holdElection(MacroState $state, float $realGdp): void
    {
        $previous = $state->dietVoteShares;
        $outgoingSeats = $state->dietSeats;
        // The last vote's short-term forces are spent, and its crisis lift given back if the window has closed; both are
        // undone in the reverse of the order they were applied.
        $shares = self::applyShortTermShocks($previous, array_map(static fn(float $shock): float => -$shock, $state->partyShortTermShocks));
        $shares = self::returnCrisisShift($shares, $state->ironHarborCrisisShift);

        $lasting = [];
        foreach (AerieDiet::PARTIES as $party) {
            $lasting[$party] = self::swingSd(self::LASTING_SWING_VARIANCE, $party) * $this->mathUtility->generateStandardNormal();
        }
        $shares = self::revertToNormalVote($shares, $lasting);

        $growthGap = self::annualisedLogChange($state->campaignStartRealGdp, $realGdp, $state->totalTime - $state->campaignStartedAt);
        $growthGap = $growthGap === null ? 0.0 : $growthGap - $state->laborForceGrowthRate - MacroEngine::TFP_DRIFT;
        $inflationGap = self::annualisedLogChange($state->termStartDeflator, $state->gdpDeflator, $state->totalTime - $state->termStartedAt);
        $inflationGap = $inflationGap === null ? 0.0 : $inflationGap - MacroEngine::TARGET_INFLATION;

        $swing = self::economicVote($growthGap, $inflationGap, $this->mathUtility->generateStandardNormal());
        $shares = self::applyIncumbentSwing($shares, $state->governingCoalition, $swing, $state->supportParties);

        $crisisShift = 0.0;
        if ($state->lastCreditCrisisAt >= 0.0 && $state->totalTime - $state->lastCreditCrisisAt <= MacroEngine::ELECTION_CRISIS_WINDOW_YEARS) {
            [$shares, $crisisShift] = self::applyCrisisShift($shares);
        }

        $shocks = [];
        foreach (AerieDiet::PARTIES as $party) {
            $shocks[$party] = self::swingSd(MacroEngine::ELECTION_SHORT_TERM_SWING_VARIANCE, $party) * $this->mathUtility->generateStandardNormal();
        }
        $shares = self::normaliseShares(self::applyShortTermShocks($shares, $shocks));
        $state->partyShortTermShocks = $shocks;

        $state->electionGrowthGap = $growthGap;
        $state->electionInflationGap = $inflationGap;
        $state->electionIncumbentSwing = self::blocShare($shares, $state->governingCoalition, $state->supportParties)
            - self::blocShare($previous, $state->governingCoalition, $state->supportParties);
        $state->ironHarborCrisisShift = $crisisShift;

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
    private function fall(MacroState $state): void
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
    private static function beginTalks(MacroState $state, array $talks): void
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
            $minority && count($cabinet) === 1 => MacroEngine::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY,
            $minority => MacroEngine::CABINET_FALL_HAZARD_MINORITY_COALITION,
            count($cabinet) > 1 => MacroEngine::CABINET_FALL_HAZARD_MAJORITY_COALITION,
            default => 0.0,
        };
    }

    /**
     * The governing coalition's change in vote share: Fair's economic slopes less the cost of ruling, plus the residual.
     *
     * @param float $growthGap    Annualised real per-capita growth over the campaign, less its trend.
     * @param float $inflationGap Annualised inflation over the term, less the target.
     * @param float $residualDraw Standard normal draw for everything the economy does not explain.
     */
    public static function economicVote(float $growthGap, float $inflationGap, float $residualDraw): float
    {
        return (MacroEngine::ELECTION_GROWTH_SLOPE * $growthGap)
            - (MacroEngine::ELECTION_INFLATION_SLOPE * $inflationGap)
            - MacroEngine::ELECTION_COST_OF_RULING
            + (MacroEngine::ELECTION_RESIDUAL_SD * $residualDraw);
    }

    /**
     * Moves a swing between the government and the opposition, each side's parties in proportion to their shares.
     *
     * A support party's share sits partly on each side, in the proportion of the cost of governing it bears
     * (MacroEngine::ELECTION_SUPPORT_ACCOUNTABILITY), so it gains or loses with the government by that much less.
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
     * Lifts the closed-economy party's share by the post-crisis gain, taken from the others in proportion to theirs.
     *
     * @param array<string, float> $shares Vote shares by party.
     * @return array{0: array<string, float>, 1: float} The shares, and the share moved.
     */
    public static function applyCrisisShift(array $shares): array
    {
        $shift = MacroEngine::ELECTION_CRISIS_CLOSED_PARTY_LIFT * ($shares[AerieDiet::IRON_HARBOR] ?? 0.0);

        return [self::moveShare($shares, AerieDiet::IRON_HARBOR, $shift), $shift];
    }

    /**
     * Gives back a crisis lift from an earlier vote, so the lift lasts only as long as its window.
     *
     * @param array<string, float> $shares Vote shares by party.
     * @param float                $shift  The share the earlier vote moved.
     * @return array<string, float> Vote shares by party.
     */
    public static function returnCrisisShift(array $shares, float $shift): array
    {
        if ($shift <= 0.0) {
            return $shares;
        }

        return self::moveShare($shares, AerieDiet::IRON_HARBOR, -min($shift, ($shares[AerieDiet::IRON_HARBOR] ?? 0.0) - self::MIN_VOTE_SHARE));
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
     * @param array<string, float> $shares Lasting vote shares by party, short-term swings and crisis lift given back.
     * @param array<string, float> $swings Lasting log swing by party; a party without one only drifts back.
     * @return array<string, float> Vote shares by party.
     */
    public static function revertToNormalVote(array $shares, array $swings): array
    {
        $persistence = MacroEngine::ELECTION_NORMAL_VOTE_PERSISTENCE ** MacroEngine::ELECTION_TERM_YEARS;

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
    public static function realGdp(MacroState $state): float
    {
        return $state->potentialGdpIndex * (1.0 + $state->outputGap);
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
            ($support[$party] ?? 0.0) > 0.5 => MacroEngine::ELECTION_SUPPORT_ACCOUNTABILITY,
            default => 0.0,
        };
    }

    /**
     * Moves a share to one party from all the others, in proportion to theirs.
     *
     * @param array<string, float> $shares Vote shares by party.
     * @return array<string, float>
     */
    private static function moveShare(array $shares, string $to, float $amount): array
    {
        $others = array_sum($shares) - ($shares[$to] ?? 0.0);
        if ($others <= 0.0) {
            return $shares;
        }
        $amount = min($amount, $others - (self::MIN_VOTE_SHARE * (count(AerieDiet::PARTIES) - 1)));

        $result = [];
        foreach (AerieDiet::PARTIES as $party) {
            $share = $shares[$party] ?? 0.0;
            $result[$party] = $party === $to ? $share + $amount : $share * ($others - $amount) / $others;
        }

        return $result;
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
        $persistence = self::POSITION_ANNUAL_PERSISTENCE[$axis] ** MacroEngine::ELECTION_TERM_YEARS;

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
