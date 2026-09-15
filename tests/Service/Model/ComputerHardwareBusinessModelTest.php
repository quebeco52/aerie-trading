<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ComputerHardwareBusinessModel;
use PHPUnit\Framework\TestCase;

class ComputerHardwareBusinessModelTest extends TestCase
{
    private ComputerHardwareBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ComputerHardwareBusinessModel();
    }

    public function testDualStreamRevenueAndFxSensitivity(): void
    {
        $stock = new Stock();
        $stock->setTicker('DELL');
        $stock->setBeta('1.1');

        $baseMacro = new MacroStateDTO(
            exchangeRateIndexEma: 100.0,
            industrialMetalsIndexEma: 100.0
        );

        $strongDollarMacro = new MacroStateDTO(
            exchangeRateIndexEma: 120.0, // Strong domestic currency creates export headwind
            industrialMetalsIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
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

        $strongDollarResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $strongDollarMacro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('enterprise_hardware', $baseResult->streamRevenue);
        $this->assertArrayHasKey('consumer_hardware', $baseResult->streamRevenue);

        // Strong dollar dampens foreign/export demand
        $this->assertLessThan($baseResult->actualRevenue, $strongDollarResult->actualRevenue);
    }

    public function testIndustrialMetalsCostDragOnMargins(): void
    {
        $stock = new Stock();
        $stock->setTicker('HPE');
        $stock->setBeta('1.0');

        $baseMacro = new MacroStateDTO(industrialMetalsIndexEma: 100.0);
        $metalsSpikeMacro = new MacroStateDTO(industrialMetalsIndexEma: 150.0);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $metalsSpikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $metalsSpikeMacro, $mathMock);

        // Industrial metals cost drag increases variable cost ratio (clampedMargin)
        $this->assertGreaterThan($baseResult->clampedMargin, $metalsSpikeResult->clampedMargin);
    }
}
