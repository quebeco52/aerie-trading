<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\ActualFinancialsDTO;
use PHPUnit\Framework\TestCase;

class ActualFinancialsDTOTest extends TestCase
{
    public function testActualFinancialsDTOPropertiesAndImmutability(): void
    {
        $dto = new ActualFinancialsDTO(
            actualRevenue: 5000.0,
            actualVariableCosts: 2000.0,
            clampedMargin: 0.40,
            ebit: 1500.0,
            primaryShockZ: 1.25,
            observableShockZ: 0.50,
            eventType: 'TAIL_RISK_EVENT',
            eventContext: ['severity' => 'high'],
            isPublicEvent: true
        );

        $this->assertSame(5000.0, $dto->actualRevenue);
        $this->assertSame(2000.0, $dto->actualVariableCosts);
        $this->assertSame(0.40, $dto->clampedMargin);
        $this->assertSame(1500.0, $dto->ebit);
        $this->assertSame(1.25, $dto->primaryShockZ);
        $this->assertSame(0.50, $dto->observableShockZ);
        $this->assertSame('TAIL_RISK_EVENT', $dto->eventType);
        $this->assertSame(['severity' => 'high'], $dto->eventContext);
        $this->assertTrue($dto->isPublicEvent);
    }
}
