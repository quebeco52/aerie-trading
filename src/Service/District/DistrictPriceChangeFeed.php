<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Entity\Stock;

/**
 * Reads each tenant's price change over the district's lookback window.
 *
 * There is no persisted previous close, session open, or OHLC anywhere in the schema, and no
 * existing change-% logic to reuse — but App\Command\MarketTickerCommand already maintains a
 * per-ticker Redis list of recent prices for the stock page's short-range charts, and that buffer
 * answers this question for free:
 *
 *     chart_buffer:{TICKER}   newest-first, lTrim'd to ceil(ticksPerYear / 12) entries
 *
 * So the oldest entry still in the buffer is the price one simulated month ago. Reading index -1
 * rather than a fixed depth is deliberate: a buffer that has not filled yet (a freshly started
 * ticker) degrades to "as far back as we have" instead of returning false, and the whole roster
 * costs one pipelined round trip rather than one call per tenant.
 *
 * Presentation only — nothing here is read back into the simulation.
 */
class DistrictPriceChangeFeed
{
    public function __construct(
        private readonly \Redis $redis,
    ) {
    }

    /**
     * @param  Stock[] $stocks
     * @return array<string, float> ticker => fractional change (0.0125 = +1.25%), absent when no
     *                              usable history is buffered for that ticker
     */
    public function changeByTicker(array $stocks): array
    {
        if ($stocks === []) {
            return [];
        }

        $tickers = [];
        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        foreach ($stocks as $stock) {
            $tickers[] = $stock->getTicker();
            // -1 is the tail of a newest-first list: the oldest price still buffered.
            $pipeline->lIndex("chart_buffer:{$stock->getTicker()}", -1);
        }
        $oldest = $pipeline->exec();

        $changes = [];
        foreach ($stocks as $index => $stock) {
            $pastPrice = $this->decodePrice($oldest[$index] ?? null);
            $currentPrice = (float) $stock->getPrice();

            if ($pastPrice === null || $pastPrice <= 0.0 || $currentPrice <= 0.0) {
                continue;
            }

            $changes[$stock->getTicker()] = ($currentPrice - $pastPrice) / $pastPrice;
        }

        return $changes;
    }

    /** Pulls the price out of one buffered `{"price": …, "recorded_at": …}` entry. */
    private function decodePrice(mixed $entry): ?float
    {
        if (!is_string($entry)) {
            return null;
        }

        $decoded = json_decode($entry, true);

        return is_array($decoded) && isset($decoded['price']) ? (float) $decoded['price'] : null;
    }
}
