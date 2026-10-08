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
     * @param list<float> $persistentZ   Consecutive generatePersistentZ() returns (established, pipeline).
     * @param list<int>   $readouts      Consecutive generatePoissonCount() returns (pivotal readouts this quarter).
     * @param list<bool>  $probabilities Consecutive checkProbability() returns (one per readout: approved or not).
     */
    private function mockMath(array $persistentZ, array $readouts = [], array $probabilities = []): MathUtility&MockObject
    {
        $mock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ', 'checkProbability', 'generatePoissonCount'])
            ->getMock();

        $mock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(...$persistentZ);
        $mock->method('generatePoissonCount')->willReturnOnConsecutiveCalls(...($readouts ?: [0]));
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

    /** Share of an expired cohort eroded after `quarters` off exclusivity: the 50/50 mixture of the two modality survival curves. */
    private function loeErodedShare(float $quarters): float
    {
        $biologic = BiotechBusinessModel::DEFAULT_BIOLOGIC_REVENUE_SHARE;

        return ($biologic * (1.0 - exp(-BiotechBusinessModel::BIOLOGIC_LOE_HAZARD * $quarters)))
            + ((1.0 - $biologic) * (1.0 - exp(-BiotechBusinessModel::SMALL_MOLECULE_LOE_HAZARD * $quarters)));
    }

    /**
     * Equity pay is blended on the firm's own book: Ibis, 85% marketed franchise, pays near the pharmaceutical
     * industry's 1.71% of revenue rather than the biotechnology industry's 7.20%; the default mix sits between.
     */
    public function testStockCompensationIsBlendedOnTheFirmsMarketedAndPipelineBook(): void
    {
        $model = new BiotechBusinessModel();
        $ibis = $this->makeStock();
        $ibis->setTicker('IBIS');

        self::assertEqualsWithDelta(
            (0.85 * BiotechBusinessModel::MARKETED_STOCK_COMPENSATION_INTENSITY) + (0.15 * BiotechBusinessModel::PIPELINE_STOCK_COMPENSATION_INTENSITY),
            $model->getStockCompensationIntensity($ibis),
            1e-12
        );
        self::assertEqualsWithDelta(
            (BiotechBusinessModel::ESTABLISHED_DRUG_WEIGHT * BiotechBusinessModel::MARKETED_STOCK_COMPENSATION_INTENSITY)
                + (BiotechBusinessModel::PIPELINE_DRUG_WEIGHT * BiotechBusinessModel::PIPELINE_STOCK_COMPENSATION_INTENSITY),
            $model->getStockCompensationIntensity($this->makeStock()),
            1e-12
        );
        self::assertLessThan(0.03, $model->getStockCompensationIntensity($ibis), 'a big pharma does not pay like a clinical-stage biotech');
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

    public function testRndReplacementRatioIsPersistedForNextQuartersPipelineRefill(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();
        $stock->setOperatingMargin('0.30');

        $model->applyAssetDepreciationDecay($stock, 0.40, 0.25);

        $state = $stock->getEarningsMomentumZ() ?? [];
        $trend = 1.0 + ($model->getSecularGrowthRate($stock) / (float) $stock->getDepreciationRate());
        $this->assertEqualsWithDelta(0.40 / $trend, $state[BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO], 1e-12);
    }

    /** Capital growing with the firm's market spends (g + delta) / delta of depreciation; only spend above that refills faster. */
    public function testTrendGrowthCapexIsNotReadAsPipelineOverInvestment(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();
        $stock->setDepreciationRate('0.10');
        $trend = 1.0 + ($model->getSecularGrowthRate($stock) / 0.10);

        $model->applyAssetDepreciationDecay($stock, $trend, 0.25);
        $this->assertEqualsWithDelta(1.0, ($stock->getEarningsMomentumZ() ?? [])[BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO], 1e-12);

        $model->applyAssetDepreciationDecay($stock, 1.42, 0.25);
        $this->assertEqualsWithDelta(1.42 / $trend, ($stock->getEarningsMomentumZ() ?? [])[BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO], 1e-12);
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
        $math = $this->mockMath([0.0, 1.5]);

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


    /** Cumulative Bass adoption after `quarters`, from the closed form on the model's own constants. */
    private function bassAdoption(float $quarters): float
    {
        $ratio = BiotechBusinessModel::BASS_IMITATION_RATIO;
        $peak = BiotechBusinessModel::LAUNCH_PEAK_ADOPTION;
        $speed = -log((1.0 - $peak) / (1.0 + ($ratio * $peak))) / BiotechBusinessModel::LAUNCH_YEARS_TO_PEAK;
        $decay = exp(-$speed * $quarters / 4.0);

        return (1.0 - $decay) / (1.0 + ($ratio * $decay));
    }

    /**
     * A carried-in book: cohorts as [peak, adoption, exclusivity], the rest mature established product.
     *
     * @param list<array{float, float, float}> $cohorts
     * @param array<string, float>             $extra
     * @return array<string, float>
     */
    private function bookState(array $cohorts, float $established, float $assets = 0.0, array $extra = []): array
    {
        $state = [
            BiotechBusinessModel::STATE_ESTABLISHED_PRODUCTS => $established,
            BiotechBusinessModel::STATE_LATE_STAGE_ASSETS => $assets,
        ];
        foreach ($cohorts as $slot => [$peak, $adoption, $exclusivity]) {
            $state[BiotechBusinessModel::STATE_COHORT_PREFIX . $slot . ':peak'] = $peak;
            $state[BiotechBusinessModel::STATE_COHORT_PREFIX . $slot . ':adoption'] = $adoption;
            $state[BiotechBusinessModel::STATE_COHORT_PREFIX . $slot . ':exclusivity'] = $exclusivity;
        }

        return $extra + $state;
    }

    private function quarter(BiotechBusinessModel $model, Stock $stock, MathUtility $math, float $expectedRevenue = self::EXPECTED_REVENUE): \App\DTO\ActualFinancialsDTO
    {
        return $model->computeActualFinancials($stock, $expectedRevenue, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, new MacroStateDTO(), $math);
    }

    private function successRate(): float
    {
        return BiotechBusinessModel::PHASE_III_SUCCESS_RATE * BiotechBusinessModel::REGULATORY_APPROVAL_RATE;
    }

    /** The lore book: the lead franchise on the lore clock, followers staggered out to a new approval's life, the rest mature. */
    public function testTheLoreIsSeededAsAStaggeredCohortLedger(): void
    {
        $result = $this->quarter(new BiotechBusinessModel(), $this->makeStock(), $this->mockMath([0.0, 0.0]));
        $state = $result->streamZ;

        $lead = BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE;
        $follower = (BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE - $lead) / 3.0;
        $spacing = (BiotechBusinessModel::NEW_APPROVAL_EXCLUSIVITY_QUARTERS - BiotechBusinessModel::DEFAULT_EXCLUSIVITY_QUARTERS) / 4.0;

        $this->assertEqualsWithDelta($lead, $state['state:cohort:0:peak'], 1e-12);
        // One quarter has run on every clock.
        $this->assertEqualsWithDelta(BiotechBusinessModel::DEFAULT_EXCLUSIVITY_QUARTERS - 1.0, $state['state:cohort:0:exclusivity'], 1e-12);
        foreach ([1, 2, 3] as $slot) {
            $this->assertEqualsWithDelta($follower, $state["state:cohort:{$slot}:peak"], 1e-12);
            $this->assertEqualsWithDelta(1.0, $state["state:cohort:{$slot}:adoption"], 1e-12);
            $this->assertEqualsWithDelta(BiotechBusinessModel::DEFAULT_EXCLUSIVITY_QUARTERS + ($slot * $spacing) - 1.0, $state["state:cohort:{$slot}:exclusivity"], 1e-12);
        }
        $this->assertEqualsWithDelta(1.0 - BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE, $state[BiotechBusinessModel::STATE_ESTABLISHED_PRODUCTS], 1e-12);
        $this->assertEqualsWithDelta(1.0, $state[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-12);
        $this->assertEqualsWithDelta(BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE, $state[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-12);
        $this->assertEqualsWithDelta(1.0, $state[BiotechBusinessModel::STATE_STRUCTURAL_MULTIPLIER], 1e-12);
    }

    /** A firm that reported under the old franchise state keeps its size, so the switch does not move revenue; the split is the lore's. */
    public function testAFirmCarryingTheOldFranchiseStateKeepsItsBook(): void
    {
        $stock = $this->makeStock([
            BiotechBusinessModel::STATE_FRANCHISE_INDEX => 1.2,
            BiotechBusinessModel::STATE_PROTECTED_SHARE => 0.6,
        ]);
        $result = $this->quarter(new BiotechBusinessModel(), $stock, $this->mockMath([0.0, 0.0]));

        $this->assertEqualsWithDelta(1.2, $result->streamZ[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-12);
        // A share drained under the old physics would otherwise be held as established product for good.
        $this->assertEqualsWithDelta(BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE, $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-12);
        $this->assertEqualsWithDelta((1.0 - BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE) * 1.2, $result->streamZ[BiotechBusinessModel::STATE_ESTABLISHED_PRODUCTS], 1e-12);
    }

    /** A launch climbs the Bass curve and reaches peak adoption at the published time to peak. */
    public function testALaunchRampsOnTheBassCurveToPeak(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock($this->bookState([[0.1, 0.0, BiotechBusinessModel::NEW_APPROVAL_EXCLUSIVITY_QUARTERS]], 0.9));

        $quartersToPeak = (int) (BiotechBusinessModel::LAUNCH_YEARS_TO_PEAK * 4);
        $previous = 0.0;
        for ($q = 1; $q <= $quartersToPeak; $q++) {
            $result = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0]));
            $adoption = $result->streamZ['state:cohort:0:adoption'];
            $this->assertGreaterThan($previous, $adoption);
            $this->assertEqualsWithDelta($this->bassAdoption($q), $adoption, 1e-9);
            $previous = $adoption;
            $stock->setEarningsMomentumZ($result->streamZ);
        }

        $this->assertEqualsWithDelta(BiotechBusinessModel::LAUNCH_PEAK_ADOPTION, $previous, 1e-9);
    }

    /** Lifetime revenue per unit of peak: the ramp up to expiry, then each modality's erosion tail to zero. */
    public function testLifetimeRevenueIsTheRampThenTheErosionTail(): void
    {
        $biologic = 0.55;
        $life = (int) BiotechBusinessModel::NEW_APPROVAL_EXCLUSIVITY_QUARTERS;

        $expected = 0.0;
        for ($k = 1; $k < $life; $k++) {
            $expected += $this->bassAdoption($k);
        }
        $rb = exp(-BiotechBusinessModel::BIOLOGIC_LOE_HAZARD);
        $rs = exp(-BiotechBusinessModel::SMALL_MOLECULE_LOE_HAZARD);
        $expected += $this->bassAdoption($life - 1) * (($biologic * $rb / (1.0 - $rb)) + ((1.0 - $biologic) * $rs / (1.0 - $rs)));

        $this->assertEqualsWithDelta($expected, (new BiotechBusinessModel())->lifetimeRevenuePerPeak($biologic), 1e-9);
    }

    /** At replacement R&D the expected launches replace exactly the protected book the lore seeds. */
    public function testApprovalSizeSatisfiesTheReplacementIdentity(): void
    {
        $model = new BiotechBusinessModel();
        $assets = 4.0;
        $protected = 0.88;
        $biologic = 0.55;

        $approvalsPerQuarter = $assets / (4.0 * BiotechBusinessModel::PHASE_III_DURATION_YEARS) * $this->successRate();
        $inflow = $approvalsPerQuarter * $model->approvalPeakSize($assets, $protected, $biologic) * $model->lifetimeRevenuePerPeak($biologic);
        $this->assertEqualsWithDelta($protected, $inflow, 1e-12);

        // Nothing in late-stage development, nothing to launch.
        $this->assertSame(0.0, $model->approvalPeakSize(0.0, $protected, $biologic));
    }

    public function testAnApprovalAddsACohortThatRampsIntoExpectedRevenue(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock();

        // Flat streams, one pivotal readout, and it succeeds through to approval.
        $approvalQuarter = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0], [1], [true]));

        $this->assertSame(ShockEvent::BIOTECH_DRUG_APPROVAL, $approvalQuarter->eventType);
        $this->assertTrue($approvalQuarter->isPublicEvent);

        // The approval quarter books the milestone news on collaboration revenue: the outcome less the odds.
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::PIPELINE_DRUG_WEIGHT * (1.0 + (BiotechBusinessModel::READOUT_MILESTONE_SWING * (1.0 - $this->successRate()))),
            $approvalQuarter->streamRevenue['pipeline_licensing_milestones'],
            1.0
        );
        // The launch itself earns nothing yet, so marketed revenue is untouched.
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * BiotechBusinessModel::ESTABLISHED_DRUG_WEIGHT, $approvalQuarter->streamRevenue['commercial_therapeutics'], 1.0);

        // A fifth cohort, sized by the replacement identity, starting its ramp with a full exclusivity life.
        $state = $approvalQuarter->streamZ;
        $peak = $model->approvalPeakSize(BiotechBusinessModel::DEFAULT_LATE_STAGE_ASSETS, BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE, BiotechBusinessModel::DEFAULT_BIOLOGIC_REVENUE_SHARE);
        $this->assertEqualsWithDelta($peak, $state['state:cohort:4:peak'], 1e-12);
        $this->assertSame(0.0, $state['state:cohort:4:adoption']);
        $this->assertSame(BiotechBusinessModel::NEW_APPROVAL_EXCLUSIVITY_QUARTERS, $state['state:cohort:4:exclusivity']);

        // Next quarter's ramp is known and is new capacity, not overtime: it reaches EXPECTED revenue through the
        // structural multiplier, and the utilization shift carries no erosion at all.
        $weight = $state['weight:commercial_therapeutics'];
        $launched = 1.0 + ($peak * $this->bassAdoption(1.0));
        $multiplier = 1.0 + ($weight * ($launched - 1.0));
        $this->assertEqualsWithDelta($multiplier, $state[BiotechBusinessModel::STATE_STRUCTURAL_MULTIPLIER], 1e-12);
        $stock->setEarningsMomentumZ($state);
        $this->assertEqualsWithDelta($multiplier, $model->getStructuralRevenueMultiplier($stock), 1e-12);
        $this->assertEqualsWithDelta(0.0, $model->getMacroPhysics($stock, new MacroStateDTO())['macro_demand_shift'], 1e-12);

        // Next quarter the engine's expected revenue arrives with the multiplier in it; the physics strips it back
        // out, applies the ramped book, and with no draw the quarter is no surprise at all.
        $nextQuarter = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0]), self::EXPECTED_REVENUE * $multiplier);
        $carriedWeight = $nextQuarter->streamZ['weight:commercial_therapeutics'];
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $carriedWeight * $launched, $nextQuarter->streamRevenue['commercial_therapeutics'], 1.0);
        $this->assertNull($nextQuarter->eventType);
        $this->assertEqualsWithDelta(0.0, $nextQuarter->observableShockZ, 1e-9);
        // The ramping launch is protected revenue, so the protected share rises with it.
        $this->assertEqualsWithDelta(
            (BiotechBusinessModel::DEFAULT_PATENT_PROTECTED_SHARE + ($peak * $this->bassAdoption(1.0))) / $launched,
            $nextQuarter->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE],
            1e-12
        );
    }

    /** The readout's milestone news is centred on the odds: across outcomes it averages to nothing. */
    public function testReadoutNewsIsUnbiased(): void
    {
        $model = new BiotechBusinessModel();
        $success = $this->quarter($model, $this->makeStock(), $this->mockMath([0.0, 0.0], [1], [true]));
        $failure = $this->quarter($model, $this->makeStock(), $this->mockMath([0.0, 0.0], [1], [false]));

        $p = $this->successRate();
        $expected = ($p * $success->streamRevenue['pipeline_licensing_milestones']) + ((1.0 - $p) * $failure->streamRevenue['pipeline_licensing_milestones']);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * BiotechBusinessModel::PIPELINE_DRUG_WEIGHT, $expected, 1e-3);
        // A success is good news and a miss bad news in the event score.
        $this->assertGreaterThan(0.0, $success->primaryShockZ);
        $this->assertLessThan(0.0, $failure->primaryShockZ);
    }

    public function testFailedPivotalTrialLeavesMarketedRevenueIntact(): void
    {
        $result = $this->quarter(new BiotechBusinessModel(), $this->makeStock(), $this->mockMath([0.0, 0.0], [1], [false]));

        $this->assertSame(ShockEvent::BIOTECH_TRIAL_SETBACK, $result->eventType);

        // Collaboration and milestone income falls by the miss less the odds...
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::PIPELINE_DRUG_WEIGHT * (1.0 - (BiotechBusinessModel::READOUT_MILESTONE_SWING * $this->successRate())),
            $result->streamRevenue['pipeline_licensing_milestones'],
            1.0
        );

        // ...but a drug that never reached the market cannot cut marketed prescription revenue.
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * BiotechBusinessModel::ESTABLISHED_DRUG_WEIGHT, $result->streamRevenue['commercial_therapeutics'], 1.0);

        // The terminated program's wind-down is expensed: 0.30 + 0.10 * 0.30 = 0.33
        $this->assertEqualsWithDelta(0.33, $result->clampedMargin, 1e-9);

        // The book is untouched, and the programme is gone from late-stage development until R&D refills it.
        $this->assertEqualsWithDelta(1.0, $result->streamZ[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-12);
        $refill = BiotechBusinessModel::DEFAULT_LATE_STAGE_ASSETS / (4.0 * BiotechBusinessModel::PHASE_III_DURATION_YEARS);
        $this->assertEqualsWithDelta(BiotechBusinessModel::DEFAULT_LATE_STAGE_ASSETS - 1.0 + $refill, $result->streamZ[BiotechBusinessModel::STATE_LATE_STAGE_ASSETS], 1e-12);
    }

    public function testAScheduledPatentCliffReachesExpectedRevenueBeforeItErodesTheActual(): void
    {
        $model = new BiotechBusinessModel();
        $exposure = BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE;

        // Two quarters of exclusivity left on the lead cohort: this quarter is clean, but the physics already knows
        // the cliff lands next quarter and persists next quarter's erosion as the known commercial shift.
        $stock = $this->makeStock($this->bookState([[$exposure, 1.0, 2.0]], 1.0 - $exposure));
        $clean = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0]));

        $this->assertNull($clean->eventType);
        $expectedShift = -$clean->streamZ['weight:commercial_therapeutics'] * $exposure * $this->loeErodedShare(1.0);
        $this->assertEqualsWithDelta($expectedShift, $clean->streamZ[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT], 1e-12);
        $this->assertLessThan(0.0, $expectedShift);

        // The cliff quarter: the engine's expected revenue arrives lower by the shift; the physics erodes the book by
        // the same fraction, so actual meets expected and the cliff is a known event, not a miss.
        $stock->setEarningsMomentumZ($clean->streamZ);
        $expectedRevenue = self::EXPECTED_REVENUE * (1.0 + $expectedShift);
        $cliff = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0]), $expectedRevenue);

        $this->assertSame(ShockEvent::BIOTECH_PATENT_CLIFF, $cliff->eventType);
        $this->assertEqualsWithDelta($expectedRevenue, $cliff->actualRevenue, 1.0);
        $this->assertEqualsWithDelta(0.0, $cliff->observableShockZ, 1e-9);
        // The whole cohort leaves exclusivity the day generics may enter, not as its sales erode.
        $this->assertEqualsWithDelta(0.0, $cliff->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-12);
        // And the erosion keeps deepening on a known schedule: the next shift is more negative still.
        $this->assertLessThan($expectedShift, $cliff->streamZ[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT]);
    }

    public function testLossOfExclusivityErodesMarketedRevenueOnSchedule(): void
    {
        $exposure = BiotechBusinessModel::DEFAULT_LOE_EXPOSURE_SHARE;
        $stock = $this->makeStock($this->bookState([[$exposure, 1.0, 1.0], [0.5, 1.0, 30.0]], 0.15));

        $result = $this->quarter(new BiotechBusinessModel(), $stock, $this->mockMath([0.0, 0.0]));

        $this->assertSame(ShockEvent::BIOTECH_PATENT_CLIFF, $result->eventType);
        $this->assertTrue($result->isPublicEvent);

        $eroded = $exposure * $this->loeErodedShare(1.0);
        $this->assertEqualsWithDelta(
            self::EXPECTED_REVENUE * BiotechBusinessModel::ESTABLISHED_DRUG_WEIGHT * (1.0 - $eroded),
            $result->streamRevenue['commercial_therapeutics'],
            1.0
        );

        // Price concessions defending the brand: 0.30 + 0.04 * 0.35 * 0.70 = 0.3098
        $this->assertEqualsWithDelta(0.3098, $result->clampedMargin, 1e-9);

        // Only the cohort still under patent is protected.
        $this->assertEqualsWithDelta(0.5 / (1.0 - $eroded), $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-12);
        // The expiry was dated and already in expected revenue: it is no news.
        $this->assertEqualsWithDelta(0.0, $result->primaryShockZ, 1e-12);
    }

    /** An expired cohort is never folded back into a protected base: the protected share runs on with no snap-back. */
    public function testAClosedErosionWindowMovesTheCohortOffPatentWithoutSnapBack(): void
    {
        $model = new BiotechBusinessModel();
        $biologic = BiotechBusinessModel::DEFAULT_BIOLOGIC_REVENUE_SHARE;
        $window = BiotechBusinessModel::LOE_EROSION_WINDOW_QUARTERS;

        // The expired cohort enters its last window quarter (elapsed = window after this quarter's tick).
        $stock = $this->makeStock($this->bookState([[0.5, 1.0, 20.0], [0.35, 1.0, 2.0 - $window]], 0.15));
        $closing = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0]));
        $state = $closing->streamZ;

        $marketed = 0.5 + (0.35 * (1.0 - $this->loeErodedShare($window))) + 0.15;
        $this->assertEqualsWithDelta($marketed, $state[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-12);
        $this->assertEqualsWithDelta(0.5 / $marketed, $state[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-12);

        // The cohort leaves the ledger into the off-patent stocks, each modality at what is left of it.
        $this->assertArrayNotHasKey('state:cohort:1:peak', $state);
        $this->assertEqualsWithDelta($biologic * 0.35 * exp(-BiotechBusinessModel::BIOLOGIC_LOE_HAZARD * $window), $state[BiotechBusinessModel::STATE_OFF_PATENT_BIOLOGIC], 1e-12);
        $this->assertEqualsWithDelta((1.0 - $biologic) * 0.35 * exp(-BiotechBusinessModel::SMALL_MOLECULE_LOE_HAZARD * $window), $state[BiotechBusinessModel::STATE_OFF_PATENT_SMALL_MOLECULE], 1e-12);

        // The capacity base now follows the folded revenue: expected revenue next quarter is the eroded book exactly.
        $weight = $state['weight:commercial_therapeutics'];
        $nextMarketed = 0.5 + (0.35 * (1.0 - $this->loeErodedShare($window + 1.0))) + 0.15;
        $this->assertEqualsWithDelta(
            1.0 - $weight + ($weight * $nextMarketed),
            $state[BiotechBusinessModel::STATE_STRUCTURAL_MULTIPLIER] * (1.0 + $state[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT]),
            1e-12
        );
        $this->assertEqualsWithDelta(0.0, $state[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT], 1e-12);

        // Next quarter the share moves only as the off-patent tail keeps eroding: no snap-back to the lore value.
        $stock->setEarningsMomentumZ($state);
        $after = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0]));
        $this->assertEqualsWithDelta(0.5 / $nextMarketed, $after->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE], 1e-12);
        $this->assertEqualsWithDelta($nextMarketed, $after->streamZ[BiotechBusinessModel::STATE_FRANCHISE_INDEX], 1e-12);
    }

    /** Aging the book with no draws is the known schedule: expected revenue every quarter is exactly the book. */
    public function testTheKnownScheduleMatchesTheBookEveryQuarter(): void
    {
        $model = new BiotechBusinessModel();
        $stock = $this->makeStock($this->bookState([[0.3, 1.0, 3.0], [0.2, 0.4, 9.0], [0.25, 1.0, -4.0]], 0.25));

        for ($q = 0; $q < 24; $q++) {
            $state = $stock->getEarningsMomentumZ() ?? [];
            $expected = self::EXPECTED_REVENUE * $model->getStructuralRevenueMultiplier($stock) * (1.0 + (float) ($state[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT] ?? 0.0));
            $result = $this->quarter($model, $stock, $this->mockMath([0.0, 0.0]), $expected);
            if ($q > 0) {
                $this->assertEqualsWithDelta($expected, $result->actualRevenue, 1e-3, "Quarter {$q}");
            }
            $stock->setEarningsMomentumZ($result->streamZ);
        }
    }

    /** Each late-stage programme reads out at one over the Phase III duration; R&D sets how fast the pipeline refills. */
    public function testReadoutsScaleWithThePipelineAndRndRefillsIt(): void
    {
        $model = new BiotechBusinessModel();
        $means = [];

        $math = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ', 'checkProbability', 'generatePoissonCount'])
            ->getMock();
        $math->method('generatePersistentZ')->willReturn(0.0);
        $math->method('checkProbability')->willReturn(false);
        $math->method('generatePoissonCount')->willReturnCallback(function (float $mean) use (&$means): int {
            $means[] = $mean;

            return 0;
        });

        $perProgramme = 1.0 / (4.0 * BiotechBusinessModel::PHASE_III_DURATION_YEARS);
        $assets = BiotechBusinessModel::DEFAULT_LATE_STAGE_ASSETS;

        $steady = $this->quarter($model, $this->makeStock(), $math);
        $this->assertEqualsWithDelta($assets * $perProgramme, $means[0], 1e-12);
        // No readout and replacement R&D: the pipeline refills by what it is expected to read out.
        $this->assertEqualsWithDelta($assets + ($assets * $perProgramme), $steady->streamZ[BiotechBusinessModel::STATE_LATE_STAGE_ASSETS], 1e-12);

        // A thinner pipeline reads out less often.
        $this->quarter($model, $this->makeStock($this->bookState([], 1.0, 1.5)), $math);
        $this->assertEqualsWithDelta(1.5 * $perProgramme, $means[1], 1e-12);

        // A pipeline starved of R&D refills slower.
        $starved = $this->quarter($model, $this->makeStock($this->bookState([], 1.0, $assets, [BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO => 0.25])), $math);
        $refill = $assets * $perProgramme * pow(0.25, BiotechBusinessModel::PIPELINE_REFILL_RND_ELASTICITY);
        $this->assertEqualsWithDelta($assets + $refill, $starved->streamZ[BiotechBusinessModel::STATE_LATE_STAGE_ASSETS], 1e-12);
    }

    /** A full ledger makes room for a launch by moving the longest-expired cohort off patent early. */
    public function testAFullLedgerStillAdmitsALaunch(): void
    {
        $cohorts = [[0.05, 1.0, -3.0]];
        for ($i = 1; $i < BiotechBusinessModel::MAX_COHORTS; $i++) {
            $cohorts[] = [0.03, 1.0, 10.0 + $i];
        }
        $stock = $this->makeStock($this->bookState($cohorts, 0.2, 3.0));

        $result = $this->quarter(new BiotechBusinessModel(), $stock, $this->mockMath([0.0, 0.0], [1], [true]));

        $this->assertArrayNotHasKey('state:cohort:' . BiotechBusinessModel::MAX_COHORTS . ':peak', $result->streamZ);
        $this->assertSame(0.0, $result->streamZ['state:cohort:0:adoption']);
        $this->assertGreaterThan(0.0, $result->streamZ[BiotechBusinessModel::STATE_OFF_PATENT_BIOLOGIC]);
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

        $math = $this->mockMath([1.0, 1.0]);

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

        $mathBaseline = $this->mockMath([0.0, 0.0]);
        $baseResult = $model->computeActualFinancials(
            $stock,
            expectedRevenue: self::EXPECTED_REVENUE,
            realizedVariableMargin: 0.20,
            fixedCosts: self::FIXED_COSTS,
            baselineVol: self::BASELINE_VOL,
            macroState: new MacroStateDTO(), // Baseline reimbursementRateGrowth (0.014)
            mathUtility: $mathBaseline
        );

        $mathUplift = $this->mockMath([0.0, 0.0]);
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
