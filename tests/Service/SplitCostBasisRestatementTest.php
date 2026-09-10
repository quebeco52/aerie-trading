<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Stock;
use App\Service\Corporate\CorporateLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
