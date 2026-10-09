<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Index;

use App\Service\Market\Index\AuthorizedParticipant;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

/**
 * The arbitrage that keeps a fund near its basket, and the band inside which nobody bothers.
 *
 * Before this the fund's price WAS its net asset value, by construction, so a discount was unreachable and
 * buying a fund moved nothing — not the fund, and not the companies it holds. These are the properties that
 * make it a security rather than a computed number.
 */
final class AuthorizedParticipantTest extends TestCase
{
    private const NET_ASSETS = 25_000_000_000.0;
    private const ONE_DAY = 1.0 / FinancialConstants::TRADING_DAYS_PER_YEAR;

    private AuthorizedParticipant $ap;

    protected function setUp(): void
    {
        $this->ap = new AuthorizedParticipant();
    }

    public function testTheBandIsTheRoundTripCostOfTheBasket(): void
    {
        $spread = 0.002;

        self::assertEqualsWithDelta(
            AuthorizedParticipant::ETF_CREATION_FEE + ($spread * AuthorizedParticipant::ETF_BASKET_ROUND_TRIP_MULTIPLE),
            $this->ap->band($spread),
            1e-9
        );
    }

    /**
     * The March 2020 behaviour, and the reason the band is read off the live basket rather than set. An
     * equity fund of liquid mega-caps held its pricing in the same week a credit fund printed a
     * double-digit discount; the only difference between them was what their constituents cost to trade.
     */
    public function testAnIlliquidBasketOpensTheBandWider(): void
    {
        self::assertGreaterThan($this->ap->band(0.0005), $this->ap->band(0.02));
    }

    public function testTheBandIsBoundedAtBothEnds(): void
    {
        self::assertGreaterThanOrEqual(FinancialConstants::ETF_HALF_SPREAD, $this->ap->band(0.0));
        self::assertSame(AuthorizedParticipant::ETF_MAX_ARBITRAGE_BAND, $this->ap->band(10.0));
    }

    /** Inside the band the arbitrage does not pay, so the fund wanders on its own order flow. */
    public function testDemandInsideTheBandMovesThePriceAndCallsNobody(): void
    {
        $band = $this->ap->band(0.002);
        $flow = self::NET_ASSETS * ($band / AuthorizedParticipant::ETF_FLOW_PRESSURE) * 0.5;

        $settled = $this->ap->settle(0.0, $flow, self::NET_ASSETS, $band, self::ONE_DAY);

        self::assertGreaterThan(0.0, $settled['premium'], 'Buying the fund makes it dearer than its basket.');
        self::assertLessThan($band, $settled['premium']);
        self::assertSame(0.0, $settled['creationValue'], 'Nobody creates for a gap that does not pay.');
    }

    /** At the edge the trade pays, and the AP takes exactly the excess — back to the edge, not to zero. */
    public function testDemandPastTheBandIsAbsorbedBackToTheEdge(): void
    {
        $band = $this->ap->band(0.002);
        $flow = self::NET_ASSETS * ($band / AuthorizedParticipant::ETF_FLOW_PRESSURE) * 4.0;

        $settled = $this->ap->settle(0.0, $flow, self::NET_ASSETS, $band, self::ONE_DAY);

        self::assertEqualsWithDelta($band, $settled['premium'], 1e-12, 'Back to where the arbitrage stops paying.');
        self::assertGreaterThan(0.0, $settled['creationValue'], 'A premium is met by creating shares.');
        self::assertLessThan($flow, $settled['creationValue'], 'Only the excess is absorbed, not the whole ticket.');
    }

    /** Selling pressure is the mirror: the fund goes to a discount and the AP redeems into it. */
    public function testSellingPressureRedeemsRatherThanCreates(): void
    {
        $band = $this->ap->band(0.002);
        $flow = -self::NET_ASSETS * ($band / AuthorizedParticipant::ETF_FLOW_PRESSURE) * 4.0;

        $settled = $this->ap->settle(0.0, $flow, self::NET_ASSETS, $band, self::ONE_DAY);

        self::assertEqualsWithDelta(-$band, $settled['premium'], 1e-12);
        self::assertLessThan(0.0, $settled['creationValue'], 'A discount is met by taking the basket out.');
    }

