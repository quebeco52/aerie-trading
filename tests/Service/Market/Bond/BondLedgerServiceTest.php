<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Bond;

use App\Entity\Bond;
use App\Service\Market\Bond\BondLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class BondLedgerServiceTest extends TestCase
{
    /**
     * A closing issue refunds the cash behind every open bid before cancelling them. A multi-table UPDATE joined to
     * the raw orders changes each users row once, however many orders match, so an account with two bids on the
     * issue got one refunded and lost the other with the cancelled row. The escrow is summed per account first.
     */
    public function testClosingAnIssueRefundsEveryOpenBidOfAnAccountBeforeCancellingThem(): void
    {
        $statements = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 1;
        });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $bond = new Bond();
        $bond->setTicker('AT-2030');
        $bond->setFaceValue('1000.00');

        (new BondLedgerService($entityManager))->processRedemption($bond, 20.0, new \DateTimeImmutable('2030-01-01'));

        $refund = array_key_first(array_filter($statements, static fn(string $sql): bool => str_contains($sql, "o.action = 'BUY'")));
        $cancel = array_key_first(array_filter($statements, static fn(string $sql): bool => str_contains($sql, "SET status = 'CANCELLED'")));

        $this->assertNotNull($refund);
        $this->assertNotNull($cancel);
        $this->assertLessThan($cancel, $refund, 'The escrow is refunded before the orders holding it are cancelled.');
        $this->assertMatchesRegularExpression('/INNER JOIN \(\s*SELECT o\.user_id, SUM\(/', $statements[$refund]);
        $this->assertStringContainsString('GROUP BY o.user_id', $statements[$refund]);
    }
}
