<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Chart;

use App\Service\Market\Ticker\TickCadence;
use App\Service\Market\Chart\ChartRange;
use PHPUnit\Framework\TestCase;

/**
 * Which chart buffers a tick writes.
 *
 * A bond's clean price moves only on its daily mark, yet every bond was pushed into its chart buffer on every
 * tick: about 270 of the pipeline's ~350 writes a tick, the same number over and over. A bond now buffers its
 * mark and nothing between, and its list is kept at a month of marks rather than a month of ticks, so the
 * short ranges and the change figure still read a month back.
 */
final class ChartBufferWritesTest extends TestCase
{
    private const TICKS_PER_YEAR = 3600;

    /** @var list<array<string, mixed>> */
    private const EQUITY = [['ticker' => 'WING', 'price' => 10.0]];

    /** @var list<array<string, mixed>> */
    private const BONDS = [['ticker' => 'GOV-10Y', 'price' => 101.0, 'clean_price' => 100.0]];

    public function testABondIsBufferedOnItsMarkAndOnNoOtherTick(): void
    {
        $written = 0;

        for ($tick = 1; $tick <= self::TICKS_PER_YEAR; $tick++) {
            $writes = TickCadence::chartBufferWrites(self::EQUITY, self::BONDS, $tick, self::TICKS_PER_YEAR);
            $bondWritten = count($writes) === 2 && $writes[1][0] === self::BONDS;

            $this->assertSame(
                TickCadence::isBondMarkTick($tick, self::TICKS_PER_YEAR),
                $bondWritten,
                "Tick {$tick} buffers bonds off their mark, or misses one."
            );
            $written += $bondWritten ? 1 : 0;
        }

        $this->assertEqualsWithDelta(TickCadence::bondMarksPerYear(self::TICKS_PER_YEAR), $written, 1.0);
    }

    public function testStocksAndFundsAreBufferedEveryTickAndTrimmedOnBars(): void
    {
        for ($tick = 1; $tick <= 600; $tick++) {
            [$equity] = TickCadence::chartBufferWrites(self::EQUITY, self::BONDS, $tick, self::TICKS_PER_YEAR);

            $this->assertSame(self::EQUITY, $equity[0]);
            $this->assertSame(TickCadence::isHistoryTick($tick, self::TICKS_PER_YEAR), $equity[2], "Tick {$tick} trims off the bar.");
        }
    }

    /**
     * A fine tick grid does not grow the buffer: past the cap a stock is buffered every k-th tick, the list still
     * holds a month, and the short ranges count entries at the thinned rate.
     */
    public function testAFineTickGridThinsTheBufferToItsCap(): void
    {
        $this->assertSame(1, TickCadence::equityBufferStride(self::TICKS_PER_YEAR), 'Below the cap every tick is buffered.');

        foreach ([604800, 1209600, 302400] as $ticksPerYear) {
            $stride = TickCadence::equityBufferStride($ticksPerYear);
            $perYear = TickCadence::equityBufferEntriesPerYear($ticksPerYear);

            $this->assertLessThanOrEqual(TickCadence::MAX_BUFFER_ENTRIES_PER_YEAR, $perYear);
            $this->assertEqualsWithDelta($ticksPerYear / $stride, $perYear, 1e-9);

            $written = 0;
            for ($tick = 1; $tick <= 10 * $stride; $tick++) {
                $writes = TickCadence::chartBufferWrites(self::EQUITY, [], $tick, $ticksPerYear);
                $equityWritten = $writes !== [] && $writes[0][0] === self::EQUITY;
                $this->assertSame($tick % $stride === 0, $equityWritten, "Tick {$tick} at {$ticksPerYear}/yr.");
                if ($equityWritten) {
                    $written++;
                    $this->assertSame(ChartRange::bufferLength($perYear), $writes[0][1], 'The list holds a month of thinned entries.');
                }
            }
            $this->assertSame(10, $written);
        }

        // A year a week at one tick a second: ten ticks per entry, 5,040 entries a month instead of 50,400.
        $this->assertSame(10, TickCadence::equityBufferStride(604800));
        $this->assertSame(5040, ChartRange::bufferLength(TickCadence::equityBufferEntriesPerYear(604800)));
    }

    /** Each list keeps a month of its own entries: three hundred ticks, or twenty marks. */
    public function testEachBufferIsTrimmedToAMonthOfItsOwnEntries(): void
    {
        $markTick = null;
        for ($tick = 1; $markTick === null; $tick++) {
            if (TickCadence::isBondMarkTick($tick, self::TICKS_PER_YEAR)) {
                $markTick = $tick;
            }
        }

        [$equity, $bonds] = TickCadence::chartBufferWrites(self::EQUITY, self::BONDS, $markTick, self::TICKS_PER_YEAR);

        $this->assertSame(300, $equity[1]);
        $this->assertSame(20, $bonds[1]);
        $this->assertTrue($bonds[2], 'A bond list is trimmed on every push; it is pushed once a day.');
        $this->assertSame(ChartRange::entries('1m', TickCadence::bondMarksPerYear(self::TICKS_PER_YEAR)), $bonds[1]);
    }

    /** The rate the short ranges count bond entries at is the rate the ticker actually marks at. */
    public function testTheBondMarkRateIsTheMarksTheTickerStrikes(): void
    {
        foreach ([252, 720, 3600, 14400, 54000] as $ticksPerYear) {
            $marks = 0;
            for ($tick = 1; $tick <= 2 * $ticksPerYear; $tick++) {
                if (TickCadence::isBondMarkTick($tick, $ticksPerYear)) {
                    $marks++;
                }
            }

            $this->assertEqualsWithDelta(2 * TickCadence::bondMarksPerYear($ticksPerYear), $marks, 1.0, "At {$ticksPerYear} ticks/year.");
            $this->assertEqualsWithDelta(TickCadence::BOND_MARKS_PER_YEAR, TickCadence::bondMarksPerYear($ticksPerYear), 26.0);
        }
    }
}
