<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Stock;
use App\Entity\StockHistory;
use App\Service\Market\PriceChangeFeed;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exchange's one piece of derived market data: a price change read from the Redis chart
 * buffer the ticker already maintains, in a single pipelined pass. Nothing about the buffer is
 * assumed beyond its documented shape — a newest-first list of
 * `{"price": …, "recorded_at": …}` entries — and a ticker with nothing usable buffered must read
 * as *unknown* rather than as flat, because "no history yet" and "hasn't moved" are different
 * facts and the kerb prints them differently — unless the persisted history can answer instead,
 * which is the fallback the second half of this file pins.
 */
#[AllowMockObjectsWithoutExpectations]
class PriceChangeFeedTest extends TestCase
{
    /** 3,600 ticks a year samples history every tick, so one month of lookback is 300 rows. */
    private const TICKS_PER_YEAR = 3600;

    private \Redis&MockObject $redis;
    private EntityManagerInterface&MockObject $entityManager;
    /** @var EntityRepository<StockHistory>&MockObject */
    private EntityRepository&MockObject $repository;
    private QueryBuilder&MockObject $queryBuilder;
    /** @var Query<int, mixed>&MockObject */
    private Query&MockObject $query;
    private PriceChangeFeed $feed;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->redis->method('multi')->willReturnSelf();

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->willReturn($this->repository);

        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->repository->method('createQueryBuilder')->willReturn($this->queryBuilder);
        foreach (['select', 'andWhere', 'setParameter', 'orderBy', 'setMaxResults'] as $fluent) {
            $this->queryBuilder->method($fluent)->willReturn($this->queryBuilder);
        }

        $this->query = $this->createMock(Query::class);
        $this->queryBuilder->method('getQuery')->willReturn($this->query);
        // No history on file unless a test says otherwise, so the Redis-only expectations hold.
        $this->query->method('getScalarResult')->willReturn([]);

