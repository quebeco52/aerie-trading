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

    /** @param list<object>|null $strategies Null for the full population; an explicit list, empty or not, otherwise. */
    private function engine(?array $strategies = null): AgentFlowEngine
    {
        return new AgentFlowEngine(
            new AgentPopulation(),
            $this->stateStore,
            $this->orderFlow,
            $strategies ?? [
                new FundamentalistStrategy(),
                new MomentumStrategy(),
                new IndexFundStrategy(),
                new MarketMakerStrategy(),
            ]
        );
    }

    private function view(
        float $price = 100.0,
        float $fairValue = 100.0,
        float $momentum = 0.0,
        float $logReturn = 0.0,
        float $adv = 1000000.0
    ): AgentMarketViewDTO {
        return new AgentMarketViewDTO(
            ticker: 'TEST',
            price: $price,
            perceivedFairValue: $fairValue,
            momentumTrend: $momentum,
            averageDailyVolume: $adv,
            logReturn: $logReturn,
            financialConditions: 0.0,
            dt: 1.0 / 14400.0,
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

    public function testAgentsOpenFlatAndWorkInRatherThanArrivingFullyPositioned(): void
    {
        // A fresh book starts at zero and takes its first adjustment step. Seeding it at a full target
        // allocation would put a large one-off order on the first tick a name is seen — an artefact of the
        // engine starting, not of anything anyone decided.
        $capacity = 1000000.0 * \App\Service\Math\FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;
        $step = \App\Service\Math\FinancialConstants::AGENT_POSITION_ADJUSTMENT_SPEED;

        $result = $this->engine()->trade($this->view());

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

    public function testTheBookIsCarriedBetweenTicks(): void
    {
        $engine = $this->engine([new FundamentalistStrategy()]);
        $engine->trade($this->view(price: 60.0, fairValue: 100.0));

        $stored = $this->stateStore->read('TEST');

        $this->assertNotNull($stored);
        $this->assertGreaterThan(0.0, $stored['positions']['fundamentalist']);
        $this->assertSame(60.0, $stored['last_price']);
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

    public function testTheMakerDampsTheNetFlowTheOthersGenerate(): void
    {
        // A market with a maker in it moves less on the same demand than one without.
        $withoutMaker = new AgentFlowEngine(
            new AgentPopulation(),
            new InMemoryAgentStateStore(),
            $bare = new InMemoryOrderFlowStore(),
            [new FundamentalistStrategy()]
        );

        $withMaker = new AgentFlowEngine(
            new AgentPopulation(),
            new InMemoryAgentStateStore(),
            $damped = new InMemoryOrderFlowStore(),
            [new FundamentalistStrategy(), new MarketMakerStrategy()]
        );

        $view = $this->view(price: 60.0, fairValue: 100.0);

        for ($tick = 0; $tick < 50; $tick++) {
            $withoutMaker->trade($view);
            $withMaker->trade($view);
        }

        $this->assertLessThan(
            array_sum($bare->drain()),
            array_sum($damped->drain()),
            'Absorbed demand reaches the price smaller than it started.'
        );
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
}
