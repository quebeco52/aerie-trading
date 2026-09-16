<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Stock;
use App\Service\Market\Index\IndexMembershipStoreInterface;
use App\Service\Market\Index\IndexRanking;
use App\Service\Market\Index\IndexWeighting;
use App\Service\Market\Index\MarketIndex;
use App\Service\Math\FinancialConstants;

/**
 * Decides who is in each index and in what weight, and keeps its level continuous when that changes.
 *
 * The index was a number: every listed company's capitalisation over a divisor. That is a market
 * capitalisation, not an index — it had no membership, so nothing could join it or be dropped from it, and
 * the passive money that is supposed to track it was allocated to names that were not in anything.
 *
 * Four pieces of real index mechanics make the difference:
 *
 *   - MEMBERSHIP, drawn from the index's own eligible universe and ranked on the index's own criterion.
 *     Size for a broad index, quiet for a low-volatility one, a sector for a sector fund. Ranking is always
 *     stated best-first, so one banding rule serves every index whatever it is measuring.
 *
 *   - FLOAT ADJUSTMENT. What a passive fund can actually buy is the part of a company that trades; a firm
 *     that is nine tenths closely held is a smaller position than its market capitalisation says, and
 *     weighting it on the whole would have index funds trying to buy stock that is not for sale.
 *
 *   - WEIGHTS, and the adjusted-weight FACTOR that carries them. A non-cap-weighted index is not computed
 *     from its weights each tick; it is run as a modified capitalisation index, with a factor per
 *     constituent chosen at the review so that the weights come out right on that day and then DRIFT with
 *     prices until the next one. That is how every non-cap-weighted index S&P publishes is computed, and it
 *     is what stops a low-volatility fund silently rebalancing itself for free on every tick.
 *
 *   - THE DIVISOR, restated whenever composition or weights change. This is the whole reason a divisor
 *     exists: an index has to measure the market's return, not the arithmetic of its own composition. Add a
 *     company worth a tenth of the index without restating, and the index jumps ten percent on a day when
 *     nobody made any money. The restatement pins the level across the change and lets it move only on
 *     prices afterwards.
 *
 * Membership is BANDED. A sitting member is not evicted the first time a marginal name edges past it,
 * because ranking noise at the boundary would otherwise churn the entire passive book twice a year to no
 * purpose. Real indices band for exactly that reason.
 *
 * The same committee runs every index in MarketIndex. A whole-board composite has no seats to contest, so
 * its membership is simply everyone alive — but it still reconstitutes on the same calendar, because the
 * board changes when a name dies and its divisor has to absorb that like any other change in composition.
 *
 * What this class deliberately does NOT do is push the inclusion trade. It does not have to: the passive
 * book is held by IndexFundStrategy, which holds each name in proportion to how much indexed money is
 * pointed at it, and the agent population works every position toward its target on its own. So an addition
 * is bought and a deletion is sold by the same machinery that trades everything else, charged the same
 * impact and already inside the variance budget — which is what makes the downward-sloping demand curve of
 * Shleifer (1986) an EMERGENT property here rather than a scripted one.
 */
final class IndexCommittee
{
    /** Passes the diversification cap is worked over. Each pass is a fixed point of one rule that may break the other; it converges in two or three. */
    private const CAP_ITERATIONS = 12;

    public function __construct(
        private readonly IndexMembershipStoreInterface $store,
        private readonly EtfTracker $etfTracker,
        private readonly LiquidityEngine $liquidityEngine,
    ) {}

    /** Ticks between reconstitutions. */
    public static function intervalTicks(int $ticksPerYear): int
    {
        return max(1, intdiv($ticksPerYear, FinancialConstants::INDEX_RECONSTITUTIONS_PER_YEAR));
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
     * A volatility screen is a trailing measurement, and that is not a detail of how it is computed — it is
     * what the screen means. S&P's low-volatility index ranks on a year of daily returns, and a year is
     * what `realizedVarianceEma` carries. What it used to read instead was `currentVolatility`, which is
     * the instantaneous state of the variance process: the number the diffusion is about to draw from,
     * which a single jump moves outright and which the QE step re-draws every tick. Ranking on it meant the
     * index reconstituted itself on volatility spikes rather than on volatility, and since the fund trades
     * every reconstitution, each spike was another round trip — a name was sold for having jumped and
     * bought back a quarter later for having settled down.
     *
     * The state is the fallback, and the structural figure the fallback's fallback, for the market's first
     * year before there is a window to measure. Floored, because an inverse-volatility weight divides by
     * this: a name that has gone briefly quiet enough to divide by almost nothing would otherwise take
     * almost the whole fund, which is the standard failure of risk-weighted portfolios built on an
     * unfloored estimate.
     */
    public static function trailingVolatility(Stock $stock): float
    {
        $vol = $stock->getRealizedVolatility()
            ?? (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility());

        return max(FinancialConstants::INDEX_MINIMUM_WEIGHT_VOLATILITY, $vol);
    }

    /**
     * Whether a company is profitable enough to be ADMITTED to an index that screens on it.
     *
     * Both halves of the S&P 500's viability test, and both exact here rather than approximated: the
     * quarterly history holds the last four reported quarters, so their sum is the trailing twelve-month
     * figure and the last of them is the most recent quarter. Requiring both to be positive is what stops a
     * large company that has never earned anything buying its way into the market's scoreboard on size.
     *
     * A company with no reported history yet cannot pass, because there is nothing to pass with. That is
     * the same answer real indices give a company that has not reported: wait.
     */
    public static function isEarningsViable(Stock $stock): bool
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
        $buffer = FinancialConstants::INDEX_MEMBERSHIP_BUFFER;

        return [
            'inner' => (int) round($constituentCount * (1.0 - $buffer)),
            'outer' => (int) round($constituentCount * (1.0 + $buffer)),
        ];
    }

