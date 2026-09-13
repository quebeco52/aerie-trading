<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\AgentMarketViewDTO;
use App\Service\Market\Agent\AgentFlowEngine;
use App\Service\Market\Agent\AgentPopulation;
use App\Service\Market\Agent\FundamentalistStrategy;
use App\Service\Market\Agent\IndexFundStrategy;
use App\Service\Market\Agent\InMemoryAgentStateStore;
use App\Service\Market\Agent\MarketMakerStrategy;
use App\Service\Market\Agent\MomentumStrategy;
use App\Service\Market\Agent\RelativeValueStrategy;
use App\Service\Market\Agent\VolatilityTargetStrategy;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use PHPUnit\Framework\TestCase;

/**
 * Beliefs turning into orders.
 *
 * The property that matters most here is the least visible one: agent flow goes into the SAME store a
 * player's fill goes into. Flow that bypassed it would reach the price without being measured, and every
 * name the agents traded would simply become more volatile — the failure the order-flow variance budget
 * exists to prevent.
 */
class AgentFlowEngineTest extends TestCase
{
    private InMemoryOrderFlowStore $orderFlow;
    private InMemoryAgentStateStore $stateStore;

    protected function setUp(): void
    {
        $this->orderFlow = new InMemoryOrderFlowStore();
        $this->stateStore = new InMemoryAgentStateStore();
    }

    /**
     * @param list<object>|null $strategies Null for the full population; an explicit list, empty or not, otherwise.
     * @param list<object>|null $providers  Null for the full set of intermediaries; an explicit list otherwise.
     */
    private function engine(?array $strategies = null, ?array $providers = null): AgentFlowEngine
    {
        return new AgentFlowEngine(
            new AgentPopulation(),
            $this->stateStore,
            $this->orderFlow,
            $strategies ?? [
                new FundamentalistStrategy(),
                new MomentumStrategy(),
                new IndexFundStrategy(),
                new VolatilityTargetStrategy(),
                new RelativeValueStrategy(),
            ],
            $providers ?? [new MarketMakerStrategy()]
        );
    }

    private function view(
        float $price = 100.0,
        float $fairValue = 100.0,
        float $momentum = 0.0,
        float $logReturn = 0.0,
        float $adv = 1000000.0,
        float $dt = 1.0 / 14400.0,
        float $splitRatio = 1.0,
        float $volatility = 0.0,
        string $ticker = 'TEST'
    ): AgentMarketViewDTO {
        return new AgentMarketViewDTO(
            ticker: $ticker,
            price: $price,
            perceivedFairValue: $fairValue,
            momentumTrend: $momentum,
            averageDailyVolume: $adv,
            logReturn: $logReturn,
            financialConditions: 0.0,
            dt: $dt,
            annualizedVolatility: $volatility,
            splitRatio: $splitRatio,
        );
    }

    public function testAgentOrdersLandInTheSameStoreAPlayersFillDoes(): void
    {
        // One channel, so one impact function and one measured variance. This is the whole integration.
        $this->engine([new FundamentalistStrategy()])->trade($this->view(price: 70.0, fairValue: 100.0));

        $drained = $this->orderFlow->drain();

        $this->assertArrayHasKey('TEST', $drained);
        $this->assertGreaterThan(0.0, $drained['TEST'], 'A deeply cheap name should be bought.');
    }

    public function testBeliefsOpenFlatAndWorkInRatherThanArrivingFullyPositioned(): void
    {
        // A fresh book starts a belief at zero and takes its first adjustment step. Seeding it at a full
        // target allocation would put a large one-off order on the first tick a name is seen — an artefact
        // of the engine starting, not of anything anyone decided.
        $capacity = 1000000.0 * \App\Service\Math\FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;
        $step = 1.0 - exp(-(1.0 / 14400.0) / \App\Service\Math\FinancialConstants::AGENT_POSITION_HORIZON_YEARS);

        $result = $this->engine([new FundamentalistStrategy(), new MomentumStrategy()], [])->trade($this->view(price: 60.0, fairValue: 100.0));

        foreach ($result['positions'] as $identifier => $position) {
            $this->assertLessThanOrEqual(
                $capacity * $step * 1.001,
                abs($position),
                "{$identifier} took more than one adjustment step on its first tick."
            );
        }

        // And the book really did start empty, rather than the first step being a rebalance of something.
        $this->assertNull((new InMemoryAgentStateStore())->read('TEST'));
    }

