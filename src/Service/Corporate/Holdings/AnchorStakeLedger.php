<?php

declare(strict_types=1);

namespace App\Service\Corporate\Holdings;

use App\Data\AnchorHoldings;
use App\Entity\Stock;

/**
 * Marks a permanent-capital sphere's listed anchor stakes at what the market says they are worth.
 *
 * Two cadences, because they are two different real quantities. The BOOKS move quarterly (`markToMarket()`
 * at the report) into equity and NOT retained earnings: an unrealised gain is not distributable, and the
 * earnings line never sees it, so consensus and surprise keep reading operating receipts. The VALUATION
 * moves every tick (`resolveMarkedBookValuePerShare()`) — pricing off the stale filed book would leave the
 * mean reversion pulling towards a NAV that had already changed.
 *
 * Capitalisations are read once per tick, like the order-flow drain, so iteration order cannot matter.
 */
final class AnchorStakeLedger
{
    /** @var array<string, float> ticker => market capitalisation at the start of the tick */
    private array $marketCaps = [];

    /** @param iterable<Stock> $stocks The whole board, priced for the tick about to run. */
    public function beginTick(iterable $stocks): void
    {
        $caps = [];

        foreach ($stocks as $stock) {
            $ticker = $stock->getTicker();

            if ($ticker === '') {
                continue;
            }

            // A delisted holding is worth nothing. Stated rather than inferred from the zeroed price:
            // this is the largest single move a sphere's book can make.
            $caps[$ticker] = $stock->isBankrupt()
                ? 0.0
                : (float) $stock->getPrice() * (float) $stock->getSharesOutstanding();
        }

        $this->marketCaps = $caps;
    }

    /**
     * What the holder's stakes are worth now, or null when any of them is missing from the primed board.
     * Null rather than a partial sum, which would be short by whatever was absent and book that as a loss.
     */
    public function resolveStakeValue(Stock $holder): ?float
    {
        $stakes = AnchorHoldings::forHolder($holder->getTicker());

        if ($stakes === []) {
            return null;
        }

        $value = 0.0;

        foreach ($stakes as $ticker => $ownership) {
            if (!isset($this->marketCaps[$ticker])) {
                return null;
            }

            $value += $this->marketCaps[$ticker] * $ownership;
        }

        return $value;
    }

    /**
     * Books the change in the portfolio and carries the new value. The first mark opens the position and
     * moves nothing — the stakes are already inside the seeded book.
     *
     * An unrealised gain has not been taxed either, and a sphere cannot sell a control block without paying
     * on everything it has appreciated by. So the mark splits: tax accrues as a deferred liability, the rest
     * reaches shareholders. That overhang is one reason the class trades below its assets, and it now grows
     * through a bull market by itself instead of being assumed into the base discount.
     *
     * @return float The change in the portfolio's value, before the tax split.
     */
    public function markToMarket(Stock $holder, float $capitalGainsTaxRate = 0.0): float
    {
        $marketValue = $this->resolveStakeValue($holder);

        if ($marketValue === null) {
            return 0.0;
        }

        $carrying = $holder->getListedStakesCarrying();
        $marked = self::decimal($marketValue);
        $holder->setListedStakesCarrying($marked);

        if ($carrying === null) {
            return 0.0;
        }

        // Differenced at the column's scale: fractions against caps in the hundreds of billions leave a
        // last-bit residue, and marking on that writes a fraction of a cent onto a book that never moved.
        $unrealised = \bcsub($marked, $carrying, 4);

        if ((float) $unrealised === 0.0) {
            return 0.0;
        }

        // Double entry: the asset moved by the whole mark, so liability and equity together must too. The
        // deferred balance floors at zero — a firm can only hand back tax it postponed — and what the floor
        // refuses stays with the shareholders.
        $rate = max(0.0, min(1.0, $capitalGainsTaxRate));
        $openingDeferred = max(0.0, (float) $holder->getDeferredTaxLiability());
        $deferredChange = max(-$openingDeferred, (float) $unrealised * $rate);

        if ($deferredChange !== 0.0) {
            $holder->setDeferredTaxLiability(self::decimal($openingDeferred + $deferredChange));
        }

        $holder->setTotalEquity(
            \bcsub(\bcadd($holder->getTotalEquity(), $unrealised, 4), self::decimal($deferredChange), 4)
        );

        return (float) $unrealised;
    }

    /** Book value per share including the portfolio's move since the last report; null for a firm holding none. */
    public function resolveMarkedBookValuePerShare(Stock $holder): ?float
    {
        $carrying = $holder->getListedStakesCarrying();

        if ($carrying === null) {
            return null;
        }

        $marketValue = $this->resolveStakeValue($holder);

        if ($marketValue === null) {
            return null;
        }

        $shares = max(1.0, (float) $holder->getSharesOutstanding());

        return ((float) $holder->getTotalEquity() + $marketValue - (float) $carrying) / $shares;
    }

    /** Fixed-point at the balance sheet's scale, so bcmath never sees an exponent. */
    private static function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
