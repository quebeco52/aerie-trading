<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The economy must not depend on how often it is asked.
 *
 * SIM_TICKS_PER_YEAR is a resolution knob: it decides how finely the price path is sampled, not what the
 * economy does. It has been set to 14,400, 7,200, 3,600 and 1,800 inside a single day, and if behaviour
 * moves with it then every calibration in this suite is pinned to a configuration value rather than to the
 * economics.
 *
 * WHAT IS TESTED IS THE SCALING LAW OF EACH PRIMITIVE, NOT THE BEHAVIOUR OF THE ENGINE. That choice was
 * made after measuring: the macro engine's own statistics were compared at 126 and 1,008 ticks a year over
 * 48 seeds and agreed within |z| < 1.3, so the engine IS neutral — but the estimator is far too noisy to
 * assert it cheaply. At six seeds, an affordable size for a suite, the gap's standard-deviation ratio
 * wandered between 0.70 and 1.56 with the model untouched; an earlier sixteen-seed reading showed a ten
 * percent trend that REVERSED SIGN at forty-eight. Worse, a deliberately broken diffusion — `* $dt` where
 * `* sqrt($dt)` belongs, in the output gap's own term — moved that ratio from 0.785 to 0.844, i.e. TOWARDS
 * one. An emergent-level test would have passed the mutant while claiming to catch exactly it.
 *
 * The scaling laws below are exact properties of single functions, measurable to a few percent with a few
 * thousand samples, and they fail loudly on the mistakes that are actually made: a diffusion scaled by dt
 * rather than its square root, an arrival rate that is a per-tick probability rather than a per-year one,
 * and a smoothing weight written dt/tau instead of 1 - exp(-dt/tau).
 */
class TimestepNeutralityTest extends TestCase
{
    /** Eightfold apart: every break below scales as a power of dt, so the contrast is the signal. */
    private const COARSE_TICKS_PER_YEAR = 126;
    private const FINE_TICKS_PER_YEAR = 1008;

    private const SEED = 20260921;

    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    /**
     * A year of diffusion carries the same variance however finely the year is cut.
     *
     * Brownian variance accumulates linearly in time, so the per-step shock must scale as sqrt(dt); written
     * as dt instead, a year's variance collapses by the ratio of the steps — a factor of eight here — which
     * no amount of recalibration downstream would recover honestly.
     */
    public function testAYearOfDiffusionCarriesTheSameVarianceAtEveryStepSize(): void
    {
        $variances = [];

        foreach ([self::COARSE_TICKS_PER_YEAR, self::FINE_TICKS_PER_YEAR] as $ticksPerYear) {
            mt_srand(self::SEED);
            $dt = 1.0 / $ticksPerYear;
            $returns = [];

            for ($path = 0; $path < 4000; $path++) {
                $price = 100.0;

                for ($step = 0; $step < $ticksPerYear; $step++) {
                    $price = $this->math->calculateCorrelatedGBM(
                        currentPrice: $price,
                        idiosyncraticVolatility: 0.25,
                        drift: 0.0,
                        gravityDrift: 0.0,
                        dt: $dt,
                        beta: 0.0,
                        marketVol: 0.0,
                        marketZ: 0.0,
                        w1: $this->math->generateStandardNormal(),
                    );
                }

                $returns[] = log($price / 100.0);
            }

            $variances[$ticksPerYear] = $this->variance($returns);
        }

        $ratio = $variances[self::FINE_TICKS_PER_YEAR] / $variances[self::COARSE_TICKS_PER_YEAR];

        // 4,000 paths put the sampling error on a variance ratio near 2%; a dt-scaled diffusion lands at 0.125.
        $this->assertEqualsWithDelta(
            1.0,
            $ratio,
            0.12,
            sprintf(
                'A year of diffusion carries %.5f of its variance at %d ticks against %d. The shock is not scaling as sqrt(dt).',
                $ratio,
                self::FINE_TICKS_PER_YEAR,
                self::COARSE_TICKS_PER_YEAR
            )
        );
    }

    /**
     * A jump intensity is arrivals per YEAR, so a year sees the same number of them at any step size.
     *
     * The arrival test is `lambda * dt`, which is a probability per step. Hand it a per-tick number by
     * mistake and a rarity becomes commonplace the moment the tick rate rises.
     */
    public function testAYearSeesTheSameNumberOfJumpsAtEveryStepSize(): void
    {
        $arrivals = [];

        foreach ([self::COARSE_TICKS_PER_YEAR, self::FINE_TICKS_PER_YEAR] as $ticksPerYear) {
            mt_srand(self::SEED);
            $dt = 1.0 / $ticksPerYear;
            $jumps = 0;
            $years = 3000;

            for ($step = 0; $step < $years * $ticksPerYear; $step++) {
                $jump = $this->math->calculateSVJJJumps(
                    lambda: 0.8,
                    pUp: 0.3,
                    etaUp: 25.0,
                    etaDown: 20.0,
                    muV: 0.0,
                    dt: $dt
                );

                if ($jump['shock_pct'] !== null) {
                    $jumps++;
                }
            }

            $arrivals[$ticksPerYear] = $jumps / $years;
        }

        foreach ($arrivals as $ticksPerYear => $perYear) {
            // 3,000 years of a 0.8/yr arrival puts the standard error near 0.016, so 10% is comfortable.
            $this->assertEqualsWithDelta(
                0.8,
                $perYear,
                0.08,
                sprintf('At %d ticks a year the market jumps %.3f times a year against the 0.8 it was asked for.', $ticksPerYear, $perYear)
            );
        }
    }

    /**
     * A smoothing horizon is measured in years, so the same elapsed time closes the same share of the gap.
     *
     * Deterministic, and the one place a first-order shortcut hides in plain sight: `dt / tau` looks like a
     * weight and behaves like one at small steps, while `1 - exp(-dt / tau)` is the same thing done exactly.
     * The two part company as the step grows, which is precisely when the tick rate is lowered for speed.
     */
    public function testASmoothingHorizonClosesTheSameGapInTheSameElapsedTime(): void
    {
        $horizonYears = 0.5;
        $levels = [];

        foreach ([self::COARSE_TICKS_PER_YEAR, self::FINE_TICKS_PER_YEAR] as $ticksPerYear) {
            $dt = 1.0 / $ticksPerYear;
            $value = 0.0;

            for ($step = 0; $step < $ticksPerYear; $step++) {
                $value = $this->math->calculateDistributedLag(
                    currentLaggedValue: $value,
                    targetValue: 1.0,
                    dt: $dt,
                    lagTimeConstant: $horizonYears
                );
            }

            $levels[$ticksPerYear] = $value;
        }

        // One year against a half-year constant leaves exp(-2) of the gap, whatever the step.
        $expected = 1.0 - exp(-1.0 / $horizonYears);

        foreach ($levels as $ticksPerYear => $level) {
            $this->assertEqualsWithDelta(
                $expected,
                $level,
                1e-9,
                sprintf('At %d ticks a year the lag reached %.9f against the %.9f one year implies.', $ticksPerYear, $level, $expected)
            );
        }
    }

    /** @param list<float> $samples */
    private function variance(array $samples): float
    {
        $count = count($samples);
        $mean = array_sum($samples) / $count;
        $variance = 0.0;

        foreach ($samples as $sample) {
            $variance += ($sample - $mean) ** 2;
        }

        return $variance / $count;
    }
}
