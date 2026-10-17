<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Trading;

use App\Entity\Stock;
use App\Service\Market\Option\OptionMarginCalculator;
use App\Service\Market\Trading\ForcedLiquidationService;
use App\Service\Market\Trading\MarginEngine;
use App\Service\Market\Trading\SecuritiesLendingDesk;
use App\Service\Market\Trading\TradeExecutionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ForcedLiquidationServiceTest extends TestCase
{
    /**
     * A called account short nothing but stock has its short bought back. The margin call used to sell longs alone,
     * so an account like this one was called every sweep and closed out never, free to run on to any negative equity.
     * Short 200 at $100 with $21,000 cash: equity $1,000 against a $6,000 requirement, a $6,000 shortfall with the
     * buffer, cleared by covering $6,000 / (0.30 + 0.05) of stock, 172 shares.
     */
    public function testAShortOnlyAccountIsCalledByBuyingItsShortBack(): void
    {
        $user = (new \App\Entity\User())->setCashBalance('21000.00')->setMarginDebit('0.00');
        (new \ReflectionProperty(\App\Entity\User::class, 'id'))->setValue($user, 5);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            static fn(string $sql): array|false => str_contains($sql, 'long_value') ? ['long_value' => 0.0, 'short_value' => 20000.0] : false,
        );
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn(string $sql): array => str_contains($sql, 'UNION ALL')
                ? [['ticker' => 'APEX', 'quantity' => -200, 'unit_value' => 100.0, 'side' => 'short']]
                : [],
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $orders = [];
        $execution = $this->createStub(TradeExecutionService::class);
        $execution->method('executeOrder')->willReturnCallback(static function (\App\Entity\User $u, string $ticker, string $action, string $type, int $quantity) use (&$orders): string {
            $orders[] = [$ticker, $action, $quantity];

            return 'STOCK';
        });

        $service = new ForcedLiquidationService($em, new MarginEngine($em, new OptionMarginCalculator($em)), new SecuritiesLendingDesk(), $execution, new NullLogger());
        (new \ReflectionMethod($service, 'processMarginCall'))->invoke($service, $user);

        $this->assertSame([['APEX', 'COVER', 172]], $orders);
    }

    /**
     * Before the loan is charged, the sweep nets each account a bulk move left with cash below zero or beside its loan,
     * locked and read fresh as a trade on it is: a borrow fee had driven this one $150 below zero, where the loan rate
     * never reached it.
     */
    public function testTheSweepNetsCashAgainstTheLoanOnAFreshLockedAccount(): void
    {
        $user = (new \App\Entity\User())->setCashBalance('-150.0000')->setMarginDebit('100.00');
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([5]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('find')->willReturn($user);
        $em->expects($this->once())->method('lock')->with($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $em->expects($this->once())->method('refresh')->with($user);
        $em->expects($this->once())->method('flush');

        $service = new ForcedLiquidationService($em, new MarginEngine($em, new OptionMarginCalculator($em)), new SecuritiesLendingDesk(), $this->createStub(TradeExecutionService::class), new NullLogger());
        (new \ReflectionMethod($service, 'netCashAgainstLoans'))->invoke($service);

        $this->assertSame('0.0000', (string) $user->getCashBalance());
        $this->assertSame('250.00', (string) $user->getMarginDebit());
    }

    /**
     * The sweep puts each name's short interest back to what its accounts are short, in the database and on the copy
     * of the stock the process holds: the web process and the ticker each moved their own copy, so the running total
     * drifted between them.
     */
    public function testTheSweepRestatesShortInterestFromThePositions(): void
    {
        $stock = (new Stock())->setTicker('APEX')->setShortInterestShares('900.00');

        $statements = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn([7 => '250']);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 1;
        });

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturnCallback(static fn(int|string $id): ?Stock => (int) $id === 7 ? $stock : null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($repository);

        $service = new ForcedLiquidationService(
            $em,
            new MarginEngine($em, new OptionMarginCalculator($em)),
            new SecuritiesLendingDesk(),
            $this->createStub(TradeExecutionService::class),
            new NullLogger(),
        );
        $service->restateShortInterest();

        $this->assertSame('250.00', $stock->getShortInterestShares());
        $this->assertCount(1, array_filter($statements, static fn(string $sql): bool => str_contains($sql, 'SET s.short_interest_shares')));
    }
}
