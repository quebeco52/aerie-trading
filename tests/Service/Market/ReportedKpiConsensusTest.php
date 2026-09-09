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
 * Reported operating KPIs feeding consensus.
 *
 * Book-to-bill is a LEADING number: orders above parity are revenue already won and not yet billed, and
 * the models already convert that backlog into revenue over following quarters. Before this, analysts
 * could not see the order book at all, so the conversion arrived as a standing positive surprise quarter
 * after quarter. Carrying the disclosure into the forward estimate is what a real sell-side model does.
 */
#[AllowMockObjectsWithoutExpectations]
final class ReportedKpiConsensusTest extends TestCase
{
    private function makeStock(?float $bookToBill): Stock
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setLastBookToBill($bookToBill);

        return $stock;
    }

    private function actuals(): ActualFinancialsDTO
    {
        // No observable shock, so the fresh estimate is the structural figure and the order tilt alone.
        return new ActualFinancialsDTO(
            actualRevenue: 1_000_000_000.0,
            actualVariableCosts: 600_000_000.0,
            clampedMargin: 0.60,
            ebit: 200_000_000.0,
            primaryShockZ: 0.0,
            observableShockZ: 0.0,
        );
    }

    private function consensusRevenue(?float $bookToBill): float
    {
        // Perfect visibility with no estimation noise isolates the order-book tilt.
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);

        return (new MarketConsensusEngine())->generateConsensus(
            $this->actuals(),
            new SectorCoverageProfile(baseVisibility: 1.0, errorStdDev: 0.0, minVisibility: 1.0),
            1_000_000_000.0,
            $math,
            $this->makeStock($bookToBill),
        )->analystExpectedRevenue;
    }

    /** A firm disclosing more orders than shipments gets a higher forward estimate. */
    public function testOrdersAboveParityRaiseTheForwardEstimate(): void
    {
        $this->assertGreaterThan(
            $this->consensusRevenue(1.00),
            $this->consensusRevenue(1.20),
            'A book-to-bill of 1.2 is revenue already won; consensus must reflect it.'
        );
    }

    /** And a shrinking order book lowers it. */
    public function testOrdersBelowParityLowerTheForwardEstimate(): void
    {
        $this->assertLessThan(
            $this->consensusRevenue(1.00),
            $this->consensusRevenue(0.80),
            'Shipping more than is being ordered is a forward revenue decline analysts can see coming.'
        );
    }

    /** Parity is neutral, and a firm with no order book to disclose is treated exactly like parity. */
    public function testParityAndNonDisclosureAreNeutral(): void
    {
        $this->assertEqualsWithDelta($this->consensusRevenue(1.00), $this->consensusRevenue(null), 1e-6);
    }

    /** One blowout order quarter cannot run the estimate away. */
    public function testTiltIsBounded(): void
    {
        $neutral = $this->consensusRevenue(1.00);
        $extreme = $this->consensusRevenue(50.0);

        $this->assertLessThanOrEqual(
            $neutral * (1.0 + FinancialConstants::MAX_BOOK_TO_BILL_CONSENSUS_TILT) + 1.0,
            $extreme,
            'The forward tilt must stay inside its cap however large the disclosed order book.'
        );
    }

    /** A non-positive disclosure is treated as no disclosure rather than a -100% order book. */
    public function testNonPositiveDisclosureIsIgnored(): void
    {
        $this->assertEqualsWithDelta($this->consensusRevenue(null), $this->consensusRevenue(0.0), 1e-6);
    }
}
