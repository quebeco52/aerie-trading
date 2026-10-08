<?php

namespace App\Service\Market\Chart;

/**
 * Reduces a price history series to the OHLCV bars a chart range actually renders.
 *
 * A stored history row is not a candle. At tick rates at or below TARGET_HISTORY_POINTS_PER_YEAR the
 * sampler writes one row per tick, so the row's open, high, low and close are all the same number and
 * every candle drawn from it is a doji — a chart of disconnected dashes where the line chart over the
 * same rows is continuous. ETF and bond history never carried a bar at all.
 *
 * The bar interval therefore belongs to the RANGE BEING VIEWED, not to the rate history was sampled at,
 * which is how a real charting backend works: a year is drawn as daily bars whatever resolution the ticks
 * arrived at. Rows are bucketed and aggregated — open of the oldest row, the extremes of every row in
 * between, close of the newest, volume summed — so no observation is discarded and consecutive bars meet.
 */
class PriceBarAggregator
{
    // --- Chart Resolution ---
    /** Bars a CANDLE range is reduced to; ~3px each on a typical chart, the width below which a candle stops being legible. */
    public const TARGET_BARS = 400;

    /**
     * Bars a LINE range is reduced to: roughly one slot per pixel on a wide chart.
     *
     * A line has no minimum legible width the way a candle does, and the bar grid is also the grid the LIVE
     * tail advances on: the chart holds its last point still until simulated time crosses a whole slot, then
     * steps the whole series left by one. At the candle target that step is 90 ms of wall clock on a one-year
     * range and 1.8 s on the longest, which reads as a freeze and a lurch rather than a moving price. Four
     * times the slots makes each step four times shorter AND four times narrower; past about one slot per
     * pixel a finer grid moves nothing the eye can resolve, so this is where the gain stops.
     */
    public const LINE_TARGET_BARS = 1600;

    /** Rows per bar floor: a bar built from one observation is a doji by construction and says nothing the line does not. */
    public const MIN_ROWS_PER_BAR = 2;

    /** Rows per slot floor for a LINE: a close needs no range of its own, and a row is never finer than the tick that advances the live tail. */
    public const LINE_MIN_ROWS_PER_BAR = 1;

    // --- Bucketing ---
    /** Tolerance on a row's bucket index, so a row stamped on a bar boundary is not pushed one bar older by float error. */
    private const BUCKET_EPSILON = 1e-6;

    /**
     * Folds a newest-first row stream into oldest-first OHLCV bars, each an equal slice of simulated time.
     *
     * Both sources are newest-first natively — the Redis buffer is lPush'd, the history tables are read
     * ORDER BY sim_time DESC — and the stream is consumed once, so a ten-year range never materialises its rows.
     *
     * The bars are cut in simulated time rather than in rows because the chart places them by index: a bar
     * per so many rows put thinned history (HistoryPruner) at the density of full-resolution history, so a
     * decade of it took the width of a week. Slices are counted back from the newest row, so the right edge
     * is always a whole bar, and each bar is dated by its age in slices: a slice with no rows yields no bar.
     *
     * @param iterable<array<string, mixed>> $newestFirstRows Rows carrying a 'price' and a 'sim_time'.
     * @param float                          $spanYears       Simulated years the rows cover.
     * @param float                          $rowsPerYear     Rows the series is written per simulated year, for the width floor.
     * @param int                            $targetBars      Bars to aim for across the span.
     * @param int                            $minRowsPerBar   Rows a bar spans at least: MIN_ROWS_PER_BAR for candles, LINE_MIN_ROWS_PER_BAR for a line.
     *
     * @return array{bar_years: float, bars: list<array<string, mixed>>} The slice width and the oldest-first bars,
     *         keyed as the history rows they replace plus 'age', the simulated years from the bar's end to the newest row.
     */
    public function aggregate(iterable $newestFirstRows, float $spanYears, float $rowsPerYear, int $targetBars = self::TARGET_BARS, int $minRowsPerBar = self::MIN_ROWS_PER_BAR): array
    {
        $barYears = max(
            max(1, $minRowsPerBar) / max(1e-9, $rowsPerYear),
            max(0.0, $spanYears) / max(1, $targetBars)
        );

        $bars = [];
        $bucket = null;
        $bucketIndex = null;
        $newestSimTime = null;

        foreach ($newestFirstRows as $row) {
            if (!isset($row['price'], $row['sim_time'])) {
                continue;
            }

            $simTime = (float) $row['sim_time'];
            $newestSimTime ??= $simTime;
            $index = (int) floor(($newestSimTime - $simTime) / $barYears + self::BUCKET_EPSILON);

            if ($bucket !== null && $index !== $bucketIndex) {
                $bars[] = $bucket;
                $bucket = null;
            }

            $close = (float) $row['price'];

            // A row that predates the bar columns, or an ETF or bond row that never had them, is a single
            // observation: its own price is its entire range. Falling back to the close keeps such rows in
            // the extremes rather than dropping them out of the high and low.
            $open = isset($row['open_price']) ? (float) $row['open_price'] : $close;
            $high = isset($row['high_price']) ? (float) $row['high_price'] : $close;
            $low = isset($row['low_price']) ? (float) $row['low_price'] : $close;

            if ($bucket === null) {
                // The first row of a newest-first bucket is the bar's close.
                $bucketIndex = $index;
                $bucket = [
                    'price' => $close,
                    'open_price' => $open,
                    'high_price' => $high,
                    'low_price' => $low,
                    'volume' => 0.0,
                    'has_volume' => false,
                    'age' => $index * $barYears,
                ];
            } else {
                $bucket['high_price'] = max($bucket['high_price'], $high);
                $bucket['low_price'] = min($bucket['low_price'], $low);
                // Walking backwards, the open is whatever the oldest row in the bucket leaves behind.
                $bucket['open_price'] = $open;
            }

            if (isset($row['volume'])) {
                $bucket['volume'] += (float) $row['volume'];
                $bucket['has_volume'] = true;
            }
        }

        if ($bucket !== null) {
            $bars[] = $bucket;
        }

        return [
            'bar_years' => $barYears,
            'bars' => array_reverse(array_map(static function (array $bar): array {
                $hasVolume = $bar['has_volume'];
                unset($bar['has_volume']);

                // Absent is not zero. An ETF series has no volume at all, and publishing zeroes would draw an
                // empty histogram pane under it rather than none.
                $bar['volume'] = $hasVolume ? (int) round($bar['volume']) : null;

                return $bar;
            }, $bars)),
        ];
    }
}
