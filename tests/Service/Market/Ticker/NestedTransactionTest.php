<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Ticker;

use App\Service\Market\Ticker\NestedTransaction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\TestCase;

/**
 * A deadlock inside a nested transaction rolls the whole transaction back on the server: the savepoint is gone, and
 * the unwind must neither hide the deadlock behind the failed rollback nor fail again in the tick's catch block.
 */
class NestedTransactionTest extends TestCase
{
    /** The rollback to a vanished savepoint fails; the deadlock that caused it is still what is thrown. */
    public function testTheCauseIsRethrownWhenTheSavepointIsGone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('rollBack')->willThrowException(NoActiveTransaction::new());
        $cause = $this->createStub(DeadlockException::class);

        $thrown = null;
        try {
            NestedTransaction::rollBackAndRethrow($connection, $cause);
        } catch (\Throwable $caught) {
            $thrown = $caught;
        }
        $this->assertSame($cause, $thrown);
    }

    /** With the savepoint gone, the unwind closes the connection rather than fail: DBAL then counts nothing open. */
    public function testUnwindClosesTheConnectionWhenALevelCannotBeRolledBack(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('rollBack')->willThrowException(NoActiveTransaction::new());
        $connection->expects($this->once())->method('close');

        NestedTransaction::unwindAll($connection);
    }

    /** Levels that can be rolled back are, one by one, and the connection is left open. */
    public function testUnwindRollsBackEveryOpenLevel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturnOnConsecutiveCalls(true, true, false);
        $connection->expects($this->exactly(2))->method('rollBack');
        $connection->expects($this->never())->method('close');

        NestedTransaction::unwindAll($connection);
    }
}
