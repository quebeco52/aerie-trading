<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

use App\Entity\Stock;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Ticker\TickCadence;
use App\Service\Math\FinancialConstants;

/**
 * Decides who is in each index and in what weight, and keeps its level continuous when that changes.
 *
 *   - MEMBERSHIP, drawn from the index's own eligible universe and ranked best-first on the index's own criterion
 *     (size for a broad index, quiet for a low-volatility one, a sector for a sector fund), so one banding rule
 *     serves every index.
 *   - FLOAT ADJUSTMENT: what a passive fund can buy is the part of a company that trades.
 *   - WEIGHTS and the adjusted-weight FACTOR that carries them. A non-cap-weighted index is run as a modified
 *     capitalisation index: a factor per constituent is set at the review so the weights come out right that day,
 *     then DRIFT with prices until the next one, as S&P's non-cap-weighted indices do.
 *   - THE DIVISOR, restated whenever composition or weights change, so the level moves only on prices: the index
 *     measures the market's return, not the arithmetic of its own composition.
 *
 * Membership is BANDED: a sitting member is not evicted the first time a marginal name edges past it, because
 * ranking noise at the boundary would churn the passive book. A whole-board composite has no seats to contest (its
 * membership is everyone alive) but reconstitutes on the same calendar so its divisor absorbs deaths.
 *
 * The committee does not push the inclusion trade: the passive book is held by IndexFundStrategy, so an addition is
 * bought and a deletion sold by the same machinery that trades everything else, which makes the downward-sloping
 * demand curve of Shleifer (1986) emergent.
 */
final class IndexCommittee
{
    /**
     * Passes the diversification cap is worked over. Each pass is a fixed point of one rule that may break the other;
     * it converges in two or three.
     */
    private const CAP_ITERATIONS = 12;

    // --- Market Index Membership (Shleifer 1986) ---
    /**
     * Banding around the cut, as a fraction of the constituent count. A sitting member is not evicted the first time a
     * marginal name edges past it: real indices band precisely because ranking noise at the boundary would otherwise
     * churn the whole passive book twice a year for nothing.
     */
    public const INDEX_MEMBERSHIP_BUFFER = 0.20;
    /**
     * Floor on the trailing volatility an inverse-volatility weighting divides by. A name that has gone quiet enough to
     * divide by nothing would otherwise take the whole fund.
     */
    public const INDEX_MINIMUM_WEIGHT_VOLATILITY = 0.04;

    // --- Passive Assets by Index (share of the indexed book each published index carries) ---
    /**
     * Ceiling on how much passive ownership a single name can carry relative to its weight in the market. A name held
     * by every fund at once is still only so much of anyone's book.
     */
    public const INDEX_MAX_PASSIVE_OWNERSHIP_MULTIPLE = 4.0;

    // --- Index Fund Accounting ---
    /**
     * Smallest rebalance worth charging for, as a fraction of the fund. Below this the trade is the rounding on a
     * weight that barely moved, and charging it would write a cost row every quarter for nothing.
     */
    public const FUND_MINIMUM_REBALANCE_TURNOVER = 0.0005;

    public function __construct(
        private readonly IndexMembershipStoreInterface $store,
        private readonly EtfTracker $etfTracker,
        private readonly LiquidityEngine $liquidityEngine,
    ) {}

    /** Ticks between reconstitutions. */
    public static function intervalTicks(int $ticksPerYear): int
    {
        return TickCadence::intervalTicks($ticksPerYear, FinancialConstants::INDEX_RECONSTITUTIONS_PER_YEAR);
    }

    /** Whether this tick is a reconstitution boundary. Tick 0 is one: a fresh market takes its first roster. */
    public static function isReconstitutionTick(int $tick, int $ticksPerYear): bool
    {
        return $tick % self::intervalTicks($ticksPerYear) === 0;
    }

