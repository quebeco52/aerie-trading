<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\LogisticsBusinessModel;
use PHPUnit\Framework\TestCase;

class LogisticsBusinessModelTest extends TestCase
{
    private LogisticsBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new LogisticsBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamLogisticsRevenueAndFreightIndexSensitivity(): void
    {
        $stock = new Stock();
        $stock->setTicker('FDX');
        $stock->setBeta('1.2');

        $baseMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            freightRateIndexEma: 100.0,
            energyPriceIndexEma: 100.0
        );

        $highFreightMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            freightRateIndexEma: 150.0, // 50% freight rate surge
            energyPriceIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $baseMacro,
            mathUtility: $mathMock
        );

        $highFreightResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $highFreightMacro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('dedicated_fleet_contracts', $baseResult->streamRevenue);
        $this->assertArrayHasKey('spot_freight_brokerage', $baseResult->streamRevenue);
        $this->assertArrayHasKey('value_added_warehousing', $baseResult->streamRevenue);

        // High freight rate increases total revenue
        $this->assertGreaterThan($baseResult->actualRevenue, $highFreightResult->actualRevenue);
        // Spot brokerage captures the freight rate surge
        $this->assertGreaterThan($baseResult->streamRevenue['spot_freight_brokerage'], $highFreightResult->streamRevenue['spot_freight_brokerage']);
        // Dedicated fleet contracts are fixed commitments and unaffected by spot charter fluctuations
        $this->assertEquals($baseResult->streamRevenue['dedicated_fleet_contracts'], $highFreightResult->streamRevenue['dedicated_fleet_contracts']);
    }

    public function testGetMacroPhysicsCalculatesDemandShiftAndPricingPower(): void
    {
        $stock = new Stock();
        $stock->setTicker('FDX');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO(
            outputGapEma: 0.02,
            inflationEma: 0.03
        );

        $physics = $this->model->getMacroPhysics($stock, $macro);

        $this->assertArrayHasKey('macro_demand_shift', $physics);
        $this->assertArrayHasKey('pricing_power_multiplier', $physics);

        // Expected demand shift: 0.02 * 1.2 * 1.40 = 0.0336
        $this->assertEqualsWithDelta(0.0336, $physics['macro_demand_shift'], 0.0001);
        // Expected pricing power: 1.0 + (0.03 * 1.2) = 1.036
        $this->assertEqualsWithDelta(1.036, $physics['pricing_power_multiplier'], 0.0001);
    }

    public function testFuelSurchargeLagCompressesMarginOnEnergySpike(): void
    {
        $stock = new Stock();
        $stock->setTicker('UPS');
        $stock->setBeta('1.0');

        $baseMacro = new MacroStateDTO(energyPriceIndexEma: 100.0);
        $spikeMacro = new MacroStateDTO(energyPriceIndexEma: 160.0);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $spikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $spikeMacro, $mathMock);

        // Energy spike increases variable cost ratio (clampedMargin)
        $this->assertGreaterThan($baseResult->clampedMargin, $spikeResult->clampedMargin);
    }
}
