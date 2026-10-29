<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Filters and lags on simulated series: EWMA levels and variances, persistence-equivalent rates, fades toward
 * trend, distributed and speed-limited lags, AR(2) autocorrelation, the one-sided HP filter, seasonally adjusted
 * annual rates, reversion pulls, and calendar-boundary crossings in simulated time. Pure functions of their
 * arguments.
 */
final class TimeSeries
{
    // --- Operating Physics ---
    /** Speed of competitive return erosion toward cost of capital for high-ROIC firms. */
    public const REVERSION_COMPETITIVE_EROSION_ALPHA = 0.50;
    /** Autoregressive persistence parameter maintaining margin drag during financial distress. */
    public const REVERSION_DISTRESS_PERSISTENCE = 0.60;
    /** Non-linear distress acceleration exponent penalizing sub-par economic returns. */
    public const REVERSION_DISTRESS_GAMMA = 1.00;

    // --- Exponential Smoothing of a Known Signal ---
    /**
     * Time constants of the past an exponential average is integrated over: the kernel's tail beyond is e^-8, 0.03%.
     */
    public const SMOOTHING_KERNEL_SPAN = 8.0;
    /** Midpoint-rule steps across that span: the error on a seasonal cosine smoothed over a quarter is under 1e-6. */
    public const SMOOTHING_KERNEL_STEPS = 256;

    // --- Profitability Persistence (Fama & French 2000; Ohlson 1995) ---
    /**
     * Share of a firm's gap between its return on capital and its own long-run level that closes each year: about 38%
     * (Fama & French 2000, J. Business, partial-adjustment model).
     */
    public const PROFITABILITY_MEAN_REVERSION_RATE = 0.38;

    /**
     * One step of an exponentially weighted estimate of annualized variance (RiskMetrics 1996):
     * sigma^2_t = phi sigma^2_{t-1} + (1 - phi) r_t^2 / dt, phi = exp(-dt / tau), with the memory set in
     * simulated years so the same window means the same thing at any tick rate.
     *
     * @param float $prior     Last estimate, annualized.
     * @param float $logReturn The return observed over this step.
     * @param float $dt        Step length in years.
     * @param float $tauYears  Memory of the average in years.
     */
    public static function ewmaAnnualizedVariance(float $prior, float $logReturn, float $dt, float $tauYears): float
    {
        if ($dt <= 0.0 || $tauYears <= 0.0) {
            return $prior;
        }

        $phi = exp(-$dt / $tauYears);

        return (max(0.0, $prior) * $phi) + ((1.0 - $phi) * (($logReturn * $logReturn) / $dt));
    }

    /**
     * The same estimate fed on returns summed over a window rather than step by step. Flow that leans the same way tick
     * after tick shapes a day's move, but each tick's share of it is proportional to dt, so its square over dt shrinks
     * with the tick and a per-step reading of such a flow vanishes as ticks get finer. Summed over the window, it reads
     * the same at any tick rate; returns that do not persist read as they did.
     *
     * @param array{sum: float, elapsed: float} $window      The window so far: its summed return and its length.
     * @param float                             $windowYears Length of a window in years.
     * @return array{variance: float, window: array{sum: float, elapsed: float}} The estimate and the window carried on.
     */
    public static function ewmaWindowedVariance(float $prior, array $window, float $logReturn, float $dt, float $windowYears, float $tauYears): array
    {
        $window = ['sum' => $window['sum'] + $logReturn, 'elapsed' => $window['elapsed'] + max(0.0, $dt)];
        if ($window['elapsed'] < $windowYears * (1.0 - 1e-9)) {
            return ['variance' => $prior, 'window' => $window];
        }

        return [
            'variance' => self::ewmaAnnualizedVariance($prior, $window['sum'], $window['elapsed'], $tauYears),
            'window' => ['sum' => 0.0, 'elapsed' => 0.0],
        ];
    }

