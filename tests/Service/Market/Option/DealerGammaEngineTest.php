<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Option;

use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Service\Market\Option\DealerGammaEngine;
use App\Service\Market\Option\InMemoryDealerGammaStore;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Option\OptionDemandEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The hedging channel. What is being checked here is not arithmetic but SIGN and BOUND: a desk short gamma
 * has to chase the price, a desk long gamma has to lean against it, and neither may demand more liquidity
 * in one tick than the name can supply.
 */
class DealerGammaEngineTest extends TestCase
{
    /** One trading day, in years: the pass length the per-day cap is stated in. */
    private const DAY = 1.0 / FinancialConstants::TRADING_DAYS_PER_YEAR;

    private InMemoryDealerGammaStore $store;
    private DealerGammaEngine $engine;

    protected function setUp(): void
    {
        $this->store = new InMemoryDealerGammaStore();
        $this->engine = new DealerGammaEngine($this->store, new LiquidityEngine(new MathUtility()));
    }

    private function stock(float $price = 100.0): \App\Entity\Stock
    {
        return StockBuilder::create('VANE')
            ->withPrice($price)
            ->withSharesOutstanding(100_000_000)
            ->withPublicFloatPercentage(1.0)
            ->build();
    }

    private function contract(int $customerOpenInterest, bool $isCall = true): OptionContract
    {
        return (new OptionContract())
            ->setTicker('VANE-13' . ($isCall ? 'C' : 'P') . '100')
            ->setOptionType($isCall ? OptionContract::TYPE_CALL : OptionContract::TYPE_PUT)
            ->setStrike('100')
            ->setExpirySerial(13)
            ->setExpiresAtTime(13.0 / 12.0)
            ->setStructuralOpenInterest($customerOpenInterest);
    }

    private function quote(float $gamma, float $delta = 0.5): OptionQuoteDTO
    {
        return new OptionQuoteDTO(
            mark: 5.0, bid: 4.9, ask: 5.1, impliedVolatility: 0.30,
            delta: $delta, gamma: $gamma, vega: 20.0, theta: -8.0, rho: 15.0,
            timeToExpiry: 0.25, riskFreeRate: 0.04, dividendYield: 0.0,
        );
    }

    // --- Exposure ---

    public function testExposureIsOpenInterestTimesGammaTimesTheContractMultiplier(): void
    {
        $stock = $this->stock();
        $contracts = [$this->contract(500)];
        $quotes = ['VANE-13C100' => $this->quote(0.04)];

        $gamma = $this->engine->refresh($stock, $contracts, $quotes);

        $this->assertEqualsWithDelta(500 * FinancialConstants::OPTION_CONTRACT_MULTIPLIER * 0.04, $gamma, 1e-9);
    }

    public function testCallsAndPutsBothAddToTheDesksShortGammaWhenThePublicIsLongThem(): void
    {
        $stock = $this->stock();
        $contracts = [$this->contract(300, true), $this->contract(300, false)];
        $contracts[1]->setTicker('VANE-13P100');

        $quotes = [
            'VANE-13C100' => $this->quote(0.04),
            'VANE-13P100' => $this->quote(0.04, -0.5),
        ];

        // Gamma is positive on both sides, so buying either leaves the desk short gamma. A signed-by-side
        // sum would have let a balanced book cancel to zero and switched the channel off.
        $this->assertGreaterThan(0.0, $this->engine->refresh($stock, $contracts, $quotes));
    }

    public function testAPublicNetSHORTLeavesTheDeskLongGamma(): void
    {
        $stock = $this->stock();
        $contracts = [$this->contract(-400)];
        $quotes = ['VANE-13C100' => $this->quote(0.04)];

        $this->assertLessThan(0.0, $this->engine->refresh($stock, $contracts, $quotes));
    }

    // --- Hedging Direction ---

