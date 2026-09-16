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

    // --- The Listing Grid Is Not Physics ---

    /**
     * THE INVARIANT THAT LETS THE LADDER BE TUNED. The book is spread over a demand profile, and a listed
     * ladder only samples that profile, so listing fewer strikes across the same range must move where the
     * public's contracts sit and NOT how much convexity the desk is short. Break it and the strike grid
     * becomes a hedging parameter: thinning the wings to save rows would lift dealer gamma by a fifth and
     * amplify every move in the market, for a change that was supposed to be about row counts.
     */
    public function testThinningTheLadderMovesRowsAndNotTheDesksGamma(): void
    {
        // The production pattern: every rung near the money, every third in the wings, the ends always kept.
        $dense = $this->ladder(range(-6, 6));
        $thin = $this->ladder([-6, -3, -2, -1, 0, 1, 2, 3, 6]);

        $this->assertLessThan(count($dense['contracts']), count($thin['contracts']));

        $this->assertEqualsWithDelta(
            $this->settledGamma($dense),
            $this->settledGamma($thin),
            $this->settledGamma($dense) * 0.05,
            'the same book spread over a coarser sampling of the same range is the same exposure'
        );
    }

    /**
     * One side of one expiry, sampled at the given rungs. Delta falls as the strike rises, which is what
     * puts the wings close together in delta and the money far apart — the shape the density has to answer.
     *
     * @param list<int> $rungs Strike offsets from the money, in increments.
     * @return array{contracts: list<OptionContract>, quotes: array<string, OptionQuoteDTO>}
     */
    private function ladder(array $rungs): array
    {
        $contracts = [];
        $quotes = [];

        foreach ($rungs as $rung) {
            $ticker = 'VANE-13C' . ($rung + 6);
            // Black-Scholes delta against the offset: near one deep in, near zero deep out.
            $delta = 1.0 / (1.0 + exp($rung * 0.9));

            $contracts[] = $this->contract($ticker, true);
            $quotes[$ticker] = new OptionQuoteDTO(
                mark: 5.0, bid: 4.9, ask: 5.1, impliedVolatility: 0.30,
                delta: $delta, gamma: 0.04 * (1.0 - abs(2.0 * $delta - 1.0)), vega: 20.0, theta: -8.0, rho: 15.0,
                timeToExpiry: 0.25, riskFreeRate: 0.04, dividendYield: 0.0,
            );
        }

        return ['contracts' => $contracts, 'quotes' => $quotes];
    }

    /**
     * What the desk is short once the book has settled: open interest times gamma, over the whole ladder.
     *
     * @param array{contracts: list<OptionContract>, quotes: array<string, OptionQuoteDTO>} $ladder
     */
    private function settledGamma(array $ladder): float
    {
        $stock = $this->stock();

        for ($i = 0; $i < 500; $i++) {
            $this->engine->evolve($stock, $ladder['contracts'], $ladder['quotes'], 0.13, 0.13, 0.01);
        }

        $gamma = 0.0;

        foreach ($ladder['contracts'] as $contract) {
            $gamma += $contract->customerOpenInterest()
                * FinancialConstants::OPTION_CONTRACT_MULTIPLIER
                * $ladder['quotes'][$contract->getTicker()]->gamma;
        }

        return $gamma;
    }
}
