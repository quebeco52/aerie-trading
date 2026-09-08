<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\District\DistrictEventFeed;
use App\Service\Event\EventPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the district event backfill's one job: fetch each tenant's recent history and hand it to
 * the real App\Service\Event\EventPresenter, never a parallel presentation of its own — so a
 * district badge and the stock page's own event feed always agree on badge/icon/colour for the
 * same event.
 */
#[AllowMockObjectsWithoutExpectations]
class DistrictEventFeedTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    /** @var EntityRepository<StockEvent>&MockObject */
    private EntityRepository&MockObject $repository;
    private DistrictEventFeed $feed;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);
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

    private function makeEvent(string $type, string $description, ?float $changePercent = null): StockEvent
    {
        $event = new StockEvent();
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
        $shockEvent = $this->makeEvent('SHOCK', 'A sudden liquidity event rattled the row.', -4.2);

        $this->repository->method('findBy')->willReturn([$shockEvent]);

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
        $event = $this->makeEvent('BANKRUPTCY', 'Filed for liquidation.');
        $event->setRecordedAt(new \DateTime('2026-03-14 09:30:00'));

        $this->repository->method('findBy')->willReturn([$event]);

        $result = $this->feed->recentEventsByTicker([$stock]);

        $this->assertIsString($result['SWAN'][0]['recordedAt']);
        $this->assertSame('2026-03-14 09:30', $result['SWAN'][0]['recordedAt']);
    }

    public function testQueriesEachStockIndependentlyOrderedNewestFirstWithACap(): void
    {
        $lake = $this->makeStock('LAKE');
        $swan = $this->makeStock('SWAN');

        $calls = [];
        $this->repository
            ->method('findBy')
            ->willReturnCallback(function ($criteria, $orderBy, $limit) use (&$calls) {
                $calls[] = [$criteria, $orderBy, $limit];

                return [];
            });

        $this->feed->recentEventsByTicker([$lake, $swan]);

        $this->assertCount(2, $calls, 'Expected one findBy() call per stock');
        foreach ($calls as [$criteria, $orderBy, $limit]) {
            $this->assertSame(['recordedAt' => 'DESC'], $orderBy);
            $this->assertIsInt($limit);
            $this->assertGreaterThan(0, $limit);
        }
    }

    public function testEmptyHistoryYieldsAnEmptyListNotAMissingKey(): void
    {
        $stock = $this->makeStock('ROOK');
        $this->repository->method('findBy')->willReturn([]);

        $result = $this->feed->recentEventsByTicker([$stock]);

        $this->assertArrayHasKey('ROOK', $result);
        $this->assertSame([], $result['ROOK']);
    }
}
