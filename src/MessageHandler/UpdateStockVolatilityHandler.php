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
        // Fetch data
        $conn = $this->entityManager->getConnection();
        $sql = "SELECT price FROM stock_history WHERE stock_id = :id ORDER BY recorded_at DESC LIMIT 100";
        $results = $conn->fetchAllAssociative($sql, ['id' => $message->getStockId()]);
        
        if (count($results) < 10) return;

        $prices = array_reverse(array_column($results, 'price'));
        $floatPrices = array_map('floatval', $prices);

        // Run the GARCH(1,1) MLE estimation
        $garchVol = $this->garchCalculator->calculateLongTermVolatility(
            $floatPrices, 
            $message->getTicksPerYear()
        );

        $stock = $this->entityManager->getRepository(Stock::class)->find($message->getStockId());
        if (!$stock) return;

        // Define the Fundamental Archetype
        $ticker = $stock->getTicker();
        $archetypeVol = 0.15;
        
        foreach (\App\Data\InitialMarket::STOCKS as $initialData) {
            if ($initialData['ticker'] === $ticker) {
                $archetypeVol = (float) $initialData['volatility'];
                break;
            }
        }

        // Bayesian Shrinkage
        $blendedVolatility = ($garchVol * 0.50) + ($archetypeVol * 0.50);

        // Cap and Floor just to be safe
        $blendedVolatility = max(0.05, min(0.80, $blendedVolatility));

        // Persist the anchored volatility back to the engine
        $stock->setVolatility((string) $blendedVolatility);
        
        $this->entityManager->flush();
    }
}