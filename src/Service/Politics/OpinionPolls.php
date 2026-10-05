<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

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
        if ($state->lastElectionAt === $state->totalTime || !MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, 1.0 / self::POLLS_PER_YEAR)) {
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
        foreach (AerieDiet::PARTIES as $party) {
            $lastingDraws[$party] = $math->generateStandardNormal();
        }
        foreach (AerieDiet::PARTIES as $party) {
            $shortTermDraws[$party] = $math->generateStandardNormal();
        }
        $state->supportLasting = self::driftLasting($state->supportLasting, $years, $lastingDraws, $math);
        $state->partyShortTermShocks = self::fadeShortTerm($state->partyShortTermShocks, $years, self::campaignOverlap($state->supportUpdatedAt, $state->totalTime), $shortTermDraws, $math);

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
     * @param array<string, float> $draws   Standard normal draw by party.
     * @return array<string, float>
     */
    public static function driftLasting(array $lasting, float $years, array $draws, MathUtility $math): array
    {
        $persistence = PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE ** $years;
        $termPersistence = PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE ** PoliticsEngine::ELECTION_TERM_YEARS;

        $moved = [];
        foreach (AerieDiet::PARTIES as $party) {
            $normal = AerieDiet::SEED_VOTE_SHARES[$party];
            $gap = log(max(PoliticsEngine::MIN_VOTE_SHARE, $lasting[$party] ?? $normal) / $normal);
            // The spread the lasting swing holds a party at, around its normal vote: a term's swing over what a term
            // takes away.
            $spread = sqrt(PoliticsEngine::LASTING_SWING_VARIANCE / $normal / (1.0 - ($termPersistence ** 2)));
            $moved[$party] = $normal * exp(($persistence * $gap) + ($spread * $math->persistentInnovationScale($persistence) * ($draws[$party] ?? 0.0)));
        }
        $total = array_sum($moved);

        return array_map(static fn(float $share): float => $share / $total, $moved);
    }

    /**
     * Each party's short-term swing some time later: faded at its half-life, and in the campaign's final days built
     * toward the vote's swing, so that spans of the campaign adding to the whole of it give the vote's swing exactly
     * (PoliticsEngine::ELECTION_SHORT_TERM_SWING_VARIANCE), however the campaign is cut.
     *
     * @param float                $campaignYears The part of the span inside the campaign's final days.
     * @param array<string, float> $swings        Short-term log swing by party.
     * @param array<string, float> $draws         Standard normal draw by party.
     * @return array<string, float>
     */
    public static function fadeShortTerm(array $swings, float $years, float $campaignYears, array $draws, MathUtility $math): array
    {
        $fade = self::fadeOver($years);
        $build = $math->persistentInnovationScale(self::fadeOver($campaignYears))
            / $math->persistentInnovationScale(self::fadeOver(self::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR));

        $faded = [];
        foreach (AerieDiet::PARTIES as $party) {
            $faded[$party] = ($fade * ($swings[$party] ?? 0.0))
                + (PoliticsEngine::swingSd(PoliticsEngine::ELECTION_SHORT_TERM_SWING_VARIANCE, $party) * $build * ($draws[$party] ?? 0.0));
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
        if ($state->termStartDeflator <= 0.0 || $macro->gdpDeflator <= 0.0) {
            return 0.0;
        }
        $years = $state->totalTime - $state->termStartedAt;

        return (log($macro->gdpDeflator / $state->termStartDeflator) - (MacroEngine::TARGET_INFLATION * $years)) / PoliticsEngine::ELECTION_TERM_YEARS;
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
        $draws = [];
        foreach (AerieDiet::PARTIES as $party) {
            $draws[$party] = $math->calculateInverseNormalCDF(CouncilAppointments::uniform($salt, "{$key}:{$party}"));
        }

        // Kept to a hundredth of a point, finer than any poll is reported, so a term of them stays small in the state.
        $state->polls[] = ['t' => $state->totalTime, 'shares' => array_map(static fn(float $share): float => round($share, 4), self::sample(self::support($state), $draws, self::EFFECTIVE_SAMPLE_SIZE))];
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
