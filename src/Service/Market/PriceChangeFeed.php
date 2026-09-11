<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Command\MarketTickerCommand;
use App\Entity\Stock;
use App\Entity\StockHistory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads each stock's price change over the buffered lookback window.
 *
 * Shared by the markets table, the stock page header and the district elevation — every surface
 * that prints a change figure reads it from here, so they cannot disagree.
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
 * costs one pipelined round trip rather than one call per stock.
 *
 * A stock whose buffer holds nothing usable — cold Redis after a restart, or a tail entry at
 * zero — falls back to the persisted `stock_history` rows the same ticker writes, reading the
 * oldest close inside the same one-month depth. That keeps a fresh ticker from printing a third
 * of the street as unknown while its buffers refill. The fallback is per stock and only fires for
 * the stocks Redis could not answer, so a warm cache still costs one round trip.
 *
 * Presentation only — nothing here is read back into the simulation.
 */
class PriceChangeFeed
{
    // --- Lookback ---
    /** Share of a simulated year the change figure looks back over: the one month the chart buffer holds. */
    public const LOOKBACK_YEARS = 1.0 / 12.0;

    /**
     * @param int $ticksPerYear simulated ticks in a year (app.ticks_per_year) — sets how many history rows one month is
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $ticksPerYear,
    ) {
    }

    /**
     * Persisted history rows one lookback window spans at the configured tick rate. History is
     * sampled at MarketTickerCommand::historyPointsPerYear(), not one row per tick, so the depth
     * has to be asked of the sampler rather than assumed.
     */
    public function historyRowsPerLookback(): int
    {
        return max(1, (int) ceil(MarketTickerCommand::historyPointsPerYear($this->ticksPerYear) * self::LOOKBACK_YEARS));
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
            $currentPrice = (float) $stock->getPrice();
            if ($currentPrice <= 0.0) {
                continue;
            }

            $pastPrice = $this->decodePrice($oldest[$index] ?? null);
            if ($pastPrice === null || $pastPrice <= 0.0) {
                $pastPrice = $this->oldestPersistedPriceInLookback($stock);
            }

            if ($pastPrice === null || $pastPrice <= 0.0) {
                continue;
            }

            $changes[$stock->getTicker()] = ($currentPrice - $pastPrice) / $pastPrice;
        }

        return $changes;
    }

    /**
     * The oldest close within one lookback window of persisted history for a stock, or null when
     * none is on file. Mirrors the buffer's own semantics: the newest historyRowsPerLookback()
     * rows are the month, and the last of them is the baseline; a shorter history degrades to
     * "as far back as we have", exactly as a half-filled buffer does.
     */
    private function oldestPersistedPriceInLookback(Stock $stock): ?float
    {
        /** @var list<array{price: string|float|null}> $rows */
        $rows = $this->entityManager->getRepository(StockHistory::class)->createQueryBuilder('h')
            ->select('h.price')
            ->andWhere('h.stock = :stock')
            ->setParameter('stock', $stock)
            ->orderBy('h.recordedAt', 'DESC')
            ->setMaxResults($this->historyRowsPerLookback())
            ->getQuery()
            ->getScalarResult();

        if ($rows === []) {
            return null;
        }

        $oldest = $rows[count($rows) - 1]['price'] ?? null;

        return $oldest === null ? null : (float) $oldest;
    }

    /**
     * Same reading for a single asset, addressed by ticker rather than by entity.
     *
     * The stock page renders both Stock and Etf through one template, and MarketTickerCommand
     * buffers the index alongside the stocks (`array_merge($stockUpdates, [$etfUpdate])`), so
     * asking by ticker is what lets one code path serve both without a Stock instance in hand.
     * For the same reason there is no history fallback here: without the entity there is no
     * telling which history table to read, and the buffer is the only source the two share.
     *
     * @return float|null Fractional change, or null when nothing usable is buffered — the caller
     *                    must render that as unknown rather than as flat.
     */
    public function changeForTicker(string $ticker, float $currentPrice): ?float
    {
        if ($ticker === '' || $currentPrice <= 0.0) {
            return null;
        }

        $pastPrice = $this->decodePrice($this->redis->lIndex("chart_buffer:{$ticker}", -1));

        if ($pastPrice === null || $pastPrice <= 0.0) {
            return null;
        }

        return ($currentPrice - $pastPrice) / $pastPrice;
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
