<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\Data\AerieDiet;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * The Aerie Diet: four parties, a vote on the fixed election calendar, seats, and the coalition that governs.
 *
 * The vote is the economic vote: the governing coalition's share moves with growth over the campaign and inflation
 * over the term on Fair's presidential vote-equation slopes, with the opposition taking what it loses in proportion to
 * its own shares. Governing costs the governing parties a share of the vote each term, the cost of ruling (Nannestad
 * & Paldam 2002) that keeps a party system balanced; the loss a government is seen to take is larger, because
 * governments are formed by the parties whose short-term swings carried the last vote, and those run off by the next. A financial crisis in the five years
 * before the vote lifts the closed-economy party, the one that names the outside world as the contagion, by the 30%
 * Funke, Schularick & Trebesch (2016) find for the far right after financial crises, and gives it back once the
 * crisis leaves that window. On top of that each party has its own short-term swing -- candidates, campaigns, scandals --
 * which lasts only the one vote (Converse 1966), sized so the Diet is as volatile as Western Europe's parliaments have
 * been on average. Seats are D'Hondt over the whole Diet.
 *
 * Each party is fixed on the axis it is defined by (size of state or openness) and drifts on the other by the
 * manifesto shift parties make between elections (Somer-Topcu 2009). The government is the minimal winning coalition
 * with the smallest ideological range (Riker 1962; de Swaan 1973; Martin & Stevenson 2001), so the drift is what lets
 * the two big parties change partners, and the grand coalition forms only when nothing tighter commands a majority.
 *
 * The calendar is CreditFiscalSubsystem's: the vote is held on the tick its election falls on. This subsystem moves no
 * macro lever. A run built by hand (a harness, a unit test) has none.
 */
class DistrictPoliticsSubsystem
{
    // --- Party Drift (Somer-Topcu 2009) ---
    /** Per-election step on the secondary axis: the mean absolute manifesto shift of 10-16 of 200 RILE points is ~0.12 of this two-unit scale, sigma = 0.12 / sqrt(2/pi). */
    public const SECONDARY_DRIFT_PER_ELECTION = 0.15;

    // --- Vote Shares ---
    /** Smallest vote share a party is carried at, so a collapse leaves it a rump rather than a negative share. */
    public const MIN_VOTE_SHARE = 0.005;

    /** Distances equal to within this are ties. */
    private const RANGE_TOLERANCE = 1e-9;

    public function __construct(private readonly MathUtility $mathUtility) {}

