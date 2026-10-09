<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Deterministic mathematics of the simulated processes, given their draws: moments and Merton compensators of the
 * Kou and truncated jump laws, the variance-jump mean, the Levy-driven OU level shift, exact OU and Schwartz steps,
 * the Schwartz forward and convenience yield, and the persistence variance scale. The draws themselves come from
 * MathUtility.
 */
final class StochasticProcesses
{
    // --- SVJJ Jump Process ---
    /** Mean variance jump on an UP price jump, as a share of the mean on a down jump; crashes spike volatility harder than rallies. */
    public const VARIANCE_JUMP_UPSIDE_MEAN_SHARE = 0.50;
    /** Ceiling on a single variance jump, as a multiple of its mean, so one draw cannot permanently corrupt the volatility state. */
    public const MAX_VARIANCE_JUMP_MEAN_MULTIPLE = 10.0;

    // --- Kou Compensator ---
    /** Exponent rate at which the closed-form compensator switches to its removable-singularity limit, where eta approaches one. */
    public const COMPENSATOR_UNIT_RATE_TOLERANCE = 1.0e-6;

    // --- Levy-Driven OU Compensator ---
    /** Even number of Simpson intervals on [0, 1] for the jump level shift; the integrand is smooth, so 32 is exact to ~1e-9. */
    public const LEVY_OU_COMPENSATOR_SIMPSON_INTERVALS = 32;

    /**
     * Converts a per-step persistence coefficient into the scale factor that preserves integrated variance.
     *
     * A sequence of N i.i.d. unit-variance shocks sums to variance N. Making those shocks autocorrelated with
     * coefficient phi inflates the sum's variance to approximately N * (1 + phi) / (1 - phi), so simply
     * swapping an i.i.d. driver for a persistent one silently multiplies realized volatility -- by more than
     * twenty times at the persistence levels used for a market factor on a fine time step. Scaling each shock
     * by this factor restores the original integrated variance while keeping the autocorrelation structure,
     * so persistence changes the SHAPE of the path (trends and regimes) without changing its magnitude.
     *
     * @param float $phi The autoregressive persistence coefficient in [0, 1).
     * @return float The multiplicative scale preserving the integrated variance of the shock sequence.
     */
    public static function calculatePersistenceVarianceScale(float $phi): float
    {
        $boundedPhi = max(0.0, min(0.999999, $phi));

        return sqrt((1.0 - $boundedPhi) / (1.0 + $boundedPhi));
    }

    /**
     * Second moment of one capped Kou double-exponential jump, E[J^2].
     *
     * The jump sizes this engine draws are truncated at MAX_JUMP_LOG_RETURN and MIN_JUMP_LOG_RETURN, so the
     * plain exponential moment 2/eta^2 overstates what a jump actually delivers — badly for a large jump
     * scale, where most of the mass sits beyond the cap. For X ~ Exp(eta) truncated at c,
     *
     *     E[min(X, c)^2] = (2 / eta^2) * (1 - e^(-eta c) * (eta c + 1))
     *
     * which is the incomplete gamma integral with the surviving point mass at the cap folded back in. This
     * is the variance one arrival supplies, so it is what the variance budget has to hand back.
     *
     * @param float $pUp     Probability the jump is upwards.
     * @param float $etaUp   Exponential rate of the up jump.
     * @param float $etaDown Exponential rate of the down jump.
     * @param float $capUp   Largest up jump, as a positive log return.
     * @param float $capDown Largest down jump, as a POSITIVE magnitude.
     */
    public static function kouTruncatedSecondMoment(
        float $pUp,
        float $etaUp,
        float $etaDown,
        float $capUp,
        float $capDown
    ): float {
        return ($pUp * self::truncatedExponentialSecondMoment($etaUp, $capUp))
            + ((1.0 - $pUp) * self::truncatedExponentialSecondMoment($etaDown, $capDown));
    }

