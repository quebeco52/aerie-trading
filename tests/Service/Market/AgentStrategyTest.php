<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\AgentMarketViewDTO;
use App\Service\Market\Agent\FundamentalistStrategy;
use App\Service\Market\Agent\IndexFundStrategy;
use App\Service\Market\Agent\MarketMakerStrategy;
use App\Service\Market\Agent\MomentumStrategy;
use App\Service\Market\Agent\RelativeValueStrategy;
use App\Service\Market\Agent\VolatilityTargetStrategy;
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
        float $adv = 1000000.0,
        float $volatility = 0.0,
        ?float $marketMispricing = null
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
            annualizedVolatility: $volatility,
            marketLogMispricing: $marketMispricing,
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

    /** @param float $dt Elapsed simulated time in years. */
    private function makerView(float $volatility = 0.20, float $dt = 1.0 / 14400.0): AgentMarketViewDTO
    {
        return new AgentMarketViewDTO(
            ticker: 'TEST',
            price: 100.0,
            perceivedFairValue: 100.0,
            momentumTrend: 0.0,
            averageDailyVolume: 1000000.0,
            logReturn: 0.0,
            financialConditions: 0.0,
            dt: $dt,
            annualizedVolatility: $volatility,
        );
    }

    public function testTheMakerTakesTheOtherSideOfTheFlowNow(): void
    {
        // Immediacy: when the others buy, it sells to them in the same tick, and part of their demand
        // never reaches the price in the tick it arrived.
        $strategy = new MarketMakerStrategy();
        $capacity = 3000000.0;

        $againstBuying = $strategy->trade($this->makerView(), 0.0, 10000.0, $capacity);
        $againstSelling = $strategy->trade($this->makerView(), 0.0, -10000.0, $capacity);

        $this->assertEqualsWithDelta(-FinancialConstants::AGENT_MAKER_ABSORPTION * 10000.0, $againstBuying, 1e-9);
        $this->assertEqualsWithDelta(FinancialConstants::AGENT_MAKER_ABSORPTION * 10000.0, $againstSelling, 1e-9);
    }

    public function testTheMakerWorksItsInventoryOffOverTimeNotOverTicks(): void
    {
        // Carrying risk is not what a maker is paid for. A day of simulated time works off the same share
        // of the book whether it is stepped 57 times or 3 times.
        $strategy = new MarketMakerStrategy();
        $capacity = 3000000.0;

        $fine = -100000.0;
        for ($tick = 0; $tick < 57; $tick++) {
            $fine += $strategy->trade($this->makerView(dt: 1.0 / 14400.0), $fine, 0.0, $capacity);
        }

        $coarse = -100000.0;
        for ($tick = 0; $tick < 3; $tick++) {
            $coarse += $strategy->trade($this->makerView(dt: 19.0 / 14400.0), $coarse, 0.0, $capacity);
        }

        $this->assertGreaterThan(-100000.0, $fine, 'A short book is bought back, not added to.');
        $this->assertLessThan(0.0, $fine, 'And not all at once.');
        $this->assertEqualsWithDelta($fine, $coarse, abs($fine) * 0.02);
    }

    public function testTheMakerStepsBackWhenTheMarketIsStressed(): void
    {
        // Ho & Stoll: the cost of carrying inventory is proportional to variance. Doubling volatility
        // above the reference quarters what the maker will absorb; below the reference it absorbs in full.
        $strategy = new MarketMakerStrategy();
        $capacity = 3000000.0;
        $reference = FinancialConstants::AGENT_MAKER_REFERENCE_VOLATILITY;

        $calm = $strategy->trade($this->makerView(volatility: $reference * 0.5), 0.0, 10000.0, $capacity);
        $atReference = $strategy->trade($this->makerView(volatility: $reference), 0.0, 10000.0, $capacity);
        $stressed = $strategy->trade($this->makerView(volatility: $reference * 2.0), 0.0, 10000.0, $capacity);

        $this->assertEqualsWithDelta($atReference, $calm, 1e-9);
        $this->assertEqualsWithDelta($atReference / 4.0, $stressed, 1e-9);
    }

    public function testTheMakerNeverCarriesMoreThanItsCapacity(): void
    {
        $strategy = new MarketMakerStrategy();
        $capacity = 3000000.0;

        $trade = $strategy->trade($this->makerView(), -$capacity * 0.99, 1.0e9, $capacity);

        $this->assertEqualsWithDelta(-$capacity * 0.01, $trade, 1e-6);
    }

    public function testTheMakerIsIdleWhenNothingIsHappening(): void
    {
        $this->assertSame(0.0, (new MarketMakerStrategy())->trade($this->makerView(), 0.0, 0.0, 3000000.0));
    }

    // --- Population membership ---

    public function testOnlyBeliefsAboutThePriceCompeteForCapital(): void
    {
        // An index fund is a decision not to have a view; it is not chosen because it beat the other side
        // last quarter, so it takes no part in the switching. (A maker is not a strategy at all.)
        $this->assertTrue((new FundamentalistStrategy())->competesForCapital());
        $this->assertTrue((new MomentumStrategy())->competesForCapital());
        $this->assertFalse((new IndexFundStrategy())->competesForCapital());
    }

    public function testEveryStrategyHasItsOwnIdentifier(): void
    {
        $identifiers = array_map(
            static fn (object $s): string => $s->identifier(),
            [new FundamentalistStrategy(), new MomentumStrategy(), new IndexFundStrategy(), new MarketMakerStrategy()]
        );

        $this->assertSame($identifiers, array_unique($identifiers));
    }
    // --- Volatility targeting ---

    public function testTheVolTargetingBookIsSizedAsTargetOverRealizedVolatility(): void
    {
        // Moreira & Muir: exposure = target / realized. At target, the base share; at twice target, half
        // of it; at half target, twice it.
        $strategy = new VolatilityTargetStrategy();
        $target = FinancialConstants::AGENT_VOL_TARGET_VOLATILITY;
        $base = FinancialConstants::AGENT_VOL_TARGET_BASE_SHARE;

        $this->assertEqualsWithDelta($base, $strategy->signal($this->view(volatility: $target), []), 1e-12);
        $this->assertEqualsWithDelta($base / 2.0, $strategy->signal($this->view(volatility: 2.0 * $target), []), 1e-12);
        $this->assertEqualsWithDelta($base * 2.0, $strategy->signal($this->view(volatility: $target / 2.0), []), 1e-12);
    }

    public function testTheVolTargetingBookSellsIntoAVolatilitySpikeWhateverThePriceDid(): void
    {
        // No opinion on the price: a name that has just rallied and one that has just crashed are sized
        // the same if they now show the same volatility.
        $strategy = new VolatilityTargetStrategy();

        $calm = $strategy->signal($this->view(volatility: 0.20), []);
        $stressed = $strategy->signal($this->view(volatility: 0.60), []);
        $stressedAfterRally = $strategy->signal($this->view(price: 150.0, fairValue: 100.0, momentum: 0.3, volatility: 0.60), []);

        $this->assertLessThan($calm, $stressed);
        $this->assertSame($stressed, $stressedAfterRally);
    }

    public function testTheVolTargetingBookCannotLeverUpWithoutLimitOnAQuietTape(): void
    {
        $strategy = new VolatilityTargetStrategy();
        $capped = FinancialConstants::AGENT_VOL_TARGET_BASE_SHARE * FinancialConstants::AGENT_VOL_TARGET_MAX_LEVERAGE;

        $this->assertEqualsWithDelta($capped, $strategy->signal($this->view(volatility: 0.01), []), 1e-12);
        $this->assertEqualsWithDelta($capped, $strategy->signal($this->view(volatility: 0.001), []), 1e-12);
    }

    public function testWithoutARealizedVolatilityTheVolTargetingBookHoldsItsSizeAtTarget(): void
    {
        $strategy = new VolatilityTargetStrategy();

        $this->assertSame(FinancialConstants::AGENT_VOL_TARGET_BASE_SHARE, $strategy->signal($this->view(volatility: 0.0), []));
        $this->assertSame(FinancialConstants::AGENT_VOL_TARGET_BASE_SHARE, $strategy->signal($this->view(volatility: -1.0), []));
        $this->assertFalse($strategy->competesForCapital());
    }

    // --- Relative value ---

    public function testRelativeValueBuysWhatIsCheapAgainstTheMarketNotWhatIsCheap(): void
    {
        // A name 10% below fair value in a market that is 10% below fair value is not cheap to a
        // relative-value book; the same name in a fairly priced market is.
        $strategy = new RelativeValueStrategy();
        $ownMispricing = $this->view(price: 90.0, fairValue: 100.0)->logMispricing();

        $this->assertEqualsWithDelta(0.0, $strategy->signal($this->view(price: 90.0, fairValue: 100.0, marketMispricing: $ownMispricing), []), 1e-12);
        $this->assertGreaterThan(0.0, $strategy->signal($this->view(price: 90.0, fairValue: 100.0, marketMispricing: 0.0), []));
        $this->assertLessThan(0.0, $strategy->signal($this->view(price: 100.0, fairValue: 100.0, marketMispricing: $ownMispricing), []), 'Fairly priced in a cheap market is dear relative to it.');
    }

    public function testRelativeValueIsMarketNeutralAcrossTheNamesItSees(): void
    {
        // The signals across a cross-section sum to zero whenever none of them is at its bound: the longs
        // are funded by the shorts, and a market-wide re-rating leaves the book flat.
        $strategy = new RelativeValueStrategy();
        $prices = [92.0, 97.0, 103.0, 108.0];

        $mispricings = array_map(fn (float $price): float => $this->view(price: $price, fairValue: 100.0)->logMispricing(), $prices);
        $average = array_sum($mispricings) / count($mispricings);

        $total = 0.0;
        foreach ($prices as $price) {
            $total += $strategy->signal($this->view(price: $price, fairValue: 100.0, marketMispricing: $average), []);
        }

        $this->assertEqualsWithDelta(0.0, $total, 1e-12);
    }

    public function testRelativeValueHoldsNothingUntilTheCrossSectionIsKnown(): void
    {
        // Falling back to the absolute mispricing would make it a second fundamentalist on the first tick.
        $strategy = new RelativeValueStrategy();

        $this->assertSame(0.0, $strategy->signal($this->view(price: 50.0, fairValue: 100.0), []));
        $this->assertFalse($strategy->competesForCapital());
    }

    public function testRelativeValueConvictionIsBoundedByItsShareOfCapital(): void
    {
        $strategy = new RelativeValueStrategy();
        $share = FinancialConstants::AGENT_RELATIVE_VALUE_SHARE;

        $this->assertEqualsWithDelta($share, $strategy->signal($this->view(price: 10.0, fairValue: 100.0, marketMispricing: 0.0), []), 1e-12);
        $this->assertEqualsWithDelta(-$share, $strategy->signal($this->view(price: 1000.0, fairValue: 100.0, marketMispricing: 0.0), []), 1e-12);
    }
}
