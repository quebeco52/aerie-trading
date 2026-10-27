<?php

declare(strict_types=1);

namespace App\Service\View;

/**
 * Line charts the industry page draws as SVG over quarters: the geometry of each series in a 600 by 190 drawing, its
 * gridlines and year ticks, and a titled point per quarter for the hover readout (chart_tip_controller.js).
 */
final class IndustryCharts
{
    // --- Drawing ---
    /** The plot area in the 600 by 190 drawing: left, right, top and bottom edges. */
    public const PLOT = [40.0, 590.0, 8.0, 166.0];

    /** Gridline steps a chart picks from, smallest first: half a point up to a half. */
    private const GRID_STEPS = [0.005, 0.01, 0.02, 0.025, 0.05, 0.1, 0.2, 0.25, 0.5];

    /** Most gridlines across the value axis. */
    private const MAX_GRIDLINES = 6;

    /** Most year ticks along the time axis; a longer span labels every second or fifth year. */
    private const MAX_YEAR_TICKS = 8;

    /**
     * Each series as polylines (a gap where a quarter has no reading breaks the line) on one percentage axis, which
     * takes in zero when $withZero is set so a gap above and below a balanced reading reads against it. Null when no
     * series has two readings.
     *
     * @param  list<array{name: string, points: list<array{t: float, label: string, value: ?float}>}> $series
     * @return array{plot: list<float>, gridlines: list<array{y: float, label: string, zero: bool}>, years: list<array{x: float, label: string}>, series: list<array{name: string, last: ?float, lines: list<string>, points: list<array{x: float, y: float, title: string}>}>}|null
     */
    public static function lines(array $series, bool $withZero = false, bool $signed = false): ?array
    {
        $values = [];
        $times = [];
        foreach ($series as $one) {
            foreach ($one['points'] as $point) {
                $times[] = $point['t'];
                if ($point['value'] !== null) {
                    $values[] = $point['value'];
                }
            }
        }
        if (count($values) < 2 || $times === []) {
            return null;
        }

        $low = min($values);
        $high = max($values);
        if ($withZero) {
            $low = min($low, 0.0);
            $high = max($high, 0.0);
        }
        $step = self::GRID_STEPS[count(self::GRID_STEPS) - 1];
        foreach (self::GRID_STEPS as $candidate) {
            if (($high - $low) / $candidate <= self::MAX_GRIDLINES - 1) {
                $step = $candidate;
                break;
            }
        }
        $bottomValue = floor($low / $step + 1e-9) * $step;
        $topValue = max($bottomValue + $step, ceil($high / $step - 1e-9) * $step);

        $start = min($times);
        $end = max(max($times), $start + 1e-9);
        [$left, $right, $top, $bottom] = self::PLOT;
        $x = static fn(float $t): float => round($left + ($right - $left) * ($t - $start) / ($end - $start), 1);
        $y = static fn(float $v): float => round($bottom - ($bottom - $top) * ($v - $bottomValue) / ($topValue - $bottomValue), 1);
        // A step off a whole percent (half a point, two and a half) labels its gridlines to a tenth.
        $decimals = abs(round(100.0 * $step) - 100.0 * $step) > 1e-9 ? 1 : 0;
        $format = static fn(float $v, int $places): string => ($signed && $v > 0.0 ? '+' : '') . number_format(100.0 * $v, $places) . '%';

        $gridlines = [];
        for ($v = $bottomValue; $v <= $topValue + 1e-9; $v += $step) {
            $gridlines[] = ['y' => $y($v), 'label' => $format(round($v, 6), $decimals), 'zero' => abs($v) < 1e-9];
        }

        $every = 1;
        foreach ([1, 2, 5, 10] as $candidate) {
            $every = $candidate;
            if (($end - $start) / $candidate <= self::MAX_YEAR_TICKS) {
                break;
            }
        }
        $years = [];
        for ($year = (int) ceil($start - 1e-9); $year <= $end + 1e-9; ++$year) {
            if (($year + 1) % $every === 0) {
                $years[] = ['x' => $x((float) $year), 'label' => sprintf('Year %d', $year + 1)];
            }
        }

        $drawn = [];
        foreach ($series as $one) {
            $lines = [];
            $run = [];
            $points = [];
            $last = null;
            foreach ($one['points'] as $point) {
                if ($point['value'] === null) {
                    $lines[] = $run;
                    $run = [];
                    continue;
                }
                $px = $x($point['t']);
                $py = $y($point['value']);
                $run[] = $px . ',' . $py;
                $points[] = ['x' => $px, 'y' => $py, 'title' => sprintf('%s, %s: %s', $one['name'], $point['label'], $format($point['value'], 1))];
                $last = $point['value'];
            }
            $lines[] = $run;
            $drawn[] = [
                'name' => $one['name'],
                'last' => $last,
                'lines' => array_values(array_map(static fn(array $r): string => implode(' ', $r), array_filter($lines, static fn(array $r): bool => count($r) >= 2))),
                'points' => $points,
            ];
        }

        return ['plot' => self::PLOT, 'gridlines' => $gridlines, 'years' => $years, 'series' => $drawn];
    }
}
