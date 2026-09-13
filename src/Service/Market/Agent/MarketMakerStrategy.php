<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Takes the other side of the flow the market generates, and works the inventory off afterwards.
 *
 * Grossman & Miller (1988): a market maker supplies immediacy. When the others want to buy now, it sells
 * to them now and carries the short until it can pass it on, so only part of their demand reaches the
 * price in the tick it arrives and the rest reaches it as the maker unwinds. Over the life of the
 * inventory the maker is flat again and the whole of the flow has been paid for — a maker smooths price
 * pressure across time, it does not make it disappear. A maker that permanently offset a share of the
 * others' positions, as the first version of this did, was a discount on the impact function rather than
 * an intermediary.
 *
 * Two things limit it. Ho & Stoll (1981): the cost of immediacy is proportional to the variance of what
 * is being carried, so above a reference volatility the absorbed share falls with 1/variance and a
 * stressed market finds its makers stepping back — the withdrawal of liquidity that every crisis shows.
 * And it never carries more than its capacity in either direction.
 */
final class MarketMakerStrategy implements LiquidityProviderInterface
{
    public function identifier(): string
    {
        return 'market_maker';
    }

    public function trade(AgentMarketViewDTO $view, float $inventory, float $othersFlow, float $capacity): float
    {
        $capacity = max(1.0, $capacity);

        $absorbed = -FinancialConstants::AGENT_MAKER_ABSORPTION * $this->riskScale($view->annualizedVolatility) * $othersFlow;

        // Inventory decays toward flat over a horizon in time, so the same book is worked off at the same
        // pace whatever the simulation is stepping at.
        $unwind = -$inventory * (1.0 - exp(-$view->dt / FinancialConstants::AGENT_MAKER_INVENTORY_HORIZON_YEARS));

        $next = max(-$capacity, min($capacity, $inventory + $absorbed + $unwind));

        return $next - $inventory;
    }

    /**
     * How much of the base absorption survives the current volatility: all of it at or below the
     * reference, and a 1/variance share above it.
     */
    private function riskScale(float $annualizedVolatility): float
    {
        $reference = FinancialConstants::AGENT_MAKER_REFERENCE_VOLATILITY ** 2;
        $variance = $annualizedVolatility * $annualizedVolatility;

        if ($variance <= $reference) {
            return 1.0;
        }

        return $reference / $variance;
    }
}