    /**
     * A known signal as an exponential moving average of it reads at a time: the signal integrated over its past
     * against the average's kernel (1/tau) e^(-s/tau), so a deterministic season can be read as a smoothed index
     * shows it, damped and late.
     *
     * @param callable(float): float $signal       The signal at a time (years).
     * @param float                  $time         When the average is read (years).
     * @param float                  $horizonYears The average's time constant (years); zero returns the signal itself.
     */
    public static function exponentiallySmoothedSignal(callable $signal, float $time, float $horizonYears): float
    {
        if ($horizonYears <= 0.0) {
            return $signal($time);
        }

        $step = self::SMOOTHING_KERNEL_SPAN * $horizonYears / self::SMOOTHING_KERNEL_STEPS;
        $sum = 0.0;
        $weight = 0.0;
        for ($i = 0; $i < self::SMOOTHING_KERNEL_STEPS; $i++) {
            $lag = ($i + 0.5) * $step;
            $kernel = exp(-$lag / $horizonYears);
            $sum += $kernel * $signal($time - $lag);
            $weight += $kernel;
        }

        return $sum / $weight;
    }

    /**
     * An exponentially weighted level: calculateDistributedLag() from the prior toward the observation, and a missing
     * prior starts at the observation.
     */
    public static function ewmaLevel(?float $prior, float $observation, float $dt, float $tauYears): float
    {
        return $prior === null ? $observation : self::calculateDistributedLag($prior, $observation, $dt, $tauYears);
    }

    /**
     * The constant return worth the same as a trailing return that fades back to its long-run level. In Ohlson's (1995)
     * residual income model an abnormal return with persistence omega is worth omega/(1+r-omega) of itself and a
     * permanent one 1/r of itself, so the permanent equivalent keeps r*omega/(1+r-omega) of the gap. Omega is the share
     * of the gap left after a year: by default one less the 38% a year profitability closes (Fama & French 2000).
     */
    public static function persistentEquivalentReturn(float $trailingReturn, float $longRunReturn, float $discountRate, float $persistence = 1.0 - self::PROFITABILITY_MEAN_REVERSION_RATE): float
    {
        $persistence = max(0.0, min(1.0, $persistence));
        $rate = max(0.0, $discountRate);

        return $longRunReturn + (($trailingReturn - $longRunReturn) * $rate * $persistence / (1.0 + $rate - $persistence));
    }

    /**
     * Whether this tick crossed a boundary of the given period in SIMULATED time.
     *
     * The ticker's retention job and the macro's calendar (a fund's month-end check, a budget year) all ask
     * the clock the same question, and a naive `floor(t) > floor(t - dt)` gets it wrong at high tick rates.
     *
     * @param float $totalTime   Simulated time after this tick, in years.
     * @param float $dt          The tick's length in years.
     * @param float $periodYears The period whose boundaries are counted, in years.
     */
    public static function crossedSimulatedBoundary(float $totalTime, float $dt, float $periodYears): bool
    {
        return self::boundariesCrossed($totalTime, $dt, $periodYears) > 0;
    }

    /**
     * How many period boundaries the step that ended at `$totalTime` crossed, counted as crossedSimulatedBoundary()
     * tests for one: more than one when a step spans several periods.
     */
    public static function boundariesCrossed(float $totalTime, float $dt, float $periodYears): int
    {
        if ($periodYears <= 0.0 || $dt <= 0.0) {
            return 0;
        }

        // Half a tick, expressed in periods, applied to BOTH samples and to the floor above zero.
        //
        // Neither end of the comparison is exact. The previous sample is reconstructed as `$totalTime - $dt`
        // rather than remembered, and that subtraction does not land back on the value the last tick held:
        // at 14,400 ticks a year the tick after the second year reconstructs its predecessor as
        // 1.99999999999999978, one ulp below a boundary it had already crossed, and the period fires twice.
        // The current sample is no better, because the loop ACCUMULATES it: 252 additions of 1/252 reach
        // 0.99999999999999989, so a plain `< $periodYears` guard rejects the first year outright and loses
        // it. Both samples are supposed to be tick multiples, so they are snapped to the nearest one; a
        // discrepancy smaller than half a tick is the float representation, not elapsed time.
        $epsilon = $dt / $periodYears / 2.0;
        $index = (int) floor($totalTime / $periodYears + $epsilon);
        $previous = (int) floor(max(0.0, $totalTime - $dt) / $periodYears + $epsilon);

        // Index zero is the period the simulation starts inside, which is entered rather than crossed.
        return $index >= 1 ? max(0, $index - $previous) : 0;
    }

