<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MarketTickerCommand;
use App\Service\Market\OptionDeskService;
use PHPUnit\Framework\TestCase;

/**
 * The working-set reload is counted in history bars, because a clear() that does not follow a flush loses
 * the tick's changes. These pin the cadence at the shipped tick rate and at the edges.
 */
class MarketTickerCadenceTest extends TestCase
{
    public function testAtTheShippedRateTheWorkingSetReloadsAboutOnceATradingDay(): void
    {
        $ticksPerYear = 14400;

        $this->assertSame(10, MarketTickerCommand::reloadIntervalBars($ticksPerYear));
        $this->assertEqualsWithDelta(
            MarketTickerCommand::WORKING_SET_RELOADS_PER_YEAR,
            self::reloadsPerYear($ticksPerYear),
            MarketTickerCommand::WORKING_SET_RELOADS_PER_YEAR * 0.1
        );
    }

    /**
     * And at every other tick rate, because the reload is a real-time cost paid on a simulated clock.
     *
     * A reload clears the identity map and re-hydrates sixty companies and a couple of hundred bonds. It is
     * meant to happen once a simulated trading day whatever the tick rate; when the bar grid collapsed to a
     * bar per tick at 3,600 it happened every fourteen ticks, which at a 20ms tick is three times a second.
     */
    public function testTheReloadHoldsItsDailyCadenceAtEveryTickRate(): void
    {
        foreach ([3600, 7200, 14400, 54000] as $ticksPerYear) {
            $this->assertEqualsWithDelta(
                MarketTickerCommand::WORKING_SET_RELOADS_PER_YEAR,
                self::reloadsPerYear($ticksPerYear),
                MarketTickerCommand::WORKING_SET_RELOADS_PER_YEAR * 0.1,
                "The working set does not reload once a trading day at {$ticksPerYear} ticks/year."
            );
        }
    }

    /** Reloads the ticker loop would actually perform over one simulated year. */
    private static function reloadsPerYear(int $ticksPerYear): int
    {
        $reloads = 0;

        for ($tick = 1; $tick <= $ticksPerYear; $tick++) {
            if (MarketTickerCommand::isReloadTick($tick, $ticksPerYear)) {
                $reloads++;
            }
        }

        return $reloads;
    }

    public function testACoarseTickRateStillReloadsOnEveryBarRatherThanNever(): void
    {
        // 252 ticks a year: one tick is a day and one bar is a tick, so the reload is every bar.
        $this->assertSame(1, MarketTickerCommand::reloadIntervalBars(252));
        $this->assertSame(1, MarketTickerCommand::reloadIntervalBars(12));
    }

    public function testBondHistoryIsSampledOncePerTradingDayNotOncePerBar(): void
    {
        $ticksPerYear = 14400;
        $bars = MarketTickerCommand::bondHistoryIntervalBars($ticksPerYear);

        // Ten equity bars to one bond row: the ladder writes a tenth of the rows the board does, per bond.
        $this->assertSame(10, $bars);

        $rows = 0;
        for ($tick = 1; $tick <= $ticksPerYear; $tick++) {
            if (MarketTickerCommand::isBondHistoryTick($tick, $ticksPerYear)) {
                $rows++;
            }
        }

        $this->assertEqualsWithDelta(
            MarketTickerCommand::BOND_HISTORY_POINTS_PER_YEAR,
            $rows,
            MarketTickerCommand::BOND_HISTORY_POINTS_PER_YEAR * 0.1
        );
    }

    public function testACoarseTickRateStillSamplesBondsOnEveryBar(): void
    {
        $this->assertSame(1, MarketTickerCommand::bondHistoryIntervalBars(252));
        $this->assertSame(1, MarketTickerCommand::bondHistoryIntervalBars(12));
    }

    public function testTheDayJobsDoNotShareABar(): void
    {
        $ticksPerYear = 14400;
        $shared = 0;

        for ($bar = 0; $bar < 1000; $bar++) {
            if (MarketTickerCommand::isReloadBar($bar, $ticksPerYear)
                && MarketTickerCommand::isBondHistoryBar($bar, $ticksPerYear)) {
                $shared++;
            }
        }

        $this->assertSame(0, $shared, 'One tick must not pay for both the reload and the bond sample.');
    }

    /**
     * A reload clears the identity map, so it may only ever happen on a tick that has just flushed.
     *
     * The loop nests the reload inside the bar block, which makes this true by construction there — but the
     * predicate is what any other caller gets, and a bar-counted job addressed by tick has to carry its own
     * history-tick conjunction or it fires on a tick whose changes have not been written yet.
     */
    public function testAReloadOnlyEverHappensOnATickThatWroteABar(): void
    {
        foreach ([3600, 7200, 14400, 54000] as $ticksPerYear) {
            for ($tick = 1; $tick <= 20000; $tick++) {
                if (MarketTickerCommand::isReloadTick($tick, $ticksPerYear)) {
                    $this->assertTrue(
                        MarketTickerCommand::isHistoryTick($tick, $ticksPerYear),
                        "Tick {$tick} reloads the working set without having flushed at {$ticksPerYear} ticks/year."
                    );
                }
            }
        }
    }

