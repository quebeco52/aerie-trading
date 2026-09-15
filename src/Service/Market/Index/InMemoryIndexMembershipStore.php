<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

/**
 * Process-local membership, for tests and for any run without Redis.
 */
final class InMemoryIndexMembershipStore implements IndexMembershipStoreInterface
{
    /** @var array{tick: int, tickers: list<string>}|null */
    private ?array $state = null;

    public function current(): ?array
    {
        return $this->state;
    }

    public function store(int $tick, array $tickers): void
    {
        $this->state = ['tick' => $tick, 'tickers' => array_values($tickers)];
    }
}
