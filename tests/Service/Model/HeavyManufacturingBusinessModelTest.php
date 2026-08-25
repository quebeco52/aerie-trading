<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\HeavyManufacturingBusinessModel;
use PHPUnit\Framework\TestCase;

class HeavyManufacturingBusinessModelTest extends TestCase
{
    private HeavyManufacturingBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new HeavyManufacturingBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamEmissionAndBacklogDamping(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO(outputGapEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('oem_equipment', $result->streamRevenue);
        $this->assertArrayHasKey('aftermarket_mro', $result->streamRevenue);
        $this->assertArrayHasKey('oem_equipment', $result->streamZ);
        $this->assertArrayHasKey('aftermarket_mro', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['oem_equipment']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['aftermarket_mro']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['oem_equipment'] + $result->streamRevenue['aftermarket_mro'],
            1.0
        );
    }

    public function testExchangeRateExportDragAndFreightCostPenalty(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.2');

        $baseMacro = new MacroStateDTO(exchangeRateIndexEma: 100.0, freightRateIndexEma: 100.0);
        $shockMacro = new MacroStateDTO(exchangeRateIndexEma: 120.0, freightRateIndexEma: 140.0);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $shockResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $shockMacro, $mathMock);

        $this->assertLessThan($baseResult->streamRevenue['oem_equipment'], $shockResult->streamRevenue['oem_equipment']);
        $this->assertGreaterThan($baseResult->clampedMargin, $shockResult->clampedMargin);
    }

    public function testCapitalStockOverhangDampensOemEquipmentDemand(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.0');

        $scarcityMacro = new MacroStateDTO(capitalStockOverhangEma: -0.10);
        $overhangMacro = new MacroStateDTO(capitalStockOverhangEma: 0.10);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $scarcityResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $scarcityMacro, $mathMock);
        $overhangResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $overhangMacro, $mathMock);

        $this->assertGreaterThan(
            $overhangResult->streamRevenue['oem_equipment'],
            $scarcityResult->streamRevenue['oem_equipment'],
            'Industrial capital capacity overhang must dampen OEM equipment demand relative to capital scarcity.'
        );
    }
}
