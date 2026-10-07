<?php

namespace App\Service\Event;

use App\Entity\DistrictNews;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Entity\Etf;
use App\Entity\EtfEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Centralized publisher for all market events, news headlines, and shocks: company and fund events against their
 * instrument, district-wide stories on their own desk.
 */
class MarketEventPublisher
{
    /** Simulation time of the tick being published; set by the ticker each tick, null until it is. */
    private ?float $simTime = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private \Redis $redis,
        private EventPresenter $presenter = new EventPresenter(),
        private ?\App\Service\Notification\PlayerNotifier $notifier = null,
    ) {
    }

    /**
     * Dates every event published from now on to the given simulation time. The ticker and the fast-forward call it
     * once a tick, after the macro step, so a story carries the District date it happened on.
     */
    public function stampSimTime(float $simTime): void
    {
        $this->simTime = $simTime;
    }

    /**
     * Publishes an event to the database, logs it, and formats it for the WebSocket.
     *
     * The wire copy carries the presented card, so the live feed renders exactly what a page load renders
     * rather than re-deriving it from the type string in the browser.
     *
     * A null change publishes the event without a number, for news whose price effect is not known. A deal passes its
     * price over the company's market value before it, which decides whether it runs as a headline.
     *
     * @return array{type: string, ticker: string|null, description: string, change_percent: float|null, sim_time: float|null, presented: array<string, mixed>, scope: string, section: string, headline: bool}
     */
    public function publish(Stock|Etf $asset, string $type, string $description, ?float $changePercent, ?float $dealShareOfValue = null): array
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
        $event->setSimTime($this->simTime);

        // Log it beautifully for the terminal
        $color = ($changePercent ?? 0.0) >= 0 ? "\033[32m" : "\033[31m";
        if ($type === 'SHOCK') {
            $move = $changePercent === null ? 'n/a' : number_format($changePercent, 2) . '%';
            echo " [!] {$color}MARKET SHOCK on {$asset->getTicker()}: {$move} \033[0m\n";
        } else {
            $this->logger->info("[{$type}] {$asset->getTicker()}: {$description}");
        }

        $wire = $this->wire(
            $type,
            $asset->getTicker(),
            null,
            $description,
            $changePercent,
            $asset instanceof Stock ? NewsDesk::SCOPE_COMPANY : NewsDesk::SCOPE_FUND,
            $asset instanceof Stock ? (float) $asset->getVolatility() : null,
            $dealShareOfValue,
        );
        $event->setHeadline($wire['headline']);
        $this->entityManager->persist($event);

        // Every story on a watched name goes to its watchers, resolved and sent once the tick commits.
        $headline = $wire['presented']['headline'] ?? null;
        $this->notifier?->queueForWatchers(
            $asset->getTicker(),
            sprintf('%s: %s', $asset->getTicker(), is_string($headline) && $headline !== '' ? $headline : $description),
            $description,
            '/stock/' . rawurlencode($asset->getTicker())
        );

        return $wire;
    }

    /**
     * Publishes a district-wide story, which belongs to no instrument, on the economy or the government desk.
     *
     * @param string $desk  DistrictNews::DESK_ECONOMY or DistrictNews::DESK_GOVERNMENT
     * @param string $topic the ShockEvent that made the story
     * @return array{type: string, ticker: string|null, description: string, change_percent: float|null, sim_time: float|null, presented: array<string, mixed>, scope: string, section: string, headline: bool}
     */
    public function publishDistrict(string $desk, string $topic, string $description, ?float $changePercent): array
    {
        $news = (new DistrictNews())
            ->setDesk($desk)
            ->setTopic($topic)
            ->setDescription($description)
            ->setChangePercent($changePercent === null ? null : (string) round($changePercent, 2))
            ->setSimTime($this->simTime);
        $this->logger->info("[{$desk}] {$description}");

        $wire = $this->wire($desk, null, $topic, $description, $changePercent, NewsDesk::SCOPE_DISTRICT, null);
        $news->setHeadline($wire['headline']);
        $this->entityManager->persist($news);

        return $wire;
    }

    /**
     * Presents the event for the wire, files it with the news desk, and pushes it to the Redis feed. The wire copy
     * carries its section and headline verdict, so the newswire and the headline strip agree with a page load.
     *
     * @return array{type: string, ticker: string|null, description: string, change_percent: float|null, sim_time: float|null, presented: array<string, mixed>, scope: string, section: string, headline: bool}
     */
    private function wire(string $type, ?string $ticker, ?string $topic, string $description, ?float $changePercent, string $scope, ?float $annualVolatility, ?float $dealShareOfValue = null): array
    {
        $changePercent = $changePercent === null ? null : round($changePercent, 2);
        $presented = $this->presenter->presentForWire([
            'type' => $type,
            'topic' => $topic,
            'description' => $description,
            'change_percent' => $changePercent,
            'sim_time' => $this->simTime,
        ]);

        $eventData = [
            'type' => $type,
            'ticker' => $ticker,
            'description' => $description,
            'change_percent' => $changePercent,
            'sim_time' => $this->simTime,
            'presented' => $presented,
            'scope' => $scope,
            'section' => NewsDesk::sectionOf($scope, (string) $presented['category']),
            'headline' => NewsDesk::isHeadline($presented, $annualVolatility, $dealShareOfValue),
        ];

        $this->redis->lPush('market_events_list', json_encode($eventData));
        $this->redis->lTrim('market_events_list', 0, 49);

        return $eventData;
    }
}
