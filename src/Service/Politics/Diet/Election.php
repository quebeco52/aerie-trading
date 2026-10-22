<?php

declare(strict_types=1);

namespace App\Service\Politics\Diet;

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsState;

/**
 * The Diet's vote: each party's support on the day as the polls have built it, the government's economic vote and cost
 * of ruling, D'Hondt seats, the parties' positions moving between votes, and the hazard a cabinet falls between them.
 */
final class Election
{
    // --- The Diet's Election ---
    /** Length of the fixed electoral term in years: the vote falls on the tick each term ends. */
    public const TERM_YEARS = 4.0;
    /** Years at the end of the term whose growth voters weigh (Fair: the election year's first three quarters). */
    public const CAMPAIGN_WINDOW_YEARS = 0.75;
    /**
     * Vote share a party governing alone gains per unit of growth per head above trend in the year to the vote; a
     * coalition shares less of it (Powell & Whitten 1993, clarity of responsibility).
     */
    public const SINGLE_PARTY_GROWTH_SLOPE = 0.506;
    /**
     * Vote share the party leading a coalition gains per unit of growth above trend, half a lone party's; its partners
     * and supporters gain none (Duch & Falco-Gimeno 2021, Table 2; Hobolt, Tilley & Banducci 2013).
     */
    public const COALITION_PRIME_MINISTER_GROWTH_SLOPE = 0.5 * self::SINGLE_PARTY_GROWTH_SLOPE;
    /** Vote share any cabinet loses per unit of annualised inflation above target over the term (ParlGov). */
    public const INFLATION_SLOPE = 0.100;
    /**
     * Standard deviation of the government's swing that the economy does not explain, common to its parties (Fair);
     * each party's own swings are drawn on top, sized with it to the Nordic record.
     */
    public const RESIDUAL_SD = 0.0295;
    /** Vote share the average government loses a term (Nannestad & Paldam 2002), the target for COST_OF_RULING. */
    public const RECORDED_COST_OF_RULING = 0.0225;
    /**
     * Vote share governing costs the governing parties each term, beyond what they were elected on running off; fitted
     * so outgoing cabinets lose RECORDED_COST_OF_RULING a term in all.
     */
    public const COST_OF_RULING = 0.005;
    /**
     * Each party's short-term swing at a vote, gone by the next (Converse 1966), as the variance of its log share times
     * its normal vote: a party of a quarter swings 7% either way, one of a twentieth 15% (ParlGov, Nordic).
     */
    public const SHORT_TERM_SWING_VARIANCE = 0.0012;
    /** Share of a party's lasting gap to its normal vote left a year on, a seven-year half-life (ParlGov, Nordic). */
    public const NORMAL_VOTE_PERSISTENCE = 0.905;
    /**
     * Share of a cabinet party's electoral cost of governing a support party bears: its loss over the mean of the prime
     * minister's party's and a junior partner's (Thürk & Klüver 2024).
     */
    public const SUPPORT_ACCOUNTABILITY = 1.991 / ((2.815 + 2.799) / 2.0);

    // --- Cabinets Falling Between Votes ---
    /** Yearly hazard a single-party minority cabinet falls between votes (ParlGov; King, Alt, Burns & Laver 1990). */
    public const CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY = 0.106;
    /** Yearly hazard a minority coalition falls between votes, twice a single party's: a partner can walk out too. */
    public const CABINET_FALL_HAZARD_MINORITY_COALITION = 0.216;
    /** Yearly hazard a majority coalition falls between votes; a party with its own majority never does (ParlGov). */
    public const CABINET_FALL_HAZARD_MAJORITY_COALITION = 0.119;
    /**
     * How a coalition's hazard answers the economy sliding: times exp(-6 x the output gap's fall over the year), 1.2
     * through a year the gap loses 3 points (Walther, Hellstrom & Bergman 2019, rising unemployment). A low level of
     * the gap does not move it (Parliamentary Affairs 2024), nor a single party's, whose risks lie elsewhere.
     */
    public const CABINET_FALL_GAP_SLIDE_RESPONSE = 6.0;

