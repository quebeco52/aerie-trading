<?php

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Macro\MacroEngine;
use App\Service\Event\MarketEventPublisher;

/**
 * Service responsible for executing corporate actions.
 * Strictly handles structural Market Actions like Stock Splits and Reverse Splits.
 */
class CorporateActionEngine
{
    // Split Physics Constants
    private const FORWARD_SPLIT_THRESHOLD = 400.0;
    private const FORWARD_SPLIT_FACTOR = 4.0;
    private const REVERSE_SPLIT_THRESHOLD = 2.0;
    private const REVERSE_SPLIT_FACTOR = 10.0;
    private const MIN_SHARES_REVERSE_SPLIT = 10.0;
    private const MAX_SPLIT_MULTIPLIER = 1_000_000;

    /**
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEventPublisher   $marketEvent   Service for publishing market events.
     * @param \Redis                 $redis         The Redis connection for managing live chart buffers.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEventPublisher $marketEvent,
        private \Redis $redis
    ) {}


    /**
     * Evaluates and processes potential stock splits based on the current price.
     *
     * Triggers a forward split if the price exceeds $400, or a reverse split
     * if the price falls below $2. Ensures the stock remains highly liquid and
     * tradeable without altering the underlying corporate value.
     *
     * @param Stock $stock             The stock entity to evaluate.
     * @param float $newPrice          The proposed new price.
     * @param float $sharesOutstanding The current number of shares outstanding.
     * @return array{price: float, shares: float, event: array|null} The adjusted price, shares, and any generated event.
     */
    public function processSplits(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $splitEvent = null;

        if ($newPrice >= self::FORWARD_SPLIT_THRESHOLD) {
            $result = $this->executeForwardSplit($stock, $newPrice, $sharesOutstanding);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        } elseif ($newPrice < self::REVERSE_SPLIT_THRESHOLD && $sharesOutstanding >= self::MIN_SHARES_REVERSE_SPLIT) {
            $result = $this->executeReverseSplit($stock, $newPrice, (int) self::REVERSE_SPLIT_FACTOR);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        }

        return [
            'price' => $newPrice,
            'shares' => $sharesOutstanding,
            'event' => $splitEvent
        ];
    }


    /**
     * Executes a recursive forward split (e.g., 4-for-1) to bring the price back below $400.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the upper threshold.
     * @param float $sharesOutstanding The current shares outstanding.
     * @return array{price: float, shares: float, event: array}
     */
    private function executeForwardSplit(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $splitFactor = 1;
        while ($newPrice >= self::FORWARD_SPLIT_THRESHOLD && $splitFactor <= self::MAX_SPLIT_MULTIPLIER && !is_infinite($newPrice)) {
            $newPrice = $newPrice / self::FORWARD_SPLIT_FACTOR;
            $splitFactor *= self::FORWARD_SPLIT_FACTOR;
        }

        $sharesOutstanding *= $splitFactor;

        $oldDiv = (float) $stock->getLastDividend();
        $oldEps = (float) $stock->getEarningsPerShare();
        $oldFcf = (float) $stock->getFreeCashFlowPerShare();

        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $stock->setLastDividend((string) ($oldDiv / $splitFactor));
        $stock->setEarningsPerShare((string) ($oldEps / $splitFactor));
        $stock->setFreeCashFlowPerShare((string) ($oldFcf / $splitFactor));

        $desc = "{$stock->getName()} has executed a {$splitFactor}-for-1 stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'SPLIT', $desc, 0.00);

        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'UPDATE user_stocks SET quantity = quantity * :factor, version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'UPDATE stock_history SET price = GREATEST(price / :factor, 0.00000001) WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'UPDATE corporate_report SET shares = shares * :factor WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                "UPDATE trade_orders SET quantity = quantity * :factor, limit_price = ROUND(limit_price / :factor, 8) WHERE ticker = :ticker AND status = 'OPEN'",
                ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
            );
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->adjustRedisBuffer($stock->getTicker(), $splitFactor, 'divide');

