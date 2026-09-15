<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Option primitives in MathUtility, anchored to results that do not go through the function under test:
 * put-call parity, published Black-Scholes values, finite differences of the price for every greek, the
 * analytic moments of an exponential for the jump shape, and a constant-volatility strip for the
 * model-free variance, which must come back at the volatility it was built from.
 */
class MathUtilityOptionTest extends TestCase
{
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    // --- Black-Scholes-Merton Price ---

    public function testCallPriceMatchesThePublishedBlackScholesValue(): void
    {
        // S=100, K=100, r=5%, q=0, sigma=20%, T=1 is the textbook case; the call is worth 10.4506.
        $price = $this->math->calculateBlackScholesPrice(100.0, 100.0, 0.20, 0.05, 0.0, 1.0, true);

        $this->assertEqualsWithDelta(10.450584, $price, 1e-4);
    }

    public function testPutPriceMatchesThePublishedBlackScholesValue(): void
    {
        $price = $this->math->calculateBlackScholesPrice(100.0, 100.0, 0.20, 0.05, 0.0, 1.0, false);

        $this->assertEqualsWithDelta(5.573526, $price, 1e-4);
    }

    public function testPutCallParityHoldsWithADividendYield(): void
    {
        $spot = 87.5;
        $strike = 95.0;
        $rate = 0.037;
        $yield = 0.021;
        $tenor = 0.75;

        $call = $this->math->calculateBlackScholesPrice($spot, $strike, 0.33, $rate, $yield, $tenor, true);
        $put = $this->math->calculateBlackScholesPrice($spot, $strike, 0.33, $rate, $yield, $tenor, false);

        // C - P = S e^(-qT) - K e^(-rT), independently of volatility.
        $expected = ($spot * exp(-$yield * $tenor)) - ($strike * exp(-$rate * $tenor));

        $this->assertEqualsWithDelta($expected, $call - $put, 1e-6);
    }

    public function testAnExpiredContractSettlesAtIntrinsicValue(): void
    {
        $this->assertEqualsWithDelta(12.0, $this->math->calculateBlackScholesPrice(112.0, 100.0, 0.30, 0.04, 0.0, 0.0, true), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->math->calculateBlackScholesPrice(88.0, 100.0, 0.30, 0.04, 0.0, 0.0, true), 1e-9);
        $this->assertEqualsWithDelta(12.0, $this->math->calculateBlackScholesPrice(88.0, 100.0, 0.30, 0.04, 0.0, 0.0, false), 1e-9);
    }

    public function testDividendYieldLowersACallAndLiftsAPut(): void
    {
        $withoutYield = $this->math->calculateBlackScholesPrice(100.0, 100.0, 0.25, 0.04, 0.00, 1.0, true);
        $withYield = $this->math->calculateBlackScholesPrice(100.0, 100.0, 0.25, 0.04, 0.05, 1.0, true);

        $this->assertLessThan($withoutYield, $withYield);

        $putWithout = $this->math->calculateBlackScholesPrice(100.0, 100.0, 0.25, 0.04, 0.00, 1.0, false);
        $putWith = $this->math->calculateBlackScholesPrice(100.0, 100.0, 0.25, 0.04, 0.05, 1.0, false);

        $this->assertGreaterThan($putWithout, $putWith);
    }

    // --- Greeks ---

