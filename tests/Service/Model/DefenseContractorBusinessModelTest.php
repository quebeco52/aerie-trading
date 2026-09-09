<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\DefenseContractorBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\DTO\StreamContext;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DefenseContractorBusinessModelTest extends TestCase
{
    private function createMacroState(
        float $inflation = 0.02,
        float $macroCreditSpread = 0.015,
        float $energyPriceShock = 0.0,
        float $nominalGdpIndex = 1.0,
        float $energyPriceIndexEma = 100.0
    ): MacroStateDTO {
        return new MacroStateDTO(
            outputGap: 0.0,
            outputGapEma: 0.0,
            unemploymentRate: 0.04,
            unemploymentRateEma: 0.04,
            energyPriceIndex: $energyPriceIndexEma,
            energyPriceIndexEma: $energyPriceIndexEma,
            energyPriceShock: $energyPriceShock,
            consumerSentimentIndex: 100.0,
            consumerSentimentIndexEma: 100.0,
            inflation: $inflation,
            inflationEma: $inflation,
            policyRate: 0.04,
            policyRateEma: 0.04,
            targetRate: 0.04,
            yield2y: 0.04,
            yield2yEma: 0.04,
            yield5y: 0.04,
            yield5yEma: 0.04,
            yield10y: 0.04,
            yield10yEma: 0.04,
            yield30y: 0.04,
            yield30yEma: 0.04,
            marketVolatility: 0.15,
            marketVolatilityEma: 0.15,
            marketZ: 0.0,
            corporateTaxRate: 0.21,
            equityRiskPremium: 0.05,
            macroCreditSpread: $macroCreditSpread,
            macroCreditSpreadEma: $macroCreditSpread,
            qeActive: false,
            qeIntensity: 0.0,
            inversionDuration: 0.0,
            nsLevel: 0.04,
            nsSlope: 0.0,
            nsSlopeEma: 0.0,
            nsCurvature: 0.0,
            potentialGdpIndex: $nominalGdpIndex,
            nominalGdpIndex: $nominalGdpIndex,
        );
    }

    private function createMathUtilityMock(array $persistentZCalls = []): MathUtility
    {
        $mock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        if (!empty($persistentZCalls)) {
            $mock->method('generatePersistentZ')
                ->willReturnOnConsecutiveCalls(...array_values($persistentZCalls));
        }

        return $mock;
    }

    public function testWrightsLawLearningCurveImprovesVariableMargin(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('0.7');

        // costPlusZ = 0.0, fixedPriceZ = 0.0, fmsZ = 2.0 (high mature production volume), eventZ = 0.0
        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 2.0, 0.0]);

        $macroState = $this->createMacroState();

        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.65,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // Wright's Law scale efficiency on FMS volume reduces variable cost margin below baseline 65%
        $this->assertLessThan(0.65, $result->clampedMargin);
        $this->assertEqualsWithDelta(0.642, $result->clampedMargin, 0.001);
    }

    public function testRevenueNotDistortedByCumulativeNominalGdpGrowth(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('0.35');

        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]);

        // Simulation has run for hours: nominal GDP index is 3.5 (high accumulated growth)
        $macroState = $this->createMacroState(inflation: 0.02, nominalGdpIndex: 3.5);

        $expectedRevenue = 10_000.0;
        $result = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // With zero Z-scores and target inflation, actual revenue should equal expected revenue exactly ($10,000)
        $this->assertEqualsWithDelta($expectedRevenue, $result->actualRevenue, 0.01);
        $this->assertEqualsWithDelta(0.0, $result->observableShockZ, 0.0001);
    }

    public function testCostPlusBonusAppliesWhenInflationExceedsTarget(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]);

        // Inflation running at 4% (2% in excess of 2% target)
        $macroState = $this->createMacroState(inflation: 0.04);

        $expectedRevenue = 10_000.0;
        $result = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // Excess inflation (0.04 - 0.02) = 0.02 * 1.50 = 0.03 costPlusBonus on cost-plus ORDERS.
        // Cost-Plus weight is 0.60 for GRIP (from StockModelTuning): +1.8% of orders this quarter.
        // Percentage-of-completion recognition burns the cost-plus backlog at 15% a quarter, so revenue
        // rises by 1.8% x 0.15 = 0.27% now ($10,027) and the remainder sits in the backlog (book-to-bill > 1).
        $burn = DefenseContractorBusinessModel::COST_PLUS_BACKLOG_BURN_RATE;
        $this->assertEqualsWithDelta(10_000.0 * (1.0 + (0.018 * $burn)), $result->actualRevenue, 1.0);
        $this->assertEqualsWithDelta(0.018 * $burn, $result->observableShockZ, 0.001);
        $this->assertGreaterThan(1.0, $result->kpis['book_to_bill']);
        $this->assertGreaterThan((1.0 - $burn) / $burn * 0.6, $result->kpis['backlog_quarters']);
    }

    public function testSovereignFiscalStressAndContinuingResolutionDrag(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]);

        // Sovereign credit spread elevated to 5.0% (2.0% above 3.0% threshold)
        $macroState = $this->createMacroState(macroCreditSpread: 0.05);

        $expectedRevenue = 10_000.0;
        $result = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // CR drag = (0.05 - 0.03) * 2.50 = 0.05 drag on cost-plus ORDERS (multiplier = 0.95).
        // Orders fund a backlog burned at 15% a quarter, so recognized cost-plus revenue only slips by
        // 5% x 0.15 = 0.75%: 6,000 -> 5,955, total 9,955. The unfunded gap shows up as book-to-bill < 1.
        $burn = DefenseContractorBusinessModel::COST_PLUS_BACKLOG_BURN_RATE;
        $this->assertEqualsWithDelta(10_000.0 - (6_000.0 * 0.05 * $burn), $result->actualRevenue, 1.0);
        $this->assertLessThan(1.0, $result->kpis['book_to_bill']);
    }

    public function testFixedPriceForwardLossAndInflationSqueeze(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        // fixedPriceZ = -2.0 (< -1.50 FORWARD_LOSS_Z_SCORE)
        $mathUtilityMock = $this->createMathUtilityMock([0.0, -2.0, 0.0, 0.0]);

        // Engineering wages running 2pts above trend: the fixed-price EMD share cannot recover it, cost-plus can.
        $macroState = $this->createMacroState(inflation: 0.04);
        $macroState = MacroStateDTO::fromArray(array_merge($macroState->toArray(), ['wage_growth_ema' => 0.055]));

        $result = $model->computeActualFinancials(
            $stock,
            10_000.0,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertSame(ShockEvent::PROJECT_DELAY, $result->eventType);
        // Fixed price weight is 0.20 for GRIP: forward loss = FORWARD_LOSS_PENALTY 0.08 * 0.20 = 0.016.
        // Wage basket: labor share 0.35 x 2pts excess = 0.7% of the cost base; 80% of contracts (cost-plus, FMS)
        // recover 0.80 of it with the repricing lag, so the first-quarter drag is
        // margin x deviation x (1 - recoveredShare x firstQuarterRecoveryWeight).
        $deviation = 0.35 * (0.055 - (MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION));
        $recoveredShare = 0.80 * DefenseContractorBusinessModel::MAX_INPUT_COST_PASS_THROUGH;
        $recoveryWeight = 1.0 - exp(-0.25 / DefenseContractorBusinessModel::INPUT_PASS_THROUGH_LAG_YEARS);
        $wageDrag = 0.30 * $deviation * (1.0 - ($recoveredShare * $recoveryWeight));
        $this->assertEqualsWithDelta(0.316 + $wageDrag, $result->clampedMargin, 0.001);
        $this->assertGreaterThan(0.316, $result->clampedMargin);
    }

    public function testGeopoliticalConflictSurge(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        // fmsZ = 2.5 (> 2.0 GEOPOLITICAL_CONFLICT_Z)
        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 2.5, 0.0]);

        $macroState = $this->createMacroState();

        $result = $model->computeActualFinancials(
            $stock,
            10_000.0,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertSame(ShockEvent::GEOPOLITICAL_CONFLICT, $result->eventType);
        // FMS revenue boosted by 1.50 multiplier
        $this->assertGreaterThan(2000.0, $result->streamRevenue['foreign_military_sales']);
        // learningCurveShift = -0.020 * 2.5 * 0.20 = -0.010
        // wartimeSupplyDrag = 0.035 * 0.20 = 0.007
        // Net clamped margin = 0.30 - 0.010 + 0.007 = 0.297
        $this->assertEqualsWithDelta(0.297, $result->clampedMargin, 0.001);
    }

    public function testCongressionalExportBan(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        // eventZ = -2.2 (< -2.0 CONGRESSIONAL_EXPORT_BAN_Z)
        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 0.0, -2.2]);

        $macroState = $this->createMacroState();

        $result = $model->computeActualFinancials(
            $stock,
            10_000.0,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertSame(ShockEvent::GEOPOLITICAL_EXPORT_BAN, $result->eventType);
        // FMS ORDERS halved (0.50 multiplier); deliveries keep flowing from the export backlog at 25% a
        // quarter, so recognized FMS revenue falls by 50% x 0.25 = 12.5% now (2000 -> 1750) and the
        // backlog drains toward the lower run-rate in the quarters that follow.
        $burn = DefenseContractorBusinessModel::FMS_BACKLOG_BURN_RATE;
        $this->assertEqualsWithDelta(2000.0 * (1.0 - (0.50 * $burn)), $result->streamRevenue['foreign_military_sales'], 1.0);
        $this->assertLessThan(1.0, $result->kpis['book_to_bill']);
    }

    public function testFlagshipWeaponPlatformFailureAndMegaContractWin(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        // Flagship failure (eventZ = -2.8 < -2.5)
        $mathMock1 = $this->createMathUtilityMock([0.0, 0.0, 0.0, -2.8]);

        $macroState = $this->createMacroState();
        $resFailure = $model->computeActualFinancials($stock, 10_000.0, 0.30, 3000.0, 0.15, $macroState, $mathMock1);
        // Cost-Plus weight is 0.60 for GRIP -> penalty is 0.10 * 0.60 = 0.06
        $this->assertEqualsWithDelta(0.30 + (DefenseContractorBusinessModel::FLAGSHIP_FAILURE_PENALTY * 0.60), $resFailure->clampedMargin, 0.001);

        // Mega contract win (eventZ = 2.8 > 2.5)
        $mathMock2 = $this->createMathUtilityMock([0.0, 0.0, 0.0, 2.8]);

        $resWin = $model->computeActualFinancials($stock, 10_000.0, 0.30, 3000.0, 0.15, $macroState, $mathMock2);
        $this->assertSame(ShockEvent::DEFENSE_CONTRACT_WIN, $resWin->eventType);
        $this->assertGreaterThan(6000.0, $resWin->streamRevenue['cost_plus_procurement']);
    }

    public function testProgressPaymentWithholdingExpandsWorkingCapital(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        // Normal operations -> BASE_NWC_INTENSITY (0.10)
        $stock->setEarningsMomentumZ(['fixed_price_development' => 0.0, 'event' => 0.0]);
        $this->assertEqualsWithDelta(DefenseContractorBusinessModel::BASE_NWC_INTENSITY, $model->getWorkingCapitalIntensity($stock), 0.001);

        // Fixed-price development distress -> WITHHOLDING_NWC_INTENSITY (0.18)
        $stock->setEarningsMomentumZ(['fixed_price_development' => -1.8, 'event' => 0.0]);
        $this->assertEqualsWithDelta(DefenseContractorBusinessModel::WITHHOLDING_NWC_INTENSITY, $model->getWorkingCapitalIntensity($stock), 0.001);

        // Flagship defect / fleet grounding -> WITHHOLDING_NWC_INTENSITY (0.18)
        $stock->setEarningsMomentumZ(['fixed_price_development' => 0.0, 'event' => -2.6]);
        $this->assertEqualsWithDelta(DefenseContractorBusinessModel::WITHHOLDING_NWC_INTENSITY, $model->getWorkingCapitalIntensity($stock), 0.001);
    }

    public function testClassifiedToolingAndNextGenPlatformReinvestment(): void
    {
        $model = new DefenseContractorBusinessModel();

        // R = 0.5 -> Classified tooling tech debt
        $stock = new Stock();
        $stock->setOperatingMargin('0.14');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.14, $decayed);
        $this->assertGreaterThanOrEqual(DefenseContractorBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Next-gen defense platform modernization
        $stock->setOperatingMargin('0.14');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.14, $expanded);
        $this->assertLessThanOrEqual(DefenseContractorBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testRoicSmoothingUnderLumpyContractAwards(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setRoicTtm('0.15');

        // Big positive quarter (NOPAT = 50 on 500 invested capital -> 10% quarterly -> 40% annualized)
        $return = $model->updateDynamicRoic($stock, 50.0, 500.0, 63.29, 0.21, 0.08, 0.10);
        $this->assertEqualsWithDelta(0.40, $return, 0.01);

        // TTM ROIC should be smoothed with 0.20 weight: 0.15 * 0.80 + 0.40 * 0.20 = 0.20 (before reversion pull)
        $newTtm = (float) $stock->getRoicTtm();
        $this->assertGreaterThan(0.15, $newTtm);
        $this->assertLessThan(0.40, $newTtm);
    }

    public function testMacroPhysicsRecessionImmunity(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('0.7');

        $macroState = $this->createMacroState();
        $physics = $model->getMacroPhysics($stock, $macroState);

        $this->assertEqualsWithDelta(0.0, $physics['macro_demand_shift'], 0.001);
        $this->assertEqualsWithDelta(1.0, $physics['pricing_power_multiplier'], 0.001);
    }

    public function testDynamicRevenueMixDriftsWithRealizedSharesAndMeanReverts(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        // Quarter 1: Previous quarter had huge FMS share (50% FMS, 30% CostPlus, 20% FixedPrice)
        $stock->setEarningsMomentumZ([
            'weight:cost_plus_procurement'   => 0.60,
            'weight:fixed_price_development' => 0.20,
            'weight:foreign_military_sales'  => 0.20,
            'share:cost_plus_procurement'    => 0.30,
            'share:fixed_price_development'  => 0.20,
            'share:foreign_military_sales'   => 0.50,
        ]);

        $mathUtility = new MathUtility();
        $macroState = $this->createMacroState();

        $result1 = $model->computeActualFinancials(
            $stock,
            10_000.0,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtility
        );

        // FMS active weight should have drifted up from 0.20 due to 50% realized share
        $activeFmsWeight = $result1->streamZ['weight:foreign_military_sales'];
        $this->assertGreaterThan(0.20, $activeFmsWeight);
        $this->assertLessThanOrEqual(\App\Service\Math\FinancialConstants::DEFAULT_MAX_STREAM_WEIGHT_CEILING, $activeFmsWeight);

        // Cost-plus active weight should have drifted down from 0.60
        $activeCostPlusWeight = $result1->streamZ['weight:cost_plus_procurement'];
        $this->assertLessThan(0.60, $activeCostPlusWeight);
        $this->assertGreaterThanOrEqual(\App\Service\Math\FinancialConstants::DEFAULT_MIN_STREAM_WEIGHT_FLOOR, $activeCostPlusWeight);

        // All active weights must sum to 1.0
        $totalActiveWeight = $result1->streamZ['weight:cost_plus_procurement']
            + $result1->streamZ['weight:fixed_price_development']
            + $result1->streamZ['weight:foreign_military_sales'];
        $this->assertEqualsWithDelta(1.0, $totalActiveWeight, 0.0001);
    }

    public function testGovernmentSpendingAndFmsFxSensitivity(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('0.8');

        $baseMacro = new MacroStateDTO(
            governmentSpendingIndexEma: 100.0,
            exchangeRateIndexEma: 100.0
        );

        $shockMacro = new MacroStateDTO(
            governmentSpendingIndexEma: 120.0,
            exchangeRateIndexEma: 120.0 // Strong dollar creates FMS export headwind
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $shockResult = $model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $shockMacro, $mathMock);

        $this->assertGreaterThan($baseResult->streamRevenue['cost_plus_procurement'], $shockResult->streamRevenue['cost_plus_procurement']);
        $this->assertLessThan($baseResult->streamRevenue['foreign_military_sales'], $shockResult->streamRevenue['foreign_military_sales']);
    }
    public function testTailEventPersistsAsAMarkovRegimeWithRandomExit(): void
    {
        $model = new DefenseContractorBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . DefenseContractorBusinessModel::REGIME_PROGRAM_OVERRUN;
        $macro = new MacroStateDTO();

        $run = function (array $momentum, bool $exits, float $onsetZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('DEF');
            $stock->setBeta('1.0');
            $stock->setEarningsMomentumZ($momentum);
            // Partial mock: stream draws and regime dice are scripted, the mix-drift weight math stays real.
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'checkProbability'])->getMock();
            // The onset stream is recognised by its sentinel previous value; every other draw is flat.
            $math->method('generatePersistentZ')->willReturnCallback(fn (float $prev): float => $prev === -9.9 ? $onsetZ : 0.0);
            $math->method('checkProbability')->willReturn($exits);
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([], false);
        $this->assertNull($clean->eventType);

        // Quarter 1: the trigger fires and the regime starts.
        $onset = $run(['fixed_price_development' => -9.9], false, -3.0);
        $this->assertSame(ShockEvent::PROJECT_DELAY, $onset->eventType);
        $this->assertSame(1.0, $onset->streamZ[$regimeKey]);

        // Quarter 2: no new trigger, no exit -> the regime is still active and its cost persists silently.
        $ongoing = $run($onset->streamZ, false);
        $this->assertNull($ongoing->eventType, 'a continuing regime is not re-announced');
        $this->assertSame(2.0, $ongoing->streamZ[$regimeKey]);
        $this->assertGreaterThan($clean->clampedMargin, $ongoing->clampedMargin, 'ongoing regime cost must persist after the onset quarter');

        // Quarter 3: the exit hazard fires -> back to baseline.
        $after = $run($ongoing->streamZ, true);
        $this->assertSame(0.0, $after->streamZ[$regimeKey]);
        $this->assertEqualsWithDelta($clean->clampedMargin, $after->clampedMargin, 1e-9, 'costs return to baseline once the regime exits');
    }

}
