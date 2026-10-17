<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

/**
 * Persistence behind the industry share ledger: one record per reporting firm, keyed by industry then ticker.
 * A record is small — revenue, the firm's own gain, installed capacity, its capacity anchor (its plant and
 * the market it sells into per unit of trend nominal GDP, and when that was struck), its addressable share,
 * report tick — and is read by every peer at its own report.
 *
 * @phpstan-type ShareRecord array{revenue: float, gain: float, capacity: float, anchor_capacity_share: float, anchor_demand_share: float, anchor_time: float, addressable_share: float, tick: int, consumed_tick: int}
 */
interface IndustryShareStoreInterface
{
    /**
     * @return array<string, ShareRecord> Ticker-keyed records.
     */
    public function readIndustry(string $industry): array;

    /**
     * @param ShareRecord $record
     */
    public function writeRecord(string $industry, string $ticker, array $record): void;

    /**
     * Industry-wide regimes (a price war every carrier fights at once), each the run the industry is in or last had.
     *
     * @return array<string, array{start_tick: int, end_tick: int}> Regime key => its run, end tick exclusive.
     */
    public function readRegimes(string $industry): array;

    /**
     * @param array{start_tick: int, end_tick: int} $record
     */
    public function writeRegime(string $industry, string $key, array $record): void;
}
