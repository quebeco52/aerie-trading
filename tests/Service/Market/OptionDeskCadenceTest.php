<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\OptionContract;
use App\Service\Market\DealerGammaEngine;
use App\Service\Market\Gamma\InMemoryDealerGammaStore;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\OptionDeskService;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The option desk's cadence, which exists for one reason: a full sweep rewrites every listed contract in
 * the market, and running that on the history tick put tens of thousands of UPDATEs a second through the
 * database. Spreading it over slices is only safe if every name is still visited on a fixed period, and
 * moving the hedge off every tick is only safe if hedging less often trades the same amount.
 *
 * Both of those are properties, not opinions, so they are asserted here.
 */
class OptionDeskCadenceTest extends TestCase
{
    // --- Slicing ---

    public function testEveryNameIsVisitedExactlyOncePerFullRotation(): void
    {
        $interval = OptionDeskService::sweepIntervalTicks(14400);
        $seen = [];

        for ($pass = 0; $pass < OptionDeskService::SWEEP_SLICES; $pass++) {
            $seen[] = OptionDeskService::sweepSlice($pass * $interval, $interval);
        }

        sort($seen);

        $this->assertSame(range(0, OptionDeskService::SWEEP_SLICES - 1), $seen);
    }

    public function testTheRotationRepeatsRatherThanDrifting(): void
    {
        $interval = OptionDeskService::sweepIntervalTicks(14400);
        $slices = OptionDeskService::SWEEP_SLICES;

        // A name in slice n must come round again exactly one rotation later, or its chain ages without
        // bound while another is remarked twice.
        for ($pass = 0; $pass < 40; $pass++) {
            $this->assertSame(
                OptionDeskService::sweepSlice($pass * $interval, $interval),
                OptionDeskService::sweepSlice(($pass + $slices) * $interval, $interval)
            );
        }
    }

    public function testAFullRotationTakesAboutASimulatedWeek(): void
    {
        $ticksPerYear = 14400;
        $rotationTicks = OptionDeskService::sweepIntervalTicks($ticksPerYear) * OptionDeskService::SWEEP_SLICES;

        $weeks = ($rotationTicks / $ticksPerYear) * 52.0;

        $this->assertEqualsWithDelta(1.0, $weeks, 0.15);
    }

    public function testTheIntervalStaysAtLeastOneTickAtAnyTickRate(): void
    {
        foreach ([52, 240, 2400, 14400, 54000] as $ticksPerYear) {
            $this->assertGreaterThanOrEqual(1, OptionDeskService::sweepIntervalTicks($ticksPerYear));
        }
    }

    // --- The Hedge Telescopes ---

    public function testHedgingOnceAcrossABarTradesWhatHedgingEveryTickWouldHave(): void
    {
        // This is the property that lets the hedge come off the per-tick path. The hedge is gamma times the
        // move since the last one, so the intermediate reference prices cancel: what matters is where the
        // price started and where it ended, not how many times it was looked at in between.
        $path = [100.0, 100.4, 99.8, 101.2, 100.9, 102.5];

        $everyTick = $this->hedgedAlong($path, 1);
        $onceAcross = $this->hedgedAlong($path, count($path) - 1);

        $this->assertEqualsWithDelta($everyTick, $onceAcross, 1e-9);
    }

    public function testTheSameHoldsForAPathThatEndsBelowWhereItStarted(): void
    {
        $path = [100.0, 102.0, 97.5, 96.0];

        $this->assertEqualsWithDelta($this->hedgedAlong($path, 1), $this->hedgedAlong($path, 3), 1e-9);
        $this->assertLessThan(0.0, $this->hedgedAlong($path, 3));
    }

