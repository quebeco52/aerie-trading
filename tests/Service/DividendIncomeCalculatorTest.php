<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\User\DividendIncomeCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DividendIncomeCalculatorTest extends TestCase
{
    private EntityManagerInterface&Stub $entityManagerMock;
    private Connection&MockObject $connectionMock;
    private DividendIncomeCalculator $calculator;
    private User&Stub $user;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createStub(EntityManagerInterface::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->entityManagerMock->method('getConnection')->willReturn($this->connectionMock);

        $this->user = $this->createStub(User::class);
        $this->user->method('getId')->willReturn(7);

        $this->calculator = new DividendIncomeCalculator($this->entityManagerMock);
    }

    public function testTotalsByTickerKeysCashReceivedByTicker(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains('SUM(amount)'),
                ['user_id' => 7]
            )
            ->willReturn([
                ['ticker' => 'AERO', 'total' => '412.50'],
                ['ticker' => 'BANC', 'total' => '87.25'],
            ]);

        $totals = $this->calculator->totalsByTicker($this->user);

        $this->assertSame(['AERO' => 412.50, 'BANC' => 87.25], $totals);
    }

    /**
     * Income from a ticker the user has since sold out of still belongs in the total: the cash was
     * received. A caller summing this map is therefore summing lifetime income, not income on open
     * positions, which is why the dashboard keys it by ticker rather than folding it into a position row.
     */
    public function testTotalsByTickerRetainsIncomeFromClosedPositions(): void
    {
        $this->connectionMock->method('fetchAllAssociative')->willReturn([
            ['ticker' => 'GONE', 'total' => '55.00'],
        ]);

        $totals = $this->calculator->totalsByTicker($this->user);

        $this->assertSame(55.00, array_sum($totals));
    }

    public function testTotalsByTickerIsEmptyWhenNothingHasEverPaid(): void
    {
        $this->connectionMock->method('fetchAllAssociative')->willReturn([]);

        $this->assertSame([], $this->calculator->totalsByTicker($this->user));
        // array_sum([]) is int 0, which is what the dashboard's lifetime total collapses to before any
        // distribution has landed. number_format handles it, so the empty case needs no special casing.
        $this->assertEquals(0, array_sum($this->calculator->totalsByTicker($this->user)));
    }

    public function testRecentPaymentsReturnsNewestFirstWithTypedFields(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('ORDER BY paid_at DESC'),
                    $this->stringContains('LIMIT 25')
                ),
                ['user_id' => 7]
            )
            ->willReturn([
                [
                    'ticker' => 'AERO',
                    'asset_type' => 'STOCK',
                    'shares_held' => '120',
                    'dividend_per_share' => '0.4500',
                    'amount' => '54.00',
                    'paid_at' => '2026-09-10 14:30:00',
                ],
            ]);

        $payments = $this->calculator->recentPayments($this->user);

        $this->assertCount(1, $payments);
        $this->assertSame('AERO', $payments[0]['ticker']);
        $this->assertSame('STOCK', $payments[0]['assetType']);
        $this->assertSame(120, $payments[0]['sharesHeld']);
        $this->assertSame(0.45, $payments[0]['dividendPerShare']);
        $this->assertSame(54.00, $payments[0]['amount']);
        $this->assertSame('2026-09-10 14:30:00', $payments[0]['paidAt']);
    }

    public function testRecentPaymentsFloorsNonPositiveLimitRatherThanEmittingInvalidSql(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('LIMIT 1'), ['user_id' => 7])
            ->willReturn([]);

        $this->assertSame([], $this->calculator->recentPayments($this->user, 0));
    }
}