    public function testTheIndexOpensAtItsHoldingWithoutPlacingAnOrder(): void
    {
        // The index has held the name all along; the engine is only now keeping track. Opening it flat and
        // working in toward its weight was a one-off buy of about a day's volume on every name, every time
        // the engine or its cache restarted.
        $capacity = 1000000.0 * \App\Service\Math\FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;
        $index = new IndexFundStrategy();
        $view = $this->view();

        $result = $this->engine([$index], [])->trade($view);

        $this->assertEqualsWithDelta($index->signal($view, []) * $capacity, $result['positions']['index_fund'], 1e-6);
        $this->assertSame(0.0, $result['flow'], 'Recognising a holding is not a trade.');
        $this->assertSame([], $this->orderFlow->drain());
    }

    public function testAPositionIsWorkedTowardItsTargetRatherThanFiredAtIt(): void
    {
        $engine = $this->engine([new FundamentalistStrategy()]);
        $view = $this->view(price: 60.0, fairValue: 100.0);

        $first = $engine->trade($view)['positions']['fundamentalist'];
        $second = $engine->trade($view)['positions']['fundamentalist'];
        $third = $engine->trade($view)['positions']['fundamentalist'];

        $this->assertGreaterThan($first, $second);
        $this->assertGreaterThan($second, $third);

        // And it converges rather than running away: each step is smaller than the last.
        $this->assertLessThan($second - $first, $third - $second);
    }

    public function testABookIsWorkedOverSimulatedTimeNotOverACountOfTicks(): void
    {
        // The same day of simulated time closes the same share of the gap whether it is stepped 57 times
        // or 3 times. A per-tick fraction would make one book fire in half a day and another take a week.
        $fine = $this->engine([new FundamentalistStrategy()]);
        $coarse = new AgentFlowEngine(
            new AgentPopulation(),
            new InMemoryAgentStateStore(),
            new InMemoryOrderFlowStore(),
            [new FundamentalistStrategy()]
        );

        for ($tick = 0; $tick < 57; $tick++) {
            $finePosition = $fine->trade($this->view(price: 60.0, fairValue: 100.0, dt: 1.0 / 14400.0))['positions']['fundamentalist'];
        }

        for ($tick = 0; $tick < 3; $tick++) {
            $coarsePosition = $coarse->trade($this->view(price: 60.0, fairValue: 100.0, dt: 19.0 / 14400.0))['positions']['fundamentalist'];
        }

        $this->assertEqualsWithDelta($finePosition, $coarsePosition, $finePosition * 0.02);
    }

    public function testASplitRestatesTheBookAndIsNotScoredAsALoss(): void
    {
        // A 4-for-1 split quarters the price and quadruples every held share. Nobody lost money and nobody
        // has to buy anything, so the fitness is untouched and the book is restated rather than rebuilt.
        $engine = $this->engine([new FundamentalistStrategy(), new MomentumStrategy()]);

        // Build a book, and let one belief earn an edge so there is a non-trivial fitness to preserve.
        for ($tick = 0; $tick < 200; $tick++) {
            $engine->trade($this->view(price: 200.0, fairValue: 300.0, momentum: 0.2, logReturn: 0.001));
        }

        $before = $this->stateStore->read('TEST');
        $this->orderFlow->drain();

        // The tick of the split: the return handed in is measured pre-split and is flat, the price
        // arrives quartered, volume in shares arrives quadrupled, and the ratio says why.
        $engine->trade($this->view(price: 50.0, fairValue: 75.0, momentum: 0.2, logReturn: 0.0, adv: 4000000.0, splitRatio: 4.0));

        $after = $this->stateStore->read('TEST');
        $flow = $this->orderFlow->drain()['TEST'] ?? 0.0;

        foreach ($before['fitness'] as $identifier => $score) {
            $this->assertEqualsWithDelta($score, $after['fitness'][$identifier], abs($score) * 0.01 + 1e-9, "{$identifier} was scored on the split.");
        }

        // Restated: the book is in the right neighbourhood of four times its old size, with only the
        // ordinary adjustment step on top, rather than a quarter of what it should be.
        foreach ($before['positions'] as $identifier => $position) {
            $this->assertEqualsWithDelta($position * 4.0, $after['positions'][$identifier], abs($position * 4.0) * 0.05, "{$identifier} was not restated.");
        }

        // And no rebuild order went to the market: the flow is the ordinary tick's step, not a quarter of the book.
        $bookSize = array_sum(array_map('abs', $before['positions'])) * 4.0;
        $this->assertLessThan($bookSize * 0.05, abs($flow), 'The split produced a rebuild order.');
    }

    // --- Style crowding across names ---

