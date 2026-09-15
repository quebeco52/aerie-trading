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
        $bars = MarketTickerCommand::reloadIntervalBars($ticksPerYear);
        $ticksBetweenReloads = $bars * MarketTickerCommand::historyIntervalTicks($ticksPerYear);

        $this->assertSame(10, $bars);
        $this->assertEqualsWithDelta(
            $ticksPerYear / MarketTickerCommand::WORKING_SET_RELOADS_PER_YEAR,
            $ticksBetweenReloads,
            MarketTickerCommand::historyIntervalTicks($ticksPerYear)
        );
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
        $this->assertEqualsWithDelta(
            $ticksPerYear / MarketTickerCommand::BOND_HISTORY_POINTS_PER_YEAR,
            $bars * MarketTickerCommand::historyIntervalTicks($ticksPerYear),
            MarketTickerCommand::historyIntervalTicks($ticksPerYear)
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

    public function testASweepPassNeverLandsOnAHistoryTick(): void
    {
        $ticksPerYear = 14400;
        $history = MarketTickerCommand::historyIntervalTicks($ticksPerYear);
        $sweep = OptionDeskService::sweepIntervalTicks($ticksPerYear);
        $collisions = 0;

        for ($tick = 0; $tick < $history * $sweep * 4; $tick++) {
            if (OptionDeskService::isSweepTick($tick, $sweep) && $tick % $history === 0) {
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
}
