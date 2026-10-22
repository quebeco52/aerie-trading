<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\MacroDriverAttribution;
use App\Service\Event\ShockEvent;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\DistressedDebtBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DistressedDebtBusinessModelTest extends TestCase
{
    /** Recovery's share of the default mix: the activity shift is the distress multiplier at this weight. */
    private const RECOVERY_SHARE = 0.60;

    /** HY spread trend of the test quarters: a decade wider than the base the spread is built from. */
    private const HIGH_YIELD_TREND = 0.060;

    private DistressedDebtBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new DistressedDebtBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testCreditSpreadBlowoutSurgesTurnaroundRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        // High credit spread blowout regime (500 bps IG, 1650 bps HY) & severe recession (-3% output gap)
        $crisisMacro = new MacroStateDTO(
            outputGapEma: -0.03,
            macroCreditSpread: 0.05,
            inflationEma: 0.02,
            highYieldCreditSpreadEma: 0.165,
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.0,
            macroState: $crisisMacro,
            mathUtility: $this->mathUtility,
        );

        $this->assertArrayHasKey('restructuring_advisory', $result->streamRevenue);
        $this->assertArrayHasKey('turnaround_recovery', $result->streamRevenue);

        // Turnaround recovery revenue must surge significantly above the baseline 60M allocation
        $this->assertGreaterThan(60_000_000.0, $result->streamRevenue['turnaround_recovery']);
        $this->assertGreaterThan(100_000_000.0, $result->actualRevenue);
    }

    public function testBullMarketTightCreditSpreadsCreateDryPowderDrag(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        // Bull market with tight credit spreads (100 bps IG, 330 bps HY) and high positive output gap (+3%)
        $bullMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            macroCreditSpread: 0.01,
            inflationEma: 0.02,
            highYieldCreditSpreadEma: 0.033,
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.0,
            macroState: $bullMacro,
            mathUtility: $this->mathUtility,
        );

        // Revenue should contract slightly due to dry powder holding drag
        $this->assertLessThan(60_000_000.0, $result->streamRevenue['turnaround_recovery']);
        $this->assertLessThan(100_000_000.0, $result->actualRevenue);
    }

    public function testRestructuringShockEventTriggeredDuringCrisis(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        $crisisMacro = new MacroStateDTO(
            outputGapEma: -0.02,
            macroCreditSpread: 0.04,
            highYieldCreditSpreadEma: 0.132,
        );

        $mathStub = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        // High positive recovery shock Z = 2.0 (triggers event)
        $mathStub->method('generatePersistentZ')->willReturn(2.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 50_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 5_000_000.0,
            baselineVol: 0.20,
            macroState: $crisisMacro,
            mathUtility: $mathStub,
        );

        $this->assertSame(ShockEvent::DISTRESSED_DEBT_RESTRUCTURING, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testEvaluateHoardingStatusThresholds(): void
    {
        $operatingBase = 100_000_000.0;
        $targetCash = 20_000_000.0;

        // 1. Normal cash ($30M treasury -> $10M excess <= 40M threshold)
        $normal = $this->model->evaluateHoardingStatus(30_000_000.0, $targetCash, $operatingBase, 0.0);
        $this->assertFalse($normal['is_hoarder']);
        $this->assertFalse($normal['is_mega_hoarder']);

        // 2. Hoarder cash ($70M treasury -> $50M excess > 40M threshold)
        $hoarder = $this->model->evaluateHoardingStatus(70_000_000.0, $targetCash, $operatingBase, 0.0);
        $this->assertTrue($hoarder['is_hoarder']);
        $this->assertFalse($hoarder['is_mega_hoarder']);

        // 3. Mega hoarder cash ($100M treasury -> $80M excess > 70M threshold)
        $mega = $this->model->evaluateHoardingStatus(100_000_000.0, $targetCash, $operatingBase, 0.0);
        $this->assertTrue($mega['is_hoarder']);
        $this->assertTrue($mega['is_mega_hoarder']);
    }

    public function testHighYieldCreditSpreadAndDefaultRateSurgeBoostRecoveryRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        $calmMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
            // Baseline HY
            highYieldCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER,
            corporateDefaultRateEma: 0.020,   // Baseline 2.0%
        );

        $hySpikeMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: 0.02,
            macroCreditSpreadEma: 0.02,
            highYieldCreditSpreadEma: 0.088, // Blowout to 880 bps (+400 bps)
            corporateDefaultRateEma: 0.060,   // Surging to 6.0% (+200% shift)
        );

        $mathMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $calmResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 10_000_000.0, 0.0, $calmMacro, $mathMock);
        $spikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 10_000_000.0, 0.0, $hySpikeMacro, $mathMock);

        $this->assertGreaterThan(
            $calmResult->streamRevenue['turnaround_recovery'],
            $spikeResult->streamRevenue['turnaround_recovery'],
            'Surging high-yield credit spreads and corporate default rates must explode distressed debt recovery revenue.',
        );
    }

    public function testExtremeCrisisSurgeIsStrictlyBoundedBySaturationCeiling(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        // Apocalyptic crisis: 25% default rate, 1500 bps HY spreads, 800 bps credit spreads, -5% GDP gap
        $apocalypseMacro = new MacroStateDTO(
            outputGapEma: -0.05,
            macroCreditSpread: 0.08,
            macroCreditSpreadEma: 0.08,
            highYieldCreditSpreadEma: 0.150,
            corporateDefaultRateEma: 0.250,
        );

        $mathMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $expectedRevenue = 100_000_000.0;
        $result = $this->model->computeActualFinancials($stock, $expectedRevenue, 0.40, 10_000_000.0, 0.0, $apocalypseMacro, $mathMock);

        // Total actual revenue is strictly bounded:
        // actualRevenue = expectedRevenue * (w_advisory + w_recovery * (1 + distressMultiplier))
        // Since w_advisory + w_recovery = 1.0 and distressMultiplier <= MAX_DISTRESS_REVENUE_EXPANSION (1.25),
        // actual revenue cannot exceed expectedRevenue * (1 + 1.25) = 225M under any crisis condition.
        $absoluteMaxAllowed = $expectedRevenue * (1.0 + DistressedDebtBusinessModel::MAX_DISTRESS_REVENUE_EXPANSION);
        $this->assertLessThanOrEqual($absoluteMaxAllowed, $result->actualRevenue);
        $this->assertLessThanOrEqual($absoluteMaxAllowed, $result->streamRevenue['turnaround_recovery']);
        $this->assertGreaterThan($expectedRevenue, $result->actualRevenue);
    }

    public function testTheWorkoutDesksStaffUpIntoTheSlump(): void
    {
        $stock = new Stock();
        $stock->setTicker('DD_TEST');
        $recession = new MacroStateDTO(
            outputGapEma: -0.03,
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
            highYieldCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER,
            corporateDefaultRateEma: MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN,
        );

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Spreads and defaults at their trends; signal 0.03 x 5 = 0.15 -> 1.25 x 0.15 / 1.15; recovery 0.60 of the mix,
        // advisory 0.40 carries none.
        $expected = 0.60 * (1.25 * 0.15 / 1.15);
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

    /**
     * A quarter with every distress input at its trend, a trend away from the base the HY spread is built from, and
     * the named inputs overridden.
     *
     * @param array<string, float> $overrides MacroStateDTO constructor arguments.
     */
    private function quarterAtTrend(array $overrides = []): MacroStateDTO
    {
        return new MacroStateDTO(...array_replace([
            'outputGapEma' => 0.0,
            'highYieldCreditSpreadEma' => self::HIGH_YIELD_TREND,
            'highYieldCreditSpreadTrend' => self::HIGH_YIELD_TREND,
            'corporateDefaultRateEma' => 0.020,
            'corporateDefaultRateTrend' => 0.020,
        ], $overrides));
    }

    private function distressMultiplier(MacroStateDTO $macroState): float
    {
        $stock = new Stock();
        $stock->setTicker('DD_TEST');

        return $this->model->resolveSectorActivityShift($stock, $macroState) / self::RECOVERY_SHARE;
    }

    /** Spreads and defaults at their trends with no output gap are a usual quarter: the book runs at plan. */
    public function testAUsualQuarterIsPlan(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->distressMultiplier($this->quarterAtTrend()), 1e-12);
    }

    /** @return iterable<string, array{array<string, float>, int}> Overrides and the sign they give the multiplier. */
    public static function distressInputs(): iterable
    {
        yield 'HY spread over its trend' => [['highYieldCreditSpreadEma' => self::HIGH_YIELD_TREND + 0.010], 1];
        yield 'HY spread under its trend' => [['highYieldCreditSpreadEma' => self::HIGH_YIELD_TREND - 0.010], -1];
        yield 'defaults over their trend' => [['corporateDefaultRateEma' => 0.030], 1];
        yield 'defaults under their trend' => [['corporateDefaultRateEma' => 0.014], -1];
        yield 'output short of potential' => [['outputGapEma' => -0.02], 1];
        yield 'output over potential' => [['outputGapEma' => 0.02], -1];
    }

    /**
     * Each input lifts the book above plan when credit is worse than usual and pulls it below when credit is easier.
     *
     * @param array<string, float> $overrides
     */
    #[DataProvider('distressInputs')]
    public function testEachInputMovesTheBookBothWays(array $overrides, int $sign): void
    {
        $multiplier = $this->distressMultiplier($this->quarterAtTrend($overrides));

        $this->assertGreaterThan(0.01, $sign * $multiplier);
    }

    /** No crisis takes the book past the fund's capacity, and no boom idles more than the drag. */
    public function testTheMultiplierIsBoundedByCapacityAndTheDrag(): void
    {
        $crisis = $this->distressMultiplier($this->quarterAtTrend([
            'highYieldCreditSpreadEma' => 0.33,
            'corporateDefaultRateEma' => 0.20,
            'outputGapEma' => -0.10,
        ]));
        $this->assertLessThanOrEqual(DistressedDebtBusinessModel::MAX_DISTRESS_REVENUE_EXPANSION, $crisis);
        $this->assertGreaterThan(0.9 * DistressedDebtBusinessModel::MAX_DISTRESS_REVENUE_EXPANSION, $crisis);

        $boom = $this->distressMultiplier($this->quarterAtTrend([
            'highYieldCreditSpreadEma' => 0.006,
            'corporateDefaultRateEma' => 0.0,
            'outputGapEma' => 0.10,
        ]));
        $this->assertGreaterThanOrEqual(-DistressedDebtBusinessModel::MAX_IDLE_DRY_POWDER_DRAG, $boom);
        $this->assertLessThan(-0.8 * DistressedDebtBusinessModel::MAX_IDLE_DRY_POWDER_DRAG, $boom);
    }

    /** A strong recovery quarter is no restructuring headline when credit is easier than usual. */
    public function testNoRestructuringEventWhenCreditIsEasierThanUsual(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');

        $mathStub = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();
        $mathStub->method('generatePersistentZ')->willReturn(2.0);

        $easy = $this->quarterAtTrend(['corporateDefaultRateEma' => 0.014, 'outputGapEma' => 0.01]);
        $result = $this->model->computeActualFinancials($stock, 50_000_000.0, 0.35, 5_000_000.0, 0.20, $easy, $mathStub);

        $this->assertNull($result->eventType);
    }

    /**
     * The credit cycle counts once, through the HY spread: the IG spread far over its trend with HY at its own moves
     * nothing, though the two are one cycle.
     */
    public function testTheInvestmentGradeSpreadAddsNothingToTheHighYieldTerm(): void
    {
        $quarter = $this->quarterAtTrend([
            'macroCreditSpread' => 0.030,
            'macroCreditSpreadEma' => 0.030,
            'macroCreditSpreadTrend' => 0.018,
        ]);

        $this->assertEqualsWithDelta(0.0, $this->distressMultiplier($quarter), 1e-12);
    }

    /**
     * The report's HY spread driver is the spread over its trend, the model's own neutral: with the spread 100 bps over
     * its trend, the counterfactual takes the whole credit term out.
     */
    public function testTheCreditSpreadDriverIsTheSpreadOverItsTrend(): void
    {
        $stock = new Stock();
        $stock->setTicker('DD_TEST');
        $macroState = $this->quarterAtTrend(['highYieldCreditSpreadEma' => self::HIGH_YIELD_TREND + 0.010]);

        mt_srand(17);
        $math = new MathUtility();
        $basis = clone $stock;
        $math->beginDrawLog();
        $actual = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 10_000_000.0, 0.0, $macroState, $math);
        $draws = $math->endDrawLog();

        $counterfactual = MacroDriverAttribution::counterfactualStates($this->model, $macroState)['high_yield_credit_spread_ema'];
        $effects = $this->model->measureStreamMacroEffects($basis, $draws, ['high_yield_credit_spread_ema' => [$counterfactual, 100_000_000.0]], 0.40, 10_000_000.0, 0.0, $actual->streamRevenue);

        // Signal 0.010 x 16 = 0.16 over a counterfactual at zero.
        $expected = 1.25 * 0.16 / 1.16;
        $this->assertEqualsWithDelta($expected, $effects['turnaround_recovery']['high_yield_credit_spread_ema'], 1e-9);
    }
}
