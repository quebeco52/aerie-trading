<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MarketTickerCommand;
use PHPUnit\Framework\TestCase;

/**
 * The two rules that keep the ticker off MySQL error 1213, guarded on the source because the loop they
 * live in needs a database and a worker to run at all.
 *
 * The ticker holds one transaction per tick. A `ProcessLimitOrdersMessage` sent from inside it reaches the
 * worker immediately -- the transport is Redis, which owes nothing to a database commit -- so the worker
 * starts taking rows the tick still holds, and it takes them in the opposite order: its user row first, by
 * TradeExecutionService's pessimistic lock, then the stock. That is a lock cycle, and InnoDB answers it by
 * shooting one of the two. Routing the message to the worker moved the work out of the transaction; it did
 * not stop it running concurrently with it, which is the part that deadlocks.
 *
 * The second rule is about what the failure costs. A deadlock is transient and consistent by construction,
 * so the tick is simply lost and the next one recomputes from disk. Handing it to the generic handler
 * instead spends `sleep(5)` on it, which at the shipped 10 ms interval is five hundred ticks of frozen
 * market for a fault that was over before the exception was caught.
 */
class MarketTickerLockContentionTest extends TestCase
{
    private function source(): string
    {
        $file = (new \ReflectionClass(MarketTickerCommand::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    /** The span the tick's row locks are held for: from the transaction opening to the commit that ends it. */
    private function insideTheTickTransaction(): string
    {
        $source = $this->source();

        $open = strpos($source, '$this->entityManager->beginTransaction();');
        $close = strpos($source, '$this->entityManager->commit();');

        self::assertIsInt($open, 'The ticker no longer opens a transaction by that name');
        self::assertIsInt($close, 'The ticker no longer commits by that name');
        self::assertLessThan($close, $open, 'The commit must follow the transaction it closes');

        return substr($source, $open, $close - $open);
    }

    public function testNoLimitOrderCheckIsDispatchedWhileTheTickHoldsItsLocks(): void
    {
        self::assertStringNotContainsString(
            'dispatch(new \App\Message\ProcessLimitOrdersMessage',
            $this->insideTheTickTransaction(),
            'A limit-order check dispatched inside the tick transaction hands the worker rows the tick still '
            . 'holds, in the opposite lock order: that is the 1213. Collect them and dispatch after the commit.'
        );
    }

    public function testTheCheckIsStillDispatchedSomewhere(): void
    {
        // Guards the obvious wrong way to satisfy the test above.
        self::assertStringContainsString(
            'dispatch(new \App\Message\ProcessLimitOrdersMessage',
            $this->source(),
            'Resting orders are never looked at if nothing dispatches the check'
        );
    }

    public function testLockContentionIsCaughtBeforeTheGenericHandler(): void
    {
        $source = $this->source();

        $retryable = strpos($source, 'catch (RetryableException');
        $generic = strpos($source, 'catch (\Exception');

        self::assertIsInt($retryable, 'A deadlock is retryable and must have its own catch');
        self::assertIsInt($generic, 'The generic tick handler has gone');
        self::assertLessThan(
            $generic,
            $retryable,
            'PHP takes the first matching catch, so the retryable one has to come first or it never runs'
        );
    }

    public function testLockContentionDoesNotPauseTheTicker(): void
    {
        $source = $this->source();

        $retryable = strpos($source, 'catch (RetryableException');
        $generic = strpos($source, 'catch (\Exception');
        self::assertIsInt($retryable);
        self::assertIsInt($generic);

        self::assertStringNotContainsString(
            'sleep(',
            substr($source, $retryable, $generic - $retryable),
            'At a 10 ms tick a five-second pause is 500 ticks of frozen market for a fault already over'
        );
    }
}
