<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\SpecialtyIndustrialMachineryBusinessModel;
use PHPUnit\Framework\TestCase;

class SpecialtyIndustrialMachineryBusinessModelTest extends TestCase
{
    private SpecialtyIndustrialMachineryBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new SpecialtyIndustrialMachineryBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamEmissionAndFxMetalsSensitivity(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPEC_MACH');
        $stock->setBeta('1.3');

        $baseMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            exchangeRateIndexEma: 100.0,
            industrialMetalsIndexEma: 100.0,
            energyPriceIndexEma: 100.0
        );

        $shockMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            exchangeRateIndexEma: 120.0, // Strong currency export headwind
            industrialMetalsIndexEma: 140.0, // Precision alloy cost drag
            energyPriceIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $baseMacro,
            mathUtility: $mathMock
        );

        $shockResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $shockMacro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('equipment_sales', $baseResult->streamRevenue);
        $this->assertArrayHasKey('aftermarket_services', $baseResult->streamRevenue);

        // Equipment sales drop under strong dollar export headwind
        $this->assertLessThan(
            $baseResult->streamRevenue['equipment_sales'],
            $shockResult->streamRevenue['equipment_sales']
        );

        // Variable cost margin expands (clampedMargin increases) under metals cost drag
        $this->assertGreaterThan(
            $baseResult->clampedMargin,
            $shockResult->clampedMargin
        );
    }

    public function testCapitalStockOverhangDampensEquipmentSales(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPEC_MACH');
        $stock->setBeta('1.0');

        $scarcityMacro = new MacroStateDTO(capitalStockOverhangEma: -0.10);
        $overhangMacro = new MacroStateDTO(capitalStockOverhangEma: 0.10);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $scarcityResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $scarcityMacro, $mathMock);
        $overhangResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $overhangMacro, $mathMock);

        $this->assertGreaterThan(
            $overhangResult->streamRevenue['equipment_sales'],
            $scarcityResult->streamRevenue['equipment_sales'],
            'Excess capital capacity overhang must dampen specialty equipment orders relative to capital scarcity.'
        );
    }
}
