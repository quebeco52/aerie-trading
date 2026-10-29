<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\TimeSeries.
 */
#[Group('unit')]
class TimeSeriesTest extends TestCase
{
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
            dt: $dtQuarterly,
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

    /** The weight is 1 - e^(-dt/tau) to the bit, none with no time passed, and all of the gap at tau <= 0. */
    public function testSmoothingWeightIsTheExactLagShareWithItsLimits(): void
    {
        foreach ([[1.0 / 3600.0, 0.25], [0.25, 5.0], [1.0 / 252.0, 0.004], [2.0, 0.5]] as [$dt, $tau]) {
            $this->assertSame(1.0 - exp(-$dt / $tau), TimeSeries::smoothingWeight($dt, $tau));
        }

        $this->assertSame(0.0, TimeSeries::smoothingWeight(0.0, 0.25));
        $this->assertSame(0.0, TimeSeries::smoothingWeight(-0.01, 0.25));
        $this->assertSame(1.0, TimeSeries::smoothingWeight(0.25, 0.0));
        $this->assertSame(1.0, TimeSeries::smoothingWeight(0.25, -1.0));
        $this->assertSame(0.0, TimeSeries::smoothingWeight(0.0, 0.0), 'With no time passed nothing moves, even at tau 0.');
        $this->assertGreaterThan(0.999, TimeSeries::smoothingWeight(10.0, 1.0));
    }

    /**
     * The lag step is x + w (target - x) with the weight above, the same double as the hand-written step; no time
     * passed keeps the value, and a zero or negative time constant jumps to the target.
     */
    public function testDistributedLagKeepsItsValueWithNoTimePassedAndJumpsAtAZeroTimeConstant(): void
    {
        $current = 0.0731;
        $target = 0.0412;
        $dt = 1.0 / 3600.0;
        $tau = 0.25;

        $this->assertSame($current + ((1.0 - exp(-$dt / $tau)) * ($target - $current)), TimeSeries::calculateDistributedLag($current, $target, $dt, $tau));

        $this->assertSame($current, TimeSeries::calculateDistributedLag($current, $target, 0.0, $tau));
        $this->assertSame($current, TimeSeries::calculateDistributedLag($current, $target, -0.25, $tau));
        $this->assertSame($current, TimeSeries::calculateDistributedLag($current, $target, 0.0, 0.0));
        $this->assertSame($target, TimeSeries::calculateDistributedLag($current, $target, $dt, 0.0));
        $this->assertSame($target, TimeSeries::calculateDistributedLag($current, $target, $dt, -1.0));
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

        // At any persistence: a lender's gap, which keeps 45% a year, is worth less than an operating firm's.
        foreach ([0.45, 0.62] as $persistence) {
            $fading = 0.0;
            for ($year = 1; $year <= 400; $year++) {
                $fading += $gap * ($persistence ** $year) / ((1.0 + $rate) ** $year);
            }
            $this->assertEqualsWithDelta($fading, (TimeSeries::persistentEquivalentReturn(0.12 + $gap, 0.12, $rate, $persistence) - 0.12) / $rate, 1e-12, "persistence {$persistence}");
        }
    }

    /**
     * A level EMA forgets per unit of time: four quarters at a time leave what one year does, and a first reading
     * starts it.
     */
    public function testALevelAverageForgetsAtTheSameRateAtAnyStep(): void
    {
        $quarterly = 0.08;
        for ($quarter = 0; $quarter < 4; $quarter++) {
            $quarterly = TimeSeries::ewmaLevel($quarterly, 0.20, 0.25, 5.0);
        }

        $this->assertEqualsWithDelta(TimeSeries::ewmaLevel(0.08, 0.20, 1.0, 5.0), $quarterly, 1e-15);
        $this->assertEqualsWithDelta(0.08 + ((1.0 - exp(-1.0)) * 0.12), TimeSeries::ewmaLevel(0.08, 0.20, 5.0, 5.0), 1e-15);
        $this->assertSame(0.20, TimeSeries::ewmaLevel(null, 0.20, 0.25, 5.0));
        $this->assertSame(TimeSeries::calculateDistributedLag(0.08, 0.20, 0.25, 5.0), TimeSeries::ewmaLevel(0.08, 0.20, 0.25, 5.0), 'One lag, not two.');
    }

    /**
     * Each period turns once, on the tick that reaches it, however the clock's additions round: the floor of an
     * accumulated clock lands a year late at 252 ticks a year, where 252 additions of 1/252 fall short of 1.
     */
    public function testEachPeriodTurnsOnceOnTheTickThatReachesIt(): void
    {
        foreach ([252, 360, 14400] as $ticksPerYear) {
            $dt = 1.0 / $ticksPerYear;
            $clock = 0.0;
            $turns = [];
            for ($tick = 1; $tick <= 3 * $ticksPerYear; $tick++) {
                $clock += $dt;
                $crossed = TimeSeries::boundariesCrossed($clock, $dt, 1.0);
                if ($crossed > 0) {
                    $turns[$tick] = $crossed;
                }
                $this->assertSame($crossed > 0, TimeSeries::crossedSimulatedBoundary($clock, $dt, 1.0));
            }

            $this->assertSame([$ticksPerYear => 1, 2 * $ticksPerYear => 1, 3 * $ticksPerYear => 1], $turns, "{$ticksPerYear} ticks a year");
        }
    }

    /**
     * A step longer than a period crosses each boundary inside it; the period a run starts in is entered, not crossed.
     */
    public function testAStepSpanningSeveralPeriodsCountsEachOne(): void
    {
        $this->assertSame(3, TimeSeries::boundariesCrossed(0.75, 0.6, 0.25));
        $this->assertSame(4, TimeSeries::boundariesCrossed(1.0, 1.0, 0.25));
        $this->assertSame(0, TimeSeries::boundariesCrossed(0.1, 0.1, 1.0));
        $this->assertSame(0, TimeSeries::boundariesCrossed(1.0, 0.0, 1.0), 'No time passed, nothing crossed.');
    }

