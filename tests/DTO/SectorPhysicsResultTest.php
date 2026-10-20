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
            eventType: 'SUPPLY_CHAIN_CRUNCH',
            eventContext: ['region' => 'Asia'],
            isPublicEvent: true,
        );

        $this->assertSame(1000.0, $result->actualRevenue);
        $this->assertSame(0.35, $result->rawVariableMargin);
        $this->assertSame('SUPPLY_CHAIN_CRUNCH', $result->eventType);
        $this->assertSame(['region' => 'Asia'], $result->eventContext);
        $this->assertTrue($result->isPublicEvent);
    }
}
