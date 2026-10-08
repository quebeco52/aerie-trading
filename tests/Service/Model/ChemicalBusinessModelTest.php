<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\ChemicalBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ChemicalBusinessModelTest extends TestCase
{
    private ChemicalBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ChemicalBusinessModel();
    }

    public function testImplementsBusinessModelInterface(): void
    {
        $this->assertInstanceOf(BusinessModelInterface::class, $this->model);
    }

    public function testModelThresholdsAndWorkingCapitalIntensity(): void
    {
        $stock = new Stock();
        $stock->setTicker('CHEM');

        $this->assertEquals(0.010, $this->model->getMoatSpread());
        $this->assertEquals(0.12, $this->model->getReversionSpeed());
        $this->assertEquals(0.30, $this->model->getCapExCompletionRate($stock));

        // Default weights: Base Petro 50% (0.24), Specialty 30% (0.18), Agri 20% (0.28)
        // (0.50*0.24) + (0.30*0.18) + (0.20*0.28) = 0.120 + 0.054 + 0.056 = 0.230
        $this->assertEqualsWithDelta(0.230, $this->model->getWorkingCapitalIntensity($stock), 0.001);
        // Trend 2% plus chemical products value added sliding from 2.02% to 1.74% of GDP over 1997-2019.
        $this->assertEqualsWithDelta(0.0132, $this->model->getSecularGrowthRate($stock), 1e-4);
        $this->assertEquals(3.50, $this->model->getCapexCyclicality());

        $surpriseWeights = $this->model->getSurpriseBlendWeights();
        $this->assertEquals(0.45, $surpriseWeights['eps_weight']);
        $this->assertEquals(0.55, $surpriseWeights['revenue_weight']);
    }

    public function testTriStreamRevenueBlendingWithNeutralShocks(): void
    {
        $stock = new Stock();
        $stock->setTicker('CHEM');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            industrialMetalsIndexEma: 100.0,
            agriculturalCommodityIndexEma: 100.0,
            energyPriceIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $mathMock->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 1.0,
            'shock_pct' => null,
            'exponent' => null,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('base_petrochemicals', $result->streamRevenue);
        $this->assertArrayHasKey('specialty_chemicals', $result->streamRevenue);
        $this->assertArrayHasKey('agrochemicals', $result->streamRevenue);

        // Baseline weights: Base Petro 50%, Specialty 30%, Agrochemicals 20%
        $this->assertEqualsWithDelta(50_000_000.0, $result->streamRevenue['base_petrochemicals'], 1.0);
        $this->assertEqualsWithDelta(30_000_000.0, $result->streamRevenue['specialty_chemicals'], 1.0);
        $this->assertEqualsWithDelta(20_000_000.0, $result->streamRevenue['agrochemicals'], 1.0);
        $this->assertEqualsWithDelta(100_000_000.0, $result->actualRevenue, 1.0);
    }

    public function testBasePetrochemicalMacroDemandShift(): void
    {
        $stock = new Stock();
        $stock->setTicker('CHEM');
        $stock->setBeta('1.0');

        $neutralMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            industrialMetalsIndexEma: 100.0,
            agriculturalCommodityIndexEma: 100.0,
            inflationEma: 0.02
        );

        $boomMacro = new MacroStateDTO(
            outputGapEma: 0.05,
            industrialMetalsIndexEma: 130.0, // +30% metals shift
            agriculturalCommodityIndexEma: 100.0,
            inflationEma: 0.02
        );

        $recessionMacro = new MacroStateDTO(
            outputGapEma: -0.05,
            industrialMetalsIndexEma: 70.0, // -30% metals shift
            agriculturalCommodityIndexEma: 100.0,
            inflationEma: 0.02
        );

        // Each scenario gets its own firm. Chemical declares a demand transmission lag, so a firm carries
        // its position in the cycle between calls: running boom and recession through one stock would
        // measure the lag converging, not the demand shift each macro state implies.
        $neutralPhysics = $this->model->getMacroPhysics(clone $stock, $neutralMacro);
        $boomPhysics = $this->model->getMacroPhysics(clone $stock, $boomMacro);
        $recessionPhysics = $this->model->getMacroPhysics(clone $stock, $recessionMacro);

        // Boom demand shift = ((0.05 * 1.60) + (0.30 * 0.60)) * cyclicality * 0.70 = 0.182 * cyclicality
        $cyclicality = ChemicalBusinessModel::OPERATING_CYCLICALITY;
        $this->assertEqualsWithDelta(0.182 * $cyclicality, $boomPhysics['macro_demand_shift'], 0.001);

        // Recession demand shift = ((-0.05 * 1.60) + (-0.30 * 0.60)) * 1.0 * 0.70 = (-0.08 - 0.18) * 0.70 = -0.182
        $this->assertEqualsWithDelta(-0.182 * $cyclicality, $recessionPhysics['macro_demand_shift'], 0.001);
    }

    public function testAgrochemicalsDrivenByWeatherJumps(): void
    {
        $stock = new Stock();
        $stock->setTicker('CHEM');

        $macro = new MacroStateDTO();

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $mathMock->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 1.20, // +20% weather surge jump
            'shock_pct' => 20.0,
            'exponent' => 0.1823,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        // Base revenue = 20M * 1.20 = 24,000,000
        $this->assertEqualsWithDelta(24_000_000.0, $result->streamRevenue['agrochemicals'], 1.0);
    }

    /** Base chemicals are priced off the naphtha cracker: an oil spike at flat gas is a windfall for an ethane slate. */
    public function testAGasAdvantagedCrackerGainsFromAnOilSpikeAtFlatGas(): void
    {
        $ethane = new class extends ChemicalBusinessModel {
            public const GAS_FEEDSTOCK_SHARE = 1.0;
        };

        $calm = $this->feedstockCostRatio($ethane, oil: 0.0, gas: 0.0);
        $oilSpike = $this->feedstockCostRatio($ethane, oil: 0.30, gas: 0.0);

        $this->assertLessThan($calm, $oilSpike, 'Product prices follow the marginal naphtha cracker while the ethane slate costs the same: the cost ratio falls.');
    }

    /** A naphtha slate eats only the share of its oil cost the market price does not pass through. */
    public function testANaphthaCrackerLosesNoMoreThanItsUnpassedShare(): void
    {
        $naphtha = new class extends ChemicalBusinessModel {
            public const GAS_FEEDSTOCK_SHARE = 0.0;
        };
        $oil = 0.30;

        $squeeze = $this->feedstockCostRatio($naphtha, oil: $oil, gas: 0.0) - $this->feedstockCostRatio($naphtha, oil: 0.0, gas: 0.0);
        $unpassedBound = ChemicalBusinessModel::ENERGY_FEEDSTOCK_INTENSITY * $oil
            * (1.0 - (ChemicalBusinessModel::BASE_PETROCHEMICALS_WEIGHT * ChemicalBusinessModel::BASE_PETRO_MARGINAL_PASS_THROUGH));

        $mixed = new ChemicalBusinessModel();
        $mixedSqueeze = $this->feedstockCostRatio($mixed, oil: $oil, gas: 0.0) - $this->feedstockCostRatio($mixed, oil: 0.0, gas: 0.0);

        $this->assertGreaterThan($mixedSqueeze, $squeeze, 'A naphtha slate pays more of an oil spike than a mixed slate: the gas share is read per firm.');
        $this->assertLessThanOrEqual($unpassedBound + 1e-9, $squeeze, 'but no more than the base book leaves unpassed.');
    }

    /** Feedstock falls reach the margin as fully as rises: oil, gas and the farm-price-backed fertilizer leg are all symmetric. */
    public function testAFeedstockFallReachesTheMarginSymmetrically(): void
    {
        $model = new ChemicalBusinessModel();
        $calm = $this->feedstockCostRatio($model, oil: 0.0, gas: 0.0);
        $delta = fn (float $oil, float $gas, float $agri = 100.0): float => $this->feedstockCostRatio($model, oil: $oil, gas: $gas, agriIndex: $agri) - $calm;

        $this->assertLessThan(0.0, $delta(-0.30, 0.0), 'An oil fall lowers the cost ratio.');
        $this->assertLessThan(0.0, $delta(0.0, -0.30), 'A gas fall lowers the cost ratio.');
        $this->assertEqualsWithDelta(-$delta(0.30, 0.0), $delta(-0.30, 0.0), 1e-9, 'Oil moves the margin by the same amount either way.');
        $this->assertEqualsWithDelta(-$delta(0.0, 0.30), $delta(0.0, -0.30), 1e-9, 'Gas moves the margin by the same amount either way.');
        $this->assertEqualsWithDelta(-$delta(0.30, 0.30, 120.0), $delta(-0.30, -0.30, 80.0), 1e-9, 'Fertilizer prices give back with farm prices what they recovered with them.');
    }

    /** The refinery 3:2:1 crack is a gasoline and distillate margin, not a cracker's feedstock cost: it must not move the chemical margin. */
    public function testTheRefiningCrackSpreadDoesNotMoveTheChemicalMargin(): void
    {
        $model = new ChemicalBusinessModel();

        $this->assertEqualsWithDelta(
            $this->feedstockCostRatio($model, oil: 0.0, gas: 0.0),
            $this->feedstockCostRatio($model, oil: 0.0, gas: 0.0, crack: 2.0 * MacroEngine::CRACK_SPREAD_BASELINE),
            1e-12,
        );
    }

    public function testPlantCorrosionAndTurnaroundCompoundingDecay(): void
    {
        $stock = new Stock();
        $stock->setOperatingMargin('0.20');

        // Under-investment (reinvestmentRatio = 0.50, dt = 0.25 => 1 quarter)
        // underinvestment = 0.50
        // decay = 0.020 * 0.50 * 1.0 = 0.010
        // updatedMargin = 0.20 - (0.20 * 0.010) = 0.20 - 0.002 = 0.198
        $this->model->applyAssetDepreciationDecay($stock, reinvestmentRatio: 0.50, dt: 0.25);
        $this->assertEqualsWithDelta(0.198, (float) $stock->getOperatingMargin(), 0.0001);

        // Extreme under-investment (reinvestmentRatio = 0.0, full maintenance deferral)
        // underinvestment = 1.0
        // decay = 0.020 * 1.0 * 1.0 = 0.020
        // updatedMargin = 0.198 - (0.198 * 0.020) = 0.19404
        $this->model->applyAssetDepreciationDecay($stock, reinvestmentRatio: 0.0, dt: 0.25);
        $this->assertEqualsWithDelta(0.19404, (float) $stock->getOperatingMargin(), 0.0001);

        // Over-investment / modernization (reinvestmentRatio = 2.0, dt = 0.25)
        // modGain = 0.010 * ln(2.0) = 0.010 * 0.693147 = 0.00693147
        // updatedMargin = 0.19404 + ((0.30 - 0.19404) * 0.00693147) = 0.19404 + (0.10596 * 0.00693147) = 0.194774
        $this->model->applyAssetDepreciationDecay($stock, reinvestmentRatio: 2.0, dt: 0.25);
        $this->assertEqualsWithDelta(0.194774, (float) $stock->getOperatingMargin(), 0.0001);
    }

    public function testCrackSpreadSqueezeTailRiskEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('CHEM');

        $macro = new MacroStateDTO();

        // 4 calls: basePetroZ, specialtyZ, agriZ, feedstockZ (-2.50 triggers crack spread squeeze)
        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, -2.50);
        $mathMock->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 1.0,
            'shock_pct' => null,
            'exponent' => null,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertEquals(ShockEvent::CHEMICAL_CRACK_SPREAD_SQUEEZE, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testAgriBoomTailRiskEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('CHEM');

        $macro = new MacroStateDTO();

        // 4 calls: basePetroZ, specialtyZ, agriZ (+2.50), feedstockZ (0.0)
        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 2.50, 0.0);
        $mathMock->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 1.25, // weather multiplier > 1.15
            'shock_pct' => 25.0,
            'exponent' => 0.223,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertEquals(ShockEvent::CHEMICAL_AGRI_BOOM, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }


    /**
     * Output is sold into a world market, so a strong domestic currency must reach expected demand through the
     * declared FX exposure rather than being discarded by this model's own macro physics.
     */
    public function testAStrongCurrencyLowersExpectedDemandThroughTheDeclaredFxExposure(): void
    {
        $shift = fn (float $fxIndex): float => $this->model->getMacroPhysics((new Stock())->setTicker('FULM_FX'), new MacroStateDTO(outputGapEma: 0.0, exchangeRateIndexEma: $fxIndex))['macro_demand_shift'];

        $strong = $shift(110.0);
        $flat = $shift(100.0);

        $this->assertEqualsWithDelta(-0.10 * ChemicalBusinessModel::FX_REVENUE_EXPOSURE, $strong - $flat, 1e-9);
        $this->assertLessThan(0.0, $strong - $flat, 'A stronger domestic currency prices exports out of foreign markets.');
    }


    /** On a mixed slate a gas spike squeezes the ethane half with no price relief, so it hurts more than an equal oil spike, which the naphtha-set price partly recovers. */
    public function testAGasSpikeSqueezesAMixedSlateMoreThanAnEqualOilSpike(): void
    {
        $model = new ChemicalBusinessModel();
        $calm = $this->feedstockCostRatio($model, oil: 0.0, gas: 0.0);
        $gasOnly = $this->feedstockCostRatio($model, oil: 0.0, gas: 0.40);
        $oilOnly = $this->feedstockCostRatio($model, oil: 0.40, gas: 0.0);

        $this->assertGreaterThan($calm, $gasOnly, 'A gas spike squeezes the ethane crackers.');
        $this->assertGreaterThan($oilOnly, $gasOnly, 'and more than an equal oil spike, which lifts the naphtha-set product price.');
    }

    /** Variable cost ratio of a flat-draw quarter with oil and gas moved by the given relative deviations. */
    private function feedstockCostRatio(ChemicalBusinessModel $model, float $oil, float $gas, float $agriIndex = 100.0, float $crack = MacroEngine::CRACK_SPREAD_BASELINE): float
    {
        $stock = new Stock();
        $stock->setTicker('FULM_FEED');
        $stock->setBeta('1.0');
        // Partial mock: draws and the weather dice are scripted flat, the jump and mix maths stay real.
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'generatePersistentZ', 'checkProbability'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $math->method('checkProbability')->willReturn(false);
        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            agriculturalCommodityIndexEma: $agriIndex,
            energyCostPushLag: $oil * MacroEngine::ENERGY_COST_PUSH_TRANSMISSION,
            naturalGasPriceIndexEma: MacroEngine::NATURAL_GAS_BASELINE * (1.0 + $gas),
            refiningCrackSpread: $crack,
        );

        return $model->computeActualFinancials($stock, 100_000_000.0, 0.60, 20_000_000.0, 0.15, $macro, $math)->clampedMargin;
    }
}
