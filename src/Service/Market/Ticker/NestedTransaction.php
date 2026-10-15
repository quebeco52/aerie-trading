<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Unwinding a transaction opened inside the tick's, after an error. InnoDB answers a deadlock by rolling the WHOLE
 * transaction back, savepoints included, so the nested level's rollback to its savepoint then fails (1305 SAVEPOINT
 * does not exist), and DBAL, which counts the level down only after the rollback succeeds, still believes it is two
 * levels deep. Left alone, that failure replaces the deadlock as the error thrown, and the tick's own rollback fails
 * the same way inside its catch block, taking the ticker down over what should have cost one tick.
 */
final class NestedTransaction
{
    /**
     * Rolls back a nested transaction and rethrows the error that caused the rollback, never the rollback's own
     * failure: with the savepoint already gone the server has rolled everything back, and the caller's unwindAll()
     * clears the levels DBAL still counts.
     */
    public static function rollBackAndRethrow(Connection $connection, \Throwable $cause): never
    {
        try {
            $connection->rollBack();
        } catch (DbalException) {
            // The savepoint went with the whole transaction; the cause is the news.
        }

        throw $cause;
    }

    /**
     * Rolls back every level still open. A level whose savepoint is gone means the server has already rolled the whole
     * transaction back, so the connection is closed, which DBAL takes as nothing open, and opened again on the next
     * query.
     */
    public static function unwindAll(Connection $connection): void
    {
        while ($connection->isTransactionActive()) {
            try {
                $connection->rollBack();
            } catch (DbalException) {
                $connection->close();

                return;
            }
        }
    }
}
