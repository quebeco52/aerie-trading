<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\District\DistrictCalendar;
use App\Data\Politics\AerieDiet;
use App\DTO\PoliticsStateDTO;
use App\Entity\ElectionOdds;
use App\Entity\RateDecision;
use App\Service\Math\MacroConstants;
use App\Service\Politics\ElectionForecast;
use App\Service\Politics\MonetaryAuthority;
use App\Service\Politics\PoliticsEngine;

/**
 * The charts the government pages draw over time: the rate each meeting left, the market's odds on the next
 * government, and the polls.
 */
final class GovernmentCharts
{
    // --- Polls Chart ---
    /** The polls chart's plot area in its 600 by 210 drawing: left, right, top and bottom edges. */
    private const POLL_CHART_PLOT = [34.0, 590.0, 8.0, 182.0];
    /** Normal quantile of the band drawn round the poll average: the true share lies inside it 19 times in 20. */
    private const POLL_BAND_Z = 1.96;

    // --- Rate Chart ---
    /** Years of meetings the rate chart covers: 48 meetings, few enough to mark each one. */
    public const RATE_CHART_YEARS = 6.0;
    /** The rate chart's plot area in its 600 by 172 drawing: left, right, top and bottom edges. */
    private const RATE_CHART_PLOT = [40.0, 590.0, 14.0, 150.0];
    /** Gridline steps the rate chart picks from, smallest first: a quarter point up to two points. */
    private const RATE_CHART_GRID_STEPS = [0.0025, 0.005, 0.01, 0.02];
    /** Most gridlines across the rate chart's value axis. */
    private const RATE_CHART_GRIDLINES = 6;

    // --- Election Odds Chart ---
    /** The odds chart's plot area in its 600 by 160 drawing: left, right, top and bottom edges. */
    private const ODDS_CHART_PLOT = [34.0, 590.0, 8.0, 132.0];
    /**
     * Least chance of leading the government a party must have reached for the odds chart and the market panel to show
     * it.
     */
    public const LISTED_LEADER_CHANCE = 0.01;
    /** Gridline step on the odds chart's axis. */
    private const ODDS_CHART_STEP = 0.25;

