<?php

namespace App\Tests\Service\Math;

use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\TimeSeries.
 */
class TimeSeriesTest extends TestCase
{
    public function testCalculateMeanRevertingWeight(): void
    {
        // Target = 0.20, Current = 0.20, Realized Share = 0.50 (boom)
        // Drift = 0.15 * (0.50 - 0.20) = +0.045
        // Reversion = 0.08 * (0.20 - 0.20) = 0.0
        // Result = 0.20 + 0.045 = 0.245
        $updated = TimeSeries::calculateMeanRevertingWeight(
            0.20,
            0.50,
            0.20,
            0.15,
            0.08,
            0.05,
            0.85
        );
        $this->assertEqualsWithDelta(0.245, $updated, 0.001);

        // Reversion pull when weight has drifted far above target:
        // Target = 0.20, Current = 0.40, Realized Share = 0.20 (cooled down)
        // Drift = 0.15 * (0.20 - 0.40) = -0.030
        // Reversion = 0.08 * (0.20 - 0.40) = -0.016
        // Result = 0.40 - 0.030 - 0.016 = 0.354 (pulling back toward 0.20)
        $reverting = TimeSeries::calculateMeanRevertingWeight(
            0.40,
            0.20,
            0.20,
            0.15,
            0.08,
            0.05,
            0.85
        );
        $this->assertEqualsWithDelta(0.354, $reverting, 0.001);

        // Clamping bounds
        $clampedCeiling = TimeSeries::calculateMeanRevertingWeight(0.80, 1.0, 0.80, 0.50, 0.0, 0.05, 0.85);
        $this->assertEqualsWithDelta(0.85, $clampedCeiling, 0.001);

        $clampedFloor = TimeSeries::calculateMeanRevertingWeight(0.10, 0.0, 0.10, 0.50, 0.0, 0.05, 0.85);
        $this->assertEqualsWithDelta(0.05, $clampedFloor, 0.001);
    }

    public function testCalculateReversionPullWithCustomDt(): void
    {
        $currentReturn = 0.20;
        $wacc = 0.08;
        $moatSpread = 0.02;
        $baseKappa = 0.40;
        $dtQuarterly = 0.25;

        $pullQuarter = TimeSeries::calculateReversionPull(
            currentReturn: $currentReturn,
            wacc: $wacc,
            baseKappa: $baseKappa,
            moatSpread: $moatSpread,
            dt: $dtQuarterly
        );

        $equilibrium = $wacc + $moatSpread; // 0.10
        $excessRatio = ($currentReturn - $equilibrium) / $equilibrium; // (0.20 - 0.10) / 0.10 = 1.0
        $effectiveKappa = $baseKappa * (1.0 + 0.50 * $excessRatio); // 0.40 * 1.50 = 0.60
        $expectedWeight = 1.0 - exp(-$effectiveKappa * $dtQuarterly); // 1 - exp(-0.15) ≈ 0.139292
        $expectedPull = ($equilibrium - $currentReturn) * $expectedWeight;

        $this->assertEqualsWithDelta($expectedPull, $pullQuarter, 0.00001);
        $this->assertLessThan(0.0, $pullQuarter, 'Excess return above equilibrium should pull return downwards.');
    }

    public function testCalculateDistributedLagSmoothsTransitions(): void
    {
        $current = 0.0;
        $target = 10.0;
        $timeConstant = 1.0; // 1 year lag

        // Step of dt = 0.25 (1 quarter)
        $nextQuarter = TimeSeries::calculateDistributedLag($current, $target, 0.25, $timeConstant);
        $expected = 0.0 + (1.0 - exp(-0.25 / 1.0)) * 10.0; // ≈ 2.21199
        $this->assertEqualsWithDelta($expected, $nextQuarter, 0.0001);

        // Immediate transition when time constant is 0
        $immediate = TimeSeries::calculateDistributedLag($current, $target, 0.25, 0.0);
        $this->assertEquals($target, $immediate);
    }

    public function testSpeedLimitedDistributedLagIsThePlainLagBelowTheKnee(): void
    {
        // Knee = 0.06 * 0.25 = 150bp: a 100bp distance never reaches the ceiling.
        $this->assertEqualsWithDelta(
            TimeSeries::calculateDistributedLag(0.02, 0.03, 0.25, 0.25),
            TimeSeries::calculateSpeedLimitedDistributedLag(0.02, 0.03, 0.25, 0.25, 0.06),
            1e-15
        );
        $this->assertEqualsWithDelta(
            TimeSeries::calculateDistributedLag(0.09, 0.02, 0.25, 0.25),
            TimeSeries::calculateSpeedLimitedDistributedLag(0.09, 0.02, 0.25, 0.25, INF),
            1e-15
        );
    }

