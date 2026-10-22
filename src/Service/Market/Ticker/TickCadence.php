<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use App\Service\Market\Chart\ChartRange;
use App\Service\Math\TimeSeries;

/**
 * The ticker's calendar: which ticks close a history bar, and which bars reload the working set, mark the bond
 * ladder and write the chart buffers. Pure functions of the tick count and rate, shared by the ticker and by every
 * reader that turns a chart range or a price change back into ticks.
 */
final class TickCadence
{
    // --- History Sampling ---
    /**
     * Target history bars per simulated year (~4.8 a trading day), each paying the flush, the tick-column write and a
     * history row; the actual rate is this or one per tick, whichever is coarser.
     */
    public const TARGET_HISTORY_POINTS_PER_YEAR = 1200;

    // --- Chart Buffer ---
    /**
     * Most entries a stock or fund chart buffer takes per simulated year (~240 a trading day); above it the buffer
     * keeps every k-th tick, so a slow wall clock with a fine tick grid does not grow Redis by a month of ticks per
     * name.
     */
    public const MAX_BUFFER_ENTRIES_PER_YEAR = 60480;

    // --- Working Set ---
    /**
     * Times per simulated year the identity map is cleared and the working set re-read from the database: once a
     * trading day.
     */
    public const WORKING_SET_RELOADS_PER_YEAR = 252;

    // --- Bond Marking ---
    /**
     * Times per simulated year the whole bond ladder is revalued and written: once a trading day, end of day.
     *
     * A bond's price is a function of a curve that moves in basis points over weeks, and the market convention for
     * fixed income is an end-of-day mark. Every mark is also the day's bond_history row, so a row always carries the
     * mark struck on its own tick. Between marks an issue quotes its last mark; one that pays a coupon or is newly
     * issued is struck at once (see BondTracker). The Redis chart buffer takes the mark too, and nothing between: see
     * bondMarksPerYear().
     */
    public const BOND_MARKS_PER_YEAR = 252;

    /**
     * History bars between working-set reloads.
     *
     * A reload has to follow a flush, so it is counted in bars rather than ticks. The only thing a reload brings
     * in that the ticker cannot see otherwise is a short-interest figure the web process writes, and nothing on
     * the pricing path reads that.
     */
    public static function reloadIntervalBars(int $ticksPerYear): int
    {
        return self::intervalBars($ticksPerYear, self::WORKING_SET_RELOADS_PER_YEAR);
    }

    /**
     * History bars between marks of the bond ladder.
     *
     * Counted in bars so the mark lands on a tick the ticker loop already treats as a bar, where the history row it
     * writes belongs.
     */
    public static function bondMarkIntervalBars(int $ticksPerYear): int
    {
        return self::intervalBars($ticksPerYear, self::BOND_MARKS_PER_YEAR);
    }

    /**
     * Marks the bond ladder actually receives per simulated year, which is also the rate its chart buffer fills.
     *
     * BOND_MARKS_PER_YEAR is the target; the mark rides the bar grid in whole bars, so the realised rate is
     * the bar rate over that interval (240 at 1,200 bars). The short chart ranges count buffer entries at
     * this rate, and a bond's buffer is trimmed at it.
     */
    public static function bondMarksPerYear(int $ticksPerYear): float
    {
        return self::historyPointsPerYear($ticksPerYear) / self::bondMarkIntervalBars($ticksPerYear);
    }

    /**
     * The chart buffers one tick writes: which quotes, the length each list is trimmed to, and whether it trims.
     *
     * Stocks and funds buffer every tick (every equityBufferStride() ticks at a fine tick grid) and are trimmed once a
     * bar. A bond buffers its day's mark and nothing
     * between, because its clean price moves on nothing else: pushing the same number every tick was three
     * quarters of the buffer pipeline. Each list keeps the month the short ranges read, counted in its own
     * entries (see ChartRange).
     *
     * @param array<int, array<string, mixed>> $equityUpdates Stock and fund quotes.
     * @param array<int, array<string, mixed>> $bondUpdates
     * @return list<array{0: array<int, array<string, mixed>>, 1: int, 2: bool}>
     */
    public static function chartBufferWrites(array $equityUpdates, array $bondUpdates, int $tickCount, int $ticksPerYear): array
    {
        $writes = [];

        if (self::isEquityBufferTick($tickCount, $ticksPerYear)) {
            $writes[] = [$equityUpdates, ChartRange::bufferLength(self::equityBufferEntriesPerYear($ticksPerYear)), self::isHistoryTick($tickCount, $ticksPerYear)];
        }

        if (self::isBondMarkTick($tickCount, $ticksPerYear)) {
            $writes[] = [$bondUpdates, ChartRange::bufferLength(self::bondMarksPerYear($ticksPerYear)), true];
        }

        return $writes;
    }

