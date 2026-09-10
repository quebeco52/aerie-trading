<?php

declare(strict_types=1);

namespace App\Service\Market\Flow;

/**
 * Process-local order flow, for tests and headless harnesses where no Redis is running.
 */
final class InMemoryOrderFlowStore implements OrderFlowStoreInterface
{
    /** @var array<string, float> */
    private array $flow = [];

    public function record(string $ticker, float $signedQuantity): void
    {
        if ($signedQuantity === 0.0) {
            return;
        }

        $this->flow[$ticker] = ($this->flow[$ticker] ?? 0.0) + $signedQuantity;
    }

    public function drain(): array
    {
        $drained = $this->flow;
        $this->flow = [];

        return $drained;
    }
}
