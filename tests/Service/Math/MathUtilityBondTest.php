<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Fixed-income primitives in MathUtility, checked against closed-form results rather than against
 * themselves: a discount factor, a duration and a yield all have known analytic values for a zero-coupon
 * bond, so each one is anchored to arithmetic that does not go through the function under test.
 */
class MathUtilityBondTest extends TestCase
{
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    // --- Present Value ---

    public function testPresentValueDiscountsAtTheCurveRateForEachFlowsOwnTenor(): void
    {
        $flows = [
            ['time' => 1.0, 'amount' => 100.0],
            ['time' => 2.0, 'amount' => 1100.0],
        ];

        // A deliberately non-flat curve: if the implementation discounted everything at one rate the two
        // legs would not both land on their own analytic value.
        $curve = static fn (float $tau): float => 0.03 + (0.005 * $tau);

        $expected = (100.0 * exp(-0.035 * 1.0)) + (1100.0 * exp(-0.04 * 2.0));

        $this->assertEqualsWithDelta($expected, $this->math->calculateBondPresentValue($flows, $curve), 1e-9);
    }

    public function testPresentValueIgnoresFlowsAtOrBeforeSettlement(): void
    {
        $flows = [
            ['time' => -0.5, 'amount' => 1000.0],
            ['time' => 0.0, 'amount' => 1000.0],
            ['time' => 1.0, 'amount' => 100.0],
        ];

        $curve = static fn (float $tau): float => 0.04;

        $this->assertEqualsWithDelta(
            100.0 * exp(-0.04),
            $this->math->calculateBondPresentValue($flows, $curve),
            1e-9
        );
    }

    // --- Duration & Convexity ---

    public function testZeroCouponDurationEqualsItsMaturity(): void
    {
        // The defining property of Macaulay duration: with one cash flow, the PV-weighted average time to
        // cash flow IS the time to that cash flow, at any yield.
        foreach ([0.0, 0.02, 0.05, 0.11] as $yield) {
            $flows = [['time' => 7.0, 'amount' => 1000.0]];

            $this->assertEqualsWithDelta(7.0, $this->math->calculateMacaulayDuration($flows, $yield), 1e-12);
            $this->assertEqualsWithDelta(49.0, $this->math->calculateConvexity($flows, $yield), 1e-12);
        }
    }

    public function testCouponBondDurationIsShorterThanItsMaturity(): void
    {
        $flows = [];
        for ($k = 1; $k <= 10; $k++) {
            $flows[] = ['time' => $k * 1.0, 'amount' => $k === 10 ? 1050.0 : 50.0];
        }

        $duration = $this->math->calculateMacaulayDuration($flows, 0.05);

        // Intermediate coupons pull the weighted average time in from the redemption date.
        $this->assertLessThan(10.0, $duration);
        $this->assertGreaterThan(7.0, $duration);
    }

    public function testModifiedDurationEqualsMacaulayUnderContinuousCompounding(): void
    {
        // Applying the periodic 1/(1 + y/k) adjustment here would be a discrete-compounding import and
        // would understate every bond's rate sensitivity.
        $this->assertSame(8.25, $this->math->calculateModifiedDuration(8.25));
    }

    public function testDurationIsTheNegativeLogPriceDerivative(): void
    {
        $flows = [];
        for ($k = 1; $k <= 20; $k++) {
            $flows[] = ['time' => $k * 0.5, 'amount' => $k === 20 ? 1025.0 : 25.0];
        }

        $yield = 0.045;
        $bump = 1e-6;

        $priceAt = fn (float $y): float => $this->math->calculateBondPresentValue(
            $flows,
            static fn (float $tau): float => $y
        );

        // Central difference on -(1/P)(dP/dy) reproduces modified duration if and only if the two are the
        // same quantity, which is the claim being tested.
        $numeric = -($priceAt($yield + $bump) - $priceAt($yield - $bump)) / (2.0 * $bump * $priceAt($yield));
        $analytic = $this->math->calculateModifiedDuration($this->math->calculateMacaulayDuration($flows, $yield));

        $this->assertEqualsWithDelta($numeric, $analytic, 1e-6);
    }

