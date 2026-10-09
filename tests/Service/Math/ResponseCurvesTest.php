<?php

namespace App\Tests\Service\Math;

use App\Service\Math\ResponseCurves;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\ResponseCurves.
 */
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

    public function testExcessOverBaselineIsTheFlooredRelativeExcess(): void
    {
        $this->assertEqualsWithDelta(0.5, ResponseCurves::excessOverBaseline(0.03, 0.02), 1e-12);
        $this->assertSame(0.0, ResponseCurves::excessOverBaseline(0.01, 0.02));
        $this->assertSame(0.0, ResponseCurves::excessOverBaseline(0.02, 0.02));
    }
}