    public function testSpeedLimitedDistributedLagMovesAtTheCeilingThenFollowsTheLag(): void
    {
        // 700bp away: 5.5pp at the ceiling takes 0.917y, so a quarter moves exactly 0.06 * 0.25, either way.
        $this->assertEqualsWithDelta(0.035, TimeSeries::calculateSpeedLimitedDistributedLag(0.02, 0.09, 0.25, 0.25, 0.06), 1e-15);
        $this->assertEqualsWithDelta(0.075, TimeSeries::calculateSpeedLimitedDistributedLag(0.09, 0.02, 0.25, 0.25, 0.06), 1e-15);

        // 200bp away: 0.5pp at the ceiling (1/12y), then the plain lag from the 150bp knee for the rest of the quarter.
        $expected = 0.02 + 0.005 + 0.015 * (1.0 - exp(-(0.25 - 0.005 / 0.06) / 0.25));
        $this->assertEqualsWithDelta($expected, TimeSeries::calculateSpeedLimitedDistributedLag(0.02, 0.04, 0.25, 0.25, 0.06), 1e-15);

        // No lag: a plain rate limit, which stops at the target.
        $this->assertEqualsWithDelta(0.035, TimeSeries::calculateSpeedLimitedDistributedLag(0.02, 0.09, 0.25, 0.0, 0.06), 1e-15);
        $this->assertEqualsWithDelta(0.03, TimeSeries::calculateSpeedLimitedDistributedLag(0.02, 0.03, 0.25, 0.0, 0.06), 1e-15);
    }

    public function testSpeedLimitedDistributedLagIsTimestepNeutral(): void
    {
        foreach ([0.09, 0.04, 0.03] as $target) {
            $quarter = TimeSeries::calculateSpeedLimitedDistributedLag(0.02, $target, 0.25, 0.25, 0.06);
            $ticks = 0.02;
            for ($i = 0; $i < 90; $i++) {
                $ticks = TimeSeries::calculateSpeedLimitedDistributedLag($ticks, $target, 0.25 / 90, 0.25, 0.06);
            }
            $this->assertEqualsWithDelta($quarter, $ticks, 1e-12, 'One quarter step must equal 90 ticks toward the same target.');
        }
    }

    /** Yule-Walker: an AR(1) decays geometrically, and an AR(2)'s first autocorrelation is a1 / (1 - a2). */
    public function testAr2AutocorrelationFollowsTheYuleWalkerRecursion(): void
    {
        $this->assertSame(1.0, TimeSeries::calculateAr2Autocorrelation(1.2, -0.3, 0));
        for ($lag = 1; $lag <= 8; $lag++) {
            $this->assertEqualsWithDelta(0.8 ** $lag, TimeSeries::calculateAr2Autocorrelation(0.8, 0.0, $lag), 1e-12, 'With no second lag it is an AR(1).');
        }
        $this->assertEqualsWithDelta(1.2 / 1.3, TimeSeries::calculateAr2Autocorrelation(1.2, -0.3, 1), 1e-12);

        // Against the sample autocorrelation of a long simulated AR(2).
        mt_srand(20260928);
        $math = new MathUtility();
        [$previous, $current] = [0.0, 0.0];
        $series = [];
        for ($t = 0; $t < 200000; $t++) {
            [$previous, $current] = [$current, (1.2 * $current) - (0.3 * $previous) + $math->generateStandardNormal()];
            $series[] = $current;
        }
        $mean = array_sum($series) / count($series);
        $variance = 0.0;
        $covariance = 0.0;
        foreach ($series as $t => $x) {
            $variance += ($x - $mean) ** 2;
            if ($t >= 4) {
                $covariance += ($x - $mean) * ($series[$t - 4] - $mean);
            }
        }
        $this->assertEqualsWithDelta(TimeSeries::calculateAr2Autocorrelation(1.2, -0.3, 4), $covariance / $variance, 0.02);
    }

    /**
     * Ohlson (1995): a gap between the trailing return and its long-run level that fades at omega a year is worth
     * sum d omega^t / (1+r)^t; the permanent equivalent is the constant gap with the same value, d_eq / r. Omega is
     * pinned at one less Fama & French's (2000) 38% a year.
     */
    public function testThePersistentEquivalentReturnIsWorthWhatTheFadingReturnIs(): void
    {
        $rate = 0.09;
        $gap = 0.08;
        $fading = 0.0;
        for ($year = 1; $year <= 400; $year++) {
            $fading += $gap * (0.62 ** $year) / ((1.0 + $rate) ** $year);
        }

        $equivalentGap = TimeSeries::persistentEquivalentReturn(0.12 + $gap, 0.12, $rate) - 0.12;

        $this->assertEqualsWithDelta($fading, $equivalentGap / $rate, 1e-12);
        $this->assertSame(0.12, TimeSeries::persistentEquivalentReturn(0.12, 0.12, $rate));
        $this->assertEqualsWithDelta(0.12, TimeSeries::persistentEquivalentReturn(0.30, 0.12, 0.0), 1e-15);
    }

