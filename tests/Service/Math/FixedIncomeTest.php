<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\FixedIncome;
use PHPUnit\Framework\TestCase;

/**
 * Fixed-income primitives, checked against closed-form results rather than against
 * themselves: a discount factor, a duration and a yield all have known analytic values for a zero-coupon
 * bond, so each one is anchored to arithmetic that does not go through the function under test.
 */
class FixedIncomeTest extends TestCase
{
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

        $this->assertEqualsWithDelta($expected, FixedIncome::calculateBondPresentValue($flows, $curve), 1e-9);
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
            FixedIncome::calculateBondPresentValue($flows, $curve),
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

            $this->assertEqualsWithDelta(7.0, FixedIncome::calculateMacaulayDuration($flows, $yield), 1e-12);
            $this->assertEqualsWithDelta(49.0, FixedIncome::calculateConvexity($flows, $yield), 1e-12);
        }
    }

    public function testCouponBondDurationIsShorterThanItsMaturity(): void
    {
        $flows = [];
        for ($k = 1; $k <= 10; $k++) {
            $flows[] = ['time' => $k * 1.0, 'amount' => $k === 10 ? 1050.0 : 50.0];
        }

        $duration = FixedIncome::calculateMacaulayDuration($flows, 0.05);

        // Intermediate coupons pull the weighted average time in from the redemption date.
        $this->assertLessThan(10.0, $duration);
        $this->assertGreaterThan(7.0, $duration);
    }

    public function testModifiedDurationEqualsMacaulayUnderContinuousCompounding(): void
    {
        // Applying the periodic 1/(1 + y/k) adjustment here would be a discrete-compounding import and
        // would understate every bond's rate sensitivity.
        $this->assertSame(8.25, FixedIncome::calculateModifiedDuration(8.25));
    }

    public function testDurationIsTheNegativeLogPriceDerivative(): void
    {
        $flows = [];
        for ($k = 1; $k <= 20; $k++) {
            $flows[] = ['time' => $k * 0.5, 'amount' => $k === 20 ? 1025.0 : 25.0];
        }

        $yield = 0.045;
        $bump = 1e-6;

        $priceAt = fn (float $y): float => FixedIncome::calculateBondPresentValue(
            $flows,
            static fn (float $tau): float => $y
        );

        // Central difference on -(1/P)(dP/dy) reproduces modified duration if and only if the two are the
        // same quantity, which is the claim being tested.
        $numeric = -($priceAt($yield + $bump) - $priceAt($yield - $bump)) / (2.0 * $bump * $priceAt($yield));
        $analytic = FixedIncome::calculateModifiedDuration(FixedIncome::calculateMacaulayDuration($flows, $yield));

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

        $priceAt = fn (float $y): float => FixedIncome::calculateBondPresentValue(
            $flows,
            static fn (float $tau): float => $y
        );

        $numeric = ($priceAt($yield + $bump) - (2.0 * $priceAt($yield)) + $priceAt($yield - $bump))
            / ($bump * $bump * $priceAt($yield));

        $this->assertEqualsWithDelta($numeric, FixedIncome::calculateConvexity($flows, $yield), 1e-3);
    }

    // --- Yield To Maturity ---

    public function testYieldToMaturityRecoversTheRateThatPricedTheBond(): void
    {
        $flows = [];
        for ($k = 1; $k <= 20; $k++) {
            $flows[] = ['time' => $k * 0.5, 'amount' => $k === 20 ? 1022.5 : 22.5];
        }

        foreach ([0.001, 0.02, 0.045, 0.09, 0.25] as $trueYield) {
            $price = FixedIncome::calculateBondPresentValue(
                $flows,
                static fn (float $tau): float => $trueYield
            );

            $this->assertEqualsWithDelta($trueYield, FixedIncome::calculateYieldToMaturity($flows, $price), 1e-8);
        }
    }

    public function testYieldToMaturityConvergesFromAFarStartingGuess(): void
    {
        // A thirty-year zero started from a wildly wrong guess is the case that sends an unbracketed
        // Newton-Raphson step outside any sensible yield and never returns.
        $flows = [['time' => 30.0, 'amount' => 1000.0]];
        $price = 1000.0 * exp(-0.06 * 30.0);

        $this->assertEqualsWithDelta(0.06, FixedIncome::calculateYieldToMaturity($flows, $price, 2.5), 1e-8);
        $this->assertEqualsWithDelta(0.06, FixedIncome::calculateYieldToMaturity($flows, $price, -0.5), 1e-8);
    }

    public function testYieldToMaturityReturnsZeroForANonPositivePrice(): void
    {
        $this->assertSame(0.0, FixedIncome::calculateYieldToMaturity([['time' => 1.0, 'amount' => 100.0]], 0.0));
        $this->assertSame(0.0, FixedIncome::calculateYieldToMaturity([], 950.0));
    }

    // --- Accrued Interest ---

    public function testAccruedInterestIsLinearAcrossTheCouponPeriod(): void
    {
        $coupon = 25.0;
        $period = 0.5;

        $this->assertSame(0.0, FixedIncome::calculateAccruedInterest($coupon, 0.0, $period));
        $this->assertEqualsWithDelta(6.25, FixedIncome::calculateAccruedInterest($coupon, 0.125, $period), 1e-12);
        $this->assertEqualsWithDelta(12.5, FixedIncome::calculateAccruedInterest($coupon, 0.25, $period), 1e-12);
        $this->assertEqualsWithDelta(25.0, FixedIncome::calculateAccruedInterest($coupon, 0.5, $period), 1e-12);
    }

    public function testAccruedInterestNeverExceedsAFullCoupon(): void
    {
        // A tick that overshoots a payment date must not accrue more than the coupon actually pays.
        $this->assertSame(25.0, FixedIncome::calculateAccruedInterest(25.0, 0.9, 0.5));
    }

    public function testAccruedInterestIsZeroForADegeneratePeriod(): void
    {
        $this->assertSame(0.0, FixedIncome::calculateAccruedInterest(25.0, 0.25, 0.0));
        $this->assertSame(0.0, FixedIncome::calculateAccruedInterest(25.0, -0.1, 0.5));
    }

    public function testCalculateNelsonSiegelAndSvenssonYieldZeroTau(): void
    {
        $level = 0.04;
        $slope = -0.01;
        $curv1 = 0.02;
        $curv2 = 0.01;

        $ns = FixedIncome::calculateNelsonSiegelYield($level, $slope, $curv1, 0.0);
        $this->assertEquals($level + $slope, $ns, 'Zero maturity should return level + slope (short rate).');

        $sv = FixedIncome::calculateSvenssonYield($level, $slope, $curv1, $curv2, 0.0);
        $this->assertEquals($level + $slope, $sv, 'Svensson zero maturity should return level + slope.');
    }

    public function testCalculateSvenssonYieldMatchesNelsonSiegelWhenCurvature2IsZero(): void
    {
        $level = 0.045;
        $slope = -0.015;
        $curvature1 = 0.02;
        $lambda1 = 0.50;

        foreach ([1.0, 2.0, 5.0, 10.0, 30.0] as $tau) {
            $nsYield = FixedIncome::calculateNelsonSiegelYield($level, $slope, $curvature1, $tau, $lambda1);
            $svenssonYield = FixedIncome::calculateSvenssonYield(
                level: $level,
                slope: $slope,
                curvature1: $curvature1,
                curvature2: 0.0,
                tau: $tau,
                lambda1: $lambda1,
                lambda2: 0.15
            );

            $this->assertEqualsWithDelta($nsYield, $svenssonYield, 0.00001, "Svensson must equal Nelson-Siegel when beta3=0 for tau=$tau");
        }
    }

    public function testCalculateSvenssonYieldSecondaryCurvatureHump(): void
    {
        $level = 0.04;
        $slope = 0.0;
        $curvature1 = 0.0;
        $curvature2 = 0.03; // Long-end hump
        $lambda2 = 0.15; // Peak around 1 / 0.15 ≈ 6.67 to 10 years

        $yieldShort = FixedIncome::calculateSvenssonYield($level, $slope, $curvature1, $curvature2, 0.1, 0.5, $lambda2);
        $yield10y = FixedIncome::calculateSvenssonYield($level, $slope, $curvature1, $curvature2, 10.0, 0.5, $lambda2);
        $yield100y = FixedIncome::calculateSvenssonYield($level, $slope, $curvature1, $curvature2, 100.0, 0.5, $lambda2);

        // Curvature 2 should be small near 0, reach hump in intermediate tenors, and decay asymptotically to level at infinite maturity
        $this->assertGreaterThan($yieldShort, $yield10y, 'Secondary curvature should create a hump in the yield curve.');
        $this->assertEqualsWithDelta($level, $yield100y, 0.005, 'Very long maturities should decay back toward long-term level.');
    }

    public function testBlissSlopeDecaySeparatesSlopeLoadingFromCurvatureHump(): void
    {
        $level = 0.045;
        $slope = -0.03;
        $lambda1 = 0.73;

        // With no slope decay given, Bliss collapses to plain Svensson.
        $plain = FixedIncome::calculateSvenssonYield($level, $slope, 0.0, 0.0, 10.0, $lambda1, 0.15);
        $collapsed = FixedIncome::calculateSvenssonYield($level, $slope, 0.0, 0.0, 10.0, $lambda1, 0.15, null);
        $this->assertEqualsWithDelta($plain, $collapsed, 0.0000001);

        // A slower slope decay keeps more of the short-rate gap alive at ten years: 0.32 versus 0.14 loading.
        $bliss = FixedIncome::calculateSvenssonYield($level, $slope, 0.0, 0.0, 10.0, $lambda1, 0.15, 0.30);
        $expectedLoad = (1.0 - exp(-3.0)) / 3.0;
        $this->assertEqualsWithDelta($level + $slope * $expectedLoad, $bliss, 0.0000001, 'Slope must load on its own decay.');
        $this->assertLessThan($plain, $bliss, 'A negative slope with slower decay pulls the ten-year lower.');

        // The curvature hump is untouched by the slope decay: same yield difference for a curvature shock either way.
        $humpPlain = FixedIncome::calculateSvenssonYield($level, $slope, 0.02, 0.0, 2.5, $lambda1, 0.15)
            - FixedIncome::calculateSvenssonYield($level, $slope, 0.0, 0.0, 2.5, $lambda1, 0.15);
        $humpBliss = FixedIncome::calculateSvenssonYield($level, $slope, 0.02, 0.0, 2.5, $lambda1, 0.15, 0.30)
            - FixedIncome::calculateSvenssonYield($level, $slope, 0.0, 0.0, 2.5, $lambda1, 0.15, 0.30);
        $this->assertEqualsWithDelta($humpPlain, $humpBliss, 0.0000001, 'Curvature loading must depend only on lambda1.');
    }

    /**
     * Vayanos-Vila duration extraction: the premium shift follows the ACM duration scale and flips sign between QE and QT.
     */
    public function testPreferredHabitatShiftScalesWithTenorAndFlipsBetweenQeAndQt(): void
    {
        // Delta TP(tau) = -lambda * durationScale(tau) * intensity, so the ten-year is the unit of measure.
        $this->assertEqualsWithDelta(-0.005, FixedIncome::calculatePreferredHabitatTermPremiumShift(0.005, 10.0), 0.0000001, 'QE must suppress the ten-year premium one-for-one with intensity.');
        $this->assertEqualsWithDelta(-0.005 * FixedIncome::calculateTermPremiumDurationScale(2.0), FixedIncome::calculatePreferredHabitatTermPremiumShift(0.005, 2.0), 0.0000001, 'The two-year absorbs under a third of the ten-year shift, the same share of the premium it carries.');
        $this->assertLessThan(-0.0014, FixedIncome::calculatePreferredHabitatTermPremiumShift(0.005, 2.0), 'and more than the linear fifth it used to.');
        $this->assertEqualsWithDelta(-0.005 * FixedIncome::calculateTermPremiumDurationScale(30.0), FixedIncome::calculatePreferredHabitatTermPremiumShift(0.005, 30.0), 0.0000001, 'The thirty-year absorbs half again the ten-year shift (Gagnon et al. 2011), not three times it.');
        $this->assertEqualsWithDelta(-0.0075, FixedIncome::calculatePreferredHabitatTermPremiumShift(0.005, 30.0), 0.0001);

        // QT extracts negative duration: the same magnitude steepens instead of compressing.
        $this->assertEqualsWithDelta(
            -FixedIncome::calculatePreferredHabitatTermPremiumShift(0.005, 10.0),
            FixedIncome::calculatePreferredHabitatTermPremiumShift(-0.005, 10.0),
            0.0000001,
            'QT must mirror QE of the same intensity.'
        );

        $this->assertSame(0.0, FixedIncome::calculatePreferredHabitatTermPremiumShift(0.0, 10.0), 'A flat balance sheet shifts nothing.');
        $this->assertSame(0.0, FixedIncome::calculatePreferredHabitatTermPremiumShift(0.005, 0.0), 'Overnight paper carries no duration to extract.');

        // Because the shift grows with tenor, QE always compresses the 2s30s slope.
        $twos = FixedIncome::calculatePreferredHabitatTermPremiumShift(0.004, 2.0);
        $thirties = FixedIncome::calculatePreferredHabitatTermPremiumShift(0.004, 30.0);
        $this->assertLessThan($twos, $thirties, 'QE must bite hardest at the long end.');
    }

    /** The forward premium is the derivative of tau x S(tau): what a constant-maturity zero earns, yield plus roll-down. */
    public function testTheTermPremiumForwardScaleIsTheForwardOfTheDurationScale(): void
    {
        foreach ([0.5, 2.0, 6.17, 10.0, 30.0] as $tau) {
            $step = 1.0e-5;
            $numerical = ((($tau + $step) * FixedIncome::calculateTermPremiumDurationScale($tau + $step))
                - (($tau - $step) * FixedIncome::calculateTermPremiumDurationScale($tau - $step))) / (2.0 * $step);
            $this->assertEqualsWithDelta($numerical, FixedIncome::calculateTermPremiumForwardScale($tau), 1e-8, "tau {$tau}");
            $this->assertGreaterThan(FixedIncome::calculateTermPremiumDurationScale($tau), FixedIncome::calculateTermPremiumForwardScale($tau), 'On a rising premium curve the forward sits above the yield.');
        }

        $this->assertSame(0.0, FixedIncome::calculateTermPremiumForwardScale(0.0), 'Overnight money earns no term premium.');
    }
}