    /**
     * Secular growth faded toward the economy's trend: the excess over trend at the open decays at a half-life,
     * because above-trend growth does not persist (Chan, Karceski & Lakonishok 2003), and a drift held forever
     * compounds a sector's share without bound.
     */
    public static function fadeTowardTrend(float $openingGrowth, float $trendGrowth, float $simYears, float $halfLifeYears): float
    {
        return $trendGrowth + (($openingGrowth - $trendGrowth) * exp(-M_LN2 * max(0.0, $simYears) / $halfLifeYears));
    }

    /**
     * The constant growth rate worth the same as a growth rate whose excess over the long-run rate fades at the secular
     * half-life: the H-model of Fuller & Hsia (1984) with an exponential fade. A dividend growing at g_L plus an excess
     * e decaying at lambda is worth D/(r-g_L) x (1 + e/(r-g_L+lambda)) to first order, so the perpetual equivalent
     * keeps (r-g_L)/(r-g_L+lambda) of the excess. Rates are real: the real discount rate against real trend growth.
     *
     * @param float|null $fadeSpeed The excess's fade a year, lambda; the secular half-life's when null.
     */
    public static function persistentEquivalentGrowth(float $growth, float $longRunGrowth, float $realDiscountRate, ?float $fadeSpeed = null): float
    {
        $fade = $fadeSpeed ?? (M_LN2 / FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS);
        $spread = max(FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD, $realDiscountRate - $longRunGrowth);

        return $longRunGrowth + (($growth - $longRunGrowth) * $spread / ($spread + $fade));
    }

    /**
     * The excess growth a fading sector accumulates between two times: the integral of the faded excess, so a
     * level that compounds it (an industry's demand over trend GDP) follows the same fade as the rate.
     */
    public static function fadedExcessIntegral(float $openingExcess, float $fromYears, float $toYears, float $halfLifeYears): float
    {
        $decay = M_LN2 / $halfLifeYears;

        return $openingExcess * (exp(-$decay * max(0.0, $fromYears)) - exp(-$decay * max(0.0, $toYears))) / $decay;
    }

    /**
     * A revenue mix whose lines drift against their industry at fading rates: each line's opening weight compounds its
     * own faded drift (fadedExcessIntegral()) and the shares are taken of the sum. A line with no drift keeps its
     * weight, one with no weight stays at none, and the mix settles where the drifts have faded out.
     *
     * @param array<string, float> $openingWeights
     * @param array<string, float> $lineDrifts     Annual drift of a line against its industry; a line left out has
     *                                             none.
     * @return array<string, float>
     */
    public static function driftedLineShares(array $openingWeights, array $lineDrifts, float $years, float $halfLifeYears): array
    {
        $levels = [];
        foreach ($openingWeights as $line => $weight) {
            $levels[$line] = max(0.0, $weight) * exp(self::fadedExcessIntegral($lineDrifts[$line] ?? 0.0, 0.0, $years, $halfLifeYears));
        }
        $total = array_sum($levels);

        return $total > 0.0 ? array_map(static fn(float $level): float => $level / $total, $levels) : $levels;
    }

    /**
     * What a firm grows at beyond its industry for its revenue mix: each line's drift on how far the firm's opening
     * share of it leans from the industry's typical share. The industry's measured growth already holds the typical
     * mix's drifts, so a firm with that mix grows at the industry's rate and nothing is counted twice.
     *
     * @param array<string, float> $firmWeights
     * @param array<string, float> $typicalWeights
     * @param array<string, float> $lineDrifts     Annual drift of a line against its industry; a line left out has
     *                                             none.
     */
    public static function lineMixExcessGrowth(array $firmWeights, array $typicalWeights, array $lineDrifts): float
    {
        $firmTotal = array_sum(array_map(static fn(float $weight): float => max(0.0, $weight), $firmWeights));
        $typicalTotal = array_sum(array_map(static fn(float $weight): float => max(0.0, $weight), $typicalWeights));
        if ($firmTotal <= 0.0 || $typicalTotal <= 0.0) {
            return 0.0;
        }

        $excess = 0.0;
        foreach ($lineDrifts as $line => $drift) {
            $excess += ((max(0.0, $firmWeights[$line] ?? 0.0) / $firmTotal) - (max(0.0, $typicalWeights[$line] ?? 0.0) / $typicalTotal)) * $drift;
        }

        return $excess;
    }

