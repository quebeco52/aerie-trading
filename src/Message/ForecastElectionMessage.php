<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the forecaster for the election forecast a poll called for, worked out on the politics as the ticker left them
 * that tick. Routed to a transport of its own, so a forecast never holds up the tick or the limit orders on the async
 * worker.
 */
final readonly class ForecastElectionMessage
{
    public function __construct(
        /** @var array<string, mixed> The politics as PoliticsState::toArray() keeps them. */
        public array $state,
    ) {}
}
