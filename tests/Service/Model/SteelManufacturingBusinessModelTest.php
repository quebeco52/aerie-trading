<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\SteelManufacturingBusinessModel;
use PHPUnit\Framework\TestCase;

class SteelManufacturingBusinessModelTest extends TestCase
{
    private SteelManufacturingBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new SteelManufacturingBusinessModel();
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

    public function testScrapAndOreCostsFollowMetalsWhileFuelAndFreightAreImmaterial(): void
    {
        // BEA 2017: scrap, ore and alloying metals are 23.6% of a mill's variable cost base (its own steel trade excluded);
        // oil and ocean freight sit under InputOutputExposures::MATERIALITY_FLOOR.
        $stock = new Stock();
        $stock->setTicker('STLD');
        $stock->setBeta('1.2');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $run = fn (MacroStateDTO $macro) => $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $macro, $mathMock);

        $base = $run(new MacroStateDTO(energyPriceIndexEma: 100.0, freightRateIndexEma: 100.0, industrialMetalsIndexEma: 100.0));
        $fuelAndFreight = $run(new MacroStateDTO(energyPriceIndexEma: 150.0, energyCostPushLag: 0.50 * MacroEngine::ENERGY_COST_PUSH_TRANSMISSION, freightRateIndexEma: 140.0, industrialMetalsIndexEma: 100.0));
        $metals = $run(new MacroStateDTO(energyPriceIndexEma: 100.0, freightRateIndexEma: 100.0, industrialMetalsIndexEma: 130.0));

        $this->assertEqualsWithDelta($base->clampedMargin, $fuelAndFreight->clampedMargin, 1e-12);
        $this->assertGreaterThan($base->clampedMargin, $metals->clampedMargin, 'dearer scrap and ore raise the cost ratio');
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

    public function testImportDumpingUnderForeignSlowdownAndStrongFx(): void
    {
        $stock = new Stock();
        $stock->setTicker('STLD');
        $stock->setBeta('1.0');

        $neutralMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            foreignOutputGapEma: 0.0,
            exchangeRateIndexEma: 100.0,
            industrialMetalsIndexEma: 100.0
        );

        $dumpingMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            foreignOutputGapEma: -0.04, // Foreign recession (-4%)
            exchangeRateIndexEma: 115.0, // Strong currency (+15% FX strength)
            industrialMetalsIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $neutralMacro,
            mathUtility: $mathMock
        );

        $dumpingResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $dumpingMacro,
            mathUtility: $mathMock
        );

        // Spot HRC revenue drops significantly due to export loss and foreign dumping
        $this->assertLessThan(
            $neutralResult->streamRevenue['spot_hrc_market'],
            $dumpingResult->streamRevenue['spot_hrc_market'],
            'Foreign recession combined with strong domestic currency must compress spot steel revenue via import dumping.'
        );
    }

    /**
     * The cycle reaches tonnage once. The root shift used to carry the lagged gap at 1.5x cyclicality and both
     * streams added another 1.2x of the current gap on half their weight, ~3.15x the gap at cyclicality 1.5
     * where the documented elasticity is 2.25x.
     */
    public function testTheCycleReachesTonnageOnceThroughTheStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('XSTL');
        $stock->setBeta('1.0');
        $quiet = $this->createStub(MathUtility::class);
        $expectedRevenue = 100_000_000.0;

        // Total revenue response: the root shift scales expected revenue, the streams act on it.
        $response = function (float $gap) use ($stock, $quiet, $expectedRevenue): float {
            $state = new MacroStateDTO(outputGapEma: $gap, foreignOutputGapEma: 0.0, exchangeRateIndexEma: 100.0, industrialMetalsIndexEma: 100.0);
            $root = $this->model->getMacroPhysics($stock, $state)['macro_demand_shift'];
            $streams = $this->model->computeActualFinancials($stock, $expectedRevenue, 0.35, 20_000_000.0, 0.10, $state, $quiet)->actualRevenue;

            return $root + ($streams / $expectedRevenue) - 1.0;
        };

        $gap = -0.03;
        $elasticity = SteelManufacturingBusinessModel::OPERATING_CYCLICALITY * SteelManufacturingBusinessModel::INVESTMENT_ACCELERATOR_MULTIPLIER;
        $this->assertEqualsWithDelta($gap * $elasticity, $response($gap) - $response(0.0), 1e-9);

        // The root carries none of it, and the sticky cost base sees the same tonnage through the sector shift.
        $recession = new MacroStateDTO(outputGapEma: $gap, foreignOutputGapEma: 0.0, exchangeRateIndexEma: 100.0);
        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);
        $this->assertEqualsWithDelta($gap * $elasticity, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }
}
