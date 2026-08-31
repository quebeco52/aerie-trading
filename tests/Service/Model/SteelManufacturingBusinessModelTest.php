<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\SteelManufacturingBusinessModel;
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

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $costSpikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $costSpikeMacro, $mathMock);

        // Energy and freight cost spikes increase the variable cost ratio (clampedMargin) and compress operating income (EBIT)
        $this->assertGreaterThan($baseResult->clampedMargin, $costSpikeResult->clampedMargin);
        $this->assertLessThan($baseResult->ebit, $costSpikeResult->ebit);
    }

    public function testBlastFurnaceAgingAndEafModernization(): void
    {
        // Underinvestment (R = 0.5) -> Blast furnace thermal wear
        $stock = new Stock();
        $stock->setOperatingMargin('0.20');
        $this->model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.20, $decayed);
        $this->assertGreaterThanOrEqual(SteelManufacturingBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // Modernization (R = 1.5) -> Electric arc furnace efficiency
        $stock->setOperatingMargin('0.20');
        $this->model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.20, $expanded);
        $this->assertLessThanOrEqual(SteelManufacturingBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testCyclicalSteelBookValueAnchoring(): void
    {
        $earningsValue = 10.0;
        $pbFairValue = 40.0; // Blast furnace physical replacement value

        // 1. Trough regime (negative normalized EPS) -> 70% book value weight
        $troughValue = $this->model->calculateFairValue($earningsValue, $pbFairValue, -0.50);
        // (10 * 0.30) + (40 * 0.70) = 3 + 28 = 31.0
        $this->assertEqualsWithDelta(31.0, $troughValue, 0.01);

        // 2. Expansion regime (positive normalized EPS) -> 40% book value weight
        $boomValue = $this->model->calculateFairValue($earningsValue, $pbFairValue, 1.50);
        // (10 * 0.60) + (40 * 0.40) = 6 + 16 = 22.0
        $this->assertEqualsWithDelta(22.0, $boomValue, 0.01);
    }
}
