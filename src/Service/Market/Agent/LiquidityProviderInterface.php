<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A participant that supplies immediacy rather than holding a view.
 *
 * Run after the beliefs and the structural holders, and shown what they decided to trade this tick: its
 * job is to take the other side of that flow now and to get rid of the inventory later. It returns a
 * TRADE, not a target — immediacy that was worked toward over days would not be immediacy — and it never
 * competes for capital, since standing on the other side of the market is not a belief about the market.
 */
#[AutoconfigureTag('app.agent_liquidity_provider')]
interface LiquidityProviderInterface
{
    /** Stable identifier, used as the key for this provider's persisted inventory. */
    public function identifier(): string;

    /**
     * Shares to trade this tick. Positive buys.
     *
     * @param AgentMarketViewDTO $view       What the provider can see.
     * @param float              $inventory  Its own position in shares, carried from the last tick.
     * @param float              $othersFlow Net shares everyone else has decided to trade this tick.
     * @param float              $capacity   Position size that counts as fully committed, in shares.
     */
    public function trade(AgentMarketViewDTO $view, float $inventory, float $othersFlow, float $capacity): float;
}
