<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Etf;
use App\Entity\EtfHistory;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\EtfTracker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EtfTrackerTest extends TestCase
{
    private EntityManagerInterface&MockObject $emMock;
    private MarketEventPublisher&Stub $marketEventMock;
    private \Redis&MockObject $redisMock;
    private Connection&MockObject $connectionMock;
    /** @var AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform>&Stub */
    private AbstractSchemaManager&Stub $schemaManagerStub;
    /** @var EntityRepository<Etf>&Stub */
    private EntityRepository&Stub $etfRepoStub;
    private EtfTracker $tracker;
    private \App\Service\Market\IndexFundAccountant $accountant;
    /** @var list<string> Every SQL statement the tracker issued, in order. */
    private array $statements = [];

    protected function setUp(): void
    {
        $this->emMock = $this->createMock(EntityManagerInterface::class);
        $this->marketEventMock = $this->createStub(MarketEventPublisher::class);
        $this->redisMock = $this->createMock(\Redis::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->schemaManagerStub = $this->createStub(AbstractSchemaManager::class);
        $this->etfRepoStub = $this->createStub(EntityRepository::class);

        $this->statements = [];
        $this->connectionMock->method('createSchemaManager')->willReturn($this->schemaManagerStub);
        $this->emMock->method('getConnection')->willReturn($this->connectionMock);
        $this->emMock->method('getRepository')->willReturnCallback(function (string $entityClass) {
            if ($entityClass === Etf::class) {
                return $this->etfRepoStub;
            }
            return $this->createStub(EntityRepository::class);
        });

        $this->accountant = new \App\Service\Market\IndexFundAccountant($this->emMock);

        $this->tracker = new EtfTracker(
            $this->emMock,
            $this->marketEventMock,
            $this->redisMock,
            $this->accountant
        );
    }

    public function testUpdateIndexInitializesDivisorAndCalculatesPrice(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');

        // Redis has no divisor initially
        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor:LBI')->willReturn(false);
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('market_index_divisor:LBI', $this->callback(fn(mixed $val) => is_string($val)));

        $totalMarketCap = 1_000_000_000.0; // $1B
        $result = $this->tracker->updateIndex($totalMarketCap, false, 'LBI', $etf);

        $this->assertSame('LBI', $result['ticker']);
        $this->assertSame(100.0, $result['price']);
        $this->assertTrue($result['is_etf']);
        $this->assertSame('100', $etf->getPrice());
    }

    public function testUpdateIndexPersistsHistoryWhenRequested(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');

        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor:LBI')->willReturn('10000000'); // 10M divisor

        $this->emMock->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (mixed $entity) use ($etf) {
                return $entity instanceof EtfHistory
                    && $entity->getEtf() === $etf
                    && $entity->getPrice() === '110';
            }));

        $totalMarketCap = 1_100_000_000.0; // $1.1B -> $110 price
        $result = $this->tracker->updateIndex($totalMarketCap, true, 'LBI', $etf);

        $this->assertSame(110.0, $result['price']);
    }

    // --- The Fund Versus Its Index ---

    /**
     * The published price is the fund's, not the index's: the level scaled by the basket a share still owns,
     * plus the income the fund is holding for its members.
     *
     * Publishing the level instead made every fund a claim on a price index — perfect tracking, and no
     * dividends, forever.
     */
    public function testThePublishedPriceIsTheFundsRatherThanTheIndexs(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');
        $etf->setExpenseRatio(0.0);
        $etf->setBasketPerShare(0.98);
        $etf->setAccruedIncome(1.25);

        $this->redisMock->method('get')->willReturn('10000000');

        // A $1.1B members' cap on a 10M divisor is a level of 110.
        $result = $this->tracker->updateIndex(1_100_000_000.0, false, 'LBI', $etf);

        $this->assertSame(round((110.0 * 0.98) + 1.25, 2), $result['price']);
        $this->assertEqualsWithDelta(110.0, $etf->getIndexLevel(), 1e-9);
    }

    /**
     * The fund collects the dividends its constituents paid, and the divisor converts them into the same
     * units the level is struck in.
     */
    public function testTheFundCollectsTheIndexDividend(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');
        $etf->setExpenseRatio(0.0);

        $this->redisMock->method('get')->willReturn('10000000');

        // $20M of dividends over a 10M divisor is 2 index points of income.
        $this->tracker->updateIndex(1_000_000_000.0, false, 'LBI', $etf, null, 20_000_000.0, 0.0);

        $this->assertEqualsWithDelta(2.0, $etf->getAccruedIncome(), 1e-9);
        // And the price carries it until it is paid out.
        $this->assertEqualsWithDelta(102.0, (float) $etf->getPrice(), 1e-9);
    }

    /**
     * A split restates the accrued income, because it is a per-SHARE amount and a split changes the share
     * count. Left alone, a 4-for-1 would quadruple the cash the fund believes it is holding for its members
     * and hand out four times what it collected at the next distribution.
     */
    public function testAForwardSplitRestatesTheAccruedIncome(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');
        $etf->setExpenseRatio(0.0);
        $etf->setAccruedIncome(4.0);

        $this->redisMock->method('get')->willReturn('1000000');
        $this->schemaManagerStub->method('tablesExist')->willReturn(true);

        // $800M on a 1M divisor is a level of 800; plus $4 of income the price is $804, so it splits 4-for-1.
        $result = $this->tracker->updateIndex(800_000_000.0, false, 'LBI', $etf);

        $this->assertEqualsWithDelta(201.0, $result['price'], 0.01);
        $this->assertEqualsWithDelta(1.0, $etf->getAccruedIncome(), 1e-9);

        // The identity still holds on the far side of the split, which is what the restatement is for.
        $this->assertEqualsWithDelta(200.0, $etf->getIndexLevel(), 1e-6);
    }

    public function testUpdateIndexExecutesForwardSplitWhenPriceExceeds400(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');

        // Divisor is 1M -> with $800M cap, price would be $800 >= 400 -> triggers 4:1 forward split
        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor:LBI')->willReturn('1000000');
        $this->schemaManagerStub->method('tablesExist')->willReturn(true);

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');

        // user_etfs + etf_history + the two order-book restatements (open orders, executed history)
        $this->connectionMock->expects($this->exactly(4))
            ->method('executeStatement')
            ->with($this->stringContains('UPDATE'));

        $totalMarketCap = 800_000_000.0;
        $result = $this->tracker->updateIndex($totalMarketCap, false, 'LBI', $etf);

        // $800 / 4 = $200
        $this->assertSame(200.0, $result['price']);
    }

    public function testUpdateIndexExecutesReverseSplitWhenPriceFallsBelow25(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');

        // Divisor is 10M -> with $100M cap, price would be $10 < 25 -> triggers 1-for-4 reverse split
        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor:LBI')->willReturn('10000000');
        $this->schemaManagerStub->method('tablesExist')->willReturn(true);

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');

        // Order book first (escrow remnant refund, open rescale, cancel the emptied, executed history),
        // then cashout + user_etfs quantity + delete 0 quantity + history update = 8 statements
        $this->connectionMock->expects($this->exactly(8))
            ->method('executeStatement');

        $totalMarketCap = 100_000_000.0;
        $result = $this->tracker->updateIndex($totalMarketCap, false, 'LBI', $etf);

        // $10 * 4 = $40
        $this->assertSame(40.0, $result['price']);
    }

    /** Records every statement a split issues, for the order-book assertions below. */
    private function captureSplitStatements(string $divisor): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30 ETF');
        $etf->setPrice('100.00');

        $this->redisMock->method('get')->willReturn($divisor);
        $this->schemaManagerStub->method('tablesExist')->willReturn(true);
        $this->connectionMock->method('executeStatement')->willReturnCallback(
            function (string $sql): int {
                $this->statements[] = $sql;

                return 1;
            }
        );

        $this->tracker->updateIndex($divisor === '1000000' ? 800_000_000.0 : 100_000_000.0, false, 'LBI', $etf);
    }

    /** @return list<string> */
    private function orderBookStatements(): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql): bool => str_contains($sql, 'trade_orders')
        ));
    }

    /**
     * An ETF split used to touch only user_etfs and etf_history, leaving the order book quoting against a
     * book that had just moved: a resting BUY at $60 under a $100 ETF became an immediate fill the moment a
     * 4-for-1 took the price to $25, and the escrow behind every open order was denominated in units that
     * no longer existed.
     */
    public function testForwardSplitRestatesTheOrderBook(): void
    {
        $this->captureSplitStatements('1000000');

        $orderBook = $this->orderBookStatements();
        $this->assertCount(2, $orderBook, 'Open orders and executed history both have to be restated.');

        $open = $orderBook[0];
        $this->assertStringContainsString("status = 'OPEN'", $open);
        $this->assertStringContainsString('quantity * :factor', $open);
        $this->assertStringContainsString('limit_price = ROUND(limit_price / :factor, 4)', $open);

        $filled = $orderBook[1];
        $this->assertStringContainsString("status = 'FILLED'", $filled);
        $this->assertStringContainsString('execution_price = ROUND(execution_price / :factor, 4)', $filled);
    }

    /**
     * The reverse direction carries the same escrow remnant the stock ledger does, and pays it out before
     * the rewrite that would otherwise round it away.
     */
    public function testReverseSplitRefundsEscrowBeforeRestatingTheOrderBook(): void
    {
        $this->captureSplitStatements('10000000');

        $refundIndex = null;
        $rewriteIndex = null;
        foreach ($this->statements as $index => $sql) {
            if ($refundIndex === null && str_contains($sql, 'remnant_value')) {
                $refundIndex = $index;
            }
            if ($rewriteIndex === null && str_contains($sql, 'limit_price = ROUND(limit_price * :factor')) {
                $rewriteIndex = $index;
            }
        }

        $this->assertNotNull($refundIndex, 'A reverse ETF split must refund the escrow remnant.');
        $this->assertNotNull($rewriteIndex);
        $this->assertLessThan($rewriteIndex, $refundIndex);

        $orderBook = $this->orderBookStatements();
        $this->assertNotEmpty($orderBook);
        $this->assertTrue(
            (bool) array_filter($orderBook, static fn (string $sql): bool => str_contains($sql, "status = 'CANCELLED'")),
            'An order emptied by the reverse split has to be cancelled, not left resting at zero shares.'
        );
    }
}
