<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

/**
 * Process-local membership, for tests and for any run without Redis.
 */
final class InMemoryIndexMembershipStore implements IndexMembershipStoreInterface
{
    /** @var array<string, array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>}> */
    private array $state = [];

    public function current(MarketIndex $index): ?array
    {
        return $this->state[$index->value] ?? null;
    }

    public function store(MarketIndex $index, int $tick, array $tickers, array $added = [], array $deleted = []): void
    {
        $this->state[$index->value] = [
            'tick' => $tick,
            'tickers' => array_values($tickers),
            'added' => array_values($added),
            'deleted' => array_values($deleted),
        ];
    }
}
