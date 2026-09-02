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

                // 3. Perform bulk SQL updates for quantities and prices
                $conn->executeStatement(
                    "UPDATE trade_orders SET limit_price = ROUND(limit_price * :factor, 8) WHERE ticker = :ticker AND status = 'OPEN'",
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
                    "UPDATE trade_orders SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor), limit_price = ROUND(limit_price / :factor, 8) WHERE ticker = :ticker AND status = 'OPEN'",
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
