<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;

/**
 * How the District would vote if the vote were held today, and the polls that measure it.
 *
 * Support moves between votes by the same three forces the vote is made of (App\Service\Politics\PoliticsEngine), each
 * spread over the term so that on election day support is the result, drawn as it was before there were polls:
 * - each party's lasting share drifts back toward its normal vote and takes its lasting swing a little at a time, an
 *   Ornstein-Uhlenbeck process whose term-long step is exactly the vote's;
 * - the government gains or loses as the economy goes, and pays the cost of ruling and takes the residual mostly in the
 *   first year after the vote, as governing parties' polls fall from their result; each month's swing lands on the
 *   parties governing that month;
 * - each party's short-term swing builds in the final weeks of the campaign, when the polls close in on the result, and
 *   fades after the vote at the same pace.
 * The governing parties' drift toward their normal votes runs on the same front-loaded clock as their cost of ruling,
 * so their fall from the result comes mostly in the first year, as governing parties' polls do; every party drifts a
 * whole term's worth by the vote (driftYears()).
 *
 * The polls also read opinion that is not yet settled: each party's share is misread by an error every poll shares,
 * which drifts slowly and is gone by the vote, as undecided voters decide over the campaign (Jennings & Wlezien 2018).
 *
 * Within a term, support so built moves in the polls as real parties' do: German monthly polls since 1998 spread over
 * one to four years as the lasting drift does (var/harness/polls).
 *
 * The polls are monthly samples of that support, off the politics stream (CouncilAppointments::uniform()), so publishing
 * them changes nothing else in the game.
 */
final class OpinionPolls
{
    // --- After the Vote (Germany, 8 terms 1998-2025, wahlrecht.de polls; var/harness/polls/fit_cabinet_path.py) ---
    /** Half-life of the governing parties' fall from their result, in years: 9.5 months (7.3-13.3), against a straight line over the term that fits far worse; also the pace the vote's short-term swing fades, which the polls show keeping for at least three months. */
    public const POST_VOTE_HALF_LIFE_YEARS = 9.5 / 12.0;

    // --- The Campaign (Jennings & Wlezien 2018 data, PR legislative elections, single polls; var/harness/polls/fit_campaign.py) ---
    /** Days before the vote in which the short-term swing builds: a poll's error on the result falls fastest over the final 20 days (30 fits nearly as well). */
    public const CAMPAIGN_SWING_DAYS = 20.0;

    // --- Unsettled Opinion (Jennings & Wlezien 2018 data; Germany 1998-2026) ---
    /** Error in a poll's read of the result, beyond its sampling error and the vote's short-term swing, that settles over the campaign as undecided voters decide: the 52 units (poll - vote)^2 / p(1 - p) x 1e4 a poll's error falls by over the final 20 days, less the 15 the short-term swing moves (same fit), so 37e-4 of p(1 - p). Opinion the vote never shows, so it enters the polls alone. */
    public const UNSETTLED_VARIANCE = (52.0 - 15.0) * 1.0e-4;
    /** Half-life of that unsettled opinion, in years: the 28 months of a single Ornstein-Uhlenbeck fitted to German monthly poll averages, 1998-2026, which show no faster component of any size (var/harness/polls/de_poll_dynamics.out). */
    public const UNSETTLED_HALF_LIFE_YEARS = 28.0 / 12.0;

    // --- Published Polls ---
    /** Polls published a year: one a month. */
    public const POLLS_PER_YEAR = 12.0;
    /** Sample size a published poll's error on the truth amounts to: 440 from the same fit, so a poll of 1,000 (the record's median) misses by 1.5 times its sampling error, the excess an error every poll of the election shares. */
    public const EFFECTIVE_SAMPLE_SIZE = 440.0;

    /**
     * One tick: at each month's turn, support is brought up to date and a poll published. The vote publishes none; it is
     * the result.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     */
    public static function advance(PoliticsState $state, MacroStateDTO $macro, float $dt, MathUtility $math): void
    {
        if ($state->lastElectionAt === $state->totalTime || !TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, 1.0 / self::POLLS_PER_YEAR)) {
            return;
        }

