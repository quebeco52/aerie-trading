<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\DefenseContractorBusinessModel;
use App\Service\Model\StreamContext;
use App\Tests\Support\MacroStateBuilder;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class DefenseContractorBusinessModelTest extends TestCase
{
    private function createMacroState(
        float $inflation = 0.02,
        float $macroCreditSpread = 0.015,
        float $energyPriceShock = 0.0,
        float $nominalGdpIndex = 1.0,
        float $energyPriceIndexEma = 100.0,
    ): MacroStateDTO {
        return MacroStateBuilder::flatCurveDto(
            energyPriceIndex: $energyPriceIndexEma,
            energyPriceIndexEma: $energyPriceIndexEma,
            energyPriceShock: $energyPriceShock,
            inflation: $inflation,
            inflationEma: $inflation,
            macroCreditSpread: $macroCreditSpread,
            macroCreditSpreadEma: $macroCreditSpread,
            potentialGdpIndex: $nominalGdpIndex,
            nominalGdpIndex: $nominalGdpIndex,
        );
    }

    /**
     * Scripted stream draws; the overrun's onset dice are pinned, so a programme goes into overrun only where a test
     * says so.
     */
    private function createMathUtilityMock(array $persistentZCalls = [], bool $overrunOnset = false): ScriptedDraws
    {
        return new ScriptedDraws(
            generatePersistentZ: $persistentZCalls === [] ? 0.0 : array_values($persistentZCalls),
            checkProbability: static fn(float $probability): bool => $overrunOnset && $probability === DefenseContractorBusinessModel::PROGRAM_OVERRUN_ONSET_HAZARD,
        );
    }

    /**
     * A prime budgets plant against its order book: an allied drawdown cuts the capital budget with the domestic
     * economy at potential, and a domestic recession with allied budgets on trend leaves it alone.
     */
    public function testTheCapexCycleIsTheAlliedOrderGapNotTheOutputGap(): void
    {
        $model = new DefenseContractorBusinessModel();

        $this->assertEqualsWithDelta(0.0, $model->getCapexCycleSignal(new Stock(), new MacroStateDTO(outputGapEma: -0.06, alliedDefenseSpendingIndexEma: 100.0)), 1e-12);
        $this->assertEqualsWithDelta(
            MacroConstants::ALLIED_PROCUREMENT_ELASTICITY * log(0.8),
            $model->getCapexCycleSignal(new Stock(), new MacroStateDTO(alliedDefenseSpendingIndexEma: 80.0)),
            1e-12,
        );
    }

    /** The US investment accelerator: a 5% allied cut takes about a fifth off the capital budget. */
    public function testTheCapitalBudgetAnswersTheOrderGapWithTheInvestmentAccelerator(): void
    {
        $model = new DefenseContractorBusinessModel();
        $modifier = 1.0 + ($model->getCapexCycleSignal(new Stock(), new MacroStateDTO(alliedDefenseSpendingIndexEma: 95.0)) * $model->getCapexCyclicality());

        $this->assertSame(2.52, $model->getCapexCyclicality());
        $this->assertEqualsWithDelta(0.801, $modifier, 0.001);
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
            $mathUtilityMock,
        );

        // With zero Z-scores and target inflation, actual revenue should equal expected revenue exactly ($10,000)
        $this->assertEqualsWithDelta($expectedRevenue, $result->actualRevenue, 0.01);
    }

    /**
     * Cost-plus work bills its costs plus a fee, so hot inflation is billed as the costs it raises and earns no margin
     * of its own: the engine already carries realised inflation on the plant, and the physics adds nothing on top.
     */
    public function testHotInflationBooksNoCostPlusOrdersOfItsOwn(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        $result = $model->computeActualFinancials($stock, 10_000.0, 0.30, 3000.0, 0.15, $this->createMacroState(inflation: 0.04), $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]));

        $this->assertEqualsWithDelta(10_000.0, $result->actualRevenue, 1.0);
        $this->assertEqualsWithDelta(1.0, $result->kpis['book_to_bill'], 1e-9);
    }

    /**
     * The customers are allied ministries, whose budgets reach the order book through the allied spending index. A
     * District credit squeeze is not a budget freeze: the model used to read a 5% corporate spread as one.
     */
    public function testDistrictCreditStressLeavesAlliedOrdersAlone(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]);

        $macroState = $this->createMacroState(macroCreditSpread: 0.05);

        $expectedRevenue = 10_000.0;
        $result = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertEqualsWithDelta(10_000.0, $result->actualRevenue, 1e-6);
        $this->assertEqualsWithDelta(1.0, $result->kpis['book_to_bill'], 1e-9);
    }

    public function testFixedPriceForwardLossAndInflationSqueeze(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');

        // The programme goes into overrun this quarter.
        $mathUtilityMock = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0], overrunOnset: true);

        // Engineering pay 2% above its productivity path: the fixed-price EMD share cannot recover it, cost-plus can.
        $macroState = $this->createMacroState(inflation: 0.04);
        $macroState = MacroStateDTO::fromArray(array_merge($macroState->toArray(), ['real_wage_gap' => 0.02]));

        $result = $model->computeActualFinancials(
            $stock,
            10_000.0,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertSame(ShockEvent::PROJECT_DELAY, $result->eventType);
        // Fixed price weight is 0.20 for GRIP: the reach-forward loss, FORWARD_LOSS_PENALTY 0.08 * 0.20 = 0.016 of
        // revenue, is provisioned once as a special item, not carried in the cost ratio.
        $this->assertEqualsWithDelta(DefenseContractorBusinessModel::FORWARD_LOSS_PENALTY * 0.20 * $result->actualRevenue, $result->specialCharge, 0.01);
        // Wage basket: the measured labor share x the 2% gap; 80% of contracts (cost-plus, FMS) recover 0.80 of it
        // with the repricing lag, so the first-quarter drag is
        // margin x deviation x (1 - recoveredShare x firstQuarterRecoveryWeight).
        $deviation = DefenseContractorBusinessModel::INPUT_COST_EXPOSURES['labor'] * 0.02;
        $recoveredShare = 0.80 * DefenseContractorBusinessModel::MAX_INPUT_COST_PASS_THROUGH;
        $recoveryWeight = 1.0 - exp(-0.25 / DefenseContractorBusinessModel::INPUT_PASS_THROUGH_LAG_YEARS);
        $wageDrag = 0.30 * $deviation * (1.0 - ($recoveredShare * $recoveryWeight));
        $this->assertEqualsWithDelta(0.30 + $wageDrag, $result->clampedMargin, 0.001);
        $this->assertGreaterThan(0.30, $result->clampedMargin);
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
            $mathUtilityMock,
        );

        $this->assertSame(ShockEvent::GEOPOLITICAL_CONFLICT, $result->eventType);
        // FMS revenue boosted by 1.50 multiplier
        $this->assertGreaterThan(2000.0, $result->streamRevenue['foreign_military_sales']);
        // wartimeSupplyDrag = 0.035 * 0.20 = 0.007: clamped margin = 0.30 + 0.007 = 0.307
        $this->assertEqualsWithDelta(0.307, $result->clampedMargin, 0.001);
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
            $mathUtilityMock,
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
        // Cost-Plus weight is 0.60 for GRIP -> the cancellation charge is 0.10 * 0.60 = 0.06 of revenue, a special
        // item.
        $this->assertEqualsWithDelta(0.30, $resFailure->clampedMargin, 0.001);
        $this->assertEqualsWithDelta(DefenseContractorBusinessModel::FLAGSHIP_FAILURE_PENALTY * 0.60 * $resFailure->actualRevenue, $resFailure->specialCharge, 0.01);

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

        // Progress payments finance the fixed-price book only, so withholding ties up that book's share (GRIP 20%).
        $withheld = DefenseContractorBusinessModel::BASE_NWC_INTENSITY
            + (DefenseContractorBusinessModel::WITHHOLDING_NWC_INTENSITY - DefenseContractorBusinessModel::BASE_NWC_INTENSITY) * 0.20;

        // A programme in overrun, for as long as the overrun runs; a weak quarter of fixed-price orders is not one.
        $overrun = StreamContext::REGIME_STATE_PREFIX . DefenseContractorBusinessModel::REGIME_PROGRAM_OVERRUN;
        $stock->setEarningsMomentumZ(['fixed_price_development' => 0.0, 'event' => 0.0, $overrun => 4.0]);
        $this->assertEqualsWithDelta($withheld, $model->getWorkingCapitalIntensity($stock), 1e-12);
        $stock->setEarningsMomentumZ(['fixed_price_development' => -1.8, 'event' => 0.0]);
        $this->assertEqualsWithDelta(DefenseContractorBusinessModel::BASE_NWC_INTENSITY, $model->getWorkingCapitalIntensity($stock), 1e-12);

        // Flagship defect / fleet grounding
        $stock->setEarningsMomentumZ(['fixed_price_development' => 0.0, 'event' => -2.6]);
        $this->assertEqualsWithDelta($withheld, $model->getWorkingCapitalIntensity($stock), 1e-12);

        // An export house with a smaller fixed-price sleeve has less of its book on progress payments (PTAR 15%).
        $exporter = new Stock();
        $exporter->setTicker('PTAR');
        $exporter->setEarningsMomentumZ(['fixed_price_development' => 0.0, 'event' => 0.0, $overrun => 1.0]);
        $this->assertEqualsWithDelta(
            DefenseContractorBusinessModel::BASE_NWC_INTENSITY
                + (DefenseContractorBusinessModel::WITHHOLDING_NWC_INTENSITY - DefenseContractorBusinessModel::BASE_NWC_INTENSITY) * 0.15,
            $model->getWorkingCapitalIntensity($exporter),
            1e-12,
        );
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

        $mathUtility = MathUtility::ownStream(20261024);
        $macroState = $this->createMacroState();

        $result1 = $model->computeActualFinancials(
            $stock,
            10_000.0,
            0.30,
            3000.0,
            0.15,
            $macroState,
            $mathUtility,
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

    public function testAlliedDefenceSpendingAndFmsFxSensitivity(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('0.8');

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);
        $run = fn(MacroStateDTO $macro) => $model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $macro, $mathMock)->streamRevenue;

        $base = $run(new MacroStateDTO(alliedDefenseSpendingIndexEma: 100.0, exchangeRateIndexEma: 100.0));
        $buildUp = $run(new MacroStateDTO(alliedDefenseSpendingIndexEma: 120.0, exchangeRateIndexEma: 100.0));
        $strongCurrency = $run(new MacroStateDTO(alliedDefenseSpendingIndexEma: 100.0, exchangeRateIndexEma: 120.0));

        // Exports are bought out of the same allied budgets as the programmes: a build-up lifts both books' orders
        // by the same proportion (the burn rates differ, so compare each book's lift on its own opening backlog).
        $costPlusLift = $buildUp['cost_plus_procurement'] / $base['cost_plus_procurement'] - 1.0;
        $fmsLift = $buildUp['foreign_military_sales'] / $base['foreign_military_sales'] - 1.0;
        $this->assertGreaterThan(0.0, $fmsLift, 'An allied build-up fills the export book too.');
        $this->assertEqualsWithDelta(
            $costPlusLift / DefenseContractorBusinessModel::COST_PLUS_BACKLOG_BURN_RATE,
            $fmsLift / DefenseContractorBusinessModel::FMS_BACKLOG_BURN_RATE,
            1e-9,
        );

        // A strong currency prices exports out against foreign primes; domestic-currency programmes do not see it.
        $this->assertLessThan($base['foreign_military_sales'], $strongCurrency['foreign_military_sales']);
        $this->assertEqualsWithDelta($base['cost_plus_procurement'], $strongCurrency['cost_plus_procurement'], 1e-6);
    }
    /** The District fields no army: its own purchases buy no arms, and its makers' programmes follow allied budgets. */
    public function testDistrictPurchasesBuyNoArmsButAlliedBudgetsDo(): void
    {
        $model = new DefenseContractorBusinessModel();
        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);
        $programmes = function (MacroStateDTO $macro) use ($model, $mathMock): float {
            $stock = new Stock();
            $stock->setTicker('GRIP');
            $stock->setBeta('0.8');
            $result = $model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $macro, $mathMock);

            return $result->streamRevenue['cost_plus_procurement'] + $result->streamRevenue['fixed_price_development'];
        };

        $base = $programmes(new MacroStateDTO());
        $this->assertSame($base, $programmes(new MacroStateDTO(governmentSpendingIndexEma: 130.0)), 'A District spending surge buys no arms.');
        $this->assertGreaterThan($base, $programmes(new MacroStateDTO(alliedDefenseSpendingIndexEma: 130.0)), 'An allied build-up fills the programme book.');
    }

    /**
     * The committed cost base staffs to the programme work in hand: with the draws and the macro terms flat, the
     * activity the prime reports is the revenue its books recognize on the opening backlog, less one. Two
     * formulas would drift, and the base would carry programme staff for work the books no longer hold.
     */
    public function testTheCostBaseStaffsToTheProgrammeWorkInHand(): void
    {
        $model = new DefenseContractorBusinessModel();
        $macro = $this->createMacroState(inflation: MacroConstants::TARGET_INFLATION);
        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $seeded = new Stock();
        $seeded->setTicker('GRIP');
        $this->assertEqualsWithDelta(0.0, $model->resolveSectorActivityShift($seeded, $macro), 1e-12, 'A freshly seeded book is normal activity.');

        $drawnDown = new Stock();
        $drawnDown->setTicker('GRIP');
        $drawnDown->setBeta('0.35');
        $drawnDown->setEarningsMomentumZ([
            StreamContext::BACKLOG_STATE_PREFIX . 'cost_plus_procurement'   => 4.0,
            StreamContext::BACKLOG_STATE_PREFIX . 'fixed_price_development' => 3.0,
            StreamContext::BACKLOG_STATE_PREFIX . 'foreign_military_sales'  => 2.0,
        ]);
        $shift = $model->resolveSectorActivityShift($drawnDown, $macro);
        $booked = $model->computeActualFinancials($drawnDown, 1000.0, 0.30, 300.0, 0.15, $macro, $mathMock)->actualRevenue / 1000.0 - 1.0;

        $this->assertLessThan(-0.10, $shift, 'A book run down by a drawdown staffs the base down.');
        $this->assertEqualsWithDelta($booked, $shift, 1e-9, 'The base staffs to the revenue the books recognize.');
    }

    public function testAnOverrunArrivesAtItsHazardAndPersistsUntilItsRandomExit(): void
    {
        $model = new DefenseContractorBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . DefenseContractorBusinessModel::REGIME_PROGRAM_OVERRUN;
        $macro = new MacroStateDTO();

        $run = function (array $momentum, bool $exits, bool $onset = false, float $fixedPriceZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('DEF');
            $stock->setBeta('1.0');
            $stock->setEarningsMomentumZ($momentum);
            // Stream draws and regime dice are scripted; the mix-drift weight math stays real.
            // The fixed-price stream is recognised by its sentinel previous value; every other draw is flat.
            $math = new ScriptedDraws(
                generatePersistentZ: fn(float $prev): float => $prev === -9.9 ? $fixedPriceZ : 0.0,
                checkProbability: static fn(float $p): bool => $p === DefenseContractorBusinessModel::PROGRAM_OVERRUN_ONSET_HAZARD ? $onset : $exits,
            );
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([], false);
        $this->assertNull($clean->eventType);

        // A programme fails on its engineering, not on its orders: the deepest order slump starts no overrun, and a
        // programme with booming orders can still go into one.
        $this->assertNull($run(['fixed_price_development' => -9.9], false, fixedPriceZ: -3.0)->eventType);
        $this->assertSame(ShockEvent::PROJECT_DELAY, $run(['fixed_price_development' => -9.9], false, onset: true, fixedPriceZ: 2.0)->eventType);

        // Quarter 1: the onset fires and the regime starts.
        $onset = $run([], false, onset: true);
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