    /** The same for the bond sample, which has to carry a mark struck on the tick that writes it. */
    public function testABondSampleOnlyEverHappensOnATickThatMarkedTheLadder(): void
    {
        foreach ([3600, 7200, 14400, 54000] as $ticksPerYear) {
            for ($tick = 1; $tick <= 20000; $tick++) {
                if (MarketTickerCommand::isBondHistoryTick($tick, $ticksPerYear)) {
                    $this->assertTrue(
                        MarketTickerCommand::isHistoryTick($tick, $ticksPerYear),
                        "Tick {$tick} samples the ladder without a mark at {$ticksPerYear} ticks/year."
                    );
                }
            }
        }
    }

    /**
     * And the two never land on the same TICK, which is the unit the cost is paid in.
     *
     * testTheDayJobsDoNotShareABar pins the offset in bar space. This pins what that offset is for: one
     * tick must not pay for both the reload and the two-hundred-row bond insert.
     */
    public function testTheDayJobsDoNotShareATick(): void
    {
        foreach ([3600, 7200, 14400, 54000] as $ticksPerYear) {
            $shared = 0;

            for ($tick = 1; $tick <= 20000; $tick++) {
                if (MarketTickerCommand::isReloadTick($tick, $ticksPerYear)
                    && MarketTickerCommand::isBondHistoryTick($tick, $ticksPerYear)) {
                    $shared++;
                }
            }

            $this->assertSame($shared, 0, "One tick pays for both day jobs at {$ticksPerYear} ticks/year.");
        }
    }

    public function testASweepPassNeverLandsOnAHistoryTick(): void
    {
        $ticksPerYear = 14400;
        $sweep = OptionDeskService::sweepIntervalTicks($ticksPerYear);
        $collisions = 0;

        for ($tick = 0; $tick < $sweep * 24; $tick++) {
            if (OptionDeskService::isSweepTick($tick, $sweep)
                && MarketTickerCommand::isHistoryTick($tick, $ticksPerYear)) {
                $collisions++;
            }
        }

        $this->assertSame(0, $collisions, 'A sweep landing on a bar makes that tick pay for both.');
    }

    public function testTheOffsetSweepStillVisitsEverySliceInOrder(): void
    {
        $sweep = OptionDeskService::sweepIntervalTicks(14400);
        $slices = [];

        for ($tick = 0; $tick < $sweep * OptionDeskService::SWEEP_SLICES; $tick++) {
            if (OptionDeskService::isSweepTick($tick, $sweep)) {
                $slices[] = OptionDeskService::sweepSlice($tick, $sweep);
            }
        }

        $this->assertSame(range(0, OptionDeskService::SWEEP_SLICES - 1), $slices);
    }

    public function testTheReloadNeverOutrunsAFullBar(): void
    {
        foreach ([252, 720, 3600, 14400, 54000, 864000] as $ticksPerYear) {
            $this->assertGreaterThanOrEqual(1, MarketTickerCommand::reloadIntervalBars($ticksPerYear), (string) $ticksPerYear);
        }
    }

    /**
     * A job whose period is written in simulated years fires once per year at ANY tick rate.
     *
     * This is what the retention pass hangs on, and it is the property the tick-count cadences do not have:
     * theirs coincide with a simulated interval only while `dt` stays `1/ticksPerYear` and the counter stays
     * in step with the clock, neither of which survives a rate change.
     */
    public function testASimulatedBoundaryIsCrossedExactlyOncePerPeriodAtEveryTickRate(): void
    {
        foreach ([252, 720, 1800, 2400, 3600, 7200, 14400, 54000] as $ticksPerYear) {
            $dt = 1.0 / $ticksPerYear;
            $accumulated = 0.0;
            $fired = [];

            // Accumulated, not reconstructed: the loop adds dt to the clock every tick, and the rounding of
            // that sum is the case that matters. 252 additions of 1/252 land at 0.99999999999999989, which a
            // plain comparison against the period reads as "the first year has not happened yet".
            for ($tick = 1; $tick <= 3 * $ticksPerYear; $tick++) {
                $accumulated += $dt;

                if (MarketTickerCommand::crossedSimulatedBoundary($accumulated, $dt, 1.0)) {
                    $fired[] = $tick;
                }
            }

            $this->assertSame(
                [$ticksPerYear, 2 * $ticksPerYear, 3 * $ticksPerYear],
                $fired,
                "Year boundary miscounted at {$ticksPerYear} ticks/year."
            );
        }
    }

    /** Nothing has elapsed before the first period, and a zero or negative period can never fire. */
    public function testABoundaryBeforeTheFirstPeriodNeverFires(): void
    {
        $dt = 1.0 / 3600;

        $this->assertFalse(MarketTickerCommand::crossedSimulatedBoundary(0.5, $dt, 1.0));
        $this->assertFalse(MarketTickerCommand::crossedSimulatedBoundary(0.0, $dt, 1.0));
        $this->assertFalse(MarketTickerCommand::crossedSimulatedBoundary(5.0, $dt, 0.0));
        $this->assertFalse(MarketTickerCommand::crossedSimulatedBoundary(5.0, 0.0, 1.0));
    }
}
