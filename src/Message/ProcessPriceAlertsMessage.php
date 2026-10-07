<?php

declare(strict_types=1);

namespace App\Message;

/** A price has reached at least one waiting alert on an instrument; the worker fires them. */
final class ProcessPriceAlertsMessage
{
    public function __construct(
        private readonly string $ticker,
        private readonly float $price,
    ) {}

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getPrice(): float
    {
        return $this->price;
    }
}
