<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Event\EventPresenter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Backfills the district event badges from persisted history so a facade's count is correct on
 * first paint, before any live tick has arrived.
 *
 * Presentation is delegated entirely to App\Service\Event\EventPresenter — the same service that
 * renders the stock page's event feed — so a district badge and a stock-page event card always
 * agree on badge text, colour, and icon for the same event. This service never re-derives that
 * vocabulary itself.
 */
class DistrictEventFeed
{
    /** Recent events shown per building before the list is truncated. */
    private const EVENTS_PER_TICKER = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventPresenter $eventPresenter,
    ) {}

    /**
     * @param  Stock[] $stocks
     * @return array<string, list<array<string, mixed>>> ticker => presented events, newest first
     */
    public function recentEventsByTicker(array $stocks): array
    {
        $repository = $this->entityManager->getRepository(StockEvent::class);
        $eventsByTicker = [];

        foreach ($stocks as $stock) {
            $rows = $repository->findBy(
                ['stock' => $stock],
                ['recordedAt' => 'DESC'],
                self::EVENTS_PER_TICKER,
            );

            $eventsByTicker[$stock->getTicker()] = array_map(
                fn (StockEvent $event): array => $this->presentForTransport($event),
                $rows,
            );
        }

        return $eventsByTicker;
    }

    /**
     * Presents one event and flattens its recordedAt into a display string so the result is
     * plain-JSON-serialisable for the client (a raw DateTimeInterface does not encode usefully).
     *
     * @return array<string, mixed>
     */
    private function presentForTransport(StockEvent $event): array
    {
        $presented = $this->eventPresenter->present($event);
        $presented['recordedAt'] = $event->getRecordedAt()->format('Y-m-d H:i');

        return $presented;
    }
}
