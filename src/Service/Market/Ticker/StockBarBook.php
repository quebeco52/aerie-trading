<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use Doctrine\DBAL\Connection;

/**
 * Open, high, low and volume accumulating between history writes, and the stock_history rows they close into.
 *
 * A history tick is one bar and many ticks fall inside it, so the extremes are carried rather than sampled: the
 * close alone cannot show that a name traded eight percent lower inside the bar and recovered. Plain arrays,
 * held outside the entity graph, so the bar survives the EntityManager clear on a reload tick.
 */
final class StockBarBook
{
    /** @var array<string, array{open: float, high: float, low: float, volume: float}> */
    private array $bars = [];

    /** @param array<int, array<string, mixed>> $stockUpdates This tick's stock quotes. */
    public function accumulate(array $stockUpdates): void
    {
        foreach ($stockUpdates as $update) {
            if (!empty($update['is_bankrupt'])) {
                continue;
            }

            $ticker = $update['ticker'];
            $price = (float) $update['price'];

            if (!isset($this->bars[$ticker])) {
                $this->bars[$ticker] = ['open' => $price, 'high' => $price, 'low' => $price, 'volume' => 0.0];
            }

            $this->bars[$ticker]['high'] = max($this->bars[$ticker]['high'], $price);
            $this->bars[$ticker]['low'] = min($this->bars[$ticker]['low'], $price);
            $this->bars[$ticker]['volume'] += (float) ($update['volume'] ?? 0.0);
        }
    }

    /**
     * Writes each closing price with the bar it belongs to, in one INSERT, then opens the next bar.
     *
     * @param array<int, array<string, mixed>> $history Closing rows from the stock phase: stock_id, ticker, price.
     */
    public function write(Connection $conn, array $history, float $simTime): void
    {
        if ($history !== []) {
            $insertValues = [];
            $params = [];
            $now = (new \DateTime())->format('Y-m-d H:i:s');

            foreach ($history as $row) {
                // Absent only for a name that appeared mid-bar, where a one-tick bar is the honest answer.
                $bar = $this->bars[$row['ticker']] ?? [
                    'open' => $row['price'],
                    'high' => $row['price'],
                    'low' => $row['price'],
                    'volume' => 0.0,
                ];

                $insertValues[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                $params[] = $row['stock_id'];
                $params[] = $row['price'];
                $params[] = $bar['open'];
                $params[] = max($bar['high'], (float) $row['price']);
                $params[] = min($bar['low'], (float) $row['price']);
                $params[] = (int) round($bar['volume']);
                $params[] = $now;
                $params[] = $simTime;
            }

            $conn->executeStatement(
                'INSERT INTO stock_history (stock_id, price, open_price, high_price, low_price, volume, recorded_at, sim_time) VALUES '
                . implode(', ', $insertValues),
                $params
            );
        }

        $this->bars = [];
    }
}
