<?php

namespace App\Service\Event;

use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Entity\Etf;
use App\Entity\EtfEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Centralized publisher for all market events, news headlines, and shocks.
 */
class MarketEventPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private \Redis $redis,
        private EventPresenter $presenter = new EventPresenter()
    ) {
    }

    /**
     * Publishes an event to the database, logs it, and formats it for the WebSocket.
     *
     * The wire copy carries the presented card, so the live feed renders exactly what a page load renders
     * rather than re-deriving it from the type string in the browser.
     *
     * A null change publishes the event without a number, for news whose price effect is not known.
     *
     * @return array{type: string, ticker: string, description: string, change_percent: float|null, presented: array<string, mixed>}
     */
    public function publish(Stock|Etf $asset, string $type, string $description, ?float $changePercent): array
    {
        // Create the Doctrine Entity
        if ($asset instanceof Stock) {
            $event = new StockEvent();
            $event->setStock($asset);
        } else {
            $event = new EtfEvent();
            $event->setEtf($asset);
        }
        $event->setEventType($type);
        $event->setDescription($description);
        $event->setChangePercent($changePercent === null ? null : (string) round($changePercent, 2));

        $this->entityManager->persist($event);

        // Log it beautifully for the terminal
        $color = ($changePercent ?? 0.0) >= 0 ? "\033[32m" : "\033[31m";
        if ($type === 'SHOCK') {
            $move = $changePercent === null ? 'n/a' : number_format($changePercent, 2) . '%';
            echo " [!] {$color}MARKET SHOCK on {$asset->getTicker()}: {$move} \033[0m\n";
        } else {
            $this->logger->info("[{$type}] {$asset->getTicker()}: {$description}");
        }

        $eventData = [
            'type' => $type,
            'ticker' => $asset->getTicker(),
            'description' => $description,
            'change_percent' => $changePercent === null ? null : round($changePercent, 2)
        ];
        $eventData['presented'] = $this->presenter->presentForWire($eventData);

        // Push to Redis Feed
        $this->redis->lPush('market_events_list', json_encode($eventData));
        $this->redis->lTrim('market_events_list', 0, 49);

        // Return the exact array format the WebSocket expects
        return $eventData;
    }
}