<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Strategy\OperatingStrategyInterface;

/**
 * Zero-sum market share inside an industry, and the industry's capacity balance.
 *
 * CAPACITY. Every firm used to sell into a private market: its revenue capacity was its own capital times
 * its own turnover, and a rival doubling its plant cost it nothing. That is the non-rivalry behind every
 * "compounds forever" trace — there was no oversupply, no price collapse, no capex cycle. The ledger now
 * also records each firm's installed capacity, anchored the first time it is priced: its plant then is
 * trend plant, and that plant over its addressable share is the market it sells into. The modelled roster
 * is not the whole industry, so the balance is struck as dominant firms against a competitive fringe that
 * supplies at trend: each firm's build beyond its trend plant is excess supply weighted by its share of
 * the market. Excess is sold into a Cournot inverse demand curve and the industry's realized price falls
 * for everyone in it; plant that leaves (a bankruptcy) tightens it and survivors' pricing recovers. Entry
 * is neutral by construction: a firm's first pricing is its anchor.
 *
 * SHARE.
 *
 * Firms report on different ticks and each one draws its own demand shocks, so without this ledger a rival's
 * record quarter costs nobody anything. Here every report writes the firm's own revenue gain (realized over
 * expected, after macro, net of the sector factor every peer drew too), its size and its addressable share;
 * every later report of a peer in the same industry reads the gains booked since its own last report and
 * absorbs them in proportion to its size against everyone else in the rival's market. That market is the
 * modelled roster at least, and what the rival's addressable share implies beyond it: the roster is not the
 * industry, so a rival ten times a peer's size does not take its whole beat from that one peer. The
 * strategy's substitutability scales how much of a gain is share taken from peers rather than a larger
 * market (a branded good is mostly share; an oil producer's extra barrels are sold into a global pool and
 * barely touch a domestic peer). Both the booked gain and the absorbed drain are bounded per report.
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
            // Everyone else in the market the rival sells into: the rest of the roster, or the rest of its
            // addressable market where that is larger.
            $othersRevenue = max(1.0, $totalRevenue - $peerRevenue, self::fringeRevenue($peerRevenue, (float) ($record['addressable_share'] ?? 0.0)));
            // My slice of what the rival took from everyone else, as a fraction of my own revenue.
            $drain -= ($rivalGainDollars * ($ownRevenue / $othersRevenue)) / $ownRevenue;
        }

        $this->store->writeRecord($industry, $ticker, [
            'revenue'       => (float) ($own['revenue'] ?? $ownRevenue),
            'gain'          => (float) ($own['gain'] ?? 0.0),
            'capacity'      => (float) ($own['capacity'] ?? 0.0),
            ...self::carriedFields($own),
            'tick'          => (int) ($own['tick'] ?? $tick),
            'consumed_tick' => $tick,
        ]);

        return max(-self::MAX_SHARE_DRAIN_PER_REPORT, min(self::MAX_SHARE_DRAIN_PER_REPORT, $drain));
    }

    /**
     * Books this report's own revenue gain (realized over expected, as a fraction, net of the sector factor)
     * for rivals to absorb, bounded like the drain: past the bound the excess is market growth, not share.
     *
     * @param float $addressableShare The firm's share of the market it sells into; sizes the market its gain came out of.
     */
    public function recordIdiosyncraticGain(Stock $stock, float $annualRevenue, float $gainFraction, float $addressableShare, int $tick): void
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return;
        }

        $existing = $this->store->readIndustry($industry)[$ticker] ?? null;
        $this->store->writeRecord($industry, $ticker, [
            'revenue'           => max(0.0, $annualRevenue),
            'gain'              => max(-self::MAX_SHARE_DRAIN_PER_REPORT, min(self::MAX_SHARE_DRAIN_PER_REPORT, $gainFraction)),
            'capacity'          => (float) ($existing['capacity'] ?? 0.0),
            ...self::carriedFields($existing),
            'addressable_share' => max(0.0, min(1.0, $addressableShare)),
            'tick'              => $tick,
            'consumed_tick'     => (int) ($existing['consumed_tick'] ?? $tick),
        ]);
    }

    /**
     * Everyone else in a firm's addressable market, in revenue, from its own revenue and its share of that
     * market. Zero (no fringe) for a firm that is its whole market or whose share the ledger never saw.
     */
    private static function fringeRevenue(float $revenue, float $addressableShare): float
    {
        if ($addressableShare <= 0.0 || $addressableShare >= 1.0) {
            return 0.0;
        }

        return $revenue * (1.0 - $addressableShare) / $addressableShare;
    }

    /**
     * The fields a partial write must carry through untouched: the capacity anchor and the addressable
     * share; zero for a firm the ledger has not seen them from yet.
     *
     * @param array<string, mixed>|null $record
     * @return array{anchor_capacity_share: float, anchor_demand_share: float, anchor_time: float, addressable_share: float}
     */
    private static function carriedFields(?array $record): array
    {
        return [
            'anchor_capacity_share' => (float) ($record['anchor_capacity_share'] ?? 0.0),
            'anchor_demand_share'   => (float) ($record['anchor_demand_share'] ?? 0.0),
            'anchor_time'           => (float) ($record['anchor_time'] ?? 0.0),
            'addressable_share'     => (float) ($record['addressable_share'] ?? 0.0),
        ];
    }

    /**
     * Installed capacity against trend demand across the industry, bounded to the range the price responds
     * to. Records this firm's capacity first, so its own plant is always in the sum, and strikes its anchor
     * if it has none.
     *
     * Dominant firms with a competitive fringe (Forchheimer; Landes & Posner 1981). The modelled roster is
     * not the whole industry — a firm's addressable share says how much of it is — and the unmodelled rest
     * supplies at trend. Industry supply over demand is therefore one plus every modelled firm's build over
     * its own trend plant, each weighted by its share of the market at its anchor:
     *
     *     S/D = 1 + Σ_j (C_j − C0_j·g_j) / (D0_j·g_j),   D0_j = C0_j / s0_j
     *
     * with g_j the trend nominal GDP and secular excess growth since firm j's anchor. A lone firm at five
     * percent of its market that doubles its plant lifts industry supply by five percent; one at half its
     * market that does the same lifts it by half. The share is the one the saturation penalty is struck on,
     * so the two costs of scale rise together.
     *
     * @param float $annualCapacity      This firm's revenue capacity at full utilization, annualized, before any price response.
     * @param float $addressableShare    This firm's share of its serviceable addressable market (CorporateMetrics::calculateScaleRatio).
     * @param float $trendNominalGdp     Trend nominal GDP index (potential output times the price level; no output gap).
     * @param float $totalTime           Simulated time in years, for the secular term.
     * @param float $secularExcessGrowth The industry's secular real growth over the economy's trend growth.
     */
    public function resolveIndustryCapacityRatio(
        Stock $stock,
        float $annualCapacity,
        float $addressableShare,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear
    ): float {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === ''
            || $annualCapacity <= 0.0 || $addressableShare <= 0.0 || $trendNominalGdp <= 0.0) {
            return 1.0;
        }

        $records = $this->store->readIndustry($industry);
        $own = $records[$ticker] ?? null;
        $hasAnchor = $own !== null && (float) ($own['anchor_capacity_share'] ?? 0.0) > 0.0;

        // The anchor is struck once, the first time the ledger prices the firm: its plant then is trend
        // plant, and its plant over its share is the market it sells into. Entry is neutral by construction.
        $record = [
            'revenue'               => (float) ($own['revenue'] ?? max(1.0, (float) $stock->getTotalRevenue())),
            'gain'                  => (float) ($own['gain'] ?? 0.0),
            'capacity'              => $annualCapacity,
            'anchor_capacity_share' => $hasAnchor ? (float) $own['anchor_capacity_share'] : $annualCapacity / $trendNominalGdp,
            'anchor_demand_share'   => $hasAnchor ? (float) $own['anchor_demand_share'] : $annualCapacity / (min(1.0, $addressableShare) * $trendNominalGdp),
            'anchor_time'           => $hasAnchor ? (float) $own['anchor_time'] : $totalTime,
            'addressable_share'     => min(1.0, $addressableShare),
            'tick'                  => $tick,
            'consumed_tick'         => (int) ($own['consumed_tick'] ?? $tick),
        ];
        $this->store->writeRecord($industry, $ticker, $record);
        $records[$ticker] = $record;

        $balance = $this->capacityBalance($records, $ticker, $trendNominalGdp, $totalTime, $secularExcessGrowth, $tick, $ticksPerYear);

        return $this->boundedCapacityRatio($balance['supply_over_demand']);
    }

    /**
     * Trend nominal GDP the demand anchor rides on: potential output times the price level, with no output
     * gap. The cycle already reaches a firm's volume through the demand shift and would be counted twice here.
     */
    public static function trendNominalGdp(MacroStateDTO $macroState): float
    {
        return max(0.01, $macroState->potentialGdpIndex) * max(0.01, $macroState->gdpDeflator);
    }

    /**
     * What an industry grows at beyond the economy: the sector's secular real growth over trend potential
     * growth (labour plus productivity). The default sector grows with the economy exactly.
     */
    public static function secularExcessGrowth(OperatingStrategyInterface $strategy, Stock $stock): float
    {
        return $strategy->getSecularGrowthRate($stock) - (MacroEngine::TFP_DRIFT + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE);
    }

    /**
     * This firm's share of what its industry sells: its revenue over the roster's, the one figure that
     * deserves the name "market share". Reads only; a firm with no record yet is counted at its current
     * revenue. Null when the firm has no industry.
     */
    public function resolveRevenueShare(Stock $stock, int $tick, int $ticksPerYear): ?float
    {
        $description = $this->describeIndustry($stock, null, $tick, $ticksPerYear);

        return $description === null ? null : $description['revenue_share'];
    }

    /**
     * The industry as the ledger sees it, for display: every firm's revenue and share, and — when the
     * caller supplies the trend inputs — the modelled roster's installed plant against what that plant
     * would be at trend, and the industry balance those imply. Reads only: it neither records this firm
     * nor strikes an anchor, so a page view cannot move the balance.
     *
     * @param array{trend_nominal_gdp: float, total_time: float, secular_excess_growth: float}|null $trend
     * @return array{
     *     revenue_share: float,
     *     peers: array<string, array{revenue: float, revenue_share: float, capacity: float}>,
     *     installed_capacity: float|null,
     *     trend_capacity: float|null,
     *     capacity_ratio: float|null
     * }|null
     */
    public function describeIndustry(Stock $stock, ?array $trend, int $tick, int $ticksPerYear): ?array
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return null;
        }

        $records = $this->store->readIndustry($industry);
        $own = $records[$ticker] ?? null;
        $ownRevenue = max(0.0, (float) ($own['revenue'] ?? (float) $stock->getTotalRevenue()));

        $roster = [$ticker => ['revenue' => $ownRevenue, 'capacity' => max(0.0, (float) ($own['capacity'] ?? 0.0))]];
        foreach ($records as $peerTicker => $record) {
            if ($peerTicker !== $ticker && $tick - $record['tick'] <= $ticksPerYear) {
                $roster[$peerTicker] = [
                    'revenue' => max(0.0, (float) $record['revenue']),
                    'capacity' => max(0.0, (float) ($record['capacity'] ?? 0.0)),
                ];
            }
        }

        $industryRevenue = array_sum(array_column($roster, 'revenue'));
        $peers = [];
        foreach ($roster as $peerTicker => $entry) {
            $peers[$peerTicker] = [
                'revenue' => $entry['revenue'],
                'revenue_share' => $industryRevenue > 0.0 ? $entry['revenue'] / $industryRevenue : 0.0,
                'capacity' => $entry['capacity'],
            ];
        }

        $installedCapacity = null;
        $trendCapacity = null;
        $capacityRatio = null;
        if ($trend !== null && $own !== null && (float) ($own['anchor_capacity_share'] ?? 0.0) > 0.0) {
            $balance = $this->capacityBalance($records, $ticker, $trend['trend_nominal_gdp'], $trend['total_time'], $trend['secular_excess_growth'], $tick, $ticksPerYear);
            $installedCapacity = $balance['installed_capacity'];
            $trendCapacity = $balance['trend_capacity'];
            $capacityRatio = $this->boundedCapacityRatio($balance['supply_over_demand']);
        }

        return [
            'revenue_share' => $peers[$ticker]['revenue_share'],
            'peers' => $peers,
            'installed_capacity' => $installedCapacity,
            'trend_capacity' => $trendCapacity,
            'capacity_ratio' => $capacityRatio,
        ];
    }

    /**
     * The sum in the class comment over every anchored firm on the roster (this firm always, a peer while
     * its record is under a year old): industry supply over demand, and the modelled plant behind it.
     *
     * @param array<string, array{revenue: float, gain: float, capacity: float, anchor_capacity_share: float, anchor_demand_share: float, anchor_time: float, addressable_share: float, tick: int, consumed_tick: int}> $records
     * @return array{supply_over_demand: float, installed_capacity: float, trend_capacity: float}
     */
    private function capacityBalance(
        array $records,
        string $ticker,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear
    ): array {
        $excess = 0.0;
        $installed = 0.0;
        $trendCapacity = 0.0;

        foreach ($records as $peerTicker => $record) {
            if ((float) ($record['anchor_capacity_share'] ?? 0.0) <= 0.0) {
                continue;
            }
            if ($peerTicker !== $ticker && $tick - $record['tick'] > $ticksPerYear) {
                continue;
            }

            $growth = $trendNominalGdp * exp($secularExcessGrowth * ($totalTime - (float) $record['anchor_time']));
            $trendPlant = (float) $record['anchor_capacity_share'] * $growth;
            $trendDemand = (float) $record['anchor_demand_share'] * $growth;
            $capacity = max(0.0, (float) $record['capacity']);

            $installed += $capacity;
            $trendCapacity += $trendPlant;
            $excess += ($capacity - $trendPlant) / max(1.0, $trendDemand);
        }

        return [
            'supply_over_demand' => 1.0 + $excess,
            'installed_capacity' => $installed,
            'trend_capacity' => $trendCapacity,
        ];
    }

    private function boundedCapacityRatio(float $supplyOverDemand): float
    {
        return max(FinancialConstants::MIN_INDUSTRY_CAPACITY_RATIO, min(FinancialConstants::MAX_INDUSTRY_CAPACITY_RATIO, $supplyOverDemand));
    }

    /**
     * A failed firm's plant leaves the industry; its demand does not. Its record stays, at no capacity
     * and no revenue but with its anchor, so survivors sell into a market short by its share until the
     * record ages out and the fringe is taken to have filled the gap. This is the consolidation effect
     * the exit-recapture rule used to approximate with a share transfer.
     */
    public function retireFirm(Stock $stock): void
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return;
        }

        $own = $this->store->readIndustry($industry)[$ticker] ?? null;
        if ($own === null) {
            return;
        }

        $this->store->writeRecord($industry, $ticker, [
            'revenue'               => 0.0,
            'gain'                  => 0.0,
            'capacity'              => 0.0,
            'anchor_capacity_share' => (float) ($own['anchor_capacity_share'] ?? 0.0),
            'anchor_demand_share'   => (float) ($own['anchor_demand_share'] ?? 0.0),
            'anchor_time'           => (float) ($own['anchor_time'] ?? 0.0),
            'addressable_share'     => (float) ($own['addressable_share'] ?? 0.0),
            'tick'                  => (int) $own['tick'],
            'consumed_tick'         => (int) $own['consumed_tick'],
        ]);
    }
}