    /** A deviation is transient. Left alone it decays rather than compounding into a permanent gap. */
    public function testAStandingPremiumDecaysWithNoFlow(): void
    {
        $band = $this->ap->band(0.002);
        $settled = $this->ap->settle($band * 0.8, 0.0, self::NET_ASSETS, $band, self::ONE_DAY);

        self::assertGreaterThan(0.0, $settled['premium']);
        self::assertLessThan($band * 0.8, $settled['premium']);
    }

    /** The decay is a rate in time, not per tick: a day of no flow leaves the same premium however finely the day is cut. */
    public function testPremiumDecayIsNeutralToTheTickGrid(): void
    {
        $band = $this->ap->band(0.002);
        $start = $band * 0.8;

        $daily = $this->ap->settle($start, 0.0, self::NET_ASSETS, $band, self::ONE_DAY)['premium'];

        $fine = $start;
        $slices = 57;
        for ($i = 0; $i < $slices; $i++) {
            $fine = $this->ap->settle($fine, 0.0, self::NET_ASSETS, $band, self::ONE_DAY / $slices)['premium'];
        }

        self::assertEqualsWithDelta($daily, $fine, 1e-12);
        // Calibrated to the 60% daily survival the fund was built on.
        self::assertEqualsWithDelta(0.60, $daily / $start, 0.005);
    }

    /** The same ticket is a rounding error to a large fund and a squeeze on a small one. */
    public function testTheSameOrderMovesASmallFundFurther(): void
    {
        $band = $this->ap->band(0.002);
        $flow = 50_000_000.0;

        $large = $this->ap->settle(0.0, $flow, self::NET_ASSETS, $band, self::ONE_DAY);
        $small = $this->ap->settle(0.0, $flow, self::NET_ASSETS / 100.0, $band, self::ONE_DAY);

        self::assertGreaterThan($large['premium'], $small['premium']);
    }

    public function testAFundWithNoAssetsIsNotPriced(): void
    {
        self::assertSame(
            ['premium' => 0.0, 'creationValue' => 0.0],
            $this->ap->settle(0.05, 1_000_000.0, 0.0, 0.01, self::ONE_DAY)
        );
    }

    /**
     * The loop back to the companies. A creation is a real purchase of every constituent in index weight,
     * which is what makes money going into a fund reach what the fund holds.
     */
    public function testACreationBasketIsSplitByIndexWeightAndPricedIntoShares(): void
    {
        $orders = $this->ap->basketOrders(
            1_000_000.0,
            ['AAA' => 750.0, 'BBB' => 250.0],
            ['AAA' => 50.0, 'BBB' => 10.0]
        );

        self::assertEqualsWithDelta(750_000.0 / 50.0, $orders['AAA'], 1e-6);
        self::assertEqualsWithDelta(250_000.0 / 10.0, $orders['BBB'], 1e-6);
    }

    public function testARedemptionBasketSells(): void
    {
        $orders = $this->ap->basketOrders(-1_000_000.0, ['AAA' => 1.0], ['AAA' => 50.0]);

        self::assertLessThan(0.0, $orders['AAA']);
    }

    public function testWeightsNeedNotBeNormalized(): void
    {
        $raw = $this->ap->basketOrders(1_000_000.0, ['AAA' => 3.0, 'BBB' => 1.0], ['AAA' => 10.0, 'BBB' => 10.0]);
        $normalized = $this->ap->basketOrders(1_000_000.0, ['AAA' => 0.75, 'BBB' => 0.25], ['AAA' => 10.0, 'BBB' => 10.0]);

        self::assertEqualsWithDelta($normalized['AAA'], $raw['AAA'], 1e-6);
        self::assertEqualsWithDelta($normalized['BBB'], $raw['BBB'], 1e-6);
    }

    public function testANameWithNoPriceIsLeftOutRatherThanDividedByZero(): void
    {
        $orders = $this->ap->basketOrders(1_000_000.0, ['AAA' => 1.0, 'DEAD' => 1.0], ['AAA' => 50.0, 'DEAD' => 0.0]);

        self::assertArrayNotHasKey('DEAD', $orders);
        self::assertArrayHasKey('AAA', $orders);
    }

    public function testNothingIsTradedForAnEmptyCreation(): void
    {
        self::assertSame([], $this->ap->basketOrders(0.0, ['AAA' => 1.0], ['AAA' => 50.0]));
        self::assertSame([], $this->ap->basketOrders(1_000_000.0, [], []));
    }
}
