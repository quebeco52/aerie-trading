<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\View\FinancialSummaryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The strip leads with what the business is read on, from figures the last report already carries, and then
 * the operating KPIs its model reports. A KPI it does not know how to label is left off rather than printed
 * as a raw key.
 */
class FinancialSummaryBuilderTest extends TestCase
{
    public function testALenderIsReadOnItsMarginCapitalReserveAndLosses(): void
    {
        $report = new CorporateReport();
        $report->setEarningAssets('800000000000');
        $report->setNetInterestMargin('0.0310');
        $report->setCet1Ratio('0.1250');
        $report->setCreditLossAllowance('12000000000');
        $report->setNetChargeOffs('2000000000');
        $report->setReturnOnEquity('0.1100');

        $tiles = $this->tiles($this->stock('Banks - Diversified'), $report);

        $this->assertSame(['Net interest margin', 'CET1 ratio', 'Reserve / book', 'Net charge-off rate', 'ROE'], array_column($tiles, 'label'));
        $this->assertEqualsWithDelta(0.015, $tiles[2]['value'], 1e-12, 'allowance over the book');
        $this->assertEqualsWithDelta(0.01, $tiles[3]['value'], 1e-12, 'a quarter of charge-offs, annualized over the book');
    }

    public function testAnInsurerIsReadOnItsCombinedRatio(): void
    {
        $report = new CorporateReport();
        $report->setCapitalRatio('0.2500');
        $report->setReturnOnEquity('0.0900');
        $report->setOperatingMargin('0.0600');

        $tiles = $this->tiles($this->stock('Insurance - Diversified'), $report);

        $this->assertSame(['Capital ratio', 'ROE', 'Combined ratio'], array_column($tiles, 'label'));
        $this->assertEqualsWithDelta(0.94, $tiles[2]['value'], 1e-12);
    }

    public function testAnOperatingCompanyLeadsWithReturnsThenItsOwnKpis(): void
    {
        $report = new CorporateReport();
        $report->setOperatingMargin('0.1800');
        $report->setRoic('0.1400');
        $report->setWacc('0.0900');
        $report->setNetIncome('2000000000');
        $report->setFreeCashFlow('1500000000');
        $report->setReportedKpis([
            'book_to_bill' => 1.12,
            'industry_price_level' => 0.97,
            'some_unlabelled_state' => 3.0,
            'backlog_quarters' => 5.5,
            'walt_years' => 6.0,
            'quarterly_churn' => 0.02,
        ]);

        $tiles = $this->tiles($this->stock('Tools & Accessories'), $report);

        $this->assertSame(['Operating margin', 'ROIC − WACC', 'FCF / net income', 'Book-to-bill', 'Backlog', 'WALT'], array_column($tiles, 'label'), 'capped at six, unknown and industry keys skipped');
        $this->assertEqualsWithDelta(0.05, $tiles[1]['value'], 1e-12);
        $this->assertSame('signed_percent', $tiles[1]['format']);
        $this->assertEqualsWithDelta(0.75, $tiles[2]['value'], 1e-12);
    }

    public function testNoReportMeansNoStrip(): void
    {
        $this->assertSame([], $this->tiles($this->stock('Tools & Accessories'), null));
    }

    /** @return list<array{label: string, value: float, format: string}> */
    private function tiles(Stock $stock, ?CorporateReport $report): array
    {
        $reports = $this->createStub(CorporateReportRepository::class);
        $reports->method('findLatestFor')->willReturn($report);

        return (new FinancialSummaryBuilder($reports))->build($stock)['financialSummary'];
    }

    private function stock(string $industry): Stock
    {
        $stock = new Stock();
        $stock->setTicker('SUMM');
        $stock->setIndustry($industry);

        return $stock;
    }
}
