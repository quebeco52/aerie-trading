<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Stands on the other side of whatever everyone else is doing, and works its book back toward flat.
 *
 * The intermediary the rest of the market trades against. It is short when the others are long, which
 * absorbs part of their net demand before it reaches the price — the reason a market with a maker in it
 * moves less on the same flow than one without.
 *
 * Two terms, because a maker has two jobs. Absorption is the position it takes to be the counterparty;
 * inventory decay is it getting flat again afterwards, since carrying risk is not what it is paid for.
 *
 * Outside the switching for the same reason as the index fund: making a market is not a belief about
 * where the price is going, and a maker that abandoned its book to chase momentum would not be one.
 */
final class MarketMakerStrategy implements AgentStrategyInterface
{
    public function identifier(): string
    {
        return 'market_maker';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        $capacity = max(1.0, $view->averageDailyVolume * FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE);

        $othersNet = 0.0;
        foreach ($positions as $identifier => $position) {
            if ($identifier !== $this->identifier()) {
                $othersNet += $position;
            }
        }

        $absorption = -FinancialConstants::AGENT_MAKER_ABSORPTION * ($othersNet / $capacity);
        $unwind = -FinancialConstants::AGENT_MAKER_INVENTORY_DECAY * (($positions[$this->identifier()] ?? 0.0) / $capacity);

        return max(-1.0, min(1.0, $absorption + $unwind));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}
