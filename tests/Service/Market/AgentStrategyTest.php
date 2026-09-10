<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\AgentMarketViewDTO;
use App\Service\Market\Agent\FundamentalistStrategy;
use App\Service\Market\Agent\IndexFundStrategy;
use App\Service\Market\Agent\MarketMakerStrategy;
use App\Service\Market\Agent\MomentumStrategy;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

/**
 * What each participant actually wants to hold.
 *
 * The four have to disagree for the population to mean anything: if every strategy reads the same signal
 * the same way, capital moving between them changes nothing and the market has one belief wearing four
 * names.
 */
class AgentStrategyTest extends TestCase
{
    private function view(
        float $price = 100.0,
        float $fairValue = 100.0,
        float $momentum = 0.0,
        float $conditions = 0.0,
        float $adv = 1000000.0
    ): AgentMarketViewDTO {
        return new AgentMarketViewDTO(
            ticker: 'TEST',
            price: $price,
            perceivedFairValue: $fairValue,
            momentumTrend: $momentum,
            averageDailyVolume: $adv,
            logReturn: 0.0,
            financialConditions: $conditions,
            dt: 1.0 / 14400.0,
        );
    }

    // --- Fundamentalist ---

    public function testTheFundamentalistBuysWhatIsCheapAndSellsWhatIsDear(): void
    {
        $strategy = new FundamentalistStrategy();

        $this->assertGreaterThan(0.0, $strategy->signal($this->view(price: 80.0, fairValue: 100.0), []));
        $this->assertLessThan(0.0, $strategy->signal($this->view(price: 125.0, fairValue: 100.0), []));
        $this->assertSame(0.0, $strategy->signal($this->view(price: 100.0, fairValue: 100.0), []));
    }

    public function testTheFundamentalistsConvictionIsBoundedNoMatterHowExtremeTheMispricing(): void
    {
        $strategy = new FundamentalistStrategy();

        $this->assertSame(1.0, $strategy->signal($this->view(price: 1.0, fairValue: 100.0), []));
        $this->assertSame(-1.0, $strategy->signal($this->view(price: 1000.0, fairValue: 1.0), []));
    }

    public function testMispricingIsSymmetricInLogs(): void
    {
        // Half fair value and twice fair value are the same distance from home in opposite directions,
        // which a percentage gap is not.
        $strategy = new FundamentalistStrategy();

        $cheap = $this->view(price: 50.0, fairValue: 100.0)->logMispricing();
        $dear = $this->view(price: 200.0, fairValue: 100.0)->logMispricing();

        $this->assertEqualsWithDelta(-$cheap, $dear, 1e-12);
    }

    public function testANonsensePriceOrFairValueProducesNoView(): void
    {
        $strategy = new FundamentalistStrategy();

        $this->assertSame(0.0, $strategy->signal($this->view(price: 0.0, fairValue: 100.0), []));
        $this->assertSame(0.0, $strategy->signal($this->view(price: 100.0, fairValue: 0.0), []));
    }

    // --- Momentum ---

    public function testTheChartistBuysWhatHasBeenGoingUp(): void
    {
        $strategy = new MomentumStrategy();

        $this->assertGreaterThan(0.0, $strategy->signal($this->view(momentum: 0.10), []));
        $this->assertLessThan(0.0, $strategy->signal($this->view(momentum: -0.10), []));
    }

    public function testTheChartistAndTheFundamentalistTakeOppositeSidesOfAnExpensiveRally(): void
    {
        // The disagreement the whole model runs on. A name well above fair value and still rising is a
        // buy to one and a sell to the other, and which of them holds the capital decides what happens.
        $rallyingAndExpensive = $this->view(price: 140.0, fairValue: 100.0, momentum: 0.15);

        $this->assertLessThan(0.0, (new FundamentalistStrategy())->signal($rallyingAndExpensive, []));
        $this->assertGreaterThan(0.0, (new MomentumStrategy())->signal($rallyingAndExpensive, []));
    }

