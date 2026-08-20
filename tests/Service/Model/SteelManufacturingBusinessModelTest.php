<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\SteelManufacturingBusinessModel;
use PHPUnit\Framework\TestCase;

class SteelManufacturingBusinessModelTest extends TestCase
{
    private SteelManufacturingBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new SteelManufacturingBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamRevenueAndMetalsPriceSensitivity(): void
    {
        $stock = new Stock();
        $stock->setTicker('STLD');
        $stock->setBeta('1.4');

        $baseMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            industrialMetalsIndexEma: 100.0,
            energyPriceIndexEma: 100.0,
            freightRateIndexEma: 100.0
        );

        $metalsSurgeMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            industrialMetalsIndexEma: 150.0, // 50% spike in steel/metals prices
            energyPriceIndexEma: 100.0,
            freightRateIndexEma: 100.0
        );

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $baseMacro,
            mathUtility: $mathMock
        );

        $metalsSurgeResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $metalsSurgeMacro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('contracted_oem_steel', $baseResult->streamRevenue);
        $this->assertArrayHasKey('spot_hrc_market', $baseResult->streamRevenue);

        // Spot HRC revenue surges when industrial metals benchmark rises
        $this->assertGreaterThan(
            $baseResult->streamRevenue['spot_hrc_market'],
            $metalsSurgeResult->streamRevenue['spot_hrc_market']
        );
    }

    public function testEnergyAndFreightCostDragOnMargins(): void
    {
        $stock = new Stock();
        $stock->setTicker('STLD');
        $stock->setBeta('1.2');

        $baseMacro = new MacroStateDTO(energyPriceIndexEma: 100.0, freightRateIndexEma: 100.0);
        $costSpikeMacro = new MacroStateDTO(energyPriceIndexEma: 150.0, freightRateIndexEma: 140.0);

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $costSpikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $costSpikeMacro, $mathMock);

        // Energy and freight drag compress variable margin (lowering the margin profit factor / increasing cost ratio)
        $this->assertLessThan($baseResult->clampedMargin, $costSpikeResult->clampedMargin);
    }
}
