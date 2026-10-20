<?php

declare(strict_types=1);

namespace App\Service\Politics\Diet;

use App\Data\Politics\AerieDiet;
use App\DTO\PoliticsStateDTO;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Policy\BudgetRound;
use App\Service\Politics\PoliticsState;

/**
 * What the market makes of the polls: a Kalman-filter average (Jackman 2005) on OpinionPolls' own drift and poll error,
 * and from it the odds of each government the next vote could seat and the budget it would pass. Each scenario runs the
 * vote as the game will hold it (drift to the day, the cabinet's remaining swing, campaign swings, D'Hondt seats), then
 * the talks' logit over every cabinet, exact as an attempt's success does not depend on the cabinet drawn. Between a
 * vote and a cabinet taking office only the talks are weighed; once it takes office the forecast turns to the vote
 * after, from the result. Draws come from a stream of its own, seeded from the game and the day, so forecasting
 * changes nothing else.
 */
final class ElectionForecast
{
    // --- The Market's Forecast ---
    /** Vote outcomes weighed at each poll, drawn in antithetic pairs. */
    public const SCENARIOS = 32;
    /** Chance under which a cabinet is left off the published odds. */
    public const LISTED_CHANCE = 0.02;
    /** Chance under which a cabinet's laws are not worked out; its weight goes to the rest in proportion. */
    private const NEGLIGIBLE_CHANCE = 1e-4;
    /** Days an off-tick forecast is awaited before the tick works it out, so a forecaster that is down costs a week. */
    public const OFF_TICK_PATIENCE_DAYS = 7.0;

    /** @var array{inputs: string, levers: array<string, float>}|null The talks' budget as last weighed, and on what. */
    private static ?array $talksBudget = null;

    /**
     * One tick: reads the sitting government's coming budget, pools a poll published on it and forecasts afresh; on the
     * day of the vote the average becomes the result and the talks are weighed on the seats; on the day a cabinet takes
     * office the next vote is forecast from the result.
     *
     * @param bool $offTick Ask the forecaster instead of working it out here: published by receive(), or worked out
     *                      here once overdue.
     */
    public static function advance(PoliticsState $state, bool $recession = false, bool $offTick = false): void
    {
        $state->sittingLevers = self::sittingBudget($state, $recession);
        if ($state->authoritySalt < 0.0) {
            return;
        }
        $time = $state->totalTime;

        if ($state->lastElectionAt === $time) {
            $state->pollAverage = $state->dietVoteShares;
            $state->pollAverageVariance = array_fill_keys(AerieDiet::PARTIES, 0.0);
            $state->pollAveragedAt = $time;
            self::publish($state, self::weighTalks($state), $time);

            return;
        }

        $polled = $state->polls !== [] && end($state->polls)['t'] === $time;
        if ($polled) {
            $since = $state->pollAveragedAt >= 0.0 ? $state->pollAveragedAt : max(0.0, $state->lastElectionAt);
            [$state->pollAverage, $state->pollAverageVariance] = self::pool(
                $state->pollAverage === [] ? $state->dietVoteShares : $state->pollAverage,
                $state->pollAverageVariance,
                end($state->polls)['shares'],
                $time - $since,
                $state->normalVoteShares,
            );
            $state->pollAveragedAt = $time;
        }

        // The talks after the vote are still the news: the odds stand until a cabinet takes office.
        if ($state->coalitionTakesOfficeAt >= 0.0 && $state->talksStartedAt === $state->lastElectionAt) {
            return;
        }
        $due = $polled || ($state->lastGovernmentFormedAt === $time && $state->forecastFor === $state->lastElectionAt);
        if ($due && $offTick) {
            $state->forecastRequestedAt = $time;
        } elseif ($due || self::overdue($state)) {
            self::publish($state, self::forecast($state, self::draws($state)), self::nextVote($time));
        }
    }

    /**
     * Publishes a forecast worked out off the tick when it answers the request still pending; one that a later poll or
     * the vote has overtaken is dropped.
     *
     * @param array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>} $forecast
     * @param float $requestedAt When it was asked for, the time of the state it was worked out on.
     * @return bool Whether it was published.
     */
    public static function receive(PoliticsState $state, array $forecast, float $requestedAt): bool
    {
        if ($state->forecastRequestedAt < 0.0 || $requestedAt !== $state->forecastRequestedAt) {
            return false;
        }
        self::publish($state, $forecast, self::nextVote($requestedAt));

        return true;
    }

