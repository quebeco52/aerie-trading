<?php

namespace App\Service\Market;

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
    /** Bars a range is reduced to; ~3px each on a typical chart, the width below which a candle stops being legible. */
    public const TARGET_BARS = 400;

    /** Rows per bar floor: a bar built from one observation is a doji by construction and says nothing the line does not. */
    public const MIN_ROWS_PER_BAR = 2;

    /**
     * Folds a newest-first row stream into oldest-first OHLCV bars.
     *
     * Both sources are newest-first natively — the Redis buffer is lPush'd, the history tables are read
     * ORDER BY id DESC — and the stream is consumed once, so a ten-year range never materialises its rows.
     *
     * @param iterable<array<string, mixed>> $newestFirstRows Rows carrying at least a 'price'.
     * @param int                            $rowCount        Rows the stream will yield; sets the bucket width.
     * @param int                            $targetBars      Bars to aim for across the range.
     *
     * @return list<array<string, mixed>> Oldest-first bars keyed as the history rows they replace.
     */
    public function aggregate(iterable $newestFirstRows, int $rowCount, int $targetBars = self::TARGET_BARS): array
    {
        $bucketWidth = max(
            self::MIN_ROWS_PER_BAR,
            (int) ceil($rowCount / max(1, $targetBars))
        );

        $bars = [];
        $bucket = null;
        $seen = 0;

        foreach ($newestFirstRows as $row) {
            if (!isset($row['price'])) {
                continue;
            }

            $close = (float) $row['price'];

            // A row that predates the bar columns, or an ETF or bond row that never had them, is a single
            // observation: its own price is its entire range. Falling back to the close keeps such rows in
            // the extremes rather than dropping them out of the high and low.
            $open = isset($row['open_price']) ? (float) $row['open_price'] : $close;
            $high = isset($row['high_price']) ? (float) $row['high_price'] : $close;
            $low = isset($row['low_price']) ? (float) $row['low_price'] : $close;

            if ($bucket === null) {
                // The first row of a newest-first bucket is the bar's close and dates it.
                $bucket = [
                    'price' => $close,
                    'open_price' => $open,
                    'high_price' => $high,
                    'low_price' => $low,
                    'volume' => 0.0,
                    'has_volume' => false,
                    'recorded_at' => $row['recorded_at'] ?? null,
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

            if (++$seen % $bucketWidth === 0) {
                $bars[] = $bucket;
                $bucket = null;
            }
        }

        // The trailing partial bucket is the OLDEST rows, since the stream runs backwards. The bar still
        // forming at the right edge is always whole.
        if ($bucket !== null) {
            $bars[] = $bucket;
        }

        return array_reverse(array_map(static function (array $bar): array {
            $hasVolume = $bar['has_volume'];
            unset($bar['has_volume']);

            // Absent is not zero. An ETF series has no volume at all, and publishing zeroes would draw an
            // empty histogram pane under it rather than none.
            $bar['volume'] = $hasVolume ? (int) round($bar['volume']) : null;

            return $bar;
        }, $bars));
    }
}