        $this->feed = new PriceChangeFeed($this->redis, $this->entityManager, self::TICKS_PER_YEAR);
    }

    /** @param list<float> $closesNewestFirst */
    private function historyOnFile(array $closesNewestFirst): void
    {
        $this->query = $this->createMock(Query::class);
        $this->query->method('getScalarResult')->willReturn(
            array_map(static fn (float $close) => ['price' => (string) $close], $closesNewestFirst),
        );
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'andWhere', 'setParameter', 'orderBy', 'setMaxResults'] as $fluent) {
            $this->queryBuilder->method($fluent)->willReturn($this->queryBuilder);
        }
        $this->queryBuilder->method('getQuery')->willReturn($this->query);
        $this->repository = $this->createMock(EntityRepository::class);
        $this->repository->method('createQueryBuilder')->willReturn($this->queryBuilder);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->willReturn($this->repository);

        $this->feed = new PriceChangeFeed($this->redis, $this->entityManager, self::TICKS_PER_YEAR);
    }

    private function makeStock(string $ticker, float $price): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Holdings');
        $stock->setPrice((string) $price);

        return $stock;
    }

    private function buffered(float $price): string
    {
        return json_encode(['price' => $price, 'recorded_at' => '2026-03-14 09:30:00']);
    }

    public function testReturnsEmptyArrayForNoStocks(): void
    {
        $this->assertSame([], $this->feed->changeByTicker([]));
    }

    public function testComputesTheFractionalChangeAgainstTheOldestBufferedPrice(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(100.0)]);

        $changes = $this->feed->changeByTicker([$this->makeStock('LAKE', 125.0)]);

        $this->assertEqualsWithDelta(0.25, $changes['LAKE'], 0.0001);
    }

    public function testReportsAFallAsANegativeChange(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(200.0)]);

        $changes = $this->feed->changeByTicker([$this->makeStock('SWAN', 150.0)]);

        $this->assertEqualsWithDelta(-0.25, $changes['SWAN'], 0.0001);
    }

    public function testReadsTheTailOfEachTickersBufferInOnePipelinedPass(): void
    {
        $requested = [];
        $this->redis->method('lIndex')->willReturnCallback(function (string $key, int $index) use (&$requested) {
            $requested[] = [$key, $index];

            return $this->redis;
        });
        $this->redis->method('exec')->willReturn([$this->buffered(10.0), $this->buffered(20.0)]);

        $this->feed->changeByTicker([$this->makeStock('LAKE', 11.0), $this->makeStock('SWAN', 22.0)]);

        $this->assertSame([
            ['chart_buffer:LAKE', -1],
            ['chart_buffer:SWAN', -1],
        ], $requested, 'Index -1 is the oldest entry of a newest-first list, and degrades gracefully when the buffer is short');
    }

    public function testTickerWithNoBufferedHistoryIsAbsentRatherThanZero(): void
    {
        $this->redis->method('exec')->willReturn([false]);

        $this->assertSame([], $this->feed->changeByTicker([$this->makeStock('ROOK', 50.0)]));
    }

    public function testMalformedBufferEntryIsSkipped(): void
    {
        $this->redis->method('exec')->willReturn(['not json at all']);

        $this->assertSame([], $this->feed->changeByTicker([$this->makeStock('ROOK', 50.0)]));
    }

    public function testAZeroBaselineIsSkippedRatherThanDividedBy(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(0.0)]);

        $this->assertSame([], $this->feed->changeByTicker([$this->makeStock('VULT', 12.0)]));
    }

    public function testABankruptTenantAtZeroIsSkipped(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(80.0)]);

        $this->assertSame([], $this->feed->changeByTicker([$this->makeStock('VULT', 0.0)]));
    }

    public function testEachTickerKeepsItsOwnResultWhenSomeAreMissing(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(100.0), false, $this->buffered(50.0)]);

        $changes = $this->feed->changeByTicker([
            $this->makeStock('LAKE', 110.0),
            $this->makeStock('SWAN', 200.0),
            $this->makeStock('ROOK', 45.0),
        ]);

        $this->assertEqualsWithDelta(0.10, $changes['LAKE'], 0.0001);
        $this->assertArrayNotHasKey('SWAN', $changes);
        $this->assertEqualsWithDelta(-0.10, $changes['ROOK'], 0.0001);
    }

    public function testChangeForTickerReadsTheSameBufferTailAsTheBatchPath(): void
    {
        $this->redis->expects($this->once())
            ->method('lIndex')
            ->with('chart_buffer:LBI', -1)
            ->willReturn($this->buffered(400.0));

        $this->assertEqualsWithDelta(0.05, $this->feed->changeForTicker('LBI', 420.0), 0.0001);
    }

    public function testChangeForTickerReportsAFallAsANegativeChange(): void
    {
        $this->redis->method('lIndex')->willReturn($this->buffered(80.0));

        $this->assertEqualsWithDelta(-0.25, $this->feed->changeForTicker('SWAN', 60.0), 0.0001);
    }

    public function testChangeForTickerIsUnknownWhenNothingIsBuffered(): void
    {
        $this->redis->method('lIndex')->willReturn(false);

        $this->assertNull($this->feed->changeForTicker('ROOK', 50.0));
    }

    public function testChangeForTickerIsUnknownForAMalformedEntry(): void
    {
        $this->redis->method('lIndex')->willReturn('not json at all');

        $this->assertNull($this->feed->changeForTicker('ROOK', 50.0));
    }

    public function testChangeForTickerRefusesToDivideByAZeroBaseline(): void
    {
        $this->redis->method('lIndex')->willReturn($this->buffered(0.0));

        $this->assertNull($this->feed->changeForTicker('VULT', 12.0));
    }

    public function testChangeForTickerTreatsADelistedAssetAtZeroAsUnknown(): void
    {
        $this->redis->expects($this->never())->method('lIndex');

        $this->assertNull($this->feed->changeForTicker('VULT', 0.0));
    }

    public function testChangeForTickerSkipsRedisEntirelyForAnEmptyTicker(): void
    {
        $this->redis->expects($this->never())->method('lIndex');

        $this->assertNull($this->feed->changeForTicker('', 42.0));
    }

    // --- Persisted-history fallback ---

    public function testOneLookbackIsOneMonthOfHistoryRowsAtTheConfiguredRate(): void
    {
        $this->assertSame(300, $this->feed->historyRowsPerLookback());
    }

    public function testAColdBufferFallsBackToTheOldestPersistedCloseInTheLookback(): void
    {
        $this->redis->method('exec')->willReturn([false]);
        // Newest first, as the query orders: the last row is the month-old baseline.
        $this->historyOnFile([118.0, 110.0, 100.0]);

        $changes = $this->feed->changeByTicker([$this->makeStock('LAKE', 125.0)]);

        $this->assertEqualsWithDelta(0.25, $changes['LAKE'], 0.0001);
    }

    public function testAZeroBufferTailFallsBackToPersistedHistoryToo(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(0.0)]);
        $this->historyOnFile([90.0, 80.0]);

        $changes = $this->feed->changeByTicker([$this->makeStock('VULT', 100.0)]);

        $this->assertEqualsWithDelta(0.25, $changes['VULT'], 0.0001);
    }

    public function testTheFallbackAsksForExactlyOneLookbackOfRows(): void
    {
        $this->redis->method('exec')->willReturn([false]);
        $this->queryBuilder->expects($this->once())->method('setMaxResults')->with(300);

        $this->feed->changeByTicker([$this->makeStock('LAKE', 125.0)]);
    }

    public function testAWarmBufferNeverTouchesTheDatabase(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(100.0), $this->buffered(50.0)]);
        $this->repository->expects($this->never())->method('createQueryBuilder');

        $changes = $this->feed->changeByTicker([$this->makeStock('LAKE', 110.0), $this->makeStock('ROOK', 45.0)]);

        $this->assertCount(2, $changes);
    }

    public function testOnlyTheColdTickersFallBack(): void
    {
        $this->redis->method('exec')->willReturn([$this->buffered(100.0), false]);
        $this->historyOnFile([200.0]);
        $this->repository->expects($this->once())->method('createQueryBuilder');

        $changes = $this->feed->changeByTicker([$this->makeStock('LAKE', 110.0), $this->makeStock('SWAN', 150.0)]);

        $this->assertEqualsWithDelta(0.10, $changes['LAKE'], 0.0001);
        $this->assertEqualsWithDelta(-0.25, $changes['SWAN'], 0.0001);
    }

    public function testAColdBufferWithNoHistoryOnFileStaysUnknown(): void
    {
        $this->redis->method('exec')->willReturn([false]);

        $this->assertSame([], $this->feed->changeByTicker([$this->makeStock('ROOK', 50.0)]));
    }

    public function testAZeroPersistedBaselineIsSkippedRatherThanDividedBy(): void
    {
        $this->redis->method('exec')->willReturn([false]);
        $this->historyOnFile([12.0, 0.0]);

        $this->assertSame([], $this->feed->changeByTicker([$this->makeStock('VULT', 12.0)]));
    }

    public function testADelistedStockAtZeroNeverConsultsHistory(): void
    {
        $this->redis->method('exec')->willReturn([false]);
        $this->repository->expects($this->never())->method('createQueryBuilder');

        $this->assertSame([], $this->feed->changeByTicker([$this->makeStock('VULT', 0.0)]));
    }
}
