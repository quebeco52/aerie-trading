<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Service\Market\TickCadence;
use PHPUnit\Framework\TestCase;

/**
 * Chart ranges are spans of simulated time, so they have to be derived from the configured tick rate.
 *
 * The stock page's range buttons used to carry hardcoded row counts ('1y' => 4800) that only matched a
 * 4,800-tick year. At the configured rate the "1Y" button was fetching sixteen months of prices and "3M"
 * four, so every span on the chart was wrong by the ratio between the two rates — and silently, because
 * a chart with too much data on it still draws.
 */
final class HistorySamplingRateTest extends TestCase
{
    /** Below the target a bar cannot be finer than a tick, so the rate is the tick rate itself. */
    public function testTickRatesBelowTheTargetRecordEveryTick(): void
    {
        $this->assertSame(252, TickCadence::historyPointsPerYear(252));
        $this->assertSame(720, TickCadence::historyPointsPerYear(720));
    }

    /**
     * Above it the rate is the target exactly, at any tick rate.
     *
     * 3,600 is the case that used to escape: the quotient is 1.5, the old cadence truncated it to a bar
     * every tick, and the ticker wrote half as many rows again as the target asks for while running the
     * flush, the bond mark and the reload on every one of them.
     */
    public function testTickRatesAboveTheTargetSampleDownToItExactly(): void
    {
        foreach ([2401, 3600, 7000, 7200, 14400, 54000, 864000] as $ticksPerYear) {
            $this->assertSame(
                TickCadence::TARGET_HISTORY_POINTS_PER_YEAR,
                TickCadence::historyPointsPerYear($ticksPerYear),
                "Sampling overshoots the target at {$ticksPerYear} ticks/year."
            );
        }
    }

    /** Never zero, whatever the configuration, since it is used as a divisor and a LIMIT. */
    public function testRateIsAlwaysPositive(): void
    {
        foreach ([1, 4, 252, 365, 3600, 7200, 14400, 54000, 864000] as $ticksPerYear) {
            $this->assertGreaterThan(0, TickCadence::historyPointsPerYear($ticksPerYear));
        }
    }

    /**
     * It matches what the ticker loop actually writes, counted off the loop's own predicate.
     *
     * The rate is what the chart's row LIMITs are derived from, so a rate that disagrees with the write
     * cadence silently mis-scales every range button on the stock page. Counting bars rather than asserting
     * an interval is the only form of this test that survives a rational grid.
     */
    public function testRateMatchesWhatTheTickerActuallyWrites(): void
    {
        foreach ([252, 720, 3600, 7200, 14400, 54000] as $ticksPerYear) {
            $bars = 0;

            for ($tick = 1; $tick <= $ticksPerYear; $tick++) {
                if (TickCadence::isHistoryTick($tick, $ticksPerYear)) {
                    $bars++;
                }
            }

            $this->assertSame(
                TickCadence::historyPointsPerYear($ticksPerYear),
                $bars,
                "Sampling rate disagrees with the ticker's own write cadence at {$ticksPerYear} ticks/year."
            );
        }
    }

    /**
     * The tick rate is a resolution knob: it may not decide which jobs a tick does.
     *
     * Every heavy job in the loop — the flush, the tick-column write, the bond mark, the working-set
     * reload — hangs off the bar flag, so a tick rate that drives the flag to true on every tick turns a
     * periodic bar job into a per-tick one. That is exactly what 3,600 did.
     */
    public function testNoTickRateTurnsEveryTickIntoABar(): void
    {
        foreach ([2401, 3000, 3600, 4800, 7000] as $ticksPerYear) {
            $bars = 0;

            for ($tick = 1; $tick <= $ticksPerYear; $tick++) {
                if (TickCadence::isHistoryTick($tick, $ticksPerYear)) {
                    $bars++;
                }
            }

            $this->assertLessThan(
                $ticksPerYear,
                $bars,
                "Every tick closes a bar at {$ticksPerYear} ticks/year; the bar cadence has collapsed."
            );
        }
    }

    /**
     * And the controller selects stored ranges as spans of simulated time rather than as row counts.
     *
     * A row count is right for one table at one rate: bond history is written once a mark, not once a bar,
     * and counting its rows at the bar rate drew nine and a half years under "1Y". See ChartRangeTest.
     */
    public function testHistoryEndpointDoesNotHardcodeRowCounts(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/src/Controller/StockController.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'ChartRange::simTimeFloor',
            $source,
            'Stored ranges must be selected by simulated time, not by a row count.'
        );
        // A bare integer against a range name is a row count; a fraction is a span of years.
        $this->assertDoesNotMatchRegularExpression(
            "/'(?:1w|1m|3m|6m|1y|3y|5y|10y)'\s*=>\s*\d+\s*[,\]]/",
            $source,
            'A hardcoded row count for a named range pins the chart to one tick rate.'
        );
    }
}
