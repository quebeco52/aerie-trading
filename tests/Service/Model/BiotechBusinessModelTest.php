<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\BiotechBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class BiotechBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const FIXED_COSTS      = 20_000_000.0;
    private const BASELINE_VOL     = 0.10;
    private const VARIABLE_MARGIN  = 0.30;

    /**
     * @param list<float> $persistentZ  Consecutive generatePersistentZ() returns (established, pipeline).
     * @param list<bool>  $probabilities Consecutive checkProbability() returns (readout, approval).
     */
    private function mockMath(array $persistentZ, array $probabilities = []): MathUtility&MockObject
    {
        $mock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ', 'checkProbability'])
            ->getMock();

        $mock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(...$persistentZ);
        $mock->method('checkProbability')->willReturnOnConsecutiveCalls(...($probabilities ?: [false]));

        return $mock;
    }

    /**
     * @param array<string, float> $state Persisted structural state carried in from prior quarters.
     */
    private function makeStock(array $state = []): Stock
    {
        $stock = new Stock();
        $stock->setTicker('BIO');
        $stock->setBeta('1.0');
        if ($state !== []) {
            $stock->setEarningsMomentumZ($state);
        }

        return $stock;
    }

    /** Share of the exposed franchise eroded after `quarters` off exclusivity: the 50/50 mixture of the two modality survival curves. */
    private function loeErodedShare(float $quarters): float
    {
        $biologic = BiotechBusinessModel::DEFAULT_BIOLOGIC_REVENUE_SHARE;

        return ($biologic * (1.0 - exp(-BiotechBusinessModel::BIOLOGIC_LOE_HAZARD * $quarters)))
            + ((1.0 - $biologic) * (1.0 - exp(-BiotechBusinessModel::SMALL_MOLECULE_LOE_HAZARD * $quarters)));
    }

    /** R&D pays off through the readout hazard alone: the shared reinvestment margin drift would pay it a second time. */
    public function testRndReinvestmentNeverMovesTheOperatingMargin(): void
    {
        $model = new BiotechBusinessModel();

        foreach ([0.5, 1.0, 1.5] as $ratio) {
            $stock = $this->makeStock();
            $stock->setOperatingMargin('0.30');
            $model->applyAssetDepreciationDecay($stock, $ratio, 0.25);
            $this->assertSame('0.30', $stock->getOperatingMargin());
        }
    }

    public function testRndReplacementRatioIsPersistedForNextQuartersReadoutHazard(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();
        $stock->setOperatingMargin('0.30');

        $model->applyAssetDepreciationDecay($stock, 0.40, 0.25);

        $state = $stock->getEarningsMomentumZ() ?? [];
        $this->assertSame(0.40, $state[BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO]);
    }

    public function testGenericManufacturerCompoundsAtCommodityRates(): void
    {
        $model = new BiotechBusinessModel();

        // A fully off-patent (generic) manufacturer: no revenue under exclusivity.
        $generic = $this->makeStock([BiotechBusinessModel::STATE_PROTECTED_SHARE => 0.0]);
        $this->assertSame(BiotechBusinessModel::GENERIC_SECULAR_GROWTH_RATE, $model->getSecularGrowthRate($generic));

        $branded = $this->makeStock([BiotechBusinessModel::STATE_PROTECTED_SHARE => 1.0]);
        $this->assertSame(BiotechBusinessModel::PATENTED_SECULAR_GROWTH_RATE, $model->getSecularGrowthRate($branded));
    }

    public function testContinuousPipelineProgressImpactsVariableCosts(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();

        // establishedZ = 0.0, pipelineZ = 1.5 (positive clinical progress), no pivotal readout.
        $math = $this->mockMath([0.0, 1.5], [false]);

        $result = $model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            0.20,
            new MacroStateDTO(),
            $math
        );

        // Continuous pipeline shift = -0.015 * 1.5 * 0.30 = -0.00675
        $this->assertEqualsWithDelta(0.29325, $result->clampedMargin, 0.0001);
        $this->assertNull($result->eventType);
    }

    public function testApprovalPermanentlyStepsUpTheCommercialFranchise(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();

        // Flat streams, a pivotal readout fires, and it succeeds through to approval.
        $math = $this->mockMath([0.0, 0.0], [true, true]);

        $approvalQuarter = $model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $math
        );

        $this->assertSame(ShockEvent::BIOTECH_DRUG_APPROVAL, $approvalQuarter->eventType);
        $this->assertTrue($approvalQuarter->isPublicEvent);

        // The approval quarter itself only books the upfront/milestone payment on collaboration revenue.
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::PIPELINE_DRUG_WEIGHT * BiotechBusinessModel::APPROVAL_MILESTONE_REV_MULT,
            $approvalQuarter->streamRevenue['pipeline_licensing_milestones'],
            1.0
        );

        // The launched asset is permanent: the commercial franchise index steps up and survives the quarter.
        $state = $approvalQuarter->streamZ;
        $this->assertEqualsWithDelta(1.12, $state[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-9);

        // Portfolio-weighted average remaining exclusivity is refreshed by the new asset's patent life:
        // (1.0 * 27 + 0.12 * 40) / 1.12 = 28.392857...
        $this->assertEqualsWithDelta(28.392857, $state[BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS], 1e-5);

        // The new asset weighs in at its share of revenue whatever the franchise has grown to: a firm at twice
        // the base refreshes its clock by exactly as much.
        $grown = $model->computeActualFinancials(
            $this->makeStock([BiotechBusinessModel::STATE_FRANCHISE_INDEX => 2.0]),
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [true, true])
        );
        $this->assertEqualsWithDelta(28.392857, $grown->streamZ[BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS], 1e-5);

        // The launched asset is known and is new capacity, not overtime on the old: the step reaches EXPECTED
        // revenue through the structural multiplier, and the utilization shift carries no erosion at all.
        $multiplier = 1.0 + ($state['weight:commercial_therapeutics'] * (1.12 - 1.0));
        $this->assertEqualsWithDelta($multiplier, $state[BiotechBusinessModel::STATE_STRUCTURAL_MULTIPLIER], 1e-9);
        $stock->setEarningsMomentumZ($state);
        $this->assertEqualsWithDelta($multiplier, $model->getStructuralRevenueMultiplier($stock), 1e-9);
        $this->assertEqualsWithDelta(0.0, $model->getMacroPhysics($stock, new MacroStateDTO())['macro_demand_shift'], 1e-9);

        // Next quarter, with no new event, the higher base is still there — this is the behaviour the
        // old one-quarter revenue multiplier could not produce. The engine's expected revenue arrives
        // with the multiplier in it, and the physics strips it back out before applying the franchise.
        $nextQuarter = $model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE * $multiplier,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [false])
        );

        $carriedWeight = $nextQuarter->streamZ['weight:commercial_therapeutics'];
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * $carriedWeight * 1.12,
            $nextQuarter->streamRevenue['commercial_therapeutics'],
            1.0
        );
        $this->assertEqualsWithDelta(1.12, $nextQuarter->streamZ[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-9);
        $this->assertNull($nextQuarter->eventType);
        // With no draw and the known step inside expected revenue, the quarter is no surprise at all.
        $this->assertEqualsWithDelta(0.0, $nextQuarter->observableShockZ, 1e-9);
    }

    public function testAScheduledPatentCliffReachesExpectedRevenueBeforeItErodesTheActual(): void
    {
        $model = new BiotechBusinessModel();

        // Two quarters of exclusivity left: this quarter is clean, but the physics already knows the cliff
        // lands next quarter and persists next quarter's erosion as the known commercial shift.
        $stock = $this->makeStock([BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS => 2.0]);
        $clean = $model->computeActualFinancials($stock, self::EXPECTED_REVENUE, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, new MacroStateDTO(), $this->mockMath([0.0, 0.0], [false]));

        $this->assertNull($clean->eventType);
        $firstQuarterErosion = BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE * $this->loeErodedShare(1.0);
        $expectedShift = -$clean->streamZ['weight:commercial_therapeutics'] * $firstQuarterErosion;
        $this->assertEqualsWithDelta($expectedShift, $clean->streamZ[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT], 1e-9);
        $this->assertLessThan(0.0, $expectedShift);

        // The cliff quarter: the engine's expected revenue arrives lower by the shift; the physics erodes the
        // base by the same fraction, so actual meets expected and the cliff is a known event, not a miss.
        $stock->setEarningsMomentumZ($clean->streamZ);
        $expectedRevenue = self::EXPECTED_REVENUE * (1.0 + $expectedShift);
        $cliff = $model->computeActualFinancials($stock, $expectedRevenue, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, new MacroStateDTO(), $this->mockMath([0.0, 0.0], [false]));

        $this->assertSame(ShockEvent::BIOTECH_PATENT_CLIFF, $cliff->eventType);
        $this->assertEqualsWithDelta($expectedRevenue, $cliff->actualRevenue, 1.0);
        $this->assertEqualsWithDelta(0.0, $cliff->observableShockZ, 1e-9);
        // And the erosion keeps deepening on a known schedule: the next shift is more negative still.
        $this->assertLessThan($expectedShift, $cliff->streamZ[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT]);
    }

    public function testFailedPivotalTrialLeavesMarketedRevenueIntact(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();

        // A pivotal readout fires and misses its primary endpoint.
        $math = $this->mockMath([0.0, 0.0], [true, false]);

        $result = $model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $math
        );

        $this->assertSame(ShockEvent::BIOTECH_TRIAL_SETBACK, $result->eventType);

        // Collaboration and milestone income collapses...
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::PIPELINE_DRUG_WEIGHT * BiotechBusinessModel::TRIAL_FAILURE_PIPELINE_RETENTION,
            $result->streamRevenue['pipeline_licensing_milestones'],
            1.0
        );

        // ...but a drug that never reached the market cannot cut marketed prescription revenue.
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::ESTABLISHED_DRUG_WEIGHT,
            $result->streamRevenue['commercial_therapeutics'],
            1.0
        );

        // The terminated program is written off through variable costs: 0.30 + 0.10 * 0.30 = 0.33
        $this->assertEqualsWithDelta(0.33, $result->clampedMargin, 1e-9);

        // The franchise base is untouched: failure destroys optionality, not marketed products.
        $this->assertEqualsWithDelta(1.0, $result->streamZ[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-9);
    }

    public function testLossOfExclusivityErodesMarketedRevenueOnSchedule(): void
    {
        $model = new BiotechBusinessModel();

        // One quarter of exclusivity left: the clock expires this quarter and generic entry begins.
        $stock = $this->makeStock([BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS => 1.0]);

        $result = $model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [false])
        );

        $this->assertSame(ShockEvent::BIOTECH_PATENT_CLIFF, $result->eventType);
        $this->assertTrue($result->isPublicEvent);

        $erodedFraction = BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE * $this->loeErodedShare(1.0);
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::ESTABLISHED_DRUG_WEIGHT * (1.0 - $erodedFraction),
            $result->streamRevenue['commercial_therapeutics'],
            1.0
        );

        // Price concessions defending the brand: 0.30 + 0.04 * 0.35 * 0.70 = 0.3098
        $this->assertEqualsWithDelta(0.3098, $result->clampedMargin, 1e-9);

        // The whole exposed book leaves exclusivity the day generics may enter, not as its sales erode.
        $protectedFranchise = BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE - BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE;
        $this->assertEqualsWithDelta($protectedFranchise, $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_FRANCHISE], 1e-9);
        $this->assertEqualsWithDelta(
            $protectedFranchise / (1.0 - $erodedFraction),
            $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE],
            1e-9
        );

        // The cliff is a revenue event, not a margin drift: revenue falls while the cost ratio barely moves.
        $this->assertLessThan(self::EXPECTED_REVENUE, $result->actualRevenue);
    }

    public function testCompletedErosionWindowFoldsIntoThePermanentBaseAndResetsTheClock(): void
    {
        $model = new BiotechBusinessModel();

        // Final quarter of a 12-quarter erosion window.
        $stock = $this->makeStock([
            BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS => 0.0,
            BiotechBusinessModel::STATE_LOE_ELAPSED_QUARTERS => BiotechBusinessModel::LOE_EROSION_WINDOW_QUARTERS - 1.0,
        ]);

        $result = $model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [false])
        );

        $terminalErosion = 1.0 - (BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE
            * $this->loeErodedShare(BiotechBusinessModel::LOE_EROSION_WINDOW_QUARTERS));

        $state = $result->streamZ;
        // ~25% of the marketed base is permanently gone (0.745 retained): the biologic half decays on the slow biosimilar curve.
        $this->assertEqualsWithDelta($terminalErosion, $state[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-9);
        $this->assertEqualsWithDelta(0.745, $state[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 0.001);

        // The window closes and the clock is rearmed for the next franchise's patent life.
        $this->assertSame(0.0, $state[BiotechBusinessModel::STATE_LOE_ELAPSED_QUARTERS]);
        $this->assertSame(BiotechBusinessModel::DEFAULT_EXCLUSIVITY_QUARTERS, $state[BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS]);
    }

    /** Revenue folded into the base off patent stays unprotected: the share does not snap back when the window closes. */
    public function testTheProtectedShareHoldsAfterTheErosionWindowCloses(): void
    {
        $model = new BiotechBusinessModel();
        $protectedFranchise = BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE - BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE;

        $closing = $model->computeActualFinancials(
            $this->makeStock([
                BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS => 0.0,
                BiotechBusinessModel::STATE_LOE_ELAPSED_QUARTERS => BiotechBusinessModel::LOE_EROSION_WINDOW_QUARTERS - 1.0,
                BiotechBusinessModel::STATE_LOE_EXPOSURE => BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE,
                BiotechBusinessModel::STATE_PROTECTED_FRANCHISE => $protectedFranchise,
            ]),
            self::EXPECTED_REVENUE, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [false])
        );

        $folded = 1.0 - (BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE * $this->loeErodedShare(BiotechBusinessModel::LOE_EROSION_WINDOW_QUARTERS));
        $share = $protectedFranchise / $folded;
        $this->assertEqualsWithDelta($share, $closing->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-9);
        $this->assertLessThan(BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE, $share);

        $stock = $this->makeStock($closing->streamZ);
        $after = $model->computeActualFinancials(
            $stock, self::EXPECTED_REVENUE, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [false])
        );
        $this->assertEqualsWithDelta($share, $after->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-9);
        $stock->setEarningsMomentumZ($after->streamZ);
        $this->assertLessThan(
            BiotechBusinessModel::GENERIC_SECULAR_GROWTH_RATE + ((BiotechBusinessModel::PATENTED_SECULAR_GROWTH_RATE - BiotechBusinessModel::GENERIC_SECULAR_GROWTH_RATE) * BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE),
            $model->getSecularGrowthRate($stock)
        );
    }

    public function testAnApprovalAddsItsLaunchToTheProtectedBook(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock([BiotechBusinessModel::STATE_PROTECTED_FRANCHISE => 0.5]);

        $result = $model->computeActualFinancials(
            $stock, self::EXPECTED_REVENUE, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [true, true])
        );

        $this->assertEqualsWithDelta(0.5 + BiotechBusinessModel::APPROVAL_FRANCHISE_STEP, $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_FRANCHISE], 1e-9);
        $this->assertEqualsWithDelta(0.62 / 1.12, $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-9);
    }

    /** A book mostly off patent already cannot lose more exclusivity than it has: the cliff takes what is left. */
    public function testACliffNeverErodesMoreThanTheProtectedBook(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock([
            BiotechBusinessModel::STATE_EXCLUSIVITY_QUARTERS => 1.0,
            BiotechBusinessModel::STATE_PROTECTED_FRANCHISE => 0.10,
        ]);

        $result = $model->computeActualFinancials(
            $stock, self::EXPECTED_REVENUE, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, new MacroStateDTO(),
            $this->mockMath([0.0, 0.0], [false])
        );

        $this->assertSame(ShockEvent::BIOTECH_PATENT_CLIFF, $result->eventType);
        $this->assertEqualsWithDelta(0.10, $result->streamZ[BiotechBusinessModel::STATE_LOE_EXPOSURE], 1e-9);
        $this->assertEqualsWithDelta(0.0, $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_FRANCHISE], 1e-9);
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::ESTABLISHED_DRUG_WEIGHT * (1.0 - (0.10 * $this->loeErodedShare(1.0))),
            $result->streamRevenue['commercial_therapeutics'],
            1.0
        );
    }

    public function testReadoutHazardScalesWithPipelineBreadthAndRndIntensity(): void
    {
        $model = new BiotechBusinessModel();
        $observed = [];

        $math = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ', 'checkProbability'])
            ->getMock();
        $math->method('generatePersistentZ')->willReturn(0.0);
        $math->method('checkProbability')->willReturnCallback(function (float $p) use (&$observed): bool {
            $observed[] = $p;
            return false;
        });

        // Baseline: 30% pipeline weight at replacement-rate R&D => 0.40 * 0.30 * 1.0
        $model->computeActualFinancials(
            $this->makeStock(),
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $math
        );
        $this->assertEqualsWithDelta(0.12, $observed[0], 1e-9);

        // A pipeline starved of R&D funding stops producing late-stage readouts.
        $starved = $this->makeStock([BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO => 0.25]);
        $model->computeActualFinancials(
            $starved,
            self::EXPECTED_REVENUE,
            self::VARIABLE_MARGIN,
            self::FIXED_COSTS,
            self::BASELINE_VOL,
            new MacroStateDTO(),
            $math
        );

        $starvedHazard = BiotechBusinessModel::PIVOTAL_READOUT_HAZARD
            * BiotechBusinessModel::PIPELINE_DRUG_WEIGHT
            * pow(0.25, BiotechBusinessModel::READOUT_HAZARD_RND_ELASTICITY);
        $this->assertEqualsWithDelta($starvedHazard, $observed[1], 1e-9);
        $this->assertLessThan($observed[0], $observed[1]);
    }

    /** A trial burn reaches value through the earnings the multiple is struck on, not as a second discount on top. */
    public function testResearchBurnDoesNotDiscountTheEarningsValueTwice(): void
    {
        $model = new BiotechBusinessModel();

        $this->assertSame(100.0, $model->calculateEarningsValue(50.0, 100.0));
        $this->assertSame(50.0, $model->calculateEarningsValue(50.0, 20.0), 'The revenue floor still binds under a thin multiple.');
    }

    public function testCoverageProfileAndModelTraits(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();

        $this->assertEquals(0.15, $model->getReversionSpeed());
        $this->assertEquals(0.015, $model->getMoatSpread());
        $this->assertEquals(0.125, $model->getCapExCompletionRate($stock));

        // Default branded book (85% protected): 0.010 + (0.045 - 0.010) * 0.85 = 0.03975
        $this->assertEqualsWithDelta(0.03975, $model->getSecularGrowthRate($stock), 1e-9);

        $weights = $model->getSurpriseBlendWeights();
        $this->assertEquals(0.20, $weights['eps_weight']);
        $this->assertEquals(0.80, $weights['revenue_weight']);

        $coverage = $model->getCoverageProfile($stock);
        $this->assertEquals(BiotechBusinessModel::BASE_COVERAGE_VISIBILITY, $coverage->baseVisibility);
        $this->assertEquals(BiotechBusinessModel::BASE_COVERAGE_ERROR, $coverage->errorStdDev);
        $this->assertEquals(BiotechBusinessModel::BASE_COVERAGE_MIN_VISIBILITY, $coverage->minVisibility);
        $this->assertEquals(BiotechBusinessModel::EVENT_BASE_VISIBILITY, $coverage->eventBaseVisibility);
        $this->assertEquals(BiotechBusinessModel::EVENT_MIN_VISIBILITY, $coverage->eventMinVisibility);
    }

    public function testPartitionedStreamVariance(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();

        $math = $this->mockMath([1.0, 1.0], [false]);

        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: self::EXPECTED_REVENUE,
            realizedVariableMargin: 0.20,
            fixedCosts: self::FIXED_COSTS,
            baselineVol: self::BASELINE_VOL,
            macroState: new MacroStateDTO(),
            mathUtility: $math
        );

        // Commercial shock = 1.0 * (0.10 * 0.15) = +0.015 (+1.5%)
        // Pipeline shock = 1.0 * (0.10 * 0.45) = +0.045 (+4.5%)
        // Established revenue = 70M * 1.015 = 71.05M
        // Pipeline revenue = 30M * 1.045 = 31.35M
        $this->assertEqualsWithDelta(71_050_000.0, $result->streamRevenue['commercial_therapeutics'], 1.0);
        $this->assertEqualsWithDelta(31_350_000.0, $result->streamRevenue['pipeline_licensing_milestones'], 1.0);
    }

    public function testReimbursementRateGrowthLiftsCommercialTherapeuticsRevenue(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();

        $mathBaseline = $this->mockMath([0.0, 0.0], [false]);
        $baseResult = $model->computeActualFinancials(
            $stock,
            expectedRevenue: self::EXPECTED_REVENUE,
            realizedVariableMargin: 0.20,
            fixedCosts: self::FIXED_COSTS,
            baselineVol: self::BASELINE_VOL,
            macroState: new MacroStateDTO(), // Baseline reimbursementRateGrowth (0.014)
            mathUtility: $mathBaseline
        );

        $mathUplift = $this->mockMath([0.0, 0.0], [false]);
        $upliftResult = $model->computeActualFinancials(
            $stock,
            expectedRevenue: self::EXPECTED_REVENUE,
            realizedVariableMargin: 0.20,
            fixedCosts: self::FIXED_COSTS,
            baselineVol: self::BASELINE_VOL,
            macroState: new MacroStateDTO(reimbursementRateGrowth: 0.04), // +4% reimbursement update vs 1.4% baseline
            mathUtility: $mathUplift
        );

        // Branded commercial stream expands with reimbursement rate growth above baseline (+2.6%)
        $this->assertGreaterThan(
            $baseResult->streamRevenue['commercial_therapeutics'],
            $upliftResult->streamRevenue['commercial_therapeutics']
        );
        $expectedUplift = 1.0 + (0.04 - (MacroEngine::TARGET_INFLATION - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET));
        $this->assertEqualsWithDelta(
            $baseResult->streamRevenue['commercial_therapeutics'] * $expectedUplift,
            $upliftResult->streamRevenue['commercial_therapeutics'],
            1.0
        );
        // Pipeline milestone licensing stream is unaffected by healthcare reimbursement updates
        $this->assertEqualsWithDelta(
            $baseResult->streamRevenue['pipeline_licensing_milestones'],
            $upliftResult->streamRevenue['pipeline_licensing_milestones'],
            1.0
        );
    }
}