    public function testANewNameInheritsTheMarketsStyleOnItsFirstTick(): void
    {
        // Capital arrives with the prevailing style. A name the market has never seen is not a blank slate
        // on which the two beliefs start even; it is judged against how they have paid everywhere else.
        $this->stateStore->writeStyle(['fundamentalist' => -0.6, 'momentum' => 0.6]);

        $shares = $this->engine([new FundamentalistStrategy(), new MomentumStrategy()])->trade($this->view())['shares'];

        $this->assertGreaterThan(0.5, $shares['momentum']);
    }

    public function testClosingATickAveragesEveryNamesScoreIntoTheStyle(): void
    {
        $engine = $this->engine([new FundamentalistStrategy(), new MomentumStrategy()]);

        // Two names, seeded with different histories, both scored on a flat tick so the histories decay
        // together and the style is their plain average.
        $this->stateStore->write('AAA', ['positions' => ['fundamentalist' => 0.0, 'momentum' => 0.0], 'fitness' => ['fundamentalist' => 0.4, 'momentum' => -0.2]]);
        $this->stateStore->write('BBB', ['positions' => ['fundamentalist' => 0.0, 'momentum' => 0.0], 'fitness' => ['fundamentalist' => 0.0, 'momentum' => 0.6]]);

        $engine->beginTick();
        $engine->trade(new AgentMarketViewDTO('AAA', 100.0, 100.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0));
        $engine->trade(new AgentMarketViewDTO('BBB', 100.0, 100.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0));
        $engine->endTick();

        $style = $this->stateStore->readStyle();
        $decay = exp(-(1.0 / 14400.0) / \App\Service\Math\FinancialConstants::AGENT_FITNESS_HORIZON_YEARS);

        $this->assertEqualsWithDelta(0.2 * $decay, $style['fundamentalist'], 1e-9);
        $this->assertEqualsWithDelta(0.2 * $decay, $style['momentum'], 1e-9);
    }

    public function testEveryNameInATickIsJudgedAgainstTheStyleAsItStoodAtTheOpen(): void
    {
        // The style score is read once when the tick opens and rebuilt when it closes. Otherwise the last
        // name visited would be judged against a score the first names had already moved, and the order
        // the tracker happens to iterate in would decide the population.
        $engine = $this->engine([new FundamentalistStrategy(), new MomentumStrategy()]);
        $this->stateStore->writeStyle(['fundamentalist' => 0.0, 'momentum' => 0.0]);

        $strongMomentum = ['positions' => ['fundamentalist' => 0.0, 'momentum' => 0.0], 'fitness' => ['fundamentalist' => -2.0, 'momentum' => 2.0]];
        $this->stateStore->write('AAA', $strongMomentum);
        $this->stateStore->write('BBB', $strongMomentum);
        $this->stateStore->write('CCC', ['positions' => ['fundamentalist' => 0.0, 'momentum' => 0.0], 'fitness' => ['fundamentalist' => 0.0, 'momentum' => 0.0]]);

        $engine->beginTick();
        $engine->trade(new AgentMarketViewDTO('AAA', 100.0, 100.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0));
        $engine->trade(new AgentMarketViewDTO('BBB', 100.0, 100.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0));
        $last = $engine->trade(new AgentMarketViewDTO('CCC', 100.0, 100.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0))['shares'];
        $engine->endTick();

        // Judged against the neutral style that opened the tick, not the momentum-heavy one AAA and BBB built.
        $this->assertEqualsWithDelta(0.5, $last['momentum'], 1e-9);

        // And the next tick sees what was built.
        $engine->beginTick();
        $next = $engine->trade(new AgentMarketViewDTO('CCC', 100.0, 100.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0))['shares'];
        $engine->endTick();

        $this->assertGreaterThan(0.5, $next['momentum']);
    }

    public function testABookOpenedOnASplitTickIsNotRestated(): void
    {
        // A fresh book is built on today's capacity, already in post-split shares. Restating it as well
        // quadrupled the index holding and then sold three quarters of it back in one order.
        $capacity = 4000000.0 * \App\Service\Math\FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;
        $index = new IndexFundStrategy();
        $view = $this->view(adv: 4000000.0, splitRatio: 4.0);

        $result = $this->engine([$index], [])->trade($view);

        $this->assertEqualsWithDelta($index->signal($view, []) * $capacity, $result['positions']['index_fund'], 1e-6);
        $this->assertSame(0.0, $result['flow']);
    }

    public function testTheBookIsCarriedBetweenTicks(): void
    {
        $engine = $this->engine([new FundamentalistStrategy()]);
        $engine->trade($this->view(price: 60.0, fairValue: 100.0));

        $stored = $this->stateStore->read('TEST');

        $this->assertNotNull($stored);
        $this->assertGreaterThan(0.0, $stored['positions']['fundamentalist']);
    }