    /** The forecast's own draw stream, seeded from the game and the day, so forecasting changes nothing else. */
    public static function draws(PoliticsState $state): MathUtility
    {
        return MathUtility::ownStream(crc32(sprintf('%d:forecast:%.6f', (int) $state->authoritySalt, $state->totalTime)));
    }

    /** Whether a forecast asked for off the tick has been awaited past the patience. */
    private static function overdue(PoliticsState $state): bool
    {
        return $state->forecastRequestedAt >= 0.0
            && $state->totalTime - $state->forecastRequestedAt >= self::OFF_TICK_PATIENCE_DAYS / FinancialConstants::DAYS_PER_YEAR;
    }

    /**
     * One filter step per party: the average drifts as lasting support does since the last poll, its variance grows by
     * that drift's, then it moves toward the poll by the gain the poll's error leaves.
     *
     * @param array<string, float> $average  The average by party.
     * @param array<string, float> $variance Its variance by party, in shares squared.
     * @param array<string, float> $poll     The poll by party.
     * @param array<string, float> $normals  Normal votes by party.
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    public static function pool(array $average, array $variance, array $poll, float $years, array $normals): array
    {
        $persistence = Election::NORMAL_VOTE_PERSISTENCE ** max(0.0, $years);
        $termPersistence = Election::NORMAL_VOTE_PERSISTENCE ** Election::TERM_YEARS;

        $pooled = [];
        $left = [];
        foreach (AerieDiet::PARTIES as $party) {
            $normal = $normals[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party];
            $share = max(Election::MIN_VOTE_SHARE, $average[$party] ?? $normal);
            $predicted = $normal * exp($persistence * log($share / $normal));
            // The lasting drift's variance over the step (OpinionPolls::driftLasting()) and the normal vote's
            // (OpinionPolls::walkNormals()), in shares.
            $drift = ($predicted ** 2) * ((Election::LASTING_SWING_VARIANCE / (1.0 - ($termPersistence ** 2)) * (1.0 - ($persistence ** 2)))
                + Election::normalVoteDriftVariance($years)) / $normal;
            $prior = ($variance[$party] ?? 0.0) + $drift;
            $error = $predicted * (1.0 - $predicted) / OpinionPolls::EFFECTIVE_SAMPLE_SIZE;
            $gain = $prior / ($prior + $error);
            $pooled[$party] = $predicted + ($gain * (($poll[$party] ?? $predicted) - $predicted));
            $left[$party] = (1.0 - $gain) * $prior;
        }
        $total = array_sum($pooled);

        return [array_map(static fn(float $share): float => $share / $total, $pooled), $left];
    }

    /**
     * The odds of each government the next vote could seat, and the laws to expect, from the poll average.
     *
     * @return array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>}
     */
    public static function forecast(PoliticsState $state, MathUtility $draws): array
    {
        $time = $state->totalTime;
        $vote = self::nextVote($time);
        $years = $vote - $time;
        $positions = self::positionsAtTheVote($state->partyPositions);
        $blocs = CoalitionFormation::declareBlocs($state->dietSeats, $positions, $state->dietBlocs);
        $cabinet = AerieDiet::governingParties($state->governingCoalition);
        $average = $state->pollAverage === [] ? $state->dietVoteShares : $state->pollAverage;
        $termLeft = 1.0 - OpinionPolls::termClock($time - $state->termStartedAt);
        $campaign = OpinionPolls::campaignOverlap($time, $vote);

        $outcomes = [];
        for ($pair = 0; $pair < intdiv(self::SCENARIOS, 2); ++$pair) {
            $z = [];
            for ($i = 0; $i < (5 * count(AerieDiet::PARTIES)) + 1; ++$i) {
                $z[] = $draws->generateStandardNormal();
            }
            foreach ([1.0, -1.0] as $sign) {
                $outcomes[] = self::outcome($state, $average, $years, $termLeft, $campaign, array_map(static fn(float $draw): float => $sign * $draw, $z), $draws);
            }
        }

        $weighed = [];
        foreach ($outcomes as $shares) {
            $seats = Election::dHondt($shares, AerieDiet::SEATS);
            $weighed[] = ['seats' => $seats, 'shares' => $shares, 'odds' => self::cabinetOdds($seats, $shares, $positions, $cabinet, $blocs)];
        }

        return self::tally($weighed, 1.0 / count($weighed), $positions, BudgetRound::standingLevers($state));
    }

