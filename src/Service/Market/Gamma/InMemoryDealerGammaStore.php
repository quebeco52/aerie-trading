<?php

declare(strict_types=1);

namespace App\Service\Market\Gamma;

/**
 * Process-local dealer exposure, for tests and for any run without Redis.
 */
final class InMemoryDealerGammaStore implements DealerGammaStoreInterface
{
    /** @var array<string, array{gamma: float, reference_price: float}> */
    private array $state = [];

    public function record(string $ticker, float $customerGamma, float $referencePrice): void
    {
        $this->state[$ticker] = ['gamma' => $customerGamma, 'reference_price' => $referencePrice];
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
