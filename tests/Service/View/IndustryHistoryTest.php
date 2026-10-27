<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Company\Sectors;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\View\IndustryCharts;
use App\Service\View\IndustryHistory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The industry page's quarters, summed from its companies' reports, and the charts drawn from them.
 */
#[Group('unit')]
final class IndustryHistoryTest extends TestCase
{
    /** A report filed in a quarter covers the one before it; staggered filings of one season share a quarter. */
    public function testAReportIsPutInTheQuarterBeforeItsFiling(): void
    {
        $quarters = IndustryHistory::quarters([
            $this->report('FALC', 3.27, 100.0, 50.0),
            $this->report('EIDR', 3.45, 300.0, 50.0),
        ]);

        $this->assertCount(1, $quarters);
        $this->assertEqualsWithDelta(3.0, $quarters[0]['t'], 1e-12);
        $this->assertSame('Year 4 Q1', $quarters[0]['label']);
        $this->assertEqualsWithDelta(400.0, $quarters[0]['revenue'], 1e-9);
        $this->assertSame(['EIDR' => 0.75, 'FALC' => 0.25], $quarters[0]['shares'], 'Largest seller first.');
    }

    /** The price is weighted by sales and idle plant by plant, as an industry's own figures would be. */
    public function testPriceIsSalesWeightedAndIdlePlantIsPlantWeighted(): void
    {
        $quarters = IndustryHistory::quarters([
            $this->report('FALC', 3.3, 100.0, 300.0, ['industry_price_level' => 1.10, 'idle_plant_share' => 0.0], cip: 30.0),
            $this->report('EIDR', 3.3, 300.0, 100.0, ['industry_price_level' => 1.02, 'idle_plant_share' => 0.2], cip: 10.0),
        ]);

        $this->assertEqualsWithDelta((100.0 * 1.10 + 300.0 * 1.02) / 400.0 - 1.0, $quarters[0]['priceGap'], 1e-12);
        $this->assertEqualsWithDelta((300.0 * 0.0 + 100.0 * 0.2) / 400.0, $quarters[0]['idle'], 1e-12);
        $this->assertEqualsWithDelta(40.0 / 400.0, $quarters[0]['building'], 1e-12);
    }

    /** A season still under way is dropped, or it would read as a fall in sales; a firm gone for good is not. */
    public function testASeasonStillUnderWayIsDropped(): void
    {
        $quarters = IndustryHistory::quarters([
            $this->report('FALC', 3.3, 100.0, 50.0),
            $this->report('EIDR', 3.3, 100.0, 50.0),
            $this->report('FALC', 3.55, 100.0, 50.0),
        ]);
        $this->assertCount(1, $quarters);

        $full = IndustryHistory::quarters([
            $this->report('FALC', 3.3, 100.0, 50.0),
            $this->report('FALC', 3.55, 100.0, 50.0),
        ]);
        $this->assertCount(2, $full);
    }

    /** A quarter without a reading breaks the line rather than joining across it, and a gap chart takes in zero. */
    public function testAMissingQuarterBreaksTheLineAndZeroIsOnTheAxis(): void
    {
        $points = [];
        foreach ([0.04, 0.05, null, 0.06, 0.07] as $i => $value) {
            $points[] = ['t' => 3.0 + $i / 4.0, 'label' => "Q{$i}", 'value' => $value];
        }

        $chart = IndustryCharts::lines([['name' => 'Price', 'points' => $points]], true, true);

        $this->assertNotNull($chart);
        $this->assertCount(2, $chart['series'][0]['lines']);
        $this->assertCount(4, $chart['series'][0]['points']);
        $this->assertContains(true, array_column($chart['gridlines'], 'zero'));
        $this->assertSame('Price, Q4: +7.0%', $chart['series'][0]['points'][3]['title']);
    }

    public function testEveryIndustryHasItsOwnAddress(): void
    {
        $slugs = array_map(Sectors::industrySlug(...), array_keys(Sectors::INDUSTRY_METRICS));

        $this->assertCount(count($slugs), array_unique($slugs));
        $this->assertSame('auto-truck-dealerships', Sectors::industrySlug('Auto & Truck Dealerships'));
        $this->assertSame('Auto & Truck Dealerships', Sectors::industryForSlug('auto-truck-dealerships'));
        $this->assertNull(Sectors::industryForSlug('no-such-industry'));
    }

    /** @param array<string, float> $kpis */
    private function report(string $ticker, float $filed, float $revenue, float $plant, array $kpis = [], float $cip = 0.0): CorporateReport
    {
        return (new CorporateReport())
            ->setStock((new Stock())->setTicker($ticker))
            ->setTotalTime((string) $filed)
            ->setRevenue((string) $revenue)
            ->setEbit((string) (0.1 * $revenue))
            ->setNetPpe((string) $plant)
            ->setCip((string) $cip)
            ->setReportedKpis($kpis);
    }
}
