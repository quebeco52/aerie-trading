<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Math\FinancialConstants;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Turns beliefs into order flow.
 *
 * Each tick, for each name: score what the strategies were holding against the return that followed,
 * reallocate capital between the competing beliefs, ask each one what it wants to hold now, and move part
 * of the way there. The net of those moves is order flow, and it goes into the same store a player's fill
 * goes into — so it reaches the price through the same impact function, and the diffusion gives back the
 * same measured variance.
 *
 * That shared channel is the point. Agent flow that bypassed it would be a second, unbudgeted source of
 * price movement, and every name the agents traded would simply become more volatile — the failure the
 * order-flow budget in MarketEngine exists to prevent.
 *
 * Agents act on the price they can see and their flow lands on the next tick. That lag is not an
 * approximation: a participant observes a price and then trades, and letting them move the price they were
 * reacting to within the same instant would be the market responding to itself before anything happened.
 */
final class AgentFlowEngine
{
    /** @var list<AgentStrategyInterface> */
    private array $strategies;

    /**
     * @param iterable<AgentStrategyInterface> $strategies
     */
    public function __construct(
        private readonly AgentPopulation $population,
        private readonly AgentStateStoreInterface $stateStore,
        private readonly OrderFlowStoreInterface $orderFlow,
        #[AutowireIterator('app.agent_strategy')]
        iterable $strategies = [],
    ) {
        $this->strategies = is_array($strategies) ? array_values($strategies) : iterator_to_array($strategies, false);
    }

    /**
     * Runs the population over one name and records whatever it wants to trade.
     *
     * @return array{flow: float, shares: array<string, float>, positions: array<string, float>}
     */
    public function trade(AgentMarketViewDTO $view): array
    {
        // The intensity dial is not checked here on purpose: it multiplies every step below, so turning it
        // to zero already leaves the population holding what it held and recording nothing. An early
        // return would say the same thing twice and hide the dial's actual mechanism.
        if ($this->strategies === [] || $view->averageDailyVolume <= 0.0) {
            return ['flow' => 0.0, 'shares' => [], 'positions' => []];
        }

        $state = $this->stateStore->read($view->ticker) ?? $this->openBook();
        $positions = $state['positions'];
        $fitness = $state['fitness'];

        $capacity = $view->averageDailyVolume * FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;

        // Score the beliefs on what they were actually carrying into the move that just happened.
        $fitness = $this->population->updateFitness($fitness, $positions, $view->logReturn, $capacity, $view->dt);
        $shares = $this->population->shares($fitness);

        $flow = 0.0;
        $updatedPositions = $positions;

        foreach ($this->strategies as $strategy) {
            $identifier = $strategy->identifier();
            $current = $positions[$identifier] ?? 0.0;

            // Competing beliefs are sized by how much capital currently holds them; the others carry their
            // own structural weight, which does not depend on who has been winning.
            $allocation = $strategy->competesForCapital()
                ? ($shares[$identifier] ?? 0.0) * $capacity
                : $capacity;

            $target = $strategy->signal($view, $positions) * $allocation;

            $step = ($target - $current) * FinancialConstants::AGENT_POSITION_ADJUSTMENT_SPEED
                * FinancialConstants::AGENT_FLOW_INTENSITY;

            $updatedPositions[$identifier] = $current + $step;
            $flow += $step;
        }

        if ($flow !== 0.0) {
            $this->orderFlow->record($view->ticker, $flow);
        }

        $this->stateStore->write($view->ticker, [
            'positions' => $updatedPositions,
            'fitness' => $fitness,
            'last_price' => $view->price,
        ]);

        return ['flow' => $flow, 'shares' => $shares, 'positions' => $updatedPositions];
    }

    /**
     * A fresh book: everyone flat, every belief scored evenly.
     *
     * Flat rather than at some notional starting allocation, because an opening position would be a large
     * one-off order on the first tick a name is seen — an artefact of the engine starting, not of anything
     * anyone decided.
     *
     * @return array{positions: array<string, float>, fitness: array<string, float>, last_price: float}
     */
    private function openBook(): array
    {
        $positions = [];
        $fitness = [];

        foreach ($this->strategies as $strategy) {
            $positions[$strategy->identifier()] = 0.0;

            if ($strategy->competesForCapital()) {
                $fitness[$strategy->identifier()] = 0.0;
            }
        }

        return ['positions' => $positions, 'fitness' => $fitness, 'last_price' => 0.0];
    }
}
