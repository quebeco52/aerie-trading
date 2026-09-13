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
 *
 * The market's cross-section travels the same way: the average mispricing of every name traded last
 * tick is handed to each name this tick, so a strategy can hold a view on one name RELATIVE to the rest.
 *
 * The volatility the agents see is their own: a realized measure built from the returns they have
 * observed, carried in the book. The value handed in with the view only seeds a book that has no history
 * yet. Reading the price process's instantaneous variance instead let the vol-control books and the maker
 * react to a shock in the tick it happened, ahead of anyone who could only watch the tape.
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

    /** @var array<string, float> The market's cross-section as it stood when the tick opened. */
    private array $crossSection = [];

    /** Running total of every traded name's log mispricing this tick. */
    private float $mispricingSum = 0.0;

    /** Key of the average log mispricing in the cross-section record. */
    private const CROSS_SECTION_MISPRICING = 'log_mispricing';

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
        $this->loadMarket();
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
            $this->stateStore->writeCrossSection([
                self::CROSS_SECTION_MISPRICING => $this->mispricingSum / $this->styleCount,
            ]);
        }

        $this->styleLoaded = false;
        $this->styleSums = [];
        $this->styleCount = 0;
        $this->mispricingSum = 0.0;

        $this->stateStore->commitBatch();
    }

    /**
     * Reads the market-wide records once per tick. Also called lazily by trade(), so an engine driven
     * without tick boundaries still sees whatever the store holds.
     */
    private function loadMarket(): void
    {
        $this->style = $this->stateStore->readStyle();
        $this->crossSection = $this->stateStore->readCrossSection();
        $this->styleLoaded = true;
        $this->styleSums = [];
        $this->styleCount = 0;
        $this->mispricingSum = 0.0;
    }

    /**
     * Runs the population over one name and records whatever it wants to trade.
     *
     * @return array{flow: float, shares: array<string, float>, positions: array<string, float>}
     */
    public function trade(AgentMarketViewDTO $view): array
    {
        if ($this->strategies === [] || $view->averageDailyVolume <= 0.0) {
            return ['flow' => 0.0, 'shares' => [], 'positions' => []];
        }

        if (!$this->styleLoaded) {
            $this->loadMarket();
        }

        // What the average name looked like at the open. Filled in before the book is opened so a relative
        // holder opens at the holding the cross-section implies, like any other structural holder.
        $marketMispricing = $this->crossSection[self::CROSS_SECTION_MISPRICING] ?? null;
        if ($marketMispricing !== null) {
            $view = $view->withMarketLogMispricing($marketMispricing);
        }

        // The intensity dial scales the book, not the speed it is worked at. Agent flow is proportional
        // to the book and the variance it supplies to the price goes as its square, so this is the one
        // number that hands variance from the diffusion to the agents. Multiplying the step instead, as
        // the first version did, changed only how fast the same book was reached — and past 1/adjustment
        // it overshot the target, at a threshold that moved with the tick rate.
        $capacity = $view->averageDailyVolume
            * FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE
            * FinancialConstants::AGENT_FLOW_INTENSITY;

        $state = $this->stateStore->read($view->ticker);
        // A book opened this tick is built on today's capacity, which is already in post-split shares, so
        // it must not be restated below: restating it too put a sell of most of the index holding into
        // the market on any name first seen on a split tick.
        $carried = $state !== null;
        $state ??= $this->openBook($view, $capacity);
        $positions = $state['positions'];
        $fitness = $state['fitness'];
        $exposures = $state['exposures'] ?? [];
        $variance = $state['variance'] ?? 0.0;

        if ($carried) {
            // Score the beliefs on what one of their agents was carrying into the move that just happened,
            // charged at the volatility that was known when the exposure was taken. The unit exposure is
            // dimensionless and the return is measured pre-split, so neither needs restating.
            $fitness = $this->population->updateFitness(
                $fitness,
                $exposures,
                $view->logReturn,
                $view->dt,
                $view->riskFreeRate,
                $variance
            );

            // The return the agents just watched is now part of the volatility they can see.
            $variance = $this->population->realizedVariance($variance, $view->logReturn, $view->dt);
        }

        $view = $view->withAnnualizedVolatility(sqrt(max(0.0, $variance)));

        // This name's score feeds the style score the NEXT tick reads. The one read at the open is what
        // every name is judged against this tick, so the order names are visited in cannot matter.
        foreach ($fitness as $identifier => $score) {
            $this->styleSums[$identifier] = ($this->styleSums[$identifier] ?? 0.0) + $score;
        }
        $this->styleCount++;
        $this->mispricingSum += $view->logMispricing();

        $shares = $this->population->shares($this->population->crowdedFitness($fitness, $this->style));

        if ($carried) {
            $positions = $this->restateForSplit($positions, $view->splitRatio);
        }

        // Worked over a horizon in simulated time, not a fixed slice per tick: a per-tick fraction would
        // make the same book fire in half a day at one tick rate and take a week at another.
        $adjustment = 1.0 - exp(-$view->dt / FinancialConstants::AGENT_POSITION_HORIZON_YEARS);

        $flow = 0.0;
        $updatedPositions = $positions;
        $updatedExposures = [];

        foreach ($this->strategies as $strategy) {
            $identifier = $strategy->identifier();
            $current = $positions[$identifier] ?? 0.0;
            $signal = $strategy->signal($view, $positions);

            if ($strategy->competesForCapital()) {
                // What one agent of this belief holds, worked in at the same pace as the book itself, so
                // the belief is scored on the exposure its money actually had rather than on the signal
                // it would have liked to be at.
                $unit = $exposures[$identifier] ?? 0.0;
                $updatedExposures[$identifier] = $unit + (($signal - $unit) * $adjustment);

                // Competing beliefs are sized by how much capital currently holds them; the others carry
                // their own structural weight, which does not depend on who has been winning.
                $allocation = ($shares[$identifier] ?? 0.0) * $capacity;
            } else {
                $allocation = $capacity;
            }

            $target = $signal * $allocation;
            $step = ($target - $current) * $adjustment;

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
            'exposures' => $updatedExposures,
            'variance' => $variance,
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
     * The realized variance is seeded from the volatility handed in with the view. It is the one time that
     * figure is read: a book seeded at zero would open every vol-control holder at its maximum leverage
     * and have it sell down over the following month, on every name at once whenever the cache is lost.
     *
     * @return array{positions: array<string, float>, fitness: array<string, float>, exposures: array<string, float>, variance: float}
     */
    private function openBook(AgentMarketViewDTO $view, float $capacity): array
    {
        $positions = [];
        $fitness = [];
        $exposures = [];

        foreach ($this->strategies as $strategy) {
            $identifier = $strategy->identifier();

            if ($strategy->competesForCapital()) {
                $positions[$identifier] = 0.0;
                $fitness[$identifier] = 0.0;
                $exposures[$identifier] = 0.0;
            } else {
                $positions[$identifier] = $strategy->signal($view, []) * $capacity;
            }
        }

        foreach ($this->providers as $provider) {
            $positions[$provider->identifier()] = 0.0;
        }

        $seed = $view->annualizedVolatility;

        return [
            'positions' => $positions,
            'fitness' => $fitness,
            'exposures' => $exposures,
            'variance' => is_finite($seed) && $seed > 0.0 ? $seed * $seed : 0.0,
        ];
    }
}
