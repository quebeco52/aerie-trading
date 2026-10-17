<?php

declare(strict_types=1);

namespace App\Service\Market\Option;

/**
 * Process-local dealer exposure, for tests and for any run without Redis.
 */
final class InMemoryDealerGammaStore implements DealerGammaStoreInterface
{
    /** @var array<string, array{gamma: float, delta: float, reference_price: float, held: float}> */
    private array $state = [];

    public function record(string $ticker, array $entry): void
    {
        $this->state[$ticker] = $entry;
    }

    public function read(string $ticker): ?array
    {
        return $this->state[$ticker] ?? null;
    }

    public function readAll(): array
    {
        return $this->state;
    }

    public function recordAll(array $entries): void
    {
        foreach ($entries as $ticker => $entry) {
            $this->state[$ticker] = $entry;
        }
    }
}
