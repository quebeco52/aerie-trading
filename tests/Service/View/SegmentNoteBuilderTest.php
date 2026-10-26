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

    /**
     * Each segment shows its own margin, scaled to the margin the company reported so the segments' operating profits
     * add up to its own, and its share of the company's assets.
     */
    public function testEachSegmentShowsItsMarginAndAssets(): void
    {
        $stock = self::stock('WEAV', 'Internet Retail');
        $momentum = SegmentBooks::open([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 1.0, []);
        $stock->setEarningsMomentumZ(SegmentBooks::openEconomics('WEAV', $momentum, 2.0, 0.06) + $momentum);
        $report = (new CorporateReport())->setRevenueStreams(['first_party_retail' => 600.0, 'third_party_services' => 200.0, 'digital_ads_cloud' => 200.0]);
        $report->setOperatingMargin('0.0500');

        $rows = SegmentNoteBuilder::fromReport($stock, $report) ?? [];

        $profit = 0.0;
        foreach ($rows as $row) {
            $profit += (float) $row['revenue'] * (float) $row['margin'];
        }
        $this->assertEqualsWithDelta(0.05 * 1_000.0, $profit, 1e-9);
        $this->assertEqualsWithDelta(6.0, (float) $rows[1]['margin'] / (float) $rows[0]['margin'], 1e-9);
        $this->assertEqualsWithDelta(1.0, (float) $rows[0]['assets'] + (float) $rows[1]['assets'], 1e-12);
        $this->assertNull(SegmentNoteBuilder::fromReport(self::stock('SKYL', 'Space Systems'), $report)[0]['margin'] ?? null, 'a company on one plant shows none');
    }

    /**
     * A business still learning its trade shows the loss it makes on its own row, its learned margin less what it still
     * falls short by, and the other segments keep their own; the segments' profits still add up to the company's.
     */
    public function testAVentureStillLearningShowsItsOwnLoss(): void
    {
        $segment = Segments::enteredKey('Farm & Heavy Construction Machinery');
        $stock = self::stock('ZZZZ', 'Auto Manufacturers');
        $stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, $segment => 0.1], 1.0, []) + [
            SegmentBooks::STATE_MARGIN . Segments::MAIN => 0.10,
            SegmentBooks::STATE_TURNOVER . Segments::MAIN => 1.0,
            SegmentBooks::STATE_MARGIN . $segment => 0.15,
            SegmentBooks::STATE_TURNOVER . $segment => 1.0,
            SegmentBooks::STATE_LEARNING . $segment => 2.0,
        ]);
        $report = (new CorporateReport())->setRevenueStreams(['passenger_cars' => 900.0, $segment => 100.0]);
        $report->setOperatingMargin('0.0750');

        $rows = SegmentNoteBuilder::fromReport($stock, $report) ?? [];

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(0.10, (float) $rows[0]['margin'], 1e-9);
        $this->assertEqualsWithDelta(-0.15, (float) $rows[1]['margin'], 1e-9, 'it loses what it still falls short by');
        $this->assertEqualsWithDelta(75.0, (900.0 * (float) $rows[0]['margin']) + (100.0 * (float) $rows[1]['margin']), 1e-9);
    }

    public function testACompanyWithOneMarketHasNoSegmentNote(): void
    {
        $this->assertNull(SegmentNoteBuilder::fromReport(self::stock('LOON', 'Telecom Services'), new CorporateReport()));
    }
}