    /**
     * Merton's jump compensator for a capped Kou jump, E[e^J - 1].
     *
     * A jump-diffusion whose drift is set to the expected return and then multiplied by e^J does not earn
     * that expected return: it earns it plus lambda * E[e^J - 1] per unit time. Kou's jump is skewed down,
     * so the term is negative and the shortfall is a silent return tax. Merton (1976) removes it by
     * subtracting lambda * E[e^J - 1] from the drift, which is what makes a jump a change in the SHAPE of
     * returns rather than in their mean.
     *
     * With the same truncation the draws obey, for X ~ Exp(eta) capped at c:
     *
     *     E[e^min(X,c)]   = eta / (eta - 1) * (1 - e^(-(eta - 1) c)) + e^(-(eta - 1) c)
     *     E[e^-min(X,d)]  = eta / (eta + 1) * (1 - e^(-(eta + 1) d)) + e^(-(eta + 1) d)
     *
     * The up branch has a removable singularity at eta = 1, where the integral collapses to c; it is taken
     * as the limit rather than allowed to divide by zero.
     *
     * @param float $pUp     Probability the jump is upwards.
     * @param float $etaUp   Exponential rate of the up jump.
     * @param float $etaDown Exponential rate of the down jump.
     * @param float $capUp   Largest up jump, as a positive log return.
     * @param float $capDown Largest down jump, as a POSITIVE magnitude.
     */
    public static function kouTruncatedCompensator(
        float $pUp,
        float $etaUp,
        float $etaDown,
        float $capUp,
        float $capDown
    ): float {
        $upRate = $etaUp - 1.0;
        if (abs($upRate) < self::COMPENSATOR_UNIT_RATE_TOLERANCE) {
            // eta = 1: the integrand is constant and the expectation is 1 + c.
            $upExpectation = 1.0 + $capUp;
        } else {
            $upTail = exp(-$upRate * $capUp);
            $upExpectation = (($etaUp / $upRate) * (1.0 - $upTail)) + $upTail;
        }

        $downRate = $etaDown + 1.0;
        $downTail = exp(-$downRate * $capDown);
        $downExpectation = (($etaDown / $downRate) * (1.0 - $downTail)) + $downTail;

        return ($pUp * $upExpectation) + ((1.0 - $pUp) * $downExpectation) - 1.0;
    }

    /**
     * E[J] of a truncated Kou jump (each tail capped where the draw is clamped). With kouTruncatedCompensator, E[e^J - 1],
     * it gives a jump's log loss under Merton compensation: E[e^J - 1 - J].
     */
    public static function kouTruncatedMean(
        float $pUp,
        float $etaUp,
        float $etaDown,
        float $capUp,
        float $capDown
    ): float {
        return ($pUp * self::truncatedExponentialMean($etaUp, $capUp))
            - ((1.0 - $pUp) * self::truncatedExponentialMean($etaDown, $capDown));
    }

    /** E[min(X, cap)] for X ~ Exp(rate). */
    private static function truncatedExponentialMean(float $rate, float $cap): float
    {
        if ($rate <= 0.0 || $cap <= 0.0) {
            return 0.0;
        }

        return (1.0 - exp(-$rate * $cap)) / $rate;
    }

    /**
     * E[min(X, c)^2] for X ~ Exp(rate). See kouTruncatedSecondMoment() for the derivation.
     */
    private static function truncatedExponentialSecondMoment(float $rate, float $cap): float
    {
        if ($rate <= 0.0 || $cap <= 0.0) {
            return 0.0;
        }

        $scaled = $rate * $cap;

        return (2.0 / ($rate * $rate)) * (1.0 - (exp(-$scaled) * ($scaled + 1.0)));
    }

    /**
     * Expected size of one calculateSVJJJumps() variance jump: mean muV on a down jump and
     * VARIANCE_JUMP_UPSIDE_MEAN_SHARE x muV on an up jump, each an exponential capped at
     * MAX_VARIANCE_JUMP_MEAN_MULTIPLE of its mean, so E[min(X, K m)] = m (1 - e^-K). Intensity times this,
     * over the reversion speed, is what the jumps add to the stationary variance (Duffie, Pan & Singleton 2000).
     *
     * @param float $pUp Probability a price jump is upward.
     * @param float $muV Mean variance jump on a down jump.
     */
    public static function meanVarianceJump(float $pUp, float $muV): float
    {
        $truncation = 1.0 - exp(-self::MAX_VARIANCE_JUMP_MEAN_MULTIPLE);

        return max(0.0, $muV) * $truncation * (($pUp * self::VARIANCE_JUMP_UPSIDE_MEAN_SHARE) + (1.0 - $pUp));
    }

