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

    public function testProcessDividendPaymentExecutesCombinedHoldingsQuery(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $this->connectionMock->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(function (string $sql) {
                    return str_contains($sql, 'UPDATE users u')
                        && str_contains($sql, 'user_stocks WHERE stock_id = :stock_id')
                        && str_contains($sql, "trade_orders WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'");
                }),
                $this->callback(function (array $params) {
                    return $params['dividend'] === 1.25
                        && $params['ticker'] === 'DIV_CORP';
                })
            );

        $this->service->processDividendPayment($stock, 1.25);
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
