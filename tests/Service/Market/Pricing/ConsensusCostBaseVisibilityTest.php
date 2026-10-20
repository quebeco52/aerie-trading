<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\DTO\ActualFinancialsDTO;
use App\DTO\SectorCoverageProfile;
use App\Entity\Stock;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * What analysts can see of the cost base.
 *
 * The public quarter carries everything published: input prices through the cost basket, the season's mix and the
 * pass-through terms the firm discloses. Analysts read the input-cost change since the last report at
 * ANALYST_COST_BASE_VISIBILITY, so a lasting shock is missed once, when it arrives, rather than every quarter; the
 * firm's own cost news (its margin draw, a stream's mix) is heard at coverage visibility.
 */
#[AllowMockObjectsWithoutExpectations]
final class ConsensusCostBaseVisibilityTest extends TestCase
{
    private const REVENUE = 1_000_000_000.0;

    private static function quarter(float $costRatio, float $priceRevenue = 0.0): ActualFinancialsDTO
    {
        return new ActualFinancialsDTO(
            actualRevenue: self::REVENUE,
            actualVariableCosts: (self::REVENUE - $priceRevenue) * $costRatio,
            clampedMargin: $costRatio,
            ebit: 0.0,
            priceRevenue: $priceRevenue,
        );
    }

    /** The estimated cost ratio, on noise-free coverage of the given visibility. */
    private function expectedCostRatio(ActualFinancialsDTO $actuals, ActualFinancialsDTO $public, float $inputCostDragChange, float $visibility = 1.0): float
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);
        $consensus = (new MarketConsensusEngine())->generateConsensus(
            $actuals,
            $public,
            new SectorCoverageProfile(baseVisibility: $visibility, errorStdDev: 0.0, minVisibility: $visibility),
            $math,
            new Stock(),
            0.15,
            self::REVENUE,
            $inputCostDragChange,
        );
        $volumeShare = ($actuals->actualRevenue - $actuals->priceRevenue) / $actuals->actualRevenue;

        return $consensus->analystExpectedVariableCosts / ($consensus->analystUnbiasedRevenue * $volumeShare);
    }

    public function testAPublicCostMoveReachesTheEstimate(): void
    {
        $this->assertEqualsWithDelta(0.65, $this->expectedCostRatio(self::quarter(0.65), self::quarter(0.65), 0.0), 1e-9);
    }

    public function testTheInputCostChangeSinceTheLastReportIsReadAtItsVisibility(): void
    {
        $expected = 0.65 - ((1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY) * 0.05);

        $this->assertEqualsWithDelta($expected, $this->expectedCostRatio(self::quarter(0.65), self::quarter(0.65), 0.05), 1e-9);
    }

    public function testAStandingInputShockIsMissedOnce(): void
    {
        // The quarter it arrives: the drag moved 5 points since the last report, a quarter of which is news.
        $arrival = $this->expectedCostRatio(self::quarter(0.65), self::quarter(0.65), 0.05);
        // The quarter after: the same drag, no change since the report that booked it.
        $standing = $this->expectedCostRatio(self::quarter(0.65), self::quarter(0.65), 0.0);

        $this->assertLessThan(0.65, $arrival);
        $this->assertEqualsWithDelta(0.65, $standing, 1e-9);
    }

    public function testTheFirmsOwnCostNewsIsHeardAtCoverageVisibility(): void
    {
        $this->assertEqualsWithDelta(0.62, $this->expectedCostRatio(self::quarter(0.64), self::quarter(0.60), 0.0, 0.5), 1e-9);
    }

    public function testCostRatioIsChargedAgainstVolumeRevenueOnly(): void
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);
        $withPrice = self::quarter(0.60, 0.2 * self::REVENUE);
        $consensus = (new MarketConsensusEngine())->generateConsensus($withPrice, $withPrice, new SectorCoverageProfile(baseVisibility: 1.0, errorStdDev: 0.0, minVisibility: 1.0), $math, new Stock(), 0.15, self::REVENUE);

        // A fifth of the quarter's revenue is price, which costs nothing to produce.
        $this->assertEqualsWithDelta(0.8 * 0.60 * $consensus->analystUnbiasedRevenue, $consensus->analystExpectedVariableCosts, 1.0);
    }
}