        self::update($state, $macro, $math);
        if ($state->authoritySalt >= 0.0) {
            self::publish($state, (int) $state->authoritySalt, $math);
        }
    }

    /**
     * Brings support up to this tick: the lasting shares and the short-term swings move on by the time since the last
     * update, and the government takes the swing the economy, the cost of ruling and the residual gave it meanwhile.
     */
    public static function update(PoliticsState $state, MacroStateDTO $macro, MathUtility $math): void
    {
        if ($state->supportUpdatedAt < 0.0 || $state->supportLasting === []) {
            self::open($state);
        }
        $years = $state->totalTime - $state->supportUpdatedAt;
        if ($years <= 0.0) {
            return;
        }

        $lastingDraws = [];
        $shortTermDraws = [];
        $normalDraws = [];
        foreach (AerieDiet::PARTIES as $party) {
            $lastingDraws[$party] = $math->generateStandardNormal();
        }
        foreach (AerieDiet::PARTIES as $party) {
            $shortTermDraws[$party] = $math->generateStandardNormal();
        }
        foreach (AerieDiet::PARTIES as $party) {
            $normalDraws[$party] = $math->generateStandardNormal();
        }
        $drift = self::driftYears($state, $state->supportUpdatedAt, $state->totalTime);
        foreach ($drift as $party => $partyYears) {
            $state->supportDriftYears[$party] = ($state->supportDriftYears[$party] ?? self::elapsedInTerm($state, $state->supportUpdatedAt)) + $partyYears;
        }
        [$state->normalVoteShares, $carried] = self::walkNormals($state->normalVoteShares, $state->supportLasting, $years, $normalDraws);
        $state->supportLasting = self::driftLasting($carried, $drift, $lastingDraws, $state->normalVoteShares, $math);
        $state->partyShortTermShocks = self::fadeShortTerm($state->partyShortTermShocks, $years, self::campaignOverlap($state->supportUpdatedAt, $state->totalTime), $shortTermDraws, $state->normalVoteShares, $math);

        $growth = self::growthCounted($state, $macro);
        $inflation = self::inflationCounted($state, $macro);
        $swing = PoliticsEngine::economicVote(
            $growth - $state->supportGrowthCounted,
            $inflation - $state->supportInflationCounted,
            $math->generateStandardNormal(),
            count(AerieDiet::governingParties($state->governingCoalition)) === 1,
            self::termClock($state->totalTime - $state->termStartedAt) - self::termClock($state->supportUpdatedAt - $state->termStartedAt)
        );
        $base = self::lastingSupport($state);
        $moved = PoliticsEngine::applyIncumbentSwing($base, $state->governingCoalition, $swing, $state->supportParties);
        foreach (AerieDiet::PARTIES as $party) {
            $state->supportIncumbency[$party] = ($state->supportIncumbency[$party] ?? 0.0) + log($moved[$party] / $base[$party]);
        }

        $state->supportGrowthCounted = $growth;
        $state->supportInflationCounted = $inflation;
        $state->supportUpdatedAt = $state->totalTime;
    }

    /**
     * The vote has been held on today's support: the government's swing this term becomes part of each party's lasting
     * share, the voters start counting the new term afresh, and the polls of the old one are put away.
     */
    public static function settle(PoliticsState $state): void
    {
        $state->supportLasting = self::lastingSupport($state);
        $state->supportIncumbency = [];
        $state->supportDriftYears = [];
        $state->supportGrowthCounted = 0.0;
        $state->supportInflationCounted = 0.0;
        $state->polls = [];
    }

    /**
     * Each party's share if the vote were held today: its lasting share, moved by its government's swing this term and
     * by its short-term swing (additive logistic form, Katz & King 1999).
     *
     * @return array<string, float>
     */
    public static function support(PoliticsState|PoliticsStateDTO $state): array
    {
        return PoliticsEngine::applyShortTermShocks(self::lastingSupport($state), $state->partyShortTermShocks);
    }

    /**
     * Each party's lasting share a term of the Ornstein-Uhlenbeck drift later (or any part of one): its log share
     * against its normal vote keeps the persistence real parties show over the span and takes the part of a term's
     * lasting swing that falls in it, so that spans adding to a term give the vote's term-long reversion and lasting
     * swing exactly (PoliticsEngine::LASTING_SWING_VARIANCE).
     *
     * @param array<string, float> $lasting Lasting shares by party.
     * @param array<string, float> $years   Years of drift by party (driftYears()).
     * @param array<string, float> $draws   Standard normal draw by party.
     * @param array<string, float> $normals Normal votes by party.
     * @return array<string, float>
     */
    public static function driftLasting(array $lasting, array $years, array $draws, array $normals, MathUtility $math): array
    {
        $termPersistence = PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE ** PoliticsEngine::ELECTION_TERM_YEARS;

        $moved = [];
        foreach (AerieDiet::PARTIES as $party) {
            $persistence = PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE ** ($years[$party] ?? 0.0);
            $normal = $normals[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party];
            $gap = log(max(PoliticsEngine::MIN_VOTE_SHARE, $lasting[$party] ?? $normal) / $normal);
            // The spread the lasting swing holds a party at, around its normal vote: a term's swing over what a term
            // takes away.
            $spread = sqrt(PoliticsEngine::LASTING_SWING_VARIANCE / $normal / (1.0 - ($termPersistence ** 2)));
            $moved[$party] = $normal * exp(($persistence * $gap) + ($spread * StochasticProcesses::persistentInnovationScale($persistence) * ($draws[$party] ?? 0.0)));
        }
        $total = array_sum($moved);

        return array_map(static fn(float $share): float => $share / $total, $moved);
    }

    /**
     * Each party's normal vote some years on, and its lasting share carried with it. A normal vote drifts as a share
     * does under neutral drift (Kimura 1955): a step with no drift of its own whose variance grows with the years and
     * with the share, so its log moves with the variance over the share the West's votes show
     * (PoliticsEngine::NORMAL_VOTE_DRIFT_VARIANCE); the normals are then put back on a whole. The lasting share moves
     * with its normal, so a party's lead or deficit on its normal vote decays as before while the normal shifts under
     * it: the random walk and the lasting swing add, as fitted.
     *
     * @param array<string, float> $normals Normal votes by party.
     * @param array<string, float> $lasting Lasting shares by party (empty: none to carry).
     * @param array<string, float> $draws   Standard normal draw by party.
     * @return array{0: array<string, float>, 1: array<string, float>} The normal votes and the lasting shares carried.
     */
    public static function walkNormals(array $normals, array $lasting, float $years, array $draws): array
    {
        $walked = [];
        foreach (AerieDiet::PARTIES as $party) {
            $normal = $normals[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party];
            $step = sqrt(PoliticsEngine::NORMAL_VOTE_DRIFT_VARIANCE * max(0.0, $years) * $normal) * ($draws[$party] ?? 0.0);
            $walked[$party] = max(PoliticsEngine::MIN_VOTE_SHARE, $normal + $step);
        }
        $total = array_sum($walked);
        $walked = array_map(static fn(float $share): float => $share / $total, $walked);
        if ($lasting === []) {
            return [$walked, []];
        }

        $carried = [];
        foreach (AerieDiet::PARTIES as $party) {
            $carried[$party] = ($lasting[$party] ?? 0.0) * $walked[$party] / ($normals[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party]);
        }
        $total = array_sum($carried);

        return [$walked, array_map(static fn(float $share): float => $share / $total, $carried)];
    }

    /**
     * Each party's short-term swing some time later: faded at its half-life, and in the campaign's final days built
     * toward the vote's swing, so that spans of the campaign adding to the whole of it give the vote's swing exactly
     * (PoliticsEngine::ELECTION_SHORT_TERM_SWING_VARIANCE), however the campaign is cut.
     *
     * @param float                $campaignYears The part of the span inside the campaign's final days.
     * @param array<string, float> $swings        Short-term log swing by party.
     * @param array<string, float> $draws         Standard normal draw by party.
     * @param array<string, float> $normals       Normal votes by party, which size each party's swing.
     * @return array<string, float>
     */
    public static function fadeShortTerm(array $swings, float $years, float $campaignYears, array $draws, array $normals, MathUtility $math): array
    {
        $fade = self::fadeOver($years);
        $build = StochasticProcesses::persistentInnovationScale(self::fadeOver($campaignYears))
            / StochasticProcesses::persistentInnovationScale(self::fadeOver(self::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR));

        $faded = [];
        foreach (AerieDiet::PARTIES as $party) {
            $faded[$party] = ($fade * ($swings[$party] ?? 0.0))
                + (PoliticsEngine::swingSd(PoliticsEngine::ELECTION_SHORT_TERM_SWING_VARIANCE, $normals[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party]) * $build * ($draws[$party] ?? 0.0));
        }

        return $faded;
    }

    /**
     * Share of the term's cost of ruling and residual the government has taken by this far into the term: rising fast
     * after the vote and slowing at the half-life governing parties' polls fall at, the whole of it by the next vote.
     */
    public static function termClock(float $yearsIntoTerm): float
    {
        $years = max(0.0, min(PoliticsEngine::ELECTION_TERM_YEARS, $yearsIntoTerm));

        return (1.0 - self::fadeOver($years)) / (1.0 - self::fadeOver(PoliticsEngine::ELECTION_TERM_YEARS));
    }

    /**
     * Each party's years of lasting drift over a span: of the drift its term has left, a governing party takes the part
     * of the term clock the span covers (termClock()), any other the part of the calendar, so a party governing from vote
     * to vote falls from its result mostly in the first year, and every party has drifted exactly a term's worth by the
     * vote however its standing changed.
     *
     * @return array<string, float>
     */
    public static function driftYears(PoliticsState|PoliticsStateDTO $state, float $from, float $to): array
    {
        $term = PoliticsEngine::ELECTION_TERM_YEARS;
        $start = max(0.0, $from - $state->termStartedAt);
        $end = $to - $state->termStartedAt;
        if ($state->termStartedAt < 0.0 || $start >= $term || $end <= $start) {
            return array_fill_keys(AerieDiet::PARTIES, max(0.0, $to - $from));
        }

        $governing = AerieDiet::governingParties($state->governingCoalition);
        $clockLeft = 1.0 - self::termClock($start);
        $governingShare = $clockLeft > 0.0 ? (self::termClock($end) - self::termClock($start)) / $clockLeft : 1.0;
        $calendarShare = (min($end, $term) - $start) / ($term - $start);

        $years = [];
        foreach (AerieDiet::PARTIES as $party) {
            $left = max(0.0, $term - ($state->supportDriftYears[$party] ?? $start));
            $years[$party] = ($left * (in_array($party, $governing, true) ? $governingShare : $calendarShare)) + max(0.0, $end - $term);
        }

        return $years;
    }

    /** Years into the term at a moment, as the drift counted them before parties kept their own (a state saved earlier). */
    private static function elapsedInTerm(PoliticsState $state, float $at): float
    {
        return $state->termStartedAt < 0.0 ? 0.0 : max(0.0, min(PoliticsEngine::ELECTION_TERM_YEARS, $at - $state->termStartedAt));
    }

    /**
     * How much of a span from $from to $to falls in the final days of the campaign for the vote it runs toward.
     */
    public static function campaignOverlap(float $from, float $to): float
    {
        $term = PoliticsEngine::ELECTION_TERM_YEARS;
        // The vote a step ending a hair either side of the term's end is run toward is that term's.
        $vote = ceil(($to / $term) - 1e-6) * $term;
        $opens = $vote - (self::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR);

        return max(0.0, min($to, $vote) - max($from, $opens));
    }

    /** What is left after this long of something fading at the post-vote half-life. */
    private static function fadeOver(float $years): float
    {
        return 0.5 ** ($years / self::POST_VOTE_HALF_LIFE_YEARS);
    }

    /**
     * Growth per head over the campaign so far, less its trend, as a share of the growth the vote will weigh: zero before
     * the campaign opens, the vote's growth gap on the day (PoliticsEngine::holdElection()).
     */
    public static function growthCounted(PoliticsState $state, MacroStateDTO $macro): float
    {
        if ($state->campaignStartedAt <= $state->termStartedAt || $state->campaignStartRealGdp <= 0.0) {
            return 0.0;
        }
        $years = $state->totalTime - $state->campaignStartedAt;

        return (log(PoliticsEngine::realGdp($macro) / $state->campaignStartRealGdp) - (($macro->laborForceGrowthRate + MacroEngine::TFP_DRIFT) * $years))
            / PoliticsEngine::ELECTION_CAMPAIGN_WINDOW_YEARS;
    }

    /**
     * Inflation over the term so far above the target, as a share of the inflation the vote will weigh: zero as the term
     * opens, the vote's inflation gap on the day.
     */
    public static function inflationCounted(PoliticsState $state, MacroStateDTO $macro): float
    {
        if ($state->termStartDeflator <= 0.0 || $macro->consumerPriceLevel <= 0.0) {
            return 0.0;
        }
        $years = $state->totalTime - $state->termStartedAt;

        return (log($macro->consumerPriceLevel / $state->termStartDeflator) - (MacroEngine::TARGET_INFLATION * $years)) / PoliticsEngine::ELECTION_TERM_YEARS;
    }

    /**
     * A poll of support: shares as a sample of the given size would find them, each party's error drawn so the errors
     * sum to nothing and have the multinomial's covariance, p(1 - p) / n on each party; a party the draw puts below
     * nothing polls nothing.
     *
     * @param array<string, float> $support Support by party.
     * @param array<string, float> $draws   Standard normal draw by party.
     * @return array<string, float>
     */
    public static function sample(array $support, array $draws, float $sampleSize): array
    {
        $common = 0.0;
        foreach (AerieDiet::PARTIES as $party) {
            $common += sqrt(($support[$party] ?? 0.0) / $sampleSize) * ($draws[$party] ?? 0.0);
        }

        $polled = [];
        foreach (AerieDiet::PARTIES as $party) {
            $share = $support[$party] ?? 0.0;
            $polled[$party] = max(0.0, $share + (sqrt($share / $sampleSize) * ($draws[$party] ?? 0.0)) - ($share * $common));
        }
        $total = array_sum($polled);

        return array_map(static fn(float $share): float => $share / $total, $polled);
    }

    /** Publishes this month's poll. */
    private static function publish(PoliticsState $state, int $salt, MathUtility $math): void
    {
        $key = CouncilAppointments::vacancyKey('poll', $state->totalTime);
        $persistence = 0.5 ** (1.0 / self::POLLS_PER_YEAR / self::UNSETTLED_HALF_LIFE_YEARS);
        $support = self::support($state);
        $yearsToVote = ElectionForecast::nextVote($state->totalTime) - $state->totalTime;
        $draws = [];
        $misread = [];
        foreach (AerieDiet::PARTIES as $party) {
            $draws[$party] = Distributions::calculateInverseNormalCDF(CouncilAppointments::uniform($salt, "{$key}:{$party}"));
            $innovation = Distributions::calculateInverseNormalCDF(CouncilAppointments::uniform($salt, "{$key}:{$party}:unsettled"));
            $state->pollUnsettled[$party] = isset($state->pollUnsettled[$party])
                ? ($persistence * $state->pollUnsettled[$party]) + (sqrt(1.0 - ($persistence ** 2)) * $innovation)
                : $innovation;
            $misread[$party] = self::unsettledMisreading($support[$party] ?? 0.0, $yearsToVote, $state->pollUnsettled[$party]);
        }

        // Kept to a hundredth of a point, finer than any poll is reported, so a term of them stays small in the state.
        $state->polls[] = ['t' => $state->totalTime, 'shares' => array_map(static fn(float $share): float => round($share, 4), self::sample(PoliticsEngine::applyShortTermShocks($support, $misread), $draws, self::EFFECTIVE_SAMPLE_SIZE))];
    }

    /**
     * Unsettled opinion's misreading of a party's log share for a standard normal draw: its spread times the draw, less
     * half its variance, so the misread share is the share on average and no party is read high or low by it.
     */
    public static function unsettledMisreading(float $share, float $yearsToVote, float $draw): float
    {
        $sd = self::unsettledSd($share, $yearsToVote);

        return ($sd * $draw) - (0.5 * $sd * $sd);
    }

    /**
     * How far unsettled opinion misreads a party's log share this long before the vote: the whole of it until the final
     * days, closing over them to nothing on the day, as a poll's error on the result does (Jennings & Wlezien 2018).
     */
    public static function unsettledSd(float $share, float $yearsToVote): float
    {
        $share = max(PoliticsEngine::MIN_VOTE_SHARE, $share);
        $settling = min(1.0, max(0.0, $yearsToVote) / (self::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR));

        return sqrt(self::UNSETTLED_VARIANCE * (1.0 - $share) / $share * $settling);
    }

    /**
     * Support as the last vote left it, for a state that has none: the result with its short-term swings set aside, the
     * term counted from its start.
     */
    private static function open(PoliticsState $state): void
    {
        $state->supportLasting = self::lastingAtTheVote($state);
        $state->supportIncumbency = [];
        $state->supportGrowthCounted = 0.0;
        $state->supportInflationCounted = 0.0;
        $state->supportUpdatedAt = $state->termStartedAt >= 0.0 ? min($state->termStartedAt, $state->totalTime) : $state->totalTime;
    }

    /**
     * Each party's lasting share moved by its government's swing this term.
     *
     * @return array<string, float>
     */
    private static function lastingSupport(PoliticsState|PoliticsStateDTO $state): array
    {
        return PoliticsEngine::applyShortTermShocks($state->supportLasting === [] ? self::lastingAtTheVote($state) : $state->supportLasting, $state->supportIncumbency);
    }

    /**
     * The last vote's result with its short-term swings set aside.
     *
     * @return array<string, float>
     */
    private static function lastingAtTheVote(PoliticsState|PoliticsStateDTO $state): array
    {
        return PoliticsEngine::applyShortTermShocks($state->dietVoteShares, array_map(static fn(float $swing): float => -$swing, $state->partyShortTermShocks));
    }
}
