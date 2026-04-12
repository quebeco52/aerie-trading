<?php

namespace App\MessageHandler;

use App\Message\UpdateStockVolatility;
use App\Entity\Stock;
use App\Service\GarchCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class UpdateStockVolatilityHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GarchCalculator $garchCalculator
    ) {}

    public function __invoke(UpdateStockVolatility $message): void
    {
        // Fetch the last ~100 prices (e.g., last 100 days)
        $conn = $this->entityManager->getConnection();
        $sql = "SELECT price FROM stock_history WHERE stock_id = :id ORDER BY recorded_at DESC LIMIT 100";
        $results = $conn->fetchAllAssociative($sql, ['id' => $message->getStockId()]);
        
        if (count($results) < 10) return;

        // Reverse to chronological order
        $prices = array_reverse(array_column($results, 'price'));
        $floatPrices = array_map('floatval', $prices);

        // Run the GARCH(1,1) MLE estimation
        $newBaselineVolatility = $this->garchCalculator->calculateLongTermVolatility(
            $floatPrices, 
            $message->getTicksPerYear()
        );

        // Fetch the stock only after we know we have enough data to process
        $stock = $this->entityManager->getRepository(Stock::class)->find($message->getStockId());
        if (!$stock) return;

        // Persist the newly discovered underlying volatility back to the database
        $stock->setVolatility((string) $newBaselineVolatility);
        
        $this->entityManager->flush();
    }
}