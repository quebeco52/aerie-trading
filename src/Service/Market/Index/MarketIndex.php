<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

use App\Service\Math\FinancialConstants;

/**
 * The indices the exchange publishes. Each is struck on a fund of the same ticker.
 *
 * Two, because they answer different questions. The HEADLINE index is the market's scoreboard: a fixed
 * number of the largest names by float, with a membership that has to be earned and can be lost, and it is
 * the benchmark the passive book tracks. The COMPOSITE is the whole board, every name that is still listed,
 * weighted the same way — the market itself rather than a selection from it. A name outside the headline
 * still moves the composite, which is how a broad rally that misses the largest names becomes visible.
 *
 * Both go through the same committee. A whole-board index has no seats to fight over, but it still needs
 * its divisor restated whenever the board changes, or the level jumps on a day nobody made any money.
 */
enum MarketIndex: string
{
    case Headline = 'LBI';
    case Composite = 'LBC';

    /** The index the passive book tracks and the pages quote as "the market". */
    public static function benchmark(): self
    {
        return self::Headline;
    }

    /** Seats the index carries, or null for every listed name. */
    public function constituentCount(): ?int
    {
        return match ($this) {
            self::Headline => FinancialConstants::INDEX_CONSTITUENT_COUNT,
            self::Composite => null,
        };
    }

    /**
     * Whether the index is a SELECTION. A selected membership is banded and its boundary is worth watching;
     * a whole-board membership changes only when a name is born or dies.
     */
    public function isSelective(): bool
    {
        return $this->constituentCount() !== null;
    }

    /** Whether passive money holds this index rather than merely quoting it. */
    public function carriesPassiveBook(): bool
    {
        return $this === self::benchmark();
    }

    /** Plain-language name for the page. */
    public function label(): string
    {
        return match ($this) {
            self::Headline => 'Headline index',
            self::Composite => 'Composite index',
        };
    }

    /** What the index is meant to measure, for the page's methodology card. */
    public function mandate(): string
    {
        return match ($this) {
            self::Headline => sprintf(
                'The %d largest listed companies by float-adjusted capitalisation, weighted by float. Membership is reviewed quarterly and banded, so a name is admitted only once it has clearly risen into the index and dropped only once it has clearly fallen out of it.',
                FinancialConstants::INDEX_CONSTITUENT_COUNT
            ),
            self::Composite => 'Every listed company, weighted by float-adjusted capitalisation. The market as a whole rather than a selection from it: nothing is admitted or dropped except by listing or delisting.',
        };
    }

    /** Redis key holding this index's membership. */
    public function membershipKey(): string
    {
        return 'index_membership:' . $this->value;
    }
}
