<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MarketTickerCommand;
use App\Service\Market\ChartRange;
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
            $writes = MarketTickerCommand::chartBufferWrites(self::EQUITY, self::BONDS, $tick, self::TICKS_PER_YEAR);
            $bondWritten = count($writes) === 2 && $writes[1][0] === self::BONDS;

            $this->assertSame(
                MarketTickerCommand::isBondMarkTick($tick, self::TICKS_PER_YEAR),
                $bondWritten,
                "Tick {$tick} buffers bonds off their mark, or misses one."
            );
            $written += $bondWritten ? 1 : 0;
        }

        $this->assertEqualsWithDelta(MarketTickerCommand::bondMarksPerYear(self::TICKS_PER_YEAR), $written, 1.0);
    }

    public function testStocksAndFundsAreBufferedEveryTickAndTrimmedOnBars(): void
    {
        for ($tick = 1; $tick <= 600; $tick++) {
            [$equity] = MarketTickerCommand::chartBufferWrites(self::EQUITY, self::BONDS, $tick, self::TICKS_PER_YEAR);

            $this->assertSame(self::EQUITY, $equity[0]);
            $this->assertSame(MarketTickerCommand::isHistoryTick($tick, self::TICKS_PER_YEAR), $equity[2], "Tick {$tick} trims off the bar.");
        }
    }

    /** Each list keeps a month of its own entries: three hundred ticks, or twenty marks. */
    public function testEachBufferIsTrimmedToAMonthOfItsOwnEntries(): void
    {
        $markTick = null;
        for ($tick = 1; $markTick === null; $tick++) {
            if (MarketTickerCommand::isBondMarkTick($tick, self::TICKS_PER_YEAR)) {
                $markTick = $tick;
            }
        }

        [$equity, $bonds] = MarketTickerCommand::chartBufferWrites(self::EQUITY, self::BONDS, $markTick, self::TICKS_PER_YEAR);

        $this->assertSame(300, $equity[1]);
        $this->assertSame(20, $bonds[1]);
        $this->assertTrue($bonds[2], 'A bond list is trimmed on every push; it is pushed once a day.');
        $this->assertSame(ChartRange::entries('1m', MarketTickerCommand::bondMarksPerYear(self::TICKS_PER_YEAR)), $bonds[1]);
    }

    /** The rate the short ranges count bond entries at is the rate the ticker actually marks at. */
    public function testTheBondMarkRateIsTheMarksTheTickerStrikes(): void
    {
        foreach ([252, 720, 3600, 14400, 54000] as $ticksPerYear) {
            $marks = 0;
            for ($tick = 1; $tick <= 2 * $ticksPerYear; $tick++) {
                if (MarketTickerCommand::isBondMarkTick($tick, $ticksPerYear)) {
                    $marks++;
                }
            }

            $this->assertEqualsWithDelta(2 * MarketTickerCommand::bondMarksPerYear($ticksPerYear), $marks, 1.0, "At {$ticksPerYear} ticks/year.");
            $this->assertEqualsWithDelta(MarketTickerCommand::BOND_MARKS_PER_YEAR, MarketTickerCommand::bondMarksPerYear($ticksPerYear), 26.0);
        }
    }
}