    /**
     * Harvey & Jaeger (1993): the one-sided HP trend as a Kalman recursion splits each surprise between level and slope
     * by the gains.
     */
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
                1e-12,
            );
        }
    }

    /**
     * The memory is a span of simulated time: after one tau of quiet the estimate has decayed by e at any tick rate.
     */
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

    /**
     * Each line compounds its own faded drift against a line with none, the shares always sum to one, a line with no
     * weight never gains one, and the mix settles where the drifts have faded out.
     */
    public function testDriftedLineSharesCompoundEachLinesFadedDrift(): void
    {
        $weights = ['legacy' => 2.0, 'steady' => 1.0, 'new' => 1.0, 'none' => 0.0];
        $drifts = ['legacy' => -0.03, 'new' => 0.05, 'none' => 0.10];

        $opening = TimeSeries::driftedLineShares($weights, $drifts, 0.0, 8.0);
        self::assertEqualsWithDelta(0.5, $opening['legacy'], 1e-12);
        self::assertEqualsWithDelta(0.25, $opening['steady'], 1e-12);

        $shares = TimeSeries::driftedLineShares($weights, $drifts, 12.0, 8.0);
        self::assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        self::assertSame(0.0, $shares['none']);
        self::assertEqualsWithDelta(2.0 * exp(TimeSeries::fadedExcessIntegral(-0.03, 0.0, 12.0, 8.0)), $shares['legacy'] / $shares['steady'], 1e-12);
        self::assertEqualsWithDelta(exp(TimeSeries::fadedExcessIntegral(0.05, 0.0, 12.0, 8.0)), $shares['new'] / $shares['steady'], 1e-12);

        $settled = TimeSeries::driftedLineShares($weights, $drifts, 1.0e6, 8.0);
        self::assertEqualsWithDelta(exp(0.05 * 8.0 / M_LN2), $settled['new'] / $settled['steady'], 1e-9);
        self::assertEqualsWithDelta(0.25, TimeSeries::driftedLineShares($weights, [], 40.0, 8.0)['steady'], 1e-12, 'no drift, no change');
    }

    /**
     * A firm with the industry's typical mix grows at the industry's rate; one that leans toward a drifting line grows
     * by the lean times the drift, however its weights are scaled.
     */
    public function testTheLineMixExcessIsTheLeanFromTheTypicalMixTimesEachDrift(): void
    {
        $typical = ['legacy' => 0.5, 'steady' => 0.35, 'new' => 0.15];
        $drifts = ['legacy' => -0.02, 'new' => 0.04];

        self::assertEqualsWithDelta(0.0, TimeSeries::lineMixExcessGrowth($typical, $typical, $drifts), 1e-15);
        $firm = ['legacy' => 0.2, 'steady' => 0.5, 'new' => 0.3];
        $expected = ((0.2 - 0.5) * -0.02) + ((0.3 - 0.15) * 0.04);
        self::assertEqualsWithDelta($expected, TimeSeries::lineMixExcessGrowth($firm, $typical, $drifts), 1e-15);
        self::assertEqualsWithDelta($expected, TimeSeries::lineMixExcessGrowth(array_map(static fn(float $w): float => 4.0 * $w, $firm), $typical, $drifts), 1e-15);
        self::assertSame(0.0, TimeSeries::lineMixExcessGrowth([], $typical, $drifts));
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

    /**
     * Flow that leans the same way tick after tick moves a day's price by the same amount at any tick rate, and the
     * windowed reading says so; read tick by tick, its variance shrank with the tick. Flow that alternates reads as
     * it did.
     */
    public function testAWindowedVarianceReadsPersistentFlowTheSameAtAnyTickRate(): void
    {
        $day = 1.0 / 252.0;
        $drift = 0.02; // log return per year the flow keeps pushing
        $read = static function (int $ticksPerDay, bool $windowed) use ($day, $drift): float {
            $dt = $day / $ticksPerDay;
            $variance = 0.0;
            $window = ['sum' => 0.0, 'elapsed' => 0.0];
            for ($tick = 0; $tick < 252 * 4 * $ticksPerDay; $tick++) {
                if ($windowed) {
                    $step = TimeSeries::ewmaWindowedVariance($variance, $window, $drift * $dt, $dt, $day, 0.25);
                    [$variance, $window] = [$step['variance'], $step['window']];
                } else {
                    $variance = TimeSeries::ewmaAnnualizedVariance($variance, $drift * $dt, $dt, 0.25);
                }
            }

            return $variance;
        };

        self::assertEqualsWithDelta($read(1, true), $read(10, true), $read(1, true) * 1e-6);
        self::assertEqualsWithDelta(($drift * $drift) * $day, $read(10, true), ($drift * $drift) * $day * 1e-3);
        self::assertLessThan(0.2 * $read(1, false), $read(10, false), 'Read tick by tick, it shrinks with the tick.');

        // Alternating flow cancels inside the day and reads no more than its per-tick figure.
        $window = ['sum' => 0.0, 'elapsed' => 0.0];
        $variance = 0.0;
        for ($tick = 0; $tick < 252 * 4 * 2; $tick++) {
            $step = TimeSeries::ewmaWindowedVariance($variance, $window, ($tick % 2 === 0 ? 1.0 : -1.0) * 0.001, $day / 2.0, $day, 0.25);
            [$variance, $window] = [$step['variance'], $step['window']];
        }
        self::assertEqualsWithDelta(0.0, $variance, 1e-12);
    }
}
