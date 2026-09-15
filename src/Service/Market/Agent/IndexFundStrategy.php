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
    public function identifier(): string
    {
        return 'index_fund';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        // A fund that tracks an index holds what is IN the index, in the weight the index gives it, and
        // nothing else. This is the whole of the inclusion effect: the target for a name that has just been
        // dropped falls by whatever share of indexed money tracked the index that dropped it, the target for
        // one that has just been added rises by the same, and the population works every position toward its
        // target on its own. So the trade that moves the price is placed by the same machinery that trades
        // everything else and is charged the same impact — which is what makes the downward-sloping demand
        // curve (Shleifer 1986) something the market produces rather than something written into it.
        //
        // The multiple is passive ownership relative to size, so it is a REALLOCATION across names and not
        // a dial on how much passive money exists: it averages to exactly one across the market, whatever
        // the split between vehicles. A name held by no published index is held by no index fund.
        if ($view->passiveOwnershipMultiple <= 0.0) {
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

        return max(0.0, min(1.0, FinancialConstants::AGENT_INDEX_BASE_SHARE * $tilt * $view->passiveOwnershipMultiple));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}