    public function testConvexityIsTheSecondLogPriceDerivative(): void
    {
        $flows = [];
        for ($k = 1; $k <= 60; $k++) {
            $flows[] = ['time' => $k * 0.5, 'amount' => $k === 60 ? 1027.5 : 27.5];
        }

        $yield = 0.055;
        $bump = 1e-4;

        $priceAt = fn (float $y): float => $this->math->calculateBondPresentValue(
            $flows,
            static fn (float $tau): float => $y
        );

        $numeric = ($priceAt($yield + $bump) - (2.0 * $priceAt($yield)) + $priceAt($yield - $bump))
            / ($bump * $bump * $priceAt($yield));

        $this->assertEqualsWithDelta($numeric, $this->math->calculateConvexity($flows, $yield), 1e-3);
    }

    // --- Yield To Maturity ---

    public function testYieldToMaturityRecoversTheRateThatPricedTheBond(): void
    {
        $flows = [];
        for ($k = 1; $k <= 20; $k++) {
            $flows[] = ['time' => $k * 0.5, 'amount' => $k === 20 ? 1022.5 : 22.5];
        }

        foreach ([0.001, 0.02, 0.045, 0.09, 0.25] as $trueYield) {
            $price = $this->math->calculateBondPresentValue(
                $flows,
                static fn (float $tau): float => $trueYield
            );

            $this->assertEqualsWithDelta($trueYield, $this->math->calculateYieldToMaturity($flows, $price), 1e-8);
        }
    }

    public function testYieldToMaturityConvergesFromAFarStartingGuess(): void
    {
        // A thirty-year zero started from a wildly wrong guess is the case that sends an unbracketed
        // Newton-Raphson step outside any sensible yield and never returns.
        $flows = [['time' => 30.0, 'amount' => 1000.0]];
        $price = 1000.0 * exp(-0.06 * 30.0);

        $this->assertEqualsWithDelta(0.06, $this->math->calculateYieldToMaturity($flows, $price, 2.5), 1e-8);
        $this->assertEqualsWithDelta(0.06, $this->math->calculateYieldToMaturity($flows, $price, -0.5), 1e-8);
    }

    public function testYieldToMaturityReturnsZeroForANonPositivePrice(): void
    {
        $this->assertSame(0.0, $this->math->calculateYieldToMaturity([['time' => 1.0, 'amount' => 100.0]], 0.0));
        $this->assertSame(0.0, $this->math->calculateYieldToMaturity([], 950.0));
    }

    // --- Accrued Interest ---

    public function testAccruedInterestIsLinearAcrossTheCouponPeriod(): void
    {
        $coupon = 25.0;
        $period = 0.5;

        $this->assertSame(0.0, $this->math->calculateAccruedInterest($coupon, 0.0, $period));
        $this->assertEqualsWithDelta(6.25, $this->math->calculateAccruedInterest($coupon, 0.125, $period), 1e-12);
        $this->assertEqualsWithDelta(12.5, $this->math->calculateAccruedInterest($coupon, 0.25, $period), 1e-12);
        $this->assertEqualsWithDelta(25.0, $this->math->calculateAccruedInterest($coupon, 0.5, $period), 1e-12);
    }

    public function testAccruedInterestNeverExceedsAFullCoupon(): void
    {
        // A tick that overshoots a payment date must not accrue more than the coupon actually pays.
        $this->assertSame(25.0, $this->math->calculateAccruedInterest(25.0, 0.9, 0.5));
    }

    public function testAccruedInterestIsZeroForADegeneratePeriod(): void
    {
        $this->assertSame(0.0, $this->math->calculateAccruedInterest(25.0, 0.25, 0.0));
        $this->assertSame(0.0, $this->math->calculateAccruedInterest(25.0, -0.1, 0.5));
    }
}
