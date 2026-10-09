<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Fixed income: the Nelson-Siegel and Svensson curves, term-premium scaling, the single evaluation of the sovereign
 * zero curve, and bond price, yield, duration, convexity and accrued interest. Pure functions of their arguments.
 */
final class FixedIncome
{
    // --- Yield To Maturity Solver ---
    /** Newton-Raphson iteration cap; a well-bracketed bond converges in well under ten. */
    public const YTM_MAX_ITERATIONS = 64;
    /** Price convergence tolerance, in currency units on a 100-face bond (0.01 = one cent). */
    public const YTM_PRICE_TOLERANCE = 1.0e-9;

    /**
     * Calculates the yield for a given maturity using the Nelson-Siegel curve model.
     *
     * @param float $level     The long-term yield level.
     * @param float $slope     The short-term yield component.
     * @param float $curvature The medium-term hump component.
     * @param float $tau       The maturity in years (e.g., 10.0 for the 10-year yield).
     * @param float $lambda    The decay factor.
     * @return float The calculated yield for the specified maturity.
     */
    public static function calculateNelsonSiegelYield(float $level, float $slope, float $curvature, float $tau, float $lambda = 0.5): float
    {
        if ($tau <= 0.0) {
            return $level + $slope;
        }

        $term1 = (1 - exp(-$lambda * $tau)) / ($lambda * $tau);
        $term2 = $term1 - exp(-$lambda * $tau);

        return $level + ($slope * $term1) + ($curvature * $term2);
    }

    /**
     * Term premium duration scale (Adrian, Crump & Moench 2013): the compensation investors demand for
     * bearing duration rises with maturity and saturates, so a two-year note carries only a fraction of the
     * premium a ten-year bond does. Normalized to 1.0 at the ten-year point, where the benchmark premium is
     * quoted, and 0.0 at zero maturity, where a yield is the policy rate and nothing else.
     */
    public static function calculateTermPremiumDurationScale(float $tau, float $horizonYears = 10.0): float
    {
        $horizon = max(0.01, $horizonYears);

        return (1.0 - exp(-max(0.0, $tau) / $horizon)) / (1.0 - exp(-10.0 / $horizon));
    }

    /**
     * The instantaneous forward term premium that duration scale implies, d(tau x S(tau)) / dtau, on the same
     * ten-year normalization. A constant-maturity zero held on an unchanging curve earns the forward rate at its
     * maturity, the yield plus its roll-down (f = y + tau x dy/dtau), so this is the premium such an index
     * earns over the short rate in steady state: 1 - e^(-tau/H) + (tau/H) e^(-tau/H), over 1 - e^(-10/H).
     */
    public static function calculateTermPremiumForwardScale(float $tau, float $horizonYears = 10.0): float
    {
        $horizon = max(0.01, $horizonYears);
        $x = max(0.0, $tau) / $horizon;

        return (1.0 - exp(-$x) + ($x * exp(-$x))) / (1.0 - exp(-10.0 / $horizon));
    }

    /**
     * Nelson-Siegel-Svensson (1994) zero-coupon yield with the Bliss (1997) extension: the slope factor may
     * decay at its own rate, separate from the primary curvature. When the level and slope are pinned to
     * economics rather than fitted, the slope decay is the market's belief about how fast the policy rate
     * returns to neutral (a Vasicek expectations-hypothesis loading), while the curvature decay sets where
     * the forward-guidance hump sits. Diebold-Li's single decay cannot serve both roles at once.
     *
     * @param float      $level       The long-term asymptotic yield level (beta0).
     * @param float      $slope       The short-rate component (beta1), policy rate minus level.
     * @param float      $curvature1  The primary medium-term hump component (beta2).
     * @param float      $curvature2  The secondary long-term hump component (beta3).
     * @param float      $tau         The maturity in years.
     * @param float      $lambda1     Decay of the primary hump (and of the slope when no slope decay is given).
     * @param float      $lambda2     Decay of the secondary hump.
     * @param float|null $slopeLambda Bliss (1997) slope decay; null reproduces the plain Svensson form.
     */
    public static function calculateSvenssonYield(
        float $level,
        float $slope,
        float $curvature1,
        float $curvature2,
        float $tau,
        float $lambda1 = 0.5,
        float $lambda2 = 0.15,
        ?float $slopeLambda = null
    ): float {
        if ($tau <= 0.0) {
            return $level + $slope;
        }

        $slopeDecay = $slopeLambda ?? $lambda1;
        $term1 = (1.0 - exp(-$slopeDecay * $tau)) / ($slopeDecay * $tau);
        $curvatureLoad = (1.0 - exp(-$lambda1 * $tau)) / ($lambda1 * $tau);
        $term2 = $curvatureLoad - exp(-$lambda1 * $tau);

        $term3 = (1.0 - exp(-$lambda2 * $tau)) / ($lambda2 * $tau);
        $term4 = $term3 - exp(-$lambda2 * $tau);

        return $level + ($slope * $term1) + ($curvature1 * $term2) + ($curvature2 * $term4);
    }

