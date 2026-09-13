<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

/**
 * Where each name's agent book lives between ticks: every participant's position and every belief's
 * accumulated fitness.
 *
 * Kept out of the Stock entity on purpose. This is written for every name on every tick and read back
 * immediately, which is a cache access rather than a durable fact about a company, and putting a
 * handful of floats per strategy on the row would churn the table for state nobody queries.
 *
 * A tick touches every book once, so the store can be told when one starts and ends. Between
 * beginBatch() and commitBatch() a backing store is free to serve reads from a single bulk load and to
 * hold writes back for one bulk send; outside a batch each call stands alone. The ticker is the only
 * writer, which is what makes serving a whole tick from one snapshot correct.
 */
interface AgentStateStoreInterface
{
    /**
     * A book: every participant's position in shares, every competing belief's accumulated fitness and
     * the exposure one of its agents holds, and the realized variance the name's agents have observed.
     * The last two are absent from a book written before they existed and are treated as empty and zero.
     *
     * @return array{positions: array<string, float>, fitness: array<string, float>, exposures?: array<string, float>, variance?: float}|null
     *         Null when this name has no book yet.
     */
    public function read(string $ticker): ?array;

    /**
     * @param array{positions: array<string, float>, fitness: array<string, float>, exposures?: array<string, float>, variance?: float} $state
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

    /**
     * The market-wide fitness of each competing belief: how a style has been paying across every name.
     *
     * One record for the whole market rather than one per name, because it is what lets capital move to a
     * style — every name reads the same score and tilts the same way.
     *
     * @return array<string, float> Empty when the market has no style history yet.
     */
    public function readStyle(): array;

    /**
     * @param array<string, float> $fitness
     */
    public function writeStyle(array $fitness): void;

    /**
     * The market's cross-section: what the average name looked like when the last tick closed.
     *
     * One record for the whole market, like the style, and for the same reason — a strategy whose view is
     * relative (cheaper than the average name, not cheap) needs the same average in every name.
     *
     * @return array<string, float> Empty when the market has no cross-section yet.
     */
    public function readCrossSection(): array;

    /**
     * @param array<string, float> $crossSection
     */
    public function writeCrossSection(array $crossSection): void;
}