    /**
     * The rate each meeting left, oldest first, on a line from meeting to meeting, each meeting marked with its
     * dissents: a mark above for votes for a higher rate, below for a lower. A dashed line marks a new governor in the
     * chair. Inflation runs quarter by quarter beside it against the target, and a summary row reads the span off.
     *
     * @param list<RateDecision> $decisions The meetings on record over the chart's span, oldest first.
     * @param list<array{t: float, inflation: float}> $inflation The quarterly record, oldest first; quarters outside
     *                                                           the span are left off.
     * @return array<string, mixed>|null Null with fewer than two meetings on record.
     */
    public static function rateChart(array $decisions, float $now, array $inflation = []): ?array
    {
        if (count($decisions) < 2) {
            return null;
        }

        $from = $decisions[0]->getSimTime();
        $to = max($now, $decisions[array_key_last($decisions)]->getSimTime());
        $inflation = array_values(array_filter($inflation, static fn(array $quarter): bool => $quarter['t'] >= $from - 1e-9 && $quarter['t'] <= $to + 1e-9));
        $rates = array_map(static fn(RateDecision $decision): float => $decision->getRate(), $decisions);
        $values = $inflation === [] ? $rates : [...$rates, ...array_column($inflation, 'inflation'), MacroConstants::TARGET_INFLATION];
        $step = self::RATE_CHART_GRID_STEPS[array_key_last(self::RATE_CHART_GRID_STEPS)];
        foreach (self::RATE_CHART_GRID_STEPS as $candidate) {
            if ((ceil(max($values) / $candidate) - floor(min($values) / $candidate)) <= self::RATE_CHART_GRIDLINES - 1) {
                $step = $candidate;
                break;
            }
        }
        $low = floor(min($values) / $step) * $step;
        $high = max($low + $step, ceil(max($values) / $step) * $step);

        [$left, $right, $top, $bottom] = self::RATE_CHART_PLOT;
        $x = static fn(float $time): float => round($left + (($right - $left) * ($time - $from) / max(1e-9, $to - $from)), 1);
        $y = static fn(float $rate): float => round($bottom - (($bottom - $top) * ($rate - $low) / ($high - $low)), 1);

        $meetings = [];
        $governors = [];
        $previous = null;
        foreach ($decisions as $decision) {
            $higher = $decision->getVotesHigher();
            $lower = $decision->getVotesLower();
            $meetings[] = [
                'x' => $x($decision->getSimTime()),
                'y' => $y($decision->getRate()),
                'date' => DistrictCalendar::quarter($decision->getSimTime()),
                'rate' => $decision->getRate(),
                // Whole basis points, the precision the rate is printed at, as the last decision reads.
                'moveBp' => (int) round(10000.0 * $decision->getRateChange()),
                'split' => (count($decision->getVotes()) - $higher - $lower) . '–' . ($higher + $lower),
                'higher' => $higher,
                'lower' => $lower,
            ];
            if ($previous !== null && $decision->getGovernor() !== $previous) {
                $governors[] = ['x' => $x($decision->getSimTime()), 'name' => $decision->getGovernor(), 'date' => DistrictCalendar::quarter($decision->getSimTime())];
            }
            $previous = $decision->getGovernor();
        }

        $gridlines = [];
        for ($rate = $low; $rate <= $high + 1e-9; $rate += $step) {
            $gridlines[] = ['y' => $y($rate), 'rate' => $rate];
        }
        $years = [];
        for ($year = (int) ceil($from); $year <= $to + 1e-9; ++$year) {
            $years[] = ['x' => $x((float) $year), 'label' => sprintf('Year %d', $year + 1)];
        }

        $quarters = array_map(static fn(array $quarter): array => [
            'x' => $x($quarter['t']),
            'y' => $y($quarter['inflation']),
            // A row is written as its quarter closes; it describes the quarter that closed.
            'date' => DistrictCalendar::quarter(max(0.0, $quarter['t'] - MacroHistoryPresenter::HALF_QUARTER_YEARS)),
            'inflation' => $quarter['inflation'],
        ], $inflation);

        return [
            'since' => DistrictCalendar::quarter($from),
            'line' => implode(' ', array_map(static fn(array $meeting): string => $meeting['x'] . ',' . $meeting['y'], $meetings)),
            'meetings' => $meetings,
            // The rule moves the rate a little at every meeting; a meeting's standard step is what counts as a move.
            'moves' => count(array_filter($decisions, static fn(RateDecision $decision): bool => abs($decision->getRateChange()) >= MonetaryAuthority::NEWSWORTHY_RATE_MOVE)),
            'stepBp' => (int) round(10000.0 * MonetaryAuthority::NEWSWORTHY_RATE_MOVE),
            'split' => count(array_filter($meetings, static fn(array $meeting): bool => $meeting['higher'] + $meeting['lower'] > 0)),
            'governors' => $governors,
            'gridlines' => $gridlines,
            'years' => $years,
            'plot' => self::RATE_CHART_PLOT,
            'inflation' => $quarters === [] ? null : [
                'line' => implode(' ', array_map(static fn(array $quarter): string => $quarter['x'] . ',' . $quarter['y'], $quarters)),
                'quarters' => $quarters,
                'target' => MacroConstants::TARGET_INFLATION,
                'targetY' => $y(MacroConstants::TARGET_INFLATION),
            ],
            'summary' => self::rateSummary($decisions, $meetings, $quarters === [] ? null : $quarters[array_key_last($quarters)]['inflation']),
        ];
    }

    /**
     * The rate chart's span in a row: the change over the last year, the range, the largest move and the widest split,
     * and the rate less the latest inflation.
     *
     * @param non-empty-list<RateDecision> $decisions
     * @param non-empty-list<array{x: float, y: float, date: string, rate: float, moveBp: int, split: string, higher: int, lower: int}> $meetings rateChart()'s meetings, in the same order.
     * @return array<string, mixed>
     */
    private static function rateSummary(array $decisions, array $meetings, ?float $inflation): array
    {
        $last = $decisions[array_key_last($decisions)];
        // The meeting a year before the last, to within half a meeting for the sim clock's rounding.
        $yearAgo = null;
        foreach ($decisions as $decision) {
            if ($decision->getSimTime() <= $last->getSimTime() - 1.0 + 0.5 / MonetaryAuthority::MEETINGS_PER_YEAR) {
                $yearAgo = $decision;
            }
        }
        $rates = array_column($meetings, 'rate');
        $largest = null;
        $widest = null;
        foreach ($meetings as $meeting) {
            if ($meeting['moveBp'] !== 0 && ($largest === null || abs($meeting['moveBp']) > abs($largest['moveBp']))) {
                $largest = $meeting;
            }
            if ($meeting['higher'] + $meeting['lower'] > 0 && ($widest === null || $meeting['higher'] + $meeting['lower'] > $widest['higher'] + $widest['lower'])) {
                $widest = $meeting;
            }
        }

        return [
            'yearChangeBp' => $yearAgo === null ? null : (int) round(10000.0 * ($last->getRate() - $yearAgo->getRate())),
            'high' => max($rates),
            'low' => min($rates),
            'largest' => $largest === null ? null : ['moveBp' => $largest['moveBp'], 'date' => $largest['date']],
            'widest' => $widest === null ? null : ['split' => $widest['split'], 'date' => $widest['date']],
            'inflation' => $inflation,
            'realRate' => $inflation === null ? null : $last->getRate() - $inflation,
        ];
    }

