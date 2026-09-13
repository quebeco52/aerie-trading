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
 *
 * Capital also moves at the level of a style. Each name's population is chosen on a blend of how a belief
 * has paid on that name and how it has paid across the market; the market-wide score is read once when a
 * tick opens and rebuilt from every name's own score when it closes. So a style that has been working
 * everywhere attracts capital in every name, and when the market turns it loses it everywhere at once —
 * which is what a momentum crash is, and what sixty unrelated populations could never produce.
 */
final class AgentFlowEngine
{
    /** @var list<AgentStrategyInterface> */
    private array $strategies;

    /** @var list<LiquidityProviderInterface> */
    private array $providers;

    /** @var array<string, float> Market-wide fitness per competing belief, as it stood when the tick opened. */
    private array $style = [];

    private bool $styleLoaded = false;

    /** @var array<string, float> Running total of each belief's local fitness across the names traded this tick. */
    private array $styleSums = [];

    private int $styleCount = 0;

    /**
     * @param iterable<AgentStrategyInterface>     $strategies
     * @param iterable<LiquidityProviderInterface> $providers
     */
    public function __construct(
        private readonly AgentPopulation $population,
        private readonly AgentStateStoreInterface $stateStore,
        private readonly OrderFlowStoreInterface $orderFlow,
        #[AutowireIterator('app.agent_strategy')]
        iterable $strategies = [],
        #[AutowireIterator('app.agent_liquidity_provider')]
        iterable $providers = [],
    ) {
        $this->strategies = is_array($strategies) ? array_values($strategies) : iterator_to_array($strategies, false);
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    /**
     * Marks the start of a tick so the book store can load every name at once rather than per trade().
     */
    public function beginTick(): void
    {
        $this->stateStore->beginBatch();
        $this->loadStyle();
    }

    /**
     * Marks the end of a tick: whatever the population wrote since beginTick() is sent in one go, and the
     * market-wide style score is rebuilt from the names that traded.
     *
     * An equal-weighted average of the names' own scores. Each of those is already an exponentially
     * weighted rate with the same memory, so the average of them is the same smoothing applied to the
     * style's average return — the style's performance as an equal-weighted portfolio of its bets. Not
     * weighted by size on purpose: a few of the largest names would otherwise decide the regime for all.
     */
    public function endTick(): void
    {
        if ($this->styleCount > 0) {
            $style = [];
            foreach ($this->styleSums as $identifier => $sum) {
                $style[$identifier] = $sum / $this->styleCount;
            }

            $this->stateStore->writeStyle($style);
        }

        $this->styleLoaded = false;
        $this->styleSums = [];
        $this->styleCount = 0;

        $this->stateStore->commitBatch();
    }

    /**
     * Reads the style score once per tick. Also called lazily by trade(), so an engine driven without tick
     * boundaries still sees whatever the store holds.
     */
    private function loadStyle(): void
    {
        $this->style = $this->stateStore->readStyle();
        $this->styleLoaded = true;
        $this->styleSums = [];
        $this->styleCount = 0;
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

        if (!$this->styleLoaded) {
            $this->loadStyle();
        }

        $capacity = $view->averageDailyVolume * FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;

        $state = $this->stateStore->read($view->ticker);
        // A book opened this tick is built on today's capacity, which is already in post-split shares, so
        // it must not be restated below: restating it too put a sell of most of the index holding into
        // the market on any name first seen on a split tick.
        $carried = $state !== null;
        $state ??= $this->openBook($view, $capacity);
        $positions = $state['positions'];
        $fitness = $state['fitness'];

        // Score the beliefs on what they were actually carrying into the move that just happened. The
        // positions here are still in pre-split shares and the return is measured pre-split, so the two
        // agree; the book is restated only once it has been scored.
        $fitness = $this->population->updateFitness(
            $fitness,
            $positions,
            $view->logReturn,
            $capacity,
            $view->dt,
            $view->riskFreeRate,
            $view->annualizedVolatility * $view->annualizedVolatility
        );
        // This name's score feeds the style score the NEXT tick reads. The one read at the open is what
        // every name is judged against this tick, so the order names are visited in cannot matter.
        foreach ($fitness as $identifier => $score) {
            $this->styleSums[$identifier] = ($this->styleSums[$identifier] ?? 0.0) + $score;
        }
        $this->styleCount++;

        $shares = $this->population->shares($this->population->crowdedFitness($fitness, $this->style));

        if ($carried) {
            $positions = $this->restateForSplit($positions, $view->splitRatio);
        }

        // Worked over a horizon in simulated time, not a fixed slice per tick: a per-tick fraction would
        // make the same book fire in half a day at one tick rate and take a week at another.
        $adjustment = 1.0 - exp(-$view->dt / FinancialConstants::AGENT_POSITION_HORIZON_YEARS);

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

            $step = ($target - $current) * $adjustment * FinancialConstants::AGENT_FLOW_INTENSITY;

            $updatedPositions[$identifier] = $current + $step;
            $flow += $step;
        }

        // The intermediaries go last and are shown what everyone else decided: they take the other side
        // of it now, so only part of that demand reaches the price this tick, and hand it back as they
        // unwind. Their inventory is a position like any other and is restated by splits like any other.
        $othersFlow = $flow;
        foreach ($this->providers as $provider) {
            $identifier = $provider->identifier();
            $current = $positions[$identifier] ?? 0.0;

            // Not scaled by the intensity dial again: the flow handed in already carries it, and the
            // inventory being unwound was built from that scaled flow. A second factor made absorption
            // quadratic in a dial everything else is linear in.
            $step = $provider->trade($view, $current, $othersFlow, $capacity);

            $updatedPositions[$identifier] = $current + $step;
            $flow += $step;
        }

        if ($flow !== 0.0) {
            $this->orderFlow->record($view->ticker, $flow);
        }

        $this->stateStore->write($view->ticker, [
            'positions' => $updatedPositions,
            'fitness' => $fitness,
        ]);

        return ['flow' => $flow, 'shares' => $shares, 'positions' => $updatedPositions];
    }

