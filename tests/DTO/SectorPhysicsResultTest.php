<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\SectorPhysicsResult;
use PHPUnit\Framework\TestCase;

class SectorPhysicsResultTest extends TestCase
{
    public function testSectorPhysicsResultProperties(): void
    {
        $result = new SectorPhysicsResult(
            actualRevenue: 1000.0,
            rawVariableMargin: 0.35,
            primaryShockZ: -1.5,
            observableShockZ: -0.5,
            eventType: 'SUPPLY_CHAIN_CRUNCH',
            eventContext: ['region' => 'Asia'],
            isPublicEvent: true
        );

        $this->assertSame(1000.0, $result->actualRevenue);
        $this->assertSame(0.35, $result->rawVariableMargin);
        $this->assertSame(-1.5, $result->primaryShockZ);
        $this->assertSame(-0.5, $result->observableShockZ);
        $this->assertSame('SUPPLY_CHAIN_CRUNCH', $result->eventType);
        $this->assertSame(['region' => 'Asia'], $result->eventContext);
        $this->assertTrue($result->isPublicEvent);
    }
}
