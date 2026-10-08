<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

/**
 * Pushes each instrument's latest point onto its `chart_buffer:{TICKER}` list, the short-range chart's source.
 *
 * Stocks and funds buffer on the grid TickCadence::chartBufferWrites() sets, bonds only on their daily mark. At a
 * fine tick grid one entry stands for several ticks, so the volume traded since the last entry is carried here
 * and written with it. One Redis pipeline per tick.
 */
final class ChartBufferWriter
{
    /** @var array<string, float> Volume traded since each stock or fund's last buffer entry. */
    private array $volumeSinceEntry = [];

    public function __construct(
        private readonly \Redis $redis,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $quotes      Stock and fund quotes this tick.
     * @param array<int, array<string, mixed>> $bondUpdates Every bond's quote this tick.
     */
    public function write(array $quotes, array $bondUpdates, int $tickCount, int $ticksPerYear): void
    {
        $nowStr = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($quotes as $update) {
            if (isset($update['volume'])) {
                $this->volumeSinceEntry[$update['ticker']] = ($this->volumeSinceEntry[$update['ticker']] ?? 0.0) + (float) $update['volume'];
            }
        }
        $buffers = TickCadence::chartBufferWrites($quotes, $bondUpdates, $tickCount, $ticksPerYear);

        $pipeline = $this->redis->multi(\Redis::PIPELINE);

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

                if ($trim) {
                    $pipeline->lTrim($cacheKey, 0, $redisBufferSize - 1);
                }
            }
        }

        $pipeline->exec();
    }
}
