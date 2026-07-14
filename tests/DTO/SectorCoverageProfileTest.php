<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\SectorCoverageProfile;
use PHPUnit\Framework\TestCase;

class SectorCoverageProfileTest extends TestCase
{
    public function testSectorCoverageProfileDefaultsAndCustomValues(): void
    {
        $defaultProfile = new SectorCoverageProfile(
            baseVisibility: 0.60,
            errorStdDev: 0.05
        );

        $this->assertSame(0.60, $defaultProfile->baseVisibility);
        $this->assertSame(0.05, $defaultProfile->errorStdDev);
        $this->assertSame(0.0, $defaultProfile->minVisibility);
        $this->assertNull($defaultProfile->eventBaseVisibility);
        $this->assertNull($defaultProfile->eventMinVisibility);

        $eventProfile = new SectorCoverageProfile(
            baseVisibility: 0.10,
            errorStdDev: 0.04,
            minVisibility: 0.05,
            eventBaseVisibility: 0.85,
            eventMinVisibility: 0.40
        );

        $this->assertSame(0.10, $eventProfile->baseVisibility);
        $this->assertSame(0.04, $eventProfile->errorStdDev);
        $this->assertSame(0.05, $eventProfile->minVisibility);
        $this->assertSame(0.85, $eventProfile->eventBaseVisibility);
        $this->assertSame(0.40, $eventProfile->eventMinVisibility);
    }
}
