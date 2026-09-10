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

#[AllowMockObjectsWithoutExpectations]
class CorporateLedgerServiceTest extends TestCase
{
    private EntityManagerInterface&Stub $entityManagerMock;
    private Connection&MockObject $connectionMock;
    private CorporateLedgerService $service;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createStub(EntityManagerInterface::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->entityManagerMock->method('getConnection')->willReturn($this->connectionMock);

        $this->service = new CorporateLedgerService($this->entityManagerMock);
    }

    public function testProcessDividendPaymentWritesLedgerThenCreditsCashFromIt(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');
        $paidAt = new \DateTimeImmutable('2026-09-10 14:30:00');

        $captured = [];
        $this->connectionMock->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        $this->service->processDividendPayment($stock, 1.25, $paidAt);

        [$insert, $update] = $captured;

        // The ledger is written first, from the holdings snapshot.
        $this->assertStringContainsString('INSERT INTO dividend_payment', $insert['sql']);
        $this->assertStringContainsString('user_stocks', $insert['sql']);
        $this->assertStringContainsString("trade_orders", $insert['sql']);
        $this->assertSame(1.25, $insert['params']['dividend']);
        $this->assertSame('DIV_CORP', $insert['params']['ticker']);
        $this->assertSame('2026-09-10 14:30:00', $insert['params']['paid_at']);

        // Cash is then credited FROM those rows, not from a second copy of the subquery. If this join ever
        // becomes an independent recomputation the ledger stops reconciling to the cash it explains.
        $this->assertStringContainsString('UPDATE users u', $update['sql']);
        $this->assertStringContainsString('INNER JOIN dividend_payment d', $update['sql']);
        $this->assertStringContainsString('u.cash_balance + d.amount', $update['sql']);
        $this->assertSame($insert['params']['paid_at'], $update['params']['paid_at']);
        $this->assertSame($insert['params']['ticker'], $update['params']['ticker']);
    }

    /**
     * An open BUY has escrowed cash, not shares. Paying a dividend on it would let anyone park a limit buy
     * far below market and collect income indefinitely on stock they never bought, with the escrow still
     * refundable on cancel. Only the SELL leg belongs in the union, because a SELL has already had its
     * shares removed from user_stocks and would otherwise go unpaid.
     */
    public function testProcessDividendPaymentPaysOpenSellEscrowButNotOpenBuyOrders(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $insertSql = null;
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$insertSql): int {
                if (str_contains($sql, 'INSERT INTO dividend_payment')) {
                    $insertSql = $sql;
                }

                return 1;
            });

        $this->service->processDividendPayment($stock, 1.25, new \DateTimeImmutable());

        $this->assertNotNull($insertSql);
        $this->assertStringContainsString("status = 'OPEN' AND action = 'SELL'", $insertSql);
        $this->assertStringNotContainsString("action = 'BUY'", $insertSql);
    }

    public function testProcessDividendPaymentSkipsHoldingsRoundingToZero(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $insertSql = null;
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$insertSql): int {
                if (str_contains($sql, 'INSERT INTO dividend_payment')) {
                    $insertSql = $sql;
                }

                return 1;
            });

        $this->service->processDividendPayment($stock, 0.0001, new \DateTimeImmutable());

        // WHERE, not HAVING: total_shares is a plain column of the derived table by that point, and HAVING
        // without a GROUP BY in the outer query would collapse every holder into one group.
        $this->assertNotNull($insertSql);
        $this->assertStringContainsString('WHERE ROUND(holdings.total_shares * :dividend, 2) > 0', $insertSql);
        $this->assertStringNotContainsString('HAVING', $insertSql);
    }

    /**
     * The tick transaction in MarketTickerCommand already spans both statements. Opening another one here
     * would only nest, and a nested rollBack would mark the whole tick rollback-only.
     */
    public function testProcessDividendPaymentDoesNotOpenItsOwnTransaction(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $this->connectionMock->expects($this->never())->method('beginTransaction');
        $this->connectionMock->expects($this->never())->method('commit');
        $this->connectionMock->method('executeStatement')->willReturn(1);

        $this->service->processDividendPayment($stock, 1.25, new \DateTimeImmutable());
    }

    public function testProcessForwardStockSplitExecutesUpdatesAndCommits(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPLIT_CORP');

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');
        $this->connectionMock->expects($this->never())->method('rollBack');

        // Forward split executes 5 SQL statements: user_stocks, stock_history, corporate_report, open
        // trade_orders, and the filled trade_orders the cost basis is derived from.
        $this->connectionMock->expects($this->exactly(5))
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE'),
                $this->arrayHasKey('factor')
            );

        $this->service->processStockSplit($stock, 2.0, false);
    }

    public function testProcessReverseStockSplitCashesOutRemnantsAndCommits(): void
    {
        $stock = new Stock();
        $stock->setTicker('REV_CORP');

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');

        // Mock 2 users: user 1 has 15 shares (15 / 10 = 1 share, 5 remnant -> cashout), user 2 has 20 shares (0 remnant)
        $this->connectionMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['id' => 1, 'user_id' => 101, 'quantity' => 15],
                ['id' => 2, 'user_id' => 102, 'quantity' => 20],
            ]);

        // 1 holdings cashout + 9 bulk updates (escrow remnant refund, then the eight rewrites, the last
        // of which restates filled trade_orders) = 10 calls
        $this->connectionMock->expects($this->exactly(10))
            ->method('executeStatement');

        $this->service->processStockSplit($stock, 10.0, true, 2.50);
    }

    public function testProcessStockSplitRollsBackOnException(): void
    {
        $stock = new Stock();
        $stock->setTicker('ERR_CORP');

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->never())->method('commit');
        $this->connectionMock->expects($this->once())->method('rollBack');

        $this->connectionMock->method('executeStatement')
            ->willThrowException(new \RuntimeException('DB Connection Lost'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB Connection Lost');

        $this->service->processStockSplit($stock, 2.0, false);
    }
}
