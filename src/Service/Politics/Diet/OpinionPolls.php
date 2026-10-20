<?php

declare(strict_types=1);

namespace App\Service\Politics\Diet;

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * How the District would vote today, and the monthly polls that measure it. Support moves by the vote's own forces
 * spread over the term, so on the day it is the result (Election): lasting shares drift toward the normal vote as an
 * Ornstein-Uhlenbeck process, the government moves with the economy and pays its cost of ruling mostly in the first
 * year, and the short-term swing builds in the campaign's final days. Polls also misread unsettled opinion that is gone
 * by the vote (Jennings & Wlezien 2018); they draw off the politics stream (MathUtility::hashedUniform()), so
 * publishing them changes nothing else in the game.
 */
final class OpinionPolls
{
    // --- After the Vote ---
    /**
     * Half-life of the governing parties' fall from their result, in years: 9.5 months (German polls since 1998); also
     * the pace the vote's short-term swing fades.
     */
    public const POST_VOTE_HALF_LIFE_YEARS = 9.5 / 12.0;

    // --- The Campaign ---
    /** Final days before the vote in which the short-term swing builds and polls close in (Jennings & Wlezien 2018). */
    public const CAMPAIGN_SWING_DAYS = 20.0;

    // --- Unsettled Opinion ---
    /**
     * Variance of a poll's misreading, as a share of p(1 - p), that settles over the campaign as undecided voters
     * decide: the fall in polls' squared error over the final days (52e-4) less the short-term swing's part (15e-4). It
     * enters the polls, never the vote.
     */
    public const UNSETTLED_VARIANCE = (52.0 - 15.0) * 1.0e-4;
    /** Half-life of unsettled opinion, in years: 28 months (an Ornstein-Uhlenbeck fit to German monthly polls). */
    public const UNSETTLED_HALF_LIFE_YEARS = 28.0 / 12.0;

    // --- Published Polls ---
    /** Polls published a year: one a month. */
    public const POLLS_PER_YEAR = 12.0;
    /** Sample size a poll's error on the truth amounts to: a poll of 1,000 misses by 1.5 times its sampling error. */
    public const EFFECTIVE_SAMPLE_SIZE = 440.0;

    /**
     * One tick: at each month's turn support is brought up to date and a poll published; a vote's tick publishes none.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already set to this tick's.
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
        $alone = count(AerieDiet::governingParties($state->governingCoalition)) === 1;
        $swing = Election::economicVote(
            $growth - $state->supportGrowthCounted,
            $inflation - $state->supportInflationCounted,
            $math->generateStandardNormal(),
            $alone,
            self::termClock($state->totalTime - $state->termStartedAt) - self::termClock($state->supportUpdatedAt - $state->termStartedAt),
        );
        $base = self::lastingSupport($state);
        $moved = Election::applyIncumbentSwing($base, $state->governingCoalition, $swing, $state->supportParties);
        // A coalition's growth vote goes to the party leading it.
        $primeMinister = PartyLeaders::primeMinisterParty($state);
        if ($primeMinister !== null) {
            $moved = Election::applyIncumbentSwing($moved, AerieDiet::membership([$primeMinister]), Election::primeMinisterGrowthVote($growth - $state->supportGrowthCounted, $alone));
        }
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
        return Election::applyShortTermShocks(self::lastingSupport($state), $state->partyShortTermShocks);
    }

    /**
     * Each party's lasting share after some years of Ornstein-Uhlenbeck drift toward its normal vote, so spans adding
     * to a term give the vote's reversion and lasting swing exactly (Election::LASTING_SWING_VARIANCE).
     *
     * @param array<string, float> $lasting Lasting shares by party.
     * @param array<string, float> $years   Years of drift by party (driftYears()).
     * @param array<string, float> $draws   Standard normal draw by party.
     * @param array<string, float> $normals Normal votes by party.
     * @return array<string, float>
     */
    public static function driftLasting(array $lasting, array $years, array $draws, array $normals, MathUtility $math): array
    {
        $termPersistence = Election::NORMAL_VOTE_PERSISTENCE ** Election::TERM_YEARS;

        $moved = [];
        foreach (AerieDiet::PARTIES as $party) {
            $persistence = Election::NORMAL_VOTE_PERSISTENCE ** ($years[$party] ?? 0.0);
            $normal = $normals[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party];
            $gap = log(max(Election::MIN_VOTE_SHARE, $lasting[$party] ?? $normal) / $normal);
            // Stationary spread around the normal vote: a term's lasting swing over what a term's reversion takes away.
            $spread = sqrt(Election::LASTING_SWING_VARIANCE / $normal / (1.0 - ($termPersistence ** 2)));
            $moved[$party] = $normal * exp(($persistence * $gap) + ($spread * StochasticProcesses::persistentInnovationScale($persistence) * ($draws[$party] ?? 0.0)));
        }
        $total = array_sum($moved);

        return array_map(static fn(float $share): float => $share / $total, $moved);
    }

