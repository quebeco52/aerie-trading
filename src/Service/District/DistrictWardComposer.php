<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\Entity\Stock;

/**
 * Derives Glasswater Row's street frontage — which of the District's listed companies currently
 * qualify, where each one stands, and which row it stands on — from live company fundamentals.
 *
 * Three independent decisions, easy to conflate: *qualification* is a ranking (top
 * DistrictMap::STREET_ROSTER_SIZE by market cap — no company is ever authored onto or off of the
 * row), *position* is authored (DistrictMap::FRONTAGE_ORDER groups same-model tenants together so
 * conduit fans read as bundles, then breaks ties by market cap), and *row* is pure legibility
 * (the frontage wraps like text so the canvas stays narrow enough to render at a readable scale).
 * See DistrictMap's class docblock for why geometry itself is display-only and never feeds the
 * simulation.
 *
 * Qualification is taken once per reconstitution (composeFrontage(), called by DistrictRoster on
 * the quarter tick) and the resulting street order is frozen; every page render in between goes
 * through composeFrontageForRoster() with that stored order, so two players see the same street
 * and a reload never moves a building. Only the street RANK plates are live.
 */
class DistrictWardComposer
{
    /**
     * Ranks the listed universe and lays out the top DistrictMap::STREET_ROSTER_SIZE — the
     * reconstitution step. Bankrupt companies never qualify: a defunct shell only stands on the
     * street because it was solvent when the roster was last taken.
     *
     * @param  Stock[] $stocks Candidate stocks; entries whose business model is not in
     *                         DistrictMap::FRONTAGE_ORDER are ignored (defensive — every model
     *                         actually carried by a listed company is required to be present).
     * @return array{
     *     slots: list<array{ticker: string, x: int, width: int, row: int, rank: int}>,
     *     viewboxWidth: int,
     *     rowCount: int,
     * }
     */
    public function composeFrontage(array $stocks): array
    {
        $ranked = [];
        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }
            $entry = $this->describe($stock);
            if ($entry !== null) {
                $ranked[] = $entry;
            }
        }

        // Qualification: the DistrictMap::STREET_ROSTER_SIZE largest by market cap alone.
        usort($ranked, static fn (array $a, array $b) => $b['marketCap'] <=> $a['marketCap']);
        $qualified = array_slice($ranked, 0, DistrictMap::STREET_ROSTER_SIZE);

        // Position: re-sort the qualifying tenants into authored frontage order, market cap
        // breaking ties inside a business-model run.
        usort($qualified, static function (array $a, array $b): int {
            return $a['order'] <=> $b['order'] ?: $b['marketCap'] <=> $a['marketCap'];
        });

        return $this->layout($qualified);
    }

    /**
     * Lays out a street whose membership AND order were fixed at the last reconstitution. The
     * stored order is honoured exactly — a tenant that has since outgrown its neighbour keeps its
     * plot until the next reconstitution — while the street rank on each plate is recomputed from
     * live market caps, because that is the figure the client re-ranks on every tick anyway. A
     * stored ticker no longer listed is skipped and the street closes up around it.
     *
     * @param  Stock[]      $stocks  The listed universe (only roster members are used).
     * @param  list<string> $tickers Street order as stored by DistrictRoster.
     * @return array{
     *     slots: list<array{ticker: string, x: int, width: int, row: int, rank: int}>,
     *     viewboxWidth: int,
     *     rowCount: int,
     * }
     */
    public function composeFrontageForRoster(array $stocks, array $tickers): array
    {
        $byTicker = [];
        foreach ($stocks as $stock) {
            $byTicker[$stock->getTicker()] = $stock;
        }

        $roster = [];
        foreach ($tickers as $ticker) {
            $stock = $byTicker[$ticker] ?? null;
            if ($stock === null) {
                continue;
            }
            $entry = $this->describe($stock);
            if ($entry !== null) {
                $roster[] = $entry;
            }
        }

        return $this->layout($roster);
    }

    /**
     * The street order as composeFrontage() would lay it out, without the geometry — what
     * DistrictRoster stores at a reconstitution.
     *
     * @param  Stock[] $stocks
     * @return list<string>
     */
    public function rosterOrder(array $stocks): array
    {
        return array_map(static fn (array $slot) => $slot['ticker'], $this->composeFrontage($stocks)['slots']);
    }

    /**
     * @return array{stock: Stock, order: int, marketCap: float, district: string}|null null when the business model has no authored place on the street
     */
    private function describe(Stock $stock): ?array
    {
        $orderIndex = array_flip(DistrictMap::FRONTAGE_ORDER);
        $businessModel = Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
        if (!isset($orderIndex[$businessModel])) {
            return null;
        }

        return [
            'stock' => $stock,
            'order' => $orderIndex[$businessModel],
            'marketCap' => (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(),
            'district' => DistrictMap::districtFor($businessModel) ?? '',
        ];
    }

    /**
     * Geometry for tenants already in street order: street rank from market cap, plot widths from
     * systemic importance, then the balanced wrap across DistrictMap::rowCountForTenants() rows.
     *
     * @param  list<array{stock: Stock, order: int, marketCap: float, district: string}> $tenants in street order
     * @return array{
     *     slots: list<array{ticker: string, x: int, width: int, row: int, rank: int}>,
     *     viewboxWidth: int,
     *     rowCount: int,
     * }
     */
    private function layout(array $tenants): array
    {
        // Street rank is the live market-cap position among the tenants; rank 1 is the largest.
        // A bankrupt shell's cap is whatever its last print says, which is the honest reading.
        $byCap = $tenants;
        usort($byCap, static fn (array $a, array $b) => $b['marketCap'] <=> $a['marketCap']);
        $streetRank = [];
        foreach ($byCap as $position => $entry) {
            $streetRank[$entry['stock']->getTicker()] = $position + 1;
        }

        $widths = array_map(
            static fn (array $entry): int => DistrictMap::plotWidthForImportance($entry['stock']->getSystemicImportance()),
            $tenants,
        );

        $rowCount = DistrictMap::rowCountForTenants(count($tenants));
        $splitIndices = $this->wrapIndices($widths, $rowCount, array_column($tenants, 'district'));

        $slots = [];
        $rowWidths = [];
        $x = DistrictMap::FRONTAGE_GUTTER;
        $row = 0;

        foreach ($tenants as $i => $entry) {
            if (in_array($i, $splitIndices, true)) {
                $rowWidths[] = $x - DistrictMap::FRONTAGE_GUTTER - DistrictMap::FRONTAGE_GAP;
                $x = DistrictMap::FRONTAGE_GUTTER;
                $row++;
            }

            $ticker = $entry['stock']->getTicker();
            $slots[] = [
                'ticker' => $ticker,
                'x' => $x,
                'width' => $widths[$i],
                'row' => $row,
                'rank' => $streetRank[$ticker],
            ];

            $x += $widths[$i] + DistrictMap::FRONTAGE_GAP;
        }

        $rowWidths[] = $slots === [] ? 0 : $x - DistrictMap::FRONTAGE_GUTTER - DistrictMap::FRONTAGE_GAP;

        return [
            'slots' => $slots,
            'viewboxWidth' => DistrictMap::FRONTAGE_GUTTER + max($rowWidths) + DistrictMap::FRONTAGE_MARGIN,
            'rowCount' => $rowCount,
        ];
    }

    /**
     * Where the street wraps: the tightest partition that also leaves every district whole, when
     * one exists within DistrictMap::DISTRICT_WRAP_SLACK of the tightest partition of all.
     *
     * A district cut in half by a row break prints its bracket twice and reads as two places —
     * the oil houses did exactly that, closing one stave and opening the next. Balance still has
     * the final say: the widest row sets the street's pixels-per-unit, so a boundary-aligned wrap
     * that costs more than the slack is refused and the unconstrained partition stands.
     *
     * @param  list<int>    $widths    plot widths in frontage order
     * @param  int          $rows      staves to wrap across
     * @param  list<string> $districts each plot's district, in the same order
     * @return list<int>    index of the first slot on each row after the first, ascending
     */
    private function wrapIndices(array $widths, int $rows, array $districts): array
    {
        $balanced = $this->balancedSplitIndices($widths, $rows);
        if ($balanced === []) {
            return $balanced;
        }

        $boundaries = [];
        foreach ($districts as $i => $district) {
            if ($i > 0 && $district !== $districts[$i - 1]) {
                $boundaries[] = $i;
            }
        }

        if ($boundaries === []) {
            return $balanced;
        }

        $aligned = $this->balancedSplitIndices($widths, $rows, $boundaries);
        if ($aligned === []) {
            return $balanced;
        }

        $budget = $this->widestRow($widths, $balanced) * (1.0 + DistrictMap::DISTRICT_WRAP_SLACK);

        return $this->widestRow($widths, $aligned) <= $budget ? $aligned : $balanced;
    }

    /**
     * Rendered width of the widest row under a given set of wrap points.
     *
     * @param list<int> $widths
     * @param list<int> $splitIndices
     */
    private function widestRow(array $widths, array $splitIndices): int
    {
        $widest = 0;
        $from = 0;
        foreach ([...$splitIndices, count($widths)] as $to) {
            $widest = max($widest, $this->rowWidth($widths, $from, $to));
            $from = $to;
        }

        return $widest;
    }

    /**
     * Finds the wrap points whose rows render closest in width — the partition that minimises the
     * widest row, since the widest row alone sets the viewBox and with it the street's rendered
     * pixels-per-unit. Balanced rather than an equal count per row because plot widths vary
     * 100..190: a titan-heavy prefix can leave an even split's canvas a couple of hundred units
     * wider than it needs to be. Sequence is never reordered — the authored frontage order
     * survives the wrap intact, so this is a partition of a fixed sequence into contiguous runs.
     *
     * Solved exactly (the classic minimax partition of a sequence) rather than greedily: with one
     * wrap point a sweep sufficed, but from two onward a greedy fill of the first row commits to
     * a prefix the later rows cannot compensate for. best[$r][$i] is the narrowest achievable
     * widest row when slots from $i onward are laid across $r rows.
     *
     * @param  list<int>       $widths        plot widths in frontage order
     * @param  int              $rows          staves to wrap across
     * @param  list<int>|null   $allowedSplits wrap only at these indices (district edges); null to wrap anywhere
     * @return list<int>        index of the first slot on each row after the first, ascending; empty when the
     *                          restriction admits no partition at all
     */
    private function balancedSplitIndices(array $widths, int $rows, ?array $allowedSplits = null): array
    {
        $count = count($widths);
        if ($rows < 2 || $count < 2) {
            return [];
        }

        $permitted = $allowedSplits === null ? null : array_flip($allowedSplits);

        // One row left: the rest of the street goes on it, whatever that costs.
        $best = [1 => []];
        $from = [1 => []];
        for ($i = 0; $i < $count; $i++) {
            $best[1][$i] = $this->rowWidth($widths, $i, $count);
        }

        $reachable = [1 => array_fill(0, $count, true)];

        for ($r = 2; $r <= $rows; $r++) {
            $best[$r] = [];
            $from[$r] = [];
            $reachable[$r] = [];
            // Leave at least one slot for each of the $r - 1 rows below this one.
            for ($i = 0; $i <= $count - $r; $i++) {
                $narrowest = PHP_INT_MAX;
                $splitIndex = $i + 1;
                for ($j = $i + 1; $j <= $count - ($r - 1); $j++) {
                    if ($permitted !== null && !isset($permitted[$j])) {
                        continue;
                    }

                    $widest = max($this->rowWidth($widths, $i, $j), $best[$r - 1][$j]);

                    // Strict, so the lowest qualifying index wins and the wrap stays deterministic.
                    if ($widest < $narrowest) {
                        $narrowest = $widest;
                        $splitIndex = $j;
                    }
                }
                $best[$r][$i] = $narrowest;
                $from[$r][$i] = $splitIndex;
                $reachable[$r][$i] = $narrowest !== PHP_INT_MAX;
            }
        }

        if (($reachable[$rows][0] ?? false) === false) {
            return [];
        }

        $indices = [];
        $i = 0;
        for ($r = $rows; $r > 1; $r--) {
            if (($reachable[$r][$i] ?? false) === false) {
                return [];
            }

            $i = $from[$r][$i];
            $indices[] = $i;
        }

        return $indices;
    }

    /**
     * Rendered width of the slots in [$from, $to): their own widths plus the gaps between them.
     *
     * @param list<int> $widths
     */
    private function rowWidth(array $widths, int $from, int $to): int
    {
        $total = 0;
        for ($i = $from; $i < $to; $i++) {
            $total += $widths[$i];
        }

        return $total + max(0, $to - $from - 1) * DistrictMap::FRONTAGE_GAP;
    }
}
