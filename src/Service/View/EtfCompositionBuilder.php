<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Market\EtfTracker;
use App\Service\Market\Index\IndexRanking;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\IndexCommittee;
use App\Service\Market\PriceChangeFeed;
use App\Service\Math\FinancialConstants;

/**
 * Builds the factsheet shown on an index fund's page: what it holds, in what weight, and how that is decided.
 *
 * The fund tracks an INDEX, not the whole board, so its composition is the index's standing membership, and
 * a constituent's weight is its share of the members' float-adjusted capitalisation CARRYING THE FACTOR the
 * committee fixed at the last review. Both halves matter. Float, because what a passive fund can actually
 * hold is the part of a company that trades. The factor, because a low-volatility fund does not hold its
 * members by size at all, and a capped sector fund holds its largest name at less than its size — a page
 * that ignored the factors would print a portfolio the fund is not running.
 *
 * The weights shown are therefore the CURRENT ones, drifted on prices since the review, which is what the
 * fund actually holds today; each row also carries the target it was set to at the review, and the gap
 * between the two is the drift a real fund only removes at the next rebalance.
 *
 * Derived rather than stored, like the index level itself. Before the first reconstitution there is no
 * membership, and the fund is then its whole eligible universe — which is exactly what it was.
 *
 * The universe and the ranking are the index's own: a sector fund is ranked against its sector and a
 * low-volatility fund against the quiet, so the page never tells a constituent it is ranked 41st on a
 * measure its index does not select on. The watch list draws the committee's own bands
 * (IndexCommittee::bands) rather than its own: the page saying who is at risk and who is in contention has
 * to agree with the decision that will actually be made.
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
     *     components: list<array{rank: int, ticker: string, name: string, sector: string, price: float, marketCap: float, floatCap: float, adjustedCap: float, volatility: float, weight: float, targetWeight: float|null, changePercent: float|null, isNew: bool}>,
     *     indexFacts: array<string, mixed>
     * }
     */
    public function build(MarketIndex $index, ?Etf $fund = null): array
    {
        $allAssets = $this->stocks->findAll();
        $roster = $this->indexCommittee->currentRoster($index);
        $members = $roster === null ? [] : array_fill_keys($roster['tickers'], true);
        $factors = $roster === null ? [] : $roster['factors'];
        $targets = $roster === null ? [] : $roster['weights'];

        // The index's OWN eligible universe: its sector if it has one, the whole live board otherwise. The
        // membership is a cut of this, and the watch list is about who stands near the cut — neither means
        // anything measured against companies the index was never allowed to hold.
        $sector = $index->sector();
        $capByTicker = [];
        $volByTicker = [];
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

            // A standing member that has left the universe is still a holding until the next review, so it
            // is carried here rather than vanishing from the fund's own factsheet.
            if ($sector !== null && $stock->getSector() !== $sector && !isset($members[$ticker])) {
                continue;
            }

            $capByTicker[$ticker] = $cap;
            $volByTicker[$ticker] = IndexCommittee::trailingVolatility($stock);
            $stockByTicker[$ticker] = $stock;
        }

        // Ranked on what this index actually selects on, so a low-volatility fund's page ranks the quiet
        // rather than the large.
        $rankOf = $this->rankUniverse($index, $capByTicker, $volByTicker);

        // Only what the index carries. An empty membership is a market that has not reconstituted yet, and
        // there the fund is still its whole universe.
        //
        // ADJUSTED capitalisation, not raw: the factor fixed at the last review is what turns a pile of
        // float caps into the portfolio the committee decided on, and the weights below are shares of the
        // adjusted total. For a cap-weighted index every factor is one and this is the arithmetic it always
        // was.
        $memberCaps = [];
        $adjustedCaps = [];
        foreach ($capByTicker as $ticker => $cap) {
            if ($members !== [] && !isset($members[$ticker])) {
                continue;
            }

            $memberCaps[$ticker] = $cap;
            $adjustedCaps[$ticker] = $cap * ($factors[$ticker] ?? 1.0);
        }
        arsort($adjustedCaps);
        $memberCaps = array_intersect_key($memberCaps, $adjustedCaps);
        $totalFloatCap = array_sum($memberCaps);
        $totalAdjustedCap = array_sum($adjustedCaps);

        $memberStocks = array_values(array_intersect_key($stockByTicker, $adjustedCaps));
        $changes = $this->priceChangeFeed->changeByTicker($memberStocks);
        $added = $roster === null ? [] : array_fill_keys($roster['added'], true);

        $pieLabels = [];
        $pieData = [];
        $sharesMap = [];
        $components = [];
        $sectorTotals = [];
        $sectorCounts = [];

        foreach ($adjustedCaps as $ticker => $adjustedCap) {
            $stock = $stockByTicker[$ticker];
            $weight = $totalAdjustedCap > 0.0 ? ($adjustedCap / $totalAdjustedCap) * 100.0 : 0.0;
            $sector = $stock->getSector();

            $pieLabels[] = $ticker;
            $pieData[] = $adjustedCap;
            // Float-adjusted shares CARRYING THE FACTOR, so a live repaint of the pie stays on the basis the
            // weights were struck on rather than sliding back to whole-company capitalisation as prices tick.
            $sharesMap[$ticker] = (float) $stock->getSharesOutstanding()
                * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()))
                * ($factors[$ticker] ?? 1.0);

            $components[] = [
                'rank' => ($rankOf[$ticker] ?? count($rankOf)) + 1,
                'ticker' => $ticker,
                'name' => $stock->getName(),
                'sector' => $sector,
                'price' => (float) $stock->getPrice(),
                'marketCap' => (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(),
                'floatCap' => $memberCaps[$ticker],
                // The same capitalisation carrying the weight factor: what the weight above is a share OF.
                // The live table falls back to this for any name a frame did not carry, so it has to be on
                // the same footing as the figures the frame does carry, or a weighted index's bars would mix
                // two different bases in one total.
                'adjustedCap' => $adjustedCap,
                'volatility' => $volByTicker[$ticker],
                'weight' => $weight,
                // What the review set this name to. The gap against the weight above is the drift a real
                // fund carries between rebalances rather than trading away for free.
                'targetWeight' => isset($targets[$ticker]) ? $targets[$ticker] * 100.0 : null,
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
                'indexName' => $index->indexName(),
                'indexProvider' => MarketIndex::PROVIDER,
                'indexProviderTicker' => MarketIndex::PROVIDER_TICKER,
                // The publisher sitting in its own index is a disclosable conflict, so the page states it
                // where it is true rather than asserting it everywhere: a sector index the publisher is not
                // eligible for has no such problem.
                'providerIsConstituent' => isset($members[MarketIndex::PROVIDER_TICKER])
                    || ($members === [] && isset($capByTicker[MarketIndex::PROVIDER_TICKER])),
                'mandate' => $index->mandate(),
                'isSelective' => $index->isSelective(),
                'carriesPassiveBook' => $index->carriesPassiveBook(),
                'passiveShare' => $index->passiveShare() * 100.0,
                'constituentCount' => $index->constituentCount(),
                'memberCount' => count($components),
                'listedCount' => count($capByTicker),
                'universe' => $index->sector() ?? 'the whole listed board',
                'rankingLabel' => $index->ranking()->label(),
                'weightingLabel' => $index->weighting()->label(),
                'weightingDescription' => $index->weighting()->description(),
                'isCapped' => $index->weightCap() !== null,
                'maxConstituentWeight' => ($index->weightCap() ?? 0.0) * 100.0,
                'appliesConcentrationBudget' => $index->appliesConcentrationBudget(),
                'screensEarnings' => $index->requiresEarningsViability(),
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
            ] + $this->fundFacts($fund) + $this->calendar($roster) + $this->watchList($index, $capByTicker, $rankOf, $members, $stockByTicker),
        ];
    }

    /**
     * What the fund costs and what it has paid, which is everything that separates it from its index.
     *
     * A page that showed only the index's composition was describing a number. These figures are the fund:
     * the fee it charges, the income it has passed on over the trailing year, and what the fee has actually
     * come to since it opened. None of it is an estimate; every figure is read off the fund's own books.
     *
     * The fee is reported as cash per share rather than as a gap against the index, because that gap does
     * not show up where an unwary reader would look for it. The fee is met out of dividend income before
     * that income is ever distributed, so the fund's HOLDINGS are untouched in an ordinary market and its
     * price tracks the index exactly — while its holders receive a smaller distribution every quarter for
     * as long as they own it.
     *
     * @return array<string, mixed>
     */
    private function fundFacts(?Etf $fund): array
    {
        if ($fund === null) {
            return [
                'expenseRatio' => null,
                'distributionYield' => null,
                'trailingDistribution' => null,
                'lastDistributionAt' => null,
                'accruedIncome' => null,
                'feesPaidPerShare' => null,
                'tradingCostsPerShare' => null,
                'trackingDifference' => null,
            ];
        }

        $price = (float) $fund->getPrice();

        return [
            'expenseRatio' => $fund->getExpenseRatio() * 100.0,
            'distributionYield' => $price > 0.0 ? ($fund->trailingDistribution() / $price) * 100.0 : 0.0,
            'trailingDistribution' => $fund->trailingDistribution(),
            'lastDistributionAt' => $fund->getLastDistributionAt(),
            // Income collected and not yet handed over. It is in the price, and it leaves it at the next
            // distribution — so a holder reading the chart should know it is there.
            'accruedIncome' => $fund->getAccruedIncome(),
            // What the fund has actually charged over its life, per share. This is the honest cost figure:
            // almost all of it comes out of income before the income is ever distributed, so a holder who
            // only watched the price would never see it leave.
            'feesPaidPerShare' => $fund->getCumulativeFeesPaid(),
            // What following the index has cost in spread, per share, over the fund's life. A different cost
            // with a different cause: the fee is what the manager charges, this is what the index's own
            // turnover costs to track. A cap-weighted fund pays almost none of it, because its weights
            // maintain themselves; a fund that restrikes its weights every quarter pays it every quarter.
            'tradingCostsPerShare' => $fund->getCumulativeTradingCosts(),
            // How far the basket has fallen behind the one index unit a share started with, as a percentage.
            // This is the fund's cumulative tracking difference and it only ever grows: a fund never buys
            // back what it sold. Both costs above can land here — the fee only when income failed to cover
            // it, a rebalance always, because a spread is paid inside the trade.
            'trackingDifference' => (1.0 - $fund->getBasketPerShare()) * 100.0,
        ];
    }

    /**
     * The eligible universe in the index's own selection order, best first.
     *
     * The committee's ranking, reproduced rather than reinvented: a page that ranked a low-volatility fund's
     * members by size would put a watch list in front of the reader that has nothing to do with the decision
     * the committee will actually make at the next review.
     *
     * @param array<string, float> $capByTicker
     * @param array<string, float> $volByTicker
     * @return array<string, int> Zero-based rank per ticker.
     */
    private function rankUniverse(MarketIndex $index, array $capByTicker, array $volByTicker): array
    {
        if ($index->ranking() === IndexRanking::TrailingVolatility) {
            $metric = $volByTicker;
            asort($metric);

            return array_flip(array_keys($metric));
        }

        $metric = $capByTicker;
        arsort($metric);

        return array_flip(array_keys($metric));
    }

    /**
     * Where the index stands on its reconstitution calendar, in simulated days.
     *
     * @param array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>, weights: array<string, float>, factors: array<string, float>}|null $roster
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
     * @param array<string, float> $capByTicker Every name in the index's eligible universe.
     * @param array<string, int>   $rankOf      Zero-based rank per ticker, on the index's own criterion.
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

        // Walked in rank order rather than in whatever order the repository returned, so both lists read
        // from the boundary outwards — which is the order the committee will work through them in.
        $order = $rankOf;
        asort($order);

        foreach (array_keys($order) as $ticker) {
            $cap = $capByTicker[$ticker] ?? 0.0;
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