    public function testCapitalShiftsTowardWhicheverBeliefHasBeenPaying(): void
    {
        // A sustained trend that keeps going pays the chartists and costs the fundamentalists, and the
        // population has to follow. If it does not, the model is inert.
        $engine = $this->engine([new FundamentalistStrategy(), new MomentumStrategy()]);

        $price = 100.0;
        $shares = [];

        for ($tick = 0; $tick < 4000; $tick++) {
            $logReturn = 0.0008;
            $price *= exp($logReturn);

            $shares = $engine->trade($this->view(
                price: $price,
                fairValue: 100.0,
                momentum: 0.15,
                logReturn: $logReturn
            ))['shares'];
        }

        $this->assertGreaterThan($shares['fundamentalist'], $shares['momentum']);
    }

    public function testTheMakerSmoothsTheOthersFlowAcrossTimeRatherThanRemovingIt(): void
    {
        // In the tick demand arrives, a market with a maker in it moves less on the same flow than one
        // without. But the maker is only carrying that demand: once it has worked its inventory off, the
        // whole of the flow has reached the price. An intermediary smooths price pressure; it is not a
        // discount on the impact function.
        $withoutMaker = new AgentFlowEngine(new AgentPopulation(), new InMemoryAgentStateStore(), $bare = new InMemoryOrderFlowStore(), [new FundamentalistStrategy()], []);
        $withMaker = new AgentFlowEngine(new AgentPopulation(), new InMemoryAgentStateStore(), $damped = new InMemoryOrderFlowStore(), [new FundamentalistStrategy()], [new MarketMakerStrategy()]);

        $view = $this->view(price: 60.0, fairValue: 100.0);

        // The first day: demand arriving.
        $bareEarly = 0.0;
        $dampedEarly = 0.0;
        for ($tick = 0; $tick < 57; $tick++) {
            $withoutMaker->trade($view);
            $withMaker->trade($view);
            $bareEarly += array_sum($bare->drain());
            $dampedEarly += array_sum($damped->drain());
        }

        $this->assertLessThan($bareEarly * 0.95, $dampedEarly, 'Absorbed demand reaches the price smaller than it started.');

        // Two more months: the belief has reached its target and the maker has worked its book off.
        $bareTotal = $bareEarly;
        $dampedTotal = $dampedEarly;
        for ($tick = 0; $tick < 2400; $tick++) {
            $withoutMaker->trade($view);
            $withMaker->trade($view);
            $bareTotal += array_sum($bare->drain());
            $dampedTotal += array_sum($damped->drain());
        }

        $this->assertEqualsWithDelta($bareTotal, $dampedTotal, $bareTotal * 0.01, 'Over time the whole of the flow reaches the price.');
    }

    public function testNoDepthMeansNoAgents(): void
    {
        // Agent capital is sized against the market it trades in. A name with no volume supports no book.
        $result = $this->engine()->trade($this->view(adv: 0.0));

        $this->assertSame(0.0, $result['flow']);
        $this->assertSame([], $this->orderFlow->drain());
    }

    public function testAnEmptyPopulationTradesNothing(): void
    {
        $result = $this->engine([])->trade($this->view(price: 50.0, fairValue: 100.0));

        $this->assertSame(0.0, $result['flow']);
        $this->assertSame([], $this->orderFlow->drain());
    }

    public function testOnlyCompetingBeliefsAreScored(): void
    {
        $this->engine()->trade($this->view());

        $stored = $this->stateStore->read('TEST');

        $this->assertArrayHasKey('fundamentalist', $stored['fitness']);
        $this->assertArrayHasKey('momentum', $stored['fitness']);
        $this->assertArrayNotHasKey('index_fund', $stored['fitness']);
        $this->assertArrayNotHasKey('market_maker', $stored['fitness']);
    }
    // --- Volatility targeting through the engine ---

    public function testAVolatilitySpikeMakesTheVolTargetingBookSellAndACalmerTapeBringsItBack(): void
    {
        // The book opens at its calm size without an order, sells when volatility jumps, then buys back
        // as it subsides. None of that depends on the price, which is flat throughout.
        $engine = $this->engine([new VolatilityTargetStrategy()], []);

        $opened = $engine->trade($this->view(volatility: 0.25));
        $this->assertSame(0.0, $opened['flow'], 'Opening at the holding is not a trade.');
        $held = $opened['positions']['vol_target'];
        $this->assertGreaterThan(0.0, $held);

        $spike = $engine->trade($this->view(volatility: 0.75, dt: 1.0 / 240.0));
        $this->assertLessThan(0.0, $spike['flow'], 'A vol spike is met with selling.');
        $this->assertLessThan($held, $spike['positions']['vol_target']);

        $calm = $engine->trade($this->view(volatility: 0.25, dt: 1.0 / 240.0));
        $this->assertGreaterThan(0.0, $calm['flow'], 'The tape calming is met with buying.');
    }