    /**
     * What a passive fund can actually buy of a company: the part of it that trades.
     */
    public static function floatAdjustedCap(Stock $stock): float
    {
        if ($stock->isBankrupt()) {
            return 0.0;
        }

        $float = max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));

        return (float) $stock->getPrice() * (float) $stock->getSharesOutstanding() * $float;
    }

    /**
     * The volatility an index selects and weights on: what the name has REALIZED over the trailing window.
     *
     * A volatility screen is a trailing measurement: S&P's low-volatility index ranks on a year of daily
     * returns, and a year is what `realizedVarianceEma` carries, not the instantaneous variance state.
     *
     * The state is the fallback, and the structural figure the fallback's fallback, for the market's first
     * year before there is a window to measure. Floored, because an inverse-volatility weight divides by this.
     */
    public static function trailingVolatility(Stock $stock): float
    {
        $vol = $stock->getRealizedVolatility()
            ?? (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility());

        return max(self::INDEX_MINIMUM_WEIGHT_VOLATILITY, $vol);
    }

    /**
     * Whether a company is profitable enough to be ADMITTED to an index that screens on it.
     *
     * Both halves of the S&P 500's viability test: the quarterly history holds the last four reported quarters,
     * so their sum is the trailing twelve-month figure and the last is the most recent quarter. A company with
     * no reported history yet cannot pass.
     */
    private static function isEarningsViable(Stock $stock): bool
    {
        $history = $stock->getQuarterlyNetIncomeHistory();

        if ($history === null || $history === []) {
            return false;
        }

        $quarters = array_map('floatval', array_values($history));
        $latest = $quarters[count($quarters) - 1];

        return array_sum($quarters) > 0.0 && $latest > 0.0;
    }

    /**
     * The two ranks that band a selective index's boundary.
     *
     * An incumbent keeps its seat while it ranks ABOVE the outer band; an outsider earns one only once it
     * ranks INSIDE the inner band. Ranks are zero-based, so "inside" means strictly less than the figure.
     * Published because the page that shows who is at risk and who is in contention has to draw the same
     * lines the committee does, not its own.
     *
     * @return array{inner: int, outer: int}
     */
    public static function bands(int $constituentCount): array
    {
        $buffer = self::INDEX_MEMBERSHIP_BUFFER;

        return [
            'inner' => (int) round($constituentCount * (1.0 - $buffer)),
            'outer' => (int) round($constituentCount * (1.0 + $buffer)),
        ];
    }

    /**
     * The standing roster of an index as stored: who is in, when it was taken, what changed then, and the
     * weights the review struck.
     *
     * @return array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>, weights: array<string, float>, factors: array<string, float>}|null
     */
    public function currentRoster(MarketIndex $index): ?array
    {
        return $this->store->current($index);
    }

    /**
     * The ADJUSTED capitalisation of an index's standing membership: what its level is struck from.
     *
     * Each constituent's float-adjusted capitalisation carries the factor fixed at the last review, so a
     * cap-weighted index sums exactly what it always did — every factor there is one — and a weighted index
     * sums the portfolio the committee actually decided on. A constituent with no factor on record is
     * carried at one.
     *
     * @param array<string, float> $capByTicker Float-adjusted capitalisation per ticker.
     */
    public function memberCapitalisation(MarketIndex $index, array $capByTicker): float
    {
        return $this->adjustedMemberSum($index, $capByTicker);
    }

    /**
     * The dividend cash the index's members paid this tick, on the same adjusted basis as the level.
     *
     * A fund holding the index receives this; the index itself does not show it, because a price index
     * measures prices. Divided by the same divisor the level is struck on, it is the index dividend in index
     * points, which is how a total-return index is built from a price one.
     *
     * @param array<string, float> $pointsByTicker Dividend rate x float-adjusted shares, per ticker.
     */
    public function memberDividendPoints(MarketIndex $index, array $pointsByTicker): float
    {
        return $this->adjustedMemberSum($index, $pointsByTicker);
    }

    /**
     * What one index unit of this basket costs to get into and out of: the members' half-spreads, weighted
     * the way the fund actually holds them.
     *
     * This sets how far the fund tracking it may drift before an authorized participant closes the gap. It is
     * read off the live book, so a fund whose constituents have seized up may trade at a discount.
     *
     * @param array<string, float> $capByTicker        Float-adjusted capitalisation per ticker, the weights.
     * @param array<string, float> $halfSpreadByTicker Half-spread per ticker, as a fraction.
     */
    public function memberWeightedHalfSpread(MarketIndex $index, array $capByTicker, array $halfSpreadByTicker): float
    {
        $roster = $this->store->current($index);
        $tickers = $roster !== null ? $roster['tickers'] : array_keys($capByTicker);

        $weighted = 0.0;
        $total = 0.0;

        foreach ($tickers as $ticker) {
            $weight = ($capByTicker[$ticker] ?? 0.0) * ($roster['factors'][$ticker] ?? 1.0);

            if ($weight <= 0.0) {
                continue;
            }

            $weighted += $weight * ($halfSpreadByTicker[$ticker] ?? 0.0);
            $total += $weight;
        }

        return $total > 0.0 ? $weighted / $total : 0.0;
    }

    /**
     * Index weight per member, normalized to one. What a creation basket is split along.
     *
     * @param array<string, float> $capByTicker Float-adjusted capitalisation per ticker.
     * @return array<string, float>
     */
    public function memberWeights(MarketIndex $index, array $capByTicker): array
    {
        $roster = $this->store->current($index);
        $tickers = $roster !== null ? $roster['tickers'] : array_keys($capByTicker);

        $weights = [];

        foreach ($tickers as $ticker) {
            $weight = ($capByTicker[$ticker] ?? 0.0) * ($roster['factors'][$ticker] ?? 1.0);

            if ($weight > 0.0) {
                $weights[$ticker] = $weight;
            }
        }

        return $weights;
    }

    /**
     * Sums a per-ticker quantity over the index's members, each carrying the factor fixed at the last
     * review.
     *
     * Capitalisation and dividend income go through the same arithmetic because they have to: the level and
     * the income must be struck on the same holdings, or the yield the fund reports is measured against a
     * portfolio it does not own.
     *
     * @param array<string, float> $valueByTicker
     */
    private function adjustedMemberSum(MarketIndex $index, array $valueByTicker): float
    {
        $roster = $this->store->current($index);

        if ($roster === null) {
            return array_sum($valueByTicker);
        }

        $total = 0.0;

        foreach ($roster['tickers'] as $ticker) {
            $total += ($valueByTicker[$ticker] ?? 0.0) * ($roster['factors'][$ticker] ?? 1.0);
        }

        return $total;
    }

    /**
     * How much passive money each name carries, relative to how much of the market that name is.
     *
     * Passive assets are not one pool behind one benchmark: they sit in the vehicles that exist, and those
     * disagree on what to hold. The multiple returned here is the ratio of the two —
     *
     *     sum over indices of (that index's share of indexed money x the name's weight in it)
     *     -------------------------------------------------------------------------------------
     *                          the name's weight in the market itself
     *
     * — so it reads 1.0 for a name held exactly in line with its size, above 1.0 for one several funds
     * overweight, and 0.0 for one no published index holds. The index shares and each index's weights sum to
     * one, so the capitalisation-weighted average of the multiple is exactly one: a REALLOCATION of passive money
     * across names, not an inflation of it.
     *
     * Struck from the frozen rosters, a weight fixed at the last rebalance. An empty return means the market's own
     * roster has not been taken yet, and the caller should treat every name as held.
     *
     * @return array<string, float>
     */
    public function passiveOwnership(): array
    {
        $market = $this->store->current(MarketIndex::market());

        if ($market === null || $market['weights'] === []) {
            return [];
        }

        $held = [];

        foreach (MarketIndex::cases() as $index) {
            $share = $index->passiveShare();

            if ($share <= 0.0) {
                continue;
            }

            $roster = $index === MarketIndex::market() ? $market : $this->store->current($index);

            if ($roster === null) {
                continue;
            }

            foreach ($roster['weights'] as $ticker => $weight) {
                $held[$ticker] = ($held[$ticker] ?? 0.0) + ($share * $weight);
            }
        }

        $multiples = [];

        foreach ($market['weights'] as $ticker => $marketWeight) {
            if ($marketWeight <= 0.0) {
                continue;
            }

            $multiples[$ticker] = min(
                self::INDEX_MAX_PASSIVE_OWNERSHIP_MULTIPLE,
                ($held[$ticker] ?? 0.0) / $marketWeight,
            );
        }

        return $multiples;
    }

    /**
     * Re-ranks the index's universe, sets a new membership and a new set of weights, and restates the
     * divisor so the level does not move.
     *
     * @param array<int, Stock> $stocks         The listed universe.
     * @param float|null        $lastKnownLevel The level the index's fund last printed, when one is on
     *                                          record. Read only when the committee's own state is missing.
     * @return array{tickers: list<string>, added: list<string>, deleted: list<string>, weights: array<string, float>, factors: array<string, float>, divisor: float, level: float, turnover: float, trading_cost: float}
     */
    public function reconstitute(MarketIndex $index, array $stocks, int $tick, ?float $lastKnownLevel = null): array
    {
        $previous = $this->store->current($index);
        $previousMembers = $previous === null ? [] : $previous['tickers'];
        $previousFactors = $previous === null ? [] : $previous['factors'];
        $previousDivisor = $this->etfTracker->currentDivisor($index->value);

        // The eligible universe, and the two measurements every index needs off it: what each name is worth
        // to a fund that can only buy the float, and how much it has been moving.
        $sector = $index->sector();

        // Two maps: ELIGIBLE is what the index may hold (its sector, or the whole board) and the new membership is
        // selected out of it; the wider map also carries a standing member that has left the universe, since the
        // level being preserved was struck on it.
        $eligible = [];
        $admissible = [];
        $capByTicker = [];
        $volByTicker = [];
        $stockByTicker = [];
        $standing = array_fill_keys($previousMembers, true);
        $screensEarnings = $index->requiresEarningsViability();

        foreach ($stocks as $stock) {
            $cap = self::floatAdjustedCap($stock);

            if ($cap <= 0.0) {
                continue;
            }

            $ticker = (string) $stock->getTicker();

            // A name outside the sector is not ranked low, it is not ranked at all. The universe is the
            // mandate, and a sector fund holding the least-bad industrial would not be a sector fund.
            $isEligible = $sector === null || $stock->getSector() === $sector;

            if (!$isEligible && !isset($standing[$ticker])) {
                continue;
            }

            $capByTicker[$ticker] = $cap;
            $volByTicker[$ticker] = self::trailingVolatility($stock);
            $stockByTicker[$ticker] = $stock;

            if ($isEligible) {
                $eligible[$ticker] = $cap;

                // Screened on ADMISSION only: an incumbent that has fallen into losses still holds its seat
                // until the bands take it; it simply could not get back in.
                if (!$screensEarnings || self::isEarningsViable($stock)) {
                    $admissible[$ticker] = true;
                }
            }
        }

        // The level as it stands, before anything changes. Everything below exists to make sure this is
        // also the level immediately after.
        $levelBefore = $this->levelBefore($previousMembers, $previousFactors, $previousDivisor, $capByTicker, $lastKnownLevel);

        $ranked = $this->rank($index->ranking(), $eligible, $volByTicker);
        $tickers = $this->selectMembers($index->constituentCount(), $ranked, $previousMembers, $admissible);

        $weights = $this->strikeWeights($index, $tickers, $eligible, $volByTicker);
        $factors = $this->adjustedWeightFactors($weights, $eligible);

        $newCap = 0.0;
        foreach ($tickers as $ticker) {
            $newCap += ($eligible[$ticker] ?? 0.0) * ($factors[$ticker] ?? 1.0);
        }

        // The first roster of a fresh market is a listing, not a change: nothing was added to anything.
        $added = $previousMembers === [] ? [] : array_values(array_diff($tickers, $previousMembers));
        $deleted = array_values(array_diff($previousMembers, $tickers));

        // What the fund tracking this index has to trade to get from the portfolio it is holding to the one
        // just decided, and what crossing the spread on that trade costs it.
        $rebalance = $this->rebalanceCost(
            $previousMembers,
            $previousFactors,
            $capByTicker,
            $weights,
            $stockByTicker,
        );

        $this->store->store($index, $tick, $tickers, $added, $deleted, $weights, $factors);

        // Divisor restatement: adjust index divisor to keep index level continuous across composition changes.
        $target = $levelBefore > 0.0 ? $levelBefore : FinancialConstants::INDEX_BASE_LEVEL;

        $divisor = $newCap > 0.0 ? $newCap / $target : $previousDivisor;

        if ($divisor !== null && $divisor > 0.0) {
            $this->etfTracker->restateDivisor($index->value, $divisor);
        }

        return [
            'tickers' => $tickers,
            'added' => $added,
            'deleted' => $deleted,
            'weights' => $weights,
            'factors' => $factors,
            'divisor' => $divisor ?? 0.0,
            'level' => $divisor !== null && $divisor > 0.0 ? $newCap / $divisor : $levelBefore,
            'turnover' => $rebalance['turnover'],
            'trading_cost' => $rebalance['cost'],
        ];
    }

    /**
     * The index level immediately before a reconstitution.
     *
     * Struck from the previous membership, its factors and its divisor when all are on record. Otherwise the
     * fund's last printed level stands in, one tick stale; a market with neither opens at the base.
     *
     * @param list<string>         $previousMembers
     * @param array<string, float> $previousFactors
     * @param array<string, float> $capByTicker
     */
    private function levelBefore(
        array $previousMembers,
        array $previousFactors,
        ?float $previousDivisor,
        array $capByTicker,
        ?float $lastKnownLevel,
    ): float {
        if ($previousMembers === [] || $previousDivisor === null || $previousDivisor <= 0.0) {
            return $lastKnownLevel !== null && $lastKnownLevel > 0.0 ? $lastKnownLevel : 0.0;
        }

        $cap = 0.0;
        foreach ($previousMembers as $ticker) {
            $cap += ($capByTicker[$ticker] ?? 0.0) * ($previousFactors[$ticker] ?? 1.0);
        }

        return $cap / $previousDivisor;
    }

    /**
     * What the review costs the fund that has to follow it.
     *
     * An index changes its weights by arithmetic and pays nothing. A fund holding the index has to TRADE from
     * the drifted portfolio it carries into the review to the one the committee just decided, crossing the
     * spread in every name it touches. A rebalance sells what has drifted up and buys what has drifted down, so
     * at mid it would be free money on mean-reverting prices. A cap-weighted index maintains its own weights and
     * only trades the names that joined or left.
     *
     * TURNOVER is stated one-way (half the sum of the weight changes, the factsheet convention). The COST is not
     * halved: both sides are real and each crossing pays its own half-spread at its own name's liquidity.
     *
     * Only the spread is charged, not market impact (the fund's assets are not modelled in shares), so the charge
     * is a FLOOR on what following the index costs. The first review of a fresh market is free: a fund is created
     * in kind.
     * @param list<string>          $previousMembers The roster the fund is holding as the review opens.
     * @param array<string, float>  $previousFactors The factors those holdings were struck with.
     * @param array<string, float>  $capByTicker     Float-adjusted capitalisation, now.
     * @param array<string, float>  $weights         The target weights the review has just decided.
     * @param array<string, Stock>  $stockByTicker   The names themselves, for what each costs to cross.
     * @return array{turnover: float, cost: float} One-way turnover, and the cost as a fraction of the fund.
     */
    private function rebalanceCost(
        array $previousMembers,
        array $previousFactors,
        array $capByTicker,
        array $weights,
        array $stockByTicker,
    ): array {
        $nothing = ['turnover' => 0.0, 'cost' => 0.0];

        if ($previousMembers === [] || $weights === []) {
            return $nothing;
        }

        // Drifted portfolio weights: calculate weights from current market prices to measure turnover from actual
        // holdings.
        $held = [];
        $heldTotal = 0.0;

        foreach ($previousMembers as $ticker) {
            $value = ($capByTicker[$ticker] ?? 0.0) * ($previousFactors[$ticker] ?? 1.0);

            if ($value <= 0.0) {
                continue;
            }

            $held[$ticker] = $value;
            $heldTotal += $value;
        }

        if ($heldTotal <= 0.0) {
            return $nothing;
        }

        foreach ($held as $ticker => $value) {
            $held[$ticker] = $value / $heldTotal;
        }

        $traded = 0.0;
        $cost = 0.0;

        foreach (array_keys($held + $weights) as $ticker) {
            $delta = abs(($weights[$ticker] ?? 0.0) - ($held[$ticker] ?? 0.0));

            if ($delta <= 0.0) {
                continue;
            }

            $stock = $stockByTicker[$ticker] ?? null;

            $traded += $delta;
            $cost += $delta * ($stock === null
                ? FinancialConstants::MIN_HALF_SPREAD
                : $this->liquidityEngine->halfSpreadFraction($stock));
        }

        $turnover = $traded / 2.0;

        // Below the threshold the "trade" is the rounding on a weight that barely moved. Charging it would
        // write a cost against the fund every quarter for a rebalance nobody would have bothered placing.
        return $turnover < self::FUND_MINIMUM_REBALANCE_TURNOVER
            ? $nothing
            : ['turnover' => $turnover, 'cost' => $cost];
    }

    /**
     * The eligible universe in selection order, best first.
     *
     * @param array<string, float> $capByTicker
     * @param array<string, float> $volByTicker
     * @return list<string>
     */
    private function rank(IndexRanking $ranking, array $capByTicker, array $volByTicker): array
    {
        if ($ranking === IndexRanking::TrailingVolatility) {
            $metric = array_intersect_key($volByTicker, $capByTicker);
            // Quietest first: for this index, low IS the good end of the ranking.
            asort($metric);

            return array_keys($metric);
        }

        arsort($capByTicker);

        return array_keys($capByTicker);
    }

    /**
     * Picks the membership from a ranking that is already in best-first order.
     *
     * With no seat count the index takes its whole eligible universe — the composite's whole board, the
     * sector fund's whole sector. Nothing else applies; there is no boundary to band.
     *
     * With one, banding is TWO thresholds. A sitting member keeps its place until it falls past the outer band,
     * so ranking noise at the boundary cannot churn the passive book. A name that is not in gets in only once it
     * has risen inside the INNER band, and when it does it displaces the weakest member. One threshold is not
     * enough: with only the outer band, incumbents fill every slot; with only the inner, the boundary flaps.
     *
     * A bankrupt shell is not ranked at all (no float-adjusted capitalisation), so it leaves at the first
     * reconstitution after it dies. So does a name that has left the index's universe.
     *
     * ADMISSIBILITY applies in one direction only: the screen (the headline index requires profitability) gates
     * the two places a name can enter and is never consulted about an incumbent, which keeps its seat until the
     * bands take it.
     *
     * @param list<string>        $ranked
     * @param list<string>        $previousMembers
     * @param array<string, true> $admissible Names eligible to be ADMITTED; incumbents need not appear.
     * @return list<string>
     */
    private function selectMembers(?int $target, array $ranked, array $previousMembers, array $admissible): array
    {
        if ($target === null) {
            return $ranked;
        }

        $rankOf = array_flip($ranked);
        ['inner' => $innerBand, 'outer' => $outerBand] = self::bands($target);

        $wasMember = array_fill_keys($previousMembers, true);
        $candidates = [];

        // Incumbents that have not fallen out of the outer band keep their claim.
        foreach ($previousMembers as $ticker) {
            if (isset($rankOf[$ticker]) && $rankOf[$ticker] < $outerBand) {
                $candidates[$ticker] = true;
            }
        }

        // Outsiders that have risen inside the inner band earn one, if the index will have them.
        foreach ($ranked as $ticker) {
            if (!isset($wasMember[$ticker]) && $rankOf[$ticker] < $innerBand && isset($admissible[$ticker])) {
                $candidates[$ticker] = true;
            }
        }

        $members = array_keys($candidates);
        usort($members, static fn(string $a, string $b): int => $rankOf[$a] <=> $rankOf[$b]);

        // More claims than seats: the weakest of them lose, which is how an addition displaces someone.
        $members = array_slice($members, 0, $target);

        // Fewer claims than seats — a small market, or one that has just lost several names — so the best
        // of whoever is left fills the rest rather than leaving the index short. Admissible names first:
        // filling a seat is an admission like any other and the screen applies to it.
        $held = array_fill_keys($members, true);

        foreach ([true, false] as $screened) {
            foreach ($ranked as $ticker) {
                if (count($members) >= $target) {
                    break 2;
                }

                if (isset($held[$ticker]) || ($screened && !isset($admissible[$ticker]))) {
                    continue;
                }

                $members[] = $ticker;
                $held[$ticker] = true;
            }
        }

        usort($members, static fn(string $a, string $b): int => $rankOf[$a] <=> $rankOf[$b]);

        return $members;
    }

    /**
     * The target weight of each constituent, summing to one.
     *
     * @param list<string>         $tickers
     * @param array<string, float> $capByTicker
     * @param array<string, float> $volByTicker
     * @return array<string, float>
     */
    private function strikeWeights(MarketIndex $index, array $tickers, array $capByTicker, array $volByTicker): array
    {
        $raw = [];

        foreach ($tickers as $ticker) {
            $raw[$ticker] = match ($index->weighting()) {
                IndexWeighting::FloatCapitalisation => $capByTicker[$ticker] ?? 0.0,
                // The reciprocal of risk, so the quietest names carry the most. Floored upstream, because
                // an unfloored estimate lets one briefly-still name take the whole fund.
                IndexWeighting::InverseVolatility => 1.0 / max(
                    self::INDEX_MINIMUM_WEIGHT_VOLATILITY,
                    $volByTicker[$ticker] ?? self::INDEX_MINIMUM_WEIGHT_VOLATILITY,
                ),
            };
        }

        $total = array_sum($raw);

        if ($total <= 0.0) {
            return [];
        }

        $weights = [];
        foreach ($raw as $ticker => $value) {
            $weights[$ticker] = $value / $total;
        }

        $cap = $index->weightCap();

        return $cap === null
            ? $weights
            : $this->applyWeightCap($weights, $cap, $index->appliesConcentrationBudget());
    }

    /**
     * Caps a set of weights, and where the index asks for it, applies the concentration budget as well.
     *
     * Rule one, always: no constituent above the single-name cap, with what comes off redistributed pro rata
     * to whoever is not at the cap. Rule two, for an index that asks: the constituents above the
     * concentration threshold limited to the budget in combination — the regulated-investment-company test
     * that exists because a fund can satisfy a per-name cap and still be a bet on one industry.
     *
     * Both are worked REPEATEDLY: redistributing the largest name's excess pro rata can push the second name
     * through the cap it was just under, and relieving the budget does the same in the other direction.
     *
     * A rule that CANNOT be satisfied (a six-company sector cannot put only 45% of itself in the names above 4.5%;
     * a fund of four cannot respect a 22.5% cap) is not applied, rather than applied until the weights stop
     * summing to one.
     *
     * @param array<string, float> $weights
     * @param float                $maxWeight   Most any one constituent may weigh.
     * @param bool                 $withBudget  Whether the concentration budget applies on top.
     * @return array<string, float>
     */
    private function applyWeightCap(array $weights, float $maxWeight, bool $withBudget): array
    {
        $count = count($weights);
        $threshold = FinancialConstants::INDEX_CONCENTRATION_THRESHOLD;
        $budget = FinancialConstants::INDEX_CONCENTRATION_BUDGET;

        // The single-name cap needs enough names to absorb what it takes off the largest.
        if ($count * $maxWeight <= 1.0) {
            return $weights;
        }

        // The budget needs enough names BELOW the threshold to carry what the budget will not: the fewest
        // names that can fill the budget is ceil(budget / cap), and everyone else is held to the threshold.
        $aboveBudget = (int) ceil($budget / $maxWeight);
        $budgetApplies = $withBudget && ($budget + (($count - $aboveBudget) * $threshold)) >= 1.0;

        for ($pass = 0; $pass < self::CAP_ITERATIONS; $pass++) {
            $changed = false;

            // Single-name cap: redistribute excess weight above cap pro-rata to uncapped names with headroom.
            $excess = 0.0;
            $free = 0.0;
            foreach ($weights as $weight) {
                if ($weight >= $maxWeight - 1e-12) {
                    $excess += $weight - $maxWeight;
                } else {
                    $free += $weight;
                }
            }

            if ($excess > 1e-12 && $free > 0.0) {
                foreach ($weights as $ticker => $weight) {
                    $weights[$ticker] = $weight >= $maxWeight - 1e-12
                        ? $maxWeight
                        : $weight + ($excess * ($weight / $free));
                }
                $changed = true;
            }

            // Rule two: the names above the threshold, in combination, no more than the budget. They are
            // scaled back together and the freed weight goes to the names below the threshold, which is the
            // only place it can go without immediately breaching the rule again.
            if ($budgetApplies) {
                $heavy = 0.0;
                $light = 0.0;
                foreach ($weights as $weight) {
                    if ($weight > $threshold + 1e-12) {
                        $heavy += $weight;
                    } else {
                        $light += $weight;
                    }
                }

                if ($heavy > $budget + 1e-12 && $light > 0.0) {
                    $scale = $budget / $heavy;
                    $released = $heavy - $budget;

                    foreach ($weights as $ticker => $weight) {
                        $weights[$ticker] = $weight > $threshold + 1e-12
                            ? $weight * $scale
                            : $weight + ($released * ($weight / $light));
                    }
                    $changed = true;
                }
            }

            if (!$changed) {
                break;
            }
        }

        return $weights;
    }

    /**
     * The multiplier on each constituent's float-adjusted capitalisation that makes the running index
     * produce the weights the committee just struck.
     *
     * This is the adjusted weight factor, and it is the whole trick behind a non-cap-weighted index: fix it
     * at the review, and the index can then be computed every tick as if it were an ordinary capitalisation
     * index, with the weights drifting on prices exactly as a real fund's holdings do. A cap-weighted index
     * comes out of this with every factor at one, which is why the same arithmetic serves all four.
     *
     * @param array<string, float> $weights
     * @param array<string, float> $capByTicker
     * @return array<string, float>
     */
    private function adjustedWeightFactors(array $weights, array $capByTicker): array
    {
        $memberCap = 0.0;
        foreach ($weights as $ticker => $weight) {
            $memberCap += $capByTicker[$ticker] ?? 0.0;
        }

        if ($memberCap <= 0.0) {
            return [];
        }

        $factors = [];

        foreach ($weights as $ticker => $weight) {
            $cap = $capByTicker[$ticker] ?? 0.0;
            $factors[$ticker] = $cap > 0.0 ? ($weight * $memberCap) / $cap : 0.0;
        }

        return $factors;
    }
}