    /**
     * Every first-order greek is a partial derivative of the price, so a central difference of the price
     * function is the reference: an analytic formula with a transcription error will not agree with the
     * function it is supposed to differentiate.
     *
     * @return array<string, array{float, float, float, float, float, float, bool}>
     */
    public static function greekContractProvider(): array
    {
        return [
            // spot, strike, vol, rate, yield, tenor, isCall
            'atm call'        => [100.0, 100.0, 0.25, 0.04, 0.015, 0.50, true],
            'atm put'         => [100.0, 100.0, 0.25, 0.04, 0.015, 0.50, false],
            'otm call'        => [100.0, 130.0, 0.35, 0.03, 0.000, 1.00, true],
            'otm put'         => [100.0, 70.0, 0.35, 0.03, 0.000, 1.00, false],
            'itm call'        => [120.0, 90.0, 0.20, 0.05, 0.020, 0.25, true],
            'short dated put' => [50.0, 52.0, 0.45, 0.02, 0.000, 0.08, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('greekContractProvider')]
    public function testDeltaMatchesTheDerivativeOfThePriceWithRespectToSpot(
        float $spot,
        float $strike,
        float $vol,
        float $rate,
        float $yield,
        float $tenor,
        bool $isCall
    ): void {
        $step = $spot * 1e-5;

        $up = $this->math->calculateBlackScholesPrice($spot + $step, $strike, $vol, $rate, $yield, $tenor, $isCall);
        $down = $this->math->calculateBlackScholesPrice($spot - $step, $strike, $vol, $rate, $yield, $tenor, $isCall);

        $greeks = $this->math->calculateBlackScholesGreeks($spot, $strike, $vol, $rate, $yield, $tenor, $isCall);

        $this->assertEqualsWithDelta(($up - $down) / (2.0 * $step), $greeks['delta'], 1e-4);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('greekContractProvider')]
    public function testGammaMatchesTheSecondDerivativeOfThePriceWithRespectToSpot(
        float $spot,
        float $strike,
        float $vol,
        float $rate,
        float $yield,
        float $tenor,
        bool $isCall
    ): void {
        $step = $spot * 1e-3;

        $up = $this->math->calculateBlackScholesPrice($spot + $step, $strike, $vol, $rate, $yield, $tenor, $isCall);
        $mid = $this->math->calculateBlackScholesPrice($spot, $strike, $vol, $rate, $yield, $tenor, $isCall);
        $down = $this->math->calculateBlackScholesPrice($spot - $step, $strike, $vol, $rate, $yield, $tenor, $isCall);

        $greeks = $this->math->calculateBlackScholesGreeks($spot, $strike, $vol, $rate, $yield, $tenor, $isCall);

        $this->assertEqualsWithDelta(($up - (2.0 * $mid) + $down) / ($step * $step), $greeks['gamma'], 1e-4);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('greekContractProvider')]
    public function testVegaMatchesTheDerivativeOfThePriceWithRespectToVolatility(
        float $spot,
        float $strike,
        float $vol,
        float $rate,
        float $yield,
        float $tenor,
        bool $isCall
    ): void {
        $step = 1e-5;

        $up = $this->math->calculateBlackScholesPrice($spot, $strike, $vol + $step, $rate, $yield, $tenor, $isCall);
        $down = $this->math->calculateBlackScholesPrice($spot, $strike, $vol - $step, $rate, $yield, $tenor, $isCall);

        $greeks = $this->math->calculateBlackScholesGreeks($spot, $strike, $vol, $rate, $yield, $tenor, $isCall);

        $this->assertEqualsWithDelta(($up - $down) / (2.0 * $step), $greeks['vega'], 1e-3);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('greekContractProvider')]
    public function testThetaMatchesTheDecayOfThePriceAsExpiryApproaches(
        float $spot,
        float $strike,
        float $vol,
        float $rate,
        float $yield,
        float $tenor,
        bool $isCall
    ): void {
        $step = 1e-5;

        // Theta is the derivative with respect to CALENDAR time, which runs the opposite way to time to
        // expiry, so the difference is taken in that direction.
        $later = $this->math->calculateBlackScholesPrice($spot, $strike, $vol, $rate, $yield, $tenor - $step, $isCall);
        $earlier = $this->math->calculateBlackScholesPrice($spot, $strike, $vol, $rate, $yield, $tenor + $step, $isCall);

        $greeks = $this->math->calculateBlackScholesGreeks($spot, $strike, $vol, $rate, $yield, $tenor, $isCall);

        $this->assertEqualsWithDelta(($later - $earlier) / (2.0 * $step), $greeks['theta'], 1e-3);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('greekContractProvider')]
    public function testRhoMatchesTheDerivativeOfThePriceWithRespectToTheRiskFreeRate(
        float $spot,
        float $strike,
        float $vol,
        float $rate,
        float $yield,
        float $tenor,
        bool $isCall
    ): void {
        $step = 1e-6;

        $up = $this->math->calculateBlackScholesPrice($spot, $strike, $vol, $rate + $step, $yield, $tenor, $isCall);
        $down = $this->math->calculateBlackScholesPrice($spot, $strike, $vol, $rate - $step, $yield, $tenor, $isCall);

        $greeks = $this->math->calculateBlackScholesGreeks($spot, $strike, $vol, $rate, $yield, $tenor, $isCall);

        $this->assertEqualsWithDelta(($up - $down) / (2.0 * $step), $greeks['rho'], 1e-3);
    }

    public function testAnExpiredContractHasSteppedDeltaAndNoSecondOrderSensitivity(): void
    {
        $greeks = $this->math->calculateBlackScholesGreeks(120.0, 100.0, 0.30, 0.04, 0.0, 0.0, true);

        $this->assertSame(1.0, $greeks['delta']);
        $this->assertSame(0.0, $greeks['gamma']);
        $this->assertSame(0.0, $greeks['vega']);

        $put = $this->math->calculateBlackScholesGreeks(80.0, 100.0, 0.30, 0.04, 0.0, 0.0, false);
        $this->assertSame(-1.0, $put['delta']);
    }

    // --- Implied Volatility ---

    public function testImpliedVolatilityRecoversTheVolatilityAPriceWasStruckAt(): void
    {
        foreach ([0.08, 0.17, 0.42, 1.10] as $sigma) {
            foreach ([80.0, 100.0, 125.0] as $strike) {
                $price = $this->math->calculateBlackScholesPrice(100.0, $strike, $sigma, 0.035, 0.012, 0.6, true);
                $recovered = $this->math->calculateImpliedVolatility($price, 100.0, $strike, 0.035, 0.012, 0.6, true);

                $this->assertEqualsWithDelta($sigma, $recovered, 1e-4, "strike {$strike} at sigma {$sigma}");
            }
        }
    }

    public function testImpliedVolatilityRecoversAPutsVolatility(): void
    {
        $price = $this->math->calculateBlackScholesPrice(64.0, 70.0, 0.28, 0.04, 0.0, 0.25, false);

        $this->assertEqualsWithDelta(0.28, $this->math->calculateImpliedVolatility($price, 64.0, 70.0, 0.04, 0.0, 0.25, false), 1e-4);
    }

    public function testAQuoteAtOrBelowIntrinsicValueReturnsTheVolatilityFloor(): void
    {
        $this->assertSame(
            MathUtility::MIN_OPTION_VOLATILITY,
            $this->math->calculateImpliedVolatility(0.0, 100.0, 100.0, 0.04, 0.0, 1.0, true)
        );
    }

    public function testAQuoteAboveTheQuotableRangeReturnsTheVolatilityCeiling(): void
    {
        $this->assertSame(
            MathUtility::MAX_OPTION_VOLATILITY,
            $this->math->calculateImpliedVolatility(99.0, 100.0, 100.0, 0.04, 0.0, 1.0, true)
        );
    }

    // --- Kou Jump Moments ---

    public function testAPureUpJumpReturnsTheMomentsOfItsExponential(): void
    {
        // p = 1 makes every jump the up branch, whose n-th moment is n! / eta^n.
        $eta = 10.0;

        $this->assertEqualsWithDelta(1.0 / $eta, $this->math->calculateKouJumpMoment(1, 1.0, $eta, 25.0), 1e-12);
        $this->assertEqualsWithDelta(2.0 / ($eta ** 2), $this->math->calculateKouJumpMoment(2, 1.0, $eta, 25.0), 1e-12);
        $this->assertEqualsWithDelta(6.0 / ($eta ** 3), $this->math->calculateKouJumpMoment(3, 1.0, $eta, 25.0), 1e-12);
        $this->assertEqualsWithDelta(24.0 / ($eta ** 4), $this->math->calculateKouJumpMoment(4, 1.0, $eta, 25.0), 1e-12);
    }

    public function testAPureDownJumpCarriesTheSignOfItsOrder(): void
    {
        $eta = 8.0;

        $this->assertLessThan(0.0, $this->math->calculateKouJumpMoment(1, 0.0, 20.0, $eta));
        $this->assertGreaterThan(0.0, $this->math->calculateKouJumpMoment(2, 0.0, 20.0, $eta));
        $this->assertLessThan(0.0, $this->math->calculateKouJumpMoment(3, 0.0, 20.0, $eta));
        $this->assertGreaterThan(0.0, $this->math->calculateKouJumpMoment(4, 0.0, 20.0, $eta));

        $this->assertEqualsWithDelta(-6.0 / ($eta ** 3), $this->math->calculateKouJumpMoment(3, 0.0, 20.0, $eta), 1e-12);
    }

    public function testASymmetricJumpHasNoOddMoments(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->math->calculateKouJumpMoment(1, 0.5, 12.0, 12.0), 1e-12);
        $this->assertEqualsWithDelta(0.0, $this->math->calculateKouJumpMoment(3, 0.5, 12.0, 12.0), 1e-12);
    }

    // --- Jump-Diffusion Shape ---

    public function testTheMarketEnginesDownwardSkewedJumpProducesNegativeSkewness(): void
    {
        // The engine's own configuration: a 40/60 split with the down branch scaled wider than the up one.
        $shape = $this->math->calculateJumpDiffusionShape(0.04, 2.0, 0.40, 1.0 / 0.10, 1.0 / 0.125, 0.25);

        $this->assertLessThan(0.0, $shape['skewness']);
        $this->assertGreaterThan(0.0, $shape['excess_kurtosis']);
    }

    public function testShapeFlattensWithHorizonAtTheCentralLimitRates(): void
    {
        $short = $this->math->calculateJumpDiffusionShape(0.04, 2.0, 0.40, 10.0, 8.0, 0.25);
        $long = $this->math->calculateJumpDiffusionShape(0.04, 2.0, 0.40, 10.0, 8.0, 1.00);

        // Four times the horizon halves the skewness and quarters the excess kurtosis.
        $this->assertEqualsWithDelta($short['skewness'] / 2.0, $long['skewness'], 1e-9);
        $this->assertEqualsWithDelta($short['excess_kurtosis'] / 4.0, $long['excess_kurtosis'], 1e-9);
    }

    public function testVarianceIsTheDiffusionPlusTheJumpSecondMoment(): void
    {
        $shape = $this->math->calculateJumpDiffusionShape(0.09, 3.0, 0.40, 10.0, 8.0, 0.5);

        $expected = (0.09 * 0.5) + (3.0 * 0.5 * $this->math->calculateKouJumpMoment(2, 0.40, 10.0, 8.0));

        $this->assertEqualsWithDelta($expected, $shape['variance'], 1e-12);
    }

    public function testAJumplessProcessHasNoShapeBeyondItsDiffusion(): void
    {
        $shape = $this->math->calculateJumpDiffusionShape(0.04, 0.0, 0.40, 10.0, 8.0, 1.0);

        $this->assertEqualsWithDelta(0.04, $shape['variance'], 1e-12);
        $this->assertSame(0.0, $shape['skewness']);
        $this->assertSame(0.0, $shape['excess_kurtosis']);
    }

    // --- Gram-Charlier Smile ---

    public function testNegativeSkewnessMarksLowStrikesUpAndHighStrikesDown(): void
    {
        // A low strike sits at positive d1, a high strike at negative d1.
        $lowStrike = $this->math->calculateGramCharlierImpliedVolatility(0.30, 1.2, -0.8, 0.0);
        $highStrike = $this->math->calculateGramCharlierImpliedVolatility(0.30, -1.2, -0.8, 0.0);

        $this->assertGreaterThan(0.30, $lowStrike);
        $this->assertLessThan(0.30, $highStrike);
        $this->assertGreaterThan($highStrike, $lowStrike);
    }

    public function testPositiveExcessKurtosisLiftsBothWingsAndDepressesTheMiddle(): void
    {
        $atm = $this->math->calculateGramCharlierImpliedVolatility(0.30, 0.0, 0.0, 1.5);
        $wingUp = $this->math->calculateGramCharlierImpliedVolatility(0.30, 2.0, 0.0, 1.5);
        $wingDown = $this->math->calculateGramCharlierImpliedVolatility(0.30, -2.0, 0.0, 1.5);

        $this->assertLessThan(0.30, $atm);
        $this->assertGreaterThan(0.30, $wingUp);
        $this->assertGreaterThan(0.30, $wingDown);
        $this->assertEqualsWithDelta($wingUp, $wingDown, 1e-12);
    }

    public function testAShapelessDistributionQuotesTheFlatSurface(): void
    {
        $this->assertEqualsWithDelta(0.30, $this->math->calculateGramCharlierImpliedVolatility(0.30, 1.7, 0.0, 0.0), 1e-12);
    }

    public function testTheExpansionIsBoundedInTheFarWings(): void
    {
        // Far enough out, the quartic term alone would quote several times the at-the-money volatility.
        $extreme = $this->math->calculateGramCharlierImpliedVolatility(0.30, 12.0, -2.0, 4.0);

        $this->assertEqualsWithDelta(0.30 * (1.0 + MathUtility::MAX_GRAM_CHARLIER_VOL_DEVIATION), $extreme, 1e-12);
    }

    // --- Model-Free Implied Variance ---

    public function testAConstantVolatilityStripReturnsThatVolatility(): void
    {
        $spot = 100.0;
        $rate = 0.04;
        $tenor = 0.5;
        $sigma = 0.25;
        $forward = $spot * exp($rate * $tenor);

        // A dense strip of out-of-the-money quotes generated at one volatility. The replication integral
        // is exact in the limit, so a fine grid has to come back at the volatility it was built from.
        $strip = [];
        for ($strike = 20.0; $strike <= 260.0; $strike += 0.5) {
            $isCall = $strike > $forward;
            $strip[] = [
                'strike' => $strike,
                'price' => $this->math->calculateBlackScholesPrice($spot, $strike, $sigma, $rate, 0.0, $tenor, $isCall),
            ];
        }

        $atmStrike = floor($forward * 2.0) / 2.0;
        $variance = $this->math->calculateModelFreeImpliedVariance($strip, $forward, $atmStrike, $rate, $tenor);

        $this->assertEqualsWithDelta($sigma, sqrt($variance), 1e-3);
    }

    public function testAFatterTailedStripPricesMoreVarianceThanItsAtTheMoneyVolatility(): void
    {
        $spot = 100.0;
        $rate = 0.03;
        $tenor = 0.25;
        $atmVol = 0.30;
        $forward = $spot * exp($rate * $tenor);

        $shape = $this->math->calculateJumpDiffusionShape(0.09, 2.0, 0.40, 1.0 / 0.10, 1.0 / 0.125, $tenor);

        $strip = [];
        for ($strike = 20.0; $strike <= 260.0; $strike += 0.5) {
            $isCall = $strike > $forward;
            $deviates = $this->math->calculateBlackScholesDeviates($spot, $strike, $atmVol, $rate, 0.0, $tenor);
            $smileVol = $this->math->calculateGramCharlierImpliedVolatility(
                $atmVol,
                $deviates['d1'],
                $shape['skewness'],
                $shape['excess_kurtosis']
            );

            $strip[] = [
                'strike' => $strike,
                'price' => $this->math->calculateBlackScholesPrice($spot, $strike, $smileVol, $rate, 0.0, $tenor, $isCall),
            ];
        }

        $atmStrike = floor($forward * 2.0) / 2.0;
        $variance = $this->math->calculateModelFreeImpliedVariance($strip, $forward, $atmStrike, $rate, $tenor);

        // The index reads the whole surface, so a smiled strip prices above the single strike the spot sits on.
        $this->assertGreaterThan($atmVol, sqrt($variance));
    }

    public function testAStripTooShortToIntegrateReturnsNoVariance(): void
    {
        $this->assertSame(0.0, $this->math->calculateModelFreeImpliedVariance([['strike' => 100.0, 'price' => 5.0]], 100.0, 100.0, 0.04, 0.5));
    }

    // --- Short Option Margin (FINRA Rule 4210) ---

    public function testTheRequirementIsThePremiumPlusAChargeOnTheUnderlying(): void
    {
        // At the money there is nothing out of the money to credit back, so the charge is the full 20%.
        $requirement = $this->math->calculateShortOptionRequirement(100.0, 100.0, 6.0, true);

        $this->assertEqualsWithDelta(6.0 + 20.0, $requirement, 1e-9);
    }

    public function testBeingFurtherOutOfTheMoneyReducesTheCharge(): void
    {
        $near = $this->math->calculateShortOptionRequirement(100.0, 105.0, 3.0, true);
        $far = $this->math->calculateShortOptionRequirement(100.0, 115.0, 1.0, true);

        $this->assertLessThan($near, $far);
        // 20% of 100 less the 5 out of the money, plus the premium.
        $this->assertEqualsWithDelta(3.0 + 15.0, $near, 1e-9);
    }

    public function testAFarOutOfTheMoneyShortIsNeverCollateralizedAtNothing(): void
    {
        // The out-of-the-money credit would wipe the charge out entirely; the floor is what stops it.
        $call = $this->math->calculateShortOptionRequirement(100.0, 400.0, 0.05, true);

        $this->assertEqualsWithDelta(0.05 + (0.10 * 100.0), $call, 1e-9);
    }

    public function testAPutsFloorIsStruckOnTheStrikeAndACallsOnTheUnderlying(): void
    {
        // The asymmetry is the rule's, and it is the right one: a call's loss scales with the underlying
        // without limit, while a put's worst case is the strike going to zero.
        $put = $this->math->calculateShortOptionRequirement(400.0, 100.0, 0.05, false);
        $call = $this->math->calculateShortOptionRequirement(100.0, 400.0, 0.05, true);

        $this->assertEqualsWithDelta(0.05 + (0.10 * 100.0), $put, 1e-9);
        $this->assertEqualsWithDelta(0.05 + (0.10 * 100.0), $call, 1e-9);
    }

    public function testTheRequirementRisesAsTheContractMovesAgainstTheWriter(): void
    {
        // A written call as the stock runs through it. Both terms climb at once — the premium the writer
        // would have to pay to get out, and the charge as the out-of-the-money credit disappears — which is
        // what calls the account before the loss is realized rather than after.
        $previous = 0.0;

        foreach ([[90.0, 1.0], [100.0, 4.0], [110.0, 12.0], [120.0, 21.0]] as [$spot, $premium]) {
            $requirement = $this->math->calculateShortOptionRequirement($spot, 100.0, $premium, true);

            $this->assertGreaterThan($previous, $requirement);
            $previous = $requirement;
        }
    }

    public function testADeepInTheMoneyShortPostsTheWholeChargeWithNoCredit(): void
    {
        $requirement = $this->math->calculateShortOptionRequirement(150.0, 100.0, 51.0, true);

        // Nothing is out of the money, so the charge is 20% of the underlying in full.
        $this->assertEqualsWithDelta(51.0 + (0.20 * 150.0), $requirement, 1e-9);
    }

    public function testTheRequirementIsNeverNegative(): void
    {
        $this->assertGreaterThanOrEqual(0.0, $this->math->calculateShortOptionRequirement(0.0, 0.0, 0.0, true));
        $this->assertGreaterThanOrEqual(0.0, $this->math->calculateShortOptionRequirement(1.0, 1000.0, 0.0, true));
    }
}
