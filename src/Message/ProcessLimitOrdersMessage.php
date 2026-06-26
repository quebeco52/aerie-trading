<?php

namespace App\Message;

class ProcessLimitOrdersMessage
{
    public function __construct(
        private string $ticker,
        private float $currentPrice
    ) {}

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getCurrentPrice(): float
    {
        return $this->currentPrice;
    }
}
