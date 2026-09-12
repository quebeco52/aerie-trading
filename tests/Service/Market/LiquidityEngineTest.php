<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Stock;
use App\Service\Market\LiquidityEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Depth, spread and impact.
 *
 * The properties asserted here are the ones that make order size mean something. Before this engine, any
 * order of any size filled at the last published price, so a player with enough cash could buy an entire
 * float at mid and leave the quote untouched.
 */
class LiquidityEngineTest extends TestCase
{
    private LiquidityEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new LiquidityEngine(new MathUtility());
    }

    private function stock(
        float $shares = 5.0e8,
        float $float = 0.90,
        ?float $turnover = 1.20,
        float $volatility = 0.28,
        ?float $currentVolatility = null
    ): Stock {
        $stock = new Stock();
        $stock->setTicker('TEST')
            ->setName('test')
            ->setSharesOutstanding((string) $shares)
            ->setPublicFloatPercentage((string) $float)
            ->setVolatility((string) $volatility)
            ->setCurrentVolatility((string) ($currentVolatility ?? $volatility))
            ->setPrice('100');

        $stock->setTurnoverRatio($turnover);

        return $stock;
    }

    // --- Depth ---

    public function testDailyVolumeIsTheFloatTurnedOverAcrossATradingYear(): void
    {
        $stock = $this->stock(shares: 5.0e8, float: 0.90, turnover: 1.20);

        $this->assertEqualsWithDelta(
            (5.0e8 * 0.90 * 1.20) / FinancialConstants::TRADING_DAYS_PER_YEAR,
            $this->engine->averageDailyVolume($stock),
            1.0
        );
    }

    public function testOnlyTheFloatTrades(): void
    {
        // Closely held stock is not available to trade, so a name with a small float is thin even when its
        // share count is large. Ignoring the float would make every controlled company look deeply liquid.
        $wideFloat = $this->engine->averageDailyVolume($this->stock(float: 0.95));
        $tightFloat = $this->engine->averageDailyVolume($this->stock(float: 0.15));

        $this->assertGreaterThan($tightFloat * 5.0, $wideFloat);
    }

    public function testDepthIsFlooredSoADeadNameIsIlliquidRatherThanUntradable(): void
    {
        // sqrt(Q/ADV) diverges as ADV approaches zero, which would price any order at infinity.
        $stock = $this->stock(shares: 100.0, float: 0.01, turnover: 0.01);

        $this->assertSame(FinancialConstants::MIN_ADV_SHARES, $this->engine->averageDailyVolume($stock));
    }

    public function testABusyTapeCarriesMoreVolumeThanAQuietOne(): void
    {
        $calm = $this->engine->averageDailyVolume($this->stock(volatility: 0.28, currentVolatility: 0.28));
        $stressed = $this->engine->averageDailyVolume($this->stock(volatility: 0.28, currentVolatility: 0.70));

        $this->assertGreaterThan($calm, $stressed);
    }

    public function testTheActivityMultiplierIsBoundedInBothDirections(): void
    {
        // Liquidity genuinely dries up in a panic, but not to zero; and a rally does not manufacture
        // unlimited depth.
        $collapsed = $this->engine->activityMultiplier($this->stock(volatility: 0.28, currentVolatility: 0.001));
        $exploded = $this->engine->activityMultiplier($this->stock(volatility: 0.28, currentVolatility: 5.0));

        $this->assertSame(FinancialConstants::MIN_ADV_ACTIVITY_MULTIPLIER, $collapsed);
        $this->assertSame(FinancialConstants::MAX_ADV_ACTIVITY_MULTIPLIER, $exploded);
    }

    // --- Spread ---

    public function testSpreadWidensWithVolatilityAndNarrowsWithVolume(): void
    {
        $liquid = $this->engine->halfSpreadFraction($this->stock(shares: 2.0e9, volatility: 0.20));
        $thin = $this->engine->halfSpreadFraction($this->stock(shares: 5.0e6, volatility: 0.70));

        $this->assertGreaterThan($liquid * 10.0, $thin);
    }

    public function testAMegaCapSpreadIsCheapButNeverFree(): void
    {
        $halfSpread = $this->engine->halfSpreadFraction($this->stock(shares: 2.0e9, float: 0.95, volatility: 0.22));

        $this->assertGreaterThanOrEqual(FinancialConstants::MIN_HALF_SPREAD, $halfSpread);
        $this->assertLessThan(0.0005, $halfSpread, 'A mega-cap should quote inside five basis points.');
    }

    public function testTheSpreadIsCappedSoADistressedNameStaysTradable(): void
    {
        $halfSpread = $this->engine->halfSpreadFraction($this->stock(shares: 1.0e5, float: 0.10, turnover: 0.05, volatility: 3.0));

        $this->assertSame(FinancialConstants::MAX_HALF_SPREAD, $halfSpread);
    }

    // --- Impact ---

    /**
     * The canonical statement of the square-root law, and the calibration this engine is pinned to.
     */
    public function testTradingOneFullDaysVolumeMovesThePriceByOneDailySigma(): void
    {
        foreach ([0.15, 0.28, 0.55, 0.90] as $volatility) {
            $stock = $this->stock(volatility: $volatility);
            $impact = $this->engine->permanentImpact($stock, $this->engine->averageDailyVolume($stock));

            $this->assertEqualsWithDelta($this->engine->dailyVolatility($stock), $impact, 1e-12);
        }
    }

    public function testPermanentImpactIsLinearInSize(): void
    {
        // Linear, not concave. The permanent leg is kept in the price and applied every tick, so it has to
        // be additive: a concave law would let the same flow leave a bigger mark simply by arriving in
        // more, smaller pieces (Huberman & Stanzl 2004).
        $stock = $this->stock();
        $adv = $this->engine->averageDailyVolume($stock);

        $single = $this->engine->permanentImpact($stock, $adv * 0.04);
        $quadruple = $this->engine->permanentImpact($stock, $adv * 0.16);

        $this->assertEqualsWithDelta(4.0, $quadruple / $single, 1e-9);
    }

    /**
     * The defect this guards against: at 14,400 ticks a year a day is forty ticks, and a square-root law
     * applied per tick charged the NPC agents' steady buying sqrt(40) times the mark the calibration
     * promised, which is what blew momentum bubbles to twice fair value.
     */
    public function testADaysFlowLeavesTheSameMarkWhetherItArrivesInOneTickOrForty(): void
    {
        $stock = $this->stock();
        $dailyFlow = $this->engine->averageDailyVolume($stock) * 0.30;

        $oneBlock = $this->engine->permanentImpact($stock, $dailyFlow);

        $sliced = 0.0;
        for ($tick = 0; $tick < 40; $tick++) {
            $sliced += $this->engine->permanentImpact($stock, $dailyFlow / 40.0);
        }

        $this->assertEqualsWithDelta($oneBlock, $sliced, 1e-12);
    }

    public function testImpactIsSignedByDirectionAndZeroWhenFlowNets(): void
    {
        $stock = $this->stock();

        $this->assertGreaterThan(0.0, $this->engine->permanentImpact($stock, 10000.0));
        $this->assertLessThan(0.0, $this->engine->permanentImpact($stock, -10000.0));
        $this->assertSame(0.0, $this->engine->permanentImpact($stock, 0.0));

        // A buy and an equal sell in the same window leave the price where they found it.
        $this->assertEqualsWithDelta(
            0.0,
            $this->engine->permanentImpact($stock, 10000.0) + $this->engine->permanentImpact($stock, -10000.0),
            1e-15
        );
    }

    public function testAThinNameMovesFurtherThanALiquidOneForTheSameShareCount(): void
    {
        $liquid = $this->stock(shares: 2.0e9, volatility: 0.20);
        $thin = $this->stock(shares: 5.0e6, volatility: 0.70);

        $this->assertGreaterThan(
            $this->engine->permanentImpact($liquid, 50000.0),
            $this->engine->permanentImpact($thin, 50000.0)
        );
    }

    // --- Execution ---

    public function testABuyFillsAboveMidAndASellBelowIt(): void
    {
        $stock = $this->stock();
        $quantity = (int) ($this->engine->averageDailyVolume($stock) * 0.05);

        $buy = $this->engine->quote($stock, 'BUY', $quantity, 100.0);
        $sell = $this->engine->quote($stock, 'SELL', $quantity, 100.0);

        $this->assertGreaterThan(100.0, $buy->executionPrice);
        $this->assertLessThan(100.0, $sell->executionPrice);

        // Symmetric: the cost of crossing does not depend on which way you cross.
        $this->assertEqualsWithDelta(
            $buy->executionPrice - 100.0,
            100.0 - $sell->executionPrice,
            1e-9
        );
        $this->assertEqualsWithDelta($buy->totalCost(), $sell->totalCost(), 1e-9);
    }

    public function testTheTakerPaysTheHalfSpreadPlusHalfOfItsOwnImpact(): void
    {
        // Derived, not chosen: the price walks to its new level while the order fills, so the average fill
        // is the midpoint of that walk.
        $stock = $this->stock();
        $quantity = (int) ($this->engine->averageDailyVolume($stock) * 0.20);

        $quote = $this->engine->quote($stock, 'BUY', $quantity, 100.0);

        $expectedFraction = $this->engine->halfSpreadFraction($stock)
            + (FinancialConstants::TEMPORARY_IMPACT_ETA * abs($quote->permanentImpact));

        $this->assertEqualsWithDelta(100.0 * (1.0 + $expectedFraction), $quote->executionPrice, 1e-9);
        $this->assertEqualsWithDelta(100.0 * $expectedFraction * $quantity, $quote->totalCost(), 1e-6);
    }

    public function testARoundTripAtAnUnchangedPriceLosesMoney(): void
    {
        $stock = $this->stock();
        $quantity = (int) ($this->engine->averageDailyVolume($stock) * 0.05);

        $bought = $this->engine->quote($stock, 'BUY', $quantity, 100.0)->executionPrice * $quantity;
        $sold = $this->engine->quote($stock, 'SELL', $quantity, 100.0)->executionPrice * $quantity;

        $this->assertLessThan($bought, $sold, 'Crossing twice costs twice.');
    }

    public function testParticipationRateIsReportedAgainstDailyVolume(): void
    {
        $stock = $this->stock();
        $quantity = (int) ($this->engine->averageDailyVolume($stock) * 0.25);

        $this->assertEqualsWithDelta(0.25, $this->engine->quote($stock, 'BUY', $quantity, 100.0)->participationRate, 0.001);
    }

    public function testMaximumOrderSizeIsAMultipleOfDailyVolume(): void
    {
        $stock = $this->stock();

        $this->assertEqualsWithDelta(
            $this->engine->averageDailyVolume($stock) * FinancialConstants::MAX_ORDER_ADV_MULTIPLE,
            $this->engine->maximumOrderSize($stock),
            1e-9
        );
    }

    // --- Other asset classes ---

    public function testAnEtfQuotesAtAFlatSpreadWithNoImpact(): void
    {
        // Creation and redemption pin it to its basket, so depth is not modelled — but it is not free
        // either, or the ETF becomes a way to buy the whole index around the equity book's costs.
        $quote = $this->engine->quoteAsset(null, 'ETF', 'BUY', 1000, 100.0);

        $this->assertEqualsWithDelta(100.0 * (1.0 + FinancialConstants::ETF_HALF_SPREAD), $quote->executionPrice, 1e-9);
        $this->assertSame(0.0, $quote->impactCost);
        $this->assertSame(0.0, $quote->permanentImpact);
        $this->assertGreaterThan(0.0, $quote->spreadCost);
    }

    public function testABondQuotesTighterThanAnEtf(): void
    {
        $bond = $this->engine->quoteAsset(null, 'BOND', 'BUY', 100, 1000.0);
        $etf = $this->engine->quoteAsset(null, 'ETF', 'BUY', 100, 1000.0);

        $this->assertLessThan($etf->executionPrice, $bond->executionPrice);
    }

    public function testAStockRoutesToTheModelledDepthRatherThanTheFlatSpread(): void
    {
        $stock = $this->stock();
        $quantity = (int) ($this->engine->averageDailyVolume($stock) * 0.10);

        $viaAsset = $this->engine->quoteAsset($stock, 'STOCK', 'BUY', $quantity, 100.0);
        $direct = $this->engine->quote($stock, 'BUY', $quantity, 100.0);

        $this->assertEquals($direct, $viaAsset);
        $this->assertGreaterThan(0.0, $viaAsset->permanentImpact);
    }

    // --- Turnover seeding ---

    public function testVolatileNamesAreSeededWithHigherTurnoverThanQuietOnes(): void
    {
        $quiet = LiquidityEngine::structuralTurnoverRatio(0.10);
        $typical = LiquidityEngine::structuralTurnoverRatio(FinancialConstants::TURNOVER_REFERENCE_VOLATILITY);
        $wild = LiquidityEngine::structuralTurnoverRatio(0.90);

        $this->assertLessThan($typical, $quiet);
        $this->assertGreaterThan($typical, $wild);
        $this->assertEqualsWithDelta(FinancialConstants::BASELINE_ANNUAL_TURNOVER, $typical, 1e-9);
    }

    public function testSeededTurnoverStaysInsideAPlausibleRange(): void
    {
        foreach ([0.0, 0.001, 0.05, 0.30, 2.0, 10.0] as $volatility) {
            $turnover = LiquidityEngine::structuralTurnoverRatio($volatility);

            $this->assertGreaterThanOrEqual(FinancialConstants::MIN_ANNUAL_TURNOVER, $turnover);
            $this->assertLessThanOrEqual(FinancialConstants::MAX_ANNUAL_TURNOVER, $turnover);
        }
    }

    // --- Volume ---

    public function testTickVolumeAveragesToTheDailyRateOverADay(): void
    {
        $stock = $this->stock();
        $dt = 1.0 / 14400.0;
        $ticksPerDay = (int) round(14400.0 / FinancialConstants::TRADING_DAYS_PER_YEAR);

        $total = 0.0;
        for ($i = 0; $i < $ticksPerDay * 200; $i++) {
            $total += $this->engine->simulateTickVolume($stock, $dt);
        }

        $this->assertEqualsWithDelta(
            $this->engine->averageDailyVolume($stock),
            $total / 200.0,
            $this->engine->averageDailyVolume($stock) * 0.05,
            'Lognormal volume must be mean-corrected, not biased high by its own dispersion.'
        );
    }

    public function testPlayerFillsAreCountedAsPrints(): void
    {
        $stock = $this->stock();

        $withoutPlayers = $this->engine->simulateTickVolume($stock, 1.0 / 14400.0, 0.0);
        $withPlayers = $this->engine->simulateTickVolume($stock, 1.0 / 14400.0, 1.0e6);

        $this->assertGreaterThan($withoutPlayers, $withPlayers);
    }

    public function testVolumeIsNeverNegative(): void
    {
        $stock = $this->stock();

        for ($i = 0; $i < 500; $i++) {
            $this->assertGreaterThanOrEqual(0.0, $this->engine->simulateTickVolume($stock, 1.0 / 14400.0));
        }
    }
}
