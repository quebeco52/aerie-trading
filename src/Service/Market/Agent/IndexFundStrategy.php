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
        // A tightening in conditions withdraws money from passive vehicles and an easing sends it back.
        // The index carries no other opinion, so this is the whole of its signal.
        $flowTilt = -FinancialConstants::AGENT_INDEX_FLOW_SENSITIVITY * $view->financialConditions;

        return max(0.0, min(1.0, FinancialConstants::AGENT_INDEX_BASE_SHARE + $flowTilt));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}
