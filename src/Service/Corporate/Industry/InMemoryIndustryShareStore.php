<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

/**
 * Process-local store for tests and the headless macro harness.
 */
final class InMemoryIndustryShareStore implements IndustryShareStoreInterface
{
    /** @var array<string, array<string, array{revenue: float, gain: float, tick: int, consumed_tick: int}>> */
    private array $records = [];

    public function readIndustry(string $industry): array
    {
        return $this->records[$industry] ?? [];
    }

    public function writeRecord(string $industry, string $ticker, array $record): void
    {
        $this->records[$industry][$ticker] = $record;
    }
}