    /**
     * The odds of each cabinet the talks could seat on these seats: a party with a majority alone; otherwise the logit
     * over every cabinet on offer.
     *
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, float>                $shares    Vote shares by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param list<string>                        $statusQuo The outgoing cabinet.
     * @param array<string, string>               $blocs     The blocs declared for the vote.
     * @return list<array{cabinet: list<string>, support: list<string>, chance: float}>
     */
    public static function cabinetOdds(array $seats, array $shares, array $positions, array $statusQuo, array $blocs): array
    {
        $order = CoalitionFormation::bySize($seats, $shares);
        if (($seats[$order[0]] ?? 0) >= AerieDiet::MAJORITY_SEATS) {
            return [['cabinet' => [$order[0]], 'support' => [], 'chance' => 1.0]];
        }

        $options = CoalitionFormation::options($seats, $positions, $blocs);
        $utilities = CoalitionFormation::utilities($options, $seats, $positions, $statusQuo, $order[0], $blocs);
        $top = max($utilities);
        $weights = array_map(static fn(float $utility): float => exp($utility - $top), $utilities);
        $total = array_sum($weights);

        $odds = [];
        foreach ($options as $index => $option) {
            $odds[] = $option + ['chance' => $weights[$index] / $total];
        }

        return $odds;
    }

    /**
     * The laws the sitting government will pass at its next budget round (BudgetRound::enactBudget()). In talks after a
     * fall it is the cabinets they could seat, weighted by their odds; in talks after a vote, the caretaker, who passes
     * nothing. Only this budget reads the reserve fund's consent on today's recession; a later government will lack it.
     *
     * @return array<string, float>
     */
    public static function sittingBudget(PoliticsState $state, bool $recession = false): array
    {
        $standing = BudgetRound::standingLevers($state);
        if ($state->coalitionTakesOfficeAt >= 0.0) {
            if ($state->talksStartedAt === $state->lastElectionAt) {
                return $standing;
            }
            // Weighed once for the inputs it reads, which hold still while the talks run, rather than on every tick.
            $seats = array_map('intval', $state->dietSeats);
            $inputs = serialize([$seats, $state->dietVoteShares, $state->partyPositions, $state->dietBlocs, $standing, $recession]);
            if (self::$talksBudget === null || self::$talksBudget['inputs'] !== $inputs) {
                $odds = self::cabinetOdds($seats, $state->dietVoteShares, $state->partyPositions, [], $state->dietBlocs);
                self::$talksBudget = [
                    'inputs' => $inputs,
                    'levers' => self::tally([['seats' => $seats, 'shares' => $state->dietVoteShares, 'odds' => $odds]], 1.0, $state->partyPositions, $standing, $recession)['levers'],
                ];
            }

            return self::$talksBudget['levers'];
        }

        return BudgetRound::budget(
            AerieDiet::governingParties($state->governingCoalition),
            AerieDiet::governingParties($state->supportParties),
            $state->dietSeats,
            $state->partyPositions,
            $standing,
            $recession,
        )['levers'];
    }

    /**
     * When the forecast laws take effect: the first budget round after their government takes office, the average
     * talks after the vote or after the talks began (BudgetRound::enactBudget()).
     */
    public static function takesEffect(PoliticsState|PoliticsStateDTO $state): float
    {
        $talks = CoalitionFormation::FORMATION_MEAN_DAYS / FinancialConstants::DAYS_PER_YEAR;
        $inOffice = match (true) {
            $state->forecastFor > $state->totalTime => $state->forecastFor + $talks,
            $state->coalitionTakesOfficeAt >= 0.0 => max($state->totalTime, $state->talksStartedAt + $talks),
            default => $state->lastGovernmentFormedAt,
        };
        $round = MacroConstants::BUDGET_ROUND_PERIOD_YEARS;

        return (floor(($inOffice / $round) + 1e-9) + 1.0) * $round;
    }

