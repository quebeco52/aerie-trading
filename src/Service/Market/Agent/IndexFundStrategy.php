<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;

/**
 * Holds the market, and buys or sells only because money is arriving or leaving.
 *
 * Price-insensitive by construction: an index fund does not care what a name is worth, only how much
 * capital it has been given to allocate. That makes its flow a function of the macro backdrop rather than
 * of the market, and it is why passive money keeps buying into an expensive market and keeps selling into
 * a cheap one.
 *
 * It holds all of the published indices at once, in the proportions their assets are actually split in,
 * rather than the benchmark alone. That is the difference between a name being in or out of one index and
 * a name being held by four funds, one, or none — and it is the only thing that makes a sector fund or a
 * low-volatility fund something the market can feel rather than a number on a page.
 *
 * Outside the discrete-choice switching. Indexing is a decision not to hold a view, not a view that beat
 * the other side last quarter, so it does not compete for capital on realized profit.
 */
final class IndexFundStrategy implements AgentStrategyInterface
{
    // --- Agent Signals ---
    /** Fractional change in the passive book per unit of the financial conditions index (a z-score composite): money leaves passive vehicles when conditions tighten. A two-sigma tightening takes 30% of the book, the order of a bad year of equity fund outflows. */
    public const AGENT_INDEX_FLOW_SENSITIVITY = 0.15;
    /** Most the passive book moves from its base in either direction, as a fraction. Passive flows are slow money even in a crisis; a tilt that could empty the book turned an index fund into a macro trader. */
    public const AGENT_INDEX_MAX_FLOW_TILT = 0.30;
    /** Baseline share of agent capital that indexes rather than picking. */
    public const AGENT_INDEX_BASE_SHARE = 0.30;

    public function identifier(): string
    {
        return 'index_fund';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        // Index tracking target: scale target position by relative passive index ownership multiple.
        if ($view->passiveOwnershipMultiple <= 0.0) {
            return 0.0;
        }

        // A tightening in conditions withdraws money from passive vehicles and an easing sends it back.
        // The index carries no other opinion, so this is the whole of its signal. The tilt is a fraction
        // of the passive book and it is bounded: fund flows are a few percent of assets a year even in a
        // crisis, and passive money was a net buyer through 2008 and 2020. An additive tilt on a z-score
        // index liquidated the whole book at a moderately tight reading and nearly tripled it at an easy one.
        $tilt = 1.0 - (self::AGENT_INDEX_FLOW_SENSITIVITY * $view->financialConditions);
        $bound = self::AGENT_INDEX_MAX_FLOW_TILT;
        $tilt = max(1.0 - $bound, min(1.0 + $bound, $tilt));

        return max(0.0, min(1.0, self::AGENT_INDEX_BASE_SHARE * $tilt * $view->passiveOwnershipMultiple));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}
