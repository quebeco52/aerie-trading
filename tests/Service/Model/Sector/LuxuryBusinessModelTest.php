<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Model\Sector\LuxuryBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class LuxuryBusinessModelTest extends TestCase
{
    public function testCulturalDominanceShockEventIsAssigned(): void
    {
        $model = new LuxuryBusinessModel();
        $stock = new Stock();
        $stock->setTicker('LUX');
        $stock->setBeta('1.0');

        // sequence: firmFactor=0, hauteZ=0.0, accessibleZ=0.0, eventZ=2.6 (> BRAND_BOOM_Z_SCORE), analystError=0.0
        $mathUtilityMock = new ScriptedDraws(generateStandardNormal: [0.0, 0.0, 0.0, 2.6, 0.0]);

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
