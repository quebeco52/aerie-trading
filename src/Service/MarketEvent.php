<?php

namespace App\Service;

use App\Entity\Stock;
use App\Entity\StockEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Centralized publisher for all market events, news headlines, and shocks.
 */
class MarketEvent
{
    private \Redis $redis;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
        $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        $this->redis = new \Redis();
        $this->redis->connect($redisUrl['host'], $redisUrl['port'] ?? 6379);
    }

    /**
     * Publishes an event to the database, logs it, and formats it for the WebSocket.
     */
    public function publish(Stock $stock, string $type, string $description, float $changePercent): array
    {
        // 1. Create the Doctrine Entity
        $event = new StockEvent();
        $event->setStock($stock);
        $event->setEventType($type);
        $event->setDescription($description);
        $event->setChangePercent((string) round($changePercent, 2));

        $this->entityManager->persist($event);

        // 2. Log it beautifully for the terminal
        $color = $changePercent >= 0 ? "\033[32m" : "\033[31m";
        if ($type === 'SHOCK') {
            echo " [!] {$color}MARKET SHOCK on {$stock->getTicker()}: " . number_format($changePercent, 2) . "% \033[0m\n";
        } else {
            $this->logger->info("[{$type}] {$stock->getTicker()}: {$description}");
        }

        $eventData = [
            'type' => $type,
            'ticker' => $stock->getTicker(),
            'description' => $description,
            'change_percent' => round($changePercent, 2)
        ];

        // 3. Push to Redis Feed
        $this->redis->lPush('market_events_list', json_encode($eventData));
        $this->redis->lTrim('market_events_list', 0, 49);

        // 4. Return the exact array format the WebSocket expects
        return $eventData;
    }
}