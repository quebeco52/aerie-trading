<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieDiet;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * What the market makes of the polls: an average of them, and from it the odds of each government the next vote could
 * seat and the laws it would pass.
 *
 * The average pools the polls as a Kalman filter (Jackman 2005): each poll moves it by as much as the poll's error
 * against a month's drift in support allows, the drift and the error being the game's own (OpinionPolls).
 *
 * The forecast runs the vote from there the way the game will hold it: the average, as uncertain as the filter leaves
 * it, drifting to election day as lasting support does, the cabinet paying what is left of the term's cost of ruling
 * and taking what is left of its residual, and the campaign's short-term swings on top; then seats by D'Hondt, and the
 * talks, whose cabinet given the seats is exactly the logit over every cabinet on offer (CoalitionFormation::talks()
 * draws each attempt from it and its success does not depend on which was drawn). Each cabinet's laws are the budget it
 * would pass on the Diet as it stands (PoliticsEngine::budget()). Between the vote and a cabinet taking office the
 * seats are known and only the talks are weighed; once it takes office, its laws are known.
 *
 * Its draws come from a stream of its own, seeded from the game and the day, so forecasting changes nothing else.
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

    /**
     * One tick: reads the sitting government's coming budget, pools a poll published on it and forecasts afresh; on the day of the vote the average becomes the result
     * and the talks are weighed on the seats; on the day a cabinet takes office its laws are the forecast.
     */
    public static function advance(PoliticsState $state, float $debtToGdp): void
    {
        $state->sittingLevers = self::sittingBudget($state, $debtToGdp);
        if ($state->authoritySalt < 0.0) {
            return;
        }
        $time = $state->totalTime;

        if ($state->lastElectionAt === $time) {
            $state->pollAverage = $state->dietVoteShares;
            $state->pollAverageVariance = array_fill_keys(AerieDiet::PARTIES, 0.0);
            $state->pollAveragedAt = $time;
            self::publish($state, self::weighTalks($state, $debtToGdp), $time);

            return;
        }

        $polled = $state->polls !== [] && end($state->polls)['t'] === $time;
        if ($polled) {
            $since = $state->pollAveragedAt >= 0.0 ? $state->pollAveragedAt : max(0.0, $state->lastElectionAt);
            [$state->pollAverage, $state->pollAverageVariance] = self::pool(
                $state->pollAverage === [] ? $state->dietVoteShares : $state->pollAverage,
                $state->pollAverageVariance,
                end($state->polls)['shares'],
                $time - $since
            );
            $state->pollAveragedAt = $time;
        }

        // The talks after the vote are still the news: the odds stand until a cabinet takes office.
        if ($state->coalitionTakesOfficeAt >= 0.0 && $state->talksStartedAt === $state->lastElectionAt) {
            return;
        }
        if ($state->lastGovernmentFormedAt === $time && $state->forecastFor === $state->lastElectionAt) {
            self::publish($state, self::seated($state, $debtToGdp), $time);

            return;
        }
        if ($polled) {
            $draws = MathUtility::ownStream(crc32(sprintf('%d:forecast:%.6f', (int) $state->authoritySalt, $time)));
            self::publish($state, self::forecast($state, $debtToGdp, $draws), self::nextVote($time));
        }
    }

    /**
     * One step of the filter, party by party: the average drifts as lasting support does over the time since the last
     * poll and grows as uncertain as that drift, then moves toward the poll by the share of the uncertainty the poll's
     * error leaves it.
     *
     * @param array<string, float> $average  The average by party.
     * @param array<string, float> $variance Its variance by party, in shares squared.
     * @param array<string, float> $poll     The poll by party.
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    public static function pool(array $average, array $variance, array $poll, float $years): array
    {
        $persistence = PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE ** max(0.0, $years);
        $termPersistence = PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE ** PoliticsEngine::ELECTION_TERM_YEARS;

        $pooled = [];
        $left = [];
        foreach (AerieDiet::PARTIES as $party) {
            $normal = AerieDiet::SEED_VOTE_SHARES[$party];
            $share = max(PoliticsEngine::MIN_VOTE_SHARE, $average[$party] ?? $normal);
            $predicted = $normal * exp($persistence * log($share / $normal));
            // The lasting drift's variance over the step (OpinionPolls::driftLasting()), in shares.
            $drift = ($predicted ** 2) * PoliticsEngine::LASTING_SWING_VARIANCE / $normal / (1.0 - ($termPersistence ** 2)) * (1.0 - ($persistence ** 2));
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
    public static function forecast(PoliticsState $state, float $debtToGdp, MathUtility $draws): array
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
            for ($i = 0; $i < (3 * count(AerieDiet::PARTIES)) + 1; ++$i) {
                $z[] = $draws->generateStandardNormal();
            }
            foreach ([1.0, -1.0] as $sign) {
                $outcomes[] = self::outcome($state, $average, $years, $termLeft, $campaign, array_map(static fn(float $draw): float => $sign * $draw, $z), $draws);
            }
        }

        $weighed = [];
        foreach ($outcomes as $shares) {
            $seats = PoliticsEngine::dHondt($shares, AerieDiet::SEATS);
            $weighed[] = ['seats' => $seats, 'shares' => $shares, 'odds' => self::cabinetOdds($seats, $shares, $positions, $cabinet, $blocs)];
        }

        return self::tally($weighed, 1.0 / count($weighed), $positions, PoliticsEngine::standingLevers($state), $debtToGdp);
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
     * The laws the sitting government will pass at its next budget round, on the Diet as it stands (PoliticsEngine::
     * enactBudget()); a caretaker, or a government in its first round, passes none yet, so the laws in force stand.
     *
     * @return array<string, float>
     */
    public static function sittingBudget(PoliticsState $state, float $debtToGdp): array
    {
        $standing = PoliticsEngine::standingLevers($state);
        if ($state->coalitionTakesOfficeAt >= 0.0) {
            return $standing;
        }

        return PoliticsEngine::budget(
            AerieDiet::governingParties($state->governingCoalition),
            AerieDiet::governingParties($state->supportParties),
            $state->dietSeats,
            $state->partyPositions,
            $standing,
            $debtToGdp
        )['levers'];
    }

    /**
     * When the laws forecast take effect: the first budget round after the government they are for takes office, a
     * government the next vote seats taking office the average talks after it, and one in talks the average talks after
     * they began (PoliticsEngine::enactBudget() passes a government's first budget the round after it takes office).
     */
    public static function takesEffect(PoliticsState|PoliticsStateDTO $state): float
    {
        $talks = CoalitionFormation::FORMATION_MEAN_DAYS / FinancialConstants::DAYS_PER_YEAR;
        $inOffice = match (true) {
            $state->forecastFor > $state->totalTime => $state->forecastFor + $talks,
            $state->coalitionTakesOfficeAt >= 0.0 => max($state->totalTime, $state->talksStartedAt + $talks),
            default => $state->lastGovernmentFormedAt,
        };
        $round = MacroEngine::BUDGET_ROUND_PERIOD_YEARS;

        return (floor(($inOffice / $round) + 1e-9) + 1.0) * $round;
    }

    /** The vote the time runs toward: the next on the calendar. */
    public static function nextVote(float $time): float
    {
        return (floor(($time / PoliticsEngine::ELECTION_TERM_YEARS) + 1e-9) + 1.0) * PoliticsEngine::ELECTION_TERM_YEARS;
    }

    /**
     * One vote outcome: the average with its uncertainty, drifted to the day, the cabinet's remaining swing, the
     * campaign's short-term swings.
     *
     * @param array<string, float> $average The poll average by party.
     * @param list<float>          $z       Standard normal draws: per party the average's error, the lasting drift and the short-term swing, then the residual.
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
            $start[$party] = max(PoliticsEngine::MIN_VOTE_SHARE, ($average[$party] ?? 0.0) + (sqrt($state->pollAverageVariance[$party] ?? 0.0) * $z[$index]));
            $lastingDraws[$party] = $z[$count + $index];
            $shortTermDraws[$party] = $z[(2 * $count) + $index];
        }
        $total = array_sum($start);
        $start = array_map(static fn(float $share): float => $share / $total, $start);

        $lasting = OpinionPolls::driftLasting($start, $years, $lastingDraws, $math);
        $swing = PoliticsEngine::economicVote(0.0, 0.0, $z[3 * $count], count(AerieDiet::governingParties($state->governingCoalition)) === 1, $termLeft);
        $moved = PoliticsEngine::applyIncumbentSwing($lasting, $state->governingCoalition, $swing, $state->supportParties);

        return PoliticsEngine::applyShortTermShocks($moved, OpinionPolls::fadeShortTerm([], $years, $campaign, $shortTermDraws, $math));
    }

    /**
     * The talks weighed on the seats the vote gave, while they run.
     *
     * @return array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>}
     */
    private static function weighTalks(PoliticsState $state, float $debtToGdp): array
    {
        $seats = array_map('intval', $state->dietSeats);
        $odds = self::cabinetOdds($seats, $state->dietVoteShares, $state->partyPositions, AerieDiet::governingParties($state->electionOutgoingCabinet), $state->dietBlocs);

        return self::tally([['seats' => $seats, 'shares' => $state->dietVoteShares, 'odds' => $odds]], 1.0, $state->partyPositions, PoliticsEngine::standingLevers($state), $debtToGdp);
    }

    /**
     * The cabinet that took office, certain.
     *
     * @return array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>}
     */
    private static function seated(PoliticsState $state, float $debtToGdp): array
    {
        $seats = array_map('intval', $state->dietSeats);
        $odds = [['cabinet' => AerieDiet::governingParties($state->governingCoalition), 'support' => AerieDiet::governingParties($state->supportParties), 'chance' => 1.0]];

        return self::tally([['seats' => $seats, 'shares' => $state->dietVoteShares, 'odds' => $odds]], 1.0, $state->partyPositions, PoliticsEngine::standingLevers($state), $debtToGdp);
    }

    /**
     * Adds the outcomes up: each cabinet's chance, each party's chance of leading the government, the seats to expect,
     * and the laws to expect, each cabinet's budget weighted by its chance.
     *
     * @param list<array{seats: array<string, int>, shares: array<string, float>, odds: list<array{cabinet: list<string>, support: list<string>, chance: float}>}> $outcomes
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param array<string, float>                $standing  The levers in force.
     * @return array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>}
     */
    private static function tally(array $outcomes, float $weight, array $positions, array $standing, float $debtToGdp): array
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
                $enacted = PoliticsEngine::budget($option['cabinet'], $option['support'], $outcome['seats'], $positions, $standing, $debtToGdp)['levers'];
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
    }

    /**
     * Where the parties are expected to stand at the vote: each pulled toward its home by a term's persistence, as
     * PoliticsEngine::movePosition() moves it with no shock.
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
                    $expected[$party][$axis] = PoliticsEngine::movePosition($expected[$party][$axis], AerieDiet::HOME_POSITIONS[$party][$axis], $axis, 0.0);
                }
            }
        }

        return $expected;
    }
}
