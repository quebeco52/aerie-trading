<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * The normal distribution and its relatives: density, CDF and quantile, truncated-normal inversion, partial moments
 * of a normal and of a histogram, robust scale from the mean absolute deviation, the Gompertz-Makeham waiting time and
 * the censored Pareto severity. Pure functions of their arguments.
 */
final class Distributions
{
    // --- Normal Density ---
    /** Standard normal density at zero, 1 / sqrt(2 pi); the peak every greek's density term is scaled from. */
    public const STANDARD_NORMAL_PEAK = 0.3989422804014327;

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

    /** Standard normal CDF (Abramowitz & Stegun 26.2.17). */
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
            return (1.0 - $c * exp(-$z * $z / 2.0) * $t
                * ($t * ($t * ($t * ($t * $b5 + $b4) + $b3) + $b2) + $b1));
        } else {
            $t = 1.0 / (1.0 - $p * $z);
            return ($c * exp(-$z * $z / 2.0) * $t
                * ($t * ($t * ($t * ($t * $b5 + $b4) + $b3) + $b2) + $b1));
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
     * Years until someone aged $age leaves under a Gompertz-Makeham hazard, background + level * exp(slope * age) a
     * year (Makeham 1860), drawn by inverse transform: the wait whose cumulative hazard equals -ln(uniform). The
     * cumulative hazard is convex in the wait, so Newton's method from its first-order bound falls onto the root from
     * above.
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
     * One of two independent components of a standard normal draw, given the draw: with the component's variance share
     * s and an independent standard normal u, s z + sqrt(s (1 - s)) u has variance s and is independent of the rest,
     * z less it. The draw itself is unchanged, so a path split this way is the path unsplit.
     *
     * @param float $total       The standard normal draw being split.
     * @param float $independent A standard normal independent of it.
     * @param float $share       The component's share of the draw's variance, in [0, 1].
     */
    public static function conditionalComponent(float $total, float $independent, float $share): float
    {
        $share = max(0.0, min(1.0, $share));

        return ($share * $total) + (sqrt($share * (1.0 - $share)) * $independent);
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

    /**
     * Standard normal quantile (Acklam's rational approximation).
     */
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
            return (((((($c1 * $q + $c2) * $q + $c3) * $q + $c4) * $q + $c5) * $q + $c6)
                / (((($d1 * $q + $d2) * $q + $d3) * $q + $d4) * $q + 1.0));
        }

        if ($p <= $pHigh) {
            // Rational approximation for central region
            $q = $p - 0.5;
            $r = $q * $q;
            return (((((($a1 * $r + $a2) * $r + $a3) * $r + $a4) * $r + $a5) * $r + $a6) * $q)
                / (((((($b1 * $r + $b2) * $r + $b3) * $r + $b4) * $r + $b5) * $r + 1.0));
        }

        // Rational approximation for upper tail
        $q = sqrt(-2.0 * log(1.0 - $p));
        return - (((((($c1 * $q + $c2) * $q + $c3) * $q + $c4) * $q + $c5) * $q + $c6)
            / (((($d1 * $q + $d2) * $q + $d3) * $q + $d4) * $q + 1.0));
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
     * binned distribution standing above a threshold. At a threshold below every bin it is the mean.
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

    // --- Censored Pareto Severity ---
    /** Bisection steps when inverting the censored mean: halves the bracket to well below a float's resolution. */
    private const PARETO_SCALE_BISECTION_STEPS = 100;

    /**
     * Mean of a single-parameter Pareto severity censored at a cap, E[min(X, cap)] for X = scale U^(-1/alpha):
     * (alpha scale - scale^alpha cap^(1 - alpha)) / (alpha - 1). Below the untruncated mean, by what the cap cuts off.
     */
    public static function censoredParetoMean(float $scale, float $alpha, float $cap): float
    {
        if ($scale <= 0.0) {
            return 0.0;
        }
        if ($cap <= $scale) {
            return $cap;
        }

        return (($alpha * $scale) - (($scale ** $alpha) * ($cap ** (1.0 - $alpha)))) / ($alpha - 1.0);
    }

    /**
     * The Pareto scale whose severity, censored at the cap, has the given mean: the censored mean rises with the
     * scale, so bisection finds it. Sizing on the untruncated mean instead leaves the capped draw short of it.
     */
    public static function paretoScaleForCensoredMean(float $mean, float $alpha, float $cap): float
    {
        if ($mean >= $cap) {
            return $cap;
        }

        $low = 0.0;
        $high = $cap;
        for ($step = 0; $step < self::PARETO_SCALE_BISECTION_STEPS; ++$step) {
            $mid = 0.5 * ($low + $high);
            if (self::censoredParetoMean($mid, $alpha, $cap) < $mean) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return 0.5 * ($low + $high);
    }

    // --- Compound Poisson Losses ---
    /**
     * Distribution of a compound Poisson sum on a lattice (Panjer 1981): a Poisson(lambda) count of events, each of a
     * size drawn from $severityPmf (its mass at k lattice steps), and the total's mass at each of $size steps. Exact on
     * the lattice: g_0 = exp(-lambda (1 - f_0)), g_n = (lambda / n) sum_j j f_j g_(n-j).
     *
     * @param list<float> $severityPmf
     * @return list<float>
     */
    public static function compoundPoissonPmf(float $lambda, array $severityPmf, int $size): array
    {
        $weighted = [];
        foreach ($severityPmf as $steps => $mass) {
            if ($steps > 0 && $mass > 0.0) {
                $weighted[$steps] = $lambda * $steps * $mass;
            }
        }

        $total = [exp(-$lambda * (1.0 - ($severityPmf[0] ?? 0.0)))];
        for ($n = 1; $n < $size; ++$n) {
            $sum = 0.0;
            foreach ($weighted as $steps => $weight) {
                if ($steps > $n) {
                    break;
                }
                $sum += $weight * $total[$n - $steps];
            }
            $total[] = $sum / $n;
        }

        return $total;
    }

    // --- Censored Normal Layer ---
    /**
     * E[max(0, scale max(0, threshold - Z) + shift)] for a standard normal Z: what a layer attaching at zero pays on a
     * loss made of a fixed part (shift) and a normal shortfall below a threshold. Above zero the fixed part is paid in
     * full and the shortfall adds its lower partial moment; below it the shortfall has to make it up first.
     */
    public static function normalHingeLayerExpectation(float $scale, float $threshold, float $shift): float
    {
        if ($shift >= 0.0) {
            return $shift + (max(0.0, $scale) * self::calculateNormalLowerPartialMoment($threshold));
        }
        if ($scale <= 0.0) {
            return 0.0;
        }

        return $scale * self::calculateNormalLowerPartialMoment($threshold + ($shift / $scale));
    }

    /** P(scale max(0, threshold - Z) + shift > 0) for a standard normal Z: how often that layer pays anything. */
    public static function normalHingeLayerExceedance(float $scale, float $threshold, float $shift): float
    {
        if ($shift > 0.0) {
            return 1.0;
        }
        if ($scale <= 0.0) {
            return 0.0;
        }

        return self::calculateNormalCDF($threshold + ($shift / $scale));
    }

    // --- Kinked Normal Payoff ---
    /**
     * E[max(0, slope Z + kinkSlope max(0, Z - kink) + intercept)] for a standard normal Z and slopes of zero or more:
     * the mean positive part of a line in the draw that steepens above a kink (a revenue line with a fee call on top).
     * Turning positive below the kink, it is the line's positive part plus the call; above it, the steeper line's.
     */
    public static function normalKinkedPositivePart(float $slope, float $kinkSlope, float $kink, float $intercept): float
    {
        if (($slope * $kink) + $intercept >= 0.0) {
            $line = $slope > 0.0 ? $slope * self::calculateNormalLowerPartialMoment($intercept / $slope) : $intercept;

            return $line + ($kinkSlope * self::calculateNormalLowerPartialMoment(-$kink));
        }

        $steepSlope = $slope + $kinkSlope;
        if ($steepSlope <= 0.0) {
            return 0.0;
        }

        $root = (($kinkSlope * $kink) - $intercept) / $steepSlope;

        return $steepSlope * self::calculateNormalLowerPartialMoment(-$root);
    }
}
