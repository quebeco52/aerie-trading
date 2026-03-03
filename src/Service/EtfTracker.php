<?php

namespace App\Service;

use App\Entity\Etf;
use App\Entity\EtfHistory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for tracking and updating ETF (Exchange Traded Fund) prices.
 *
 * This service specifically handles the calculation of a market index ETF
 * based on the total market capitalization of the tracked stocks. It manages
 * the index divisor via Redis to maintain price continuity.
 */
class EtfTracker
{
    private \Redis $redis;

    /**
     * @param EntityManagerInterface $entityManager The Doctrine Entity Manager
     */
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        $this->redis = new \Redis();
        $this->redis->connect($redisUrl['host'], $redisUrl['port'] ?? 6379);
    }

    /**
     * Updates the price of the market index ETF based on total market capitalization.
     *
     * If the index divisor is not set in Redis, it initializes it based on the
     * current total market cap to set a baseline price (e.g., 100.00).
     *
     * @param float  $totalMarketCap The sum of market caps of all tracked stocks.
     * @param string $ticker         The ticker symbol of the ETF to update (default: 'LBI').
     *
     * @return array{ticker: string, price: float, name: string, is_etf: bool} Array containing updated ETF data.
     */
    public function updateIndex(float $totalMarketCap, string $ticker = 'LBI'): array
    {
        $divisor = $this->redis->get('market_index_divisor');

        if (!$divisor && $totalMarketCap > 0) {
            $divisor = $totalMarketCap / 100.00;
            $this->redis->set('market_index_divisor', (string) $divisor);
        }

        $price = ($divisor > 0) ? ($totalMarketCap / (float) $divisor) : 100.00;

        // Fetch a fresh ETF entity so Doctrine knows it exists after the clear()
        $etf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);

        if ($etf) {
            $etf->setPrice((string) $price);

            $history = new EtfHistory();
            $history->setEtf($etf);
            $history->setPrice((string) $price);
            
            $this->entityManager->persist($history);
        }

        return [
            'ticker' => $ticker,
            'price' => round($price, 2),
            'name' => $etf ? $etf->getName() : 'Market Index',
            'is_etf' => true
        ];
    }
}