    /**
     * Walks a price path, hedging every $stride steps, and reports the total signed shares traded.
     *
     * @param array<int, float> $path
     */
    private function hedgedAlong(array $path, int $stride): float
    {
        $store = new InMemoryDealerGammaStore();
        $engine = new DealerGammaEngine($store, new LiquidityEngine(new MathUtility()));

        $stock = StockBuilder::create('VANE')
            ->withPrice($path[0])
            ->withSharesOutstanding(500_000_000)
            ->withPublicFloatPercentage(1.0)
            ->build();

        $contract = (new OptionContract())
            ->setTicker('VANE-13C100')
            ->setOptionType(OptionContract::TYPE_CALL)
            ->setStrike('100')
            ->setExpirySerial(13)
            ->setExpiresAtTime(13.0 / 12.0)
            ->setStructuralOpenInterest(2000);

        $quotes = ['VANE-13C100' => new \App\DTO\OptionQuoteDTO(
            mark: 5.0, bid: 4.9, ask: 5.1, impliedVolatility: 0.30,
            delta: 0.5, gamma: 0.0002, vega: 20.0, theta: -8.0, rho: 15.0,
            timeToExpiry: 0.25, riskFreeRate: 0.04, dividendYield: 0.0,
        )];

        $engine->refresh($stock, [$contract], $quotes);

        $traded = 0.0;

        for ($step = 1; $step < count($path); $step++) {
            $stock->setPrice((string) $path[$step]);

            if ($step % $stride === 0 || $step === count($path) - 1) {
                $traded += array_sum($engine->hedgeMarket([$stock]));
            }
        }

        return $traded;
    }

    // --- Bulk Store ---

    public function testTheBulkStoreRoundTripsWhatTheSingleWriterPutIn(): void
    {
        $store = new InMemoryDealerGammaStore();
        $store->record('VANE', 1200.0, 100.0);
        $store->record('OWLS', -400.0, 40.0);

        $all = $store->readAll();

        $this->assertEqualsWithDelta(1200.0, $all['VANE']['gamma'], 1e-9);
        $this->assertEqualsWithDelta(40.0, $all['OWLS']['reference_price'], 1e-9);
    }

    public function testABulkWriteMovesOnlyTheNamesItNames(): void
    {
        $store = new InMemoryDealerGammaStore();
        $store->record('VANE', 1200.0, 100.0);
        $store->record('OWLS', -400.0, 40.0);

        $store->recordAll(['VANE' => ['gamma' => 1200.0, 'reference_price' => 105.0]]);

        $this->assertEqualsWithDelta(105.0, $store->read('VANE')['reference_price'], 1e-9);
        $this->assertEqualsWithDelta(40.0, $store->read('OWLS')['reference_price'], 1e-9);
    }

    public function testABankruptNameIsNotHedged(): void
    {
        $store = new InMemoryDealerGammaStore();
        $engine = new DealerGammaEngine($store, new LiquidityEngine(new MathUtility()));

        $stock = StockBuilder::create('DEAD')->withPrice(100.0)->withSharesOutstanding(50_000_000)->build();
        $store->record('DEAD', 5000.0, 90.0);
        $stock->setIsBankrupt(true);

        $this->assertSame([], $engine->hedgeMarket([$stock]));
    }

    public function testTheHedgeRatioIsWhatBoundsTheFlowNotTheContractMultiplier(): void
    {
        // Guards the unit: gamma is already in shares per 1.00 of underlying, so the multiplier has been
        // applied when the exposure was measured and must not be applied again when it is traded.
        $store = new InMemoryDealerGammaStore();
        $engine = new DealerGammaEngine($store, new LiquidityEngine(new MathUtility()));

        $stock = StockBuilder::create('VANE')
            ->withPrice(101.0)
            ->withSharesOutstanding(500_000_000)
            ->withPublicFloatPercentage(1.0)
            ->build();

        $store->record('VANE', 10_000.0, 100.0);

        $this->assertEqualsWithDelta(
            10_000.0 * 1.0 * FinancialConstants::DEALER_HEDGE_RATIO,
            $engine->hedgeMarket([$stock])['VANE'],
            1e-6
        );
    }
}