    /**
     * Each party's normal vote some years on, and its lasting share carried with it. The normal drifts as a share does
     * under neutral drift (Kimura 1955), variance growing with the years and the share, then is renormalised; the
     * lasting share moves with it, so its lead or deficit on the normal decays unchanged and the two add.
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
            $step = sqrt(Election::NORMAL_VOTE_DRIFT_VARIANCE * max(0.0, $years) * $normal) * ($draws[$party] ?? 0.0);
            $walked[$party] = max(Election::MIN_VOTE_SHARE, $normal + $step);
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
     * Each party's short-term swing some time later: faded at its half-life and, in the campaign's final days, built so
     * spans adding to the campaign give the vote's swing exactly (Election::SHORT_TERM_SWING_VARIANCE).
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
                + (Election::swingSd(Election::SHORT_TERM_SWING_VARIANCE, $normals[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party]) * $build * ($draws[$party] ?? 0.0));
        }

        return $faded;
    }

    /**
     * Share of the term's cost of ruling and residual the government has taken by this far into the term: rising fast
     * after the vote and slowing at the half-life governing parties' polls fall at, the whole of it by the next vote.
     */
    public static function termClock(float $yearsIntoTerm): float
    {
        $years = max(0.0, min(Election::TERM_YEARS, $yearsIntoTerm));

        return (1.0 - self::fadeOver($years)) / (1.0 - self::fadeOver(Election::TERM_YEARS));
    }

    /**
     * Each party's years of lasting drift over a span: of what its term has left, a governing party takes the share of
     * the term clock the span covers (termClock()), any other the share of the calendar, so a governing party falls
     * from its result mostly in the first year and every party has drifted exactly a term's worth by the vote.
     *
     * @return array<string, float>
     */
    public static function driftYears(PoliticsState|PoliticsStateDTO $state, float $from, float $to): array
    {
        $term = Election::TERM_YEARS;
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

    /** Years into the term at a moment, as a state saved before parties kept their own drift years counts them. */
    private static function elapsedInTerm(PoliticsState $state, float $at): float
    {
        return $state->termStartedAt < 0.0 ? 0.0 : max(0.0, min(Election::TERM_YEARS, $at - $state->termStartedAt));
    }

    /** How much of a span from $from to $to falls in the final days of the campaign for the vote it runs toward. */
    public static function campaignOverlap(float $from, float $to): float
    {
        $term = Election::TERM_YEARS;
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
     * Growth per head over the campaign so far, less its trend, as a share of the growth the vote will weigh: zero
     * before the campaign opens, the vote's growth gap on the day (Election::hold()).
     */
    public static function growthCounted(PoliticsState $state, MacroStateDTO $macro): float
    {
        if ($state->campaignStartedAt <= $state->termStartedAt || $state->campaignStartRealGdp <= 0.0) {
            return 0.0;
        }
        $years = $state->totalTime - $state->campaignStartedAt;

        return (log(Election::realGdp($macro) / $state->campaignStartRealGdp) - (($macro->laborForceGrowthRate + MacroConstants::TFP_DRIFT) * $years))
            / Election::CAMPAIGN_WINDOW_YEARS;
    }

    /**
     * Inflation over the term so far above the target, as a share of the inflation the vote will weigh: zero as the
     * term opens, the vote's inflation gap on the day.
     */
    public static function inflationCounted(PoliticsState $state, MacroStateDTO $macro): float
    {
        if ($state->termStartDeflator <= 0.0 || $macro->consumerPriceLevel <= 0.0) {
            return 0.0;
        }
        $years = $state->totalTime - $state->termStartedAt;

        return (log($macro->consumerPriceLevel / $state->termStartDeflator) - (MacroConstants::TARGET_INFLATION * $years)) / Election::TERM_YEARS;
    }

    /**
     * A poll of support: shares as a sample of this size would find them, the errors summing to nothing with the
     * multinomial's covariance, p(1 - p) / n on each party; a party drawn below nothing polls nothing.
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
            $draws[$party] = Distributions::calculateInverseNormalCDF(MathUtility::hashedUniform($salt, "{$key}:{$party}"));
            $innovation = Distributions::calculateInverseNormalCDF(MathUtility::hashedUniform($salt, "{$key}:{$party}:unsettled"));
            $state->pollUnsettled[$party] = isset($state->pollUnsettled[$party])
                ? ($persistence * $state->pollUnsettled[$party]) + (sqrt(1.0 - ($persistence ** 2)) * $innovation)
                : $innovation;
            $misread[$party] = self::unsettledMisreading($support[$party] ?? 0.0, $yearsToVote, $state->pollUnsettled[$party]);
        }

        // Kept to a hundredth of a point, finer than any poll is reported, so a term of them stays small in the state.
        $state->polls[] = ['t' => $state->totalTime, 'shares' => array_map(static fn(float $share): float => round($share, 4), self::sample(Election::applyShortTermShocks($support, $misread), $draws, self::EFFECTIVE_SAMPLE_SIZE))];
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
        $share = max(Election::MIN_VOTE_SHARE, $share);
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
        return Election::applyShortTermShocks($state->supportLasting === [] ? self::lastingAtTheVote($state) : $state->supportLasting, $state->supportIncumbency);
    }

    /**
     * The last vote's result with its short-term swings set aside.
     *
     * @return array<string, float>
     */
    private static function lastingAtTheVote(PoliticsState|PoliticsStateDTO $state): array
    {
        return Election::applyShortTermShocks($state->dietVoteShares, array_map(static fn(float $swing): float => -$swing, $state->partyShortTermShocks));
    }
}
