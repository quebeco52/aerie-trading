<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

/**
 * Process-local store for tests and the headless macro harness.
 *
 * @phpstan-import-type ShareRecord from IndustryShareStoreInterface
 * @phpstan-import-type OutlookState from IndustryShareStoreInterface
 */
final class InMemoryIndustryShareStore implements IndustryShareStoreInterface
{
    /** @var array<string, array<string, ShareRecord>> */
    private array $records = [];

    /** @var OutlookState|null */
    private ?array $outlooks = null;

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

    public function readOutlooks(): ?array
    {
        return $this->outlooks;
    }

    public function readOutlook(string $industry): ?array
    {
        return $this->outlooks['industries'][$industry] ?? null;
    }

    public function writeOutlooks(array $state): void
    {
        $this->outlooks = $state;
    }
}
