<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Sectors;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;

/**
 * The handful of numbers an analyst reads first off the last report, chosen by what the business is.
 *
 * A lender is read on its margin, its capital, its reserve and what went bad; an operating company on its
 * margin, its return over its cost of capital and how much of its profit turned into cash, followed by the
 * operating KPIs its own model reports (book-to-bill, backlog, churn, lease roll). Every tile is a figure
 * the report already carries; nothing here is struck for the page.
 */
class FinancialSummaryBuilder
{
    // --- Summary Strip ---

    /** Operating KPIs the business models report, as label and figure format; anything else is not shown. */
    private const KPI_LABELS = [
        'book_to_bill' => ['Book-to-bill', FigureFormat::Multiple],
        'backlog_quarters' => ['Backlog', FigureFormat::Quarters],
        'contract_book_to_bill' => ['Charter book-to-bill', FigureFormat::Multiple],
        'contract_backlog_quarters' => ['Charter backlog', FigureFormat::Quarters],
        'bookings_to_revenue' => ['Bookings / revenue', FigureFormat::Multiple],
        'deferred_revenue_quarters' => ['Deferred revenue', FigureFormat::Quarters],
        'enrollment_to_revenue' => ['Enrollment / revenue', FigureFormat::Multiple],
        'deferred_tuition_quarters' => ['Deferred tuition', FigureFormat::Quarters],
        'rollout_wave_quarters' => ['Rollout wave age', FigureFormat::Quarters],
        'withheld_royalty_quarters' => ['Royalties withheld', FigureFormat::Quarters],
        'subscriber_index' => ['Subscriber index', FigureFormat::Index],
        'quarterly_churn' => ['Quarterly churn', FigureFormat::Percent],
        'net_adds' => ['Net adds', FigureFormat::SignedIndex],
        'arpu_index' => ['ARPU index', FigureFormat::Index],
        'audience_index' => ['Audience index', FigureFormat::Index],
        'seat_capacity_index' => ['Seat capacity index', FigureFormat::Index],
        'basket_volume_index' => ['Basket volume index', FigureFormat::Index],
        'shelf_price_index' => ['Shelf price index', FigureFormat::Index],
        'load_factor' => ['Load factor', FigureFormat::Percent],
        'carriage_fee_index' => ['Carriage fee index', FigureFormat::Index],
        'upfront_price_index' => ['Upfront price index', FigureFormat::Index],
        'walt_years' => ['WALT', FigureFormat::Years],
        'releasing_spread' => ['Re-leasing spread', FigureFormat::SignedPercent],
        'in_place_rent_index' => ['In-place rent index', FigureFormat::Index],
        'realized_price_index' => ['Realized price index', FigureFormat::Index],
        'home_sales_margin' => ['Home sales margin', FigureFormat::Percent],
        'land_cost_share' => ['Land cost / price', FigureFormat::Percent],
        'land_write_down' => ['Land write-down / revenue', FigureFormat::Percent],
        'power_price_index' => ['Realized power price', FigureFormat::Index],
        'generation_index' => ['Generation index', FigureFormat::Index],
        'clean_spark_spread' => ['Clean spark spread / power price', FigureFormat::Percent],
        'availability' => ['Plant availability', FigureFormat::Percent],
        'hedge_gain' => ['Hedge gain / revenue', FigureFormat::SignedPercent],
        'capture_rate' => ['Crack capture', FigureFormat::Percent],
        'throughput_index' => ['Throughput index', FigureFormat::Index],
        'dwell_index' => ['Yard dwell index', FigureFormat::Index],
        'loss_ratio' => ['Loss ratio', FigureFormat::Percent],
        'medical_cost_ratio' => ['Medical cost ratio', FigureFormat::Percent],
        'premium_rebate' => ['Premium rebates', FigureFormat::Percent],
        'expense_ratio' => ['Expense ratio', FigureFormat::Percent],
    ];

    public function __construct(
        private readonly CorporateReportRepository $reports,
    ) {}

