<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\AutoManufacturerBusinessModel;
use PHPUnit\Framework\TestCase;

class AutoManufacturerBusinessModelTest extends TestCase
{
    private AutoManufacturerBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new AutoManufacturerBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');
        $stock->setBeta('1.5');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, yield2yEma: 0.03);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 30_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('mass_market_sales', $result->streamRevenue);
        $this->assertArrayHasKey('apex_luxury', $result->streamRevenue);
        $this->assertArrayHasKey('software_telematics', $result->streamRevenue);

        $this->assertArrayHasKey('mass_market_sales', $result->streamZ);
        $this->assertArrayHasKey('apex_luxury', $result->streamZ);
        $this->assertArrayHasKey('software_telematics', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['mass_market_sales']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['apex_luxury']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['software_telematics']);

        $sumStreams = $result->streamRevenue['mass_market_sales']
            + $result->streamRevenue['apex_luxury']
            + $result->streamRevenue['software_telematics'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testFalcTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, yield2yEma: 0.03);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathMock
        );

        // FALC tuned: 55% Mass Market Fleet, 25% Apex Luxury, 20% Software Telematics
        $this->assertEqualsWithDelta(55_000_000.0, $result->streamRevenue['mass_market_sales'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['apex_luxury'], 1.0);
        $this->assertEqualsWithDelta(20_000_000.0, $result->streamRevenue['software_telematics'], 1.0);
    }

    public function testApexLuxurySurgesDuringMarketWealthAndQELiquidityBoom(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03, equityRiskPremium: 0.045, qeActive: false);
        $wealthBoomMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03, equityRiskPremium: 0.035, qeActive: true, qeIntensity: 1.0);

        $neutralResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $neutralMacro, $mathMock);
        $wealthBoomResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $wealthBoomMacro, $mathMock);

        // Apex luxury revenue expands when financial asset wealth (ERP compression) and central bank liquidity surge
        $this->assertGreaterThan(
            $neutralResult->streamRevenue['apex_luxury'],
            $wealthBoomResult->streamRevenue['apex_luxury']
        );
        // Specifically: 25M base * (1.0 + (0.010 * 10.0) + (1.0 * 0.15)) = 25M * 1.25 = 31.25M
        $this->assertEqualsWithDelta(31_250_000.0, $wealthBoomResult->streamRevenue['apex_luxury'], 1.0);
    }

    public function testApexLuxuryVeblenInflationPricingPower(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baselineMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03);
        $inflationMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.06, policyRateEma: 0.03);

        $baselineResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $baselineMacro, $mathMock);
        $inflationResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $inflationMacro, $mathMock);

        // Apex luxury hypercars exert Veblen pricing power when inflation exceeds target (0.04 excess * 0.80 = +3.2%)
        $this->assertGreaterThan(
            $baselineResult->streamRevenue['apex_luxury'],
            $inflationResult->streamRevenue['apex_luxury']
        );
        $this->assertEqualsWithDelta(25_800_000.0, $inflationResult->streamRevenue['apex_luxury'], 1.0);
    }

    public function testLaborStrikeObservableShockBounded(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(-3.0);

        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');
        $stock->setBeta('1.0');
        $stock->setEarningsMomentumZ([
            'event' => -3.0,
        ]);

        $macro = new MacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertSame(\App\Service\Event\ShockEvent::LABOR_STRIKE, $result->eventType);
        // Strike reduces mass-market sales by 20% (on 60% of revenue -> -12% overall hit).
        // Observable shock is properly scaled between -0.25 and 0.0, rather than blowing up to -2.0 (-200%).
        $this->assertGreaterThan(-0.25, $result->observableShockZ);
        $this->assertLessThan(0.0, $result->observableShockZ);
    }

    public function testCoverageProfile(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');

        $coverage = $this->model->getCoverageProfile($stock);
        $this->assertEquals(AutoManufacturerBusinessModel::BASE_COVERAGE_VISIBILITY, $coverage->baseVisibility);
        $this->assertEquals(AutoManufacturerBusinessModel::BASE_COVERAGE_ERROR, $coverage->errorStdDev);
        $this->assertEquals(AutoManufacturerBusinessModel::BASE_COVERAGE_MIN_VISIBILITY, $coverage->minVisibility);
    }

    public function testProductRecallEventAndObservableShock(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(-2.30); // Triggers recall event between -2.20 and -2.50

        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');
        $stock->setBeta('1.0');
        $stock->setEarningsMomentumZ([
            'event' => -2.30,
        ]);

        $macro = new MacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertSame(\App\Service\Event\ShockEvent::PRODUCT_RECALL, $result->eventType);
        // Recall penalty = 0.05 on 60% sales stream -> observable shock = -(0.05 * 0.60) * 0.70 = -0.021
        $this->assertEqualsWithDelta(-0.021, $result->observableShockZ, 0.001);
    }

    public function testExchangeRateExportDragIsCarriedOnceAtTheDeclaredExposure(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $stock = function (): Stock {
            $stock = new Stock();
            $stock->setTicker('GEN_AUTO');
            $stock->setBeta('1.2');

            return $stock;
        };

        $baseMacro = new MacroStateDTO(exchangeRateIndexEma: 100.0);
        $strongDollarMacro = new MacroStateDTO(exchangeRateIndexEma: 120.0);

        // A 20% stronger currency takes the declared exposure off every stream through the root shift...
        $rootDrag = $this->model->getMacroPhysics($stock(), $strongDollarMacro)['macro_demand_shift']
            - $this->model->getMacroPhysics($stock(), $baseMacro)['macro_demand_shift'];
        $this->assertEqualsWithDelta(-0.20 * AutoManufacturerBusinessModel::FX_REVENUE_EXPOSURE, $rootDrag, 1e-9);

        // ...so the sector physics must not take it off mass-market sales a second time.
        $baseResult = $this->model->computeActualFinancials($stock(), 100_000_000.0, 0.20, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $strongDollarResult = $this->model->computeActualFinancials($stock(), 100_000_000.0, 0.20, 20_000_000.0, 0.0, $strongDollarMacro, $mathMock);
        $this->assertEqualsWithDelta($baseResult->streamRevenue['mass_market_sales'], $strongDollarResult->streamRevenue['mass_market_sales'], 1.0);
    }

    public function testMetalsAreTheInputThatMovesAndOceanFreightIsImmaterial(): void
    {
        // BEA 2017: steel, aluminium and copper through the supply chain are 11.9% of an assembler's variable costs; ocean
        // freight is under 0.2%, below InputOutputExposures::MATERIALITY_FLOOR.
        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');
        $stock->setBeta('1.0');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $run = fn (MacroStateDTO $macro) => $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $macro, $mathMock);

        $calm = $run(new MacroStateDTO(freightRateIndexEma: 100.0, industrialMetalsIndexEma: 100.0, inflationEma: 0.02));
        $freightSpike = $run(new MacroStateDTO(freightRateIndexEma: 160.0, industrialMetalsIndexEma: 100.0, inflationEma: 0.02));
        $metalsSpike = $run(new MacroStateDTO(freightRateIndexEma: 100.0, industrialMetalsIndexEma: 140.0, inflationEma: 0.02));

        $this->assertEqualsWithDelta($calm->clampedMargin, $freightSpike->clampedMargin, 1e-12);
        $this->assertGreaterThan($calm->clampedMargin, $metalsSpike->clampedMargin);
        $this->assertLessThan($calm->ebit, $metalsSpike->ebit);
    }

    /**
     * A vehicle is a consumer durable: demand follows the output gap at the sector's cyclicality, household
     * sentiment and financing rates. The heavy-manufacturing parent's amplified gap and manufacturing PMI
     * used to stack on top of those, so a mild slowdown cut volume by a quarter, which is the 2008-09
     * collapse and not 1991 (-12%). A 2008-scale shock must still be a collapse.
     *
     * Measured against the NEUTRAL state rather than against zero. Confidence used to be read off the
     * index's construction constant, which the series sits twelve points under at trend, so every shift
     * this model returned carried a standing -0.07 that a recession scenario was silently claiming as part
     * of its depth. The drop from neutral is what was calibrated here, and it is unchanged by that fix.
     *
     * Each scenario pairs its gap with the confidence that gap actually comes with (SENTIMENT_TREND_LEVEL
     * less SENTIMENT_GAP_LOADING per unit of gap): 82 is a mild slowdown, 55 at a -6% gap is a panic.
     */
    public function testMildSlowdownCutsVolumeByAnEleventhNotAQuarterAndPmiDoesNotStack(): void
    {
        // Each scenario's gap has stood long enough to reach the order book: a state given no lags opens them at its gap.
        $shift = function (MacroStateDTO $macroState): float {
            $stock = new Stock();
            $stock->setTicker('AUTO');
            $stock->setBeta('1.35');

            return $this->model->getMacroPhysics($stock, $macroState)['macro_demand_shift'];
        };

        $neutralShift = $shift(new MacroStateDTO(outputGapEma: 0.0, consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL, policyRateEma: 0.02));

        $mild = new MacroStateDTO(outputGapEma: -0.015, consumerSentimentIndexEma: 82.0, policyRateEma: 0.043, manufacturingPmiEma: 43.0);
        $mildDrop = $shift($mild) - $neutralShift;
        $this->assertLessThan(-0.07, $mildDrop);
        $this->assertGreaterThan(-0.18, $mildDrop, 'a mild recession is not the 2008 auto collapse');

        $mildStrongPmi = new MacroStateDTO(outputGapEma: -0.015, consumerSentimentIndexEma: 82.0, policyRateEma: 0.043, manufacturingPmiEma: 56.0);
        $this->assertEqualsWithDelta(
            $mildDrop,
            $shift($mildStrongPmi) - $neutralShift,
            1e-12,
            'the industrial PMI is the machinery makers\' signal, not the car buyer\'s'
        );

        $severe = new MacroStateDTO(outputGapEma: -0.06, consumerSentimentIndexEma: 55.0, policyRateEma: 0.01);
        $this->assertLessThan(-0.20, $shift($severe) - $neutralShift, 'a 2008-scale shock still collapses volume');
    }

    /**
     * The gap reaches vehicle demand once, through the stock-adjustment multiplier. Confidence used to be read whole
     * beside the gap, and it carries SENTIMENT_GAP_LOADING points of gap, so a second ~2.3x the gap rode in on it
     * unpriced. With confidence at the level its gap implies, sentiment adds nothing and nothing else moves.
     */
    public function testTheGapReachesVehicleDemandOnceThroughTheDurableMultiplier(): void
    {
        $stock = new Stock();
        $stock->setTicker('XAUT');
        $stock->setBeta('1.0');
        $quiet = $this->createStub(MathUtility::class);
        $expectedRevenue = 100_000_000.0;

        $stateAt = fn (float $gap): MacroStateDTO => new MacroStateDTO(
            outputGapEma: $gap,
            consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL + (MacroEngine::SENTIMENT_GAP_LOADING * $gap),
            exchangeRateIndexEma: 100.0,
        );
        $response = function (float $gap) use ($stock, $quiet, $expectedRevenue, $stateAt): float {
            $root = $this->model->getMacroPhysics($stock, $stateAt($gap))['macro_demand_shift'];
            $streams = $this->model->computeActualFinancials($stock, $expectedRevenue, 0.20, 25_000_000.0, 0.0, $stateAt($gap), $quiet)->actualRevenue;

            return $root + ($streams / $expectedRevenue) - 1.0;
        };

        $gap = -0.03;
        $elasticity = AutoManufacturerBusinessModel::OPERATING_CYCLICALITY * AutoManufacturerBusinessModel::DURABLE_STOCK_ADJUSTMENT_MULTIPLIER;
        $this->assertEqualsWithDelta($gap * $elasticity, $response($gap) - $response(0.0), 1e-9);
    }

    /** Financing demand reads the real stance: the same real rate over r* costs the same volume at any inflation. */
    public function testRatePenaltyReadsTheRealPolicyStanceNotTheNominalRate(): void
    {
        $stock = new Stock();
        $stock->setTicker('XAUT');
        $stock->setBeta('1.0');
        $shift = fn (float $policy, float $breakeven): float => $this->model->getMacroPhysics($stock, new MacroStateDTO(
            policyRateEma: $policy,
            tipsBreakevenEma: $breakeven,
            naturalRateEma: MacroEngine::BASE_NATURAL_RATE,
            exchangeRateIndexEma: 100.0,
        ))['macro_demand_shift'];

        // Neutral at 2% and at 4% expected inflation alike; a nominal 3% neutral would read the second as 250bp tight.
        $neutralLowInflation = $shift(MacroEngine::BASE_NATURAL_RATE + 0.02, 0.02);
        $neutralHighInflation = $shift(MacroEngine::BASE_NATURAL_RATE + 0.04, 0.04);
        $this->assertEqualsWithDelta(0.0, $neutralLowInflation, 1e-12);
        $this->assertEqualsWithDelta(0.0, $neutralHighInflation, 1e-12);

        // 100bp of real tightness takes cyclicality x the rate scalar of it off volume.
        $this->assertEqualsWithDelta(
            -0.01 * AutoManufacturerBusinessModel::OPERATING_CYCLICALITY * AutoManufacturerBusinessModel::RATE_SENSITIVITY_SCALAR,
            $shift(MacroEngine::BASE_NATURAL_RATE + 0.03, 0.02),
            1e-12
        );
    }

    public function testFalcCalibratedFinancialsUnderMacroShifts(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.35');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $macro = new MacroStateDTO(
            outputGapEma: -0.02,
            inflationEma: 0.04,
            policyRateEma: 0.05,
            yield10yEma: 0.045,
            yield2yEma: 0.050, // Inverted curve
            equityRiskPremium: 0.055,
            macroCreditSpread: 0.035
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 500_000_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 263_900_000_000.0, // 500B * (1 - 0.09 margin) * 0.58 fixed cost ratio
            baselineVol: 0.35,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertGreaterThan(0.0, $result->actualVariableCosts);
        $this->assertLessThan(1.0, $result->clampedMargin);

        $operatingMargin = $result->ebit / $result->actualRevenue;
        $this->assertGreaterThan(-0.25, $operatingMargin);
        $this->assertLessThan(0.35, $operatingMargin);
    }

    public function testSupplyChainPressureIncreasesManufacturingCost(): void
    {
        $stock = new Stock();
        $stock->setTicker('OEM');
        $stock->setBeta('1.2');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $macroNormal = new MacroStateDTO(
            outputGapEma: 0.0,
            supplyChainPressureIndexEma: 0.0
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroNormal,
            mathUtility: $mathMock
        );

        $macroBottleneck = new MacroStateDTO(
            outputGapEma: 0.0,
            supplyChainPressureIndexEma: 2.50 // Severe supply chain bottleneck
        );

        $resultBottleneck = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroBottleneck,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan($resultNormal->clampedMargin, $resultBottleneck->clampedMargin, 'Global supply chain bottleneck must increase variable manufacturing costs.');
    }

    public function testCapacityUtilizationDrivesAssemblyThroughput(): void
    {
        $stock = new Stock();
        $stock->setTicker('OEM_PLANT');
        $stock->setBeta('1.0');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $macroNormal = new MacroStateDTO(
            outputGapEma: 0.0,
            capacityUtilizationRateEma: 0.785
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroNormal,
            mathUtility: $mathMock
        );

        $macroBoom = new MacroStateDTO(
            outputGapEma: 0.0,
            capacityUtilizationRateEma: 0.835 // High factory utilization
        );

        $resultBoom = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroBoom,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan($resultNormal->streamRevenue['mass_market_sales'], $resultBoom->streamRevenue['mass_market_sales'], 'High industrial capacity utilization must increase vehicle assembly revenue.');
    }
}