    // --- Election Uncertainty ---
    /** Days a term that talks after a fall hold the election pulse above its ramp. */
    public const FALL_TALK_DAYS_PER_TERM = 19.5;
    /** Long-run mean nearness to the vote: the final-year ramp's half year a term, plus the talks held at the peak. */
    public const MEAN_ELECTION_PROXIMITY = (0.5 + ((CoalitionFormation::FORMATION_MEAN_DAYS + self::FALL_TALK_DAYS_PER_TERM) / FinancialConstants::DAYS_PER_YEAR)) / self::TERM_YEARS;

    // --- Party Positions ---
    /**
     * Share of a party's distance from its home still there a year later, by axis (Chapel Hill expert survey: economic
     * left-right, European integration, anti-elite rhetoric, and environment against growth).
     */
    public const POSITION_ANNUAL_PERSISTENCE = [AerieDiet::AXIS_STATE => 0.872, AerieDiet::AXIS_OPENNESS => 0.958, AerieDiet::AXIS_COUNCIL => 0.846, AerieDiet::AXIS_ENVIRONMENT => 0.933];
    /** Standard deviation of a party's position around its home, by axis (Chapel Hill; a full scale is two units). */
    public const POSITION_WITHIN_SD = [AerieDiet::AXIS_STATE => 0.125, AerieDiet::AXIS_OPENNESS => 0.265, AerieDiet::AXIS_COUNCIL => 0.188, AerieDiet::AXIS_ENVIRONMENT => 0.170];

    // --- Vote Shares ---
    /** Smallest vote share a party is carried at, so a collapse leaves it a rump rather than a negative share. */
    public const MIN_VOTE_SHARE = 0.005;
    /**
     * Each party's lasting swing a term, as the variance of its log share times its normal vote: fitted with the
     * government's swings to the Nordic spread (ParlGov), less what the normal vote's drift adds over a term.
     */
    public const LASTING_SWING_VARIANCE = 0.0118 - (self::TERM_YEARS * self::NORMAL_VOTE_DRIFT_VARIANCE / 2.0
        * (1.0 - (self::NORMAL_VOTE_PERSISTENCE ** (2.0 * self::TERM_YEARS))) / (1.0 - (self::NORMAL_VOTE_PERSISTENCE ** self::TERM_YEARS)));
    /**
     * Yearly variance of a party's log normal vote times its normal vote, beyond its lasting swing: fitted with its
     * half-life to West European parties' semivariogram over 10-55 years (ParlGov), so realignments build over decades.
     */
    public const NORMAL_VOTE_DRIFT_VARIANCE = 1.12e-3;
    /**
     * Half-life of a party's normal vote's distance from its founding share: realignments last decades, but the party
     * system does not concentrate without end as it would under neutral drift.
     */
    public const NORMAL_VOTE_HALF_LIFE_YEARS = 40.0;

    /**
     * Variance of a normal vote's drift over some years, as a share of the normal vote: the yearly variance summed
     * with the pull back toward the founding share undoing it as it builds (an Ornstein-Uhlenbeck spread).
     */
    public static function normalVoteDriftVariance(float $years): float
    {
        $pull = 2.0 * M_LN2 / self::NORMAL_VOTE_HALF_LIFE_YEARS;

        return self::NORMAL_VOTE_DRIFT_VARIANCE * (1.0 - exp(-$pull * max(0.0, $years))) / $pull;
    }