    /**
     * The expected capped Kou jump over dt, the part calculateCompensatedKouJump subtracts: a caller that has to
     * account for the jump and its compensation separately adds it back to recover the jump drawn.
     *
     * @param float $lambda  Jump arrivals per year.
     * @param float $pUp     Probability that a jump is upwards.
     * @param float $etaUp   Exponential rate of the up jump (mean size 1/etaUp).
     * @param float $etaDown Exponential rate of the down jump (mean size 1/etaDown).
     * @param float $cap     Absolute cap on a single jump, in the units of the process.
     * @param float $dt      Time step in years.
     * @return float Expected jump over dt, signed.
     */
    public static function calculateKouCompensator(
        float $lambda,
        float $pUp,
        float $etaUp,
        float $etaDown,
        float $cap,
        float $dt
    ): float {
        if ($lambda <= 0.0 || $etaUp <= 0.0 || $etaDown <= 0.0 || $cap <= 0.0 || $dt <= 0.0) {
            return 0.0;
        }

        $cappedUpMean = (1.0 - exp(-$etaUp * $cap)) / $etaUp;
        $cappedDownMean = (1.0 - exp(-$etaDown * $cap)) / $etaDown;

        return $lambda * (($pUp * $cappedUpMean) - ((1.0 - $pUp) * $cappedDownMean)) * $dt;
    }

    /**
     * Averages a mean-reverting volatility over a horizon, giving the single volatility a T-year model
     * should be struck on.
     *
     * Spot volatility answers "how much is this moving today"; a T-year default probability asks "how much
     * will this move between now and T", which under a mean-reverting variance process is the expected
     * integrated variance, E[(1/T) * int_0^T v_t dt]. For the Heston / GARCH family that expectation is
     * closed form:
     *
     *     sigmaBar^2(T) = theta + (v0 - theta) * (1 - e^(-kappa*T)) / (kappa*T)
     *
     * which returns spot variance as T -> 0 and the long-run level as T -> infinity. Feeding raw spot
     * volatility into a five-year horizon instead asserts that today's shock persists undiminished for five
     * years, and since the Merton d2 carries a -sigma*sqrt(T) term that assertion alone can consume the whole
     * distance to default on a firm whose balance sheet never moved.
     *
     * @param float $spotVolatility     Today's annualized volatility (sqrt of v0).
     * @param float $longRunVolatility  The structural volatility the process reverts to (sqrt of theta).
     * @param float $reversionSpeed     Mean-reversion speed kappa, in reversions per year.
     * @param float $horizonYears       The horizon T being modelled, in years.
     * @return float The annualized volatility to use over the horizon.
     */
    public static function averageMeanRevertingVolatility(
        float $spotVolatility,
        float $longRunVolatility,
        float $reversionSpeed,
        float $horizonYears
    ): float {
        $spotVariance = $spotVolatility ** 2.0;
        $longRunVariance = $longRunVolatility ** 2.0;

        if ($reversionSpeed <= 0.0 || $horizonYears <= 0.0) {
            return sqrt(max(0.0, $spotVariance));
        }

        $decay = $reversionSpeed * $horizonYears;
        $weight = (1.0 - exp(-$decay)) / $decay;

        return sqrt(max(0.0, $longRunVariance + (($spotVariance - $longRunVariance) * $weight)));
    }

