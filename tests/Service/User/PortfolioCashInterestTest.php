<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Service\User\Portfolio;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Idle brokerage cash is swept into overnight money market instruments, so it earns the policy rate net of
 * the intermediary's spread. Without this the opportunity cost of sitting in cash was zero: an inverted
 * curve paying five percent on the sidelines looked identical to a zero-rate boom.
 */
#[AllowMockObjectsWithoutExpectations]
final class PortfolioCashInterestTest extends TestCase
{
    private Connection&MockObject $connection;
    private Portfolio $portfolio;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($this->connection);

        $this->portfolio = new Portfolio($entityManager);
    }

    public function testIdleCashCompoundsAtTheSweepRateOverTheElapsedPeriod(): void
    {
        $annualRate = 0.05;
        $dt = 1.0 / 52.0; // One simulated week.

        $capturedRate = null;
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedRate): int {
                $this->assertStringContainsString('cash_balance', $sql);
                $capturedRate = $params['rate'];

                return 1;
            });

        $this->portfolio->accrueCashInterest($annualRate, $dt);

        // Compounded continuously, matching the rest of the engine's time stepping.
        $this->assertEqualsWithDelta(exp($annualRate * $dt) - 1.0, $capturedRate, 1e-12);

        // A week at five percent is a little under ten basis points, not five percent.
        $this->assertGreaterThan(0.0009, $capturedRate);
        $this->assertLessThan(0.0011, $capturedRate);
    }

    public function testAccrualCompoundsToTheAnnualRateOverAFullYear(): void
    {
        $capturedRate = null;
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedRate): int {
                $capturedRate = $params['rate'];

                return 1;
            });

        // Fifty two weekly accruals must compound to the annual rate, so the cadence of the accrual does not
        // change how much a player earns over a year.
        $weekly = null;
        $this->portfolio->accrueCashInterest(0.05, 1.0 / 52.0);
        $weekly = $capturedRate;

        $this->assertEqualsWithDelta(exp(0.05) - 1.0, ((1.0 + $weekly) ** 52) - 1.0, 1e-9);
    }

    public function testNoInterestAccruesAtTheZeroLowerBound(): void
    {
        $this->connection->expects($this->never())->method('executeStatement');

        $this->portfolio->accrueCashInterest(0.0, 1.0 / 52.0);
        $this->portfolio->accrueCashInterest(-0.01, 1.0 / 52.0);
        $this->portfolio->accrueCashInterest(0.05, 0.0);
    }
}
