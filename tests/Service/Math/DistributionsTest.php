<?php

namespace App\Tests\Service\Math;

use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\Distributions;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\Distributions.
 */
class DistributionsTest extends TestCase
{
    private const EDGES = CreditFiscalSubsystem::NEW_PURCHASE_CLTV_EDGES;
    private const SHARES = CreditFiscalSubsystem::NEW_PURCHASE_CLTV_SHARES;

    public function testCalculateInverseNormalCDF(): void
    {
        // Symmetry around 0.5
        $this->assertEqualsWithDelta(0.0, Distributions::calculateInverseNormalCDF(0.50), 0.0001);

        // Standard normal quantiles
        $this->assertEqualsWithDelta(-2.3263, Distributions::calculateInverseNormalCDF(0.01), 0.001);
        $this->assertEqualsWithDelta(-1.6449, Distributions::calculateInverseNormalCDF(0.05), 0.001);
        $this->assertEqualsWithDelta(-1.2816, Distributions::calculateInverseNormalCDF(0.10), 0.001);
        $this->assertEqualsWithDelta(1.2816, Distributions::calculateInverseNormalCDF(0.90), 0.001);
        $this->assertEqualsWithDelta(1.6449, Distributions::calculateInverseNormalCDF(0.95), 0.001);
        $this->assertEqualsWithDelta(2.3263, Distributions::calculateInverseNormalCDF(0.99), 0.001);

        // Invariance round-trip: Phi(Phi^-1(p)) = p
        $probabilities = [0.001, 0.01, 0.05, 0.20, 0.50, 0.80, 0.95, 0.99, 0.999];
        foreach ($probabilities as $p) {
            $z = Distributions::calculateInverseNormalCDF($p);
            $recoveredP = Distributions::calculateNormalCDF($z);
            $this->assertEqualsWithDelta($p, $recoveredP, 0.0005, "Round trip failed for p = $p");
        }

        // Boundary safety clamps
        $this->assertFalse(is_nan(Distributions::calculateInverseNormalCDF(0.0)));
        $this->assertFalse(is_nan(Distributions::calculateInverseNormalCDF(1.0)));
        $this->assertFalse(is_infinite(Distributions::calculateInverseNormalCDF(0.0)));
        $this->assertFalse(is_infinite(Distributions::calculateInverseNormalCDF(1.0)));
    }

    /** E[max(0, t - Z)] = tΦ(t) + φ(t): φ(0) at the mean, ~0.0293 at t = -1.5, and the identity f(t) - f(-t) = t. */
    public function testNormalLowerPartialMoment(): void
    {

        $this->assertEqualsWithDelta(1.0 / sqrt(2.0 * M_PI), Distributions::calculateNormalLowerPartialMoment(0.0), 1e-6);
        $this->assertEqualsWithDelta(0.029307, Distributions::calculateNormalLowerPartialMoment(-1.5), 1e-5);
        foreach ([0.5, 1.5, 3.0] as $t) {
            $this->assertEqualsWithDelta($t, Distributions::calculateNormalLowerPartialMoment($t) - Distributions::calculateNormalLowerPartialMoment(-$t), 1e-6);
        }
    }

    public function testCalculateNormalCDF(): void
    {
        // Standard normal symmetry: N(0) = 0.5
        $this->assertEqualsWithDelta(0.50, Distributions::calculateNormalCDF(0.0), 0.0001);

        // N(1.96) ≈ 0.9750
        $this->assertEqualsWithDelta(0.9750, Distributions::calculateNormalCDF(1.96), 0.0005);

        // N(-1.96) ≈ 0.0250
        $this->assertEqualsWithDelta(0.0250, Distributions::calculateNormalCDF(-1.96), 0.0005);

        // Extreme bounds
        $this->assertGreaterThan(0.9999, Distributions::calculateNormalCDF(6.0));
        $this->assertLessThan(0.0001, Distributions::calculateNormalCDF(-6.0));
    }

    /**
     * Mean absolute deviation recovers sigma without letting one outlier dominate, unlike a sum of squares.
     */
    public function testMeanAbsoluteScaleRecoversSigmaAndResistsOutliers(): void
    {
        $this->assertSame(0.0, Distributions::calculateMeanAbsoluteScale([]), 'An empty sample has nothing to estimate from.');
        $this->assertSame(0.0, Distributions::calculateMeanAbsoluteScale([0.0, 0.0, 0.0]), 'A sample of exact forecasts has zero scale.');

        // E|X| = sigma * sqrt(2/pi), so a constant absolute deviation recovers that deviation over the constant.
        $this->assertEqualsWithDelta(
            1.0 / Distributions::MEAN_ABSOLUTE_DEVIATION_TO_SIGMA,
            Distributions::calculateMeanAbsoluteScale([1.0, -1.0, 1.0, -1.0]),
            0.0000001,
            'The estimator must invert the normal mean-absolute-deviation constant.'
        );

        // Sign is discarded: only the magnitude of the surprise carries scale.
        $this->assertSame(
            Distributions::calculateMeanAbsoluteScale([0.4, -0.2, 0.6]),
            Distributions::calculateMeanAbsoluteScale([-0.4, 0.2, -0.6]),
            'The scale of a surprise must not depend on its direction.'
        );

        // The point of the estimator: one blow-up quarter must not dominate the other nine.
        $quiet = array_fill(0, 9, 0.02);
        $withOutlier = $quiet;
        $withOutlier[] = 2.0;

        $sumOfSquares = sqrt(array_sum(array_map(static fn (float $x): float => $x ** 2, $withOutlier)) / count($withOutlier));
        $robust = Distributions::calculateMeanAbsoluteScale($withOutlier);

        $this->assertLessThan($sumOfSquares, $robust, 'A fat tail must move the mean absolute estimate less than a root-mean-square.');
    }