    /**
     * The strip, and the operating KPIs the last report carries for the page to chart over its reports.
     *
     * Every labelled KPI is shown: a model reports only the few its business is read on, and the strip wraps
     * rather than dropping one.
     *
     * @return array{financialSummary: list<array{label: string, value: float, format: FigureFormat}>, kpiSeries: array<string, array{label: string, format: FigureFormat}>}
     */
    public function build(Stock $stock): array
    {
        $report = $stock->isBankrupt() ? null : $this->reports->findLatestFor($stock);
        if ($report === null) {
            return ['financialSummary' => [], 'kpiSeries' => []];
        }

        $strategy = Sectors::strategyFor($stock->getIndustry());

        $tiles = match (true) {
            (float) $report->getEarningAssets() > 0.0 && $strategy->isFinancial() => $this->lenderTiles($report, $strategy instanceof CommercialBankBusinessModel ? $strategy->getLoanShareOfEarningAssets() : null),
            $strategy->getRatingMethodology()->scoresSolvencyOnCapital() => $this->capitalTiles($report, $strategy instanceof InsuranceBusinessModel),
            default => $this->operatingTiles($report),
        };

        $kpiSeries = [];
        foreach ($report->getReportedKpis() ?? [] as $key => $value) {
            if (isset(self::KPI_LABELS[$key]) && is_numeric($value)) {
                [$label, $format] = self::KPI_LABELS[$key];
                $tiles[] = $this->tile($label, (float) $value, $format);
                $kpiSeries[$key] = ['label' => $label, 'format' => $format];
            }
        }

        return ['financialSummary' => array_values(array_filter($tiles)), 'kpiSeries' => $kpiSeries];
    }

    /**
     * A bank quotes its reserve and charge-offs on loans, the part of the book that can default; another lender on
     * its whole book.
     *
     * @return list<array{label: string, value: float, format: FigureFormat}|null>
     */
    private function lenderTiles(CorporateReport $report, ?float $loanShare): array
    {
        $book = (float) $report->getEarningAssets() * ($loanShare ?? 1.0);

        return [
            $this->tile('Net interest margin', $report->getNetInterestMargin(), FigureFormat::Percent),
            $report->getCet1Ratio() !== null
                ? $this->tile('CET1 ratio', $report->getCet1Ratio(), FigureFormat::Percent)
                : $this->tile('Capital ratio', $report->getCapitalRatio(), FigureFormat::Percent),
            $this->tile($loanShare !== null ? 'Reserve / loans' : 'Reserve / book', (float) $report->getCreditLossAllowance() / $book, FigureFormat::Percent),
            $this->tile('Net charge-off rate', ((float) $report->getNetChargeOffs() * FinancialConstants::QUARTERS_PER_YEAR) / $book, FigureFormat::Percent),
            $this->tile('ROE', $report->getReturnOnEquity(), FigureFormat::Percent),
        ];
    }

    /** @return list<array{label: string, value: float, format: FigureFormat}|null> */
    private function capitalTiles(CorporateReport $report, bool $isInsurer): array
    {
        $margin = $report->getOperatingMargin();

        return [
            $this->tile('Capital ratio', $report->getCapitalRatio(), FigureFormat::Percent),
            $this->tile('ROE', $report->getReturnOnEquity(), FigureFormat::Percent),
            $isInsurer
                ? $this->tile('Combined ratio', self::combinedRatio($report), FigureFormat::Percent)
                : $this->tile('Operating margin', $margin, FigureFormat::Percent),
        ];
    }

    /**
     * An insurer's combined ratio: losses plus expenses per dollar of premium, as the report files them; a report
     * filed before the split was carried falls back on one less the operating margin.
     */
    public static function combinedRatio(CorporateReport $report): ?float
    {
        $kpis = $report->getReportedKpis() ?? [];
        if (is_numeric($kpis['loss_ratio'] ?? null) && is_numeric($kpis['expense_ratio'] ?? null)) {
            return (float) $kpis['loss_ratio'] + (float) $kpis['expense_ratio'];
        }
        $margin = $report->getOperatingMargin();

        return $margin === null ? null : 1.0 - (float) $margin;
    }

    /** @return list<array{label: string, value: float, format: FigureFormat}|null> */
    private function operatingTiles(CorporateReport $report): array
    {
        $netIncome = (float) $report->getNetIncome();
        $spread = $report->getRoic() !== null && $report->getWacc() !== null ? (float) $report->getRoic() - (float) $report->getWacc() : null;

        return [
            $this->tile('Operating margin', $report->getOperatingMargin(), FigureFormat::Percent),
            $this->tile('ROIC − WACC', $spread, FigureFormat::SignedPercent),
            $netIncome > 0.0 && $report->getFreeCashFlow() !== null
                ? $this->tile('FCF / net income', (float) $report->getFreeCashFlow() / $netIncome, FigureFormat::Percent)
                : null,
        ];
    }

    /** @return array{label: string, value: float, format: FigureFormat}|null */
    private function tile(string $label, float|string|null $value, FigureFormat $format): ?array
    {
        if ($value === null || !is_finite((float) $value)) {
            return null;
        }

        return ['label' => $label, 'value' => (float) $value, 'format' => $format];
    }
}
