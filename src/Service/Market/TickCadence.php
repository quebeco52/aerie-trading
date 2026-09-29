<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Service\Math\MathUtility;

/**
 * The ticker's calendar: which ticks close a history bar, and which bars reload the working set, mark the bond
 * ladder and write the chart buffers. Pure functions of the tick count and rate, shared by the ticker and by every
 * reader that turns a chart range or a price change back into ticks.
 */
final class TickCadence
{
    // --- History Sampling ---
    /** Target history bars per simulated year (~4.8 a trading day), each paying the flush, the tick-column write and a history row; the actual rate is this or one per tick, whichever is coarser. */
    public const TARGET_HISTORY_POINTS_PER_YEAR = 1200;

    // --- Working Set ---
    /** Times per simulated year the identity map is cleared and the working set re-read from the database: once a trading day. */
    public const WORKING_SET_RELOADS_PER_YEAR = 252;

    // --- Bond Marking ---
    /**
     * Times per simulated year the whole bond ladder is revalued and written: once a trading day, end of day.
     *
     * A bond's price is a function of a curve that moves in basis points over weeks, and the market convention
     * for fixed income is an end-of-day mark. Riding the equity bar instead revalued ~290 issues 2,400 times a
     * simulated year for a series recorded 252 times; a profile put the ladder at 24% of the ticker. Every
     * mark is also the day's bond_history row, so a row always carries the mark struck on its own tick.
     * Between marks an issue quotes its last mark; one that pays a coupon or is newly issued is struck at once
     * (see BondTracker). The Redis chart buffer takes the mark too, and nothing between: see bondMarksPerYear().
     */
    public const BOND_MARKS_PER_YEAR = 252;

    /**
     * History bars between working-set reloads.
     *
     * A reload has to follow a flush, so it is counted in bars rather than ticks. Reloading on every bar
     * re-hydrated sixty companies and a couple of hundred bonds eight times a second, which was a quarter
     * of the history tick; the only thing a reload brings in that the ticker cannot see otherwise is a
     * short-interest figure the web process writes, and nothing on the pricing path reads that.
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
     * Stocks and funds buffer every tick and are trimmed once a bar. A bond buffers its day's mark and nothing
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
        $writes = [[$equityUpdates, ChartRange::bufferLength($ticksPerYear), self::isHistoryTick($tickCount, $ticksPerYear)]];

        if (self::isBondMarkTick($tickCount, $ticksPerYear)) {
            $writes[] = [$bondUpdates, ChartRange::bufferLength(self::bondMarksPerYear($ticksPerYear)), true];
        }

        return $writes;
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
     * The loop holds a tick count, not a bar index, and asking it to carry one was a mistake that cost a
     * crash loop: `$bar` is a natural name for the open/high/low accumulator too, the history-row insert
     * reassigned it four hundred lines further down, and the reload below then received an array. Nothing
     * caught it — PHPStan reads the loop body as too complex to track a local through, and the loop itself
     * has no test, so the first thing to notice was the ticker restarting in production.
     *
     * Deriving the bar inside these wrappers is a multiply and an integer division against a job that
     * clears the identity map and re-hydrates four hundred entities. The name cannot collide with anything
     * because it no longer exists.
     */
    public static function isReloadTick(int $tickCount, int $ticksPerYear): bool
    {
        return self::isHistoryTick($tickCount, $ticksPerYear)
            && self::isReloadBar(self::barIndex($tickCount, $ticksPerYear), $ticksPerYear);
    }

    /**
     * Whether a history bar marks the bond ladder, and samples that mark into bond_history.
     *
     * Offset half an interval from the reload bar. Both are once-a-day jobs counted in the same bars, and
     * at the same offset every ladder mark would land on the tick that had just thrown its working set away
     * and re-read it — one tick paying for both, which is the shape of an outlier rather than of a cost.
     * Away from each other they are two ordinary bars.
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
     * a tick. This is the whole of the sampling rule; the grid below is derived from it rather than the
     * other way round.
     *
     * Anything converting a chart range into a row LIMIT has to ask this rather than assume a tick rate:
     * the range buttons used to carry hardcoded row counts that only matched a 4,800-tick year, so every
     * span on the stock page was wrong by whatever ratio the configured rate differed by.
     */
    public static function historyPointsPerYear(int $ticksPerYear): int
    {
        return max(1, min(self::TARGET_HISTORY_POINTS_PER_YEAR, $ticksPerYear));
    }

    /**
     * History bars closed by a given tick.
     *
     * THE BAR GRID IS RATIONAL, NOT A WHOLE NUMBER OF TICKS. Spacing bars by dividing the tick rate by the
     * target and truncating only lands on the target when one divides the other, and truncation is biased
     * toward writing too many at every rate where it does not: against the then target of 2,400, 7,000 ticks
     * a year wanted a bar every 2.9 ticks and got one every 2, which is 3,500 rows. At 3,600 the quotient was
     * 1.5, truncation reached the floor of 1, and a bar closed on EVERY tick — with the flush, the bond
     * mark, the tick-column write and the working-set reload all riding a flag that is never meant to be
     * true every tick. The tick rate is a resolution knob and nothing more; it must not decide which jobs
     * the ticker does.
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
        return MathUtility::crossedSimulatedBoundary($totalTime, $dt, $periodYears);
    }

    /** Whether a tick closes a history bar: the tick the bar count rolls over on. */
    public static function isHistoryTick(int $tickCount, int $ticksPerYear): bool
    {
        return $tickCount <= 0
            || self::barIndex($tickCount, $ticksPerYear) > self::barIndex($tickCount - 1, $ticksPerYear);
    }
}