    /**
     * Votes, seats and positions: the vote is support on the day, seated by D'Hondt, and each party's position moves
     * on the questions it is not defined by.
     */
    public static function hold(PoliticsState $state, MacroStateDTO $macro, float $realGdp, MathUtility $math): void
    {
        $previous = $state->dietVoteShares;
        // The vote is support on the day, as the polls have sampled it over the term (OpinionPolls).
        OpinionPolls::update($state, $macro, $math);
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
                        $math->generateStandardNormal(),
                    );
                }
            }
        }
        $state->partyPositions = $positions;
    }

    /**
     * The election calendar's pull on policy uncertainty, centred on its long-run mean: nearness to the vote rises over
     * the final year (Julio & Yook 2012) and holds at its peak on the day and through the talks after it or after a
     * fall, since only a seated government settles the regime (Bernhard & Leblang 2006).
     *
     * @param float $coalitionTakesOfficeAt When the talks' cabinet takes office (-1: no talks pending).
     */
    public static function pulse(float $totalTime, float $lastElectionAt, float $coalitionTakesOfficeAt): float
    {
        $yearsToElection = self::TERM_YEARS - fmod($totalTime, self::TERM_YEARS);
        $proximity = ($lastElectionAt === $totalTime || $coalitionTakesOfficeAt > $totalTime) ? 1.0 : max(0.0, 1.0 - $yearsToElection);

        return $proximity - self::MEAN_ELECTION_PROXIMITY;
    }

    /**
     * Yearly hazard a cabinet falls between votes: a coalition can lose a partner and a minority cabinet its
     * supporters; a party governing alone with its own majority has neither to lose.
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
     * The government's change in vote share: the economy as PR electorates weigh it, less the cost of ruling, plus the
     * residual. Inflation costs every cabinet; growth counts here only for a party governing alone (Powell & Whitten
     * 1993), and in a coalition goes to the prime minister's party (primeMinisterGrowthVote()).
     * Over part of a term the cost accrues pro rata and the residual as a random walk, so parts add to the whole.
     *
     * @param float $growthGap    Annualised real growth per head over the campaign, less trend.
     * @param float $inflationGap Annualised inflation over the term, less target.
     * @param float $residualDraw Standard normal draw.
     * @param bool  $alone        Whether one party forms the cabinet.
     * @param float $termShare    Share of the term the swing covers.
     */
    public static function economicVote(float $growthGap, float $inflationGap, float $residualDraw, bool $alone, float $termShare = 1.0): float
    {
        return ($alone ? self::SINGLE_PARTY_GROWTH_SLOPE * $growthGap : 0.0)
            - (self::INFLATION_SLOPE * $inflationGap)
            - (self::COST_OF_RULING * $termShare)
            + (self::RESIDUAL_SD * sqrt($termShare) * $residualDraw);
    }

    /**
     * The prime minister's party's own change in vote share in a coalition: growth above trend at
     * COALITION_PRIME_MINISTER_GROWTH_SLOPE, taken from every other party. None for a party governing alone, whose
     * growth vote is the government's (economicVote()).
     *
     * @param float $growthGap Annualised real growth per head over the campaign, less trend.
     * @param bool  $alone     Whether one party forms the cabinet.
     */
    public static function primeMinisterGrowthVote(float $growthGap, bool $alone): float
    {
        return $alone ? 0.0 : self::COALITION_PRIME_MINISTER_GROWTH_SLOPE * $growthGap;
    }

    /**
     * How much faster a cabinet is falling than its fitted hazard says, as the economy slides: a coalition's hazard
     * scaled by CABINET_FALL_GAP_SLIDE_RESPONSE on the output gap's change over the year, a single party's unmoved.
     *
     * @param list<string> $cabinet   The cabinet's parties.
     * @param float        $gapChange The output gap less its level a year earlier.
     */
    public static function fallHazardScale(array $cabinet, float $gapChange): float
    {
        return count($cabinet) > 1 ? exp(-self::CABINET_FALL_GAP_SLIDE_RESPONSE * $gapChange) : 1.0;
    }

    /**
     * Moves a swing between the government and the opposition, each side's parties in proportion to their shares. A
     * support party sits on the government's side by SUPPORT_ACCOUNTABILITY, so it moves with it by that much less.
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
     * Standard deviation of a party's log-share swing: the variance scale over its normal vote, as shares vary in
     * proportion to their size.
     *
     * @param float $varianceScale Variance of the log share times the normal vote.
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
     * Total electoral volatility (Pedersen 1979): half the sum of the parties' absolute changes in share.
     *
     * @param array<string, float> $swings Change in vote share by party.
     */
    public static function pedersenVolatility(array $swings): float
    {
        return 0.5 * array_sum(array_map('abs', $swings));
    }

    /** Real GDP as an index: potential times the gap. */
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
            ($support[$party] ?? 0.0) > 0.5 => self::SUPPORT_ACCOUNTABILITY,
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
     * @param float $position Its position at the last vote.
     * @param float $home     Its home on the axis.
     * @param float $draw     Standard normal draw.
     */
    public static function movePosition(float $position, float $home, string $axis, float $draw): float
    {
        $persistence = self::POSITION_ANNUAL_PERSISTENCE[$axis] ** self::TERM_YEARS;

        return self::reflect($home + ($persistence * ($position - $home))
            + (self::POSITION_WITHIN_SD[$axis] * sqrt(1.0 - ($persistence ** 2)) * $draw));
    }

    /** Reflects a position back inside the axis at either end. */
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