    /**
     * Restates a book held in shares after a split.
     *
     * A 4-for-1 split turns every held share into four without anyone buying anything. Left unadjusted,
     * the book would look three-quarters smaller against a capacity that is already in new shares, and
     * the agents would rebuild it — a large buy order caused by a corporate action that moved no money.
     *
     * @param array<string, float> $positions
     * @return array<string, float>
     */
    private function restateForSplit(array $positions, float $splitRatio): array
    {
        if ($splitRatio === 1.0 || $splitRatio <= 0.0 || !is_finite($splitRatio)) {
            return $positions;
        }

        return array_map(static fn (float $position): float => $position * $splitRatio, $positions);
    }

    /**
     * A fresh book: beliefs flat and scored evenly, structural holders at their holding, intermediaries flat.
     *
     * A belief opens flat because an opening position would be a large one-off order on the first tick a
     * name is seen — an artefact of the engine starting, not of anything anyone decided. A structural
     * holder opens AT its holding for the same reason: the index has held the name all along, and opening
     * it flat only to work in toward its weight put exactly that one-off order into the market — about a
     * day's volume on every name, every time the engine or its cache restarted. Neither opening records
     * any flow; the book is what was already true, not a trade.
     *
     * @return array{positions: array<string, float>, fitness: array<string, float>}
     */
    private function openBook(AgentMarketViewDTO $view, float $capacity): array
    {
        $positions = [];
        $fitness = [];

        foreach ($this->strategies as $strategy) {
            $identifier = $strategy->identifier();

            if ($strategy->competesForCapital()) {
                $positions[$identifier] = 0.0;
                $fitness[$identifier] = 0.0;
            } else {
                $positions[$identifier] = $strategy->signal($view, []) * $capacity;
            }
        }

        foreach ($this->providers as $provider) {
            $positions[$provider->identifier()] = 0.0;
        }

        return ['positions' => $positions, 'fitness' => $fitness];
    }
}
