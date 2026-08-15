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
}