    /** Ticks between two entries of a stock or fund chart buffer: one, until the tick rate passes the buffer cap. */
    public static function equityBufferStride(int $ticksPerYear): int
    {
        return max(1, (int) ceil($ticksPerYear / self::MAX_BUFFER_ENTRIES_PER_YEAR));
    }

    /**
     * Entries a stock or fund chart buffer receives per simulated year, which is the rate its short ranges are counted
     * in.
     */
    public static function equityBufferEntriesPerYear(int $ticksPerYear): float
    {
        return max(1, $ticksPerYear) / self::equityBufferStride($ticksPerYear);
    }

    /** Whether a tick writes the stock and fund chart buffers. */
    public static function isEquityBufferTick(int $tickCount, int $ticksPerYear): bool
    {
        return $tickCount % self::equityBufferStride($ticksPerYear) === 0;
    }

    /** History bars between events that should happen a given number of times a simulated year. */
    private static function intervalBars(int $ticksPerYear, int $perYear): int
    {
        return max(1, (int) round(self::historyPointsPerYear($ticksPerYear) / $perYear));
    }

    /** Whether a history bar re-reads the working set. */
    public static function isReloadBar(int $bar, int $ticksPerYear): bool
    {
        return $bar % self::reloadIntervalBars($ticksPerYear) === 0;
    }

    /**
     * Whether a TICK re-reads the working set: the bar-counted job, addressed the way the ticker loop has it.
     *
     * The loop holds a tick count, not a bar index; the bar is derived inside this wrapper, which costs a multiply
     * and an integer division against a job that clears the identity map and re-hydrates the working set.
     */
    public static function isReloadTick(int $tickCount, int $ticksPerYear): bool
    {
        return self::isHistoryTick($tickCount, $ticksPerYear)
            && self::isReloadBar(self::barIndex($tickCount, $ticksPerYear), $ticksPerYear);
    }

    /**
     * Whether a history bar marks the bond ladder, and samples that mark into bond_history.
     *
     * Offset half an interval from the reload bar, so the two once-a-day jobs do not land on one tick.
     */
    public static function isBondMarkBar(int $bar, int $ticksPerYear): bool
    {
        $bars = self::bondMarkIntervalBars($ticksPerYear);

        return $bar % $bars === intdiv($bars, 2);
    }

    /** Whether a TICK marks the bond ladder: see isReloadTick() for why this is addressed by tick. */
    public static function isBondMarkTick(int $tickCount, int $ticksPerYear): bool
    {
        return self::isHistoryTick($tickCount, $ticksPerYear)
            && self::isBondMarkBar(self::barIndex($tickCount, $ticksPerYear), $ticksPerYear);
    }

    /**
     * Price history rows written per simulated year at a given tick rate.
     *
     * The target, or the tick rate itself when that is the coarser of the two — a bar cannot be finer than
     * a tick. This is the whole of the sampling rule; the grid below is derived from it.
     *
     * Anything converting a chart range into a row LIMIT has to ask this rather than assume a tick rate.
     */
    public static function historyPointsPerYear(int $ticksPerYear): int
    {
        return max(1, min(self::TARGET_HISTORY_POINTS_PER_YEAR, $ticksPerYear));
    }

    /**
     * History bars closed by a given tick.
     *
     * THE BAR GRID IS RATIONAL, NOT A WHOLE NUMBER OF TICKS. Spacing bars by truncating the tick rate over the
     * target only lands on the target when one divides the other, and is biased toward too many bars elsewhere
     * (down to a bar on every tick). The tick rate is a resolution knob and must not decide which jobs run.
     *
     * Counting bars rather than spacing them spreads the remainder evenly and closes exactly
     * historyPointsPerYear() of them per simulated year at any tick rate. It is a pure function of the tick
     * count, so it survives a restart with no accumulator to carry, and it is exact wherever the target
     * does divide the rate: at 14,400 this is still every sixth tick to the tick.
     */
    public static function barIndex(int $tickCount, int $ticksPerYear): int
    {
        return intdiv(max(0, $tickCount) * self::historyPointsPerYear($ticksPerYear), max(1, $ticksPerYear));
    }

    /**
     * Whether this tick crossed a boundary of the given period in SIMULATED time.
     *
     * The tick-count cadences elsewhere in the ticker loop only equal a simulated interval because `dt` happens to
     * be `1/ticksPerYear` for the whole of a run; they carry no meaning across a rate change or a counter
     * that has drifted from the clock. A job whose period is written in simulated years asks the clock.
     */
    public static function crossedSimulatedBoundary(float $totalTime, float $dt, float $periodYears): bool
    {
        return TimeSeries::crossedSimulatedBoundary($totalTime, $dt, $periodYears);
    }

    /** Whether a tick closes a history bar: the tick the bar count rolls over on. */
    public static function isHistoryTick(int $tickCount, int $ticksPerYear): bool
    {
        return $tickCount <= 0
            || self::barIndex($tickCount, $ticksPerYear) > self::barIndex($tickCount - 1, $ticksPerYear);
    }
}