    /**
     * Each party's chance of leading the next government, held from one forecast to the next as the odds stood and run
     * on to today, for every party that has reached the listed chance.
     *
     * @param list<ElectionOdds> $forecasts The forecasts of one vote, oldest first.
     * @return array<string, mixed>|null Null with fewer than two.
     */
    public static function oddsChart(array $forecasts, float $now, float $voteAt): ?array
    {
        if (count($forecasts) < 2) {
            return null;
        }

        $from = $forecasts[0]->getSimTime();
        $to = max($now, $voteAt);
        [$left, $right, $top, $bottom] = self::ODDS_CHART_PLOT;
        $x = static fn(float $time): float => round($left + (($right - $left) * ($time - $from) / max(1e-9, $to - $from)), 1);
        $y = static fn(float $chance): float => round($bottom - (($bottom - $top) * $chance), 1);

        $parties = [];
        foreach (AerieDiet::PARTIES as $party) {
            $chances = array_map(static fn(ElectionOdds $forecast): float => (float) ($forecast->getLeaders()[$party] ?? 0.0), $forecasts);
            if (max($chances) < self::LISTED_LEADER_CHANCE) {
                continue;
            }
            $points = [];
            foreach ($forecasts as $i => $forecast) {
                if ($i > 0) {
                    $points[] = $x($forecast->getSimTime()) . ',' . $y($chances[$i - 1]);
                }
                $points[] = $x($forecast->getSimTime()) . ',' . $y($chances[$i]);
            }
            $latest = $chances[array_key_last($chances)];
            $points[] = $x($now) . ',' . $y($latest);
            $parties[] = [
                'name' => AerieDiet::PARTY_NAMES[$party],
                'color' => DietStyle::PARTY_COLORS[$party],
                'chance' => $latest,
                'first' => $chances[0],
                'line' => implode(' ', $points),
                'last' => ['x' => $x($now), 'y' => $y($latest)],
            ];
        }
        usort($parties, static fn(array $a, array $b): int => $b['chance'] <=> $a['chance']);

        $gridlines = [];
        for ($chance = 0.0; $chance <= 1.0 + 1e-9; $chance += self::ODDS_CHART_STEP) {
            $gridlines[] = ['y' => $y($chance), 'label' => (string) round($chance * 100) . '%'];
        }
        $years = [];
        for ($year = (int) ceil($from); $year <= $to + 1e-9; ++$year) {
            $years[] = ['x' => $x((float) $year), 'label' => sprintf('Year %d', $year + 1)];
        }

        return [
            'since' => DistrictCalendar::quarter($from),
            'count' => count($forecasts),
            'parties' => $parties,
            'gridlines' => $gridlines,
            'years' => $years,
            'today' => $x($now),
            'vote' => $voteAt > $now ? ['x' => $x($voteAt), 'date' => DistrictCalendar::quarter($voteAt)] : null,
            'plot' => self::ODDS_CHART_PLOT,
        ];
    }

