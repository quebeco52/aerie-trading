<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

/**
 * Persistence behind the industry share ledger: one record per reporting firm, keyed by industry then ticker.
 * A record is small — revenue, the firm's own gain, installed capacity, its capacity anchor (its plant and
 * the market it sells into per unit of trend nominal GDP, and when that was struck), its addressable share,
 * report tick — and is read by every peer at its own report.
 *
 * @phpstan-type ShareRecord array{revenue: float, gain: float, capacity: float, anchor_capacity_share: float, anchor_demand_share: float, anchor_time: float, anchor_outlook?: float, segment_outlook_gap?: float, addressable_share: float, tick: int, consumed_tick: int}
 * @phpstan-type OutlookRecord array{x: float, m: float, v: float, f: float, w: float, prior_belief: float, revised_tick: int}
 * @phpstan-type OutlookState array{salt: int, quarter: int, industries: array<string, OutlookRecord>}
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

    /**
     * Every industry's outlook (IndustryOutlook) with the run's draw salt and the last quarter stepped; null before the
     * first step.
     *
     * @return OutlookState|null
     */
    public function readOutlooks(): ?array;

    /**
     * One industry's outlook, or null for an industry the outlook does not track.
     *
     * @return OutlookRecord|null
     */
    public function readOutlook(string $industry): ?array;

    /**
     * @param OutlookState $state
     */
    public function writeOutlooks(array $state): void;
}