    /**
     * Calculates a step in the Schwartz 1-Factor Model (1997) for commodity pricing.
     * Uses an Ornstein-Uhlenbeck (OU) process on the natural logarithm of the price,
     * ensuring strictly positive, right-skewed log-normal distributions.
     *
     * Schwartz's own parameterisation, theta = e^mu with alpha = mu - sigma^2 / 2 kappa: the stationary mean of S is
     * theta x e^(-sigma^2 / 4 kappa), not theta. A caller whose theta is the level S should average passes
     * schwartzThetaForMean() instead.
     *
     * @param float $currentPrice The current commodity price (S).
     * @param float $kappa        The speed of mean reversion.
     * @param float $theta        Schwartz's e^mu (see above), not the stationary mean.
     * @param float $sigma        The volatility of the log price.
     * @param float $dt           The time step delta.
     * @param float $dW           The Brownian motion Z-score.
     * @return float The next commodity price.
     */
    public static function calculateSchwartz1Factor(float $currentPrice, float $kappa, float $theta, float $sigma, float $dt, float $dW): float
    {
        $currentPrice = max(0.0001, $currentPrice);
        $currentLogPrice = log($currentPrice);

        // Schwartz (1997) eq. 3: alpha = mu - sigma^2 / 2 kappa, the drift of ln S under dS = kappa (mu - ln S) S dt + sigma S dz.
        $alpha = log($theta) - ($sigma * $sigma) / (2.0 * max(0.0001, $kappa));

        // Exact solution for OU process to prevent Euler discretization errors for large kappa * dt
        $expKappaDt = exp(-$kappa * $dt);
        $drift = $currentLogPrice * $expKappaDt + $alpha * (1.0 - $expKappaDt);

        // The exact variance of the OU process over dt
        $variance = ($sigma * $sigma / (2.0 * max(0.0001, $kappa))) * (1.0 - exp(-2.0 * $kappa * $dt));
        $diffusion = sqrt($variance) * $dW;

        $nextLogPrice = $drift + $diffusion;

        return exp($nextLogPrice);
    }

    /**
     * The theta calculateSchwartz1Factor() needs for its stationary mean to be `$mean`: the log-OU's stationary log
     * variance is sigma^2 / 2 kappa, so E[S] = theta x e^(-sigma^2 / 4 kappa) and theta is the mean lifted by the inverse.
     *
     * @param float $mean  Level the price is to average.
     * @param float $kappa Speed of mean reversion of the log price.
     * @param float $sigma Volatility of the log price.
     */
    public static function schwartzThetaForMean(float $mean, float $kappa, float $sigma): float
    {
        return $mean * exp(($sigma * $sigma) / (4.0 * max(0.0001, $kappa)));
    }

    /**
     * The theta calculateSchwartz1Factor() needs for its stationary LOG mean to be ln(`$level`): Schwartz's alpha
     * subtracts sigma^2 / 2 kappa, so theta is lifted by the inverse. For processes whose readers take log(S / level).
     *
     * @param float $level Level whose log the price is to average.
     * @param float $kappa Speed of mean reversion of the log price.
     * @param float $sigma Volatility of the log price.
     */
    public static function schwartzThetaForLogMean(float $level, float $kappa, float $sigma): float
    {
        return $level * exp(($sigma * $sigma) / (2.0 * max(0.0001, $kappa)));
    }

    /**
     * How much compound-Poisson log jumps lift the stationary mean LEVEL of a log-OU, as a log shift: the OU driven by a
     * Levy process has stationary cumulant K(u) = integral_0^inf psi(u e^(-kappa s)) ds (Barndorff-Nielsen & Shephard
     * 2001), which for Merton (1976) normal jumps at u = 1 is (lambda / kappa) integral_0^1 (E[e^(wJ)] - 1) / w dw.
     * Dividing a target by e^(this) puts the jump-diffusion's mean level back on it.
     *
     * @param float $lambda   Jump arrivals per year.
     * @param float $kappa    Speed of mean reversion of the log price.
     * @param float $jumpMean Mean log jump.
     * @param float $jumpVol  Standard deviation of the log jump.
     */
    public static function logOuJumpLevelShift(float $lambda, float $kappa, float $jumpMean, float $jumpVol): float
    {
        // (E[e^(wJ)] - 1) / w is smooth on [0, 1] and tends to the mean jump at w = 0; composite Simpson integrates it.
        $integrand = static fn (float $w): float => $w > 0.0
            ? (exp(($jumpMean * $w) + (0.5 * $jumpVol * $jumpVol * $w * $w)) - 1.0) / $w
            : $jumpMean;
        $intervals = self::LEVY_OU_COMPENSATOR_SIMPSON_INTERVALS;
        $h = 1.0 / $intervals;
        $sum = $integrand(0.0) + $integrand(1.0);
        for ($i = 1; $i < $intervals; $i++) {
            $sum += ($i % 2 === 1 ? 4.0 : 2.0) * $integrand($i * $h);
        }

        return ($lambda / max(0.0001, $kappa)) * ($sum * $h / 3.0);
    }

