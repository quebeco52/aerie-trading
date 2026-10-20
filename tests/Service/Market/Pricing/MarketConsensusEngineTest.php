<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\DTO\ActualFinancialsDTO;
use App\DTO\ConsensusDTO;
use App\DTO\SectorCoverageProfile;
use App\Entity\Stock;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Analysts know the public quarter (the firm's physics averaged over its private draws) and hear the firm's own news
 * at their coverage visibility.
 */
#[AllowMockObjectsWithoutExpectations]
class MarketConsensusEngineTest extends TestCase
{
    private MathUtility&Stub $mathUtilityMock;
    private MarketConsensusEngine $engine;

    protected function setUp(): void
    {
        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->engine = new MarketConsensusEngine();
    }

    private static function quarter(float $revenue, float $costRatio = 0.40, bool $publicEvent = false): ActualFinancialsDTO
    {
        return new ActualFinancialsDTO(
            actualRevenue: $revenue,
            actualVariableCosts: $revenue * $costRatio,
            clampedMargin: $costRatio,
            ebit: $revenue * (1.0 - $costRatio) - 100.0,
            isPublicEvent: $publicEvent,
        );
    }

    private function consensus(ActualFinancialsDTO $actuals, ActualFinancialsDTO $public, SectorCoverageProfile $coverage, ?Stock $stock = null, float $base = 1000.0, float $priorBase = 0.0): ConsensusDTO
    {
        return $this->engine->generateConsensus($actuals, $public, $coverage, $this->mathUtilityMock, $stock ?? new Stock(), 0.15, $base, 0.0, 1.0, $priorBase);
    }

    public function testThePrivateNewsIsHeardAtVisibility(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.05, minVisibility: 0.20);

        // Public quarter 1000, actual 1100: half the private 10% is heard, a fresh signal of 1050.
        $consensus = $this->consensus(self::quarter(1100.0), self::quarter(1000.0), $coverage);

        $this->assertSame(0.50, $consensus->dynamicVisibility);
        // The Bayesian update blends the structural prior (1000) with the fresh signal (1050): ~1036.26 unbiased,
        // ~1020.72 after the 1.5% walk-down, and costs struck on the unbiased revenue at the cost ratio, 414.51.
        $this->assertEqualsWithDelta(1036.26, $consensus->analystUnbiasedRevenue, 0.05);
        $this->assertEqualsWithDelta(1020.72, $consensus->analystExpectedRevenue, 0.05);
        $this->assertEqualsWithDelta(414.51, $consensus->analystExpectedVariableCosts, 0.05);
    }

    /**
     * A quarter that moved only on what is public, a benchmark price, a disclosed backlog or the cycle, is forecast
     * in full whatever the coverage: a titan's analysts and a small name's hear the same move.
     */
    public function testAPublicMoveIsHeardInFullWhateverTheCoverage(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $estimate = fn(float $visibility): float => $this->consensus(self::quarter(1500.0), self::quarter(1500.0), new SectorCoverageProfile(baseVisibility: $visibility, errorStdDev: 0.05), base: 1500.0)
            ->analystUnbiasedRevenue;

        $this->assertEqualsWithDelta(1500.0, $estimate(0.20), 1e-9);
        $this->assertEqualsWithDelta(1500.0, $estimate(0.95), 1e-9);
    }

    public function testVisibilityIsClampedToItsFloor(): void
    {
        // Analyst error -10.0 * 0.05 = -0.50 takes visibility below the floor.
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-10.0);
        $consensus = $this->consensus(self::quarter(1200.0), self::quarter(1000.0), new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.05, minVisibility: 0.25));

        $this->assertSame(0.25, $consensus->dynamicVisibility);
    }

    public function testVisibilityIsClampedToOne(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(10.0);
        $consensus = $this->consensus(self::quarter(1100.0), self::quarter(1000.0), new SectorCoverageProfile(baseVisibility: 0.80, errorStdDev: 0.05, minVisibility: 0.20));

        $this->assertSame(1.0, $consensus->dynamicVisibility);
        // The whole private 10% is heard: fresh 1100 -> posterior ~1072.52, ~1056.44 after the walk-down.
        $this->assertEqualsWithDelta(1056.44, $consensus->analystExpectedRevenue, 0.05);
    }

    public function testAPublicEventOpensTheEventVisibility(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        // Routine visibility is low (0.10); an announced event lifts it to 0.90.
        $coverage = new SectorCoverageProfile(baseVisibility: 0.10, errorStdDev: 0.05, minVisibility: 0.0, eventBaseVisibility: 0.90, eventMinVisibility: 0.50);

        $event = $this->consensus(self::quarter(2000.0, 0.20, true), self::quarter(1000.0, 0.20), $coverage);
        $routine = $this->consensus(self::quarter(1100.0, 0.20), self::quarter(1000.0, 0.20), $coverage);

        $this->assertSame(0.90, $event->dynamicVisibility);
        // 1000 * (1 + 1.0 * 0.90) = 1900 fresh -> posterior ~1652.75, ~1627.96 after the walk-down.
        $this->assertEqualsWithDelta(1627.96, $event->analystExpectedRevenue, 0.05);
        $this->assertSame(0.10, $routine->dynamicVisibility);
    }

    /**
     * The cost estimate is the public quarter's cost ratio, short of the input-cost change since the last report by
     * the share analysts cannot read off published prices, with the firm's own cost news heard at visibility.
     */
    public function testTheCostRatioIsThePublicQuartersPlusPrivateNewsAtVisibility(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.05, minVisibility: 0.0);

        $consensus = $this->engine->generateConsensus(self::quarter(1000.0, 0.50), self::quarter(1000.0, 0.44), $coverage, $this->mathUtilityMock, new Stock(), 0.15, 1000.0, 0.04);

        // 0.44 - 0.25 * 0.04 + 0.50 * (0.50 - 0.44) = 0.46 on the 1000 the estimate settles on.
        $this->assertEqualsWithDelta(0.46 * $consensus->analystUnbiasedRevenue, $consensus->analystExpectedVariableCosts, 1e-6);
    }

    // --- The anchor rolls forward with the structural base ---

    public function testTheAnchorIsCarriedForwardWithTheFirmsStructuralBase(): void
    {
        // Analysts forecast off disclosed capacity. A base that grew 20% since the last report lifts the
        // anchor 20% before it is blended — frozen in dollars, a sector with a wide coverage error closed
        // under a quarter of the gap each report and a fast-growing firm beat every quarter forever.
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.15, minVisibility: 0.20);

        $stock = new Stock();
        $stock->setLastAnalystRevenue('1000.0');

        $rolled = $this->consensus(self::quarter(1200.0), self::quarter(1200.0), $coverage, $stock, 1200.0, 1000.0);

        // Prior 1200 (rolled) and fresh 1200 agree, so only the walkdown separates the estimate from the base.
        $this->assertEqualsWithDelta(1200.0 * (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS), $rolled->analystExpectedRevenue, 1e-6);
    }

    public function testWithoutAKnownPriorBaseTheAnchorStaysSticky(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.15, minVisibility: 0.20);

        $stock = new Stock();
        $stock->setLastAnalystRevenue('1000.0');

        $frozen = $this->consensus(self::quarter(1200.0), self::quarter(1200.0), $coverage, $stock, 1200.0, 0.0);

        $this->assertLessThan(1100.0, $frozen->analystExpectedRevenue);
        $this->assertGreaterThan(1000.0, $frozen->analystExpectedRevenue);
    }

    public function testTheRollForwardIsBoundedSoACapacityCapOrCollapseIsNotExtrapolated(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $coverage = new SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.15, minVisibility: 0.20);

        $stock = new Stock();
        $stock->setLastAnalystRevenue('1000.0');

        $bounded = $this->consensus(self::quarter(5000.0), self::quarter(5000.0), $coverage, $stock, 5000.0, 1000.0);

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
            $estimate = $this->consensus(self::quarter(1000.0), self::quarter(1000.0), $coverage, $stock, 1000.0, 1000.0)->analystExpectedRevenue;
        }

        $this->assertEqualsWithDelta(1000.0 * (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS), $estimate, 0.5);
        $this->assertEqualsWithDelta(1000.0, (float) $stock->getLastAnalystRevenue(), 0.5, 'The anchor itself carries no walkdown.');
    }
}
