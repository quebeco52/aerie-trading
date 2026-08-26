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
use App\Service\Model\Sector\ApparelManufacturingBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ApparelManufacturingBusinessModelTest extends TestCase
{
    private ApparelManufacturingBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ApparelManufacturingBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testImplementsBusinessModelInterface(): void
    {
        $this->assertInstanceOf(BusinessModelInterface::class, $this->model);
    }

    public function testModelThresholdsAndWorkingCapitalIntensity(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHER');

        $thresholds = $this->model->getModelThresholds();
        $this->assertEquals(0.22, $thresholds['nwc_intensity']);
        $this->assertEquals(0.008, $thresholds['moat_spread']);
        $this->assertEquals(0.15, $thresholds['reversion_speed']);
        $this->assertEquals(0.25, $thresholds['capex_completion_rate']);

        $this->assertEqualsWithDelta(0.229, $this->model->getWorkingCapitalIntensity($stock), 0.01);
        $this->assertEquals(0.025, $this->model->getSecularGrowthRate($stock));

        $surpriseWeights = $this->model->getSurpriseBlendWeights();
        $this->assertEquals(0.50, $surpriseWeights['eps_weight']);
        $this->assertEquals(0.50, $surpriseWeights['revenue_weight']);
    }

    public function testTriStreamRevenueBlendingWithNeutralShocks(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHER');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(
            exchangeRateIndexEma: 100.0,
            agriculturalCommodityIndexEma: 100.0,
            freightRateIndexEma: 100.0,
            energyPriceIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.22,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('dtc_retail', $result->streamRevenue);
        $this->assertArrayHasKey('wholesale_channel', $result->streamRevenue);
        $this->assertArrayHasKey('contract_textile_supply', $result->streamRevenue);

        // Default weights: DTC 30%, Wholesale 40%, Contract 30%
        $this->assertEqualsWithDelta(30_000_000.0, $result->streamRevenue['dtc_retail'], 1.0);
        $this->assertEqualsWithDelta(40_000_000.0, $result->streamRevenue['wholesale_channel'], 1.0);
        $this->assertEqualsWithDelta(30_000_000.0, $result->streamRevenue['contract_textile_supply'], 1.0);
        $this->assertEqualsWithDelta(100_000_000.0, $result->actualRevenue, 1.0);
    }

    public function testMacroPhysicsTradeDownCounterCyclicality(): void
    {
        // 1. Generic apparel stock (default pricing power = 0.50)
        $genericStock = new Stock();
        $genericStock->setTicker('GENERIC_APPAREL');
        $genericStock->setBeta('1.0');

        // Recession: Negative output gap (-0.05), weak consumer sentiment (80.0)
        $recessionMacro = new MacroStateDTO(
            outputGapEma: -0.05,
            consumerSentimentIndexEma: 80.0,
            inflationEma: 0.02
        );

        $genericPhysics = $this->model->getMacroPhysics($genericStock, $recessionMacro);

        // Pro-cyclical drag = (-0.05 * 0.70) + (-0.20 * 0.40) = -0.035 - 0.08 = -0.115
        // Generic trade-down bonus = 0.05 * 0.60 * (1.5 - 0.5) = +0.030
        // Blended demand shift = -0.115 + 0.030 = -0.085
        $this->assertEqualsWithDelta(-0.085, $genericPhysics['macro_demand_shift'], 0.0001);
        $this->assertGreaterThan(-0.115, $genericPhysics['macro_demand_shift'], 'Trade-down effect must soften the recessionary drop.');

        // 2. SHER stock (tuned pricing power = 0.60)
        $sherStock = new Stock();
        $sherStock->setTicker('SHER');
        $sherStock->setBeta('1.0');

        $sherPhysics = $this->model->getMacroPhysics($sherStock, $recessionMacro);
        // SHER trade-down bonus = 0.05 * 0.60 * (1.5 - 0.60) = 0.05 * 0.54 = +0.027
        // Blended demand shift = -0.115 + 0.027 = -0.088
        $this->assertEqualsWithDelta(-0.088, $sherPhysics['macro_demand_shift'], 0.0001);
    }

    public function testContractStreamFxExportCompetitiveness(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHER');
        $stock->setBeta('1.0');

        $neutralMacro = new MacroStateDTO(exchangeRateIndexEma: 100.0);
        $weakCurrencyMacro = new MacroStateDTO(exchangeRateIndexEma: 120.0); // +20% FX shift

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.22,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $neutralMacro,
            mathUtility: $mathMock
        );

        $weakCurrencyResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.22,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $weakCurrencyMacro,
            mathUtility: $mathMock
        );

        // Contract textile supply revenue should increase due to export bonus
        $this->assertGreaterThan(
            $neutralResult->streamRevenue['contract_textile_supply'],
            $weakCurrencyResult->streamRevenue['contract_textile_supply']
        );
    }

    public function testAgriculturalCommodityAndFreightInflationPenalty(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHER');
        $stock->setBeta('1.0');

        $baseMacro = new MacroStateDTO(
            agriculturalCommodityIndexEma: 100.0,
            freightRateIndexEma: 100.0,
            energyPriceIndexEma: 100.0
        );

        $inflationMacro = new MacroStateDTO(
            agriculturalCommodityIndexEma: 140.0, // +40% raw fiber costs
            freightRateIndexEma: 150.0,            // +50% freight rates
            energyPriceIndexEma: 130.0             // +30% energy
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.22,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $baseMacro,
            mathUtility: $mathMock
        );

        $inflationResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.22,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $inflationMacro,
            mathUtility: $mathMock
        );

        // Higher input costs must increase raw variable margin (higher cost of goods)
        $this->assertGreaterThan(
            $baseResult->clampedMargin,
            $inflationResult->clampedMargin,
            'Commodity and freight inflation must increase variable cost margin.'
        );
    }

    public function testSupplyChainDisruptionTailRiskEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHER');

        $macro = new MacroStateDTO();

        // 4 calls: dtcZ, wholesaleZ, contractZ, eventZ (-2.50 triggers disruption)
        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, -2.50);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.22,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertEquals(ShockEvent::APPAREL_SUPPLY_CHAIN_DISRUPTION, $result->eventType);
        $this->assertTrue($result->isPublicEvent);

        // Revenue should be scaled by 0.88
        $this->assertEqualsWithDelta(88_000_000.0, $result->actualRevenue, 1.0);
    }

    public function testViralProductTailRiskEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHER');

        $macro = new MacroStateDTO();

        // 4 calls: dtcZ, wholesaleZ, contractZ, eventZ (+2.60 triggers viral product)
        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, 2.60);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.22,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertEquals(ShockEvent::APPAREL_VIRAL_PRODUCT, $result->eventType);
        $this->assertTrue($result->isPublicEvent);

        // DTC & Wholesale get 1.12x boost, Contract is unaffected
        $expectedDtc = 30_000_000.0 * 1.12;
        $expectedWholesale = 40_000_000.0 * 1.12;
        $expectedContract = 30_000_000.0;
        $this->assertEqualsWithDelta($expectedDtc, $result->streamRevenue['dtc_retail'], 1.0);
        $this->assertEqualsWithDelta($expectedWholesale, $result->streamRevenue['wholesale_channel'], 1.0);
        $this->assertEqualsWithDelta($expectedContract, $result->streamRevenue['contract_textile_supply'], 1.0);
    }

    public function testAssetDepreciationDecayAndLoomModernization(): void
    {
        $stock = new Stock();
        $stock->setOperatingMargin('0.22');

        // Under-investment (reinvestmentRatio = 0.50, dt = 0.25 => 1 quarter)
        // decayRate = 0.025 * (1.0 - 0.50) * 1.0 = 0.0125
        // updatedMargin = 0.22 - (0.22 * 0.0125) = 0.21725
        $this->model->applyAssetDepreciationDecay($stock, reinvestmentRatio: 0.50, dt: 0.25);
        $this->assertEqualsWithDelta(0.21725, (float) $stock->getOperatingMargin(), 0.0001);

        // Over-investment / modernization (reinvestmentRatio = 2.0, dt = 0.25)
        $stock->setOperatingMargin('0.22');
        // modGain = 0.012 * ln(2.0) * 1.0 = 0.012 * 0.693147 = 0.00831776
        // updatedMargin = 0.22 + ((0.32 - 0.22) * 0.00831776) = 0.22 + (0.10 * 0.00831776) = 0.22083178
        $this->model->applyAssetDepreciationDecay($stock, reinvestmentRatio: 2.0, dt: 0.25);
        $this->assertEqualsWithDelta(0.220832, (float) $stock->getOperatingMargin(), 0.0001);
    }
}
