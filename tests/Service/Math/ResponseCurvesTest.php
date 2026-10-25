<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\ResponseCurves;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\ResponseCurves.
 */
#[Group('unit')]
class ResponseCurvesTest extends TestCase
{
    public function testNormalizeWeightsSimplex(): void
    {
        $weights = ['a' => 0.60, 'b' => 0.20, 'c' => 0.20];
        $norm = ResponseCurves::normalizeWeightsSimplex($weights);
        $this->assertEqualsWithDelta(1.0, array_sum($norm), 0.0001);
        $this->assertEqualsWithDelta(0.60, $norm['a'], 0.0001);

        $unnormalized = ['a' => 0.90, 'b' => 0.30, 'c' => 0.30];
        $norm2 = ResponseCurves::normalizeWeightsSimplex($unnormalized);
        $this->assertEqualsWithDelta(1.0, array_sum($norm2), 0.0001);
        $this->assertEqualsWithDelta(0.60, $norm2['a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $norm2['b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $norm2['c'], 0.0001);
    }

    public function testAsymmetricResponseTakesOneSlopeAboveZeroAndAnotherBelow(): void
    {
        $this->assertEqualsWithDelta(6.0, ResponseCurves::calculateAsymmetricResponse(2.0, 3.0, 0.5), 1e-12);
        $this->assertEqualsWithDelta(-1.0, ResponseCurves::calculateAsymmetricResponse(-2.0, 3.0, 0.5), 1e-12);
        $this->assertSame(0.0, ResponseCurves::calculateAsymmetricResponse(0.0, 3.0, 0.5));
        // Continuous through the kink: no jump where the driver changes sign.
        $this->assertEqualsWithDelta(0.0, ResponseCurves::calculateAsymmetricResponse(1e-12, 3.0, 0.5) - ResponseCurves::calculateAsymmetricResponse(-1e-12, 3.0, 0.5), 1e-11);
    }

    public function testBassAdoptionIsAnSCurveWhoseSlopeIsItsRate(): void
    {
        $p = 0.03;
        $q = 0.38;

        $this->assertSame(0.0, ResponseCurves::bassAdoption(-1.0, $p, $q));
        $this->assertEqualsWithDelta($p, ResponseCurves::bassAdoptionRate(0.0, $p, $q), 1e-12);
        $this->assertGreaterThan(0.97, ResponseCurves::bassAdoption(16.0, $p, $q));

        // The rate is the curve's slope, and it peaks at ln(q / p) / (p + q).
        foreach ([0.5, 3.0, 6.0, 12.0] as $years) {
            $slope = (ResponseCurves::bassAdoption($years + 1e-6, $p, $q) - ResponseCurves::bassAdoption($years - 1e-6, $p, $q)) / 2e-6;
            $this->assertEqualsWithDelta($slope, ResponseCurves::bassAdoptionRate($years, $p, $q), 1e-6);
        }
        $peak = log($q / $p) / ($p + $q);
        $this->assertGreaterThan(ResponseCurves::bassAdoptionRate($peak - 1.0, $p, $q), ResponseCurves::bassAdoptionRate($peak, $p, $q));
        $this->assertGreaterThan(ResponseCurves::bassAdoptionRate($peak + 1.0, $p, $q), ResponseCurves::bassAdoptionRate($peak, $p, $q));
        // Half the market has adopted at the peak less p's head start: F(t*) = (1 - p / q) / 2.
        $this->assertEqualsWithDelta((1.0 - ($p / $q)) / 2.0, ResponseCurves::bassAdoption($peak, $p, $q), 1e-12);
    }
}