    /**
     * The polls since the last vote: each party's poll average across the term from the result on the day, with the
     * band the true share lies in 19 times in 20, the polls themselves as dots, and the seats the market expects at the
     * vote.
     *
     * @param float $nextElection When the next vote falls.
     * @return array<string, mixed>|null Null before the term's first poll.
     */
    public static function polls(PoliticsStateDTO $politics, float $nextElection): ?array
    {
        if ($politics->polls === []) {
            return null;
        }

        $start = max(0.0, $politics->lastElectionAt);
        $span = max(1e-9, $nextElection - $start);
        $averages = self::pollAverages($politics);
        $latestPoll = $politics->polls[array_key_last($politics->polls)];
        $latest = $averages[array_key_last($averages)];
        $margin = static fn(array $point, string $party): float => self::POLL_BAND_Z * sqrt(max(0.0, $point['variance'][$party] ?? 0.0));

        $top = 0.0;
        foreach ($averages as $point) {
            foreach (AerieDiet::PARTIES as $party) {
                $top = max($top, ($point['shares'][$party] ?? 0.0) + $margin($point, $party));
            }
        }
        foreach ($politics->polls as $poll) {
            foreach ($poll['shares'] as $share) {
                $top = max($top, $share);
            }
        }
        $step = $top > 0.3 ? 0.1 : 0.05;
        $scale = ceil(($top + 0.01) / $step) * $step;

        [$left, $right, $topY, $bottom] = self::POLL_CHART_PLOT;
        $x = static fn(float $time): float => round($left + (($right - $left) * ($time - $start) / $span), 1);
        $y = static fn(float $share): float => round($bottom - (($bottom - $topY) * max(0.0, $share) / $scale), 1);

        // The market's expected seats while its forecast is for the coming vote; the average's own D'Hondt result
        // otherwise.
        $marketSeats = $politics->forecastSeats !== [] && $politics->forecastFor > $politics->totalTime;
        $expected = $marketSeats ? $politics->forecastSeats : PoliticsEngine::dHondt($latest['shares'], AerieDiet::SEATS);
        $coalition = AerieDiet::governingParties($politics->governingCoalition);
        $support = AerieDiet::governingParties($politics->supportParties);
        $sumSeats = static fn(array $members): float => array_sum(array_map(static fn(string $party): float => (float) ($expected[$party] ?? 0.0), $members));

        $parties = [];
        foreach (AerieDiet::PARTIES as $party) {
            $upper = [];
            $lower = [];
            foreach ($averages as $point) {
                $share = $point['shares'][$party] ?? 0.0;
                $upper[] = $x($point['t']) . ',' . $y($share + $margin($point, $party));
                $lower[] = $x($point['t']) . ',' . $y($share - $margin($point, $party));
            }
            $parties[] = [
                'name' => AerieDiet::PARTY_NAMES[$party],
                'label' => DietStyle::PARTY_LABELS[$party],
                'color' => DietStyle::PARTY_COLORS[$party],
                'vote' => $politics->dietVoteShares[$party] ?? 0.0,
                'average' => $latest['shares'][$party] ?? 0.0,
                'margin' => $margin($latest, $party),
                'poll' => $latestPoll['shares'][$party] ?? 0.0,
                'change' => ($latest['shares'][$party] ?? 0.0) - ($politics->dietVoteShares[$party] ?? 0.0),
                'seats' => (int) round((float) ($expected[$party] ?? 0.0)),
                'line' => implode(' ', array_map(static fn(array $point): string => $x($point['t']) . ',' . $y($point['shares'][$party] ?? 0.0), $averages)),
                'band' => implode(' ', array_merge($upper, array_reverse($lower))),
                'dots' => array_map(static fn(array $poll): array => ['x' => $x($poll['t']), 'y' => $y($poll['shares'][$party] ?? 0.0)], $politics->polls),
                'last' => ['x' => $x($latest['t']), 'y' => $y($latest['shares'][$party] ?? 0.0)],
            ];
        }
        usort($parties, static fn(array $a, array $b): int => $b['average'] <=> $a['average']);

        $gridlines = [];
        for ($share = 0.0; $share <= $scale + 1e-9; $share += $step) {
            $gridlines[] = ['y' => $y($share), 'label' => (string) round($share * 100) . '%'];
        }
        $years = [];
        for ($year = ceil($start); $year <= $nextElection + 1e-9; $year += 1.0) {
            $years[] = ['x' => $x($year), 'label' => sprintf('Year %d', (int) $year + 1)];
        }

        return [
            'since' => $politics->lastElectionAt < 0.0 ? 'Year 1' : DistrictCalendar::quarter($politics->lastElectionAt),
            'latest' => DistrictCalendar::quarter($latestPoll['t']),
            'count' => count($politics->polls),
            'parties' => $parties,
            'gridlines' => $gridlines,
            'years' => $years,
            'today' => $x($latest['t']),
            'plot' => self::POLL_CHART_PLOT,
            'marketSeats' => $marketSeats,
            'cabinet' => [
                'seats' => (int) round($sumSeats($coalition)),
                'supportedSeats' => (int) round($sumSeats($coalition) + $sumSeats($support)),
                'hasSupport' => $support !== [],
            ],
        ];
    }

    /**
     * The market's poll average after each poll of the term, from the result on the day, as ElectionForecast::advance()
     * pools it: the state keeps only the latest, and replaying the filter over the term's polls gives its path.
     *
     * @return non-empty-list<array{t: float, shares: array<string, float>, variance: array<string, float>}>
     */
    public static function pollAverages(PoliticsStateDTO $politics): array
    {
        $since = max(0.0, $politics->lastElectionAt);
        $average = $politics->dietVoteShares;
        $variance = array_fill_keys(AerieDiet::PARTIES, 0.0);
        $path = [['t' => $since, 'shares' => $average, 'variance' => $variance]];
        foreach ($politics->polls as $poll) {
            [$average, $variance] = ElectionForecast::pool($average, $variance, $poll['shares'], $poll['t'] - $since, $politics->normalVoteShares);
            $since = $poll['t'];
            $path[] = ['t' => $poll['t'], 'shares' => $average, 'variance' => $variance];
        }

        return $path;
    }
}
