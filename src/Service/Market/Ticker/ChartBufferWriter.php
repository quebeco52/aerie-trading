<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use App\Service\Market\Chart\PriceChangeFeed;

/**
 * Pushes each instrument's latest point onto its `chart_buffer:{TICKER}` list, the short-range chart's source.
 *
 * Stocks and funds buffer on the grid TickCadence::chartBufferWrites() sets, bonds only on their daily mark. At a
 * fine tick grid one entry stands for several ticks, so the volume traded since the last entry is carried here
 * and written with it. One Redis pipeline per tick.
 *
 * On a frame tick the same pipeline reads back each stock and fund buffer's oldest entry, the price the
 * window opened on, so the frame carries the past-month change PriceChangeFeed renders on first paint.
 */
final class ChartBufferWriter
{
    // --- Wire Precision ---
    /** Decimals a past-month change carries on the wire: a hundredth of a percentage point, the most a page prints. */
    public const CHANGE_DECIMALS = 4;

    /** @var array<string, float> Volume traded since each stock or fund's last buffer entry. */
    private array $volumeSinceEntry = [];

    public function __construct(
        private readonly \Redis $redis,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $quotes       Stock and fund quotes this tick.
     * @param array<int, array<string, mixed>> $bondUpdates  Every bond's quote this tick.
     * @param bool                             $readChanges  Read back each quote's past-month change (a frame tick).
     *
     * @return array<string, float|null> ticker => fractional past-month change, for the quotes read back
     */
    public function write(array $quotes, array $bondUpdates, int $tickCount, int $ticksPerYear, bool $readChanges = false): array
    {
        $nowStr = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($quotes as $update) {
            if (isset($update['volume'])) {
                $this->volumeSinceEntry[$update['ticker']] = ($this->volumeSinceEntry[$update['ticker']] ?? 0.0) + (float) $update['volume'];
            }
        }
        $buffers = TickCadence::chartBufferWrites($quotes, $bondUpdates, $tickCount, $ticksPerYear);

        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        $commands = 0;

        foreach ($buffers as [$bufferedUpdates, $redisBufferSize, $trim]) {
            foreach ($bufferedUpdates as $update) {
                if (!empty($update['is_bankrupt'])) {
                    continue; // The chart freezes where the company failed.
                }

                $cacheKey = "chart_buffer:{$update['ticker']}";

                // Bonds buffer the CLEAN price, as bond_history stores it: a dirty price would splice an accrual
                // sawtooth onto the flat history at the join, a jump the instrument never made.
                $point = ['price' => $update['clean_price'] ?? $update['price'], 'recorded_at' => $nowStr];

                // Funds and bonds have no share volume; the key stays absent, which tells the chart to draw no
                // histogram rather than an empty one.
                if (isset($update['volume'])) {
                    $point['volume'] = $this->volumeSinceEntry[$update['ticker']] ?? $update['volume'];
                    unset($this->volumeSinceEntry[$update['ticker']]);
                }
                // A bond's yield rides along, so its yield chart has the same short ranges as its price.
                if (isset($update['yield_to_maturity'])) {
                    $point['yield'] = $update['yield_to_maturity'];
                }

                $pipeline->lPush($cacheKey, json_encode($point));
                $commands++;

                if ($trim) {
                    $pipeline->lTrim($cacheKey, 0, $redisBufferSize - 1);
                    $commands++;
                }
            }
        }

        // After the pushes, so a buffer read back includes this tick's entry and is trimmed as the feed reads it.
        $readAt = [];
        if ($readChanges) {
            foreach ($quotes as $update) {
                if (empty($update['is_bankrupt'])) {
                    $readAt[(string) $update['ticker']] = $commands++;
                    $pipeline->lIndex("chart_buffer:{$update['ticker']}", -1);
                }
            }
        }

        $replies = $pipeline->exec();

        $changes = [];
        $pricesByTicker = array_column($quotes, 'price', 'ticker');
        foreach ($readAt as $ticker => $at) {
            $changes[$ticker] = PriceChangeFeed::change((float) ($pricesByTicker[$ticker] ?? 0.0), PriceChangeFeed::bufferedPrice($replies[$at] ?? null));
        }

        return $changes;
    }

    /**
     * Puts each read-back change on its quote as `change_percent`, rounded to what a page prints.
     *
     * @param list<array<string, mixed>> $quotes
     * @param array<string, float|null>  $changes ChartBufferWriter::write()'s return.
     *
     * @return list<array<string, mixed>>
     */
    public static function withChanges(array $quotes, array $changes): array
    {
        foreach ($quotes as $i => $quote) {
            if (array_key_exists((string) $quote['ticker'], $changes)) {
                $change = $changes[$quote['ticker']];
                $quotes[$i]['change_percent'] = $change === null ? null : round($change, self::CHANGE_DECIMALS);
            }
        }

        return $quotes;
    }
}
