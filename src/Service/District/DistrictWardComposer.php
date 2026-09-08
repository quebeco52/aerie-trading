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
 * Three independent decisions, easy to conflate: *qualification* is a live ranking (top
 * DistrictMap::STREET_ROSTER_SIZE by market cap, recomputed on every request — no company is ever
 * authored onto or off of the row), *position* is authored (DistrictMap::FRONTAGE_ORDER groups
 * same-model tenants together so conduit fans read as bundles, then breaks ties by market cap),
 * and *row* is pure legibility (the frontage wraps like text so the canvas stays narrow enough to
 * render at a readable scale). See DistrictMap's class docblock for why geometry itself is
 * display-only and never feeds the simulation.
 */
class DistrictWardComposer
{
    /**
     * Selects the District's current top tenants by market cap, lays out plot geometry across
     * them in authored frontage order, and wraps that frontage into rows.
     *
     * @param  Stock[] $stocks Candidate stocks; entries whose business model is not in
     *                         DistrictMap::FRONTAGE_ORDER are ignored (defensive — every model
     *                         actually carried by a listed company is required to be present).
     * @return array{
     *     slots: list<array{ticker: string, x: int, width: int, row: int, rank: int}>,
     *     viewboxWidth: int,
     *     rowCount: int,
     *     nextInLine: array{ticker: string, name: string, marketCap: float}|null,
     * }
     */
    public function composeFrontage(array $stocks): array
    {
        $orderIndex = array_flip(DistrictMap::FRONTAGE_ORDER);

        $ranked = [];
        foreach ($stocks as $stock) {
            $businessModel = Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
            if (!isset($orderIndex[$businessModel])) {
                continue;
            }

            $ranked[] = [
                'stock' => $stock,
                'rank' => $orderIndex[$businessModel],
                'marketCap' => (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(),
            ];
        }

        // Qualification: the DistrictMap::STREET_ROSTER_SIZE largest by market cap alone.
        usort($ranked, static fn (array $a, array $b) => $b['marketCap'] <=> $a['marketCap']);
        $qualified = array_slice($ranked, 0, DistrictMap::STREET_ROSTER_SIZE);

        // The first company that missed the cut, shown in the summary bar as next in line — the
        // clearest way to make "the roster changes over time" visible rather than theoretical.
        $nextInLine = $this->describeNextInLine($ranked);

        // Street rank is the market-cap position, captured before the re-sort below reorders the
        // list for display. Rank 1 is the largest company on the street.
        foreach ($qualified as $position => $entry) {
            $qualified[$position]['streetRank'] = $position + 1;
        }

        // Position: re-sort the qualifying tenants into authored frontage order.
        usort($qualified, static function (array $a, array $b): int {
            return $a['rank'] <=> $b['rank'] ?: $b['marketCap'] <=> $a['marketCap'];
        });

        $widths = array_map(
            static fn (array $entry): int => DistrictMap::plotWidthForImportance($entry['stock']->getSystemicImportance()),
            $qualified,
        );

        $rowCount = count($qualified) >= DistrictMap::ROW_SPLIT_THRESHOLD ? DistrictMap::ROW_COUNT : 1;
        $splitIndex = $rowCount > 1 ? $this->balancedSplitIndex($widths) : count($qualified);

        $slots = [];
        $rowWidths = [];
        $x = DistrictMap::FRONTAGE_GUTTER;
        $row = 0;

        foreach ($qualified as $i => $entry) {
            if ($i === $splitIndex) {
                $rowWidths[] = $x - DistrictMap::FRONTAGE_GUTTER - DistrictMap::FRONTAGE_GAP;
                $x = DistrictMap::FRONTAGE_GUTTER;
                $row++;
            }

            $slots[] = [
                'ticker' => $entry['stock']->getTicker(),
                'x' => $x,
                'width' => $widths[$i],
                'row' => $row,
                'rank' => $entry['streetRank'],
            ];

            $x += $widths[$i] + DistrictMap::FRONTAGE_GAP;
        }

        $rowWidths[] = $slots === [] ? 0 : $x - DistrictMap::FRONTAGE_GUTTER - DistrictMap::FRONTAGE_GAP;

        return [
            'slots' => $slots,
            'viewboxWidth' => DistrictMap::FRONTAGE_GUTTER + max($rowWidths) + DistrictMap::FRONTAGE_MARGIN,
            'rowCount' => $rowCount,
            'nextInLine' => $nextInLine,
        ];
    }

    /**
     * Finds the wrap point whose two rows render closest in width. A balanced split rather than a
     * fixed midpoint because plot widths vary 100..190: a titan-heavy prefix can leave a fixed
     * split's canvas up to ~200 units wider than it needs to be, and canvas width is precisely
     * what sets the street's rendered pixels-per-unit. Sequence is never reordered — the authored
     * frontage order survives the wrap intact.
     *
     * @param  list<int> $widths plot widths in frontage order
     * @return int index of the first slot on the second row
     */
    private function balancedSplitIndex(array $widths): int
    {
        $count = count($widths);
        $narrowest = PHP_INT_MAX;
        $splitIndex = $count;

        for ($candidate = 1; $candidate < $count; $candidate++) {
            $widest = max(
                $this->rowWidth($widths, 0, $candidate),
                $this->rowWidth($widths, $candidate, $count),
            );

            // Strict, so the lowest qualifying index wins and the split stays deterministic.
            if ($widest < $narrowest) {
                $narrowest = $widest;
                $splitIndex = $candidate;
            }
        }

        return $splitIndex;
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

    /**
     * @param  list<array{stock: Stock, rank: int, marketCap: float}> $ranked full market-cap ranking
     * @return array{ticker: string, name: string, marketCap: float}|null
     */
    private function describeNextInLine(array $ranked): ?array
    {
        $next = $ranked[DistrictMap::STREET_ROSTER_SIZE] ?? null;
        if ($next === null) {
            return null;
        }

        return [
            'ticker' => $next['stock']->getTicker(),
            'name' => (string) $next['stock']->getName(),
            'marketCap' => $next['marketCap'],
        ];
    }
}
