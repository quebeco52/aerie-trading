<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

/**
 * Persistence behind the industry share ledger: one record per reporting firm, keyed by industry then ticker.
 * Records are small (revenue, idiosyncratic gain, report tick) and are read by every peer at its own report.
 */
interface IndustryShareStoreInterface
{
    /**
     * @return array<string, array{revenue: float, gain: float, tick: int, consumed_tick: int}> Ticker-keyed records.
     */
    public function readIndustry(string $industry): array;

    /**
     * @param array{revenue: float, gain: float, tick: int, consumed_tick: int} $record
     */
    public function writeRecord(string $industry, string $ticker, array $record): void;
}
