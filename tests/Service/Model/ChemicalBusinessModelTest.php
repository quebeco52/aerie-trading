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
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ChemicalBusinessModel();
        $this->mathUtility = new MathUtility();
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
        $this->assertEquals(0.02, $this->model->getSecularGrowthRate($stock));
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

        $neutralPhysics = $this->model->getMacroPhysics($stock, $neutralMacro);
        $boomPhysics = $this->model->getMacroPhysics($stock, $boomMacro);
        $recessionPhysics = $this->model->getMacroPhysics($stock, $recessionMacro);

        // Boom demand shift = ((0.05 * 1.60) + (0.30 * 0.60)) * 1.0 * 0.70 = (0.08 + 0.18) * 0.70 = +0.182
        $this->assertEqualsWithDelta(0.182, $boomPhysics['macro_demand_shift'], 0.001);

        // Recession demand shift = ((-0.05 * 1.60) + (-0.30 * 0.60)) * 1.0 * 0.70 = (-0.08 - 0.18) * 0.70 = -0.182
        $this->assertEqualsWithDelta(-0.182, $recessionPhysics['macro_demand_shift'], 0.001);
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

    public function testFeedstockMarginSqueezeAsymmetricPassThrough(): void
    {
        $stock = new Stock();
        $stock->setTicker('CHEM');
        $stock->setBeta('1.0');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $mathMock->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 1.0,
            'shock_pct' => null,
            'exponent' => null,
        ]);

        // Scenario 1: High energy price (140.0, +40% energy inflation) in Economic Expansion (outputGap = +0.04)
        $expansionMacro = new MacroStateDTO(
            energyPriceIndexEma: 140.0,
            outputGapEma: 0.04
        );

        $expansionResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $expansionMacro,
            mathUtility: $mathMock
        );

        // Scenario 2: High energy price (140.0, +40% energy inflation) in Economic Recession (outputGap = -0.04)
        $recessionMacro = new MacroStateDTO(
            energyPriceIndexEma: 140.0,
            outputGapEma: -0.04
        );

        $recessionResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $recessionMacro,
            mathUtility: $mathMock
        );

        // In a recession, Base Petrochemicals cannot pass through energy inflation -> higher variable cost margin (cost squeeze)
        $this->assertGreaterThan(
            $expansionResult->clampedMargin,
            $recessionResult->clampedMargin,
            'Recession with energy spike must crush margins due to inability of base chemicals to pass through feedstock inflation.'
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
}
