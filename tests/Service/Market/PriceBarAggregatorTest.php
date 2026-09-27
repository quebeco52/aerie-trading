<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Command\MarketTickerCommand;
use App\Service\Market\PriceBarAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Turning history rows into candles.
 *
 * The bug these guard: at any tick rate at or below the history sampler's target, a stored row spans one
 * tick, so open, high, low and close are the same number and every candle is a doji. The chart drew a
 * scatter of disconnected dashes while the line chart over the same rows was continuous. The reader
 * reasonably read the gaps as missing data.
 *
 * The second bug: the endpoint reduced long ranges by keeping every Nth row and dropping the rest. That is
 * correct for a line — a subsample of closes is still a price path — and wrong for a bar, which is a
 * statement about everything that happened inside its interval.
 */
class PriceBarAggregatorTest extends TestCase
{
    private PriceBarAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new PriceBarAggregator();
    }

    /**
     * Builds newest-first rows the way the history query returns them.
     *
     * @param list<float>            $oldestFirstPrices
     * @param array<int, float>|null $volumes
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $oldestFirstPrices, ?array $volumes = null): array
    {
        $rows = [];
        foreach ($oldestFirstPrices as $index => $price) {
            $row = ['price' => $price, 'recorded_at' => '2026-09-10 12:00:00'];
            if ($volumes !== null) {
                $row['volume'] = $volumes[$index] ?? 0;
            }
            $rows[] = $row;
        }

        return array_reverse($rows);
    }

    /** A sawtooth walk: every step reverses, so a bar spanning several of them must show a real range. */
    private function sawtooth(int $count): array
    {
        $prices = [];
        for ($i = 0; $i < $count; $i++) {
            $prices[] = 100.0 + ($i % 2 === 0 ? 0.0 : 2.0) + ($i * 0.01);
        }

        return $prices;
    }

    /**
     * At any tick rate up to the sampling target a stored row spans one tick, which is a bar with no usable
     * range of its own; so is every Redis buffer entry and every fund and bond row at any rate. If this ever
     * fails the candle chart is back to drawing dojis.
     */
    public function testATickRateAtTheTargetWritesSingleTickRowsWithNoRangeOfTheirOwn(): void
    {
        foreach ([720, MarketTickerCommand::TARGET_HISTORY_POINTS_PER_YEAR] as $ticksPerYear) {
            $singleTickRows = 0;

            for ($tick = 2; $tick <= 1000; $tick++) {
                if (MarketTickerCommand::isHistoryTick($tick, $ticksPerYear)
                    && MarketTickerCommand::isHistoryTick($tick - 1, $ticksPerYear)) {
                    $singleTickRows++;
                }
            }

            self::assertGreaterThan(
                0,
                $singleTickRows,
                "At {$ticksPerYear} ticks/year a stored row spans a single tick and cannot carry a range, so the bar has to be built on read."
            );
        }
    }

    public function testSingleObservationRowsStillProduceBarsWithARange(): void
    {
        $bars = $this->aggregator->aggregate($this->rows($this->sawtooth(800)), 800);

        self::assertNotEmpty($bars);

        $withRange = 0;
        foreach ($bars as $bar) {
            if ($bar['high_price'] > $bar['low_price']) {
                $withRange++;
            }
        }

        self::assertSame(
            count($bars),
            $withRange,
            'Every bar aggregates several observations, so every bar has a high above its low.'
        );
    }

    public function testNoObservationIsDiscardedByTheReduction(): void
    {
        $prices = $this->sawtooth(5000);
        $bars = $this->aggregator->aggregate($this->rows($prices), 5000);

        // Decimation drops rows outright; bucketing has to keep every one of them inside some bar's range.
        foreach ($prices as $price) {
            $covered = false;
            foreach ($bars as $bar) {
                if ($price >= $bar['low_price'] - 1e-9 && $price <= $bar['high_price'] + 1e-9) {
                    $covered = true;
                    break;
                }
            }

            self::assertTrue($covered, sprintf('Price %.4f fell outside every bar.', $price));
        }
    }

    public function testAnIsolatedSpikeSurvivesAggregationIntoTheHigh(): void
    {
        $prices = array_fill(0, 1000, 100.0);
        $prices[437] = 180.0;

        $bars = $this->aggregator->aggregate($this->rows($prices), 1000);

        $highest = max(array_column($bars, 'high_price'));
        self::assertEqualsWithDelta(180.0, $highest, 1e-9, 'The spike must reach the high of the bar containing it.');
    }

    public function testBarsAreContiguousAcrossTheWholeSeries(): void
    {
        $prices = $this->sawtooth(2000);
        $bars = $this->aggregator->aggregate($this->rows($prices), 2000);

        // Bucket k's open is the row immediately after bucket k-1's close, so the series has no holes: the
        // opens and closes interleave in the order the observations arrived.
        $sourceIndex = 0;
        $bucketWidth = (int) ceil(2000 / PriceBarAggregator::TARGET_BARS);

        foreach ($bars as $bar) {
            self::assertEqualsWithDelta($prices[$sourceIndex], $bar['open_price'], 1e-9);
            $sourceIndex = min(count($prices) - 1, $sourceIndex + $bucketWidth);
        }
    }

    public function testTheFirstBarOpensAtTheOldestPriceAndTheLastClosesAtTheNewest(): void
    {
        $prices = $this->sawtooth(1200);
        $bars = $this->aggregator->aggregate($this->rows($prices), 1200);

        self::assertEqualsWithDelta($prices[0], $bars[0]['open_price'], 1e-9);
        self::assertEqualsWithDelta(end($prices), $bars[count($bars) - 1]['price'], 1e-9);
    }

    public function testStoredBarColumnsAreUsedWhenTheRowCarriesThem(): void
    {
        // A row written at a tick rate fast enough to aggregate carries a wick the close never touches.
        $rows = [
            ['price' => 101.0, 'open_price' => 100.0, 'high_price' => 140.0, 'low_price' => 90.0, 'volume' => 10],
            ['price' => 100.0, 'open_price' => 99.0, 'high_price' => 105.0, 'low_price' => 60.0, 'volume' => 5],
        ];

        $bars = $this->aggregator->aggregate($rows, 2);

        self::assertCount(1, $bars);
        self::assertEqualsWithDelta(140.0, $bars[0]['high_price'], 1e-9, 'The stored high must survive.');
        self::assertEqualsWithDelta(60.0, $bars[0]['low_price'], 1e-9, 'The stored low must survive.');
        self::assertEqualsWithDelta(99.0, $bars[0]['open_price'], 1e-9, 'Open comes from the oldest row.');
        self::assertEqualsWithDelta(101.0, $bars[0]['price'], 1e-9, 'Close comes from the newest row.');
    }

    public function testVolumeIsSummedRatherThanSampled(): void
    {
        $prices = array_fill(0, 1000, 100.0);
        $volumes = array_fill(0, 1000, 7.0);

        $bars = $this->aggregator->aggregate($this->rows($prices, $volumes), 1000);

        self::assertSame(7000, (int) array_sum(array_column($bars, 'volume')), 'Every row\'s volume is in a bar.');
    }

    public function testASeriesWithNoVolumeReportsNoneRatherThanZero(): void
    {
        // ETF and bond history are a single price series. A zero histogram would claim nothing traded;
        // the truth is that the instrument has no share volume to report.
        $bars = $this->aggregator->aggregate($this->rows($this->sawtooth(500)), 500);

        foreach ($bars as $bar) {
            self::assertNull($bar['volume']);
        }
    }

    public function testTheBarCountStaysNearTheTargetForALongRange(): void
    {
        $bars = $this->aggregator->aggregate($this->rows($this->sawtooth(36000)), 36000);

        self::assertLessThanOrEqual(PriceBarAggregator::TARGET_BARS, count($bars));
        self::assertGreaterThan(PriceBarAggregator::TARGET_BARS / 2, count($bars));
    }

    public function testAShortSeriesStillAggregatesRatherThanEmittingDojis(): void
    {
        $bars = $this->aggregator->aggregate($this->rows($this->sawtooth(70)), 70);

        self::assertCount((int) ceil(70 / PriceBarAggregator::MIN_ROWS_PER_BAR), $bars);
        foreach ($bars as $bar) {
            self::assertGreaterThan($bar['low_price'], $bar['high_price']);
        }
    }

    /**
     * The line grid exists to keep the LIVE tail moving.
     *
     * The chart holds its last point still until simulated time crosses a whole slot, so the slot width in
     * ticks is the dwell: at the candle target a one-year range steps once every nine ticks, which at the
     * configured tick rate is 90 ms of wall clock spent frozen before the whole series lurches left.
     */
    public function testTheLineGridDwellsFewerTicksPerSlotThanTheCandleGrid(): void
    {
        $ticksPerYear = 3600;
        $rowsPerYear = MarketTickerCommand::historyPointsPerYear($ticksPerYear);

        foreach ([1, 3, 10] as $years) {
            $rows = $this->rows($this->sawtooth($rowsPerYear * $years));

            $candleBars = count($this->aggregator->aggregate($rows, $rowsPerYear * $years, PriceBarAggregator::TARGET_BARS));
            $lineBars = count($this->aggregator->aggregate($rows, $rowsPerYear * $years, PriceBarAggregator::LINE_TARGET_BARS, PriceBarAggregator::LINE_MIN_ROWS_PER_BAR));

            $candleDwell = ($ticksPerYear * $years) / $candleBars;
            $lineDwell = ($ticksPerYear * $years) / $lineBars;

            self::assertLessThan(
                $candleDwell,
                $lineDwell,
                sprintf('A %dy line slot must span fewer ticks than the candle slot it replaces.', $years)
            );
            self::assertLessThanOrEqual(
                30.0,
                $lineDwell,
                sprintf('A %dy line slot spans %.1f ticks: the tail freezes that long between steps.', $years, $lineDwell)
            );
        }
    }

    /**
     * A slot narrower than one tick cannot be advanced a tick at a time, which is the only way the live tail
     * moves. The row floor is what keeps the served grid clear of it at the rate this project runs at.
     */
    public function testTheLineGridNeverServesASlotNarrowerThanATick(): void
    {
        foreach ([252, 720, MarketTickerCommand::TARGET_HISTORY_POINTS_PER_YEAR, 3600, 14400] as $ticksPerYear) {
            $rowsPerYear = MarketTickerCommand::historyPointsPerYear($ticksPerYear);

            $bars = $this->aggregator->aggregate(
                $this->rows($this->sawtooth($rowsPerYear)),
                $rowsPerYear,
                PriceBarAggregator::LINE_TARGET_BARS,
                PriceBarAggregator::LINE_MIN_ROWS_PER_BAR
            );

            self::assertLessThanOrEqual(
                $ticksPerYear,
                count($bars),
                "More slots than ticks at {$ticksPerYear} ticks/year would leave the grid finer than the clock that advances it."
            );
        }
    }

    /**
     * Coarser history must not coarsen the live tail.
     *
     * The chart steps one slot at a time, so a line served two rows a slot at 1,200 rows a year would dwell six
     * ticks where it dwelt three at 2,400. A close needs no range of its own, so a line is served one row a
     * slot up to its target, and the short ranges keep the three-tick step they had.
     */
    public function testALineIsServedOneRowPerSlotUpToItsTarget(): void
    {
        $ticksPerYear = 3600;
        $rowsPerYear = MarketTickerCommand::historyPointsPerYear($ticksPerYear);

        foreach (['3m' => 0.25, '1y' => 1.0] as $range => $years) {
            $rowCount = (int) round($rowsPerYear * $years);
            $bars = count($this->aggregator->aggregate(
                $this->rows($this->sawtooth($rowCount)),
                $rowCount,
                PriceBarAggregator::LINE_TARGET_BARS,
                PriceBarAggregator::LINE_MIN_ROWS_PER_BAR
            ));

            self::assertSame($rowCount, $bars, "The {$range} line merged rows it had room to draw.");
            self::assertLessThanOrEqual(3.0, $ticksPerYear * $years / $bars, "The {$range} line dwells longer than it did at 2,400 rows a year.");
        }
    }

    /** A candle still takes at least two rows: one observation has no range, and drawing it would be a doji. */
    public function testACandleStillTakesAtLeastTwoRows(): void
    {
        $bars = $this->aggregator->aggregate($this->rows($this->sawtooth(300)), 300, PriceBarAggregator::TARGET_BARS);

        self::assertCount(150, $bars);
    }

    public function testAnEmptySeriesProducesNoBars(): void
    {
        self::assertSame([], $this->aggregator->aggregate([], 0));
    }

    public function testRowsWithoutAPriceAreSkipped(): void
    {
        $rows = [
            ['price' => 102.0],
            ['recorded_at' => '2026-09-10 12:00:00'],
            ['price' => 100.0],
        ];

        $bars = $this->aggregator->aggregate($rows, 3);

        self::assertNotEmpty($bars);
        self::assertEqualsWithDelta(100.0, $bars[0]['open_price'], 1e-9);
    }
}
