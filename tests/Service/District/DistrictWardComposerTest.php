<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\DistrictMap;
use App\Entity\Stock;
use App\Service\District\DistrictWardComposer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two independent rules the street's frontage is derived from: *qualification* is a
 * live top-DistrictMap::STREET_ROSTER_SIZE ranking by market cap (recomputed every request, no
 * company authored on or off), while *position* is DistrictMap::FRONTAGE_ORDER — see that
 * constant's docblock. Geometry itself is a pure function of systemic importance and position,
 * reproducing the pre-rework hand-authored coordinates exactly from the same formula.
 */
class DistrictWardComposerTest extends TestCase
{
    private DistrictWardComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new DistrictWardComposer();
    }

    private function makeStock(string $ticker, string $industry, float $price, float $shares, string $importance = 'none'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Holdings');
        $stock->setSector('Financials');
        $stock->setIndustry($industry);
        $stock->setPrice((string) $price);
        $stock->setSharesOutstanding((string) $shares);
        $stock->setSystemicImportance($importance);

        return $stock;
    }

    /** @return Stock[] $count stocks, each a distinct commercial_bank tenant with descending market cap. */
    private function makeDescendingMarketCapStocks(int $count): array
    {
        $stocks = [];
        for ($i = 0; $i < $count; $i++) {
            $stocks[] = $this->makeStock(sprintf('T%03d', $i), 'Banks - Diversified', 100.0 - $i, 1_000_000.0);
        }

        return $stocks;
    }

    public function testOnlyTheTopRosterSizeByMarketCapQualify(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(DistrictMap::STREET_ROSTER_SIZE + 5);
        $frontage = $this->composer->composeFrontage($stocks);

        $this->assertCount(DistrictMap::STREET_ROSTER_SIZE, $frontage['slots']);

        $tickers = array_map(static fn (array $slot) => $slot['ticker'], $frontage['slots']);
        for ($i = 0; $i < DistrictMap::STREET_ROSTER_SIZE; $i++) {
            $this->assertContains(sprintf('T%03d', $i), $tickers, 'The larger-cap tenant should qualify');
        }
        for ($i = DistrictMap::STREET_ROSTER_SIZE; $i < DistrictMap::STREET_ROSTER_SIZE + 5; $i++) {
            $this->assertNotContains(sprintf('T%03d', $i), $tickers, 'The smaller-cap tenant should not qualify');
        }
    }

    public function testFewerThanRosterSizeStocksFillsExactlyAsManySlots(): void
    {
        $frontage = $this->composer->composeFrontage([
            $this->makeStock('LAKE', 'Banks - Diversified', 100.0, 1_000_000_000.0, 'titan'),
        ]);

        $this->assertCount(1, $frontage['slots']);
        $this->assertSame('LAKE', $frontage['slots'][0]['ticker']);
    }

    public function testEveryOccupiedSlotWidthMatchesSystemicImportance(): void
    {
        $frontage = $this->composer->composeFrontage([
            $this->makeStock('LAKE', 'Banks - Diversified', 100.0, 1.0, 'titan'),
        ]);

        $this->assertSame(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE['titan'], $frontage['slots'][0]['width']);
    }

    public function testPositionOrdersByFrontageOrderThenMarketCapDescendingAmongQualifiers(): void
    {
        // FRONTAGE_ORDER puts credit_services before commercial_bank; within the same model, the
        // larger market cap should lead — even though STRK's own market cap is smaller than
        // LAKE's, credit_services still leads because position is authored, not ranked.
        $frontage = $this->composer->composeFrontage([
            $this->makeStock('RIVR', 'Banks - Regional', 50.0, 1_000_000.0),          // commercial_bank, small
            $this->makeStock('LAKE', 'Banks - Diversified', 100.0, 1_000_000_000.0),  // commercial_bank, huge
            $this->makeStock('STRK', 'Credit Services', 10.0, 1_000_000.0),           // credit_services
        ]);

        $tickers = array_map(static fn (array $slot) => $slot['ticker'], $frontage['slots']);

        $this->assertSame(['STRK', 'LAKE', 'RIVR'], $tickers);
    }

    public function testQualificationIsByMarketCapEvenAcrossDifferentFrontageOrderPositions(): void
    {
        // A tiny credit_services tenant (early in FRONTAGE_ORDER) must not bump a much larger
        // commercial_bank tenant (later in FRONTAGE_ORDER) out of the roster — qualification
        // ignores position entirely.
        $stocks = $this->makeDescendingMarketCapStocks(DistrictMap::STREET_ROSTER_SIZE);
        $stocks[] = $this->makeStock('TINY', 'Credit Services', 0.01, 1.0);

        $frontage = $this->composer->composeFrontage($stocks);
        $tickers = array_map(static fn (array $slot) => $slot['ticker'], $frontage['slots']);

        $this->assertNotContains('TINY', $tickers);
        $this->assertContains('T000', $tickers);
    }

    public function testStocksWithNoReachableBusinessModelAreExcluded(): void
    {
        // 'General' has no entry in Sectors::INDUSTRY_METRICS, so it resolves to business model
        // 'none', which does not appear in FRONTAGE_ORDER.
        $frontage = $this->composer->composeFrontage([
            $this->makeStock('XXXX', 'General', 1_000_000_000.0, 1.0),
        ]);

        $tickers = array_map(static fn (array $slot) => $slot['ticker'], $frontage['slots']);
        $this->assertNotContains('XXXX', $tickers);
    }

    public function testGeometryIsSequentialWithNoOverlap(): void
    {
        $stocks = [
            $this->makeStock('LAKE', 'Banks - Diversified', 100.0, 1.0, 'titan'),
            $this->makeStock('RIVR', 'Banks - Regional', 50.0, 1.0, 'systemic'),
            $this->makeStock('STRK', 'Credit Services', 10.0, 1.0, 'none'),
        ];
        $frontage = $this->composer->composeFrontage($stocks);

        $previousEdge = 0;
        foreach ($frontage['slots'] as $slot) {
            $this->assertGreaterThanOrEqual($previousEdge, $slot['x']);
            $previousEdge = $slot['x'] + $slot['width'];
        }

        $this->assertLessThanOrEqual($frontage['viewboxWidth'], $previousEdge + DistrictMap::FRONTAGE_MARGIN);
    }

    public function testEmptyStreetProducesNoSlotsAndAMinimalViewbox(): void
    {
        $frontage = $this->composer->composeFrontage([]);

        $this->assertSame([], $frontage['slots']);
        $this->assertSame(DistrictMap::FRONTAGE_GUTTER + DistrictMap::FRONTAGE_MARGIN, $frontage['viewboxWidth']);
    }

    /**
     * Width by importance tier, uniform FRONTAGE_GAP, and a canvas of
     * gutter + widest row + margin — the gutter being wider than the margin because the
     * market-cap gridline labels are printed in it.
     */
    public function testViewboxWidthMatchesTheDerivationFormula(): void
    {
        $stocks = [
            $this->makeStock('LAKE', 'Banks - Diversified', 100.0, 1.0, 'titan'),
            $this->makeStock('RIVR', 'Banks - Regional', 50.0, 1.0, 'systemic'),
        ];
        $frontage = $this->composer->composeFrontage($stocks);

        $widths = array_map(static fn (array $slot) => $slot['width'], $frontage['slots']);
        $total = array_sum($widths) + (count($widths) - 1) * DistrictMap::FRONTAGE_GAP;

        $this->assertSame(DistrictMap::FRONTAGE_GUTTER + $total + DistrictMap::FRONTAGE_MARGIN, $frontage['viewboxWidth']);
    }

    public function testShortStreetsStayOnOneRow(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(DistrictMap::ROW_SPLIT_THRESHOLD - 1);
        $frontage = $this->composer->composeFrontage($stocks);

        $this->assertSame(1, $frontage['rowCount'], 'A short street is already legible unwrapped');
        foreach ($frontage['slots'] as $slot) {
            $this->assertSame(0, $slot['row']);
        }
    }

    public function testAFullStreetWrapsIntoTwoRows(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(DistrictMap::STREET_ROSTER_SIZE);
        $frontage = $this->composer->composeFrontage($stocks);

        $this->assertSame(DistrictMap::ROW_COUNT, $frontage['rowCount']);

        $rows = array_unique(array_map(static fn (array $slot) => $slot['row'], $frontage['slots']));
        sort($rows);
        $this->assertSame([0, 1], $rows);
    }

    /**
     * The wrap must not reorder anything: x restarts at the gutter on the second row, but the
     * authored frontage sequence has to survive intact across the break.
     */
    public function testEachRowWalksLeftToRightFromTheGutterWithoutOverlap(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(DistrictMap::STREET_ROSTER_SIZE);
        $frontage = $this->composer->composeFrontage($stocks);

        $edgeByRow = [];
        foreach ($frontage['slots'] as $slot) {
            $row = $slot['row'];
            if (!isset($edgeByRow[$row])) {
                $this->assertSame(DistrictMap::FRONTAGE_GUTTER, $slot['x'], 'Each row starts at the gutter');
                $edgeByRow[$row] = 0;
            }

            $this->assertGreaterThanOrEqual($edgeByRow[$row], $slot['x'], 'Plots must not overlap within a row');
            $edgeByRow[$row] = $slot['x'] + $slot['width'];
        }
    }

    /**
     * The split balances by rendered width rather than by count, because canvas width is what
     * sets the street's pixels-per-unit and plot widths vary by a factor of nearly two.
     */
    public function testTheSplitBalancesTheTwoRowsByWidth(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(DistrictMap::STREET_ROSTER_SIZE);
        $frontage = $this->composer->composeFrontage($stocks);

        $edges = [];
        foreach ($frontage['slots'] as $slot) {
            $edges[$slot['row']] = $slot['x'] + $slot['width'];
        }

        $widest = max($edges);
        $narrowest = min($edges);

        $this->assertLessThanOrEqual(
            max(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE) + DistrictMap::FRONTAGE_GAP,
            $widest - $narrowest,
            'The two rows should differ by at most one plot\'s worth of width'
        );
    }

    public function testStreetRankIsMarketCapPositionNotFrontagePosition(): void
    {
        // STRK sorts first by frontage order (credit_services leads the street) but is the
        // smaller company, so it must still carry the lower rank.
        $frontage = $this->composer->composeFrontage([
            $this->makeStock('LAKE', 'Banks - Diversified', 100.0, 1_000_000_000.0),
            $this->makeStock('STRK', 'Credit Services', 10.0, 1_000_000.0),
        ]);

        $rankByTicker = [];
        foreach ($frontage['slots'] as $slot) {
            $rankByTicker[$slot['ticker']] = $slot['rank'];
        }

        $this->assertSame('STRK', $frontage['slots'][0]['ticker'], 'Credit services still leads the frontage');
        $this->assertSame(1, $rankByTicker['LAKE'], 'The largest company is rank 1 wherever it stands');
        $this->assertSame(2, $rankByTicker['STRK']);
    }

    public function testBankruptCompaniesNeverQualifyAtAReconstitution(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(4);
        $stocks[0]->setIsBankrupt(true);

        $tickers = array_column($this->composer->composeFrontage($stocks)['slots'], 'ticker');

        $this->assertNotContains('T000', $tickers, 'The largest company is bankrupt and sits the street out');
        $this->assertCount(3, $tickers);
    }

    public function testRosterFrontageHonoursTheStoredOrderAndSkipsDelistedTickers(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(3);

        // Stored smallest-first, one ticker no longer listed: the order is kept, the gap closes.
        $frontage = $this->composer->composeFrontageForRoster($stocks, ['T002', 'GONE', 'T000']);

        $this->assertSame(['T002', 'T000'], array_column($frontage['slots'], 'ticker'));
        $this->assertSame(DistrictMap::FRONTAGE_GUTTER, $frontage['slots'][0]['x']);
        $this->assertSame(2, $frontage['slots'][0]['rank'], 'Rank is live market cap, not stored position');
        $this->assertSame(1, $frontage['slots'][1]['rank']);
    }

    public function testRosterOrderIsTheFrontageOrderWithoutGeometry(): void
    {
        $stocks = $this->makeDescendingMarketCapStocks(5);

        $this->assertSame(
            array_column($this->composer->composeFrontage($stocks)['slots'], 'ticker'),
            $this->composer->rosterOrder($stocks),
        );
    }
}
