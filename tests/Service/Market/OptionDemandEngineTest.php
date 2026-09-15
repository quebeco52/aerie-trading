<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\OptionDemandEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The public's standing option book. The properties that matter are the three empirical facts the model is
 * built on: the public is a net BUYER, it buys out of the money, and in single names it buys calls until it
 * gets frightened.
 */
class OptionDemandEngineTest extends TestCase
{
    private OptionDemandEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new OptionDemandEngine(new LiquidityEngine(new MathUtility()));
    }

    private function stock(): \App\Entity\Stock
    {
        return StockBuilder::create('VANE')
            ->withPrice(100.0)
            ->withSharesOutstanding(100_000_000)
            ->withPublicFloatPercentage(1.0)
            ->build();
    }

    private function quote(float $delta, float $timeToExpiry = 0.25): OptionQuoteDTO
    {
        return new OptionQuoteDTO(
            mark: 5.0, bid: 4.9, ask: 5.1, impliedVolatility: 0.30,
            delta: $delta, gamma: 0.03, vega: 20.0, theta: -8.0, rho: 15.0,
            timeToExpiry: $timeToExpiry, riskFreeRate: 0.04, dividendYield: 0.0,
        );
    }

    private function contract(string $ticker, bool $isCall): OptionContract
    {
        return (new OptionContract())
            ->setTicker($ticker)
            ->setOptionType($isCall ? OptionContract::TYPE_CALL : OptionContract::TYPE_PUT)
            ->setStrike('100')
            ->setExpirySerial(13)
            ->setExpiresAtTime(13.0 / 12.0);
    }

    // --- Where Demand Sits ---

    public function testDemandPeaksAtTheDeltaThePublicActuallyBuys(): void
    {
        $atTarget = $this->engine->demandWeight($this->quote(FinancialConstants::OPTION_PUBLIC_TARGET_DELTA));
        $atTheMoney = $this->engine->demandWeight($this->quote(0.50));
        $farWing = $this->engine->demandWeight($this->quote(0.02));

        $this->assertGreaterThan($atTheMoney, $atTarget);
        $this->assertGreaterThan($farWing, $atTarget);
    }

    public function testDemandIsTheSameOnEitherSideOfTheMoneyAtEqualAbsoluteDelta(): void
    {
        // A put's delta is negative; where the contract sits on the ladder is its ABSOLUTE delta.
        $this->assertEqualsWithDelta(
            $this->engine->demandWeight($this->quote(0.30)),
            $this->engine->demandWeight($this->quote(-0.30)),
            1e-12
        );
    }

    public function testDemandConcentratesInTheFrontMonths(): void
    {
        $near = $this->engine->demandWeight($this->quote(0.30, 1.0 / 12.0));
        $far = $this->engine->demandWeight($this->quote(0.30, 1.0));

        $this->assertGreaterThan($far, $near);
    }

    // --- Which Side The Public Buys ---

    public function testSingleNameDemandIsCallLedInCalmConditions(): void
    {
        $share = $this->engine->callShare(0.13, 0.13);

        $this->assertSame(FinancialConstants::OPTION_PUBLIC_CALL_SHARE, $share);
        $this->assertGreaterThan(0.5, $share);
    }

    public function testFearSwingsDemandFromCallsToPuts(): void
    {
        $calm = $this->engine->callShare(0.13, 0.13);
        $frightened = $this->engine->callShare(0.26, 0.13);

        $this->assertLessThan($calm, $frightened);
        $this->assertLessThan(0.5, $frightened);
    }

    public function testTheCallShareStaysAProportionInAPanic(): void
    {
        $this->assertGreaterThanOrEqual(0.0, $this->engine->callShare(2.00, 0.13));
        $this->assertLessThanOrEqual(1.0, $this->engine->callShare(0.01, 0.13));
    }

    public function testACalmerThanNormalMarketDoesNotManufactureExtraCallDemand(): void
    {
        // Only fear moves the tilt; a quiet tape leaves it at its base rather than pushing past it.
        $this->assertSame(
            FinancialConstants::OPTION_PUBLIC_CALL_SHARE,
            $this->engine->callShare(0.05, 0.13)
        );
    }

    // --- How The Book Is Built ---

    public function testThePublicBookConvergesToItsBudgetAndStaysNetLong(): void
    {
        $stock = $this->stock();
        $contracts = [$this->contract('VANE-13C100', true), $this->contract('VANE-13P100', false)];
        $quotes = [
            'VANE-13C100' => $this->quote(0.30),
            'VANE-13P100' => $this->quote(-0.30),
        ];

        for ($i = 0; $i < 500; $i++) {
            $this->engine->evolve($stock, $contracts, $quotes, 0.13, 0.13, 0.01);
        }

        $total = array_sum(array_map(static fn (OptionContract $c): int => $c->customerOpenInterest(), $contracts));

        $this->assertGreaterThan(0, $total);
        $this->assertEqualsWithDelta($this->engine->contractBudget($stock), (float) $total, 2.0);
    }

    public function testTheBookIsBuiltOverTimeRatherThanInOneTick(): void
    {
        $stock = $this->stock();
        $contracts = [$this->contract('VANE-13C100', true)];
        $quotes = ['VANE-13C100' => $this->quote(0.30)];

        $this->engine->evolve($stock, $contracts, $quotes, 0.13, 0.13, 1.0 / 14400.0);

        $afterOneTick = $contracts[0]->customerOpenInterest();

        $this->assertGreaterThan(0, $afterOneTick);
        $this->assertLessThan($this->engine->contractBudget($stock) * 0.05, (float) $afterOneTick);
    }

    public function testAFrightenedMarketRebuildsTheBookOnThePutSide(): void
    {
        $stock = $this->stock();
        $contracts = [$this->contract('VANE-13C100', true), $this->contract('VANE-13P100', false)];
        $quotes = [
            'VANE-13C100' => $this->quote(0.30),
            'VANE-13P100' => $this->quote(-0.30),
        ];

        for ($i = 0; $i < 500; $i++) {
            $this->engine->evolve($stock, $contracts, $quotes, 0.30, 0.13, 0.01);
        }

        $this->assertGreaterThan(
            $contracts[0]->customerOpenInterest(),
            $contracts[1]->customerOpenInterest(),
            'a frightened public should hold more puts than calls'
        );
    }

    public function testABiggerNameCarriesABiggerBook(): void
    {
        $small = StockBuilder::create('TINY')->withPrice(100.0)->withSharesOutstanding(1_000_000)->build();
        $large = $this->stock();

        $this->assertGreaterThan($this->engine->contractBudget($small), $this->engine->contractBudget($large));
    }

    public function testAnEmptyChainIsLeftAlone(): void
    {
        $this->engine->evolve($this->stock(), [], [], 0.13, 0.13, 0.01);

        $this->addToAssertionCount(1);
    }
}
