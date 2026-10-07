<?php

declare(strict_types=1);

namespace App\Service\Season;

/**
 * Risk and return figures for a season entry, read from its running sums of weekly returns.
 *
 * Sharpe ratio (Sharpe 1966, "Mutual Fund Performance"; ex-post form, Sharpe 1994): mean weekly return over the cash
 * rate divided by the weekly standard deviation, annualised by √(periods per year). Beta and alpha are the CAPM
 * regression of weekly returns on the benchmark's (Jensen 1968): beta = cov(r, b) / var(b), alpha = mean excess
 * return less beta × the benchmark's mean excess, annualised. Periods per year are measured from the elapsed
 * simulation time the weeks span, never assumed.
 */
final class SeasonStatistics
{
    /** Fractional change from a base; null when the base is not positive. */
    public static function growth(float $value, float $base): ?float
    {
        return $base > 0.0 ? $value / $base - 1.0 : null;
    }

    /** Observations per simulated year, from the time the recorded weeks span. */
    public static function periodsPerYear(SeasonStatisticsInput $in): ?float
    {
        return $in->periods > 0 && $in->sumDt > 0.0 ? $in->periods / $in->sumDt : null;
    }

    /** Annualised volatility of weekly returns (sample standard deviation); null under two weeks. */
    public static function volatility(SeasonStatisticsInput $in): ?float
    {
        $sd = self::sampleStdDev($in->periods, $in->sumReturn, $in->sumReturnSq);
        $perYear = self::periodsPerYear($in);

        return $sd === null || $perYear === null ? null : $sd * sqrt($perYear);
    }

    /** Annualised Sharpe ratio; null under two weeks or with no variation at all. */
    public static function sharpe(SeasonStatisticsInput $in): ?float
    {
        $sd = self::sampleStdDev($in->periods, $in->sumReturn, $in->sumReturnSq);
        $perYear = self::periodsPerYear($in);
        if ($sd === null || $sd <= 0.0 || $perYear === null) {
            return null;
        }

        $meanExcess = ($in->sumReturn - $in->sumRiskFree) / $in->periods;

        return $meanExcess / $sd * sqrt($perYear);
    }

    /** Beta to the benchmark; null under two weeks or when the benchmark did not move. */
    public static function beta(SeasonStatisticsInput $in): ?float
    {
        if ($in->periods < 2) {
            return null;
        }

        $n = $in->periods;
        $varB = ($in->sumBenchmarkSq - $in->sumBenchmark * $in->sumBenchmark / $n) / ($n - 1);
        if ($varB <= 0.0) {
            return null;
        }

        $cov = ($in->sumCross - $in->sumReturn * $in->sumBenchmark / $n) / ($n - 1);

        return $cov / $varB;
    }

    /** Jensen's alpha, annualised: the return not explained by the benchmark exposure. */
    public static function alpha(SeasonStatisticsInput $in): ?float
    {
        $beta = self::beta($in);
        $perYear = self::periodsPerYear($in);
        if ($beta === null || $perYear === null) {
            return null;
        }

        $n = $in->periods;
        $meanRf = $in->sumRiskFree / $n;
        $excess = $in->sumReturn / $n - $meanRf;
        $benchmarkExcess = $in->sumBenchmark / $n - $meanRf;

        return ($excess - $beta * $benchmarkExcess) * $perYear;
    }

    private static function sampleStdDev(int $n, float $sum, float $sumSq): ?float
    {
        if ($n < 2) {
            return null;
        }

        $variance = ($sumSq - $sum * $sum / $n) / ($n - 1);

        return sqrt(max(0.0, $variance));
    }
}
