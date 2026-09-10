<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * An account's margin position at one instant: what it is worth, what it must keep, and what it can still do.
 */
final readonly class MarginStatusDTO
{
    /**
     * @param float $cash                   Settled cash, short sale proceeds included.
     * @param float $longMarketValue        Market value of long positions.
     * @param float $shortMarketValue       Market value owed on short positions, as a positive number.
     * @param float $marginDebit            Cash borrowed from the broker.
     * @param float $equity                 What the account would be worth if everything closed at mid.
     * @param float $maintenanceRequirement Equity the account must keep before it is called.
     * @param float $buyingPower            Additional position value the account can still open.
     */
    public function __construct(
        public float $cash,
        public float $longMarketValue,
        public float $shortMarketValue,
        public float $marginDebit,
        public float $equity,
        public float $maintenanceRequirement,
        public float $buyingPower,
    ) {}

    /** True when equity has fallen below what the positions require. */
    public function isCalled(): bool
    {
        return $this->equity < $this->maintenanceRequirement;
    }

    /** Equity as a share of gross exposure; the number a risk screen actually reads. */
    public function equityRatio(): float
    {
        $gross = $this->longMarketValue + $this->shortMarketValue;

        return $gross > 0.0 ? $this->equity / $gross : 1.0;
    }

    /** Currency of equity that must be restored to clear a call. */
    public function callAmount(): float
    {
        return max(0.0, $this->maintenanceRequirement - $this->equity);
    }
}
