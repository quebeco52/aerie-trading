<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\StreamContext;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class StreamContextTest extends TestCase
{
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
    }

    public function testResolveActiveStreamWeightsOnInitialRun(): void
    {
        $context = new StreamContext([], $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $weights = $context->resolveActiveStreamWeights($targets);

        $this->assertEqualsWithDelta(0.60, $weights['stream_a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $weights['stream_b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $weights['stream_c'], 0.0001);
        $this->assertEqualsWithDelta(1.0, array_sum($weights), 0.0001);

        $streamZ = $context->getStreamZ();
        $this->assertEqualsWithDelta(0.60, $streamZ['weight:stream_a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['weight:stream_b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['weight:stream_c'], 0.0001);
    }

    public function testResolveActiveStreamWeightsWithRealizedShareDrift(): void
    {
        // Previous quarter had stream_c surge to 60% of sales
        $previousMomentum = [
            'weight:stream_a' => 0.60,
            'weight:stream_b' => 0.20,
            'weight:stream_c' => 0.20,
            'share:stream_a'  => 0.25,
            'share:stream_b'  => 0.15,
            'share:stream_c'  => 0.60,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $active = $context->resolveActiveStreamWeights(
            $targets,
            adaptationRate: 0.15,
            reversionSpeed: 0.08
        );

        // Stream C should have drifted above 0.20
        $this->assertGreaterThan(0.20, $active['stream_c']);
        // Stream A should have drifted below 0.60
        $this->assertLessThan(0.60, $active['stream_a']);
        // Simplex property
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testResolveActiveStreamWeightsStrategicMeanReversion(): void
    {
        // Stream C was previously expanded to 0.40, but realized share dropped back to target 0.20
        $previousMomentum = [
            'weight:stream_a' => 0.45,
            'weight:stream_b' => 0.15,
            'weight:stream_c' => 0.40,
            'share:stream_a'  => 0.60,
            'share:stream_b'  => 0.20,
            'share:stream_c'  => 0.20,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $active = $context->resolveActiveStreamWeights(
            $targets,
            adaptationRate: 0.15,
            reversionSpeed: 0.08
        );

        // Stream C active weight should pull back toward 0.20 (below previous 0.40)
        $this->assertLessThan(0.40, $active['stream_c']);
        // Stream A active weight should pull up toward 0.60 (above previous 0.45)
        $this->assertGreaterThan(0.45, $active['stream_a']);
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testResolveActiveStreamWeightsClampingBounds(): void
    {
        // Extreme past share of 100% on stream_c
        $previousMomentum = [
            'weight:stream_a' => 0.10,
            'weight:stream_b' => 0.10,
            'weight:stream_c' => 0.80,
            'share:stream_a'  => 0.00,
            'share:stream_b'  => 0.00,
            'share:stream_c'  => 1.00,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $active = $context->resolveActiveStreamWeights(
            $targets,
            adaptationRate: 0.50,
            reversionSpeed: 0.00,
            minFloor: 0.05,
            maxCeiling: 0.85
        );

        $this->assertGreaterThanOrEqual(0.05, $active['stream_a']);
        $this->assertGreaterThanOrEqual(0.05, $active['stream_b']);
        $this->assertLessThanOrEqual(0.90, $active['stream_c']);
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testRecordStreamShares(): void
    {
        $context = new StreamContext([], $this->mathUtility);

        $revenues = [
            'stream_a' => 6000.0,
            'stream_b' => 2000.0,
            'stream_c' => 2000.0,
        ];

        $context->recordStreamShares($revenues);

        $streamZ = $context->getStreamZ();
        $this->assertEqualsWithDelta(0.60, $streamZ['share:stream_a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['share:stream_b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['share:stream_c'], 0.0001);
    }

    public function testResolveActiveStreamWeightsWithZeroTargetWeightDoesNotClampToFloor(): void
    {
        // Quarter 2 with past momentum for a pure-play firm with a 0% stream (e.g., PERE with 0% advisory)
        $previousMomentum = [
            'weight:advisory'               => 0.00,
            'weight:trading'                => 0.40,
            'weight:options_premium_income' => 0.60,
            'share:advisory'                => 0.00,
            'share:trading'                 => 0.40,
            'share:options_premium_income'  => 0.60,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'advisory'               => 0.00,
            'trading'                => 0.40,
            'options_premium_income' => 0.60,
        ];

        $active = $context->resolveActiveStreamWeights($targets);

        $this->assertSame(0.0, $active['advisory'], 'Stream with 0.0 target must remain strictly 0.0 and never clamp to the 5% floor.');
        $this->assertEqualsWithDelta(0.40, $active['trading'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $active['options_premium_income'], 0.0001);
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testResolveActiveStreamWeightsWithSubFloorTargetWeight(): void
    {
        // Target weight of 2% (0.02) which is below the default 5% minFloor
        $previousMomentum = [
            'weight:core'  => 0.98,
            'weight:niche' => 0.02,
            'share:core'   => 0.98,
            'share:niche'  => 0.02,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'core'  => 0.98,
            'niche' => 0.02,
        ];

        $active = $context->resolveActiveStreamWeights($targets);

        $this->assertEqualsWithDelta(0.02, $active['niche'], 0.0001, 'A 2% target stream must not be artificially forced up to 5%.');
        $this->assertEqualsWithDelta(0.98, $active['core'], 0.0001);
    }

    public function testResolveActiveStreamWeightsWithHighConcentrationTarget(): void
    {
        // Target weight of 90% (0.90) which is above the default 85% maxCeiling
        $previousMomentum = [
            'weight:dominant'  => 0.90,
            'weight:secondary' => 0.10,
            'share:dominant'   => 0.90,
            'share:secondary'  => 0.10,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'dominant'  => 0.90,
            'secondary' => 0.10,
        ];

        $active = $context->resolveActiveStreamWeights($targets);

        $this->assertEqualsWithDelta(0.90, $active['dominant'], 0.0001, 'A 90% dominant target stream must not be capped at 85%.');
        $this->assertEqualsWithDelta(0.10, $active['secondary'], 0.0001);
    }
}
