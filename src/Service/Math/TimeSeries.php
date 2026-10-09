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
     * An exponentially weighted level: the prior decays by exp(-dt/tau) toward the observation, and a missing prior
     * starts at the observation.
     */
    public static function ewmaLevel(?float $prior, float $observation, float $dt, float $tauYears): float
    {
        if ($prior === null) {
            return $observation;
        }
        if ($dt <= 0.0 || $tauYears <= 0.0) {
            return $prior;
        }

        $phi = exp(-$dt / $tauYears);

        return ($prior * $phi) + ((1.0 - $phi) * $observation);
    }

    /**
     * The constant return worth the same as a trailing return that fades back to its long-run level. In Ohlson's (1995)
     * residual income model an abnormal return with persistence omega is worth omega/(1+r-omega) of itself and a
     * permanent one 1/r of itself, so the permanent equivalent keeps r*omega/(1+r-omega) of the gap. Omega is one less
     * the 38% a year profitability closes its gap (Fama & French 2000).
     */
    public static function persistentEquivalentReturn(float $trailingReturn, float $longRunReturn, float $discountRate): float
    {
        $persistence = 1.0 - FinancialConstants::PROFITABILITY_MEAN_REVERSION_RATE;
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
        if ($periodYears <= 0.0 || $dt <= 0.0) {
            return false;
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
        return $index >= 1 && $index > $previous;
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
     * half-life: the H-model of Fuller & Hsia (1984) with an exponential fade. A dividend growing at g_L plus an excess e
     * decaying at lambda is worth D/(r-g_L) x (1 + e/(r-g_L+lambda)) to first order, so the perpetual equivalent keeps
     * (r-g_L)/(r-g_L+lambda) of the excess. Rates are real: the real discount rate against real trend growth.
     */
    public static function persistentEquivalentGrowth(float $growth, float $longRunGrowth, float $realDiscountRate): float
    {
        $fade = M_LN2 / FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS;
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

    /**
     * Calculates an exponential distributed lag step (discrete recursive lag filter).
     * Models delayed transmission and economic stickiness (e.g., cost-push pass-through).
     *
     * @param float $currentLaggedValue The current lagged state variable.
     * @param float $targetValue        The new driving target value.
     * @param float $dt                 The time step in years.
     * @param float $lagTimeConstant    The characteristic adjustment time constant in years.
     * @return float The updated lagged value.
     */
    public static function calculateDistributedLag(
        float $currentLaggedValue,
        float $targetValue,
        float $dt,
        float $lagTimeConstant
    ): float {
        if ($lagTimeConstant <= 0.0 || $dt <= 0.0) {
            return $targetValue;
        }

        $weight = 1.0 - exp(-$dt / $lagTimeConstant);
        return $currentLaggedValue + $weight * ($targetValue - $currentLaggedValue);
    }

    /**
     * Exponential distributed lag whose speed is capped (a slew-rate-limited first-order lag).
     *
     * Exact step of dx/dt = sign(d) * min(|d| / tau, maxSpeed), d = target - x: the value moves at the ceiling
     * until the distance falls to maxSpeed * tau, where the lag's own speed equals it, and follows the plain lag
     * from there. Being exact, one step of dt equals any split of it for a fixed target.
     *
     * @param float $currentLaggedValue The current lagged state variable.
     * @param float $targetValue        The driving target value.
     * @param float $dt                 The time step in years.
     * @param float $lagTimeConstant    The adjustment time constant in years.
     * @param float $maxSpeed           Most the value moves per year (INF for none).
     * @return float The updated lagged value.
     */
    public static function calculateSpeedLimitedDistributedLag(
        float $currentLaggedValue,
        float $targetValue,
        float $dt,
        float $lagTimeConstant,
        float $maxSpeed
    ): float {
        $distance = abs($targetValue - $currentLaggedValue);
        $knee = $lagTimeConstant > 0.0 ? $maxSpeed * $lagTimeConstant : 0.0;
        if ($distance <= $knee || $dt <= 0.0) {
            return self::calculateDistributedLag($currentLaggedValue, $targetValue, $dt, $lagTimeConstant);
        }

        $timeAtCeiling = ($distance - $knee) / $maxSpeed;
        $move = $timeAtCeiling >= $dt || $lagTimeConstant <= 0.0
            ? min($distance, $maxSpeed * $dt)
            : ($distance - $knee) + $knee * (1.0 - exp(-($dt - $timeAtCeiling) / $lagTimeConstant));

        return $currentLaggedValue + ($targetValue > $currentLaggedValue ? $move : -$move);
    }

    public static function calculateReversionPull(
        float $currentReturn,
        float $wacc,
        float $baseKappa,
        float $moatSpread,
        float $dt = 0.25,
        float $erosionAlpha = FinancialConstants::REVERSION_COMPETITIVE_EROSION_ALPHA,
        float $distressPersistence = FinancialConstants::REVERSION_DISTRESS_PERSISTENCE,
        float $distressGamma = FinancialConstants::REVERSION_DISTRESS_GAMMA
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
        int $periodsPerYear = 4
    ): float {
        return ($periodValue / max(0.01, $seasonalFactor)) * $periodsPerYear;
    }

    /**
     * Calculates the mean-reverting exponential moving average (Ornstein-Uhlenbeck mix drift) for dynamic portfolio/revenue weights.
     *
     * Formula: w_{t+1} = w_t + alpha * (realized_t - w_t) + kappa * (target - w_t)
     *
     * @param float $currentWeight Current active weight w_t
     * @param float $realizedShare Recent realized share r_t
     * @param float $targetWeight  Long-term strategic anchor theta
     * @param float $alpha         Adaptation speed parameter [0, 1]
     * @param float $kappa         Mean reversion pull speed [0, 1]
     * @param float $minFloor      Minimum structural floor clamp for active diversified segments
     * @param float $maxCeiling    Maximum structural ceiling clamp for diversified segments
     * @return float Clamped updated weight before simplex normalization
     */
    public static function calculateMeanRevertingWeight(
        float $currentWeight,
        float $realizedShare,
        float $targetWeight,
        float $alpha,
        float $kappa,
        float $minFloor = FinancialConstants::DEFAULT_MIN_STREAM_WEIGHT_FLOOR,
        float $maxCeiling = FinancialConstants::DEFAULT_MAX_STREAM_WEIGHT_CEILING
    ): float {
        $drift = $alpha * ($realizedShare - $currentWeight);
        $reversion = $kappa * ($targetWeight - $currentWeight);
        $raw = $currentWeight + $drift + $reversion;

        $effectiveMinFloor = $targetWeight <= 0.0 ? 0.0 : min($minFloor, $targetWeight);
        $effectiveMaxCeiling = max($maxCeiling, $targetWeight);

        return max($effectiveMinFloor, min($effectiveMaxCeiling, $raw));
    }
}
