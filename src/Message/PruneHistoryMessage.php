<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the worker to apply the retention policy.
 *
 * A message of its own rather than Symfony's RunCommandMessage: that class has no routing entry, so
 * dispatching one from the ticker would run the whole prune synchronously inside the tick loop — the
 * opposite of the point. This one is routed to the async transport beside ProcessLimitOrdersMessage.
 */
final readonly class PruneHistoryMessage
{
    public function __construct(
        /** Simulated years of full-resolution history to keep. */
        public float $years,
        /** Rows kept out of every N when a history table is downsampled. */
        public int $ratio,
    ) {}
}
