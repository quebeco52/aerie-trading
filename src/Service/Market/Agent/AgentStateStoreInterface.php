<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

/**
 * Where each name's agent book lives between ticks: every strategy's position, its accumulated fitness,
 * and the price it last acted on.
 *
 * Kept out of the Stock entity on purpose. This is written for every name on every tick and read back
 * immediately, which is a cache access rather than a durable fact about a company, and putting seven
 * floats per strategy on the row would churn the table for state nobody queries.
 */
interface AgentStateStoreInterface
{
    /**
     * @return array{positions: array<string, float>, fitness: array<string, float>, last_price: float}|null
     *         Null when this name has no book yet.
     */
    public function read(string $ticker): ?array;

    /**
     * @param array{positions: array<string, float>, fitness: array<string, float>, last_price: float} $state
     */
    public function write(string $ticker, array $state): void;
}
