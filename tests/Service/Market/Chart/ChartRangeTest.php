<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Chart;

use App\Service\Market\Ticker\TickCadence;
use App\Service\Market\Chart\ChartRange;
use PHPUnit\Framework\TestCase;

/**
 * A chart range is a span of simulated time in whatever series it is drawn from.
 *
 * The bug this guards: the history endpoint turned a range into a row count at the equity bar rate, 2,400 a
 * year, and applied it to bond_history too, which holds one row a mark, 252 a year. Every bond chart drew
 * nine and a half times the span its button named. Lowering the bar rate would have mis-scaled the stock
 * charts the same way, over the rows already written at the old rate.
 */
final class ChartRangeTest extends TestCase
{
    public function testEveryRangeButtonIsASpanOfYears(): void
    {
        foreach (['1w', '1m', '3m', '6m', '1y', '3y', '5y', '10y'] as $range) {
            $this->assertArrayHasKey($range, ChartRange::YEARS, "The {$range} button has no span.");
        }

        $this->assertSame(1.0, ChartRange::years('1y'));
        $this->assertSame(0.25, ChartRange::years('3m'));
        $this->assertNull(ChartRange::years(ChartRange::MAX_RANGE), 'The whole history has no bound.');
        $this->assertSame(1.0, ChartRange::years('7y'), 'An unknown range is served as the default.');
    }

    public function testOnlyTheWeekAndTheMonthAreReadFromTheBuffer(): void
    {
        $this->assertTrue(ChartRange::isBuffered('1w'));
        $this->assertTrue(ChartRange::isBuffered('1m'));

        foreach (['3m', '6m', '1y', '3y', '5y', '10y', ChartRange::MAX_RANGE, 'nonsense'] as $range) {
            $this->assertFalse(ChartRange::isBuffered($range), "{$range} is longer than the buffer holds.");
        }
    }

    /** A stock's buffer is pushed every tick, and at that rate the counts are the ones the endpoint always read. */
    public function testAStockBufferIsCountedInTicksExactlyAsBefore(): void
    {
        foreach ([252, 720, 3600, 14400, 54000] as $ticksPerYear) {
            $this->assertSame((int) ceil($ticksPerYear / 52), ChartRange::entries('1w', $ticksPerYear), "1w at {$ticksPerYear}");
            $this->assertSame((int) ceil($ticksPerYear / 12), ChartRange::entries('1m', $ticksPerYear), "1m at {$ticksPerYear}");
            $this->assertSame((int) ceil($ticksPerYear / 12), ChartRange::bufferLength($ticksPerYear), "buffer at {$ticksPerYear}");
        }
    }

    /** Float error must not add an entry: 3,600 × 1/12 is exactly the three hundred ticks in a month. */
    public function testAWholeNumberOfEntriesIsNotRoundedUpByFloatError(): void
    {
        $this->assertSame(300, ChartRange::entries('1m', 3600));
        $this->assertSame(20, ChartRange::bufferLength(240.0));
    }

    /** Whatever a series is pushed at, its list holds the longest range read from it and no more. */
    public function testTheBufferHoldsExactlyTheLongestRangeReadFromIt(): void
    {
        foreach ([3600.0, 14400.0, TickCadence::bondMarksPerYear(3600), TickCadence::bondMarksPerYear(14400)] as $perYear) {
            foreach (['1w', '1m'] as $range) {
                $this->assertLessThanOrEqual(ChartRange::bufferLength($perYear), ChartRange::entries($range, $perYear));
            }
            $this->assertSame(ChartRange::entries('1m', $perYear), ChartRange::bufferLength($perYear));
        }
    }

    /**
     * The regression: one range is one span of time in a series written at any rate.
     *
     * Five years of sim_time stamps at the bond mark rate, the new bar rate and the old one, selected by the
     * floor the endpoint applies. Each selection spans one year to within one of its own rows. The row count
     * the endpoint used to take instead is shown spanning nine and a half years of bond history.
     */
    public function testARangeIsTheSameSpanOfTimeInEverySeries(): void
    {
        foreach ([252, 1200, 2400] as $rowsPerYear) {
            $simTimes = [];
            for ($row = 1; $row <= 5 * $rowsPerYear; $row++) {
                $simTimes[] = $row / $rowsPerYear;
            }
            $newest = end($simTimes);

            $floor = ChartRange::simTimeFloor('1y', $newest);
            $this->assertNotNull($floor);
            $selected = array_values(array_filter($simTimes, static fn (float $t): bool => $t >= $floor));

            $this->assertEqualsWithDelta(1.0, $newest - $selected[0], 1.0 / $rowsPerYear, "1y spans the wrong time at {$rowsPerYear} rows a year.");
        }

        $oldBondRowCount = 1.0 * 2400;
        $this->assertEqualsWithDelta(9.5, $oldBondRowCount / 252, 0.05, 'The old row count, read against bond history.');
    }

    public function testTheFloorIsCountedBackFromTheSeriesOwnNewestRow(): void
    {
        $this->assertSame(9.0, ChartRange::simTimeFloor('1y', 10.0));
        $this->assertSame(9.75, ChartRange::simTimeFloor('3m', 10.0));
        $this->assertNull(ChartRange::simTimeFloor(ChartRange::MAX_RANGE, 10.0), 'The whole history has no floor.');
        $this->assertNull(ChartRange::simTimeFloor('1y', null), 'A series with no dated row has nothing to count back from.');
    }

    public function testTheUnboundedRangeIsCappedAtTheRowLimit(): void
    {
        $this->assertSame(ChartRange::MAX_ROWS, ChartRange::entries(ChartRange::MAX_RANGE, 3600));
    }
}