    /** A level EMA forgets per unit of time: four quarters at a time leave what one year does, and a first reading starts it. */
    public function testALevelAverageForgetsAtTheSameRateAtAnyStep(): void
    {
        $quarterly = 0.08;
        for ($quarter = 0; $quarter < 4; $quarter++) {
            $quarterly = TimeSeries::ewmaLevel($quarterly, 0.20, 0.25, 5.0);
        }

        $this->assertEqualsWithDelta(TimeSeries::ewmaLevel(0.08, 0.20, 1.0, 5.0), $quarterly, 1e-15);
        $this->assertEqualsWithDelta(0.08 + ((1.0 - exp(-1.0)) * 0.12), TimeSeries::ewmaLevel(0.08, 0.20, 5.0, 5.0), 1e-15);
        $this->assertSame(0.20, TimeSeries::ewmaLevel(null, 0.20, 0.25, 5.0));
    }

    /** Harvey & Jaeger (1993): the one-sided HP trend as a Kalman recursion splits each surprise between level and slope by the gains. */
    public function testOneSidedHpStepSplitsTheSurpriseByItsGains(): void
    {
        $step = TimeSeries::calculateOneSidedHpStep(1.0, 0.01, 1.11, 0.05, 0.002);
        $this->assertEqualsWithDelta(1.01 + 0.05 * 0.10, $step['level'], 1e-12, 'Predict along the slope, then correct by the level gain times the surprise.');
        $this->assertEqualsWithDelta(0.01 + 0.002 * 0.10, $step['slope'], 1e-12);

        $onTrend = TimeSeries::calculateOneSidedHpStep(2.0, 0.03, 2.03, 0.05, 0.002);
        $this->assertEqualsWithDelta(2.03, $onTrend['level'], 1e-12, 'An observation on the predicted trend leaves nothing to correct.');
        $this->assertEqualsWithDelta(0.03, $onTrend['slope'], 1e-12);
    }

    /** A steady return of sigma sqrt(dt) every tick holds the estimate at sigma^2, whatever the tick. */
    public function testEwmaAnnualizedVarianceHoldsATrueVarianceAtAnyTickRate(): void
    {
        foreach ([1.0 / 252.0, 1.0 / 14400.0] as $dt) {
            self::assertEqualsWithDelta(
                0.09,
                TimeSeries::ewmaAnnualizedVariance(0.09, 0.30 * sqrt($dt), $dt, 0.25),
                1e-12
            );
        }
    }

    /** The memory is a span of simulated time: after one tau of quiet the estimate has decayed by e at any tick rate. */
    public function testEwmaAnnualizedVarianceMemoryIsInYears(): void
    {
        foreach ([1.0 / 252.0, 1.0 / 14400.0] as $dt) {
            $variance = 0.04;
            $steps = (int) round(0.25 / $dt);
            for ($i = 0; $i < $steps; $i++) {
                $variance = TimeSeries::ewmaAnnualizedVariance($variance, 0.0, $dt, 0.25);
            }
            self::assertEqualsWithDelta(0.04 * exp(-1.0), $variance, 1e-9);
        }

        self::assertSame(0.04, TimeSeries::ewmaAnnualizedVariance(0.04, 0.1, 0.0, 0.25));
    }

    /** The excess over trend halves every half-life and the rate settles on trend; a trend sector never moves. */
    public function testSecularGrowthFadesTowardTrendAtItsHalfLife(): void
    {
        self::assertEqualsWithDelta(0.06, TimeSeries::fadeTowardTrend(0.06, 0.02, 0.0, 8.0), 1e-12);
        self::assertEqualsWithDelta(0.04, TimeSeries::fadeTowardTrend(0.06, 0.02, 8.0, 8.0), 1e-12);
        self::assertEqualsWithDelta(0.00, TimeSeries::fadeTowardTrend(-0.02, 0.02, 8.0, 8.0), 1e-12);
        self::assertEqualsWithDelta(0.02, TimeSeries::fadeTowardTrend(0.06, 0.02, 400.0, 8.0), 1e-12);
        self::assertSame(0.02, TimeSeries::fadeTowardTrend(0.02, 0.02, 5.0, 8.0));
    }

    /** What a level compounds is the integral of the faded rate: the closed form matches a fine Riemann sum. */
    public function testTheFadedExcessIntegralIsTheSumOfTheFadedRate(): void
    {
        $steps = 100000;
        $from = 3.0;
        $to = 15.0;
        $sum = 0.0;
        for ($i = 0; $i < $steps; $i++) {
            $t = $from + (($i + 0.5) * ($to - $from) / $steps);
            $sum += (TimeSeries::fadeTowardTrend(0.05, 0.02, $t, 8.0) - 0.02) * ($to - $from) / $steps;
        }

        self::assertEqualsWithDelta($sum, TimeSeries::fadedExcessIntegral(0.03, $from, $to, 8.0), 1e-9);
        self::assertEqualsWithDelta(0.03 * 8.0 / M_LN2, TimeSeries::fadedExcessIntegral(0.03, 0.0, 1.0e6, 8.0), 1e-9, 'a fading excess compounds a bounded amount');
    }
}
