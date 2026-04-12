<?php

namespace App\Message;

class UpdateStockVolatility
{
    public function __construct(
        private int $stockId,
        private int $ticksPerYear
    ) {}

    public function getStockId(): int { return $this->stockId; }
    public function getTicksPerYear(): int { return $this->ticksPerYear; }
}