    /**
     * When the sitting government's coming budget falls: the next round; while the parties talk after a cabinet falls
     * between votes, the first round after the talks' government is expected to take office.
     */
    public static function sittingTakesEffect(PoliticsState|PoliticsStateDTO $state): float
    {
        $inOffice = $state->totalTime;
        if ($state->coalitionTakesOfficeAt >= 0.0 && $state->talksStartedAt !== $state->lastElectionAt) {
            $inOffice = max($inOffice, $state->talksStartedAt + (CoalitionFormation::FORMATION_MEAN_DAYS / FinancialConstants::DAYS_PER_YEAR));
        }
        $round = MacroConstants::BUDGET_ROUND_PERIOD_YEARS;

        return (floor(($inOffice / $round) + 1e-9) + 1.0) * $round;
    }

    /** The vote the time runs toward: the next on the calendar. */
    public static function nextVote(float $time): float
    {
        return (floor(($time / Election::TERM_YEARS) + 1e-9) + 1.0) * Election::TERM_YEARS;
    }

    /**
     * One vote outcome: the average with its uncertainty, drifted to the day, the cabinet's remaining swing, the
     * campaign's short-term swings.
     *
     * @param array<string, float> $average The poll average by party.
     * @param list<float>          $z       Standard normal draws: per party the average's error, the lasting drift and
     *                                      the short-term swing, then the residual, then per party the unsettled
     *                                      misreading and the normal vote's walk.
     * @return array<string, float>
     */
    private static function outcome(PoliticsState $state, array $average, float $years, float $termLeft, float $campaign, array $z, MathUtility $math): array
    {
        $parties = AerieDiet::PARTIES;
        $count = count($parties);
        $start = [];
        $lastingDraws = [];
        $shortTermDraws = [];
        foreach ($parties as $index => $party) {
            $start[$party] = max(Election::MIN_VOTE_SHARE, ($average[$party] ?? 0.0) + (sqrt($state->pollAverageVariance[$party] ?? 0.0) * $z[$index]));
            $lastingDraws[$party] = $z[$count + $index];
            $shortTermDraws[$party] = $z[(2 * $count) + $index];
        }
        $total = array_sum($start);
        $start = array_map(static fn(float $share): float => $share / $total, $start);

        // The polls misread opinion yet to settle, which the vote will not carry; and each party drifts what its term
        // has left (OpinionPolls::driftYears()).
        $unsettled = [];
        foreach ($parties as $index => $party) {
            $unsettled[$party] = OpinionPolls::unsettledMisreading($start[$party], $years, $z[(3 * $count) + 1 + $index]);
        }
        $start = Election::applyShortTermShocks($start, $unsettled);
        // The normal votes walk on to the day, carrying the parties' support with them.
        $normalDraws = [];
        foreach ($parties as $index => $party) {
            $normalDraws[$party] = $z[(4 * $count) + 1 + $index];
        }
        [$normals, $start] = OpinionPolls::walkNormals($state->normalVoteShares, $start, $years, $normalDraws);
        $lasting = OpinionPolls::driftLasting($start, OpinionPolls::driftYears($state, $state->totalTime, $state->totalTime + $years), $lastingDraws, $normals, $math);
        $swing = Election::economicVote(0.0, 0.0, $z[3 * $count], count(AerieDiet::governingParties($state->governingCoalition)) === 1, $termLeft);
        $moved = Election::applyIncumbentSwing($lasting, $state->governingCoalition, $swing, $state->supportParties);

        return Election::applyShortTermShocks($moved, OpinionPolls::fadeShortTerm([], $years, $campaign, $shortTermDraws, $normals, $math));
    }