    /**
     * Autocorrelation of a stationary AR(2) at a lag, from the Yule-Walker equations (Box & Jenkins 1970):
     * rho_1 = a1 / (1 - a2), then rho_k = a1 rho_{k-1} + a2 rho_{k-2}.
     *
     * @param float $a1  First autoregressive coefficient.
     * @param float $a2  Second autoregressive coefficient.
     * @param int   $lag Lag in periods of the process.
     * @return float Autocorrelation at that lag.
     */
    public static function calculateAr2Autocorrelation(float $a1, float $a2, int $lag): float
    {
        $previous = 1.0;
        $current = $a1 / (1.0 - $a2);
        if ($lag <= 0) {
            return 1.0;
        }
        for ($k = 2; $k <= $lag; $k++) {
            [$previous, $current] = [$current, ($a1 * $current) + ($a2 * $previous)];
        }

        return $current;
    }

    /** Share of a gap a first-order lag closes over dt: 1 - e^(-dt/tau); none at dt <= 0, all at tau <= 0. */
    public static function smoothingWeight(float $dt, float $tauYears): float
    {
        if ($dt <= 0.0) {
            return 0.0;
        }
        if ($tauYears <= 0.0) {
            return 1.0;
        }

        return 1.0 - exp(-$dt / $tauYears);
    }

    /**
     * Calculates an exponential distributed lag step (discrete recursive lag filter).
     * Models delayed transmission and economic stickiness (e.g., cost-push pass-through).
     *
     * @param float $currentLaggedValue The current lagged state variable.
     * @param float $targetValue        The new driving target value.
     * @param float $dt                 The time step in years; with none passed the value stays.
     * @param float $lagTimeConstant    The characteristic adjustment time constant in years; at zero the value jumps.
     * @return float The updated lagged value.
     */
    public static function calculateDistributedLag(
        float $currentLaggedValue,
        float $targetValue,
        float $dt,
        float $lagTimeConstant,
    ): float {
        if ($dt <= 0.0) {
            return $currentLaggedValue;
        }
        if ($lagTimeConstant <= 0.0) {
            return $targetValue;
        }

        return $currentLaggedValue + self::smoothingWeight($dt, $lagTimeConstant) * ($targetValue - $currentLaggedValue);
    }

    /**
     * The share of a gap a partial adjustment closes over dt years when it closes the annual share of it in a year:
     * 1 - (1 - annual)^dt, so a speed estimated on annual data (Lintner payout, Flannery-Rangan leverage, ABJ cost
     * stickiness) compounds to the same yearly response at any step.
     */
    public static function periodAdjustmentSpeed(float $annualSpeed, float $dt): float
    {
        return 1.0 - ((1.0 - max(0.0, min(1.0, $annualSpeed))) ** $dt);
    }

    public static function calculateReversionPull(
        float $currentReturn,
        float $wacc,
        float $baseKappa,
        float $moatSpread,
        float $dt = 0.25,
        float $erosionAlpha = self::REVERSION_COMPETITIVE_EROSION_ALPHA,
        float $distressPersistence = self::REVERSION_DISTRESS_PERSISTENCE,
        float $distressGamma = self::REVERSION_DISTRESS_GAMMA,
    ): float {
        $equilibrium = $wacc + $moatSpread;

        $safeEquilibriumDivisor = max(0.01, abs($equilibrium));

        if ($currentReturn > $equilibrium) {
            $excessRatio = ($currentReturn - $equilibrium) / $safeEquilibriumDivisor;
            $effectiveKappa = $baseKappa * (1.0 + $erosionAlpha * $excessRatio);
        } elseif ($currentReturn < 0.0) {
            $distressRatio = abs($currentReturn) / max(0.01, $wacc);
            $effectiveKappa = $baseKappa * $distressPersistence * (1.0 + $distressGamma * $distressRatio);
        } else {
            $effectiveKappa = $baseKappa * $distressPersistence;
        }

        // EXACT DISCRETIZATION: Use the exponential solution (1 - e^(-kappa * dt))
        // This acts as a dampener. Even if effectiveKappa approaches infinity,
        // the weight naturally caps at 1.0, mathematically preventing target overshooting.
        $reversionWeight = 1.0 - exp(-$effectiveKappa * $dt);

        return ($equilibrium - $currentReturn) * $reversionWeight;
    }

