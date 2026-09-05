<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class CommodityLogisticsSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private CommodityLogisticsSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $this->subsystem = new CommodityLogisticsSubsystem($this->mathUtility);
    }

    public function testCommodityAndFreightIndicesStayWithinSensibleBounds(): void
    {
        $state = new MacroState();
        $state->energyPriceIndex = 100.0;
        $state->industrialMetalsIndex = 100.0;
        $state->agriculturalCommodityIndex = 100.0;
        $state->freightRateIndex = 100.0;
        $state->freightSupplyEma = 100.0;

        $this->subsystem->calculateEnergyShock($state, 0.25);
        $this->subsystem->calculateIndustrialMetalsIndex($state, 0.25);
        $this->subsystem->calculateAgriculturalCommodityIndex($state, 0.25);
        $this->subsystem->calculateFreightRateIndex($state, 0.25);

        $this->assertGreaterThan(10.0, $state->energyPriceIndex);
        $this->assertGreaterThan(20.0, $state->industrialMetalsIndex);
        $this->assertGreaterThan(20.0, $state->agriculturalCommodityIndex);
        $this->assertGreaterThan(20.0, $state->freightRateIndex);
    }

    public function testConvenienceYieldSpikesWhenPhysicalInventoryDrawsDown(): void
    {
        $ampleYield = $this->mathUtility->calculateConvenienceYield(105.0, 50.0);
        $this->assertEquals(0.0, $ampleYield, 'Ample buffer stocks must have zero convenience yield (contango)');

        $tightYield = $this->mathUtility->calculateConvenienceYield(75.0, 50.0);
        $criticalYield = $this->mathUtility->calculateConvenienceYield(55.0, 50.0);

        $this->assertGreaterThan(0.0, $tightYield);
        $this->assertGreaterThan(3.0 * $tightYield, $criticalYield, 'Critical inventory depletion must spike convenience yield non-linearly');

        $state = new MacroState();
        $state->energyPriceIndex = 100.0;
        $state->energyInventoryIndex = 55.0; // Very tight inventory buffer

        $this->subsystem->calculateEnergyShock($state, 0.25);
        $this->assertGreaterThan(0.0, $state->energyPriceShock, 'Depleted buffer inventory must generate backwardation price shock');
    }
}
