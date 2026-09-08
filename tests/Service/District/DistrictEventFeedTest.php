<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\District\DistrictEventFeed;
use App\Service\Event\EventPresenter;
use Doctrine\ORM\Query;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the district event backfill's job: fetch every tenant's recent history in one query and
 * hand each event to the real App\Service\Event\EventPresenter, never a parallel presentation of
 * its own — so a district badge and the stock page's own event feed always agree on
 * badge/icon/colour for the same event. One query rather than one per tenant is what
 * StockEvent's (stock_id, recorded_at) index exists to make cheap — see recentEventsByTicker()'s
 * own docblock.
 */
#[AllowMockObjectsWithoutExpectations]
class DistrictEventFeedTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    /** @var EntityRepository<StockEvent>&MockObject */
    private EntityRepository&MockObject $repository;
    private QueryBuilder&MockObject $queryBuilder;
    /** @var Query<int, mixed>&MockObject */
    private Query&MockObject $query;
    private DistrictEventFeed $feed;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->willReturn($this->repository);

        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->repository->method('createQueryBuilder')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('andWhere')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('setParameter')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('orderBy')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('addOrderBy')->willReturn($this->queryBuilder);

        $this->query = $this->createMock(Query::class);
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

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
        $this->assertSame([], $this->feed->recentEventsByTicker([]));
    }

    public function testKeysResultByTickerAndPresentsEachEvent(): void
    {
        $stock = $this->makeStock('LAKE');
        $shockEvent = $this->makeEvent($stock, 'SHOCK', 'A sudden liquidity event rattled the row.', -4.2);

        $this->query->method('getResult')->willReturn([$shockEvent]);

        $result = $this->feed->recentEventsByTicker([$stock]);

        $this->assertArrayHasKey('LAKE', $result);
        $this->assertCount(1, $result['LAKE']);

        $presented = $result['LAKE'][0];
        $this->assertSame('shock', $presented['category']);
        $this->assertSame('bolt', $presented['icon']);
        $this->assertArrayHasKey('badge', $presented);
        $this->assertArrayHasKey('badgeClass', $presented);
    }

    public function testFlattensRecordedAtToAPlainStringForJsonTransport(): void
    {
        $stock = $this->makeStock('SWAN');
        $event = $this->makeEvent($stock, 'BANKRUPTCY', 'Filed for liquidation.');
        $event->setRecordedAt(new \DateTime('2026-03-14 09:30:00'));

        $this->query->method('getResult')->willReturn([$event]);

        $result = $this->feed->recentEventsByTicker([$stock]);

        $this->assertIsString($result['SWAN'][0]['recordedAt']);
        $this->assertSame('2026-03-14 09:30', $result['SWAN'][0]['recordedAt']);
    }

    public function testFetchesAllStocksInOneQuery(): void
    {
        $lake = $this->makeStock('LAKE');
        $swan = $this->makeStock('SWAN');

        $this->repository->expects($this->once())->method('createQueryBuilder');
        $this->query->method('getResult')->willReturn([]);

        $this->feed->recentEventsByTicker([$lake, $swan]);
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

        $this->query->method('getResult')->willReturn($rows);

        $result = $this->feed->recentEventsByTicker([$lake, $swan]);

        $this->assertCount(6, $result['LAKE'], 'Should cap at EVENTS_PER_TICKER even though 7 rows were returned for LAKE');
        $this->assertCount(1, $result['SWAN']);
    }

    public function testEmptyHistoryYieldsAnEmptyListNotAMissingKey(): void
    {
        $stock = $this->makeStock('ROOK');
        $this->query->method('getResult')->willReturn([]);

        $result = $this->feed->recentEventsByTicker([$stock]);

        $this->assertArrayHasKey('ROOK', $result);
        $this->assertSame([], $result['ROOK']);
    }
}
