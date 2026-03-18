<?php

namespace App\Service;

use App\Entity\Stock;
use App\Entity\StockEvent;
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

        // The Stock Split (Price gets too high)
        $splitFactor = 1;
        while ($newPrice >= 400.0) {
            $newPrice = $newPrice / 4.0;
            $newEps = $newEps / 4.0;
            $splitFactor *= 4;
        }

        if ($splitFactor > 1) {
            $sharesOutstanding *= $splitFactor;

            $desc = "{$stock->getName()} has executed a {$splitFactor}-for-1 stock split.";

            $eventEntity = new StockEvent();
            $eventEntity->setStock($stock);
            $eventEntity->setEventType('SPLIT');
            $eventEntity->setDescription($desc);
            $eventEntity->setChangePercent('0.00');
            $this->entityManager->persist($eventEntity);

            $splitEvent = [
                'type' => 'SPLIT',
                'ticker' => $stock->getTicker(),
                'description' => $desc,
                'change_percent' => '0.00'
            ];

            // Safely multiply the players' shares by the dynamic factor
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE user_stocks SET quantity = quantity * :factor, version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );

            // Retroactively divide all historical chart prices by the dynamic factor
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE stock_history SET price = GREATEST(price / :factor, 0.00000001) WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );
        }
        
        // The Reverse Split (Price drops into Penny Stock territory)
        $reverseFactor = 1;
        while ($newPrice < 2.0) {
            $newPrice = $newPrice * 10.0;
            $newEps = $newEps * 10.0;
            $reverseFactor *= 10;
        }

        if ($reverseFactor > 1) {
            $sharesOutstanding = max(1, (int)($sharesOutstanding / $reverseFactor));

            $desc = "{$stock->getName()} executed a 1-for-{$reverseFactor} reverse split.";

            $eventEntity = new StockEvent();
            $eventEntity->setStock($stock);
            $eventEntity->setEventType('REVSPLIT');
            $eventEntity->setDescription($desc);
            $eventEntity->setChangePercent('0.00');
            $this->entityManager->persist($eventEntity);

            $splitEvent = [
                'type' => 'REVSPLIT',
                'ticker' => $stock->getTicker(),
                'description' => $desc,
                'change_percent' => '0.00'
            ];

            // Safely divide players' shares by the dynamic factor
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE user_stocks SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );

            // Retroactively multiply all historical chart prices by the dynamic factor
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE stock_history SET price = LEAST(price * :factor, 900000000000.0) WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
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