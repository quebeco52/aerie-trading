<?php

declare(strict_types=1);

namespace App\Service\Market\Chart;

/**
 * The span of simulated time each chart range button covers, and what that span is in any one series.
 *
 * A range is a span of time, never a row count. The series behind a chart are written at different rates —
 * stock and fund history once a bar, bond history once a mark, the Redis buffers once a tick or once a mark —
 * and the bar rate is itself a tuning constant, so a row count is right for one table at one configuration
 * and silently wrong for the rest: the bond page drew about nine and a half years under "1Y" because the
 * endpoint counted bond rows at the equity bar rate. History is therefore selected by its own sim_time, and
 * the Redis buffers, which carry no simulated time, are counted in their own entries per year.
 */
final class ChartRange
{
    // --- Ranges ---
    /** Simulated years each range button spans; 'max' is absent because it has no bound. */
    public const YEARS = [
        '1w' => 1.0 / 52.0,
        '1m' => 1.0 / 12.0,
        '3m' => 0.25,
        '6m' => 0.5,
        '1y' => 1.0,
        '3y' => 3.0,
        '5y' => 5.0,
        '10y' => 10.0,
    ];

    /** The range served when a request names none, or one YEARS does not list. */
    public const DEFAULT_RANGE = '1y';

    /** The unbounded range: a series' whole history, up to MAX_ROWS. */
    public const MAX_RANGE = 'max';

    /** Rows one history request reads at most, whatever its range. */
    public const MAX_ROWS = 500000;

    // --- Redis Buffer ---
    /** Simulated years a chart_buffer list holds; a range no longer than this is drawn from it instead of from history. */
    public const BUFFERED_YEARS = 1.0 / 12.0;

    /** Simulated years a range spans, or null for the unbounded one. */
    public static function years(string $range): ?float
    {
        if ($range === self::MAX_RANGE) {
            return null;
        }

        return self::YEARS[$range] ?? self::YEARS[self::DEFAULT_RANGE];
    }

    /** Whether a range is short enough to be read from the Redis buffer. */
    public static function isBuffered(string $range): bool
    {
        $years = self::years($range);

        return $years !== null && $years <= self::BUFFERED_YEARS;
    }

    /** Entries a range spans in a series written $perYear times a simulated year; MAX_ROWS for the unbounded one. */
    public static function entries(string $range, float $perYear): int
    {
        $years = self::years($range);

        return $years === null ? self::MAX_ROWS : self::entriesOver($years, $perYear);
    }

    /** Entries a chart_buffer list keeps for a series pushed $perYear times a simulated year. */
    public static function bufferLength(float $perYear): int
    {
        return self::entriesOver(self::BUFFERED_YEARS, $perYear);
    }

    /**
     * The oldest sim_time a range includes, counted back from the series' own newest row.
     *
     * Anchored to the series rather than to the clock, so a delisted stock or a matured bond still shows the
     * last span it traded instead of an empty chart. Null when the range is unbounded or the series has no
     * dated row.
     */
    public static function simTimeFloor(string $range, ?float $newestSimTime): ?float
    {
        $years = self::years($range);

        return $years === null || $newestSimTime === null ? null : $newestSimTime - $years;
    }

    /**
     * Entries in a span, never fewer than one.
     *
     * Rounded before the ceiling, so a product that is whole in exact arithmetic is not pushed to the next
     * entry by float error: 3,600 × 1/12 is 300, not 301.
     */
    private static function entriesOver(float $years, float $perYear): int
    {
        return max(1, (int) ceil(round($perYear * $years, 6)));
    }
}
