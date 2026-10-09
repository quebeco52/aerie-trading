<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * The normal distribution and its relatives: density, CDF and quantile, truncated-normal inversion, partial moments
 * of a normal and of a histogram, robust scale from the mean absolute deviation, and the Gompertz-Makeham waiting
 * time. Pure functions of their arguments.
 */
final class Distributions
{
    // --- Robust Scale Estimation ---
    /** Ratio of a zero-mean normal variable's mean absolute deviation to its sigma, sqrt(2 / pi). */
    public const MEAN_ABSOLUTE_DEVIATION_TO_SIGMA = 0.7978845608028654;

    // --- Normal Density ---
    /** Standard normal density at zero, 1 / sqrt(2 pi); the peak every greek's density term is scaled from. */
    public const STANDARD_NORMAL_PEAK = 0.3989422804014327;

    /**
     * Estimates the normal-equivalent scale (sigma) of a zero-centred sample from its mean absolute value.
     *
     * For a zero-mean normal variable E|X| = sigma * sqrt(2 / pi), so dividing the realized mean absolute
     * value by that constant recovers sigma. This is preferred to the sample standard deviation when the
     * sample is fat tailed, as earnings surprises are: a single outlier quarter dominates a sum of squares
     * and pushes the estimate far above the scale of a typical quarter, whereas the mean absolute value
     * degrades gracefully.
     *
     * @param list<float> $samples Observations centred on zero (an unbiased forecast error has zero mean).
     * @return float The estimated scale, or 0.0 when there is nothing to estimate from.
     */
    public static function calculateMeanAbsoluteScale(array $samples): float
    {
        $count = count($samples);
        if ($count === 0) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($samples as $sample) {
            $total += abs((float) $sample);
        }

        return ($total / $count) / self::MEAN_ABSOLUTE_DEVIATION_TO_SIGMA;
    }

    /**
     * Calculates the cumulative distribution function (CDF) for the standard normal distribution.
     * Uses a high-precision polynomial approximation.
     *
     * @param float $z The standard normal variable.
     * @return float The probability that a standard normal variable is less than or equal to $z.
     */
    public static function calculateNormalCDF(float $z): float
    {
        return self::standardNormalCdf($z);
    }

    /** Standard normal CDF (Abramowitz & Stegun 26.2.17), static so model code reads it without the sampler. */
    public static function standardNormalCdf(float $z): float
    {
        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;
        $p  = 0.2316419;
        $c  = 0.39894228;

        if ($z >= 0.0) {
            $t = 1.0 / (1.0 + $p * $z);
            return (1.0 - $c * exp(-$z * $z / 2.0) * $t *
                ($t * ($t * ($t * ($t * $b5 + $b4) + $b3) + $b2) + $b1));
        } else {
            $t = 1.0 / (1.0 - $p * $z);
            return ($c * exp(-$z * $z / 2.0) * $t *
                ($t * ($t * ($t * ($t * $b5 + $b4) + $b3) + $b2) + $b1));
        }
    }

    /** Standard normal density. */
    public static function standardNormalPdf(float $z): float
    {
        return exp(-$z * $z / 2.0) / sqrt(2.0 * M_PI);
    }

    /**
     * A draw from a normal truncated to [min, max] by inverse transform: the uniform picks a quantile of the truncated
     * law, so one uniform gives one draw and the draw never leaves the range.
     *
     * @param float $uniform A uniform draw on [0, 1].
     */
    public static function truncatedNormalInverse(float $uniform, float $mean, float $sd, float $min, float $max): float
    {
        $low = self::calculateNormalCDF(($min - $mean) / $sd);
        $high = self::calculateNormalCDF(($max - $mean) / $sd);

        return $mean + ($sd * self::calculateInverseNormalCDF($low + ($uniform * ($high - $low))));
    }

    /**
     * Years until someone aged $age leaves under a Gompertz-Makeham hazard, background + level * exp(slope * age) a year
     * (Makeham 1860), drawn by inverse transform: the wait whose cumulative hazard equals -ln(uniform). The cumulative
     * hazard is convex in the wait, so Newton's method from its first-order bound falls onto the root from above.
     *
     * @param float $uniform    A uniform draw on (0, 1).
     * @param float $age        Age now, in years.
     * @param float $level      Gompertz level: the age-related hazard at age 0, per year.
     * @param float $slope      Gompertz slope: the age-related hazard's growth per year of age.
     * @param float $background Makeham term: the hazard that does not rise with age, per year.
     */
    public static function gompertzMakehamWait(float $uniform, float $age, float $level, float $slope, float $background): float
    {
        $target = -log(max(1e-300, $uniform));
        $ageHazard = $level * exp($slope * $age);
        if ($background + $ageHazard <= 0.0) {
            return INF;
        }
        $wait = $target / ($background + $ageHazard);
        if ($ageHazard <= 0.0 || $slope <= 0.0) {
            return $wait;
        }
        for ($iteration = 0; $iteration < 64; ++$iteration) {
            $grown = exp($slope * $wait);
            $step = (($background * $wait) + ($ageHazard * ($grown - 1.0) / $slope) - $target) / ($background + ($ageHazard * $grown));
            $wait -= $step;
            if (abs($step) < 1e-12 * max(1.0, $wait)) {
                break;
            }
        }

        return $wait;
    }

