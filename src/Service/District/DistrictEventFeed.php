<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Event\EventPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

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

    /**
     * @param int $ticksPerYear   simulated ticks in a year (app.ticks_per_year)
     * @param int $tickIntervalUs wall-clock microseconds the ticker sleeps per tick (app.tick_interval)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventPresenter $eventPresenter,
        private readonly int $ticksPerYear,
        private readonly int $tickIntervalUs,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /**
     * Wall-clock seconds one badge window (DistrictMap::EVENT_BADGE_WINDOW_YEARS of simulated
     * time) currently lasts, from the configured tick cadence. Events carry only a wall-clock
     * recordedAt, so "one simulated month ago" has to be translated through that cadence — the
     * same translation the chart buffer behind the kerb's change figure implies by holding one
     * month of ticks. A paused ticker lets badges age out while the change figure stands still;
     * the two agree again as soon as it resumes.
     */
    public function badgeWindowSeconds(): float
    {
        return DistrictMap::EVENT_BADGE_WINDOW_YEARS * $this->ticksPerYear * $this->tickIntervalUs / 1_000_000;
    }

    /**
     * @param  Stock[] $stocks
     * @return array<string, list<array<string, mixed>>> ticker => presented events, newest first
     */
    public function recentEventsByTicker(array $stocks): array
    {
        $eventsByTicker = [];
        foreach ($stocks as $stock) {
            $eventsByTicker[$stock->getTicker()] = [];
        }

        if ($stocks === []) {
            return $eventsByTicker;
        }

        // One query for every tenant instead of one per tenant — StockEvent carries an
        // (stock_id, recorded_at) index (see its class attributes), so this stays a single
        // efficient index scan as history accumulates. Grouped in PHP rather than with a
        // per-stock SQL LIMIT, which Doctrine has no portable way to express in one query.
        $rows = $this->entityManager->getRepository(StockEvent::class)->createQueryBuilder('e')
            ->andWhere('e.stock IN (:stocks)')
            ->setParameter('stocks', $stocks)
            ->orderBy('e.stock', 'ASC')
            ->addOrderBy('e.recordedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $windowOpensAt = $this->clock->now()->getTimestamp() - $this->badgeWindowSeconds();

        foreach ($rows as $event) {
            $ticker = $event->getStock()->getTicker();
            if (count($eventsByTicker[$ticker]) >= self::EVENTS_PER_TICKER) {
                continue;
            }

            $eventsByTicker[$ticker][] = $this->presentForTransport($event, $windowOpensAt);
        }

        return $eventsByTicker;
    }

    /**
     * Presents one event and flattens its recordedAt into a display string so the result is
     * plain-JSON-serialisable for the client (a raw DateTimeInterface does not encode usefully).
     * The epoch timestamp rides along too, with the badge verdict the server reached from it, so
     * the client can re-reach the same verdict later without parsing the display string.
     *
     * @return array<string, mixed>
     */
    private function presentForTransport(StockEvent $event, float $windowOpensAt): array
    {
        $presented = $this->eventPresenter->present($event);
        $recordedAt = $event->getRecordedAt();
        $presented['recordedAt'] = $recordedAt->format('Y-m-d H:i');
        $presented['recordedAtTs'] = $recordedAt->getTimestamp();
        $presented['recent'] = $recordedAt->getTimestamp() >= $windowOpensAt;

        return $presented;
    }
}
