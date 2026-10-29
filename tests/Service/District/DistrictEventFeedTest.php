<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\District\DistrictMap;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Repository\StockEventRepository;
use App\Service\District\DistrictEventFeed;
use App\Service\News\EventPresenter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the district event backfill's job: fetch every tenant's recent history in one query and
 * hand each event to the real App\Service\News\EventPresenter, never a parallel presentation of
 * its own — so a district badge and the stock page's own event feed always agree on
 * badge/icon/colour for the same event. One query rather than one per tenant is what
 * StockEvent's (stock_id, recorded_at) index exists to make cheap — see recentEventsByTicker()'s
 * own docblock.
 */
#[AllowMockObjectsWithoutExpectations]
class DistrictEventFeedTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private StockEventRepository&MockObject $repository;
    private DistrictEventFeed $feed;

    /** The simulation time the page renders at, in years. */
    private const NOW = 13.5;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(StockEventRepository::class);
        $this->entityManager->method('getRepository')->willReturn($this->repository);

        $this->feed = new DistrictEventFeed($this->entityManager, new EventPresenter());
    }

    private function makeStock(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Holdings');

        return $stock;
    }

    private function makeEvent(Stock $stock, string $type, string $description, ?float $changePercent = null): StockEvent
    {
        $event = new StockEvent();
        $event->setStock($stock);
        $event->setEventType($type);
        $event->setDescription($description);
        if ($changePercent !== null) {
            $event->setChangePercent((string) $changePercent);
        }

        return $event;
    }

    public function testReturnsEmptyArrayForNoStocks(): void
    {
        $this->assertSame([], $this->feed->recentEventsByTicker([], self::NOW));
    }

    public function testKeysResultByTickerAndPresentsEachEvent(): void
    {
        $stock = $this->makeStock('LAKE');
        $shockEvent = $this->makeEvent($stock, 'SHOCK', 'A sudden liquidity event rattled the row.', -4.2);

        $this->repository->method('findForStocksNewestFirst')->willReturn([$shockEvent]);

        $result = $this->feed->recentEventsByTicker([$stock], self::NOW);

        $this->assertArrayHasKey('LAKE', $result);
        $this->assertCount(1, $result['LAKE']);

        $presented = $result['LAKE'][0];
        $this->assertSame('MARKET SHOCK', $presented['badge']);
        $this->assertSame('bolt', $presented['icon']);
        $this->assertSame('badge-down', $presented['badgeClass']);
        $this->assertSame('A sudden liquidity event rattled the row.', $presented['headline']);
    }

    /** The side panel prints the District dateline, as the stock page does. */
    public function testEachEventCarriesItsDateline(): void
    {
        $stock = $this->makeStock('SWAN');
        $event = $this->makeEvent($stock, 'BANKRUPTCY', 'Filed for liquidation.');
        $event->setSimTime(13.25);

        $this->repository->method('findForStocksNewestFirst')->willReturn([$event]);

        $result = $this->feed->recentEventsByTicker([$stock], self::NOW);

        $this->assertSame('1 Apr, Year 14', $result['SWAN'][0]['dateline']);
        $this->assertSame(13.25, $result['SWAN'][0]['simTime']);
    }

    public function testFetchesAllStocksInOneQuery(): void
    {
        $lake = $this->makeStock('LAKE');
        $swan = $this->makeStock('SWAN');

        $this->repository->expects($this->once())->method('findForStocksNewestFirst')
            ->with([$lake, $swan], DistrictEventFeed::EVENTS_PER_TICKER)
            ->willReturn([]);

        $this->feed->recentEventsByTicker([$lake, $swan], self::NOW);
    }

    public function testGroupsRowsByTickerAndCapsAtEventsPerTicker(): void
    {
        $lake = $this->makeStock('LAKE');
        $swan = $this->makeStock('SWAN');

        // Newest-first per ticker, as the query orders — 7 for LAKE (one past the cap of 6), 1 for SWAN.
        $rows = [];
        for ($i = 0; $i < 7; $i++) {
            $rows[] = $this->makeEvent($lake, 'EARNINGS', "Lake report {$i}");
        }
        $rows[] = $this->makeEvent($swan, 'EARNINGS', 'Swan report');

        $this->repository->method('findForStocksNewestFirst')->willReturn($rows);

        $result = $this->feed->recentEventsByTicker([$lake, $swan], self::NOW);

        $this->assertCount(6, $result['LAKE'], 'Should cap at EVENTS_PER_TICKER even though 7 rows were returned for LAKE');
        $this->assertCount(1, $result['SWAN']);
    }

    public function testEmptyHistoryYieldsAnEmptyListNotAMissingKey(): void
    {
        $stock = $this->makeStock('FINC');
        $this->repository->method('findForStocksNewestFirst')->willReturn([]);

        $result = $this->feed->recentEventsByTicker([$stock], self::NOW);

        $this->assertArrayHasKey('FINC', $result);
        $this->assertSame([], $result['FINC']);
    }

    /**
     * The badge used to print the whole capped backfill, so it read "6" on every building for good. Only events inside
     * one simulated month of now count; the rest still ship for the side panel. A paused ticker leaves now where it
     * is, so nothing ages out while the market stands still.
     */
    public function testOnlyEventsInsideTheBadgeWindowAreMarkedRecent(): void
    {
        $stock = $this->makeStock('LAKE');
        $window = DistrictMap::EVENT_BADGE_WINDOW_YEARS;
        $justNow = $this->makeEvent($stock, 'EARNINGS', 'Fresh')->setSimTime(self::NOW - 0.001);
        $onTheEdge = $this->makeEvent($stock, 'EARNINGS', 'A month ago')->setSimTime(self::NOW - $window);
        $stale = $this->makeEvent($stock, 'EARNINGS', 'Older')->setSimTime(self::NOW - $window - 0.001);
        $undated = $this->makeEvent($stock, 'EARNINGS', 'From before stories carried their simulation time');

        $this->repository->method('findForStocksNewestFirst')->willReturn([$justNow, $onTheEdge, $stale, $undated]);

        $result = $this->feed->recentEventsByTicker([$stock], self::NOW);

        $this->assertCount(4, $result['LAKE'], 'Stale events still ship for the side panel');
        $this->assertSame([true, true, false, false], array_column($result['LAKE'], 'recent'));
    }
}