    /**
     * Calculates the inverse of the standard normal cumulative distribution function (Probit function).
     * Uses Peter J. Acklam's high-precision rational approximation (maximum error < 1.15e-9).
     *
     * @param float $p Probability value in (0, 1). Clamped to [1e-12, 1 - 1e-12] to prevent NaN/Inf.
     * @return float The standard normal quantile z corresponding to probability p.
     */
    public static function calculateInverseNormalCDF(float $p): float
    {
        return self::standardNormalQuantile($p);
    }

    /** Standard normal quantile (Acklam's rational approximation), static so model code reads it without the sampler. */
    public static function standardNormalQuantile(float $p): float
    {
        $p = max(1e-12, min(1.0 - 1e-12, $p));

        // Coefficients in rational approximations
        $a1 = -3.969683028665376e+01;
        $a2 =  2.209460984245205e+02;
        $a3 = -2.759285104469687e+02;
        $a4 =  1.383577518672690e+02;
        $a5 = -3.066479806614716e+01;
        $a6 =  2.506628277459239e+00;

        $b1 = -5.447609879822406e+01;
        $b2 =  1.615858368580409e+02;
        $b3 = -1.556989798598866e+02;
        $b4 =  6.680131188771972e+01;
        $b5 = -1.328068155288572e+01;

        $c1 = -7.784894002430293e-03;
        $c2 = -3.223964580411365e-01;
        $c3 = -2.400758277161838e+00;
        $c4 = -2.549732539343734e+00;
        $c5 =  4.374664141464968e+00;
        $c6 =  2.938163982698783e+00;

        $d1 =  7.784695709041462e-03;
        $d2 =  3.224671290700398e-01;
        $d3 =  2.445134137142996e+00;
        $d4 =  3.754408661907416e+00;

        $pLow  = 0.02425;
        $pHigh = 1.0 - $pLow;

        if ($p < $pLow) {
            // Rational approximation for lower tail
            $q = sqrt(-2.0 * log($p));
            return (((((($c1 * $q + $c2) * $q + $c3) * $q + $c4) * $q + $c5) * $q + $c6) /
                (((($d1 * $q + $d2) * $q + $d3) * $q + $d4) * $q + 1.0));
        }

        if ($p <= $pHigh) {
            // Rational approximation for central region
            $q = $p - 0.5;
            $r = $q * $q;
            return (((((($a1 * $r + $a2) * $r + $a3) * $r + $a4) * $r + $a5) * $r + $a6) * $q) /
                (((((($b1 * $r + $b2) * $r + $b3) * $r + $b4) * $r + $b5) * $r + 1.0));
        }

        // Rational approximation for upper tail
        $q = sqrt(-2.0 * log(1.0 - $p));
        return - (((((($c1 * $q + $c2) * $q + $c3) * $q + $c4) * $q + $c5) * $q + $c6) /
            (((($d1 * $q + $d2) * $q + $d3) * $q + $d4) * $q + 1.0));
    }

    /**
     * Standard normal probability density, the derivative of calculateNormalCDF().
     *
     * @param float $z Standard normal deviate.
     * @return float The density at z.
     */
    public static function calculateNormalPDF(float $z): float
    {
        return self::STANDARD_NORMAL_PEAK * exp(-0.5 * $z * $z);
    }

    /**
     * First lower partial moment of the standard normal, E[max(0, t - Z)] = t·Φ(t) + φ(t): the expected
     * payout of a unit layer that pays the shortfall of a standard normal draw below t (the Bachelier put).
     *
     * @param float $threshold The level t below which the layer pays.
     * @return float The expected shortfall below t, in standard deviations.
     */
    public static function calculateNormalLowerPartialMoment(float $threshold): float
    {
        return ($threshold * self::calculateNormalCDF($threshold)) + self::calculateNormalPDF($threshold);
    }

    /**
     * First upper partial moment of a histogram, E[max(0, X - t)], with X uniform within each bin: the part of a
     * binned distribution standing above a threshold. Its derivative in t is minus the share above it
     * (histogramUpperTailShare()), and at a threshold below every bin it is the mean.
     *
     * @param list<float> $edges   Bin edges, ascending; one more than the weights.
     * @param list<float> $weights Each bin's mass; normalised by their sum.
     * @param float       $threshold The level t.
     */
    public static function histogramUpperPartialMoment(array $edges, array $weights, float $threshold): float
    {
        $total = array_sum($weights);
        $moment = 0.0;
        foreach ($weights as $bin => $weight) {
            $low = max($edges[$bin], $threshold);
            $high = $edges[$bin + 1];
            if ($high <= $low) {
                continue;
            }
            $massAbove = ($weight / $total) * ($high - $low) / ($high - $edges[$bin]);
            $moment += $massAbove * ((($low + $high) / 2.0) - $threshold);
        }

        return $moment;
    }

    /**
     * Share of a histogram above a threshold, with mass uniform within each bin.
     *
     * @param list<float> $edges   Bin edges, ascending; one more than the weights.
     * @param list<float> $weights Each bin's mass; normalised by their sum.
     * @param float       $threshold The level t.
     */
    public static function histogramUpperTailShare(array $edges, array $weights, float $threshold): float
    {
        $total = array_sum($weights);
        $share = 0.0;
        foreach ($weights as $bin => $weight) {
            $low = max($edges[$bin], $threshold);
            $high = $edges[$bin + 1];
            if ($high > $low) {
                $share += ($weight / $total) * ($high - $low) / ($high - $edges[$bin]);
            }
        }

        return $share;
    }
}