    public function testTheChartistsConvictionIsAlsoBounded(): void
    {
        $strategy = new MomentumStrategy();

        $this->assertSame(1.0, $strategy->signal($this->view(momentum: 10.0), []));
        $this->assertSame(-1.0, $strategy->signal($this->view(momentum: -10.0), []));
    }

    // --- Index fund ---

    public function testTheIndexFundDoesNotCareWhatTheNameIsWorth(): void
    {
        // Price-insensitive by construction. It is why passive money keeps buying into an expensive market
        // and keeps selling into a cheap one.
        $strategy = new IndexFundStrategy();

        $cheap = $strategy->signal($this->view(price: 50.0, fairValue: 100.0, momentum: -0.2), []);
        $dear = $strategy->signal($this->view(price: 200.0, fairValue: 100.0, momentum: 0.2), []);

        $this->assertSame($cheap, $dear);
    }

    public function testMoneyLeavesPassiveVehiclesWhenConditionsTighten(): void
    {
        $strategy = new IndexFundStrategy();

        $easy = $strategy->signal($this->view(conditions: -1.0), []);
        $neutral = $strategy->signal($this->view(conditions: 0.0), []);
        $tight = $strategy->signal($this->view(conditions: 1.0), []);

        $this->assertGreaterThan($neutral, $easy);
        $this->assertLessThan($neutral, $tight);
    }

    public function testTheIndexFundIsNeverShortTheMarketItTracks(): void
    {
        $strategy = new IndexFundStrategy();

        foreach ([-5.0, -1.0, 0.0, 1.0, 5.0] as $conditions) {
            $this->assertGreaterThanOrEqual(0.0, $strategy->signal($this->view(conditions: $conditions), []));
        }
    }

    // --- Market maker ---

    public function testTheMakerStandsOnTheOtherSideOfTheMarket(): void
    {
        // Short when everyone else is long. It is the counterparty, and absorbing part of the net demand
        // before it reaches the price is why a market with a maker in it moves less on the same flow.
        $strategy = new MarketMakerStrategy();
        $capacity = 1000000.0 * FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;

        $marketIsLong = $strategy->signal($this->view(), ['fundamentalist' => $capacity * 0.5, 'market_maker' => 0.0]);
        $marketIsShort = $strategy->signal($this->view(), ['fundamentalist' => -$capacity * 0.5, 'market_maker' => 0.0]);

        $this->assertLessThan(0.0, $marketIsLong);
        $this->assertGreaterThan(0.0, $marketIsShort);
    }

    public function testTheMakerWorksItsOwnInventoryBackTowardFlat(): void
    {
        // Carrying risk is not what a maker is paid for.
        $strategy = new MarketMakerStrategy();
        $capacity = 1000000.0 * FinancialConstants::AGENT_CAPITAL_ADV_MULTIPLE;

        $longInventory = $strategy->signal($this->view(), ['market_maker' => $capacity * 0.5]);

        $this->assertLessThan(0.0, $longInventory, 'A long book is worked down, not added to.');
    }

    public function testTheMakerIsFlatWhenNobodyIsPositioned(): void
    {
        $strategy = new MarketMakerStrategy();

        $this->assertSame(0.0, $strategy->signal($this->view(), ['fundamentalist' => 0.0, 'market_maker' => 0.0]));
    }

    // --- Population membership ---

    public function testOnlyBeliefsAboutThePriceCompeteForCapital(): void
    {
        // A maker is an intermediary and an index fund is a decision not to have a view. Neither is chosen
        // because it beat the other side last quarter, so neither takes part in the switching.
        $this->assertTrue((new FundamentalistStrategy())->competesForCapital());
        $this->assertTrue((new MomentumStrategy())->competesForCapital());
        $this->assertFalse((new IndexFundStrategy())->competesForCapital());
        $this->assertFalse((new MarketMakerStrategy())->competesForCapital());
    }

    public function testEveryStrategyHasItsOwnIdentifier(): void
    {
        $identifiers = array_map(
            static fn (object $s): string => $s->identifier(),
            [new FundamentalistStrategy(), new MomentumStrategy(), new IndexFundStrategy(), new MarketMakerStrategy()]
        );

        $this->assertSame($identifiers, array_unique($identifiers));
    }
}