    /**
     * One quarterly step of the one-sided Hodrick-Prescott trend in its state-space form (Harvey & Jaeger 1993).
     *
     * The HP trend is the filtered level of a local linear trend, y = tau + eps, tau' = tau + beta, beta' = beta + eta,
     * with var(eta) / var(eps) = 1 / lambda. The one-sided (real-time) trend is the Kalman filter's estimate, and at
     * the steady-state gains of a given lambda it is a two-line recursion: predict the level along its slope, then
     * correct both by the gains times the surprise.
     *
     * @param float $trendLevel  Trend level after the previous observation.
     * @param float $trendSlope  Trend slope per observation after the previous observation.
     * @param float $observation This period's observation.
     * @param float $levelGain   Steady-state Kalman gain on the level.
     * @param float $slopeGain   Steady-state Kalman gain on the slope.
     * @return array{level: float, slope: float} The updated trend level and slope.
     */
    public static function calculateOneSidedHpStep(float $trendLevel, float $trendSlope, float $observation, float $levelGain, float $slopeGain): array
    {
        $predictedLevel = $trendLevel + $trendSlope;
        $surprise = $observation - $predictedLevel;

        return [
            'level' => $predictedLevel + ($levelGain * $surprise),
            'slope' => $trendSlope + ($slopeGain * $surprise),
        ];
    }

    /**
     * Converts a single reporting period into a seasonally adjusted annual rate (SAAR).
     *
     * Standard BEA/BLS seasonal adjustment: the period value is divided by its seasonal
     * factor to recover the underlying run-rate, then scaled to an annual basis. Exact
     * when the seasonal factors are known a priori rather than estimated.
     *
     * @param float $periodValue    The single-period value (e.g., quarterly revenue).
     * @param float $seasonalFactor The period's empirical seasonal factor (e.g., 0.85).
     * @param int   $periodsPerYear The number of reporting periods per year (default 4 for quarterly).
     * @return float The seasonally adjusted annual rate (SAAR).
     */
    public static function calculateSeasonallyAdjustedAnnualRate(
        float $periodValue,
        float $seasonalFactor,
        int $periodsPerYear = 4,
    ): float {
        return ($periodValue / max(0.01, $seasonalFactor)) * $periodsPerYear;
    }

    /**
     * One step of a local-level filter (Muth 1960; Kalman 1960): a level that walks at random, read through noise. The
     * belief's variance grows by the walk's over the step, then the reading is weighed against it by their precisions.
     * A walk with no variance and a belief already certain stay put.
     *
     * @return array{float, float} The updated mean and variance.
     */
    public static function localLevelStep(float $mean, float $variance, float $reading, float $stepVariance, float $readingVariance): array
    {
        $prior = max(0.0, $variance) + max(0.0, $stepVariance);
        if ($prior <= 0.0) {
            return [$mean, 0.0];
        }
        $gain = $prior / ($prior + max(0.0, $readingVariance));

        return [$mean + ($gain * ($reading - $mean)), (1.0 - $gain) * $prior];
    }

    /**
     * The reading noise that settles a local-level filter at a given gain: in steady state the prior variance is q/K,
     * and K = (q/K) / (q/K + R) gives R = q (1 - K) / K^2.
     *
     * @param float $stepVariance The walk's variance over one step (q).
     * @param float $steadyGain   The share of a reading's surprise the settled belief takes in (K, 0 to 1).
     */
    public static function localLevelReadingVariance(float $stepVariance, float $steadyGain): float
    {
        $gain = max(1e-9, min(1.0, $steadyGain));

        return max(0.0, $stepVariance) * (1.0 - $gain) / ($gain * $gain);
    }

    /** The settled belief's variance after a reading at that gain: (1 - K) q / K. */
    public static function localLevelSteadyVariance(float $stepVariance, float $steadyGain): float
    {
        $gain = max(1e-9, min(1.0, $steadyGain));

        return (1.0 - $gain) * max(0.0, $stepVariance) / $gain;
    }
}
