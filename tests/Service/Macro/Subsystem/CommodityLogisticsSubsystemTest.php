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
}
