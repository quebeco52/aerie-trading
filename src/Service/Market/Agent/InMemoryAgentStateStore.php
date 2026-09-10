<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

/**
 * Process-local agent state, for tests and headless harnesses where no Redis is running.
 */
final class InMemoryAgentStateStore implements AgentStateStoreInterface
{
    /** @var array<string, array{positions: array<string, float>, fitness: array<string, float>, last_price: float}> */
    private array $state = [];

    public function read(string $ticker): ?array
    {
        return $this->state[$ticker] ?? null;
    }

    public function write(string $ticker, array $state): void
    {
        $this->state[$ticker] = $state;
    }
}
