<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

/**
 * Who is in an index, in what weight, and what changed the last time it was reviewed.
 *
 * Frozen between reconstitutions rather than recomputed on demand, for the same reason the district roster
 * is: a membership that re-ranked itself on every read would change under the reader, and the passive book
 * that tracks it would be chasing a target that moved every tick instead of four times a year.
 *
 * The WEIGHTS are frozen for a stronger reason than convenience. An index that recomputed its weights every
 * tick would be a portfolio rebalanced continuously and for free — selling every winner and buying every
 * loser at no cost — which is not a fund anyone can run. A real index fixes its weights at the review and
 * lets them drift with prices until the next one. Two figures are kept per constituent because they answer
 * different questions: the TARGET WEIGHT is what the committee decided, and the FACTOR is the multiplier on
 * float-adjusted capitalisation that makes the running index produce it.
 */
interface IndexMembershipStoreInterface
{
    /**
     * The standing membership of an index, or null when none has been taken since the last reset.
     *
     * A roster written before weights were stored comes back with empty maps rather than a missing key, and
     * every reader treats an absent factor as one — which is the cap-weighted index the store used to
     * assume every index was.
     *
     * @return array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>, weights: array<string, float>, factors: array<string, float>}|null
     */
    public function current(MarketIndex $index): ?array;

    /**
     * Freezes a new membership.
     *
     * The divisor is NOT stored here. It lives with the index level it scales, in EtfTracker, because the
     * index splits as well as reconstitutes and a divisor written in two places is two answers to what the
     * index is worth.
     *
     * @param list<string>         $tickers Constituents, best-ranked first.
     * @param list<string>         $added   Names admitted at this review.
     * @param list<string>         $deleted Names dropped at this review.
     * @param array<string, float> $weights Target weight per constituent, summing to one.
     * @param array<string, float> $factors Multiplier on float-adjusted capitalisation that produces those weights.
     */
    public function store(
        MarketIndex $index,
        int $tick,
        array $tickers,
        array $added = [],
        array $deleted = [],
        array $weights = [],
        array $factors = []
    ): void;
}