        return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => $splitEvent];
    }


    /**
     * Executes a recursive reverse split (e.g., 1-for-10) to bring the price back above $2.
     * Cashes out fractional shares directly to user accounts to prevent loss of wealth.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the lower threshold.
     * @param int   $reverseFactor The factor to multiply the price by (default 10).
     *
     * @return array Returns details about the executed split.
     * @throws \Exception
     */
    public function executeReverseSplit(Stock $stock, float $newPrice, int $reverseFactor = 10): array
    {
        $oldShares = (float) $stock->getSharesOutstanding();
        $sharesOutstanding = (string) floor($oldShares / $reverseFactor);
        $stock->setSharesOutstanding($sharesOutstanding);

        $oldPrice = (float) $stock->getPrice();
        $stock->setPrice((string) round($oldPrice * $reverseFactor, 4));

        $oldEps = (float) $stock->getEarningsPerShare();
        $stock->setEarningsPerShare((string) ($oldEps * $reverseFactor));

        $oldDiv = (float) $stock->getLastDividend();
        $stock->setLastDividend((string) ($oldDiv * $reverseFactor));

        $oldFcf = (float) $stock->getFreeCashFlowPerShare();
        $stock->setFreeCashFlowPerShare((string) ($oldFcf * $reverseFactor));

        $desc = "{$stock->getName()} has executed a 1-for-{$reverseFactor} reverse stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'REVERSE_SPLIT', $desc, 0.00);

        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            // 1. Fetch all user stock holdings for this ticker before mutating
            $holdings = $conn->fetchAllAssociative(
                'SELECT id, user_id, quantity FROM user_stocks WHERE stock_id = :stock_id AND quantity > 0',
                ['stock_id' => $stock->getId()]
            );

            // 2. Process cashouts for fractional remnants
            foreach ($holdings as $holding) {
                $qty = (float) $holding['quantity'];
                $newQty = floor($qty / $reverseFactor);
                $remnant = $qty - ($newQty * $reverseFactor);

                if ($remnant > 0) {
                    $cashoutValue = round($remnant * $oldPrice, 4);

                    $conn->executeStatement(
                        'UPDATE user SET cash_balance = cash_balance + :cashout WHERE id = :user_id',
                        ['cashout' => $cashoutValue, 'user_id' => $holding['user_id']]
                    );

                    $conn->executeStatement(
                        'INSERT INTO account_ledger (user_id, type, amount, description, created_at) VALUES (:user_id, :type, :amount, :desc, NOW())',
                        [
                            'user_id' => $holding['user_id'],
                            'type'    => 'REVERSE_SPLIT_CASHOUT',
                            'amount'  => $cashoutValue,
                            'desc'    => "Cashout for {$remnant} fractional shares of {$stock->getTicker()} during 1-for-{$reverseFactor} reverse split."
                        ]
                    );
                }
            }

            // 3. Perform bulk SQL updates for quantities and prices
            $conn->executeStatement(
                "UPDATE trade_orders SET limit_price = ROUND(limit_price * :factor, 8) WHERE ticker = :ticker AND status = 'OPEN'",
                ['factor' => $reverseFactor, 'ticker' => $stock->getTicker()]
            );

            $conn->executeStatement(
                'UPDATE user_stocks SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
                ['stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                "UPDATE trade_orders SET quantity = FLOOR(quantity / :factor) WHERE ticker = :ticker AND status = 'OPEN'",
                ['factor' => $reverseFactor, 'ticker' => $stock->getTicker()]
            );

            $conn->executeStatement(
                "UPDATE trade_orders SET status = 'CANCELLED' WHERE ticker = :ticker AND status = 'OPEN' AND quantity = 0",
                ['ticker' => $stock->getTicker()]
            );

            $conn->executeStatement(
                'UPDATE stock_history SET price = LEAST(price * :factor, 900000000000.0) WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );

            $conn->executeStatement(
                'UPDATE corporate_report SET shares = FLOOR(shares / :factor) WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->adjustRedisBuffer($stock->getTicker(), $reverseFactor, 'multiply');

        return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => $splitEvent];
    }


    /**
     * Mutates the live Redis chart buffer to prevent massive visual vertical spikes on the UI during a split.
     *
     * @param string $ticker The stock ticker symbol.
     * @param float $factor The split factor.
     * @param string $operation The mathematical operation to apply ('divide' for forward splits, 'multiply' for reverse splits).
     */
    private function adjustRedisBuffer(string $ticker, float $factor, string $operation): void
    {
        $cacheKey = "chart_buffer:{$ticker}";
        $redisData = $this->redis->lRange($cacheKey, 0, -1);

        if (empty($redisData)) return;

        $this->redis->del($cacheKey);

        foreach (array_reverse($redisData) as $jsonStr) {
            $point = json_decode($jsonStr, true);
            if ($operation === 'divide') {
                $point['price'] = max(0.01, $point['price'] / $factor);
            } else {
                $point['price'] = $point['price'] * $factor;
            }
            $this->redis->lPush($cacheKey, json_encode($point));
        }
    }
}