    /**
     * Calculates the duration-weighted preferred-habitat term premium shift under QE/QT (Vayanos & Vila 2021).
     *
     * When the central bank expands its balance sheet via Quantitative Easing (QE), it extracts net duration
     * from the market, reducing the duration risk absorbed by private arbitrageurs and compressing term premia.
     * The effect scales with the same saturating duration law as the premium it offsets: Gagnon et al. (2011)
     * find the thirty-year LSAP effect at or below the ten-year's, not three times it.
     *
     * Formula: Delta TP(tau) = - lambda_habitat * durationScale(tau) * balanceSheetIntensity
     *
     * @param float $balanceSheetIntensity Positive for QE (yield suppression), negative for QT (steepening).
     * @param float $tau                   Bond tenor maturity in years (e.g. 2.0, 5.0, 10.0, 30.0).
     * @param float $habitatSensitivity    Sensitivity parameter scaling duration extraction.
     * @return float Term premium shift in decimal (e.g., -0.0050 for -50bps).
     */
    public static function calculatePreferredHabitatTermPremiumShift(
        float $balanceSheetIntensity,
        float $tau,
        float $habitatSensitivity = 1.0
    ): float {
        $durationWeight = self::calculateTermPremiumDurationScale($tau);
        return -$balanceSheetIntensity * $durationWeight * $habitatSensitivity;
    }

    /**
     * Nominal sovereign zero-coupon yield at an arbitrary tenor, term premium and central-bank duration
     * extraction included, floored at the effective lower bound.
     *
     * This is the single evaluation of the sovereign curve. MonetaryPolicySubsystem calls it to publish the
     * benchmark 2y/5y/10y/30y points, and the bond desk calls it to discount a cash flow that falls between
     * them. A second implementation would price a seven-year note off a curve that the macro dashboard never
     * quoted, and the gap between the two would be a risk-free arbitrage for anyone who noticed.
     *
     * @param float $tau                     Maturity in years.
     * @param float $level                   Asymptotic long-term yield level (beta0).
     * @param float $slope                   Short-rate slope parameter (beta1).
     * @param float $curvature1              Medium-term hump parameter (beta2).
     * @param float $curvature2              Long-term secondary hump parameter (beta3).
     * @param float $lambda1                 Decay of the primary hump.
     * @param float $lambda2                 Decay of the secondary hump.
     * @param float $slopeLambda             Bliss (1997) slope decay.
     * @param float $termPremium10y          Ten-year term premium, scaled down by duration below ten years.
     * @param float $longEndPremium          Structural premium that keeps accruing past the ten-year point.
     * @param float $termPremiumHorizonYears Horizon of the ACM (2013) duration scale.
     * @param float $balanceSheetIntensity   QE (positive) or QT (negative) duration extraction intensity.
     * @param float $habitatSensitivity      Vayanos-Vila preferred-habitat sensitivity.
     * @param float $effectiveLowerBound     Nominal floor on the resulting yield.
     * @return float The nominal zero-coupon yield at the requested tenor.
     */
    public static function calculateSovereignZeroYield(
        float $tau,
        float $level,
        float $slope,
        float $curvature1,
        float $curvature2,
        float $lambda1,
        float $lambda2,
        float $slopeLambda,
        float $termPremium10y,
        float $longEndPremium,
        float $termPremiumHorizonYears,
        float $balanceSheetIntensity,
        float $habitatSensitivity,
        float $effectiveLowerBound
    ): float {
        return max($effectiveLowerBound, self::calculateSovereignZeroYieldUnbounded(
            tau: $tau,
            level: $level,
            slope: $slope,
            curvature1: $curvature1,
            curvature2: $curvature2,
            lambda1: $lambda1,
            lambda2: $lambda2,
            slopeLambda: $slopeLambda,
            termPremium10y: $termPremium10y,
            longEndPremium: $longEndPremium,
            termPremiumHorizonYears: $termPremiumHorizonYears,
            balanceSheetIntensity: $balanceSheetIntensity,
            habitatSensitivity: $habitatSensitivity
        ));
    }

