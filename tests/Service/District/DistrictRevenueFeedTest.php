<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\District\DistrictRevenueFeed;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the district revenue-mix summary: it must read the same revenueStreams/streamDetails
 * EarningsReportSubscriber already writes onto CorporateReport, never recompute a mix of its own,
 * degrade gracefully for a tenant with no quarterly report yet, and fetch every tenant's latest
 * report in one query rather than one per tenant — see latestRevenueMixByTicker()'s own docblock.
 */
#[AllowMockObjectsWithoutExpectations]
class DistrictRevenueFeedTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    /** @var EntityRepository<CorporateReport>&MockObject */
    private EntityRepository&MockObject $repository;
    private QueryBuilder&MockObject $queryBuilder;
    /** @var Query<int, mixed>&MockObject */
    private Query&MockObject $query;
    private DistrictRevenueFeed $feed;

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

        $this->feed = new DistrictRevenueFeed($this->entityManager);
    }

    private function makeStock(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Holdings');

        return $stock;
    }

    private function makeReport(
        Stock $stock,
        float $revenue,
        array $revenueStreams,
        array $streamDetails,
    ): CorporateReport {
        $report = new CorporateReport();
        $report->setStock($stock);
        $report->setRevenue((string) $revenue);
        $report->setRevenueStreams($revenueStreams);
        $report->setStreamDetails($streamDetails);
        $report->setRecordedAt(new \DateTime());

        return $report;
    }

    public function testReturnsEmptyArrayForNoStocks(): void
    {
        $this->assertSame([], $this->feed->latestRevenueMixByTicker([]));
    }

    public function testTenantWithNoReportGetsAnEmptyMixNotAMissingKey(): void
    {
        $stock = $this->makeStock('ROOK');
        $this->query->method('getResult')->willReturn([]);

        $result = $this->feed->latestRevenueMixByTicker([$stock]);

        $this->assertArrayHasKey('ROOK', $result);
        $this->assertSame(0.0, $result['ROOK']['totalRevenue']);
        $this->assertSame([], $result['ROOK']['streams']);
    }

    public function testFetchesAllStocksInOneQuery(): void
    {
        $lake = $this->makeStock('LAKE');
        $swan = $this->makeStock('SWAN');

        $this->repository->expects($this->once())->method('createQueryBuilder');
        $this->query->method('getResult')->willReturn([]);

        $this->feed->latestRevenueMixByTicker([$lake, $swan]);
    }

    public function testOnlyTheFirstRowPerTickerIsUsedAsItsLatestReport(): void
    {
        $stock = $this->makeStock('LAKE');
        $newer = $this->makeReport($stock, 300.0, ['fee_income' => 300.0], ['fee_income' => ['share' => 1.0, 'qoq_delta' => 0.0, 'drivers' => []]]);
        $older = $this->makeReport($stock, 100.0, ['fee_income' => 100.0], ['fee_income' => ['share' => 1.0, 'qoq_delta' => 0.0, 'drivers' => []]]);

        // The query orders newest-first per ticker, so the first row for a ticker is its latest.
        $this->query->method('getResult')->willReturn([$newer, $older]);

        $result = $this->feed->latestRevenueMixByTicker([$stock]);

        $this->assertSame(300.0, $result['LAKE']['totalRevenue']);
    }

    public function testBuildsAStreamPerRevenueStreamKeyedByTicker(): void
    {
        $stock = $this->makeStock('LAKE');
        $report = $this->makeReport(
            $stock,
            300_000_000.0,
            ['net_interest_income' => 200_000_000.0, 'fee_income' => 60_000_000.0, 'proprietary_dividend' => 40_000_000.0],
            [
                'net_interest_income' => ['share' => 0.6667, 'qoq_delta' => -0.05, 'drivers' => [
                    ['label' => 'Yield Curve & NIM Spread (90 bps)', 'impact' => -0.02, 'type' => 'macro'],
                ]],
                'fee_income' => ['share' => 0.20, 'qoq_delta' => 0.03, 'drivers' => []],
                'proprietary_dividend' => ['share' => 0.1333, 'qoq_delta' => 0.0, 'drivers' => []],
            ],
        );

        $this->query->method('getResult')->willReturn([$report]);

        $result = $this->feed->latestRevenueMixByTicker([$stock]);

        $this->assertArrayHasKey('LAKE', $result);
        $this->assertSame(300_000_000.0, $result['LAKE']['totalRevenue']);
        $this->assertCount(3, $result['LAKE']['streams']);
    }

    public function testStreamsAreSortedByShareDescending(): void
    {
        $stock = $this->makeStock('LAKE');
        $report = $this->makeReport(
            $stock,
            100.0,
            ['fee_income' => 20.0, 'net_interest_income' => 80.0],
            [
                'fee_income' => ['share' => 0.20, 'qoq_delta' => 0.0, 'drivers' => []],
                'net_interest_income' => ['share' => 0.80, 'qoq_delta' => 0.0, 'drivers' => []],
            ],
        );
        $this->query->method('getResult')->willReturn([$report]);

        $streams = $this->feed->latestRevenueMixByTicker([$stock])['LAKE']['streams'];

        $this->assertSame('net_interest_income', $streams[0]['key']);
        $this->assertSame('fee_income', $streams[1]['key']);
    }

    public function testPrettifiesStreamKeysIntoTitleCaseLabels(): void
    {
        $stock = $this->makeStock('LAKE');
        $report = $this->makeReport(
            $stock,
            100.0,
            ['net_interest_income' => 100.0],
            ['net_interest_income' => ['share' => 1.0, 'qoq_delta' => 0.0, 'drivers' => []]],
        );
        $this->query->method('getResult')->willReturn([$report]);

        $stream = $this->feed->latestRevenueMixByTicker([$stock])['LAKE']['streams'][0];

        $this->assertSame('Net Interest Income', $stream['label']);
    }

    public function testCarriesDriversAndEventThrough(): void
    {
        $stock = $this->makeStock('SWAN');
        $driver = ['label' => 'Directional Macro Positioning', 'impact' => 0.18, 'type' => 'macro'];
        $report = $this->makeReport(
            $stock,
            50.0,
            ['directional_bets' => 50.0],
            ['directional_bets' => ['share' => 1.0, 'qoq_delta' => 0.22, 'drivers' => [$driver], 'event' => 'Hf Margin Call']],
        );
        $this->query->method('getResult')->willReturn([$report]);

        $stream = $this->feed->latestRevenueMixByTicker([$stock])['SWAN']['streams'][0];

        $this->assertSame([$driver], $stream['drivers']);
        $this->assertSame('Hf Margin Call', $stream['event']);
        $this->assertSame(0.22, $stream['qoqDelta']);
    }

    public function testMissingStreamDetailsFallBackToZeroedFields(): void
    {
        $stock = $this->makeStock('VULT');
        $report = $this->makeReport($stock, 10.0, ['restructuring_advisory' => 10.0], []);
        $this->query->method('getResult')->willReturn([$report]);

        $stream = $this->feed->latestRevenueMixByTicker([$stock])['VULT']['streams'][0];

        $this->assertSame(0.0, $stream['share']);
        $this->assertSame(0.0, $stream['qoqDelta']);
        $this->assertSame([], $stream['drivers']);
        $this->assertNull($stream['event']);
    }
}
