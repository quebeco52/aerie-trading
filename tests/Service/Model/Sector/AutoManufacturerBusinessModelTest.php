<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\AutoManufacturerBusinessModel;
use PHPUnit\Framework\TestCase;

class AutoManufacturerBusinessModelTest extends TestCase
{
    /** A policy rate at the natural real rate plus expected inflation, so the real stance moves no purchases. */
    private const NEUTRAL_POLICY_RATE = MacroConstants::TARGET_INFLATION + MacroConstants::BASE_NATURAL_RATE;

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
            mathUtility: $this->mathUtility,
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
        $this->assertGreaterThan(0.0, $result->streamRevenue['captive_finance']);

        $this->assertEqualsWithDelta($result->actualRevenue, array_sum($result->streamRevenue), 1.0);
    }

    public function testFalcTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        // A neutral real rate as well as a closed gap, so no purchase swing moves the mix.
        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: self::NEUTRAL_POLICY_RATE, yield10yEma: 0.04, yield2yEma: 0.03);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathMock,
        );

        // FALC tuned: 55% Mass Market Fleet, 25% Apex Luxury, 8% Software Telematics, 12% Captive Finance
        $this->assertEqualsWithDelta(55_000_000.0, $result->streamRevenue['mass_market_sales'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['apex_luxury'], 1.0);
        $this->assertEqualsWithDelta(8_000_000.0, $result->streamRevenue['software_telematics'], 1.0);
        $this->assertEqualsWithDelta(12_000_000.0, $result->streamRevenue['captive_finance'], 1.0);
    }

    public function testApexLuxuryFollowsEquityWealthAgainstItsTrend(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: self::NEUTRAL_POLICY_RATE, equityWealthRatio: 2.0e13, equityWealthTrend: 2.0e13);
        $wealthBoomMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: self::NEUTRAL_POLICY_RATE, equityWealthRatio: 2.5e13, equityWealthTrend: 2.0e13);

        $neutralResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $neutralMacro, $mathMock);
        $wealthBoomResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $wealthBoomMacro, $mathMock);

        // Hypercar deliveries follow equity wealth over its trend: 25M base * (1.0 + 0.25 * 0.40) = 27.5M
        $this->assertEqualsWithDelta(25_000_000.0, $neutralResult->streamRevenue['apex_luxury'], 1.0);
        $this->assertEqualsWithDelta(27_500_000.0, $wealthBoomResult->streamRevenue['apex_luxury'], 1.0);
    }

    public function testApexLuxuryVeblenInflationPricingPower(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baselineMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: self::NEUTRAL_POLICY_RATE);
        $inflationMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.06, policyRateEma: self::NEUTRAL_POLICY_RATE);

        $baselineResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $baselineMacro, $mathMock);
        $inflationResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $inflationMacro, $mathMock);

        // Apex luxury hypercars exert Veblen pricing power when inflation runs over the 2% breakevens (0.04 * 0.80 =
        // +3.2%)
        $this->assertGreaterThan(
            $baselineResult->streamRevenue['apex_luxury'],
            $inflationResult->streamRevenue['apex_luxury'],
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
            mathUtility: $mathMock,
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
            mathUtility: $mathMock,
        );

        $this->assertSame(\App\Service\Event\ShockEvent::PRODUCT_RECALL, $result->eventType);
        // The recall is a special item on the mass-market sales it was charged against, out of the cost ratio and of
        // what the Street forecasts, which excludes it: analysts cutting their numbers for it would manufacture a beat.
        $salesRevenue = $result->streamRevenue['mass_market_sales'];
        $this->assertEqualsWithDelta(AutoManufacturerBusinessModel::MASSIVE_RECALL_PENALTY * $salesRevenue, $result->specialCharge, 1.0);
        $this->assertEqualsWithDelta(0.0, $result->observableShockZ, 0.001);
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
        $strongDollarMacro = new MacroStateDTO(exchangeRateIndexEma: 100.0 * exp(0.20));

        // A currency twenty log points over its trend takes the declared exposure off every stream through the root
        // shift...
        $rootDrag = $this->model->getMacroPhysics($stock(), $strongDollarMacro)['macro_demand_shift']
            - $this->model->getMacroPhysics($stock(), $baseMacro)['macro_demand_shift'];
        $this->assertEqualsWithDelta(-0.20 * AutoManufacturerBusinessModel::FX_REVENUE_EXPOSURE, $rootDrag, 1e-9);

        // ...so the sector physics must not take it off the firm a second time: it only moves it from the fleet's
        // subscriptions and the loan book onto vehicle sales.
        $baseResult = $this->model->computeActualFinancials($stock(), 100_000_000.0, 0.20, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $strongDollarResult = $this->model->computeActualFinancials($stock(), 100_000_000.0, 0.20, 20_000_000.0, 0.0, $strongDollarMacro, $mathMock);
        $this->assertEqualsWithDelta($baseResult->actualRevenue, $strongDollarResult->actualRevenue, 1.0);
        $this->assertLessThan($baseResult->streamRevenue['mass_market_sales'], $strongDollarResult->streamRevenue['mass_market_sales']);
        $this->assertGreaterThan($baseResult->streamRevenue['captive_finance'], $strongDollarResult->streamRevenue['captive_finance']);
    }

    public function testMetalsAreTheInputThatMovesAndOceanFreightIsImmaterial(): void
    {
        // BEA 2017: steel, aluminium and copper through the supply chain are 11.9% of an assembler's variable costs;
        // ocean freight is under 0.2%, below InputOutputExposures::MATERIALITY_FLOOR.
        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');
        $stock->setBeta('1.0');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $run = fn(MacroStateDTO $macro) => $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $macro, $mathMock);

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
     * less SentimentOnTheCycle::POINTS_PER_GAP per unit of gap): 82 is a mild slowdown, 55 at a -6% gap is a panic.
     */
    public function testMildSlowdownCutsVolumeByAnEleventhNotAQuarterAndPmiDoesNotStack(): void
    {
        // Each scenario's gap has stood long enough to reach the order book: a state given no lags opens them at its
        // gap.
        $shift = function (MacroStateDTO $macroState): float {
            $stock = new Stock();
            $stock->setTicker('AUTO');
            $stock->setBeta('1.35');

            return $this->model->getMacroPhysics($stock, $macroState)['macro_demand_shift'];
        };

        $neutralShift = $shift(new MacroStateDTO(outputGapEma: 0.0, consumerSentimentIndexEma: MacroConstants::SENTIMENT_TREND_LEVEL, policyRateEma: 0.02));

        $mild = new MacroStateDTO(outputGapEma: -0.015, consumerSentimentIndexEma: 82.0, policyRateEma: 0.043, manufacturingPmiEma: 43.0);
        $mildDrop = $shift($mild) - $neutralShift;
        $this->assertLessThan(-0.07, $mildDrop);
        $this->assertGreaterThan(-0.18, $mildDrop, 'a mild recession is not the 2008 auto collapse');

        $mildStrongPmi = new MacroStateDTO(outputGapEma: -0.015, consumerSentimentIndexEma: 82.0, policyRateEma: 0.043, manufacturingPmiEma: 56.0);
        $this->assertEqualsWithDelta(
            $mildDrop,
            $shift($mildStrongPmi) - $neutralShift,
            1e-12,
            'the industrial PMI is the machinery makers\' signal, not the car buyer\'s',
        );

        $severe = new MacroStateDTO(outputGapEma: -0.06, consumerSentimentIndexEma: 55.0, policyRateEma: 0.01);
        $this->assertLessThan(-0.20, $shift($severe) - $neutralShift, 'a 2008-scale shock still collapses volume');
    }

    /**
     * The gap reaches vehicle demand once, through the stock-adjustment multiplier. Confidence used to be read whole
     * beside the gap, and it carries SentimentOnTheCycle::POINTS_PER_GAP points of gap, so a second ~2.3x the gap rode
     * in on it unpriced. With confidence at the level its gap implies, sentiment adds nothing and nothing else moves.
     */
    public function testTheGapReachesVehicleDemandOnceThroughTheDurableMultiplier(): void
    {
        $stock = new Stock();
        $stock->setTicker('XAUT');
        $stock->setBeta('1.0');
        $quiet = $this->createStub(MathUtility::class);
        $expectedRevenue = 100_000_000.0;

        $stateAt = fn(float $gap): MacroStateDTO => new MacroStateDTO(
            outputGapEma: $gap,
            consumerSentimentIndexEma: MacroConstants::SENTIMENT_TREND_LEVEL + (\App\Tests\Support\SentimentOnTheCycle::POINTS_PER_GAP * $gap),
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
        $shift = fn(float $policy, float $breakeven): float => $this->model->getMacroPhysics($stock, new MacroStateDTO(
            policyRateEma: $policy,
            tipsBreakevenEma: $breakeven,
            naturalRateEma: MacroConstants::BASE_NATURAL_RATE,
            exchangeRateIndexEma: 100.0,
        ))['macro_demand_shift'];

        // Neutral at 2% and at 4% expected inflation alike; a nominal 3% neutral would read the second as 250bp tight.
        $neutralLowInflation = $shift(MacroConstants::BASE_NATURAL_RATE + 0.02, 0.02);
        $neutralHighInflation = $shift(MacroConstants::BASE_NATURAL_RATE + 0.04, 0.04);
        $this->assertEqualsWithDelta(0.0, $neutralLowInflation, 1e-12);
        $this->assertEqualsWithDelta(0.0, $neutralHighInflation, 1e-12);

        // 100bp of real tightness takes cyclicality x the rate scalar of it off volume.
        $this->assertEqualsWithDelta(
            -0.01 * AutoManufacturerBusinessModel::OPERATING_CYCLICALITY * AutoManufacturerBusinessModel::RATE_SENSITIVITY_SCALAR,
            $shift(MacroConstants::BASE_NATURAL_RATE + 0.03, 0.02),
            1e-12,
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
            macroCreditSpread: 0.035,
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 500_000_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 263_900_000_000.0, // 500B * (1 - 0.09 margin) * 0.58 fixed cost ratio
            baselineVol: 0.35,
            macroState: $macro,
            mathUtility: $mathMock,
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
            supplyChainPressureIndexEma: 0.0,
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroNormal,
            mathUtility: $mathMock,
        );

        $macroBottleneck = new MacroStateDTO(
            outputGapEma: 0.0,
            supplyChainPressureIndexEma: 2.50, // Severe supply chain bottleneck
        );

        $resultBottleneck = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroBottleneck,
            mathUtility: $mathMock,
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
            capacityUtilizationRateEma: 0.785,
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroNormal,
            mathUtility: $mathMock,
        );

        $macroBoom = new MacroStateDTO(
            outputGapEma: 0.0,
            capacityUtilizationRateEma: 0.835, // High factory utilization
        );

        $resultBoom = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 2_000_000_000.0,
            baselineVol: 0.0,
            macroState: $macroBoom,
            mathUtility: $mathMock,
        );

        $this->assertGreaterThan($resultNormal->streamRevenue['mass_market_sales'], $resultBoom->streamRevenue['mass_market_sales'], 'High industrial capacity utilization must increase vehicle assembly revenue.');
    }

    /**
     * Credit losses and the funding squeeze are the loan book's: a maker with no finance arm feels none of them, and
     * one with an arm pays them on the book's interest income. They used to be charged to the telematics line.
     */
    public function testCreditCostsLandOnTheLoanBookNotTheTelematicsLine(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $run = function (string $ticker, MacroStateDTO $macro) use ($mathMock) {
            $stock = new Stock();
            $stock->setTicker($ticker);
            $stock->setBeta('1.2');

            return $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 20_000_000.0, 0.0, $macro, $mathMock);
        };

        $calm = new MacroStateDTO(policyRateEma: self::NEUTRAL_POLICY_RATE, yield10yEma: 0.04, yield2yEma: 0.03);
        // Defaults at twice their through-the-cycle rates, an inverted curve and spreads 2pp over trend.
        $stress = new MacroStateDTO(
            policyRateEma: self::NEUTRAL_POLICY_RATE,
            yield10yEma: 0.03,
            yield2yEma: 0.045,
            retailDefaultRateEma: 2.0 * MacroConstants::RETAIL_DEFAULT_BASELINE,
            corporateDefaultRateEma: 2.0 * MacroConstants::CORPORATE_DEFAULT_BASELINE,
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD + 0.02,
        );

        // EIDR's fleets finance through their own banks.
        $this->assertEqualsWithDelta($run('EIDR', $calm)->actualVariableCosts, $run('EIDR', $stress)->actualVariableCosts, 1e-3);

        // FALC's book: +0.05 retail and +0.02 corporate provisions, the curve from a 0.3pp gain to a 2.96pp squeeze,
        // and +1pp of CECL reserve, all on its 12M of finance income.
        $nimCalm = AutoManufacturerBusinessModel::NIM_SPREAD_BUFFER - 0.01;
        $nimStress = (AutoManufacturerBusinessModel::NIM_SPREAD_BUFFER + 0.015)
            + ((0.015 * AutoManufacturerBusinessModel::YIELD_CURVE_INVERSION_SENSITIVITY) ** 2) * AutoManufacturerBusinessModel::NIM_QUADRATIC_COEFF;
        $creditCost = 0.05 + 0.02 + ($nimStress - $nimCalm) + 0.01;
        $falcCalm = $run('FALC', $calm);
        $falcStress = $run('FALC', $stress);
        $this->assertEqualsWithDelta($falcCalm->streamRevenue['captive_finance'], $falcStress->streamRevenue['captive_finance'], 1e-3);
        $this->assertEqualsWithDelta(
            $falcCalm->streamRevenue['captive_finance'] * $creditCost,
            $falcStress->actualVariableCosts - $falcCalm->actualVariableCosts,
            1.0,
        );
    }
}