    /**
     * The talks weighed on the seats the vote gave, while they run.
     *
     * @return array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>}
     */
    private static function weighTalks(PoliticsState $state): array
    {
        $seats = array_map('intval', $state->dietSeats);
        $odds = self::cabinetOdds($seats, $state->dietVoteShares, $state->partyPositions, AerieDiet::governingParties($state->electionOutgoingCabinet), $state->dietBlocs);

        return self::tally([['seats' => $seats, 'shares' => $state->dietVoteShares, 'odds' => $odds]], 1.0, $state->partyPositions, BudgetRound::standingLevers($state));
    }

    /**
     * Adds the outcomes up: each cabinet's chance, each party's chance of leading the government, the seats to expect,
     * and the laws to expect, each cabinet's budget weighted by its chance.
     *
     * @param list<array{seats: array<string, int>, shares: array<string, float>, odds: list<array{cabinet: list<string>, support: list<string>, chance: float}>}> $outcomes
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param array<string, float>                $standing  The levers in force.
     * @param bool                                $recession Whether the fund consents to a draw above its ceiling.
     * @return array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>}
     */
    private static function tally(array $outcomes, float $weight, array $positions, array $standing, bool $recession = false): array
    {
        $cabinets = [];
        $leaders = array_fill_keys(AerieDiet::PARTIES, 0.0);
        $seats = array_fill_keys(AerieDiet::PARTIES, 0.0);
        $levers = array_fill_keys(array_keys($standing), 0.0);
        foreach ($outcomes as $outcome) {
            $order = CoalitionFormation::bySize($outcome['seats'], $outcome['shares']);
            foreach (AerieDiet::PARTIES as $party) {
                $seats[$party] += $weight * ($outcome['seats'][$party] ?? 0);
            }
            $weighed = array_values(array_filter($outcome['odds'], static fn(array $option): bool => $option['chance'] >= self::NEGLIGIBLE_CHANCE));
            $kept = array_sum(array_column($weighed, 'chance'));
            foreach ($weighed as $option) {
                $chance = $weight * $option['chance'] / $kept;
                $key = implode('+', $option['cabinet']) . '|' . implode('+', $option['support']);
                $cabinets[$key] ??= ['cabinet' => $option['cabinet'], 'support' => $option['support'], 'chance' => 0.0];
                $cabinets[$key]['chance'] += $chance;
                $leaders[CoalitionFormation::leader($option['cabinet'], $order)] += $chance;
                $enacted = BudgetRound::budget($option['cabinet'], $option['support'], $outcome['seats'], $positions, $standing, $recession)['levers'];
                foreach ($enacted as $lever => $value) {
                    $levers[$lever] += $chance * $value;
                }
            }
        }
        uasort($cabinets, static fn(array $a, array $b): int => $b['chance'] <=> $a['chance']);

        return ['cabinets' => $cabinets, 'leaders' => $leaders, 'seats' => $seats, 'levers' => $levers];
    }

    /**
     * Keeps a forecast: its cabinets down to the listed chance, the vote it is for, and when it was made.
     *
     * @param array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>} $forecast
     */
    private static function publish(PoliticsState $state, array $forecast, float $for): void
    {
        $state->forecastCabinets = array_values(array_filter($forecast['cabinets'], static fn(array $option): bool => $option['chance'] >= self::LISTED_CHANCE));
        $state->forecastLeaders = $forecast['leaders'];
        $state->forecastSeats = $forecast['seats'];
        $state->forecastLevers = $forecast['levers'];
        $state->forecastFor = $for;
        $state->forecastAt = $state->totalTime;
        $state->forecastRequestedAt = -1.0;
    }

    /**
     * Where the parties are expected to stand at the vote: each pulled toward its home by a term's persistence, as
     * Election::movePosition() moves it with no shock.
     *
     * @param array<string, array<string, float>> $positions
     * @return array<string, array<string, float>>
     */
    private static function positionsAtTheVote(array $positions): array
    {
        $expected = [];
        foreach (AerieDiet::PARTIES as $party) {
            $expected[$party] = AerieDiet::position($party, $positions);
            foreach (AerieDiet::AXES as $axis) {
                if (!AerieDiet::isFixed($party, $axis)) {
                    $expected[$party][$axis] = Election::movePosition($expected[$party][$axis], AerieDiet::HOME_POSITIONS[$party][$axis], $axis, 0.0);
                }
            }
        }

        return $expected;
    }
}
