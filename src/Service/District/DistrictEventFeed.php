<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\District\DistrictMap;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\News\EventPresenter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Backfills the district event badges from persisted history so a facade's count is correct on first paint, before
 * any live tick has arrived.
 *
 * Presentation is delegated entirely to App\Service\News\EventPresenter, the same service that renders the stock
 * page's event feed, so a district badge and a stock-page event card always agree on badge text, colour and icon for
 * the same event.
 */
class DistrictEventFeed
{
    /** Recent events shown per building before the list is truncated. */
    public const EVENTS_PER_TICKER = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventPresenter $eventPresenter,
    ) {}

    /**
     * @param  list<Stock> $stocks
     * @param  float       $simTime the simulation time now, in years; the badge window runs back from it
     * @return array<string, list<array{badge: string, badgeClass: string, icon: string, iconClass: string, headline: string, dateline: string, simTime: float|null, recent: bool}>> ticker => presented events, newest first
     */
    public function recentEventsByTicker(array $stocks, float $simTime): array
    {
        $eventsByTicker = [];
        foreach ($stocks as $stock) {
            $eventsByTicker[$stock->getTicker()] = [];
        }

        if ($stocks === []) {
            return $eventsByTicker;
        }

        // One query for every tenant, limited per tenant, so accumulated history never grows hydration.
        $rows = $this->entityManager->getRepository(StockEvent::class)
            ->findForStocksNewestFirst($stocks, self::EVENTS_PER_TICKER);

        $windowOpensAt = $simTime - DistrictMap::EVENT_BADGE_WINDOW_YEARS;

        foreach ($rows as $event) {
            $ticker = $event->getStock()->getTicker();
            if (count($eventsByTicker[$ticker]) >= self::EVENTS_PER_TICKER) {
                continue;
            }

            $card = $this->eventPresenter->present($event);
            // Only what district_controller.js renders, with the badge verdict the client re-reaches as the
            // simulation moves on. A story from before events carried their simulation time is older than any window.
            $eventsByTicker[$ticker][] = [
                'badge' => $card->badge,
                'badgeClass' => $card->badgeClass(),
                'icon' => $card->icon,
                'iconClass' => $card->iconClass(),
                'headline' => $card->headline,
                'dateline' => $card->dateline(),
                'simTime' => $card->simTime,
                'recent' => $card->simTime !== null && $card->simTime >= $windowOpensAt,
            ];
        }

        return $eventsByTicker;
    }
}
