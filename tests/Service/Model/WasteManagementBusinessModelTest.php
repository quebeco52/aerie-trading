<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\WasteManagementBusinessModel;
use PHPUnit\Framework\TestCase;

class WasteManagementBusinessModelTest extends TestCase
{
    private WasteManagementBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new WasteManagementBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamArchitectureAndShares(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORM');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.08,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('residential_collection', $result->streamRevenue);
        $this->assertArrayHasKey('commercial_disposal', $result->streamRevenue);
        $this->assertArrayHasKey('recycling_and_rng', $result->streamRevenue);

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['residential_collection'] + $result->streamRevenue['commercial_disposal'] + $result->streamRevenue['recycling_and_rng'],
            1.0
        );
    }

    public function testIndustrialMetalsAndEnergySpikeExpandsRecyclingRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORM');
        $stock->setBeta('0.8');

        $normalMacro = new MacroStateDTO(
            energyPriceIndexEma: 100.0,
            industrialMetalsIndexEma: 100.0
        );

        $spikeMacro = new MacroStateDTO(
            energyPriceIndexEma: 150.0,
            industrialMetalsIndexEma: 180.0
        );

        $normalResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $normalMacro,
            mathUtility: $this->mathUtility
        );

        $spikeResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $spikeMacro,
            mathUtility: $this->mathUtility
        );

        $this->assertGreaterThan(
            $normalResult->streamRevenue['recycling_and_rng'],
            $spikeResult->streamRevenue['recycling_and_rng'],
            'Industrial metals and energy price surges must expand scrap and commodity recycling revenue.'
        );
    }

    public function testEnergySpikeCompressesMarginsDueToFuelSurchargeLag(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORM');
        $stock->setBeta('1.0');

        $calmMacro = new MacroStateDTO(energyPriceIndexEma: 100.0);
        $energyShockMacro = new MacroStateDTO(energyPriceIndexEma: 200.0);

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $calmResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $calmMacro, $mathMock);
        $shockResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $energyShockMacro, $mathMock);

        $this->assertGreaterThan(
            $calmResult->clampedMargin,
            $shockResult->clampedMargin,
            'Energy price spikes cause diesel fuel surcharge lag, expanding variable cost ratio.'
        );
        $this->assertLessThan($calmResult->ebit, $shockResult->ebit);
    }
}
