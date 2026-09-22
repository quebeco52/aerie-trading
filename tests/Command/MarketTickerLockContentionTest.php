<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MarketTickerCommand;
use PHPUnit\Framework\TestCase;

/**
 * The two rules that keep the ticker off MySQL error 1213, guarded on the source because the loop they
 * live in needs a database and a worker to run at all.
 *
 * A `ProcessLimitOrdersMessage` sent inside the tick transaction reaches the Redis-backed worker at once,
 * which then takes the same rows in the opposite lock order and deadlocks. And a deadlock is transient, so
 * the tick is simply lost -- the generic handler's `sleep(5)` is 500 frozen ticks for a fault already over.
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
