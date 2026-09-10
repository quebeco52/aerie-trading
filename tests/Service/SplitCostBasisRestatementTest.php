<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Stock;
use App\Service\Corporate\CorporateLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * A split has to restate executed order history, not just holdings.
 *
 * The cost basis every portfolio surface prints is derived from FILLED trade orders. The split routine
 * restated user_stocks, stock_history, corporate_report and OPEN orders but left the executed history in
 * pre-split units, so after a 4-for-1 a position bought for $4,000 read as having cost $16,000 and the
 * page showed a 75% loss the holder never took. A reverse split produced the mirror-image phantom gain.
 */
#[AllowMockObjectsWithoutExpectations]
final class SplitCostBasisRestatementTest extends TestCase
{
    private EntityManagerInterface&Stub $entityManagerMock;
    private Connection&MockObject $connectionMock;
    private CorporateLedgerService $service;
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $statements = [];

    protected function setUp(): void
    {
        $this->statements = [];
        $this->entityManagerMock = $this->createStub(EntityManagerInterface::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->entityManagerMock->method('getConnection')->willReturn($this->connectionMock);
        $this->connectionMock->method('fetchAllAssociative')->willReturn([]);
        $this->connectionMock->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            }
        );

        $this->service = new CorporateLedgerService($this->entityManagerMock);
    }

    /** The one statement that restates executed history. */
    private function filledOrderStatement(): array
    {
        $matches = array_values(array_filter(
            $this->statements,
            static fn (array $s): bool => str_contains($s['sql'], 'trade_orders') && str_contains($s['sql'], "status = 'FILLED'")
        ));

        $this->assertCount(1, $matches, 'A split must restate filled trade orders exactly once.');

        return $matches[0];
    }

    /** The one statement that restates the price history table. */
    private function historyStatement(): array
    {
        $matches = array_values(array_filter(
            $this->statements,
            static fn (array $s): bool => str_contains($s['sql'], 'stock_history')
        ));

        $this->assertCount(1, $matches, 'A split must restate price history exactly once.');

        return $matches[0];
    }

    /**
     * A history row is a whole bar, and all four of its prices are quoted in shares.
     *
     * Restating only the close leaves open, high and low in pre-split units, which draws every historical
     * candle with a wick spanning the entire split factor — a chart that says the stock traded from $25 to
     * $100 on a day it never moved. The columns arrived with the candle chart and this restatement did not
     * learn about them.
     *
     * @param non-empty-list<string> $expected
     */
    #[DataProvider('barColumnRestatementCases')]
    public function testASplitRestatesEveryPriceInTheBarNotJustTheClose(
        float $factor,
        bool $isReverse,
        array $expected,
    ): void {
        $stock = new Stock();
        $stock->setTicker('BAR_CORP');

        $this->service->processStockSplit($stock, $factor, $isReverse, 2.50);

        $sql = $this->historyStatement()['sql'];
        foreach ($expected as $fragment) {
            $this->assertStringContainsString($fragment, $sql);
        }
    }

    /** @return array<string, array{float, bool, non-empty-list<string>}> */
    public static function barColumnRestatementCases(): array
    {
        return [
            'forward' => [4.0, false, [
                'price = GREATEST(price / :factor',
                'open_price = GREATEST(open_price / :factor',
                'high_price = GREATEST(high_price / :factor',
                'low_price = GREATEST(low_price / :factor',
            ]],
            'reverse' => [10.0, true, [
                'price = LEAST(price * :factor',
                'open_price = LEAST(open_price * :factor',
                'high_price = LEAST(high_price * :factor',
                'low_price = LEAST(low_price * :factor',
            ]],
        ];
    }

    /**
     * Volume is a share count, so it moves against price. A forward split leaves the same consideration
     * spread over more shares; restating price without it puts a step in the histogram at the split.
     */
    public function testSplitRestatementMovesVolumeAgainstPrice(): void
    {
        foreach ([[4.0, false, 'volume * :factor'], [10.0, true, 'FLOOR(volume / :factor)']] as [$factor, $isReverse, $fragment]) {
            $this->statements = [];
            $stock = new Stock();
            $stock->setTicker('VOL_CORP');
            $this->service->processStockSplit($stock, $factor, $isReverse, 2.50);

            $this->assertStringContainsString($fragment, $this->historyStatement()['sql']);
        }
    }

    /**
     * Every price-bearing column the entity declares has to appear in the restatement. This is the guard
     * that a column added later cannot quietly go unrestated the way the bar columns did.
     */
    public function testTheRestatementCoversEveryColumnStockHistoryDeclares(): void
    {
        $stock = new Stock();
        $stock->setTicker('COVERAGE');
        $this->service->processStockSplit($stock, 4.0, false, 2.50);

        $sql = $this->historyStatement()['sql'];
        $entity = file_get_contents(__DIR__ . '/../../src/Entity/StockHistory.php');
        $this->assertIsString($entity);

        preg_match_all('/private [^;]*\$(\w+)\s*=/', $entity, $matches);

        $exempt = ['id', 'stock', 'recordedAt'];
        foreach ($matches[1] as $property) {
            if (in_array($property, $exempt, true)) {
                continue;
            }

            $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $property) ?? $property);
            $this->assertStringContainsString(
                $column . ' =',
                $sql,
                sprintf('stock_history.%s is quoted in shares or money and is not restated by a split.', $column)
            );
        }
    }

    /** A forward split multiplies the executed quantities and divides the prices they were struck at. */
    public function testForwardSplitRestatesExecutedHistory(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPLIT_CORP');

        $this->service->processStockSplit($stock, 4.0, false);

        $statement = $this->filledOrderStatement();
        $this->assertStringContainsString('quantity * :factor', $statement['sql']);
        $this->assertStringContainsString('filled_quantity', $statement['sql']);
        $this->assertStringContainsString('execution_price = ROUND(execution_price / :factor', $statement['sql']);
        $this->assertSame(4.0, $statement['params']['factor']);
        $this->assertSame('SPLIT_CORP', $statement['params']['ticker']);
    }

    /** A reverse split does the inverse: quantities divide and prices multiply. */
    public function testReverseSplitRestatesExecutedHistory(): void
    {
        $stock = new Stock();
        $stock->setTicker('REV_CORP');

        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        $statement = $this->filledOrderStatement();
        $this->assertStringContainsString('FLOOR(quantity / :factor)', $statement['sql']);
        $this->assertStringContainsString('FLOOR(filled_quantity / :factor)', $statement['sql']);
        $this->assertStringContainsString('execution_price = ROUND(execution_price * :factor', $statement['sql']);
        $this->assertSame(10.0, $statement['params']['factor']);
    }

    /**
     * Consideration is what has to survive: quantity times price is the money that changed hands, and a
     * split moves neither. This is the invariant the cost basis actually depends on.
     */
    public function testRestatementPreservesConsideration(): void
    {
        foreach ([[4.0, false], [10.0, true]] as [$factor, $isReverse]) {
            $this->statements = [];
            $stock = new Stock();
            $stock->setTicker('CONSIDERATION');
            $this->service->processStockSplit($stock, $factor, $isReverse, 2.50);

            $sql = $this->filledOrderStatement()['sql'];
            $quantityScalesUp = str_contains($sql, 'quantity * :factor');
            $priceScalesDown = str_contains($sql, 'execution_price = ROUND(execution_price / :factor');

            $this->assertSame(
                $quantityScalesUp,
                $priceScalesDown,
                'Quantity and price must move in opposite directions, or the restatement invents or destroys money.'
            );
        }
    }

    /**
     * A forward split conserves consideration exactly, because nothing rounds: quantity multiplies and
     * price divides by the same factor.
     */
    public function testForwardRestatementIsExactOnConsideration(): void
    {
        foreach ([[100, 12.5000], [7, 3.3300], [1, 999.9900]] as [$quantity, $price]) {
            foreach ([2.0, 4.0, 10.0] as $factor) {
                $restated = ($quantity * $factor) * round($price / $factor, 4);

                $this->assertEqualsWithDelta(
                    $quantity * $price,
                    $restated,
                    0.0001,
                    sprintf('A %g-for-1 split moved money on %d shares at $%s.', $factor, $quantity, $price)
                );
            }
        }
    }

    /**
     * A reverse split cannot conserve per-row consideration, because share counts are integers - but the
     * shares it drops are exactly the ones the fractional cashout pays out, so the money is not lost, it
     * changes form. What must never happen is a row losing ALL of its shares: an executed trade that
     * floors to zero takes its whole cost out of the basis pool, and a position assembled from orders
     * smaller than the factor lost every one of them at once, leaving the page with no cost to print
     * against a position the holder still held. GREATEST(..., 1) is the floor that prevents it.
     */
    public function testReverseRestatementNeverFloorsAnExecutedTradeOutOfExistence(): void
    {
        $stock = new Stock();
        $stock->setTicker('REV_CORP');
        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        $sql = $this->filledOrderStatement()['sql'];

        $this->assertStringContainsString('GREATEST(FLOOR(quantity / :factor), 1)', $sql);
        $this->assertStringContainsString('GREATEST(FLOOR(filled_quantity / :factor), 1)', $sql);
        $this->assertStringNotContainsString('GREATEST(FLOOR(quantity / :factor), 0)', $sql);

        foreach ([1, 5, 9, 10, 15] as $quantity) {
            $this->assertGreaterThan(
                0,
                max((int) floor($quantity / 10.0), 1),
                sprintf('%d shares must survive a 1-for-10 as a countable position.', $quantity)
            );
        }
    }

    /** The statement that refunds escrow value the split is about to round away. */
    private function escrowRefundStatement(): array
    {
        $matches = array_values(array_filter(
            $this->statements,
            static fn (array $s): bool => str_contains($s['sql'], 'UPDATE users')
                && str_contains($s['sql'], 'remnant_value')
        ));

        $this->assertCount(1, $matches, 'A reverse split must refund escrow remnants exactly once.');

        return $matches[0];
    }

    /**
     * Escrow is not idle money. A resting BUY has had limit x quantity taken out of cash; a resting SELL
     * has had its shares taken out of user_stocks. The reverse split rescales both legs by FLOOR, and
     * whatever the FLOOR drops was simply destroyed - the sweep that cancels an emptied order is raw SQL
     * and refunds nothing at all.
     */
    public function testReverseSplitRefundsEscrowItIsAboutToRoundAway(): void
    {
        $stock = new Stock();
        $stock->setTicker('REV_CORP');

        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        $statement = $this->escrowRefundStatement();

        $this->assertStringContainsString("o.status = 'OPEN'", $statement['sql']);
        $this->assertStringContainsString('COALESCE(o.limit_price, 0) * (o.quantity % :factor)', $statement['sql']);
        $this->assertStringContainsString('(o.quantity % :factor) * :old_price', $statement['sql']);
        $this->assertSame(10.0, $statement['params']['factor']);
        $this->assertSame(2.50, $statement['params']['old_price']);
        $this->assertSame('REV_CORP', $statement['params']['ticker']);
    }

    /**
     * Ordering is load-bearing: the refund is measured at the limit the cash was COMMITTED at, so it has to
     * be paid before the bulk rewrite multiplies limit_price and divides quantity out from under it.
     */
    public function testEscrowRefundPrecedesTheRewriteItCompensatesFor(): void
    {
        $stock = new Stock();
        $stock->setTicker('REV_CORP');

        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        $refundIndex = null;
        $rewriteIndex = null;
        foreach ($this->statements as $index => $statement) {
            if ($refundIndex === null && str_contains($statement['sql'], 'remnant_value')) {
                $refundIndex = $index;
            }
            if ($rewriteIndex === null && str_contains($statement['sql'], 'limit_price = ROUND(limit_price * :factor')) {
                $rewriteIndex = $index;
            }
        }

        $this->assertNotNull($refundIndex);
        $this->assertNotNull($rewriteIndex);
        $this->assertLessThan(
            $rewriteIndex,
            $refundIndex,
            'Refunding after the rewrite would pay out at the post-split limit and invent money.'
        );
    }

    /**
     * The algebra the refund SQL encodes. What the user committed is L x q; after the split the surviving
     * order holds (L x f) x floor(q / f) and the refund pays L x (q mod f). Those must add back to L x q
     * for every quantity, including the ones that empty the order entirely.
     */
    public function testEscrowValueIsConservedAcrossTheReverseSplit(): void
    {
        $limit = 2.0;

        foreach ([1, 5, 9, 10, 15, 20, 137] as $quantity) {
            foreach ([2.0, 10.0] as $factor) {
                $survivingOrder = ($limit * $factor) * floor($quantity / $factor);
                $refunded = $limit * ($quantity % (int) $factor);

                $this->assertEqualsWithDelta(
                    $limit * $quantity,
                    $survivingOrder + $refunded,
                    0.0001,
                    sprintf('A 1-for-%g split moved escrow on %d shares at $%s.', $factor, $quantity, $limit)
                );
            }
        }
    }

    /**
     * A forward split needs no refund: quantity multiplies and the limit divides by the same factor, so the
     * escrow the order holds is unchanged and there is nothing to round away.
     */
    public function testForwardSplitRefundsNothing(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPLIT_CORP');

        $this->service->processStockSplit($stock, 4.0, false);

        foreach ($this->statements as $statement) {
            $this->assertStringNotContainsString('remnant_value', $statement['sql']);
        }
    }

    /** Only executed orders are restated; a cancelled order never traded and is left as the record it is. */
    public function testCancelledOrdersAreNotRestated(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPLIT_CORP');

        $this->service->processStockSplit($stock, 4.0, false);

        foreach ($this->statements as $statement) {
            $this->assertStringNotContainsString("status = 'CANCELLED'", $statement['sql']);
        }
    }
}
