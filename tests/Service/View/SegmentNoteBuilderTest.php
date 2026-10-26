<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Company\Segments;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\View\SegmentNoteBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SegmentNoteBuilderTest extends TestCase
{
    private static function stock(string $ticker, string $industry): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry($industry);

        return $stock;
    }

    /**
     * Each line lands in one segment and the segments add up to the lines, main segment first; a segment that has not
     * opened prints as unknown, not as a zero share.
     */
    public function testTheSegmentsAddUpToTheLinesTheReportFiled(): void
    {
        $report = (new CorporateReport())->setRevenueStreams(['launch_services' => 700.0, 'government_missions' => 300.0, 'satellite_broadband' => 0.0]);
        $rows = SegmentNoteBuilder::fromReport(self::stock('SKYL', 'Space Systems'), $report, ['Space Systems' => 1.08, 'Telecom Services' => 0.97]);

        $this->assertNotNull($rows);
        $this->assertSame(['Launches and missions', 'Satellite internet'], array_column($rows, 'label'));
        $this->assertSame(['Space Systems', 'Telecom Services'], array_column($rows, 'industry'));
        $this->assertSame([1.08, 0.97], array_column($rows, 'outlook'), 'each segment shows its own industry\'s outlook');
        $this->assertEqualsWithDelta(1_000.0, $rows[0]['revenue'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $rows[0]['share'], 1e-12);
        $this->assertNull($rows[1]['revenue']);
        $this->assertNull($rows[1]['share']);

        $report->setRevenueStreams(['industrial_manufacturing' => 450.0, 'defensive_staples' => 350.0, 'financial_investments' => 200.0]);
        $rows = SegmentNoteBuilder::fromReport(self::stock('OWLS', 'Conglomerates'), $report);
        $this->assertNotNull($rows);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($rows, 'share')), 1e-12);
        $this->assertEqualsWithDelta(1_000.0, array_sum(array_column($rows, 'revenue')), 1e-9);
        $this->assertEqualsWithDelta(0.2, $rows[0]['share'], 1e-12, 'the cash float stays in the main segment');
        $this->assertNull($rows[0]['outlook'], 'an industry with no outlook shows none');
    }

    /** A segment the company sold is gone from its note; one it still holds stays. */
    public function testASegmentSoldIsNoLongerReported(): void
    {
        $stock = self::stock('TRIV', 'Conglomerates');
        $plant = SegmentBooks::sellSegment([Segments::MAIN => 0.5, 'industrial' => 0.3, 'consumer' => 0.2], 'industrial');
        $stock->setEarningsMomentumZ(SegmentBooks::open($plant, 1.0, []));
        $report = (new CorporateReport())->setRevenueStreams(['industrial_manufacturing' => 0.0, 'defensive_staples' => 350.0, 'financial_investments' => 200.0]);

        $rows = SegmentNoteBuilder::fromReport($stock, $report);

        $this->assertNotNull($rows);
        $this->assertSame(['Cash and investments', 'Household and medical products'], array_column($rows, 'label'));
    }

    public function testACompanyWithOneMarketHasNoSegmentNote(): void
    {
        $this->assertNull(SegmentNoteBuilder::fromReport(self::stock('LOON', 'Telecom Services'), new CorporateReport()));
    }
}
