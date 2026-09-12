<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

use App\Entity\Stock;

/**
 * Zero-sum market share inside an industry.
 *
 * Firms report on different ticks and each one draws its own demand shocks, so without this ledger a rival's
 * record quarter costs nobody anything. Here every report writes the firm's idiosyncratic revenue gain
 * (realized over expected, after macro) and its size; every later report of a peer in the same industry
 * reads the gains booked since its own last report and absorbs them in proportion to its share of the
 * remaining industry revenue. Summed over the peers, the drain equals the rival's gain, so idiosyncratic
 * share moves net to zero within the industry, up to the per-report bound below. The strategy's substitutability scales how much of a gain
 * is share taken from peers rather than a larger market (a branded good is mostly share; an oil producer's
 * extra barrels are sold into a global pool and barely touch a domestic peer).
 */
class IndustryShareLedger
{
    // --- Share Drain Bounds ---
    /**
     * Largest fraction of its own revenue a firm can lose to (or win from) rivals between two of its reports.
     * Proportional absorption is exact only while a rival's gain is small next to the rest of the industry;
     * in a concentrated one a leader's ordinary beat can exceed everything its peers sell, and unbounded it
     * would zero out a healthy firm in one report. Past the bound the excess is treated as market growth.
     */
    public const MAX_SHARE_DRAIN_PER_REPORT = 0.25;

    public function __construct(
        private readonly IndustryShareStoreInterface $store,
    ) {}

    /**
     * Fraction of the firm's own revenue that rivals' idiosyncratic gains since its last report have taken
     * from it. Negative when rivals gained, positive when they lost. Each rival record is absorbed once.
     */
    public function resolveRivalShareDrain(Stock $stock, int $tick, int $ticksPerYear): float
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return 0.0;
        }

        $records = $this->store->readIndustry($industry);
        $own = $records[$ticker] ?? null;
        $ownRevenue = max(1.0, (float) $stock->getTotalRevenue());
        $lastReadTick = (int) ($own['consumed_tick'] ?? -1);

        $totalRevenue = $ownRevenue;
        foreach ($records as $peerTicker => $record) {
            if ($peerTicker !== $ticker && $tick - $record['tick'] <= $ticksPerYear) {
                $totalRevenue += max(0.0, $record['revenue']);
            }
        }

        $drain = 0.0;
        foreach ($records as $peerTicker => $record) {
            if ($peerTicker === $ticker || $record['tick'] <= $lastReadTick || $tick - $record['tick'] > $ticksPerYear) {
                continue;
            }
            $peerRevenue = max(0.0, $record['revenue']);
            $rivalGainDollars = $peerRevenue * $record['gain'];
            $othersRevenue = max(1.0, $totalRevenue - $peerRevenue);
            // My slice of what the rival took from everyone else, as a fraction of my own revenue.
            $drain -= ($rivalGainDollars * ($ownRevenue / $othersRevenue)) / $ownRevenue;
        }

        $this->store->writeRecord($industry, $ticker, [
            'revenue'       => (float) ($own['revenue'] ?? $ownRevenue),
            'gain'          => (float) ($own['gain'] ?? 0.0),
            'tick'          => (int) ($own['tick'] ?? $tick),
            'consumed_tick' => $tick,
        ]);

        return max(-self::MAX_SHARE_DRAIN_PER_REPORT, min(self::MAX_SHARE_DRAIN_PER_REPORT, $drain));
    }

    /**
     * Books this report's idiosyncratic revenue gain (realized over expected, as a fraction) for rivals to absorb.
     */
    public function recordIdiosyncraticGain(Stock $stock, float $annualRevenue, float $gainFraction, int $tick): void
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return;
        }

        $existing = $this->store->readIndustry($industry)[$ticker] ?? null;
        $this->store->writeRecord($industry, $ticker, [
            'revenue'       => max(0.0, $annualRevenue),
            'gain'          => $gainFraction,
            'tick'          => $tick,
            'consumed_tick' => (int) ($existing['consumed_tick'] ?? $tick),
        ]);
    }
}