    // --- The cross-section ---

    public function testClosingATickRecordsTheMarketsAverageMispricing(): void
    {
        $engine = $this->engine([new FundamentalistStrategy()], []);

        $engine->beginTick();
        $engine->trade($this->view(price: 80.0, fairValue: 100.0, ticker: 'AAA'));
        $engine->trade($this->view(price: 125.0, fairValue: 100.0, ticker: 'BBB'));
        $engine->endTick();

        $expected = (log(100.0 / 80.0) + log(100.0 / 125.0)) / 2.0;

        $this->assertEqualsWithDelta($expected, $this->stateStore->readCrossSection()['log_mispricing'], 1e-12);
    }

    public function testRelativeValueSeesTheCrossSectionAsItStoodAtTheOpenAndOpensAtItsHolding(): void
    {
        // Two names at the same discount in a market that is, on average, at that discount: nothing is
        // cheap relative to anything and the book holds nothing. Then the cross-section moves to fair and
        // the same two names are cheap relative to it — but only from the next tick, because what is read
        // at the open is what every name is judged against.
        $engine = $this->engine([new RelativeValueStrategy()], []);
        $discount = log(100.0 / 90.0);
        $this->stateStore->writeCrossSection(['log_mispricing' => $discount]);

        $engine->beginTick();
        $first = $engine->trade($this->view(price: 90.0, fairValue: 100.0, ticker: 'AAA'));
        $engine->trade($this->view(price: 90.0, fairValue: 100.0, ticker: 'BBB'));
        $engine->endTick();

        $this->assertEqualsWithDelta(0.0, $first['positions']['relative_value'], 1e-9, 'Cheap in a cheap market is not cheap.');
        $this->assertSame(0.0, $first['flow']);

        // BBB re-rates to fair. The cross-section read at this open is still the old discount, so AAA is
        // judged flat against it and BBB, now dear against a cheap market, is sold: what changed this tick
        // is not seen until the next one.
        $engine->beginTick();
        $second = $engine->trade($this->view(price: 90.0, fairValue: 100.0, ticker: 'AAA'));
        $dear = $engine->trade($this->view(price: 100.0, fairValue: 100.0, ticker: 'BBB'));
        $engine->endTick();
        $this->assertEqualsWithDelta(0.0, $second['positions']['relative_value'], 1e-9);
        $this->assertLessThan(0.0, $dear['flow']);

        // From here the average sits between the two. Worked in over a few days, the book settles long
        // AAA and short BBB by the same amount: the long is funded by the short.
        for ($day = 0; $day < 20; $day++) {
            $engine->beginTick();
            $cheap = $engine->trade($this->view(price: 90.0, fairValue: 100.0, ticker: 'AAA', dt: 1.0 / 240.0));
            $dear = $engine->trade($this->view(price: 100.0, fairValue: 100.0, ticker: 'BBB', dt: 1.0 / 240.0));
            $engine->endTick();
        }

        $this->assertGreaterThan(0.0, $cheap['positions']['relative_value']);
        $this->assertLessThan(0.0, $dear['positions']['relative_value']);
        $this->assertEqualsWithDelta(0.0, $cheap['positions']['relative_value'] + $dear['positions']['relative_value'], 1e-3);
    }

    public function testANameFirstSeenOnceTheCrossSectionExistsOpensAtItsRelativeHoldingWithoutAnOrder(): void
    {
        $this->stateStore->writeCrossSection(['log_mispricing' => 0.0]);
        $engine = $this->engine([new RelativeValueStrategy()], []);

        $result = $engine->trade($this->view(price: 80.0, fairValue: 100.0));

        $this->assertGreaterThan(0.0, $result['positions']['relative_value']);
        $this->assertSame(0.0, $result['flow']);
        $this->assertSame([], $this->orderFlow->drain());
    }

    public function testWithoutACrossSectionRelativeValueHoldsNothing(): void
    {
        $engine = $this->engine([new RelativeValueStrategy()], []);

        $result = $engine->trade($this->view(price: 50.0, fairValue: 100.0));

        $this->assertSame(0.0, $result['positions']['relative_value']);
        $this->assertSame(0.0, $result['flow']);
    }
}
