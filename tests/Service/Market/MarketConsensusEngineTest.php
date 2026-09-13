<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\ActualFinancialsDTO;
use App\DTO\ConsensusDTO;
use App\DTO\SectorCoverageProfile;
use App\Entity\Stock;
use App\Service\Market\MarketConsensusEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MarketConsensusEngineTest extends TestCase
{
    private MathUtility&Stub $mathUtilityMock;
    private MarketConsensusEngine $engine;

    protected function setUp(): void
    {
        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->mathUtilityMock->method('calculateBayesianAnalystUpdate')->willReturnCallback(
            fn(float $pEst, float $pVar, float $sEst, float $sVar) => (new MathUtility())->calculateBayesianAnalystUpdate($pEst, $pVar, $sEst, $sVar)
        );
        $this->engine = new MarketConsensusEngine();
    }

    public function testGenerateConsensusRoutine(): void
    {
        // Mock standard normal return of 0.0 -> zero analyst estimation error
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1100.0,
            actualVariableCosts: 440.0,
            clampedMargin: 0.40,
            ebit: 360.0,
            primaryShockZ: 1.0,
            observableShockZ: 0.10, // 10% positive observable shock
            eventType: null,
            eventContext: [],
            isPublicEvent: false
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.50,
            errorStdDev: 0.05,
            minVisibility: 0.20
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock, new Stock());

        $this->assertInstanceOf(ConsensusDTO::class, $consensus);
        $this->assertSame(0.50, $consensus->dynamicVisibility);
        // Bayesian update blends structural prior ($1000) with fresh signal ($1050) -> ~1036.26, minus 1.5% walkdown -> ~1020.72
        $this->assertEqualsWithDelta(1020.72, $consensus->analystExpectedRevenue, 0.05);
        // analystExpectedVariableCosts = analystExpectedRevenue * clampedMargin = 1020.72 * 0.40 = 408.29
        $this->assertEqualsWithDelta(408.29, $consensus->analystExpectedVariableCosts, 0.05);
    }

    public function testGenerateConsensusClampingToMinVisibility(): void
    {
        // Mock extreme negative analyst error (-10.0 * 0.05 = -0.50)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-10.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1000.0,
            actualVariableCosts: 300.0,
            clampedMargin: 0.30,
            ebit: 400.0,
            primaryShockZ: 0.0,
            observableShockZ: 0.20,
            eventType: null
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.50,
            errorStdDev: 0.05,
            minVisibility: 0.25
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock, new Stock());

        // baseVisibility (0.50) - 0.50 = 0.0, clamped to minVisibility (0.25)
        $this->assertSame(0.25, $consensus->dynamicVisibility);
        $this->assertEqualsWithDelta(1020.72, $consensus->analystExpectedRevenue, 0.05);
    }

    public function testGenerateConsensusClampingToMaxVisibility(): void
    {
        // Mock extreme positive analyst error (+10.0 * 0.05 = +0.50)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(10.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1000.0,
            actualVariableCosts: 300.0,
            clampedMargin: 0.30,
            ebit: 400.0,
            primaryShockZ: 0.0,
            observableShockZ: 0.10,
            eventType: null
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.80,
            errorStdDev: 0.05,
            minVisibility: 0.20
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock, new Stock());

        // baseVisibility (0.80) + 0.50 = 1.30, clamped to 1.0
        $this->assertSame(1.0, $consensus->dynamicVisibility);
        $this->assertEqualsWithDelta(1056.44, $consensus->analystExpectedRevenue, 0.05);
    }

    public function testGenerateConsensusEventConditionalForkWhenPublicEventIsTrue(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 2000.0,
            actualVariableCosts: 400.0,
            clampedMargin: 0.20,
            ebit: 1000.0,
            primaryShockZ: 3.0,
            observableShockZ: 1.0,
            eventType: 'CLINICAL_TRIAL_SUCCESS',
            eventContext: [],
            isPublicEvent: true
        );

        // Biotech / ConsumerStaples style profile: routine visibility is low (0.10), but event visibility is high (0.90)
        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.10,
            errorStdDev: 0.05,
            minVisibility: 0.0,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.50
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock, new Stock());

        $this->assertSame(0.90, $consensus->dynamicVisibility);
        // 1000 * (1 + 1.0 * 0.90) = 1900.0 fresh signal -> posterior ~1652.75, minus 1.5% walkdown -> ~1627.96
        $this->assertEqualsWithDelta(1627.96, $consensus->analystExpectedRevenue, 0.05);
    }

    public function testGenerateConsensusUsesRoutineVisibilityWhenPublicEventIsFalse(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1050.0,
            actualVariableCosts: 210.0,
            clampedMargin: 0.20,
            ebit: 600.0,
            primaryShockZ: 0.5,
            observableShockZ: 0.1,
            eventType: null,
            eventContext: [],
            isPublicEvent: false
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.10,
            errorStdDev: 0.05,
            minVisibility: 0.0,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.50
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock, new Stock());

        $this->assertSame(0.10, $consensus->dynamicVisibility);
        // 1000 * (1 + 0.1 * 0.10) = 1010.0 fresh signal -> posterior ~1007.25, minus 1.5% walkdown -> ~992.14
        $this->assertEqualsWithDelta(992.14, $consensus->analystExpectedRevenue, 0.05);
    }

    // --- The anchor rolls forward with the structural base ---

    private function flatActuals(float $revenue): ActualFinancialsDTO
    {
        return new ActualFinancialsDTO(
            actualRevenue: $revenue,
            actualVariableCosts: $revenue * 0.4,
            clampedMargin: 0.40,
            ebit: $revenue * 0.3,
            primaryShockZ: 0.0,
            observableShockZ: 0.0,
            eventType: null,
            eventContext: [],
            isPublicEvent: false
        );
    }

    public function testTheAnchorIsCarriedForwardWithTheFirmsStructuralBase(): void
    {
        // Analysts forecast off disclosed capacity. A base that grew 20% since the last report lifts the
        // anchor 20% before it is blended — frozen in dollars, a sector with a wide coverage error closed
        // under a quarter of the gap each report and a fast-growing firm beat every quarter forever.
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.15, minVisibility: 0.20);

        $stock = new Stock();
        $stock->setLastAnalystRevenue('1000.0');

        $rolled = $this->engine->generateConsensus($this->flatActuals(1200.0), $coverage, 1200.0, $this->mathUtilityMock, $stock, 0.15, null, 1.0, 1000.0);

        // Prior 1200 (rolled) and fresh 1200 agree, so only the walkdown separates the estimate from the base.
        $this->assertEqualsWithDelta(1200.0 * (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS), $rolled->analystExpectedRevenue, 1e-6);
    }

    public function testWithoutAKnownPriorBaseTheAnchorBehavesAsBefore(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.15, minVisibility: 0.20);

        $stock = new Stock();
        $stock->setLastAnalystRevenue('1000.0');

        $frozen = $this->engine->generateConsensus($this->flatActuals(1200.0), $coverage, 1200.0, $this->mathUtilityMock, $stock, 0.15, null, 1.0, 0.0);

        // Sticky: the estimate sits well below the base, which is the defect the roll-forward removes.
        $this->assertLessThan(1100.0, $frozen->analystExpectedRevenue);
        $this->assertGreaterThan(1000.0, $frozen->analystExpectedRevenue);
    }

    public function testTheRollForwardIsBoundedSoACapacityCapOrCollapseIsNotExtrapolated(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.15, minVisibility: 0.20);

        $stock = new Stock();
        $stock->setLastAnalystRevenue('1000.0');

        $bounded = $this->engine->generateConsensus($this->flatActuals(5000.0), $coverage, 5000.0, $this->mathUtilityMock, $stock, 0.15, null, 1.0, 1000.0);

        // Anchor rolled to at most 2000, fresh 5000: the estimate cannot have jumped all the way.
        $this->assertLessThan(4000.0, $bounded->analystExpectedRevenue);
        $this->assertGreaterThan(2000.0, $bounded->analystExpectedRevenue);
    }

    public function testTheWalkdownIsNotLearnedFromSoItDoesNotCompoundThroughTheAnchor(): void
    {
        // A flat base, quarter after quarter: the published number settles one walkdown below the base,
        // not lower. Anchored on the shaded figure, a sticky sector compounded the bias into a standing
        // deficit several times its size.
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.15, minVisibility: 0.20);
        $stock = new Stock();

        $estimate = 0.0;
        for ($quarter = 0; $quarter < 40; $quarter++) {
            $estimate = $this->engine->generateConsensus($this->flatActuals(1000.0), $coverage, 1000.0, $this->mathUtilityMock, $stock, 0.15, null, 1.0, 1000.0)->analystExpectedRevenue;
        }

        $this->assertEqualsWithDelta(1000.0 * (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS), $estimate, 0.5);
        $this->assertEqualsWithDelta(1000.0, (float) $stock->getLastAnalystRevenue(), 0.5, 'The anchor itself carries no walkdown.');
    }
}
