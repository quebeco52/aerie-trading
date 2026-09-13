<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\ActualFinancialsDTO;
use App\DTO\SectorCoverageProfile;
use App\Entity\Stock;
use App\Service\Market\MarketConsensusEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * What analysts can see of the cost base.
 *
 * The consensus used to be built entirely on the ex-ante variable cost ratio — the CIR draw, BEFORE the
 * sector physics moved it — so the input-cost basket, the pass-through lag and every other cost term the
 * models apply were invisible to it. Because the consensus anchor remembers revenue and not margin, a
 * standing cost shock was then re-discovered as a fresh miss every quarter for as long as it lasted:
 * measured across 46 business models under a sustained supply shock, 26 of them missed consensus in at
 * least 20 of 24 consecutive quarters, at a median mean surprise of -19%.
 *
 * Two things fix it, and both are needed. Analysts read published input-price series, so they forecast
 * most of a cost move (ANALYST_COST_BASE_VISIBILITY); and the estimate anchors on the ratio the firm last
 * REPORTED, so a lasting shock is missed once, when it arrives, rather than every quarter forever.
 */
#[AllowMockObjectsWithoutExpectations]
final class ConsensusCostBaseVisibilityTest extends TestCase
{
    private const REVENUE = 1_000_000_000.0;
    /** The structural cost ratio the margin process hands over, before any sector physics. */
    private const EX_ANTE_RATIO = 0.60;

    private function makeStock(?float $lastReportedCostRatio = null): Stock
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setLastReportedCostRatio($lastReportedCostRatio !== null ? (string) $lastReportedCostRatio : null);

        return $stock;
    }

    private function actuals(float $realizedCostRatio, float $priceRevenue = 0.0): ActualFinancialsDTO
    {
        return new ActualFinancialsDTO(
            actualRevenue: self::REVENUE,
            actualVariableCosts: (self::REVENUE - $priceRevenue) * $realizedCostRatio,
            clampedMargin: $realizedCostRatio,
            ebit: 0.0,
            primaryShockZ: 0.0,
            observableShockZ: 0.0,
            priceRevenue: $priceRevenue,
        );
    }

    /** Runs consensus with no estimation noise so the cost channel is isolated. */
    private function expectedCosts(ActualFinancialsDTO $actuals, Stock $stock): float
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);

        return (new MarketConsensusEngine())->generateConsensus(
            $actuals,
            new SectorCoverageProfile(baseVisibility: 1.0, errorStdDev: 0.0, minVisibility: 1.0),
            self::REVENUE,
            $math,
            $stock,
            0.15,
            self::EX_ANTE_RATIO,
        )->analystExpectedVariableCosts;
    }

    /** A cost move the physics produced has to reach the estimate; it used to be invisible. */
    public function testRealizedCostBaseReachesTheEstimate(): void
    {
        $stock = $this->makeStock(self::EX_ANTE_RATIO);
        $unshocked = $this->expectedCosts($this->actuals(self::EX_ANTE_RATIO), $stock);

        $shockedStock = $this->makeStock(self::EX_ANTE_RATIO);
        $shocked = $this->expectedCosts($this->actuals(0.75), $shockedStock);

        $this->assertGreaterThan(
            $unshocked,
            $shocked,
            'A firm whose realized cost ratio jumped must be forecast to have higher costs.'
        );
    }

    /** But not all of it: what analysts cannot see is what leaves room for a surprise at all. */
    public function testEstimateStopsShortOfPerfectForesight(): void
    {
        $realized = 0.75;
        $stock = $this->makeStock(self::EX_ANTE_RATIO);

        $this->assertLessThan(
            self::REVENUE * $realized,
            $this->expectedCosts($this->actuals($realized), $stock),
            'Perfect cost foresight would leave earnings surprises driven by revenue alone.'
        );
    }

    /** The blend is exactly the visibility constant, measured from the last reported ratio. */
    public function testEstimateIsTheAnchorMovedByVisibility(): void
    {
        $anchor = 0.60;
        $realized = 0.80;
        $stock = $this->makeStock($anchor);

        $expectedRatio = $anchor + (FinancialConstants::ANALYST_COST_BASE_VISIBILITY * ($realized - $anchor));
        $costs = $this->expectedCosts($this->actuals($realized), $stock);

        // Revenue is walked down by the analyst bias, so compare the implied ratio rather than the dollars.
        $this->assertEqualsWithDelta(
            $expectedRatio,
            $costs / (self::REVENUE * (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS)),
            1e-6
        );
    }

    /**
     * The regression that matters: a shock that has already been reported once is in the estimate, so
     * repeating it is not a miss.
     */
    public function testAStandingShockIsMissedOnceRatherThanEveryQuarter(): void
    {
        $shocked = 0.80;

        // First quarter of the shock: the anchor is still the pre-shock ratio.
        $firstQuarter = $this->expectedCosts($this->actuals($shocked), $this->makeStock(self::EX_ANTE_RATIO));

        // The report set the anchor to the realized ratio, and the shock simply persists.
        $secondQuarter = $this->expectedCosts($this->actuals($shocked), $this->makeStock($shocked));

        $actualCosts = self::REVENUE * $shocked;
        $this->assertLessThan(
            $actualCosts - $firstQuarter,
            $actualCosts - $secondQuarter,
            'The cost miss must shrink once the shock has been reported.',
        );
        $this->assertEqualsWithDelta(
            $actualCosts * (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS),
            $secondQuarter,
            1.0,
            'With the anchor at the realized ratio, an unchanged cost base is fully in the estimate.'
        );
    }

    /** Every report re-anchors, so the next quarter starts from what was just disclosed. */
    public function testReportUpdatesTheAnchor(): void
    {
        $stock = $this->makeStock(self::EX_ANTE_RATIO);
        $this->expectedCosts($this->actuals(0.72), $stock);

        $this->assertEqualsWithDelta(0.72, (float) $stock->getLastReportedCostRatio(), 1e-6);
    }

    /**
     * Price is not produced, so the cost ratio bites on volume revenue only — the same split the physics
     * applies. Charging it against the whole estimate left any firm with a price-driven revenue stream
     * looking permanently cheaper to run than analysts assumed.
     */
    public function testCostRatioIsChargedAgainstVolumeRevenueOnly(): void
    {
        $ratio = 0.60;
        $stock = $this->makeStock($ratio);
        $allVolume = $this->expectedCosts($this->actuals($ratio), $stock);

        $halfPriced = $this->expectedCosts($this->actuals($ratio, priceRevenue: self::REVENUE * 0.5), $this->makeStock($ratio));

        $this->assertEqualsWithDelta($allVolume * 0.5, $halfPriced, 1.0);
    }

    /** Before a firm has ever reported there is no anchor, and the ex-ante ratio stands in for one. */
    public function testFirstReportFallsBackToTheExAnteRatio(): void
    {
        $costs = $this->expectedCosts($this->actuals(self::EX_ANTE_RATIO), $this->makeStock(null));

        $this->assertGreaterThan(0.0, $costs);
        $this->assertEqualsWithDelta(
            self::EX_ANTE_RATIO,
            $costs / (self::REVENUE * (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS)),
            1e-6
        );
    }
}
