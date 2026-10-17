<?php

declare(strict_types=1);

namespace App\Service\Market\Trading;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Restates the order book on a split, for a stock and a fund alike: resting orders keep their distance from the price
 * in the new units, the escrow a reverse split would round away is refunded first, and executed history is restated
 * so the cost basis every portfolio surface derives from it stays whole.
 *
 * The factor is whole (4^k, 10^k) and bound as an integer. Bound as a string, MariaDB evaluates the arithmetic in
 * DOUBLE, and the overflow guard's BIGINT ceiling comes back as a double one past the column's range, which fails the
 * statement it guards.
 */
final class SplitOrderBook
{
    /** The largest value a BIGINT column holds: where a forward split's scaled quantity is capped. */
    public const BIGINT_MAX = '9223372036854775807';

    /** The factor's parameter type, for every statement that binds `:factor`. */
    public const FACTOR_TYPES = ['factor' => ParameterType::INTEGER];

    /** SQL for a column multiplied by :factor, capped at the BIGINT ceiling instead of overflowing. */
    public static function scaledUp(string $column): string
    {
        return sprintf('IF(%1$s > %2$s / :factor, %2$s, %1$s * :factor)', $column, self::BIGINT_MAX);
    }

    public static function restate(Connection $conn, string $ticker, int $factor, bool $isReverse, float $preSplitPrice): void
    {
        if (!$isReverse) {
            $conn->executeStatement(
                'UPDATE trade_orders
                 SET quantity = ' . self::scaledUp('quantity') . ',
                     limit_price = ROUND(limit_price / :factor, 4),
                     stop_price = ROUND(stop_price / :factor, 4)
                 WHERE ticker = :ticker AND status = \'OPEN\'',
                ['factor' => $factor, 'ticker' => $ticker],
                self::FACTOR_TYPES,
            );
            $conn->executeStatement(
                'UPDATE trade_orders
                 SET quantity = ' . self::scaledUp('quantity') . ',
                     filled_quantity = ' . self::scaledUp('filled_quantity') . ',
                     execution_price = ROUND(execution_price / :factor, 4),
                     limit_price = ROUND(limit_price / :factor, 4)
                 WHERE ticker = :ticker AND status = \'FILLED\'',
                ['factor' => $factor, 'ticker' => $ticker],
                self::FACTOR_TYPES,
            );

            return;
        }

        // Escrow first, on the pre-split book: the cash behind a resting limit BUY (a buy stop escrowed none) and the
        // shares behind a SELL, for the fraction the rewrite below rounds away.
        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN (
                 SELECT o.user_id,
                        SUM(CASE WHEN o.action = 'BUY' AND o.order_type NOT IN ('STOP', 'STOP_LIMIT')
                                 THEN COALESCE(o.limit_price, 0) * (o.quantity % :factor)
                                 WHEN o.action = 'SELL'
                                 THEN (o.quantity % :factor) * :pre_split_price
                                 ELSE 0
                            END) AS remnant_value
                 FROM trade_orders o
                 WHERE o.ticker = :ticker AND o.status = 'OPEN'
                 GROUP BY o.user_id
             ) remnants ON remnants.user_id = u.id
             SET u.cash_balance = u.cash_balance + remnants.remnant_value",
            ['factor' => $factor, 'ticker' => $ticker, 'pre_split_price' => $preSplitPrice],
            self::FACTOR_TYPES,
        );
        $conn->executeStatement(
            "UPDATE trade_orders
             SET quantity = FLOOR(quantity / :factor),
                 limit_price = ROUND(limit_price * :factor, 4),
                 stop_price = ROUND(stop_price * :factor, 4)
             WHERE ticker = :ticker AND status = 'OPEN'",
            ['factor' => $factor, 'ticker' => $ticker],
            self::FACTOR_TYPES,
        );
        $conn->executeStatement(
            "UPDATE trade_orders SET status = 'CANCELLED' WHERE ticker = :ticker AND status = 'OPEN' AND quantity = 0",
            ['ticker' => $ticker],
        );
        // An executed trade is never floored out of existence: it keeps at least one unit at its restated price.
        $conn->executeStatement(
            "UPDATE trade_orders
             SET quantity = GREATEST(FLOOR(quantity / :factor), 1),
                 filled_quantity = GREATEST(FLOOR(filled_quantity / :factor), 1),
                 execution_price = ROUND(execution_price * :factor, 4),
                 limit_price = ROUND(limit_price * :factor, 4)
             WHERE ticker = :ticker AND status = 'FILLED'",
            ['factor' => $factor, 'ticker' => $ticker],
            self::FACTOR_TYPES,
        );
    }
}