    public function testGompertzMakehamWaitIsExponentialWithoutTheAgeTerm(): void
    {
        // With no Gompertz term the hazard is constant, so the wait is -ln(u) / background, whatever the age.
        foreach ([0.05, 0.5, 0.95] as $uniform) {
            $this->assertEqualsWithDelta(-log($uniform) / 0.04, Distributions::gompertzMakehamWait($uniform, 63.0, 0.0, 0.09, 0.04), 1e-9);
        }
    }

    public function testGompertzMakehamWaitSpendsExactlyTheDrawnCumulativeHazard(): void
    {
        // The wait solves background * t + level * e^(slope * age) * (e^(slope * t) - 1) / slope = -ln(u).
        [$level, $slope, $background, $age] = [3.0e-5, 0.09, 0.02, 55.0];
        foreach ([1e-6, 0.01, 0.3, 0.7, 0.999] as $uniform) {
            $wait = Distributions::gompertzMakehamWait($uniform, $age, $level, $slope, $background);
            $spent = ($background * $wait) + ($level * exp($slope * $age) * (exp($slope * $wait) - 1.0) / $slope);
            $this->assertGreaterThan(0.0, $wait);
            $this->assertEqualsWithDelta(-log($uniform), $spent, 1e-9 * max(1.0, -log($uniform)));
        }
        $this->assertLessThan(
            Distributions::gompertzMakehamWait(0.5, $age, $level, $slope, $background),
            Distributions::gompertzMakehamWait(0.5, $age + 20.0, $level, $slope, $background),
            'The older wait less.'
        );
    }

    /** Below every bin the moment is the mean (NMDB's 82.2%), above every bin it is zero, and the tail share runs 1 to 0. */
    public function testTheMomentRunsFromTheMeanToNothing(): void
    {
        $this->assertEqualsWithDelta(0.822, Distributions::histogramUpperPartialMoment(self::EDGES, self::SHARES, 0.0), 5e-4);
        $this->assertSame(0.0, Distributions::histogramUpperPartialMoment(self::EDGES, self::SHARES, 1.02));
        $this->assertSame(0.0, Distributions::histogramUpperPartialMoment(self::EDGES, self::SHARES, 1.20));
        $this->assertEqualsWithDelta(1.0, Distributions::histogramUpperTailShare(self::EDGES, self::SHARES, 0.0), 1e-12);
        $this->assertSame(0.0, Distributions::histogramUpperTailShare(self::EDGES, self::SHARES, 1.02));
    }

    /** One bin, uniform on [0, 1]: E[max(0, X - t)] = (1 - t)^2 / 2 and P(X > t) = 1 - t. */
    public function testAUniformBinHasTheClosedForm(): void
    {
        foreach ([0.0, 0.25, 0.5, 0.9] as $t) {
            $this->assertEqualsWithDelta(((1.0 - $t) ** 2) / 2.0, Distributions::histogramUpperPartialMoment([0.0, 1.0], [3.0], $t), 1e-12);
            $this->assertEqualsWithDelta(1.0 - $t, Distributions::histogramUpperTailShare([0.0, 1.0], [3.0], $t), 1e-12);
        }
    }

    /** The moment's slope is minus the share above the threshold, so it falls and is convex; at 90% the share is NMDB's 44%. */
    public function testTheSlopeIsMinusTheShareAbove(): void
    {
        $h = 1e-6;
        foreach ([0.72, 0.80, 0.85, 0.90, 0.93, 0.96, 1.00] as $t) {
            $slope = (Distributions::histogramUpperPartialMoment(self::EDGES, self::SHARES, $t + $h) - Distributions::histogramUpperPartialMoment(self::EDGES, self::SHARES, $t - $h)) / (2.0 * $h);
            $this->assertEqualsWithDelta(-Distributions::histogramUpperTailShare(self::EDGES, self::SHARES, $t), $slope, 1e-6, "at {$t}");
        }
        $this->assertEqualsWithDelta(0.437, Distributions::histogramUpperTailShare(self::EDGES, self::SHARES, 0.90), 1e-3);
        $this->assertEqualsWithDelta(0.0277, Distributions::histogramUpperPartialMoment(self::EDGES, self::SHARES, 0.90), 1e-4);
    }
}
