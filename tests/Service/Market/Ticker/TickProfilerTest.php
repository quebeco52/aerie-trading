<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Ticker;

use App\Service\Market\Ticker\TickProfiler;
use PHPUnit\Framework\TestCase;

/**
 * The lag warning names the costliest phases of the tick that overran, and the window report is a per-tick mean
 * over a full window, not a sample of whichever tick happened to close it.
 */
final class TickProfilerTest extends TestCase
{
    public function testTheBreakdownNamesTheCostliestPhasesFirst(): void
    {
        $profiler = new TickProfiler();
        $profiler->startTick();
        foreach (['macro' => 1.0, 'stocks' => 9.0, 'bonds' => 3.0, 'etfs' => 2.0, 'flush' => 5.0] as $phase => $ms) {
            $profiler->record($phase, $ms);
        }

        self::assertSame('stocks 9.0, flush 5.0, bonds 3.0, etfs 2.0', $profiler->breakdown());
    }

    public function testAPhaseIsEverythingSinceTheLastLap(): void
    {
        $profiler = new TickProfiler();
        $profiler->startTick();
        $profiler->lap('first');
        usleep(2000);
        $profiler->lap('second');

        [$second, $first] = array_map('floatval', array_map(
            static fn (string $part): string => explode(' ', $part)[1],
            explode(', ', $profiler->breakdown())
        ));
        self::assertGreaterThanOrEqual(2.0, $second);
        self::assertLessThan($second, $first);
    }

    public function testTheWindowReportsPerTickMeansOnceFull(): void
    {
        $profiler = new TickProfiler();

        for ($tick = 1; $tick < TickProfiler::PHASE_REPORT_INTERVAL_TICKS; $tick++) {
            $profiler->startTick();
            $profiler->record('options', $tick % 2 === 0 ? 6.0 : 0.0);
            self::assertSame([], $profiler->closeTick(4.0, false, $tick, 10.0));
        }

        $profiler->startTick();
        $profiler->record('options', 6.0);
        $report = $profiler->closeTick(16.0, true, TickProfiler::PHASE_REPORT_INTERVAL_TICKS, 10.0);

        self::assertCount(2, $report);
        self::assertStringContainsString('1 over (0%)', $report[0]);
        self::assertStringContainsString('max 16.0ms', $report[0]);
        // Every other tick sweeps at 6 ms, so the sweep costs 3 ms per tick, cadence included.
        self::assertStringContainsString('options 3.00', $report[1]);

        // The next window starts empty.
        $profiler->startTick();
        self::assertSame([], $profiler->closeTick(1.0, false, 1, 10.0));
    }
}
