<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Entity\DistrictNews;
use App\Entity\Etf;
use App\Entity\EtfEvent;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Notification\PlayerNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Centralized publisher for all market events, news headlines, and shocks: company and fund events against their
 * instrument, district-wide stories on their own desk.
 *
 * @phpstan-import-type WireCard from EventCard
 * @phpstan-type WireEvent array{type: string, ticker: string|null, description: string, change_percent: float|null, sim_time: float|null, presented: WireCard, scope: string, section: string, headline: bool}
 */
class MarketEventPublisher
{
    /** Simulation time of the tick being published; set by the ticker each tick, null until it is. */
    private ?float $simTime = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private EventPresenter $presenter = new EventPresenter(),
        private ?PlayerNotifier $notifier = null,
    ) {}

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
     * A null change publishes the event without a number, for news whose price effect is not known. The facts are what
     * the card is built from (EventPresenter); the description is the line the log and the notification carry. A deal
     * passes its price over the company's market value before it, which decides whether it runs as a headline.
     *
     * @param array<string, mixed> $facts
     * @return WireEvent
     */
    public function publish(Stock|Etf $asset, string $type, string $description, ?float $changePercent, array $facts = [], ?float $dealShareOfValue = null): array
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
        $event->setFacts($facts);
        $event->setChangePercent($changePercent === null ? null : (string) round($changePercent, 2));
        $event->setSimTime($this->simTime);

        // A shock leads with its move; every other story with its text.
        $this->logger->info($type === 'SHOCK'
            ? sprintf('[SHOCK] %s: %s', $asset->getTicker(), $changePercent === null ? 'n/a' : number_format($changePercent, 2) . '%')
            : "[{$type}] {$asset->getTicker()}: {$description}");

        $wire = $this->wire(
            $type,
            $asset->getTicker(),
            null,
            $description,
            $changePercent,
            $asset instanceof Stock ? NewsDesk::SCOPE_COMPANY : NewsDesk::SCOPE_FUND,
            $asset instanceof Stock ? (float) $asset->getVolatility() : null,
            $facts,
            $dealShareOfValue,
        );
        $event->setHeadline($wire['headline']);
        $this->entityManager->persist($event);

        // Every story on a watched name goes to its watchers, resolved and sent once the tick commits.
        $headline = $wire['presented']['headline'];
        $this->notifier?->queueForWatchers(
            $asset->getTicker(),
            sprintf('%s: %s', $asset->getTicker(), $headline !== '' ? $headline : $description),
            $description,
            '/stock/' . rawurlencode($asset->getTicker()),
        );

        return $wire;
    }

    /**
     * Publishes a district-wide story, which belongs to no instrument, on the economy or the government desk.
     *
     * @param string $desk  DistrictNews::DESK_ECONOMY or DistrictNews::DESK_GOVERNMENT
     * @param string $topic the ShockEvent that made the story
     * @return WireEvent
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

        $wire = $this->wire($desk, null, $topic, $description, $changePercent, NewsDesk::SCOPE_DISTRICT, null, []);
        $news->setHeadline($wire['headline']);
        $this->entityManager->persist($news);

        return $wire;
    }

    /**
     * Presents the event for the wire, and files it with the news desk. The wire copy
     * carries its section and headline verdict, so the newswire and the headline strip agree with a page load.
     *
     * @param array<string, mixed> $facts
     * @return WireEvent
     */
    private function wire(string $type, ?string $ticker, ?string $topic, string $description, ?float $changePercent, string $scope, ?float $annualVolatility, array $facts, ?float $dealShareOfValue = null): array
    {
        $changePercent = $changePercent === null ? null : round($changePercent, 2);
        $card = $this->presenter->present(new PresentableEvent($type, $description, $facts, $changePercent, $topic, $this->simTime));

        $eventData = [
            'type' => $type,
            'ticker' => $ticker,
            'description' => $description,
            'change_percent' => $changePercent,
            'sim_time' => $this->simTime,
            'presented' => $card->toWire(),
            'scope' => $scope,
            'section' => NewsDesk::sectionOf($scope, $card->card),
            'headline' => NewsDesk::isHeadline($card, $annualVolatility, $dealShareOfValue),
        ];

        return $eventData;
    }
}
