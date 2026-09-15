<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Holds the market, and buys or sells only because money is arriving or leaving.
 *
 * Price-insensitive by construction: an index fund does not care what a name is worth, only how much
 * capital it has been given to allocate. That makes its flow a function of the macro backdrop rather than
 * of the market, and it is why passive money keeps buying into an expensive market and keeps selling into
 * a cheap one.
 *
 * Outside the discrete-choice switching. Indexing is a decision not to hold a view, not a view that beat
 * the other side last quarter, so it does not compete for capital on realized profit.
 */
final class IndexFundStrategy implements AgentStrategyInterface
{
    public function identifier(): string
    {
        return 'index_fund';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        // A fund that tracks an index holds what is IN the index, and nothing else. This is the whole of the
        // inclusion effect: the target for a name that has just been dropped is zero, the target for one
        // that has just been added is a full position, and the population works every position toward its
        // target on its own. So the trade that moves the price is placed by the same machinery that trades
        // everything else and is charged the same impact — which is what makes the downward-sloping demand
        // curve (Shleifer 1986) something the market produces rather than something written into it.
        if (!$view->isIndexMember) {
            return 0.0;
        }

        // A tightening in conditions withdraws money from passive vehicles and an easing sends it back.
        // The index carries no other opinion, so this is the whole of its signal. The tilt is a fraction
        // of the passive book and it is bounded: fund flows are a few percent of assets a year even in a
        // crisis, and passive money was a net buyer through 2008 and 2020. An additive tilt on a z-score
        // index liquidated the whole book at a moderately tight reading and nearly tripled it at an easy one.
        $tilt = 1.0 - (FinancialConstants::AGENT_INDEX_FLOW_SENSITIVITY * $view->financialConditions);
        $bound = FinancialConstants::AGENT_INDEX_MAX_FLOW_TILT;
        $tilt = max(1.0 - $bound, min(1.0 + $bound, $tilt));

        return max(0.0, min(1.0, FinancialConstants::AGENT_INDEX_BASE_SHARE * $tilt));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}