    /**
     * The same curve before the effective lower bound is applied.
     *
     * Split out because the floor is the one part of the curve that is not smooth in tenor, and a caller
     * sampling the curve onto a grid has to interpolate the smooth part and apply the floor afterwards. Doing
     * it the other way round interpolates ACROSS the kink: between two pillars that straddle the point where
     * the floor starts binding, a straight line cuts the corner off and misprices the cash flows that fall in
     * that cell. Every parameter is as calculateSovereignZeroYield() documents it.
     */
    public static function calculateSovereignZeroYieldUnbounded(
        float $tau,
        float $level,
        float $slope,
        float $curvature1,
        float $curvature2,
        float $lambda1,
        float $lambda2,
        float $slopeLambda,
        float $termPremium10y,
        float $longEndPremium,
        float $termPremiumHorizonYears,
        float $balanceSheetIntensity,
        float $habitatSensitivity
    ): float {
        $preferredHabitatShift = self::calculatePreferredHabitatTermPremiumShift(
            balanceSheetIntensity: $balanceSheetIntensity,
            tau: $tau,
            habitatSensitivity: $habitatSensitivity
        );

        $durationScale = self::calculateTermPremiumDurationScale($tau, $termPremiumHorizonYears);
        $termPremium = ($termPremium10y * min(1.0, $durationScale)) + ($longEndPremium * max(0.0, $durationScale - 1.0));

        $yield = self::calculateSvenssonYield(
            level: $level,
            slope: $slope,
            curvature1: $curvature1,
            curvature2: $curvature2,
            tau: $tau,
            lambda1: $lambda1,
            lambda2: $lambda2,
            slopeLambda: $slopeLambda
        );

        return $yield + $termPremium + $preferredHabitatShift;
    }

    /**
     * Present value of a bond's cash flows under continuous compounding against a zero-coupon curve.
     *
     * Each flow is discounted at the zero rate for its own settlement distance rather than at a single
     * yield to maturity, so a steep curve prices a long bond differently from a flat curve at the same
     * average level. Continuous compounding matches the Nelson-Siegel-Svensson curve the rates come from,
     * which is quoted as a continuously compounded zero curve.
     *
     * @param array<int, array{time: float, amount: float}> $cashFlows Flows in years from settlement, ascending.
     * @param callable(float): float                        $zeroYield Zero-coupon yield at a tenor in years.
     * @return float The dirty price: present value including accrued interest.
     */
    public static function calculateBondPresentValue(array $cashFlows, callable $zeroYield): float
    {
        $presentValue = 0.0;

        foreach ($cashFlows as $flow) {
            $time = (float) $flow['time'];
            if ($time <= 0.0) {
                continue;
            }

            $presentValue += ((float) $flow['amount']) * exp(-$zeroYield($time) * $time);
        }

        return $presentValue;
    }

    /**
     * Macaulay duration: the present-value-weighted average time to a bond's cash flows, in years.
     *
     * @param array<int, array{time: float, amount: float}> $cashFlows Flows in years from settlement.
     * @param float                                         $yieldToMaturity Continuously compounded YTM.
     * @return float Weighted average time to cash flow, in years.
     */
    public static function calculateMacaulayDuration(array $cashFlows, float $yieldToMaturity): float
    {
        $weightedTime = 0.0;
        $presentValue = 0.0;

        foreach ($cashFlows as $flow) {
            $time = (float) $flow['time'];
            if ($time <= 0.0) {
                continue;
            }

            $discounted = ((float) $flow['amount']) * exp(-$yieldToMaturity * $time);
            $presentValue += $discounted;
            $weightedTime += $discounted * $time;
        }

        if ($presentValue <= 0.0) {
            return 0.0;
        }

        return $weightedTime / $presentValue;
    }

