<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing raw SQL updates related to corporate actions 
 * (Dividends, Splits, Reverse Splits). Separates domain logic from persistence.
 */
class CorporateLedgerService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Distributes dividend payments to all users holding the stock or having open SELL orders.
     */
    public function processDividendPayment(Stock $stock, float $dividendPerShare): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE users u
             INNER JOIN (
                 SELECT user_id, SUM(total_qty) AS total_shares
                 FROM (
                     SELECT user_id, quantity AS total_qty FROM user_stocks WHERE stock_id = :stock_id
                     UNION ALL
                     SELECT user_id, quantity AS total_qty FROM trade_orders WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'
                 ) combined_shares
                 GROUP BY user_id
             ) holdings ON u.id = holdings.user_id
             SET u.cash_balance = u.cash_balance + (holdings.total_shares * :dividend)",
            ['dividend' => $dividendPerShare, 'stock_id' => $stock->getId(), 'ticker' => $stock->getTicker()]
        );
    }

    /**
     * Adjusts ledgers and history for a stock split or reverse split.
     * 
     * @param Stock $stock The stock entity.
     * @param float $splitFactor The multiplier or divisor for the split.
     * @param bool $isReverse Whether it is a reverse split (where shares divide and price multiplies).
     * @param float|null $oldPrice Required for reverse splits to calculate cashout value.
     */
    public function processStockSplit(Stock $stock, float $splitFactor, bool $isReverse = false, ?float $oldPrice = null): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        
        try {
            if ($isReverse) {
                // 1. Fetch all user stock holdings for this ticker before mutating
                $holdings = $conn->fetchAllAssociative(
                    'SELECT id, user_id, quantity FROM user_stocks WHERE stock_id = :stock_id AND quantity > 0',
                    ['stock_id' => $stock->getId()]
                );

                // 2. Process cashouts for fractional remnants
                foreach ($holdings as $holding) {
                    $qty = (float) $holding['quantity'];
                    $newQty = floor($qty / $splitFactor);
                    $remnant = $qty - ($newQty * $splitFactor);

                    if ($remnant > 0 && $oldPrice !== null) {
                        $cashoutValue = round($remnant * $oldPrice, 4);

                        $conn->executeStatement(
                            'UPDATE users SET cash_balance = cash_balance + :cashout WHERE id = :user_id',
                            ['cashout' => $cashoutValue, 'user_id' => $holding['user_id']]
                        );
                    }
                }

                // 3. Cash out the fractional remnant sitting in ESCROW, on the same terms as the holdings
                // above and BEFORE the bulk rewrite below reaches it.
                //
                // An open order is not idle: a BUY has had limit x quantity debited from cash, and a SELL has
                // had its shares removed from user_stocks. The rewrite below rescales both legs by FLOOR, so
                // an order of 15 shares at $2 became 1 share at $20 and $10 of committed cash simply ceased
                // to exist; an order that floored to zero was cancelled outright by the sweep further down
                // and lost the whole escrow, because that sweep is raw SQL and refunds nothing. Refunding
                // limit x (quantity MOD factor) leaves the surviving order holding exactly what it still
                // needs, so escrow value is conserved across the split and across a later cancel.
                $conn->executeStatement(
                    "UPDATE users u
                     INNER JOIN (
                         SELECT o.user_id,
                                SUM(CASE WHEN o.action = 'BUY'
                                         THEN COALESCE(o.limit_price, 0) * (o.quantity % :factor)
                                         ELSE (o.quantity % :factor) * :old_price
                                    END) AS remnant_value
                         FROM trade_orders o
                         WHERE o.ticker = :ticker AND o.status = 'OPEN'
                         GROUP BY o.user_id
                     ) remnants ON remnants.user_id = u.id
                     SET u.cash_balance = u.cash_balance + remnants.remnant_value",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker(), 'old_price' => $oldPrice ?? 0.0]
                );

                // 4. Perform bulk SQL updates for quantities and prices
                $conn->executeStatement(
                    "UPDATE trade_orders SET limit_price = ROUND(limit_price * :factor, 4) WHERE ticker = :ticker AND status = 'OPEN'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );

                $conn->executeStatement(
                    'UPDATE user_stocks SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
                    ['stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    "UPDATE trade_orders SET quantity = FLOOR(quantity / :factor) WHERE ticker = :ticker AND status = 'OPEN'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );

                $conn->executeStatement(
                    "UPDATE trade_orders SET status = 'CANCELLED' WHERE ticker = :ticker AND status = 'OPEN' AND quantity = 0",
                    ['ticker' => $stock->getTicker()]
                );

                $conn->executeStatement(
                    'UPDATE stock_history SET price = LEAST(price * :factor, 900000000000.0) WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'UPDATE corporate_report SET shares = FLOOR(shares / :factor) WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                // Executed history is restated too, or the cost basis derived from it is left in pre-split
                // units against a post-split holding: after a 1-for-10 the same money reads as ten times the
                // shares at a tenth the price, and the position shows a phantom gain that never happened.
                // GREATEST(..., 1) rather than 0: an executed trade that floors away takes its whole
                // consideration out of the basis pool with it, and a position built from orders all smaller
                // than the factor lost every one of them at once — the cost basis then had no shares to
                // average over and the page printed no cost at all against a position the holder still held.
                $conn->executeStatement(
                    "UPDATE trade_orders
                     SET quantity = GREATEST(FLOOR(quantity / :factor), 1),
                         filled_quantity = GREATEST(FLOOR(filled_quantity / :factor), 1),
                         execution_price = ROUND(execution_price * :factor, 4),
                         limit_price = ROUND(limit_price * :factor, 4)
                     WHERE ticker = :ticker AND status = 'FILLED'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );
            } else {
                $conn->executeStatement(
                    'UPDATE user_stocks SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor), version = version + 1 WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'UPDATE stock_history SET price = GREATEST(price / :factor, 0.00000001) WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'UPDATE corporate_report SET shares = IF(shares > 9223372036854775807 / :factor, 9223372036854775807, shares * :factor) WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    "UPDATE trade_orders SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor), limit_price = ROUND(limit_price / :factor, 4) WHERE ticker = :ticker AND status = 'OPEN'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );

                // Executed history is restated too, or the cost basis derived from it is left in pre-split
                // units against a post-split holding: after a 4-for-1 a position bought for $4,000 reads as
                // having cost $16,000 and the page shows a 75% loss the holder never took.
                $conn->executeStatement(
                    "UPDATE trade_orders
                     SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor),
                         filled_quantity = IF(filled_quantity > 9223372036854775807 / :factor, 9223372036854775807, filled_quantity * :factor),
                         execution_price = ROUND(execution_price / :factor, 4),
                         limit_price = ROUND(limit_price / :factor, 4)
                     WHERE ticker = :ticker AND status = 'FILLED'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );
            }

            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}
