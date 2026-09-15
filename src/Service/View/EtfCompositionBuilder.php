<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Market\EtfTracker;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\IndexCommittee;
use App\Service\Market\PriceChangeFeed;
use App\Service\Math\FinancialConstants;

/**
 * Builds the factsheet shown on an index fund's page: what it holds, in what weight, and how that is decided.
 *
 * The fund tracks an INDEX, not the whole board, so its composition is the index's standing membership —
 * and a constituent's weight is its share of the members' FLOAT-adjusted capitalisation, because what a
 * passive fund can actually hold is the part of a company that trades. Listing every company instead was
 * describing a market capitalisation rather than a fund: it showed holdings in names the fund does not own
 * and weights struck on stock it could not buy.
 *
 * Derived rather than stored, like the index level itself. Before the first reconstitution there is no
 * membership, and the fund is then the whole live board — which is exactly what it was.
 *
 * The watch list draws the committee's own bands (IndexCommittee::bands) rather than its own: the page
 * saying who is at risk and who is in contention has to agree with the decision that will actually be made.
 */
class EtfCompositionBuilder
{
    // --- Factsheet ---

    /** Constituents whose combined weight is quoted as the index's concentration, the standard factsheet figure. */
    public const CONCENTRATION_TOP_N = 10;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly IndexCommittee $indexCommittee,
        private readonly EtfTracker $etfTracker,
        private readonly PriceChangeFeed $priceChangeFeed,
        private readonly \Redis $redis,
        private readonly int $ticksPerYear,
    ) {}

    /**
     * @return array{
     *     allAssets: list<Stock>,
     *     pieLabels: list<string>,
     *     pieData: list<float>,
     *     sharesMap: array<string, float>,
     *     components: list<array{rank: int, ticker: string, name: string, sector: string, price: float, marketCap: float, floatCap: float, weight: float, changePercent: float|null, isNew: bool}>,
     *     indexFacts: array<string, mixed>
     * }
     */
    public function build(MarketIndex $index): array
    {
        $allAssets = $this->stocks->findAll();
        $roster = $this->indexCommittee->currentRoster($index);
        $members = $roster === null ? [] : array_fill_keys($roster['tickers'], true);

        // Every live name ranked on float, members or not: the membership is a cut of this ranking, and the
        // watch list is about who stands near the cut.
        $capByTicker = [];
        $stockByTicker = [];
        foreach ($allAssets as $stock) {
            if ($stock->isBankrupt()) {
                // A delisted shell is not a holding; counting it would dilute every live weight.
                continue;
            }

            $ticker = (string) $stock->getTicker();
            $cap = IndexCommittee::floatAdjustedCap($stock);
            if ($cap <= 0.0) {
                continue;
            }

            $capByTicker[$ticker] = $cap;
            $stockByTicker[$ticker] = $stock;
        }
        arsort($capByTicker);
        $rankOf = array_flip(array_keys($capByTicker));

        // Only what the index carries. An empty membership is a market that has not reconstituted yet, and
        // there the fund is still the whole board.
        $memberCaps = [];
        foreach ($capByTicker as $ticker => $cap) {
            if ($members === [] || isset($members[$ticker])) {
                $memberCaps[$ticker] = $cap;
            }
        }
        $totalFloatCap = array_sum($memberCaps);

        $memberStocks = array_values(array_intersect_key($stockByTicker, $memberCaps));
        $changes = $this->priceChangeFeed->changeByTicker($memberStocks);
        $added = $roster === null ? [] : array_fill_keys($roster['added'], true);

        $pieLabels = [];
        $pieData = [];
        $sharesMap = [];
        $components = [];
        $sectorTotals = [];
        $sectorCounts = [];

        foreach ($memberCaps as $ticker => $floatCap) {
            $stock = $stockByTicker[$ticker];
            $weight = $totalFloatCap > 0.0 ? ($floatCap / $totalFloatCap) * 100.0 : 0.0;
            $sector = $stock->getSector();

            $pieLabels[] = $ticker;
            $pieData[] = $floatCap;
            // FLOAT-adjusted shares, so a live repaint of the pie stays on the same basis the weights were
            // struck on rather than sliding back to whole-company capitalisation as prices tick.
            $sharesMap[$ticker] = (float) $stock->getSharesOutstanding() * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));

            $components[] = [
                'rank' => $rankOf[$ticker] + 1,
                'ticker' => $ticker,
                'name' => $stock->getName(),
                'sector' => $sector,
                'price' => (float) $stock->getPrice(),
                'marketCap' => (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(),
                'floatCap' => $floatCap,
                'weight' => $weight,
                'changePercent' => $changes[$ticker] ?? null,
                'isNew' => isset($added[$ticker]),
            ];

            $sectorTotals[$sector] = ($sectorTotals[$sector] ?? 0.0) + $weight;
            $sectorCounts[$sector] = ($sectorCounts[$sector] ?? 0) + 1;
        }

        arsort($sectorTotals);
        $sectorWeights = [];
        foreach ($sectorTotals as $sector => $weight) {
            $sectorWeights[] = ['sector' => $sector, 'weight' => $weight, 'count' => $sectorCounts[$sector]];
        }

        $topWeight = 0.0;
        foreach (array_slice($components, 0, self::CONCENTRATION_TOP_N) as $component) {
            $topWeight += $component['weight'];
        }

        return [
            'allAssets' => $allAssets,
            'pieLabels' => $pieLabels,
            'pieData' => $pieData,
            'sharesMap' => $sharesMap,
            'components' => $components,
            'indexFacts' => [
                'ticker' => $index->value,
                'label' => $index->label(),
                'mandate' => $index->mandate(),
                'isSelective' => $index->isSelective(),
                'carriesPassiveBook' => $index->carriesPassiveBook(),
                'constituentCount' => $index->constituentCount(),
                'memberCount' => count($components),
                'listedCount' => count($capByTicker),
                'totalFloatCap' => $totalFloatCap,
                'topWeight' => $topWeight,
                'topN' => min(self::CONCENTRATION_TOP_N, count($components)),
                'largest' => $components[0] ?? null,
                'sectorWeights' => $sectorWeights,
                'divisor' => $this->etfTracker->currentDivisor($index->value),
                'reconstitutionsPerYear' => FinancialConstants::INDEX_RECONSTITUTIONS_PER_YEAR,
                'added' => $roster['added'] ?? [],
                'deleted' => $roster['deleted'] ?? [],
                'hasRoster' => $roster !== null,
            ] + $this->calendar($roster) + $this->watchList($index, $capByTicker, $rankOf, $members, $stockByTicker),
        ];
    }

    /**
     * Where the index stands on its reconstitution calendar, in simulated days.
     *
     * @param array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>}|null $roster
     * @return array{lastReconstitutionTick: int|null, daysSinceReconstitution: float|null, daysUntilReconstitution: float}
     */
    private function calendar(?array $roster): array
    {
        $tick = (int) ($this->redis->get('simulation_tick_count') ?: 0);
        $interval = IndexCommittee::intervalTicks($this->ticksPerYear);
        $daysPerTick = 365.0 / $this->ticksPerYear;

        $nextTick = (intdiv($tick, $interval) + 1) * $interval;

        return [
            'lastReconstitutionTick' => $roster['tick'] ?? null,
            'daysSinceReconstitution' => $roster === null ? null : max(0, $tick - $roster['tick']) * $daysPerTick,
            'daysUntilReconstitution' => ($nextTick - $tick) * $daysPerTick,
        ];
    }

    /**
     * Who stands near the boundary of a selective index, on the committee's own bands.
     *
     * A member ranked outside the inner band is exposed: it is the first to be displaced when an outsider
     * qualifies, and it is dropped outright once it falls past the outer band. An outsider ranked inside
     * the outer band is close enough to watch, and one already inside the inner band WILL be admitted at
     * the next review. Neither list means anything for a whole-board index, which has no boundary.
     *
     * @param array<string, float> $capByTicker Every live name, best-ranked first.
     * @param array<string, int>   $rankOf      Zero-based rank per ticker.
     * @param array<string, true>  $members
     * @param array<string, Stock> $stockByTicker
     * @return array{bands: array{inner: int, outer: int}|null, atRisk: list<array<string, mixed>>, contenders: list<array<string, mixed>>}
     */
    private function watchList(MarketIndex $index, array $capByTicker, array $rankOf, array $members, array $stockByTicker): array
    {
        $count = $index->constituentCount();

        if ($count === null || $members === []) {
            return ['bands' => null, 'atRisk' => [], 'contenders' => []];
        }

        $bands = IndexCommittee::bands($count);
        $atRisk = [];
        $contenders = [];

        foreach ($capByTicker as $ticker => $cap) {
            $rank = $rankOf[$ticker];
            $row = [
                'ticker' => $ticker,
                'name' => $stockByTicker[$ticker]->getName(),
                'rank' => $rank + 1,
                'floatCap' => $cap,
            ];

            if (isset($members[$ticker])) {
                if ($rank >= $bands['inner']) {
                    $atRisk[] = $row + ['exits' => $rank >= $bands['outer']];
                }
            } elseif ($rank < $bands['outer']) {
                $contenders[] = $row + ['qualifies' => $rank < $bands['inner']];
            }
        }

        return ['bands' => $bands, 'atRisk' => $atRisk, 'contenders' => $contenders];
    }
}
