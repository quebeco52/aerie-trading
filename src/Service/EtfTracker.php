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
     * @param MarketEvent $marketEvent The publisher for market announcements
     * @param \Redis $redis The Redis connection instance
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
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
            // 1. Process ETF Splits
            if ($price >= 400.0) {
                $splitFactor = 1;
                while ($price >= 400.0 && $splitFactor <= 1000000) {
                    $price /= 4.0;
                    $splitFactor *= 4;
                }
                
                $divisor *= $splitFactor;
                $this->redis->set('market_index_divisor', (string) $divisor);
                $this->executeEtfSplit($etf, $splitFactor, 'forward', $price * $splitFactor);
                
            } elseif ($price < 25.0 && $price > 0) {
                $splitFactor = 1;
                $preSplitPrice = $price;
                while ($price < 25.0 && $splitFactor <= 1000000 && $price > 0.0) {
                    $price *= 4.0;
                    $splitFactor *= 4;
                }
                
                $divisor /= $splitFactor;
                $this->redis->set('market_index_divisor', (string) $divisor);
                $this->executeEtfSplit($etf, $splitFactor, 'reverse', $preSplitPrice);
            }

            // 2. Persist the updated price
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

    /**
     * Executes the backend database adjustments for an ETF split.
     * Ensures users are compensated for fractional shares during reverse splits.
     */
    private function executeEtfSplit(Etf $etf, float $factor, string $direction, float $preSplitPrice): void
    {
        $conn = $this->entityManager->getConnection();
        $etfId = $etf->getId();
        $ticker = $etf->getTicker();
        
        // Note: You must ensure you have a 'user_etfs' table or similar structure!
        $userEtfsTableExists = $conn->createSchemaManager()->tablesExist(['user_etfs']);

        $conn->beginTransaction();
        try {
            if ($direction === 'forward') {
                if ($userEtfsTableExists) {
                    $conn->executeStatement(
                        'UPDATE user_etfs SET quantity = quantity * :factor WHERE etf_id = :etf_id',
                        ['factor' => $factor, 'etf_id' => $etfId]
                    );
                }

                $conn->executeStatement(
                    'UPDATE etf_history SET price = price / :factor WHERE etf_id = :etf_id',
                    ['factor' => $factor, 'etf_id' => $etfId]
                );
                
                $desc = "{$etf->getName()} has executed a {$factor}-for-1 forward split to maintain liquidity.";
                $this->marketEvent->publish($etf, 'SPLIT', $desc, 0.0);

                $this->adjustRedisBuffer($ticker, $factor, 'divide');

            } else {
                if ($userEtfsTableExists) {
                    // Compensate users for fractional shares to prevent wealth destruction
                    $conn->executeStatement(
                        'UPDATE users u
                         INNER JOIN user_etfs ue ON u.id = ue.user_id
                         SET u.cash_balance = u.cash_balance + ((ue.quantity % :factor) * :pre_split_price)
                         WHERE ue.etf_id = :etf_id',
                        ['factor' => $factor, 'pre_split_price' => $preSplitPrice, 'etf_id' => $etfId]
                    );

                    $conn->executeStatement(
                        'UPDATE user_etfs SET quantity = FLOOR(quantity / :factor) WHERE etf_id = :etf_id',
                        ['factor' => $factor, 'etf_id' => $etfId]
                    );

                    $conn->executeStatement(
                        'DELETE FROM user_etfs WHERE etf_id = :etf_id AND quantity = 0',
                        ['etf_id' => $etfId]
                    );
                }

                $conn->executeStatement(
                    'UPDATE etf_history SET price = price * :factor WHERE etf_id = :etf_id',
                    ['factor' => $factor, 'etf_id' => $etfId]
                );
                
                $desc = "{$etf->getName()} has executed a 1-for-{$factor} reverse split to maintain value requirements.";
                $this->marketEvent->publish($etf, 'REVSPLIT', $desc, 0.0);

                $this->adjustRedisBuffer($ticker, $factor, 'multiply');
            }
            
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Mutates the live Redis chart buffer to prevent massive visual vertical spikes on the UI during a split.
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