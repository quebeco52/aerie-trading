<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

/**
 * Who is in the index, and the divisor that keeps its level continuous.
 *
 * Frozen between reconstitutions rather than recomputed on demand, for the same reason the district roster
 * is: a membership that re-ranked itself on every read would change under the reader, and the passive book
 * that tracks it would be chasing a target that moved every tick instead of four times a year.
 */
interface IndexMembershipStoreInterface
{
    /**
     * The standing membership, or null when none has been taken since the last reset.
     *
     * @return array{tick: int, tickers: list<string>}|null
     */
    public function current(): ?array;

    /**
     * Freezes a new membership.
     *
     * The divisor is NOT stored here. It lives with the index level it scales, in EtfTracker, because the
     * index splits as well as reconstitutes and a divisor written in two places is two answers to what the
     * index is worth.
     *
     * @param list<string> $tickers Constituents, best-ranked first.
     */
    public function store(int $tick, array $tickers): void;
}
