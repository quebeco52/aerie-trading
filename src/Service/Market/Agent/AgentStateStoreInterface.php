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
 *
 * A tick touches every book once, so the store can be told when one starts and ends. Between
 * beginBatch() and commitBatch() a backing store is free to serve reads from a single bulk load and to
 * hold writes back for one bulk send; outside a batch each call stands alone. The ticker is the only
 * writer, which is what makes serving a whole tick from one snapshot correct.
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

    /**
     * Opens a batch: every book is loaded once, and writes are held until commitBatch().
     *
     * Opening a batch while one is open discards whatever the open one was holding, so a tick that
     * aborted half-way leaves nothing behind for the next one to send.
     */
    public function beginBatch(): void;

    /**
     * Sends the writes the batch collected and closes it. Reads and writes stand alone again afterwards.
     */
    public function commitBatch(): void;
}