    /**
     * The standing membership of an index as a lookup, empty when none has been taken.
     *
     * @return array<string, true>
     */
    public function currentMembers(MarketIndex $index): array
    {
        $roster = $this->store->current($index);

        return $roster === null ? [] : array_fill_keys($roster['tickers'], true);
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
     * carried at one, which is the cap-weighted index every roster written before weights existed was.
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
     * points — which is exactly how a total-return index is built from a price one, and it is the figure
     * that makes the difference between a fund that pays its holders and one that quietly keeps the money.
     *
     * @param array<string, float> $pointsByTicker Dividend rate x float-adjusted shares, per ticker.
     */
    public function memberDividendPoints(MarketIndex $index, array $pointsByTicker): float
    {
        return $this->adjustedMemberSum($index, $pointsByTicker);
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
     * Passive assets are not one pool behind one benchmark. They sit in the vehicles that exist, and those
     * vehicles disagree: a quiet staple inside the headline index is held by four funds at once, and a
     * volatile mid-cap outside it by one. The multiple returned here is the ratio of the two —
     *
     *     sum over indices of (that index's share of indexed money x the name's weight in it)
     *     -------------------------------------------------------------------------------------
     *                          the name's weight in the market itself
     *
     * — so it reads 1.0 for a name held exactly in line with its size, above 1.0 for one several funds
     * overweight, and 0.0 for one no published index holds. Because the index shares sum to one and each
     * index's weights sum to one, the capitalisation-weighted average of the multiple is exactly one: the
     * indexed book as a whole holds the market as a whole, however it is split between vehicles. That is
     * what makes this a REALLOCATION of passive money across names rather than a way to quietly inflate it.
     *
     * Struck from the frozen rosters rather than from live prices, because that is what a passive fund's
     * target actually is: a weight fixed at the last rebalance, not one recomputed every tick.
     *
     * An empty return means the market's own roster has not been taken yet, and the caller should treat
     * every name as held — which is what the market was before there was a membership at all.
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
                FinancialConstants::INDEX_MAX_PASSIVE_OWNERSHIP_MULTIPLE,
                ($held[$ticker] ?? 0.0) / $marketWeight
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
     *                                          record. Read only when the committee's own state is missing
     *                                          — a Redis that has been emptied under a market that has
     *                                          not — so that the level survives that rather than reopening
     *                                          at the base.
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

        // Two maps, because the level before a review and the membership after it are asked of different
        // populations. ELIGIBLE is what the index may hold — its sector, or the whole board — and the new
        // membership is selected and weighted out of it. The wider map also carries a standing member that
        // has left the universe since the last review, because the level being preserved across the review
        // was struck on it and dropping it from the arithmetic would move the level by its weight.
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

                // Screened on ADMISSION only, so it is collected separately from the ranking rather than
                // filtered out of it. An incumbent that has fallen into losses still appears in the ranking
                // and still holds its seat until the bands take it; it simply could not get back in.
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
            $stockByTicker
        );

        $this->store->store($index, $tick, $tickers, $added, $deleted, $weights, $factors);

        // The restatement. A divisor is what an index uses to absorb a change in its own composition, so
        // that its level continues to measure prices rather than the committee's decisions. Without it,
        // admitting a company worth a tenth of the index moves the index a tenth on a day nobody made any
        // money — and every chart, every return and every passive book reading off it inherits that lie.
        // A re-weighting is a change in composition on exactly the same footing: a low-volatility index
        // that restruck its weights without restating would print the rebalance itself as a return.
        //
        // With no prior level there is nothing to preserve, so the index OPENS at its base. Stating that
        // here rather than leaving the first divisor to whoever happens to compute a level first is what
        // makes the opening a decision instead of an accident of ordering.
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
     * Struck from the previous membership, its factors and its divisor when all are on record, which is
     * exact. When they are not — the committee's state lives in Redis and can be emptied under a market
     * whose database has not been — the fund's last printed level is the next best statement of where the
     * index stands, one tick stale. Only a market with neither has no level to preserve, and it opens at
     * the base.
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
        ?float $lastKnownLevel
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
     * An index changes its weights by arithmetic and pays nothing. A fund holding the index has to TRADE to
     * get from the portfolio it is carrying into the review to the one the committee just decided, and the
     * two are not the same portfolio: weights drift with prices between reviews, so even a membership that
     * did not change has to be traded back to its targets. That trade crosses the spread in every name it
     * touches, and the spread is the one execution cost that is knowable here — see the note on impact
     * below.
     *
     * Without this the quarterly restrike was free money and the index arithmetic said so: a rebalance sells
     * whatever has drifted up and buys whatever has drifted down, which on mean-reverting prices is a
     * profitable trade in its own right, repeated four times a year, at mid, in unlimited size. It is the
     * reason a low-volatility fund could beat a cap-weighted benchmark by a steady margin that had nothing
     * to do with holding quiet companies. A CAP-WEIGHTED index pays almost nothing here and that is not an
     * exemption granted to it: its weights maintain themselves as prices move, so there is nothing to trade
     * except the names that actually joined or left.
     *
     * TURNOVER is stated one-way, which is the convention a factsheet reports and half the sum of the weight
     * changes. The COST is not halved, because both sides are real: the fund sells one name and buys another,
     * and each crossing pays its own half-spread at its own name's liquidity.
     *
     * What is charged here is the spread and nothing else. A real rebalance also pays market impact, and
     * this deliberately does not estimate it: impact is a function of how much of a name's daily volume the
     * order is, and the fund's assets are not modelled in shares anywhere in this system, so any impact
     * figure would be a number invented to look like one. The charge is therefore a FLOOR on what following
     * the index costs, not the whole of it.
     *
     * The first review of a fresh market is free, because a fund is created in kind: the authorised
     * participant delivers the basket and receives shares, and no spread is crossed doing it.
     *
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
        array $stockByTicker
    ): array {
        $nothing = ['turnover' => 0.0, 'cost' => 0.0];

        if ($previousMembers === [] || $weights === []) {
            return $nothing;
        }

        // The weights the fund is actually carrying, which are the ones it struck at the last review left to
        // drift with prices since — not the weights that review decided. Trading is measured from where the
        // portfolio IS.
        //
        // A member that has gone to zero (a bankrupt shell) drops out of this: it is worth nothing, so it is
        // neither part of what the fund holds nor something that can be sold for anything.
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
        return $turnover < FinancialConstants::FUND_MINIMUM_REBALANCE_TURNOVER
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
     * With one, banding is TWO thresholds, not one, and that is the whole of it. A sitting member keeps its
     * place until it falls past the outer band, so ranking noise at the boundary cannot churn the passive
     * book. A name that is not in gets in only once it has risen inside the INNER band — clearly into the
     * body of the index rather than merely level with its weakest member — and when it does, it displaces
     * that weakest member.
     *
     * One threshold is not enough in either direction. With only the outer band, incumbents fill every slot
     * and a company that has plainly overtaken half the index can never join it. With only the inner one,
     * the boundary flaps every quarter, which is the cost banding exists to avoid.
     *
     * A bankrupt shell is not ranked at all — it has no float-adjusted capitalisation — so it leaves at the
     * first reconstitution after it dies, whatever the bands say. So does a name that has left the index's
     * universe, which for a sector fund is how a reclassified company is dropped.
     *
     * ADMISSIBILITY is a third thing again, and it applies in one direction only. An index may require more
     * of a name than a good rank to let it IN — the headline index requires it to be profitable — and
     * nothing of it to let it stay. So the screen gates the two places a name can enter and is never
     * consulted about an incumbent: a member that stops earning keeps its seat until the bands take it,
     * because an index that ejected every company having a bad year would be a momentum strategy wearing a
     * benchmark's name.
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
        usort($members, static fn (string $a, string $b): int => $rankOf[$a] <=> $rankOf[$b]);

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

        usort($members, static fn (string $a, string $b): int => $rankOf[$a] <=> $rankOf[$b]);

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
                    FinancialConstants::INDEX_MINIMUM_WEIGHT_VOLATILITY,
                    $volByTicker[$ticker] ?? FinancialConstants::INDEX_MINIMUM_WEIGHT_VOLATILITY
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
     * Both are worked REPEATEDLY, and that is not belt and braces. Redistributing the largest name's excess
     * pro rata raises everyone else, and on a concentrated set it raises the second name straight through
     * the cap it was just under; relieving the budget does the same thing in the other direction. A single
     * pass leaves the cap breached by whoever caught the redistribution, which is a cap that does not hold.
     *
     * A rule that CANNOT be satisfied is not applied, rather than applied until the weights stop summing to
     * one. A six-company sector cannot put only 45% of itself in the names above 4.5% — the remaining five
     * would have to hold 55% between them at 4.5% each — and a fund of four cannot respect a 22.5% cap at
     * all. This is not a fudge: it is why real capped indices need a reasonable number of constituents, and
     * the honest thing for a narrow one to do is cap what it can and say so.
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

            // Rule one: nobody above the single-name cap. What comes off goes pro rata to the names that
            // still have HEADROOM under it.
            //
            // At the cap counts as capped, not as free. A name pinned exactly at the ceiling has no room to
            // take anything, and letting it into the pool hands it a share of the next redistribution and
            // pushes it straight back through — which does not converge, it merely gets smaller every pass
            // until the iteration limit stops it somewhere just over the line.
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