    /**
     * Keeps the campaign and term marks, and holds the vote on the tick the calendar puts it on.
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

        if ($state->lastElectionAt !== $state->totalTime) {
            return;
        }

        $this->holdElection($state, $realGdp);

        $state->termStartedAt = $state->totalTime;
        $state->termStartDeflator = $state->gdpDeflator;
    }

    /**
     * Votes, seats, positions, government.
     */
    private function holdElection(MacroState $state, float $realGdp): void
    {
        $previous = $state->dietVoteShares;
        // The last vote's short-term forces are spent, and its crisis lift given back if the window has closed; both are
        // undone in the reverse of the order they were applied.
        $shares = self::applyShortTermShocks($previous, array_map(static fn(float $shock): float => -$shock, $state->partyShortTermShocks));
        $shares = self::returnCrisisShift($shares, $state->ironHarborCrisisShift);

        $growthGap = self::annualisedLogChange($state->campaignStartRealGdp, $realGdp, $state->totalTime - $state->campaignStartedAt);
        $growthGap = $growthGap === null ? 0.0 : $growthGap - MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE - MacroEngine::TFP_DRIFT;
        $inflationGap = self::annualisedLogChange($state->termStartDeflator, $state->gdpDeflator, $state->totalTime - $state->termStartedAt);
        $inflationGap = $inflationGap === null ? 0.0 : $inflationGap - MacroEngine::TARGET_INFLATION;

        $swing = self::economicVote($growthGap, $inflationGap, $this->mathUtility->generateStandardNormal());
        $shares = self::applyIncumbentSwing($shares, $state->governingCoalition, $swing);

        $crisisShift = 0.0;
        if ($state->lastCreditCrisisAt >= 0.0 && $state->totalTime - $state->lastCreditCrisisAt <= MacroEngine::ELECTION_CRISIS_WINDOW_YEARS) {
            [$shares, $crisisShift] = self::applyCrisisShift($shares);
        }

        $shocks = [];
        foreach (AerieDiet::PARTIES as $party) {
            $shocks[$party] = MacroEngine::ELECTION_PARTY_SHOCK_SD * $this->mathUtility->generateStandardNormal();
        }
        $shares = self::normaliseShares(self::applyShortTermShocks($shares, $shocks));
        $state->partyShortTermShocks = $shocks;

        $state->electionGrowthGap = $growthGap;
        $state->electionInflationGap = $inflationGap;
        $state->electionIncumbentSwing = self::blocShare($shares, $state->governingCoalition) - self::blocShare($previous, $state->governingCoalition);
        $state->ironHarborCrisisShift = $crisisShift;

        $swings = [];
        foreach (AerieDiet::PARTIES as $party) {
            $swings[$party] = $shares[$party] - ($previous[$party] ?? 0.0);
        }
        $state->dietVoteSwings = $swings;
        $state->dietVoteShares = $shares;

        $seats = self::dHondt($shares, AerieDiet::SEATS);
        $state->dietSeats = array_map('floatval', $seats);

        $positions = $state->partySecondaryPositions;
        foreach (AerieDiet::PARTIES as $party) {
            $positions[$party] = self::reflect(($positions[$party] ?? AerieDiet::SEED_SECONDARY_POSITIONS[$party])
                + (self::SECONDARY_DRIFT_PER_ELECTION * $this->mathUtility->generateStandardNormal()));
        }
        $state->partySecondaryPositions = $positions;

        $coalition = self::formCoalition($seats, $positions);
        $membership = [];
        foreach (AerieDiet::PARTIES as $party) {
            $membership[$party] = in_array($party, $coalition, true) ? 1.0 : 0.0;
        }
        $state->governingCoalition = $membership;
        $state->coalitionFormedAt = $state->totalTime;
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
     * Moves a swing between the governing coalition and the opposition, each side's parties in proportion to their shares.
     *
     * @param array<string, float> $shares     Vote shares by party.
     * @param array<string, float> $coalition  1.0 for a governing party.
     * @param float                $swing      Change in the coalition's combined share.
     * @return array<string, float> Vote shares by party.
     */
    public static function applyIncumbentSwing(array $shares, array $coalition, float $swing): array
    {
        $governing = self::blocShare($shares, $coalition);
        $opposition = array_sum($shares) - $governing;
        if ($governing <= 0.0 || $opposition <= 0.0) {
            return $shares;
        }

        // Neither side can lose more than it holds above the floor of its parties.
        $swing = max(-($governing - self::MIN_VOTE_SHARE), min($opposition - self::MIN_VOTE_SHARE, $swing));

        $result = [];
        foreach (AerieDiet::PARTIES as $party) {
            $share = $shares[$party] ?? 0.0;
            $result[$party] = ($coalition[$party] ?? 0.0) > 0.5
                ? $share * ($governing + $swing) / $governing
                : $share * ($opposition - $swing) / $opposition;
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
     * The government: of the minimal winning coalitions, the one with the smallest ideological range.
     *
     * A coalition wins with a majority of the Diet, and is minimal when every member is needed for it (Riker 1962).
     * Its range is the largest distance between two members in the policy space (de Swaan 1973, as Martin & Stevenson
     * 2001 measure it). Ties go to the coalition holding the largest party, then to the one with more seats.
     *
     * @param array<string, int|float>  $seats     Seats by party.
     * @param array<string, float>      $secondary Secondary-axis positions by party.
     * @return list<string> The governing parties, in PARTIES order.
     */
    public static function formCoalition(array $seats, array $secondary): array
    {
        $largest = AerieDiet::PARTIES[0];
        foreach (AerieDiet::PARTIES as $party) {
            if (($seats[$party] ?? 0) > ($seats[$largest] ?? 0)) {
                $largest = $party;
            }
        }

        $best = null;
        $bestRange = INF;
        $bestHoldsLargest = false;
        $bestSeats = 0.0;
        $count = count(AerieDiet::PARTIES);
        for ($mask = 1; $mask < (1 << $count); ++$mask) {
            $members = [];
            foreach (AerieDiet::PARTIES as $index => $party) {
                if ($mask & (1 << $index)) {
                    $members[] = $party;
                }
            }
            if (!self::isMinimalWinning($members, $seats)) {
                continue;
            }

            $range = self::ideologicalRange($members, $secondary);
            $holdsLargest = in_array($largest, $members, true);
            $total = self::coalitionSeats($members, $seats);
            $tied = abs($range - $bestRange) <= self::RANGE_TOLERANCE;
            $better = $best === null
                || $range < $bestRange - self::RANGE_TOLERANCE
                || ($tied && $holdsLargest && !$bestHoldsLargest)
                || ($tied && $holdsLargest === $bestHoldsLargest && $total > $bestSeats);
            if ($better) {
                $best = $members;
                $bestRange = $range;
                $bestHoldsLargest = $holdsLargest;
                $bestSeats = $total;
            }
        }

        return $best ?? [];
    }

    /**
     * Whether a set of parties holds a majority that every member is needed for.
     *
     * @param list<string>             $members Parties.
     * @param array<string, int|float> $seats   Seats by party.
     */
    public static function isMinimalWinning(array $members, array $seats): bool
    {
        $total = self::coalitionSeats($members, $seats);
        if ($total < AerieDiet::MAJORITY_SEATS) {
            return false;
        }
        foreach ($members as $party) {
            if ($total - ($seats[$party] ?? 0) >= AerieDiet::MAJORITY_SEATS) {
                return false;
            }
        }

        return true;
    }

    /**
     * The largest distance between two members in the policy space; zero for one party.
     *
     * @param list<string>         $members   Parties.
     * @param array<string, float> $secondary Secondary-axis positions by party.
     */
    public static function ideologicalRange(array $members, array $secondary): float
    {
        $range = 0.0;
        foreach ($members as $i => $a) {
            foreach (array_slice($members, $i + 1) as $b) {
                $pa = AerieDiet::position($a, $secondary[$a] ?? AerieDiet::SEED_SECONDARY_POSITIONS[$a]);
                $pb = AerieDiet::position($b, $secondary[$b] ?? AerieDiet::SEED_SECONDARY_POSITIONS[$b]);
                $range = max($range, hypot($pa[AerieDiet::AXIS_STATE] - $pb[AerieDiet::AXIS_STATE], $pa[AerieDiet::AXIS_OPENNESS] - $pb[AerieDiet::AXIS_OPENNESS]));
            }
        }

        return $range;
    }

    /**
     * The coalition's policy: its members' positions weighted by their seats.
     *
     * @param array<string, float> $coalition 1.0 for a governing party.
     * @param array<string, float> $seats     Seats by party.
     * @param array<string, float> $secondary Secondary-axis positions by party.
     * @return array{state: float, openness: float}
     */
    public static function coalitionPosition(array $coalition, array $seats, array $secondary): array
    {
        $weighted = [AerieDiet::AXIS_STATE => 0.0, AerieDiet::AXIS_OPENNESS => 0.0];
        $total = 0.0;
        foreach (AerieDiet::PARTIES as $party) {
            if (($coalition[$party] ?? 0.0) <= 0.5) {
                continue;
            }
            $weight = (float) ($seats[$party] ?? 0.0);
            $point = AerieDiet::position($party, $secondary[$party] ?? AerieDiet::SEED_SECONDARY_POSITIONS[$party]);
            $weighted[AerieDiet::AXIS_STATE] += $weight * $point[AerieDiet::AXIS_STATE];
            $weighted[AerieDiet::AXIS_OPENNESS] += $weight * $point[AerieDiet::AXIS_OPENNESS];
            $total += $weight;
        }
        if ($total <= 0.0) {
            return $weighted;
        }

        return [AerieDiet::AXIS_STATE => $weighted[AerieDiet::AXIS_STATE] / $total, AerieDiet::AXIS_OPENNESS => $weighted[AerieDiet::AXIS_OPENNESS] / $total];
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
     * @param array<string, float> $shares    Vote shares by party.
     * @param array<string, float> $coalition 1.0 for a governing party.
     */
    private static function blocShare(array $shares, array $coalition): float
    {
        $total = 0.0;
        foreach (AerieDiet::PARTIES as $party) {
            if (($coalition[$party] ?? 0.0) > 0.5) {
                $total += $shares[$party] ?? 0.0;
            }
        }

        return $total;
    }

    /**
     * @param list<string>             $members Parties.
     * @param array<string, int|float> $seats   Seats by party.
     */
    private static function coalitionSeats(array $members, array $seats): float
    {
        $total = 0.0;
        foreach ($members as $party) {
            $total += (float) ($seats[$party] ?? 0);
        }

        return $total;
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
