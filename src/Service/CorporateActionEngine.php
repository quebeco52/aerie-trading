<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing corporate actions like stock splits.
 * It adjusts the underlying corporate math and securely patches user portfolios.
 */
class CorporateActionEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Evaluates a stock's price and executes a split or reverse-split if necessary.
     *
     * @return array Contains the potentially modified price, eps, shares, and any event generated.
     */
    public function processSplits(Stock $stock, float $newPrice, float $newEps, int $sharesOutstanding): array
    {
        $splitEvent = null;

        // 1. The Stock Split (Price gets too high)
        while ($newPrice >= 500.0) {
            $newPrice = $newPrice / 2.0;
            $newEps = $newEps / 2.0;
            $sharesOutstanding *= 2;

            $splitEvent = [
                'type' => 'STOCK_SPLIT',
                'ticker' => $stock->getTicker(),
                'message' => "{$stock->getName()} has executed a 2-for-1 stock split.",
                'timestamp' => time()
            ];

            // Safely double the players' shares
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE user_stocks SET quantity = quantity * 2, version = version + 1 WHERE stock_id = :stock_id',
                ['stock_id' => $stock->getId()]
            );

            // Retroactively divide all historical chart prices by 2!
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE stock_history SET price = GREATEST(price / 2.0, 0.00000001) WHERE stock_id = :stock_id',
                ['stock_id' => $stock->getId()]
            );
        }
        
        // 2. The Reverse Split (Price < 5)
        while ($newPrice < 5.0) {
            $newPrice = $newPrice * 5.0;
            $newEps = $newEps * 5.0;
            $sharesOutstanding = max(1, (int)($sharesOutstanding / 5));

            $splitEvent = [
                'type' => 'REVERSE_SPLIT',
                'ticker' => $stock->getTicker(),
                'message' => "{$stock->getName()} executed a 1-for-5 reverse split.",
                'timestamp' => time()
            ];

            // Safely divide players' shares
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE user_stocks SET quantity = FLOOR(quantity / 5), version = version + 1 WHERE stock_id = :stock_id',
                ['stock_id' => $stock->getId()]
            );

            // Retroactively multiply all historical chart prices by 5!
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE stock_history SET price = LEAST(price * 5.0, 900000000000.0) WHERE stock_id = :stock_id',
                ['stock_id' => $stock->getId()]
            );
        }

        return [
            'price' => $newPrice,
            'eps' => $newEps,
            'shares' => $sharesOutstanding,
            'event' => $splitEvent
        ];
    }
}