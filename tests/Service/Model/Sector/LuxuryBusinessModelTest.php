<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\LuxuryBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LuxuryBusinessModelTest extends TestCase
{
    public function testVeblenBrandCachetElasticityImprovesMargin(): void
    {
        $model = new LuxuryBusinessModel();
        $stock = new Stock();
        $stock->setTicker('LUX');
        $stock->setBeta('1.2');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // sequence: firmFactor=0, hauteZ idio=2.5 (composite 2.0 after the 0.8 idiosyncratic weight),
        // accessibleZ=0, eventZ=0, analystError=0
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 2.5, 0.0, 0.0, 0.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray(['inflation_ema' => 0.02]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.35,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        // Veblen pricing cachet reduces variable cost percentage below 35%
        $this->assertLessThan(1000.0 * 0.35, $result->actualVariableCosts);
    }

    public function testCulturalDominanceShockEventIsAssigned(): void
    {
        $model = new LuxuryBusinessModel();
        $stock = new Stock();
        $stock->setTicker('LUX');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // sequence: firmFactor=0, hauteZ=0.0, accessibleZ=0.0, eventZ=2.6 (> BRAND_BOOM_Z_SCORE), analystError=0.0
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, 2.6, 0.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray(['inflation_ema' => 0.02]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.35,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertSame(ShockEvent::LUXURY_CULTURAL_DOMINANCE, $result->eventType);
    }
}