    /**
     * Modified duration: the first-order price sensitivity to a parallel yield shift, -(1/P)(dP/dy).
     *
     * Under continuous compounding modified duration equals Macaulay duration exactly; the periodic
     * 1/(1 + y/k) adjustment belongs to discrete compounding and applying it here would understate the
     * sensitivity of every bond on the desk.
     *
     * @param float $macaulayDuration Macaulay duration in years.
     * @return float Modified duration in years.
     */
    public static function calculateModifiedDuration(float $macaulayDuration): float
    {
        return $macaulayDuration;
    }

    /**
     * Convexity: the second-order price sensitivity, (1/P)(d2P/dy2), in years squared.
     *
     * The term that makes a duration estimate accurate for a large yield move. Price change over a shift
     * dy is -D_mod * dy + 0.5 * C * dy^2, and without the convexity leg a 100bp move on a thirty-year
     * bond misprices by enough to be visible in a portfolio.
     *
     * @param array<int, array{time: float, amount: float}> $cashFlows Flows in years from settlement.
     * @param float                                         $yieldToMaturity Continuously compounded YTM.
     * @return float Convexity in years squared.
     */
    public static function calculateConvexity(array $cashFlows, float $yieldToMaturity): float
    {
        $weighted = 0.0;
        $presentValue = 0.0;

        foreach ($cashFlows as $flow) {
            $time = (float) $flow['time'];
            if ($time <= 0.0) {
                continue;
            }

            $discounted = ((float) $flow['amount']) * exp(-$yieldToMaturity * $time);
            $presentValue += $discounted;
            $weighted += $discounted * $time * $time;
        }

        if ($presentValue <= 0.0) {
            return 0.0;
        }

        return $weighted / $presentValue;
    }

    /**
     * Yield to maturity: the single continuously compounded rate that reproduces a bond's dirty price.
     *
     * Newton-Raphson on the price function, whose derivative with respect to yield is the negative
     * PV-weighted time, so each step is price error divided by a quantity the duration calculation already
     * needs. Falls back to bisection bounds if a step leaves the bracket, which a deeply distressed or
     * very long zero can do from a poor starting guess.
     *
     * @param array<int, array{time: float, amount: float}> $cashFlows Flows in years from settlement.
     * @param float                                         $dirtyPrice Target present value.
     * @param float                                         $guess Starting yield.
     * @return float The continuously compounded yield to maturity.
     */
    public static function calculateYieldToMaturity(array $cashFlows, float $dirtyPrice, float $guess = 0.04): float
    {
        if ($dirtyPrice <= 0.0 || $cashFlows === []) {
            return 0.0;
        }

        $yield = $guess;
        $lowerBound = -0.99;
        $upperBound = 5.0;

        for ($iteration = 0; $iteration < self::YTM_MAX_ITERATIONS; $iteration++) {
            $presentValue = 0.0;
            $derivative = 0.0;

            foreach ($cashFlows as $flow) {
                $time = (float) $flow['time'];
                if ($time <= 0.0) {
                    continue;
                }

                $discounted = ((float) $flow['amount']) * exp(-$yield * $time);
                $presentValue += $discounted;
                $derivative -= $discounted * $time;
            }

            $error = $presentValue - $dirtyPrice;
            if (abs($error) < self::YTM_PRICE_TOLERANCE) {
                return $yield;
            }

            if ($error > 0.0) {
                $lowerBound = $yield;
            } else {
                $upperBound = $yield;
            }

            if ($derivative === 0.0) {
                break;
            }

            $step = $error / $derivative;
            $next = $yield - $step;

            if ($next <= $lowerBound || $next >= $upperBound || !is_finite($next)) {
                $next = ($lowerBound + $upperBound) / 2.0;
            }

            $yield = $next;
        }

        return $yield;
    }

    /**
     * Accrued interest on an actual/actual basis: the share of the current coupon period already earned.
     *
     * Separates the dirty price a buyer pays from the clean price a desk quotes. Without it the quoted
     * price of a coupon bond saws upward through every period and drops at each payment, which reads as
     * volatility that the instrument does not actually have.
     *
     * @param float $couponAmount   Cash paid at the end of the current coupon period.
     * @param float $periodElapsed  Years elapsed since the last coupon.
     * @param float $periodLength   Full length of the coupon period in years.
     * @return float Interest accrued to the seller.
     */
    public static function calculateAccruedInterest(float $couponAmount, float $periodElapsed, float $periodLength): float
    {
        if ($periodLength <= 0.0 || $periodElapsed <= 0.0) {
            return 0.0;
        }

        return $couponAmount * min(1.0, $periodElapsed / $periodLength);
    }
}