    /**
     * One exact step of a zero-mean Ornstein-Uhlenbeck factor given its stationary standard deviation: the decay and
     * the conditional variance are those of the continuous process over dt, so the factor's distribution does not
     * depend on the tick length.
     *
     *   x' = x e^(-kappa dt) + s sqrt(1 - e^(-2 kappa dt)) dW
     *
     * @param float $current      The factor now.
     * @param float $kappa        Mean reversion per year.
     * @param float $stationarySd Standard deviation of the factor's stationary distribution.
     * @param float $dt           Time step in years.
     * @param float $dW           Standard normal draw.
     */
    public static function calculateOrnsteinUhlenbeckStep(float $current, float $kappa, float $stationarySd, float $dt, float $dW): float
    {
        $decay = exp(-max(0.0, $kappa) * $dt);

        return ($current * $decay) + ($stationarySd * sqrt(max(0.0, 1.0 - ($decay * $decay))) * $dW);
    }

    /**
     * Schwartz (1997) one-factor futures price with a zero market price of risk: the expected spot at the
     * delivery horizon under the same exact log-OU transition calculateSchwartz1Factor() steps, so a swap
     * struck on this curve is fair against the process the spot actually follows.
     *
     *   ln F(T) = e^(-kT) ln S + (1 - e^(-kT)) alpha + sigma^2 / (4k) (1 - e^(-2kT)),  alpha = ln theta - sigma^2 / 2k
     *
     * @param float $spot         Current spot price (S).
     * @param float $kappa        Speed of mean reversion.
     * @param float $theta        Long-term equilibrium price level.
     * @param float $sigma        Volatility of the log price.
     * @param float $horizonYears Time to delivery in years.
     */
    public static function calculateSchwartzForwardPrice(float $spot, float $kappa, float $theta, float $sigma, float $horizonYears): float
    {
        $kappa = max(0.0001, $kappa);
        $horizonYears = max(0.0, $horizonYears);
        $alpha = log(max(0.0001, $theta)) - ($sigma * $sigma) / (2.0 * $kappa);
        $decay = exp(-$kappa * $horizonYears);

        $logForward = ($decay * log(max(0.0001, $spot)))
            + ((1.0 - $decay) * $alpha)
            + (($sigma * $sigma) / (4.0 * $kappa)) * (1.0 - exp(-2.0 * $kappa * $horizonYears));

        return exp($logForward);
    }

    /**
     * Calculates the non-linear convenience yield under the Theory of Storage (Working 1949, Litzenberger & Rabinowitz 1995).
     *
     * When physical inventory levels are ample (above baseline), the market is in contango and convenience yield is near zero.
     * When physical inventories drop toward a critical buffer threshold, convenience yield spikes asymptotically,
     * inducing extreme spot price inelasticity and backwardation.
     *
     * @param float $inventoryLevel Normalized inventory index (100 = neutral, < 80 = tight, < 55 = critical).
     * @param float $minBufferStock Critical structural minimum buffer stock floor.
     * @param float $yieldScale     Scaling multiplier.
     * @param float $exponent       Asymptotic curvature exponent.
     * @return float Annualized convenience yield percentage add-on.
     */
    public static function calculateConvenienceYield(
        float $inventoryLevel,
        float $minBufferStock = 50.0,
        float $yieldScale = 0.10,
        float $exponent = 1.8
    ): float {
        $bufferSlack = max(1.0, $inventoryLevel - $minBufferStock);
        $neutralSlack = max(1.0, 100.0 - $minBufferStock);

        if ($inventoryLevel >= 100.0) {
            // Contango regime: convenience yield negligible
            return 0.0;
        }

        // Backwardation regime: convenience yield rises non-linearly as inventories deplete
        $tightnessRatio = $neutralSlack / $bufferSlack;
        return $yieldScale * (pow($tightnessRatio, $exponent) - 1.0);
    }
}
