<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\District\DistrictCalendar;
use App\Entity\Season;
use App\Entity\User;
use App\Repository\SeasonRepository;
use App\Service\Market\Chart\PriceChangeFeed;
use App\Service\Notification\PriceAlertService;
use App\Service\Season\SeasonService;
use App\Service\Season\SeasonStanding;
use App\Service\User\FreshStart;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The portfolio page's player panels: the season standing against the index, the watchlist with its alerts, the
 * first-week checklist and the fresh-start offer.
 */
final class PlayerPanelBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonRepository $seasons,
        private readonly SeasonService $seasonService,
        private readonly PriceAlertService $alerts,
        private readonly PriceChangeFeed $priceChanges,
        private readonly FreshStart $freshStart,
        private readonly int $tickIntervalUs,
        private readonly int $ticksPerYear,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, float $simTime, bool $hasTraded): array
    {
        $season = $this->seasons->findOpen();
        $table = $this->seasonService->liveTable($season);

        return [
            'season' => $season === null ? null : $this->seasonPanel($season, $table, $user, $simTime),
            'watchlist' => $this->watchlist($user),
            'priceAlerts' => $this->alerts->waiting($user),
            'checklist' => $this->checklist($user, $hasTraded),
            'freshStartBlocker' => $this->freshStart->blocker($user),
            'freshStartCapital' => (float) $this->freshStart->startingCapital(),
        ];
    }

    /** Real seconds one simulated year takes at the configured pace. */
    public function realSecondsPerYear(): float
    {
        return $this->tickIntervalUs / 1e6 * $this->ticksPerYear;
    }

    /**
     * @param list<SeasonStanding> $table
     * @return array<string, mixed>
     */
    private function seasonPanel(Season $season, array $table, User $user, float $simTime): array
    {
        $yearsLeft = SeasonService::yearsLeft($season, $simTime);

        return [
            'number' => $season->getNumber(),
            'endsOn' => DistrictCalendar::quarter($season->getEndTime()),
            'realSecondsLeft' => $yearsLeft * $this->realSecondsPerYear(),
            'progress' => Season::LENGTH_YEARS > 0 ? min(1.0, max(0.0, 1.0 - $yearsLeft / Season::LENGTH_YEARS)) : 0.0,
            'row' => SeasonService::rowFor($table, $user),
            'ranked' => SeasonService::rankedCount($table),
            'entrants' => count($table),
            'qualifyingWeeks' => Season::MIN_QUALIFYING_WEEKS,
        ];
    }

    /** @return list<array{ticker: string, name: string, assetType: string, price: float, change: float|null}> */
    private function watchlist(User $user): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT w.ticker, w.asset_type, COALESCE(s.name, e.name) AS name, COALESCE(s.price, e.price) AS price
             FROM watchlist_items w
             LEFT JOIN stocks s ON s.ticker = w.ticker AND w.asset_type = 'STOCK'
             LEFT JOIN etfs e ON e.ticker = w.ticker AND w.asset_type = 'ETF'
             WHERE w.user_id = :u
             ORDER BY w.ticker",
            ['u' => $user->getId()]
        );

        $items = [];
        foreach ($rows as $row) {
            if ($row['price'] === null) {
                continue;
            }
            $price = (float) $row['price'];
            $items[] = [
                'ticker' => (string) $row['ticker'],
                'name' => (string) $row['name'],
                'assetType' => (string) $row['asset_type'],
                'price' => $price,
                'change' => $this->priceChanges->changeForTicker((string) $row['ticker'], $price),
            ];
        }

        return $items;
    }

    /** @return array{items: list<array{label: string, done: bool, href: string}>, complete: bool} */
    private function checklist(User $user, bool $hasTraded): array
    {
        $conn = $this->entityManager->getConnection();
        $id = ['u' => $user->getId()];

        $items = [
            ['label' => 'Read the investor guide', 'done' => false, 'href' => '/guide'],
            ['label' => 'Buy your first shares', 'done' => $hasTraded, 'href' => '/'],
            [
                'label' => 'Place a limit or stop order',
                'done' => (int) $conn->fetchOne("SELECT COUNT(*) FROM trade_orders WHERE user_id = :u AND order_type IN ('LIMIT', 'STOP', 'STOP_LIMIT')", $id) > 0,
                'href' => '/guide#orders',
            ],
            [
                'label' => 'Watch a company',
                'done' => (int) $conn->fetchOne('SELECT COUNT(*) FROM watchlist_items WHERE user_id = :u', $id) > 0,
                'href' => '/screener',
            ],
            [
                'label' => 'Set a price alert',
                'done' => (int) $conn->fetchOne('SELECT COUNT(*) FROM price_alerts WHERE user_id = :u', $id) > 0,
                'href' => '/guide#alerts',
            ],
        ];

        $checkable = array_slice($items, 1);

        return [
            'items' => $items,
            'complete' => count(array_filter($checkable, static fn (array $item): bool => $item['done'])) === count($checkable),
        ];
    }
}
