<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use App\Entity\Etf;
use Doctrine\DBAL\Connection;

/**
 * The columns of `etfs` a fill is struck at, written as data on the ticks a frame goes out.
 *
 * The fund's own flush carries them on history ticks only, so between bars a desk reading the row quoted the fund at a
 * price the screens had moved past. The ticker writes these on every frame tick that is not a history tick, so the row
 * never lags a price a player has been shown.
 */
final class EtfTickColumns
{
    // --- Columns ---
    /** The quote columns, in the order the row values are laid out. */
    public const COLUMNS = [
        'price',
        'arbitrage_band',
        'nav_premium',
    ];

    /**
     * Writes the quote columns of every persisted fund in the working set.
     *
     * @param array<int|string, Etf> $funds
     * @return int Statements sent.
     */
    public static function write(Connection $connection, array $funds): int
    {
        $rows = self::rows($funds);

        if ($rows === []) {
            return 0;
        }

        return BulkRowUpdate::apply($connection, 'etfs', self::COLUMNS, $rows);
    }

    /**
     * The row set one write carries: id => values aligned with COLUMNS.
     *
     * @param array<int|string, Etf> $funds
     * @return array<int, array<int, mixed>>
     */
    public static function rows(array $funds): array
    {
        $rows = [];

        foreach ($funds as $fund) {
            $id = $fund->getId();

            if ($id === null) {
                continue;
            }

            $rows[$id] = [
                $fund->getPrice(),
                $fund->getArbitrageBand(),
                $fund->getNavPremium(),
            ];
        }

        return $rows;
    }
}
