<?php

declare(strict_types=1);

namespace App\Service\Market;

/**
 * Rate-limits the ticker's limit-order checks per instrument.
 *
 * The ticker dispatches a ProcessLimitOrdersMessage when an instrument's bounds key is missing from Redis
 * or its price has crossed a resting order, and the handler on the worker rewrites the key when it is done.
 * Between the dispatch and that rewrite the condition still holds, so without a gate every tick in the
 * window dispatched again: a newly issued bond produced two dozen identical messages, and an order the
 * handler could not fill (a COVER against no short, say) kept the queue busy at the tick rate for as long
 * as the price stayed through it. One dispatch per instrument per retry window is what the handler needs.
 */
final class LimitOrderDispatchGate
{
    // --- Cadence ---
    /** Ticks after a dispatch before the same instrument may be dispatched again while its condition still holds. Long enough for the worker to answer, short enough that a lost message is retried within seconds at any tick rate in use. */
    public const RETRY_TICKS = 100;

    /** @var array<string, int> Tick of the last dispatch, per instrument still waiting for the handler. */
    private array $lastDispatch = [];

    /** Whether to dispatch for this instrument now; records the dispatch when it says yes. */
    public function allow(string $ticker, int $tick): bool
    {
        $last = $this->lastDispatch[$ticker] ?? null;
        if ($last !== null && $tick - $last < self::RETRY_TICKS) {
            return false;
        }

        $this->lastDispatch[$ticker] = $tick;

        return true;
    }

    /** The handler has answered (bounds present and not crossed): the next crossing dispatches at once. */
    public function settle(string $ticker): void
    {
        unset($this->lastDispatch[$ticker]);
    }

    /** Instruments still waiting on the handler. */
    public function pending(): int
    {
        return count($this->lastDispatch);
    }
}