    public function testAShortGammaDeskBuysIntoARallyAndSellsIntoASelloff(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.02)]);

        $stock->setPrice('101.00000000');
        $this->assertGreaterThan(0.0, $this->engine->hedgeFlow($stock, self::DAY));

        $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.02)]);
        $stock->setPrice('99.00000000');
        $this->assertLessThan(0.0, $this->engine->hedgeFlow($stock, self::DAY));
    }

    public function testALongGammaDeskLeansAgainstTheMove(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(-500)], ['VANE-13C100' => $this->quote(0.02)]);

        $stock->setPrice('101.00000000');

        // The desk that is long gamma sells a rally, which is the stabilising side of the same channel.
        $this->assertLessThan(0.0, $this->engine->hedgeFlow($stock, self::DAY));
    }

    public function testHedgeSizeIsGammaTimesTheMoveTimesTheHedgeRatio(): void
    {
        $stock = $this->stock(100.0);
        $gamma = $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.002)]);

        $stock->setPrice('102.00000000');

        $this->assertEqualsWithDelta(
            $gamma * 2.0 * FinancialConstants::DEALER_HEDGE_RATIO,
            $this->engine->hedgeFlow($stock, self::DAY),
            1e-6
        );
    }

    // --- The Same Move Is Never Hedged Twice ---

    public function testAMoveAlreadyHedgedProducesNoFurtherFlow(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.002)]);

        $stock->setPrice('102.00000000');
        $this->assertGreaterThan(0.0, $this->engine->hedgeFlow($stock, self::DAY));

        // Nothing has moved since; the book is already flat against it.
        $this->assertSame(0.0, $this->engine->hedgeFlow($stock, self::DAY));
    }

    public function testAnUnpricedNameHedgesNothing(): void
    {
        $this->assertSame(0.0, $this->engine->hedgeFlow($this->stock(), self::DAY));
    }

    public function testAChainWithNoOpenInterestHedgesNothing(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(0)], ['VANE-13C100' => $this->quote(0.04)]);

        $stock->setPrice('110.00000000');

        $this->assertSame(0.0, $this->engine->hedgeFlow($stock, self::DAY));
    }

    // --- The Liquidity Bound ---

    public function testHedgingFlowCannotExceedWhatTheNameCanFill(): void
    {
        $stock = $this->stock(100.0);

        // A deliberately enormous book against a huge move.
        $this->engine->refresh($stock, [$this->contract(10_000_000)], ['VANE-13C100' => $this->quote(0.10)]);
        $stock->setPrice('140.00000000');

        $ceiling = (new LiquidityEngine(new MathUtility()))->averageDailyVolume($stock)
            * FinancialConstants::MAX_DEALER_HEDGE_ADV_MULTIPLE;

        $this->assertEqualsWithDelta($ceiling, $this->engine->hedgeFlow($stock, self::DAY), 1e-6);
    }

    public function testADaysClippedHedgeIsTheSameAtAnyHedgingCadence(): void
    {
        $daily = $this->hedgedOverOneDay(1);
        $fine = $this->hedgedOverOneDay(5);

        // The cap is per trading day, so five passes of a fifth of a day fill what one pass of a day fills.
        $this->assertGreaterThan(0.0, $daily);
        $this->assertEqualsWithDelta($daily, $fine, 1e-6 * $daily);
    }

    public function testAClippedHedgeCompletesOverLaterPasses(): void
    {
        $stock = $this->stock(100.0);
        $gamma = $this->engine->refresh($stock, [$this->contract(10_000_000)], ['VANE-13C100' => $this->quote(0.10)]);
        $stock->setPrice('140.00000000');

        $wanted = $gamma * 40.0 * FinancialConstants::DEALER_HEDGE_RATIO;
        $first = $this->engine->hedgeFlow($stock, self::DAY);
        $this->assertLessThan($wanted, $first);

        $total = $first;
        for ($pass = 0; $pass < 100_000 && $total < $wanted * (1.0 - 1e-9); $pass++) {
            $total += $this->engine->hedgeFlow($stock, self::DAY);
        }

        // The price has not moved again, so the later passes trade exactly the owed remainder and then stop.
        $this->assertEqualsWithDelta($wanted, $total, 1e-6 * $wanted);
        $this->assertSame(0.0, $this->engine->hedgeFlow($stock, self::DAY));
    }

    public function testARequoteKeepsTheHedgeStillOwed(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.02)]);
        $stock->setPrice('104.00000000');
        $owed = 500 * FinancialConstants::OPTION_CONTRACT_MULTIPLIER * 0.02 * 4.0 * FinancialConstants::DEALER_HEDGE_RATIO;

        // The chain is re-quoted at double the gamma before the desk has hedged: the delta it owes is unchanged.
        $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.04)]);

        $this->assertEqualsWithDelta($owed, $this->engine->hedgeFlow($stock, self::DAY), 1e-6);
    }

    /**
     * Shares hedged over one trading day after a gap that the per-day cap clips, in a given number of passes.
     */
    private function hedgedOverOneDay(int $passes): float
    {
        $store = new InMemoryDealerGammaStore();
        $engine = new DealerGammaEngine($store, new LiquidityEngine(new MathUtility()));
        $stock = $this->stock(100.0);
        $engine->refresh($stock, [$this->contract(10_000_000)], ['VANE-13C100' => $this->quote(0.10)]);
        $stock->setPrice('140.00000000');

        $total = 0.0;
        for ($i = 0; $i < $passes; $i++) {
            $total += $engine->hedgeFlow($stock, self::DAY / $passes);
        }

        return $total;
    }

    // --- The Public Is Net Long, Which Is What Makes The Desk Short ---

    public function testThePublicsDemandLeavesTheDeskShortGammaByConstruction(): void
    {
        $demand = new OptionDemandEngine(new LiquidityEngine(new MathUtility()));
        $stock = $this->stock();

        $contracts = [$this->contract(0, true), $this->contract(0, false)];
        $contracts[1]->setTicker('VANE-13P100');

        $quotes = [
            'VANE-13C100' => $this->quote(0.03, 0.30),
            'VANE-13P100' => $this->quote(0.03, -0.30),
        ];

        // Run the demand model out to its target, then measure what the desk is left holding.
        for ($i = 0; $i < 400; $i++) {
            $demand->evolve($stock, $contracts, $quotes, 0.13, 0.13, 0.01);
        }

        $this->assertGreaterThan(0, $contracts[0]->customerOpenInterest());
        $this->assertGreaterThan(0.0, $this->engine->refresh($stock, $contracts, $quotes));
    }
}
