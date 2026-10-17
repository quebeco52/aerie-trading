<?php

declare(strict_types=1);

namespace App\Service\Market\Option;

/**
 * The desk's option-book exposure per name, the price it was measured at, and the shares the desk holds against it.
 *
 * Lives outside the entity for the same reason order flow does: it is per-tick market state rather than a
 * property of the company, the web process reads it while the ticker writes it, and the exposure can be rebuilt
 * from the chain at any time, so persisting it in the stock table would be migration debt for a cache.
 *
 * Because it is a cache, an outage in it must never be able to stop a tick. Implementations swallow their
 * own transport failures and report nothing, exactly as the order flow store does.
 *
 * An entry: `gamma`, shares of delta the PUBLIC gains per 1.00 move (the desk is short this); `delta`, the public's
 * delta in shares at `reference_price`; `held`, the shares the desk holds as its hedge.
 */
interface DealerGammaStoreInterface
{
    /**
     * Records one name's entry, every field together.
     *
     * @param array{gamma: float, delta: float, reference_price: float, held: float} $entry
     */
    public function record(string $ticker, array $entry): void;

    /**
     * The stored entry for one name, or null if its chain has never been walked or the store is down.
     *
     * @return array{gamma: float, delta: float, reference_price: float, held: float}|null
     */
    public function read(string $ticker): ?array;

    /**
     * Every name's entry in one read.
     *
     * The hedging pass needs all of them at once and there is no cheaper way to ask for that than asking
     * once: a read per name turned one pass into a hundred round trips against a store that holds the whole
     * market in a single hash.
     *
     * @return array<string, array{gamma: float, delta: float, reference_price: float, held: float}>
     */
    public function readAll(): array;

    /**
     * Records many names' entries in one write, for the same reason.
     *
     * @param array<string, array{gamma: float, delta: float, reference_price: float, held: float}> $entries
     */
    public function recordAll(array $entries): void;
}
