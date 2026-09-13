<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\DistrictHeightEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * The one mapping every consumer of facade height shares: linear in log10 across the window,
 * clamped outside it, and never a window with no width to divide by.
 */
class DistrictHeightEnvelopeTest extends TestCase
{
    public function testNormaliseIsLinearInLogAcrossTheWindow(): void
    {
        $envelope = new DistrictHeightEnvelope(11.0, 13.0);

        $this->assertEqualsWithDelta(0.0, $envelope->normalise(1.0e11), 1.0e-9);
        $this->assertEqualsWithDelta(0.5, $envelope->normalise(1.0e12), 1.0e-9);
        $this->assertEqualsWithDelta(1.0, $envelope->normalise(1.0e13), 1.0e-9);
        $this->assertEqualsWithDelta(log10(2.0) / 2.0, $envelope->normalise(2.0e11), 1.0e-9);
    }

    public function testNormaliseClampsOutsideTheWindow(): void
    {
        $envelope = new DistrictHeightEnvelope(11.0, 13.0);

        $this->assertSame(0.0, $envelope->normalise(1.0e9));
        $this->assertSame(0.0, $envelope->normalise(0.0), 'A zero cap has no log and must land on the floor, not NAN');
        $this->assertSame(1.0, $envelope->normalise(1.0e15));
    }

    public function testSpanIsTheWindowWidth(): void
    {
        $this->assertEqualsWithDelta(2.0, (new DistrictHeightEnvelope(11.0, 13.0))->span(), 1.0e-9);
    }

    public function testACollapsedWindowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DistrictHeightEnvelope(12.0, 12.0);
    }

    public function testToArrayShipsExactlyTheFieldsTheClientMirrorReads(): void
    {
        $this->assertSame(
            ['logFloor' => 11.0, 'logCeiling' => 13.0],
            (new DistrictHeightEnvelope(11.0, 13.0))->toArray(),
        );
    }
}
