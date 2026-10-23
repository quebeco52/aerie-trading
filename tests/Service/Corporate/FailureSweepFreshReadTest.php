<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Entity\Stock;
use App\Entity\User;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\FailureSweep;
use App\Service\News\MarketEventPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Cancelling a failed firm's equity moves cash on accounts the ticker has held for up to a day: refunds of resting
 * buys and the cash settlement of options on the name. Each account is read as the database holds it before any of
 * that moves, once, ahead of both, since the option settlement runs while the refunds are still waiting to be written.
 */
final class FailureSweepFreshReadTest extends TestCase
{
    public function testEveryAccountTheCancellationPaysIsReadFreshFirst(): void
    {
        $users = [7 => new User(), 9 => new User()];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([7, 9]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('find')->willReturnCallback(static fn(string $class, int|string $id): ?User => $users[(int) $id] ?? null);
        $refreshed = [];
        $em->expects($this->exactly(2))->method('refresh')->willReturnCallback(static function (object $user) use (&$refreshed): void {
            $refreshed[] = $user;
        });

        $sweep = new FailureSweep($em, new NullLogger(), $this->createStub(MarketEventPublisher::class), $this->createStub(DebtEngine::class));
        (new \ReflectionMethod($sweep, 'readAccountsFresh'))->invoke($sweep, (new Stock())->setTicker('GONE'));

        $this->assertSame(array_values($users), $refreshed);
    }

    /** Both ways a firm's equity is cancelled read the accounts first. */
    public function testBothCancellationsReadTheAccountsBeforeAnyCashMoves(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Service/Corporate/FailureSweep.php');

        $this->assertSame(2, substr_count($source, '$this->cancelEquity($stock)'));
        $this->assertSame(2, preg_match_all('/\$this->readAccountsFresh\(\$stock\);\s*\n\s*\$holders = \$this->cancelEquity\(\$stock\);/', $source));
    }
}
