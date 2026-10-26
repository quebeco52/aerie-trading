<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Earnings\CapitalBudgetEngine;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\TimeSeries;
use App\Service\Model\Strategy\OperatingStrategyInterface;

/**
 * Zero-sum market share inside an industry, and the industry's capacity balance.
 *
 * CAPACITY. The ledger records each firm's installed capacity, anchored the first time it is priced: its plant then is
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
 *
 * @phpstan-import-type ShareRecord from IndustryShareStoreInterface
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

    // --- Industry Capacity & Cournot Pricing ---
    /**
     * Tightest capacity-to-demand ratio the industry price responds to; past it demand is rationed rather than bid ever
     * higher.
     */
    public const MIN_INDUSTRY_CAPACITY_RATIO = 0.5;

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

        return $this->drainAt($industry, $ticker, max(1.0, (float) $stock->getTotalRevenue()), $tick, $ticksPerYear);
    }

    /**
     * The drain on one segment of a company that keeps its plant by segment (SegmentBooks), from rivals in that
     * segment's industry, as a fraction of the segment's revenue.
     */
    public function resolveSegmentRivalDrain(Stock $stock, string $segment, float $segmentRevenue, int $tick, int $ticksPerYear): float
    {
        $address = self::segmentAddress($stock, $segment);

        // A segment new to its industry starts reading its rivals now: the gains they booked before it sold there took
        // nothing from it.
        return $address === null ? 0.0 : $this->drainAt($address[0], $address[1], max(1.0, $segmentRevenue), $tick, $ticksPerYear, $tick);
    }

    private function drainAt(string $industry, string $ticker, float $ownRevenue, int $tick, int $ticksPerYear, int $firstReadTick = -1): float
    {
        $records = $this->store->readIndustry($industry);
        $own = $records[$ticker] ?? null;
        $lastReadTick = (int) ($own['consumed_tick'] ?? $firstReadTick);

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
     * @param float $addressableShare The firm's share of the market it sells into; sizes the market its gain came out
     *                                of.
     * @param array{margin: float, turnover: float}|null $economics What its plant earns there: its operating margin
     *                                at the balanced price and its annual capital turnover.
     */
    public function recordIdiosyncraticGain(Stock $stock, float $annualRevenue, float $gainFraction, float $addressableShare, int $tick, ?array $economics = null): void
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return;
        }

        $this->gainAt($industry, $ticker, $annualRevenue, $gainFraction, $addressableShare, $tick, $economics);
    }

    /**
     * Books one segment's revenue and gain in its industry for a company that keeps its plant by segment: the segment's
     * revenue only, so the company's sales are counted once across its industries.
     */
    /** @param array{margin: float, turnover: float}|null $economics As recordIdiosyncraticGain(). */
    public function recordSegmentGain(Stock $stock, string $segment, float $annualRevenue, float $gainFraction, float $addressableShare, int $tick, ?array $economics = null): void
    {
        $address = self::segmentAddress($stock, $segment);
        if ($address !== null) {
            $this->gainAt($address[0], $address[1], $annualRevenue, $gainFraction, $addressableShare, $tick, $economics);
        }
    }

    /** @param array{margin: float, turnover: float}|null $economics */
    private function gainAt(string $industry, string $ticker, float $annualRevenue, float $gainFraction, float $addressableShare, int $tick, ?array $economics = null): void
    {
        $existing = $this->store->readIndustry($industry)[$ticker] ?? null;
        $this->store->writeRecord($industry, $ticker, [
            'revenue'           => max(0.0, $annualRevenue),
            'gain'              => max(-self::MAX_SHARE_DRAIN_PER_REPORT, min(self::MAX_SHARE_DRAIN_PER_REPORT, $gainFraction)),
            'capacity'          => (float) ($existing['capacity'] ?? 0.0),
            ...self::carriedFields($existing),
            ...($economics ?? []),
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
     * @return array{anchor_capacity_share: float, anchor_demand_share: float, anchor_time: float, anchor_outlook: float, addressable_share: float, segment_outlook_gap?: float, books_main_share?: float, path?: float, anchor_path?: float, margin?: float, turnover?: float, entry_offset?: float, entry_time?: float}
     */
    private static function carriedFields(?array $record): array
    {
        return [
            'anchor_capacity_share' => (float) ($record['anchor_capacity_share'] ?? 0.0),
            'anchor_demand_share'   => (float) ($record['anchor_demand_share'] ?? 0.0),
            'anchor_time'           => (float) ($record['anchor_time'] ?? 0.0),
            'anchor_outlook'        => (float) ($record['anchor_outlook'] ?? 0.0),
            'addressable_share'     => (float) ($record['addressable_share'] ?? 0.0),
        ] + self::carriedMarks($record);
    }

    /**
     * What a record carries for a company in several markets: on one plant, how far its blended outlook sits from its
     * industry's; with plant by segment, the main record's share of the plant its books opened at, so the opening is
     * applied once, and a segment's own path where its model plans one; and what any record's plant earns and how it
     * entered (peerMarks()).
     *
     * @param array<string, mixed>|null $record
     * @return array{segment_outlook_gap?: float, books_main_share?: float, path?: float, anchor_path?: float, margin?: float, turnover?: float, entry_offset?: float, entry_time?: float}
     */
    private static function carriedMarks(?array $record): array
    {
        return (isset($record['segment_outlook_gap']) ? ['segment_outlook_gap' => (float) $record['segment_outlook_gap']] : [])
            + (isset($record['books_main_share']) ? ['books_main_share' => (float) $record['books_main_share']] : [])
            + (isset($record['path']) ? ['path' => (float) $record['path'], 'anchor_path' => (float) ($record['anchor_path'] ?? $record['path'])] : [])
            + self::peerMarks($record);
    }

    /**
     * What a record's plant earns, as its last report filed it: its margin at the balanced price and its capital
     * turnover, which a firm entering the industry benchmarks itself against (resolvePeerEconomics());
     * and for plant built into the industry from outside it, the share of the market the fringe has yet to give up to
     * it, and when it entered (capacityBalance()).
     *
     * @param array<string, mixed>|null $record
     * @return array{margin?: float, turnover?: float, entry_offset?: float, entry_time?: float}
     */
    private static function peerMarks(?array $record): array
    {
        return (isset($record['margin']) ? ['margin' => (float) $record['margin'], 'turnover' => (float) ($record['turnover'] ?? 0.0)] : [])
            + (isset($record['entry_offset']) ? ['entry_offset' => (float) $record['entry_offset'], 'entry_time' => (float) ($record['entry_time'] ?? 0.0)] : []);
    }

    /**
     * Installed capacity against trend demand across the industry, floored where a shortage stops bidding the price
     * up. Records this firm's capacity first, so its own plant is always in the sum, and strikes its anchor if it has
     * none.
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
     * @param float $annualCapacity      This firm's revenue capacity at full utilization, annualized, before any price
     *                                   response.
     * @param float $addressableShare    This firm's share of its serviceable addressable market
     *                                   (CorporateMetrics::calculateScaleRatio).
     * @param float $trendNominalGdp     Trend nominal GDP index (potential output times the price level; no output
     *                                   gap).
     * @param float $totalTime           Simulated time in years, for the secular term.
     * @param float $secularExcessGrowth The industry's secular real growth over the economy's trend growth at the open;
     *                                   faded here.
     */
    public function resolveIndustryCapacityRatio(
        Stock $stock,
        float $annualCapacity,
        float $addressableShare,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear,
    ): float {
        $balance = $this->recordAndBalance($stock, $annualCapacity, $addressableShare, $trendNominalGdp, $totalTime, $secularExcessGrowth, $tick, $ticksPerYear);

        return $balance === null ? 1.0 : $this->boundedCapacityRatio($balance['supply_over_demand']);
    }

    /**
     * The industry's supply against its true demand at the trend price, split into the listed roster's plant and the
     * unlisted fringe's, after recording this firm as resolveIndustryCapacityRatio() does. A roster that leaves the
     * industry shorter than the price responds to is floored there. Null for a firm the ledger cannot price.
     *
     * @return array{roster: float, fringe: float}|null
     */
    public function resolveIndustrySupply(
        Stock $stock,
        float $annualCapacity,
        float $addressableShare,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear,
    ): ?array {
        $balance = $this->recordAndBalance($stock, $annualCapacity, $addressableShare, $trendNominalGdp, $totalTime, $secularExcessGrowth, $tick, $ticksPerYear);
        if ($balance === null) {
            return null;
        }

        return [
            'roster' => max($balance['roster_supply'], self::MIN_INDUSTRY_CAPACITY_RATIO - $balance['fringe_supply']),
            'fringe' => $balance['fringe_supply'],
        ];
    }

    /**
     * Records this firm's capacity, striking its anchor if it has none, and strikes the industry balance with it in.
     *
     * @return array{supply_over_demand: float, roster_supply: float, fringe_supply: float, installed_capacity: float, trend_capacity: float, roster_trend_share: float}|null
     */
    private function recordAndBalance(
        Stock $stock,
        float $annualCapacity,
        float $addressableShare,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear,
    ): ?array {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return null;
        }
        // A company in several markets on one plant grows by its blend of their outlooks, not by its own industry's
        // alone; one that keeps plant by segment grows each segment by its own.
        $segmentGap = Segments::forTicker($ticker) !== null && SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) === null
            ? $this->resolveOutlookBelief($stock) - $this->outlook($industry)['belief']
            : null;

        return $this->recordAndBalanceAt($industry, $ticker, max(1.0, (float) $stock->getTotalRevenue()), $segmentGap, $annualCapacity, $addressableShare, $trendNominalGdp, $totalTime, $secularExcessGrowth, $tick, $ticksPerYear);
    }

    /**
     * resolveIndustrySupply() for one segment of a company that keeps its plant by segment: its plant, struck against
     * its own industry's demand, among that industry's firms.
     *
     * @param float|null $path The log level of the segment's own path where its model plans one (ownPathFactor()).
     * @return array{roster: float, fringe: float}|null
     */
    public function resolveSegmentSupply(
        Stock $stock,
        string $segment,
        float $segmentRevenue,
        float $annualCapacity,
        float $addressableShare,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear,
        ?float $path = null,
    ): ?array {
        $address = self::segmentAddress($stock, $segment);
        $balance = $address === null
            ? null
            : $this->recordAndBalanceAt($address[0], $address[1], max(1.0, $segmentRevenue), null, $annualCapacity, $addressableShare, $trendNominalGdp, $totalTime, $secularExcessGrowth, $tick, $ticksPerYear, $path);

        return $balance === null ? null : [
            'roster' => max($balance['roster_supply'], self::MIN_INDUSTRY_CAPACITY_RATIO - $balance['fringe_supply']),
            'fringe' => $balance['fringe_supply'],
        ];
    }

    /**
     * @param float|null $segmentGap How far a company in several markets on one plant reads its blend above its
     *                               industry's outlook; null for any other record.
     * @param float|null $path       The log level of a segment's own path; null for a record that follows its industry.
     * @return array{supply_over_demand: float, roster_supply: float, fringe_supply: float, installed_capacity: float, trend_capacity: float, roster_trend_share: float}|null
     */
    private function recordAndBalanceAt(
        string $industry,
        string $ticker,
        float $revenue,
        ?float $segmentGap,
        float $annualCapacity,
        float $addressableShare,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear,
        ?float $path = null,
    ): ?array {
        if ($annualCapacity <= 0.0 || $addressableShare <= 0.0 || $trendNominalGdp <= 0.0) {
            return null;
        }

        $records = $this->store->readIndustry($industry);
        $own = $records[$ticker] ?? null;
        $hasAnchor = $own !== null && (float) ($own['anchor_capacity_share'] ?? 0.0) > 0.0;
        $outlook = $this->outlook($industry);
        $segmentGap = $segmentGap === null ? [] : ['segment_outlook_gap' => $segmentGap];
        // A path is struck with the anchor, so the record's plant starts on its trend wherever the path then stands; a
        // report that brings none keeps the one the record has.
        $path ??= isset($own['path']) ? (float) $own['path'] : null;
        $ownPath = $path === null ? [] : ['path' => $path, 'anchor_path' => $hasAnchor ? (float) ($own['anchor_path'] ?? $path) : $path];

        // The anchor is struck once, the first time the ledger prices the firm: its plant then is trend
        // plant, and its plant over its share is the market it sells into as the public then saw it. Entry is
        // neutral by construction.
        $record = [
            'revenue'               => (float) ($own['revenue'] ?? $revenue),
            'gain'                  => (float) ($own['gain'] ?? 0.0),
            'capacity'              => $annualCapacity,
            'anchor_capacity_share' => $hasAnchor ? (float) $own['anchor_capacity_share'] : $annualCapacity / $trendNominalGdp,
            'anchor_demand_share'   => $hasAnchor ? (float) $own['anchor_demand_share'] : $annualCapacity / (min(1.0, $addressableShare) * $trendNominalGdp),
            'anchor_time'           => $hasAnchor ? (float) $own['anchor_time'] : $totalTime,
            'anchor_outlook'        => $hasAnchor ? (float) ($own['anchor_outlook'] ?? 0.0) : $outlook['belief'] + ($segmentGap['segment_outlook_gap'] ?? 0.0),
            'addressable_share'     => min(1.0, $addressableShare),
            'tick'                  => $tick,
            'consumed_tick'         => (int) ($own['consumed_tick'] ?? $tick),
        ] + $segmentGap + $ownPath + (isset($own['books_main_share']) ? ['books_main_share' => (float) $own['books_main_share']] : []) + self::grownEntry($own, $path, $totalTime) + self::peerMarks($own);
        $this->store->writeRecord($industry, $ticker, $record);
        $records[$ticker] = $record;

        return $this->capacityBalance($records, $ticker, $trendNominalGdp, $totalTime, $secularExcessGrowth, $tick, $ticksPerYear, $outlook);
    }

    /**
     * The log gap between the plant this firm's share of trend demand calls for and the plant it has installed:
     * positive when it has fallen behind its market, negative when it has built ahead of it. Its anchored share
     * moves only with the capacity a deal buys or sells, so a firm grows past its market by acquisition and not
     * by building into demand that is not there. Reads only. Null before the ledger has anchored the firm.
     */
    public function resolveTrendCapacityGap(Stock $stock, MacroStateDTO $macroState, float $secularExcessGrowth): ?float
    {
        return $this->trendCapacityGapAt((string) $stock->getIndustry(), $this->readOwnRecord($stock), $macroState, $secularExcessGrowth);
    }

    /** resolveTrendCapacityGap() for one segment of a company that keeps its plant by segment, in its own industry. */
    public function resolveSegmentCapacityGap(Stock $stock, string $segment, MacroStateDTO $macroState, float $secularExcessGrowth): ?float
    {
        $address = self::segmentAddress($stock, $segment);

        return $address === null ? null : $this->trendCapacityGapAt($address[0], $this->store->readIndustry($address[0])[$address[1]] ?? null, $macroState, $secularExcessGrowth);
    }

    /** @param ShareRecord|null $own */
    private function trendCapacityGapAt(string $industry, ?array $own, MacroStateDTO $macroState, float $secularExcessGrowth): ?float
    {
        $anchorShare = (float) ($own['anchor_capacity_share'] ?? 0.0);
        $capacity = (float) ($own['capacity'] ?? 0.0);
        if ($own === null || $anchorShare <= 0.0 || $capacity <= 0.0) {
            return null;
        }

        $growth = self::trendFactor($own, self::trendNominalGdp($macroState), $secularExcessGrowth, $macroState->totalTime, $this->recordBelief($own, $industry));

        return log($anchorShare * $growth * self::ownPathFactor($own) / $capacity);
    }

    /**
     * The market an industry's listed firms sell into, in annual revenue now: the largest any of them anchored, grown
     * as the ledger grows it. Records of the given company are left out, so a segment entering is sized against the
     * market already there. Null while no other firm in the industry is anchored.
     */
    public function resolveIndustryMarketRevenue(string $industry, string $excludedTicker, MacroStateDTO $macroState, float $secularExcessGrowth, int $tick, int $ticksPerYear): ?float
    {
        $market = null;
        foreach ($this->store->readIndustry($industry) as $peerTicker => $record) {
            if (SegmentBooks::ownerOf((string) $peerTicker) === $excludedTicker || $tick - $record['tick'] > $ticksPerYear
                || (float) ($record['anchor_demand_share'] ?? 0.0) <= 0.0) {
                continue;
            }
            $growth = self::trendFactor($record, self::trendNominalGdp($macroState), $secularExcessGrowth, $macroState->totalTime, $this->recordBelief($record, $industry));
            $market = max($market ?? 0.0, (float) $record['anchor_demand_share'] * $growth);
        }

        return $market;
    }

    /**
     * What an industry's firms earn on their plant, as a firm entering it from outside benchmarks itself: the margin
     * and capital turnover their fresh records filed (under a year old), over their revenue capacity and so the
     * industry's as a whole (the turnover is its capacity over its plant), leaving out the entrant's own. Null where no
     * firm in the industry has filed them.
     *
     * @return array{margin: float, turnover: float}|null
     */
    public function resolvePeerEconomics(string $industry, string $excludedTicker, int $tick, int $ticksPerYear): ?array
    {
        $capacity = 0.0;
        $plant = 0.0;
        $income = 0.0;
        foreach ($this->store->readIndustry($industry) as $peerTicker => $record) {
            $peerCapacity = max(0.0, (float) $record['capacity']);
            $turnover = (float) ($record['turnover'] ?? 0.0);
            if (!isset($record['margin']) || $peerCapacity <= 0.0 || $turnover <= 0.0 || $tick - $record['tick'] > $ticksPerYear
                || SegmentBooks::ownerOf((string) $peerTicker) === $excludedTicker) {
                continue;
            }
            $capacity += $peerCapacity;
            $plant += $peerCapacity / $turnover;
            $income += $peerCapacity * $record['margin'];
        }

        return $capacity > 0.0 && $plant > 0.0 ? ['margin' => $income / $capacity, 'turnover' => $capacity / $plant] : null;
    }

    /**
     * The market a firm from outside an industry would enter: what the industry's firms sell into now
     * (resolveIndustryMarketRevenue()), and the share of it the fringe holds, the room a newcomer can take its place
     * in: one less the anchored firms' trend shares, earlier entrants' included whether or not the fringe has yet
     * yielded them theirs, so two entrants never count on the same room. With it, the trend share a typical incumbent
     * holds (the median of those that did not enter in play, or of all where none did) and the sales of the largest
     * firm there. Null while no firm in the industry is anchored.
     *
     * @return array{market: float, fringe_share: float, typical_share: float, leader_revenue: float}|null
     */
    public function describeEntrant(string $industry, string $ticker, MacroStateDTO $macroState, float $secularExcessGrowth, int $tick, int $ticksPerYear): ?array
    {
        $market = $this->resolveIndustryMarketRevenue($industry, $ticker, $macroState, $secularExcessGrowth, $tick, $ticksPerYear);
        if ($market === null) {
            return null;
        }

        $held = 0.0;
        $incumbents = [];
        $entrants = [];
        $leader = 0.0;
        foreach ($this->store->readIndustry($industry) as $key => $record) {
            $demandShare = (float) ($record['anchor_demand_share'] ?? 0.0);
            if ($demandShare <= 0.0 || (float) ($record['anchor_capacity_share'] ?? 0.0) <= 0.0 || $tick - $record['tick'] > $ticksPerYear) {
                continue;
            }
            $share = (float) $record['anchor_capacity_share'] * self::ownPathFactor($record) / $demandShare;
            $held += $share;
            if (SegmentBooks::ownerOf((string) $key) === $ticker) {
                continue;
            }
            if (isset($record['entry_time'])) {
                $entrants[] = $share;
            } else {
                $incumbents[] = $share;
            }
            $leader = max($leader, (float) $record['revenue']);
        }
        $typical = $incumbents !== [] ? $incumbents : $entrants;
        sort($typical);
        $count = count($typical);
        $median = $count === 0 ? 0.0 : ($count % 2 === 1 ? $typical[intdiv($count, 2)] : ($typical[$count / 2 - 1] + $typical[$count / 2]) / 2.0);

        return ['market' => $market, 'fringe_share' => max(0.0, 1.0 - $held), 'typical_share' => $median, 'leader_revenue' => $leader];
    }

    /** The run's salt, which keyed draws hash from (MathUtility::hashedUniform()); zero before the outlooks open. */
    public function runSalt(): int
    {
        return (int) ($this->store->readOutlooks()['salt'] ?? 0);
    }

    /**
     * The level the public expects the firm's demand to reach, in log against its path: its industry's outlook (0 if
     * untracked), or for a company in several markets the blend of its segments' at the shares its last report filed.
     */
    public function resolveOutlookBelief(Stock $stock): float
    {
        $beliefs = $this->resolveSegmentBeliefs($stock);
        if ($beliefs === null) {
            return $this->outlook((string) $stock->getIndustry())['belief'];
        }

        return SegmentOutlook::blend(SegmentOutlook::filedShares($stock->getEarningsMomentumZ() ?? []), $beliefs);
    }

    /**
     * Each segment's industry outlook, in log, main segment under Segments::MAIN; null for a company in one market.
     *
     * @return array<string, float>|null
     */
    public function resolveSegmentBeliefs(Stock $stock): ?array
    {
        if (!SegmentBooks::keepsBooks($stock)) {
            return null;
        }

        $beliefs = [];
        foreach (SegmentBooks::industries($stock) as $segment => $industry) {
            $beliefs[$segment] = $this->outlook($industry)['belief'];
        }

        return $beliefs;
    }

    /**
     * An industry's outlook as the public reads it, for display: the demand level it expects against the expected path
     * (1 at the open), its doubt about that as a log standard deviation, and the last quarter's revision as a fraction
     * (null before the first). Null for an industry with no outlook. The true level is never shown.
     *
     * @return array{level: float, doubt: float, revision: float|null}|null
     */
    public function describeOutlook(string $industry): ?array
    {
        $record = $this->store->readOutlook($industry);
        if ($record === null) {
            return null;
        }

        return [
            'level' => exp(IndustryOutlook::belief($record)),
            'doubt' => sqrt(max(0.0, $record['v'])),
            'revision' => $record['revised_tick'] < 0 ? null : IndustryOutlook::revision($record),
        ];
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
     * What an industry grows at beyond the economy at the open: the sector's secular real growth over trend
     * potential growth (labour plus productivity). The ledger fades it from there on the same half-life as the
     * rate (TimeSeries::fadedExcessIntegral()); the default sector grows with the economy exactly.
     */
    public static function secularExcessGrowth(OperatingStrategyInterface $strategy, Stock $stock): float
    {
        return $strategy->getSecularGrowthRate($stock) - MacroConstants::TREND_REAL_GROWTH;
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
     *     capacity_ratio: float|null,
     *     roster_trend_share: float|null
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
        $rosterTrendShare = null;
        if ($trend !== null && $own !== null && (float) ($own['anchor_capacity_share'] ?? 0.0) > 0.0) {
            $balance = $this->capacityBalance($records, $ticker, $trend['trend_nominal_gdp'], $trend['total_time'], $trend['secular_excess_growth'], $tick, $ticksPerYear, $this->outlook($industry));
            $installedCapacity = $balance['installed_capacity'];
            $trendCapacity = $balance['trend_capacity'];
            $capacityRatio = $this->boundedCapacityRatio($balance['supply_over_demand']);
            $rosterTrendShare = $balance['roster_trend_share'];
        }

        return [
            'revenue_share' => $peers[$ticker]['revenue_share'],
            'peers' => $peers,
            'installed_capacity' => $installedCapacity,
            'trend_capacity' => $trendCapacity,
            'capacity_ratio' => $capacityRatio,
            'roster_trend_share' => $rosterTrendShare,
        ];
    }

    /**
     * The sum in the class comment over every anchored firm on the roster (this firm always, a peer while
     * its record is under a year old): industry supply over demand as the public sees it, and the modelled plant
     * behind it. Trend plant and market grow by trendFactor(), so a revision of the outlook moves both and leaves the
     * believed balance where it was. Against the TRUE demand the roster supplies its plant over each firm's market at
     * the true level, and the fringe the plant it has built toward the belief; at an outlook of zero the two sum to the
     * believed balance. Plant built into the industry from outside it adds to supply: the fringe yields it its share of
     * the market only as fast as the fringe builds (entry_offset).
     *
     * @param array<string, ShareRecord> $records
     * @param array{truth: float, belief: float, fringe: float} $outlook
     * @return array{supply_over_demand: float, roster_supply: float, fringe_supply: float, installed_capacity: float, trend_capacity: float, roster_trend_share: float}
     */
    private function capacityBalance(
        array $records,
        string $ticker,
        float $trendNominalGdp,
        float $totalTime,
        float $secularExcessGrowth,
        int $tick,
        int $ticksPerYear,
        array $outlook,
    ): array {
        $excess = 0.0;
        $installed = 0.0;
        $trendCapacity = 0.0;
        $rosterTrendShare = 0.0;
        $rosterSupply = 0.0;
        $entryOffset = 0.0;
        $surprise = exp($outlook['truth'] - $outlook['belief']);

        foreach ($records as $peerTicker => $record) {
            if ((float) ($record['anchor_capacity_share'] ?? 0.0) <= 0.0) {
                continue;
            }
            if ($peerTicker !== $ticker && $tick - $record['tick'] > $ticksPerYear) {
                continue;
            }

            $growth = self::trendFactor($record, $trendNominalGdp, $secularExcessGrowth, $totalTime, $outlook['belief'] + (float) ($record['segment_outlook_gap'] ?? 0.0));
            $trendPlant = (float) $record['anchor_capacity_share'] * $growth * self::ownPathFactor($record);
            $trendDemand = (float) $record['anchor_demand_share'] * $growth;
            $capacity = max(0.0, (float) $record['capacity']);

            $installed += $capacity;
            $trendCapacity += $trendPlant;
            $excess += ($capacity - $trendPlant) / max(1.0, $trendDemand);
            $rosterSupply += $capacity / max(1.0, $trendDemand * $surprise);
            // Trend plant over trend demand is the anchor share on the record's own path: the growth factor cancels.
            $rosterTrendShare += $trendDemand > 0.0 ? $trendPlant / $trendDemand : 0.0;
            $entryOffset += self::entryOffset($record, $totalTime);
        }

        return [
            'supply_over_demand' => 1.0 + $excess,
            'roster_supply' => $rosterSupply,
            'fringe_supply' => (1.0 - min(1.0, max(0.0, $rosterTrendShare - $entryOffset))) * exp($outlook['fringe'] - $outlook['truth']),
            'installed_capacity' => $installed,
            'trend_capacity' => $trendCapacity,
            'roster_trend_share' => min(1.0, $rosterTrendShare),
        ];
    }

    /**
     * A venture built into the industry that grows its share along its own path takes that share as it took its first:
     * the fringe yields it only as fast as the fringe builds, so the growth adds to supply and weighs on the price.
     * What the fringe still owes it now, plus the share this step of its path adds; nothing for any other record, and
     * nothing added as a venture shrinks, whose share the fringe takes back at once.
     *
     * @param ShareRecord|null $own
     * @return array{entry_offset?: float, entry_time?: float}
     */
    private static function grownEntry(?array $own, ?float $path, float $totalTime): array
    {
        if ($own === null || !isset($own['entry_offset'], $own['path']) || $path === null || (float) ($own['anchor_demand_share'] ?? 0.0) <= 0.0) {
            return [];
        }

        $anchorPath = (float) ($own['anchor_path'] ?? $own['path']);
        $anchorShare = (float) $own['anchor_capacity_share'] / (float) $own['anchor_demand_share'];
        $grown = $anchorShare * (exp($path - $anchorPath) - exp((float) $own['path'] - $anchorPath));

        return ['entry_offset' => self::entryOffset($own, $totalTime) + max(0.0, $grown), 'entry_time' => $totalTime];
    }

    /**
     * The share of the market the fringe has yet to yield to plant built into the industry from outside it: what it
     * owed at entry, fading at the speed the fringe builds. None for any other record.
     *
     * @param ShareRecord $record
     */
    private static function entryOffset(array $record, float $totalTime): float
    {
        return (float) ($record['entry_offset'] ?? 0.0)
            * exp(-CapitalBudgetEngine::CAPITAL_ERROR_CORRECTION_SPEED * max(0.0, $totalTime - (float) ($record['entry_time'] ?? $totalTime)));
    }

    /**
     * What a firm's anchor plant and market have grown into by now as the public sees them: trend nominal GDP times the
     * industry's expected path since the anchor (TimeSeries::fadedExcessIntegral()) and the public's revision of the
     * industry's outlook since then. Every conversion between plant and anchor units goes through here.
     *
     * @param ShareRecord $record
     */
    private static function trendFactor(array $record, float $trendNominalGdp, float $secularExcessGrowth, float $totalTime, float $belief): float
    {
        return $trendNominalGdp * exp(
            TimeSeries::fadedExcessIntegral($secularExcessGrowth, (float) $record['anchor_time'], $totalTime, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS)
            + $belief - (float) ($record['anchor_outlook'] ?? 0.0),
        );
    }

    /**
     * The expected level a record grows by: its industry's outlook, plus the gap a company in several markets filed.
     *
     * @param ShareRecord $record
     */
    private function recordBelief(array $record, string $industry): float
    {
        return $this->outlook($industry)['belief'] + (float) ($record['segment_outlook_gap'] ?? 0.0);
    }

    /**
     * How far a segment whose model plans its own path (OperatingStrategyInterface::segmentPath()) has moved along it
     * since its anchor: its trend plant, and so its share of the market it sells into, rides the path while that market
     * grows as its industry's. One for any other record.
     *
     * @param ShareRecord $record
     */
    private static function ownPathFactor(array $record): float
    {
        return exp((float) ($record['path'] ?? 0.0) - (float) ($record['anchor_path'] ?? $record['path'] ?? 0.0));
    }

    /**
     * The industry's outlook in log (IndustryOutlook): the true level, the level the public expects, and the fringe's
     * plant. All zero for an industry the outlook does not track.
     *
     * @return array{truth: float, belief: float, fringe: float}
     */
    private function outlook(string $industry): array
    {
        $record = $this->store->readOutlook($industry);

        return $record === null
            ? ['truth' => 0.0, 'belief' => 0.0, 'fringe' => 0.0]
            : ['truth' => $record['x'], 'belief' => IndustryOutlook::belief($record), 'fringe' => $record['f']];
    }

    /**
     * The modelled roster's trend share of the market it sells into: the sum of the anchor shares of every
     * record on the roster (a retired firm's hole included until its record ages out). The rest of the
     * market is the competitive fringe that answers the industry price. One at most: a roster that spans
     * more than its market is a closed loop with no fringe left to cede.
     */
    public function resolveRosterTrendShare(Stock $stock, int $tick, int $ticksPerYear): float
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return 0.0;
        }

        return min(1.0, array_sum($this->rosterTrendShares($industry, $ticker, $tick, $ticksPerYear)));
    }

    /**
     * The acquirer's market as a horizontal merger review sees it: the market in annual revenue (its trend
     * demand), its trend share of that market, the roster's concentration index, and the fringe still off
     * the board to buy. The fringe is competitive, many small firms, and adds nothing to the index. Null
     * before the ledger has seen the firm report, when there is no market to measure yet.
     *
     * A market the ledger does not price on plant (a lender sells a yield, not a unit of output) is measured
     * in revenue instead. Section 7 of the Clayton Act reaches every merger, and since 2024 the DOJ reviews
     * bank mergers under the same 2023 Guidelines, so a firm is not exempt from review because its industry
     * has no capacity to count.
     *
     * @return array{market_revenue: float, acquirer_share: float, herfindahl: float, fringe_share: float}|null
     */
    public function describeMergerMarket(Stock $stock, MacroStateDTO $macroState, float $secularExcessGrowth, int $tick, int $ticksPerYear, string $segment = Segments::MAIN): ?array
    {
        // A company that keeps plant by segment buys into one of its segments' markets, reviewed in that market.
        $address = self::segmentAddress($stock, $segment);
        $own = $address === null ? null : ($this->store->readIndustry($address[0])[$address[1]] ?? null);
        if ($address === null || $own === null) {
            return null;
        }
        [$industry, $ticker] = $address;
        if ((float) ($own['anchor_capacity_share'] ?? 0.0) <= 0.0 || (float) ($own['anchor_demand_share'] ?? 0.0) <= 0.0) {
            return $this->describeRevenueMarket($industry, $ticker, $own, $tick, $ticksPerYear);
        }

        $growth = self::trendFactor($own, self::trendNominalGdp($macroState), $secularExcessGrowth, $macroState->totalTime, $this->recordBelief($own, $industry));
        $shares = $this->rosterTrendShares($industry, $ticker, $tick, $ticksPerYear);

        return [
            'market_revenue' => (float) $own['anchor_demand_share'] * $growth,
            'acquirer_share' => min(1.0, $shares[$ticker] ?? 0.0),
            'herfindahl'     => min(1.0, array_sum(array_map(static fn(float $share): float => $share * $share, $shares))),
            'fringe_share'   => max(0.0, 1.0 - array_sum($shares)),
        ];
    }

    /**
     * A market measured in revenue: the firm's own annual revenue over its addressable share sizes it, and
     * every rival that has reported within the year holds its revenue's share of it (the same sizing the
     * rival drain reads, fringeRevenue). Null until the firm has reported a share and a revenue.
     *
     * @param ShareRecord $own
     * @return array{market_revenue: float, acquirer_share: float, herfindahl: float, fringe_share: float}|null
     */
    private function describeRevenueMarket(string $industry, string $ticker, array $own, int $tick, int $ticksPerYear): ?array
    {
        $ownShare = min(1.0, (float) ($own['addressable_share'] ?? 0.0));
        $ownRevenue = (float) ($own['revenue'] ?? 0.0);
        if ($ownShare <= 0.0 || $ownRevenue <= 0.0) {
            return null;
        }

        $marketRevenue = $ownRevenue / $ownShare;
        $shares = [$ticker => $ownShare];
        foreach ($this->store->readIndustry($industry) as $peerTicker => $record) {
            $revenue = (float) ($record['revenue'] ?? 0.0);
            if ($peerTicker === $ticker || $revenue <= 0.0 || $tick - (int) $record['tick'] > $ticksPerYear) {
                continue;
            }
            $shares[(string) $peerTicker] = $revenue / $marketRevenue;
        }

        return [
            'market_revenue' => $marketRevenue,
            'acquirer_share' => $ownShare,
            'herfindahl'     => min(1.0, array_sum(array_map(static fn(float $share): float => $share * $share, $shares))),
            'fringe_share'   => max(0.0, 1.0 - array_sum($shares)),
        ];
    }

    /**
     * Each anchored firm's trend share of the market: its anchor plant over its anchor market, which only a
     * deal moves, along its own path where it has one. This firm always; a peer while its record is under a year old.
     *
     * @return array<string, float>
     */
    private function rosterTrendShares(string $industry, string $ticker, int $tick, int $ticksPerYear): array
    {
        $shares = [];
        foreach ($this->store->readIndustry($industry) as $peerTicker => $record) {
            $demandShare = (float) ($record['anchor_demand_share'] ?? 0.0);
            if ($demandShare <= 0.0 || (float) ($record['anchor_capacity_share'] ?? 0.0) <= 0.0) {
                continue;
            }
            if ($peerTicker !== $ticker && $tick - $record['tick'] > $ticksPerYear) {
                continue;
            }
            $shares[(string) $peerTicker] = (float) $record['anchor_capacity_share'] * self::ownPathFactor($record) / $demandShare;
        }

        return $shares;
    }

    /**
     * No ceiling: an overbuild past the deepest price cut clears as idle plant
     * (FirmEconomics::calculateIndustryClearing).
     */
    private function boundedCapacityRatio(float $supplyOverDemand): float
    {
        return max(self::MIN_INDUSTRY_CAPACITY_RATIO, $supplyOverDemand);
    }

    /**
     * The industry-wide regimes as this firm's report opens. A regime runs from the tick a member started it to its
     * end tick, so a rival reporting later in the quarter is in it from its own next report and for as many quarters.
     * Null for a firm with no industry to share one with.
     */
    public function openIndustryRegimes(Stock $stock, int $tick, int $ticksPerYear): ?IndustryRegimeView
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return null;
        }

        $ticksPerQuarter = max(1.0, $ticksPerYear / FinancialConstants::QUARTERS_PER_YEAR);
        $elapsed = [];
        foreach ($this->store->readRegimes($industry) as $key => $run) {
            if ($tick >= $run['start_tick'] && $tick < $run['end_tick']) {
                $elapsed[$key] = (int) floor(($tick - $run['start_tick']) / $ticksPerQuarter) + 1;
            }
        }

        // Members are the firms still selling into the industry: a retired record keeps its anchor at no revenue. A
        // segment of a company from another industry sells here but runs its own industry's regimes, not this one's.
        $members = 1;
        foreach ($this->store->readIndustry($industry) as $peerTicker => $record) {
            if ($peerTicker !== $ticker && SegmentBooks::ownerOf((string) $peerTicker) === (string) $peerTicker
                && $record['revenue'] > 0.0 && $tick - $record['tick'] <= $ticksPerYear) {
                $members++;
            }
        }

        return new IndustryRegimeView($elapsed, $members);
    }

    /**
     * Books the regimes this report started for the whole industry: each runs its drawn number of quarters from
     * this tick. A regime already running is left as it is.
     */
    public function commitIndustryRegimes(Stock $stock, IndustryRegimeView $view, int $tick, int $ticksPerYear): void
    {
        $industry = $stock->getIndustry();
        if ($industry === null || $industry === '') {
            return;
        }

        $ticksPerQuarter = max(1.0, $ticksPerYear / FinancialConstants::QUARTERS_PER_YEAR);
        $running = $this->store->readRegimes($industry);
        foreach ($view->startedRegimes() as $key => $quarters) {
            $run = $running[$key] ?? null;
            if ($run !== null && $tick >= $run['start_tick'] && $tick < $run['end_tick']) {
                continue;
            }
            $this->store->writeRegime($industry, $key, [
                'start_tick' => $tick,
                'end_tick'   => $tick + (int) round($quarters * $ticksPerQuarter),
            ]);
        }
    }

    /**
     * A failed firm's plant leaves the industry; its demand does not. Its record stays, at no capacity
     * and no revenue but with its anchor, so survivors sell into a market short by its share until the
     * record ages out and the fringe is taken to have filled the gap. This is the consolidation effect.
     */
    public function retireFirm(Stock $stock): void
    {
        $industry = $stock->getIndustry();
        $ticker = $stock->getTicker();
        if ($industry === null || $industry === '' || $ticker === '') {
            return;
        }

        // Every segment's plant leaves its industry with the company, and so does plant it was still building there; a
        // segment sold is the buyer's plant now.
        $pending = SegmentBooks::pending($stock->getEarningsMomentumZ() ?? []);
        foreach (array_keys(SegmentBooks::industries($stock)) as $segment) {
            $address = SegmentBooks::holdsPlant($stock, $segment) || isset($pending[$segment]) ? self::segmentAddress($stock, $segment) : null;
            if ($address !== null) {
                $this->retireRecord($address[0], $address[1]);
            }
        }
    }

    private function retireRecord(string $industry, string $ticker): void
    {
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
            'anchor_outlook'        => (float) ($own['anchor_outlook'] ?? 0.0),
            'addressable_share'     => (float) ($own['addressable_share'] ?? 0.0),
            'tick'                  => (int) $own['tick'],
            'consumed_tick'         => (int) $own['consumed_tick'],
        ] + self::carriedMarks($own));
    }

    /**
     * An acquired off-board company was already selling into this market: the deal changes who owns the
     * plant, not how much the industry supplies. The acquirer's trend plant rises with the capacity it
     * bought and its market stays where it was, so its share of that market rises and the fringe's falls.
     */
    public function recordAcquiredCapacity(Stock $stock, float $annualCapacity, MacroStateDTO $macroState, float $secularExcessGrowth, string $segment = Segments::MAIN): void
    {
        $address = self::segmentAddress($stock, $segment);
        if ($address !== null) {
            $this->transferCapacity($address[0], $address[1], max(0.0, $annualCapacity), $macroState, $secularExcessGrowth);
        }
    }

    /**
     * A segment sold whole to an off-board buyer keeps supplying its market: its record keeps its plant and its anchor
     * but sells nothing for the company and is no longer refreshed, so for the year it stays on the roster the buyer's
     * plant weighs on the price as it did, and once it ages out the fringe supplies the market at trend. The company's
     * other records are untouched.
     */
    public function recordSoldSegment(Stock $stock, string $segment): void
    {
        $address = self::segmentAddress($stock, $segment);
        $own = $address === null ? null : ($this->store->readIndustry($address[0])[$address[1]] ?? null);
        if ($address === null || $own === null) {
            return;
        }

        $own['revenue'] = 0.0;
        $own['gain'] = 0.0;
        $this->store->writeRecord($address[0], $address[1], $own);
    }

    /**
     * The mirror of an acquisition: a division sold to an off-board buyer keeps supplying the market, now
     * from the fringe. The seller's installed and trend plant both fall by the capacity that left.
     */
    public function recordDivestedFraction(Stock $stock, float $fraction, MacroStateDTO $macroState, float $secularExcessGrowth): void
    {
        $fraction = max(0.0, min(1.0, $fraction));
        // A division sold off pro rata takes the same slice of every segment's plant it still holds.
        foreach (SegmentBooks::industries($stock) as $segment => $industry) {
            $address = SegmentBooks::holdsPlant($stock, $segment) ? self::segmentAddress($stock, $segment) : null;
            $own = $address === null ? null : ($this->store->readIndustry($address[0])[$address[1]] ?? null);
            if ($address === null || $own === null) {
                continue;
            }
            $excess = $segment === Segments::MAIN ? $secularExcessGrowth : self::secularExcessGrowth(Sectors::strategyFor($industry), $stock);

            if ((float) ($own['anchor_capacity_share'] ?? 0.0) <= 0.0) {
                $this->transferRevenue($address[0], $address[1], $own, -$fraction * (float) ($own['revenue'] ?? 0.0));
            } else {
                $this->transferCapacity($address[0], $address[1], -$fraction * max(0.0, (float) $own['capacity']), $macroState, $excess);
            }
        }
    }

    /**
     * A company that starts keeping its plant by segment (SegmentBooks) moves its other segments' plant and market out
     * of its main record, which until now carried the whole company: the main record keeps its plant share of both,
     * so its share of its own market, and its excess over trend per unit of that market, stay where they were. The
     * other segments anchor afresh in their own industries at their first pricing. The blend's gap leaves with them,
     * folded into the anchor's outlook so the main record's trend path does not move; so does any change in the secular
     * rate the record grows by, where the company's model grew all its lines on one plant and now grows the main
     * segment's alone.
     *
     * @param float $excessBefore The secular excess the main record grew by on one plant.
     * @param float $excessAfter  The one it grows by with the books open.
     */
    public function openSegmentBooks(Stock $stock, float $mainPlantShare, float $totalTime = 0.0, float $excessBefore = 0.0, float $excessAfter = 0.0): void
    {
        $own = $this->readOwnRecord($stock);
        // Opened once: a report replayed after its tick rolled back finds the main record already split.
        if ($own === null || isset($own['books_main_share'])) {
            return;
        }

        $share = max(0.0, min(1.0, $mainPlantShare));
        foreach (['anchor_capacity_share', 'anchor_demand_share', 'capacity', 'revenue'] as $field) {
            $own[$field] = (float) ($own[$field] ?? 0.0) * $share;
        }
        $anchorTime = (float) ($own['anchor_time'] ?? 0.0);
        $pathChange = TimeSeries::fadedExcessIntegral($excessBefore, $anchorTime, $totalTime, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS)
            - TimeSeries::fadedExcessIntegral($excessAfter, $anchorTime, $totalTime, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS);
        $own['anchor_outlook'] = (float) ($own['anchor_outlook'] ?? 0.0) - (float) ($own['segment_outlook_gap'] ?? 0.0) - $pathChange;
        $own['books_main_share'] = $share;
        unset($own['segment_outlook_gap']);

        $this->store->writeRecord((string) $stock->getIndustry(), $stock->getTicker(), $own);
    }

    /**
     * Anchors the record of a segment a company starts building into a new industry: its trend plant is the plant it
     * builds, none of it installed yet, at its share of the industry's market. That plant adds to the industry's supply
     * as it is placed in service, since the fringe yields it its share of the market only as fast as the fringe builds
     * (entry_offset; capacityBalance()); bought plant was the fringe's already and needs no such record. Its trend
     * plant then follows the venture's own path from where it entered (SegmentBooks::venturePath()).
     *
     * @param float $annualCapacity   The revenue its plant will earn a year at full use and the balanced price.
     * @param float $addressableShare Its share of its industry's market once built.
     */
    public function openVentureRecord(Stock $stock, string $segment, float $annualCapacity, float $addressableShare, MacroStateDTO $macroState, int $tick): void
    {
        $this->openEnteredRecord($stock, $segment, $annualCapacity, $addressableShare, $macroState, $tick, built: true);
    }

    /**
     * Anchors the record of a segment a company has just bought in a new industry, at the deal and not its next report,
     * so that a second deal or another entrant before then finds it there: its trend plant is the plant it bought, all
     * of it installed, at its share of the industry's market. That plant was the fringe's and already supplied the
     * market, so the fringe yields its share at once (no entry_offset), and the record follows its industry from here.
     *
     * @param float $annualCapacity   The revenue the bought plant earns a year at full use and the balanced price.
     * @param float $addressableShare Its share of its industry's market.
     */
    public function openAcquiredRecord(Stock $stock, string $segment, float $annualCapacity, float $addressableShare, MacroStateDTO $macroState, int $tick): void
    {
        $this->openEnteredRecord($stock, $segment, $annualCapacity, $addressableShare, $macroState, $tick, built: false);
    }

    /**
     * Writes the anchor of a segment entered in play: its trend plant at its share of its industry's market as the
     * public now sees it. Plant bought is installed at once; plant built is none of it yet, and the fringe yields it
     * its share only as fast as the fringe builds, along the venture's own path.
     */
    private function openEnteredRecord(Stock $stock, string $segment, float $annualCapacity, float $addressableShare, MacroStateDTO $macroState, int $tick, bool $built): void
    {
        $address = self::segmentAddress($stock, $segment);
        $trendNominalGdp = self::trendNominalGdp($macroState);
        if ($address === null || $annualCapacity <= 0.0 || $addressableShare <= 0.0 || $trendNominalGdp <= 0.0) {
            return;
        }

        $share = min(1.0, $addressableShare);
        $installed = $built ? 0.0 : $annualCapacity;
        $record = [
            'revenue'               => $installed,
            'gain'                  => 0.0,
            'capacity'              => $installed,
            'anchor_capacity_share' => $annualCapacity / $trendNominalGdp,
            'anchor_demand_share'   => $annualCapacity / ($share * $trendNominalGdp),
            'anchor_time'           => $macroState->totalTime,
            'anchor_outlook'        => $this->outlook($address[0])['belief'],
            'addressable_share'     => $share,
            'tick'                  => $tick,
            'consumed_tick'         => $tick,
        ];
        if ($built) {
            $record['entry_offset'] = $share;
            $record['entry_time'] = $macroState->totalTime;
            $record['path'] = 0.0;
            $record['anchor_path'] = 0.0;
        }
        $this->store->writeRecord($address[0], $address[1], $record);
    }

    /**
     * Moves installed and trend plant together, which leaves the firm's excess over trend — and with it the
     * industry balance — exactly where it was. A firm not yet priced has no anchor to move; its first report
     * anchors whatever it then owns. A sale larger than the trend plant left clears the anchor, and the next
     * report strikes a fresh one.
     */
    private function transferCapacity(string $industry, string $ticker, float $capacityDelta, MacroStateDTO $macroState, float $secularExcessGrowth): void
    {
        $own = $this->store->readIndustry($industry)[$ticker] ?? null;
        $trendNominalGdp = self::trendNominalGdp($macroState);
        if ($own === null || $capacityDelta === 0.0) {
            return;
        }
        if ((float) ($own['anchor_capacity_share'] ?? 0.0) <= 0.0) {
            $this->transferRevenue($industry, $ticker, $own, $capacityDelta);

            return;
        }

        $growth = self::trendFactor($own, $trendNominalGdp, $secularExcessGrowth, $macroState->totalTime, $this->recordBelief($own, $industry)) * self::ownPathFactor($own);
        $own['anchor_capacity_share'] = max(0.0, (float) $own['anchor_capacity_share'] + ($capacityDelta / $growth));
        $own['capacity'] = max(0.0, (float) $own['capacity'] + $capacityDelta);

        $this->store->writeRecord($industry, $ticker, $own);
    }

    /**
     * A market measured in revenue moves with the revenue a deal buys or a sale gives up, at the market's
     * size, so a second deal before the next report is reviewed at the share the first one left.
     *
     * @param ShareRecord $own
     */
    private function transferRevenue(string $industry, string $ticker, array $own, float $revenueDelta): void
    {
        $share = (float) ($own['addressable_share'] ?? 0.0);
        $revenue = (float) ($own['revenue'] ?? 0.0);
        if ($share <= 0.0 || $revenue <= 0.0) {
            return;
        }

        $newRevenue = max(0.0, $revenue + $revenueDelta);
        $own['revenue'] = $newRevenue;
        $own['addressable_share'] = min(1.0, $newRevenue / ($revenue / $share));

        $this->store->writeRecord($industry, $ticker, $own);
    }

    /**
     * Where a segment's record sits: the main segment's under the ticker in the company's industry, another's under
     * its own key in its industry, and only once the company keeps plant by segment. Null for a company with no
     * industry or ticker.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function segmentAddress(Stock $stock, string $segment): ?array
    {
        $industry = SegmentBooks::industries($stock)[$segment] ?? '';
        $ticker = $stock->getTicker();
        if ($industry === '' || $ticker === '') {
            return null;
        }
        if ($segment !== Segments::MAIN && SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) === null) {
            return null;
        }

        return [$industry, SegmentBooks::recordKey($ticker, $segment)];
    }

    /** @return ShareRecord|null */
    private function readOwnRecord(Stock $stock): ?array
    {
        $industry = $stock->getIndustry();
        if ($industry === null || $industry === '' || $stock->getTicker() === '') {
            return null;
        }

        return $this->store->readIndustry($industry)[$stock->getTicker()] ?? null;
    }
}
