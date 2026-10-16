<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

/**
 * Process-local store for tests and the headless macro harness.
 */
final class InMemoryIndustryShareStore implements IndustryShareStoreInterface
{
    /** @var array<string, array<string, array{revenue: float, gain: float, capacity: float, anchor_capacity_share: float, anchor_demand_share: float, anchor_time: float, addressable_share: float, tick: int, consumed_tick: int}>> */
    private array $records = [];

    /** @var array<string, array<string, array{start_tick: int, end_tick: int}>> */
    private array $regimes = [];

    public function readIndustry(string $industry): array
    {
        return $this->records[$industry] ?? [];
    }

    public function writeRecord(string $industry, string $ticker, array $record): void
    {
        $this->records[$industry][$ticker] = $record;
    }

    public function readRegimes(string $industry): array
    {
        return $this->regimes[$industry] ?? [];
    }

    public function writeRegime(string $industry, string $key, array $record): void
    {
        $this->regimes[$industry][$key] = $record;
    }
}
