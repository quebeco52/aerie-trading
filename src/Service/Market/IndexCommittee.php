<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Stock;
use App\Service\Market\Index\IndexMembershipStoreInterface;
use App\Service\Market\Index\MarketIndex;
use App\Service\Math\FinancialConstants;

/**
 * Decides who is in each index, and keeps its level continuous when that changes.
 *
 * The index was a number: every listed company's capitalisation over a divisor. That is a market
 * capitalisation, not an index — it had no membership, so nothing could join it or be dropped from it, and
 * the passive money that is supposed to track it was allocated to names that were not in anything.
 *
 * Two pieces of real index mechanics make the difference:
 *
 *   - MEMBERSHIP, ranked on FLOAT-adjusted capitalisation rather than total. What a passive fund can
 *     actually buy is the part of a company that trades; a firm that is nine tenths closely held is a
 *     smaller position than its market capitalisation says, and weighting it on the whole would have index
 *     funds trying to buy stock that is not for sale.
 *
 *   - THE DIVISOR, restated whenever membership changes. This is the whole reason a divisor exists: an
 *     index has to measure the market's return, not the arithmetic of its own composition. Add a company
 *     worth a tenth of the index without restating, and the index jumps ten percent on a day when nobody
 *     made any money. The restatement pins the level across the change and lets it move only on prices
 *     afterwards.
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
 * book is held by IndexFundStrategy, which now holds nothing in a name that is not a member, and the agent
 * population works every position toward its target on its own. So an addition is bought and a deletion is
 * sold by the same machinery that trades everything else, charged the same impact and already inside the
 * variance budget — which is what makes the downward-sloping demand curve of Shleifer (1986) an EMERGENT
 * property here rather than a scripted one.
 */
final class IndexCommittee
{
    public function __construct(
        private readonly IndexMembershipStoreInterface $store,
        private readonly EtfTracker $etfTracker,
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
     * The standing roster of an index as stored: who is in, when it was taken, and what changed then.
     *
     * @return array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>}|null
     */
    public function currentRoster(MarketIndex $index): ?array
    {
        return $this->store->current($index);
    }

    /**
     * The capitalisation of an index's standing membership.
     *
     * @param array<string, float> $capByTicker Float-adjusted capitalisation per ticker.
     */
    public function memberCapitalisation(MarketIndex $index, array $capByTicker): float
    {
        $roster = $this->store->current($index);

        if ($roster === null) {
            return array_sum($capByTicker);
        }

        $total = 0.0;

        foreach ($roster['tickers'] as $ticker) {
            $total += $capByTicker[$ticker] ?? 0.0;
        }

        return $total;
    }

    /**
     * Re-ranks the market, sets a new membership for an index, and restates its divisor so the level does
     * not move.
     *
     * @param array<int, Stock> $stocks         The listed universe.
     * @param float|null        $lastKnownLevel The level the index's fund last printed, when one is on
     *                                          record. Read only when the committee's own state is missing
     *                                          — a Redis that has been emptied under a market that has
     *                                          not — so that the level survives that rather than reopening
     *                                          at the base.
     * @return array{tickers: list<string>, added: list<string>, deleted: list<string>, divisor: float, level: float}
     */
    public function reconstitute(MarketIndex $index, array $stocks, int $tick, ?float $lastKnownLevel = null): array
    {
        $previous = $this->store->current($index);
        $previousMembers = $previous === null ? [] : $previous['tickers'];
        $previousDivisor = $this->etfTracker->currentDivisor($index->value);

        $capByTicker = [];
        foreach ($stocks as $stock) {
            $cap = self::floatAdjustedCap($stock);

            if ($cap > 0.0) {
                $capByTicker[$stock->getTicker()] = $cap;
            }
        }

        // The level as it stands, before anything changes. Everything below exists to make sure this is
        // also the level immediately after.
        $levelBefore = $this->levelBefore($previousMembers, $previousDivisor, $capByTicker, $lastKnownLevel);

        $tickers = $this->selectMembers($index->constituentCount(), $capByTicker, $previousMembers);

        $newCap = 0.0;
        foreach ($tickers as $ticker) {
            $newCap += $capByTicker[$ticker] ?? 0.0;
        }

        // The first roster of a fresh market is a listing, not a change: nothing was added to anything.
        $added = $previousMembers === [] ? [] : array_values(array_diff($tickers, $previousMembers));
        $deleted = array_values(array_diff($previousMembers, $tickers));

        $this->store->store($index, $tick, $tickers, $added, $deleted);

        // The restatement. A divisor is what an index uses to absorb a change in its own composition, so
        // that its level continues to measure prices rather than the committee's decisions. Without it,
        // admitting a company worth a tenth of the index moves the index a tenth on a day nobody made any
        // money — and every chart, every return and every passive book reading off it inherits that lie.
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
            'divisor' => $divisor ?? 0.0,
            'level' => $divisor !== null && $divisor > 0.0 ? $newCap / $divisor : $levelBefore,
        ];
    }

    /**
     * The index level immediately before a reconstitution.
     *
     * Struck from the previous membership and divisor when both are on record, which is exact. When they
     * are not — the committee's state lives in Redis and can be emptied under a market whose database has
     * not been — the fund's last printed level is the next best statement of where the index stands, one
     * tick stale. Only a market with neither has no level to preserve, and it opens at the base.
     *
     * @param list<string>         $previousMembers
     * @param array<string, float> $capByTicker
     */
    private function levelBefore(array $previousMembers, ?float $previousDivisor, array $capByTicker, ?float $lastKnownLevel): float
    {
        if ($previousMembers === [] || $previousDivisor === null || $previousDivisor <= 0.0) {
            return $lastKnownLevel !== null && $lastKnownLevel > 0.0 ? $lastKnownLevel : 0.0;
        }

        $cap = 0.0;
        foreach ($previousMembers as $ticker) {
            $cap += $capByTicker[$ticker] ?? 0.0;
        }

        return $cap / $previousDivisor;
    }

    /**
     * Picks the membership.
     *
     * With no seat count the index is the whole board: every name with a float-adjusted capitalisation,
     * best-ranked first. Nothing else applies — there is no boundary to band.
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
     * first reconstitution after it dies, whatever the bands say.
     *
     * @param array<string, float> $capByTicker Descending rank is taken from this.
     * @param list<string>         $previousMembers
     * @return list<string>
     */
    private function selectMembers(?int $target, array $capByTicker, array $previousMembers): array
    {
        arsort($capByTicker);

        $ranked = array_keys($capByTicker);

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

        // Outsiders that have risen inside the inner band earn one.
        foreach ($ranked as $ticker) {
            if (!isset($wasMember[$ticker]) && $rankOf[$ticker] < $innerBand) {
                $candidates[$ticker] = true;
            }
        }

        $members = array_keys($candidates);
        usort($members, static fn (string $a, string $b): int => $rankOf[$a] <=> $rankOf[$b]);

        // More claims than seats: the weakest of them lose, which is how an addition displaces someone.
        $members = array_slice($members, 0, $target);

        // Fewer claims than seats — a small market, or one that has just lost several names — so the best
        // of whoever is left fills the rest rather than leaving the index short.
        $held = array_fill_keys($members, true);
        foreach ($ranked as $ticker) {
            if (count($members) >= $target) {
                break;
            }

            if (!isset($held[$ticker])) {
                $members[] = $ticker;
                $held[$ticker] = true;
            }
        }

        usort($members, static fn (string $a, string $b): int => $rankOf[$a] <=> $rankOf[$b]);

        return $members;
    }
}
