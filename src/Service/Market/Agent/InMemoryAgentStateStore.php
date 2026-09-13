<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

/**
 * Process-local agent state, for tests and headless harnesses where no Redis is running.
 */
final class InMemoryAgentStateStore implements AgentStateStoreInterface
{
    /** @var array<string, array{positions: array<string, float>, fitness: array<string, float>}> */
    private array $state = [];

    /** @var array<string, float> */
    private array $style = [];

    public function read(string $ticker): ?array
    {
        return $this->state[$ticker] ?? null;
    }

    public function write(string $ticker, array $state): void
    {
        $this->state[$ticker] = $state;
    }

    /** Nothing to bulk-load: the array is already local. */
    public function beginBatch(): void
    {
    }

    /** Nothing to bulk-send: writes landed as they were made. */
    public function commitBatch(): void
    {
    }

    public function readStyle(): array
    {
        return $this->style;
    }

    public function writeStyle(array $fitness): void
    {
        $this->style = $fitness;
    }
}
