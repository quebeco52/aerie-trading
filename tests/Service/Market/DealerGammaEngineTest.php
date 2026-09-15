<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Service\Market\DealerGammaEngine;
use App\Service\Market\Gamma\InMemoryDealerGammaStore;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\OptionDemandEngine;
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
        $this->assertGreaterThan(0.0, $this->engine->hedgeFlow($stock));

        $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.02)]);
        $stock->setPrice('99.00000000');
        $this->assertLessThan(0.0, $this->engine->hedgeFlow($stock));
    }

    public function testALongGammaDeskLeansAgainstTheMove(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(-500)], ['VANE-13C100' => $this->quote(0.02)]);

        $stock->setPrice('101.00000000');

        // The desk that is long gamma sells a rally, which is the stabilising side of the same channel.
        $this->assertLessThan(0.0, $this->engine->hedgeFlow($stock));
    }

    public function testHedgeSizeIsGammaTimesTheMoveTimesTheHedgeRatio(): void
    {
        $stock = $this->stock(100.0);
        $gamma = $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.002)]);

        $stock->setPrice('102.00000000');

        $this->assertEqualsWithDelta(
            $gamma * 2.0 * FinancialConstants::DEALER_HEDGE_RATIO,
            $this->engine->hedgeFlow($stock),
            1e-6
        );
    }

    // --- The Same Move Is Never Hedged Twice ---

    public function testAMoveAlreadyHedgedProducesNoFurtherFlow(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(500)], ['VANE-13C100' => $this->quote(0.002)]);

        $stock->setPrice('102.00000000');
        $this->assertGreaterThan(0.0, $this->engine->hedgeFlow($stock));

        // Nothing has moved since; the book is already flat against it.
        $this->assertSame(0.0, $this->engine->hedgeFlow($stock));
    }

    public function testAnUnpricedNameHedgesNothing(): void
    {
        $this->assertSame(0.0, $this->engine->hedgeFlow($this->stock()));
    }

    public function testAChainWithNoOpenInterestHedgesNothing(): void
    {
        $stock = $this->stock(100.0);
        $this->engine->refresh($stock, [$this->contract(0)], ['VANE-13C100' => $this->quote(0.04)]);

        $stock->setPrice('110.00000000');

        $this->assertSame(0.0, $this->engine->hedgeFlow($stock));
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

        $this->assertEqualsWithDelta($ceiling, $this->engine->hedgeFlow($stock), 1e-6);
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
