<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One way of deciding what to hold.
 *
 * A strategy returns a signal in [-1, 1]: how much of the capital allotted to it should be long or short
 * this name. It does not place orders and does not know how much capital it has — turning a conviction
 * into shares is the population's job, because how much a belief is worth depends on how many people
 * currently hold it.
 */
#[AutoconfigureTag('app.agent_strategy')]
interface AgentStrategyInterface
{
    /** Stable identifier, used as the key for this strategy's persisted position and fitness. */
    public function identifier(): string;

    /**
     * Conviction, in [-1, 1]. Positive is long.
     *
     * @param AgentMarketViewDTO         $view      What the strategy can see.
     * @param array<string, float> $positions Every strategy's current position in shares, keyed by identifier.
     */
    public function signal(AgentMarketViewDTO $view, array $positions): float;

    /**
     * Whether this strategy competes for capital in the discrete-choice switching.
     *
     * False for participants that are not beliefs about where the price is going: a market maker is an
     * intermediary and an index fund is a decision not to have a view, and neither one is chosen because
     * it beat the other side last quarter.
     */
    public function competesForCapital(): bool;
}
