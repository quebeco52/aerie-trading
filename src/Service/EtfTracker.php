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
    /**
     * @param EntityManagerInterface $entityManager The Doctrine Entity Manager
     * @param \Redis $redis The Redis connection instance
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private \Redis $redis
    ) {
    }

    /**
     * Updates the price of the market index ETF based on total market capitalization.
     *
     * @return array{ticker: string, price: float, name: string, is_etf: bool}
     */
    public function updateIndex(float $totalMarketCap, bool $recordHistory = false, string $ticker = 'LBI'): array
    {

        $etf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
        
        $divisor = $this->redis->get('market_index_divisor');

        if (!$divisor && $totalMarketCap > 0) {
            
            if ($etf && (float)$etf->getPrice() > 0) {
                $lastKnownPrice = (float) $etf->getPrice();
                $divisor = $totalMarketCap / $lastKnownPrice;
            } else {
                $divisor = $totalMarketCap / 100.00;
            }
            
            $this->redis->set('market_index_divisor', (string) $divisor);
        }

        $price = ($divisor > 0) ? ($totalMarketCap / (float) $divisor) : 100.00;

        if ($etf) {
            $etf->setPrice((string) $price);

            if ($recordHistory) {
                $history = new EtfHistory();
                $history->setEtf($etf);
                $history->setPrice((string) $price);
                
                $this->entityManager->persist($history);
            }
        }

        return [
            'ticker' => $ticker,
            'price' => round($price, 2),
            'name' => $etf ? $etf->getName() : 'Market Index',
            'is_etf' => true
        ];
    }
}