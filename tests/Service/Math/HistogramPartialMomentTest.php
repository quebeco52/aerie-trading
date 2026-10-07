<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class HistogramPartialMomentTest extends TestCase
{
    private const EDGES = CreditFiscalSubsystem::NEW_PURCHASE_CLTV_EDGES;
    private const SHARES = CreditFiscalSubsystem::NEW_PURCHASE_CLTV_SHARES;

    /** Below every bin the moment is the mean (NMDB's 82.2%), above every bin it is zero, and the tail share runs 1 to 0. */
    public function testTheMomentRunsFromTheMeanToNothing(): void
    {
        $this->assertEqualsWithDelta(0.822, MathUtility::histogramUpperPartialMoment(self::EDGES, self::SHARES, 0.0), 5e-4);
        $this->assertSame(0.0, MathUtility::histogramUpperPartialMoment(self::EDGES, self::SHARES, 1.02));
        $this->assertSame(0.0, MathUtility::histogramUpperPartialMoment(self::EDGES, self::SHARES, 1.20));
        $this->assertEqualsWithDelta(1.0, MathUtility::histogramUpperTailShare(self::EDGES, self::SHARES, 0.0), 1e-12);
        $this->assertSame(0.0, MathUtility::histogramUpperTailShare(self::EDGES, self::SHARES, 1.02));
    }

    /** One bin, uniform on [0, 1]: E[max(0, X - t)] = (1 - t)^2 / 2 and P(X > t) = 1 - t. */
    public function testAUniformBinHasTheClosedForm(): void
    {
        foreach ([0.0, 0.25, 0.5, 0.9] as $t) {
            $this->assertEqualsWithDelta(((1.0 - $t) ** 2) / 2.0, MathUtility::histogramUpperPartialMoment([0.0, 1.0], [3.0], $t), 1e-12);
            $this->assertEqualsWithDelta(1.0 - $t, MathUtility::histogramUpperTailShare([0.0, 1.0], [3.0], $t), 1e-12);
        }
    }

    /** The moment's slope is minus the share above the threshold, so it falls and is convex; at 90% the share is NMDB's 44%. */
    public function testTheSlopeIsMinusTheShareAbove(): void
    {
        $h = 1e-6;
        foreach ([0.72, 0.80, 0.85, 0.90, 0.93, 0.96, 1.00] as $t) {
            $slope = (MathUtility::histogramUpperPartialMoment(self::EDGES, self::SHARES, $t + $h) - MathUtility::histogramUpperPartialMoment(self::EDGES, self::SHARES, $t - $h)) / (2.0 * $h);
            $this->assertEqualsWithDelta(-MathUtility::histogramUpperTailShare(self::EDGES, self::SHARES, $t), $slope, 1e-6, "at {$t}");
        }
        $this->assertEqualsWithDelta(0.437, MathUtility::histogramUpperTailShare(self::EDGES, self::SHARES, 0.90), 1e-3);
        $this->assertEqualsWithDelta(0.0277, MathUtility::histogramUpperPartialMoment(self::EDGES, self::SHARES, 0.90), 1e-4);
    }
}
