<?php

namespace App\MessageHandler;

use App\Entity\Stock;
use App\Message\UpdateStockVolatility;
use App\Service\GarchCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class UpdateStockVolatilityHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GarchCalculator $garchCalculator,
        private \Redis $redis
    ) {}

    public function __invoke(UpdateStockVolatility $message): void
    {
        $conn = $this->entityManager->getConnection();
        
        // 1. Fetch History (Read-only, no locks)
        $sql = "SELECT price FROM stock_history WHERE stock_id = :id ORDER BY recorded_at DESC LIMIT 100";
        $results = $conn->fetchAllAssociative($sql, ['id' => $message->getStockId()]);
        
        if (count($results) < 10) return;

        $prices = array_reverse(array_column($results, 'price'));
        $floatPrices = array_map('floatval', $prices);

        // 2. Run GARCH(1,1)
        $garchVol = $this->garchCalculator->calculateLongTermVolatility(
            $floatPrices, 
            $message->getTicksPerYear()
        );

        // 3. Fetch Ticker String directly (Read-only, no locks)
        $tickerResult = $this->entityManager->createQueryBuilder()
            ->select('s.ticker')
            ->from(Stock::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $message->getStockId())
            ->getQuery()
            ->getSingleScalarResult();
            
        if (!$tickerResult) return;

        // 4. Blend with Archetype
        $archetypeVol = 0.15;
        foreach (\App\Data\InitialMarket::STOCKS as $initialData) {
            if ($initialData['ticker'] === $tickerResult) {
                $archetypeVol = (float) $initialData['volatility'];
                break;
            }
        }

        $blendedVolatility = ($garchVol * 0.50) + ($archetypeVol * 0.50);
        $blendedVolatility = max(0.05, min(0.80, $blendedVolatility));

        // ========================================================
        // 5. CACHE-BACK (THE DEADLOCK KILLER)
        // Dump the result into Redis for the Ticker to absorb.
        // We DO NOT write to MySQL here!
        // ========================================================
        $cacheKey = "computed_vol_cache:{$message->getStockId()}";
        $this->redis->set($cacheKey, (string) $blendedVolatility);
        $this->redis->expire($cacheKey, 60); // Auto-cleanup
    }
}