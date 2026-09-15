<?php

declare(strict_types=1);

namespace App\Service\Market\Gamma;

/**
 * The desk's option-book exposure per name, and the price it last hedged at.
 *
 * Lives outside the entity for the same reason order flow does: it is per-tick market state rather than a
 * property of the company, the web process reads it while the ticker writes it, and it can be rebuilt from
 * the chain at any time, so persisting it in the stock table would be migration debt for a cache.
 *
 * Because it is a cache, an outage in it must never be able to stop a tick. Implementations swallow their
 * own transport failures and report nothing, exactly as the order flow store does.
 */
interface DealerGammaStoreInterface
{
    /**
     * Records the desk's exposure and the price it applies as of.
     *
     * Both fields are written together on every call, including when only the price is moving on, so that
     * advancing the hedge mark never needs a read to preserve the gamma beside it.
     *
     * @param string $ticker        The underlying.
     * @param float  $customerGamma Shares of delta the PUBLIC gains per 1.00 move in the underlying; the
     *                              desk is short this, so it is also what the desk must buy per 1.00 move.
     * @param float  $referencePrice The underlying price the exposure is marked against.
     */
    public function record(string $ticker, float $customerGamma, float $referencePrice): void;

    /**
     * The stored exposure for one name, or null if its chain has never been walked or the store is down.
     *
     * @return array{gamma: float, reference_price: float}|null
     */
    public function read(string $ticker): ?array;
}
