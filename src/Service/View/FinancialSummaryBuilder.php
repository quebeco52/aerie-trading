<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Sectors;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Math\FinancialConstants;

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

    /** Operating KPIs the business models report, as label and display format; anything else is not shown. */
    private const KPI_LABELS = [
        'book_to_bill' => ['Book-to-bill', 'multiple'],
        'backlog_quarters' => ['Backlog', 'quarters'],
        'contract_book_to_bill' => ['Charter book-to-bill', 'multiple'],
        'contract_backlog_quarters' => ['Charter backlog', 'quarters'],
        'bookings_to_revenue' => ['Bookings / revenue', 'multiple'],
        'deferred_revenue_quarters' => ['Deferred revenue', 'quarters'],
        'enrollment_to_revenue' => ['Enrollment / revenue', 'multiple'],
        'deferred_tuition_quarters' => ['Deferred tuition', 'quarters'],
        'rollout_wave_quarters' => ['Rollout wave age', 'quarters'],
        'withheld_royalty_quarters' => ['Royalties withheld', 'quarters'],
        'subscriber_index' => ['Subscriber index', 'index'],
        'quarterly_churn' => ['Quarterly churn', 'percent'],
        'net_adds' => ['Net adds', 'signed_index'],
        'arpu_index' => ['ARPU index', 'index'],
        'walt_years' => ['WALT', 'years'],
        'releasing_spread' => ['Re-leasing spread', 'signed_percent'],
        'in_place_rent_index' => ['In-place rent index', 'index'],
        'realized_price_index' => ['Realized price index', 'index'],
        'power_price_index' => ['Realized power price', 'index'],
        'hedge_gain' => ['Hedge gain / revenue', 'signed_percent'],
        'capture_rate' => ['Crack capture', 'percent'],
        'throughput_index' => ['Throughput index', 'index'],
        'loss_ratio' => ['Loss ratio', 'percent'],
        'expense_ratio' => ['Expense ratio', 'percent'],
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
     * @return array{financialSummary: list<array{label: string, value: float, format: string}>, kpiSeries: array<string, array{label: string, format: string}>}
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
            $strategy->requiresAlternativeZScore() => $this->capitalTiles($report, $strategy instanceof InsuranceBusinessModel),
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
     * @return list<array{label: string, value: float, format: string}|null>
     */
    private function lenderTiles(CorporateReport $report, ?float $loanShare): array
    {
        $book = (float) $report->getEarningAssets() * ($loanShare ?? 1.0);

        return [
            $this->tile('Net interest margin', $report->getNetInterestMargin(), 'percent'),
            $report->getCet1Ratio() !== null
                ? $this->tile('CET1 ratio', $report->getCet1Ratio(), 'percent')
                : $this->tile('Capital ratio', $report->getCapitalRatio(), 'percent'),
            $this->tile($loanShare !== null ? 'Reserve / loans' : 'Reserve / book', (float) $report->getCreditLossAllowance() / $book, 'percent'),
            $this->tile('Net charge-off rate', ((float) $report->getNetChargeOffs() * FinancialConstants::QUARTERS_PER_YEAR) / $book, 'percent'),
            $this->tile('ROE', $report->getReturnOnEquity(), 'percent'),
        ];
    }

    /** @return list<array{label: string, value: float, format: string}|null> */
    private function capitalTiles(CorporateReport $report, bool $isInsurer): array
    {
        $margin = $report->getOperatingMargin();

        return [
            $this->tile('Capital ratio', $report->getCapitalRatio(), 'percent'),
            $this->tile('ROE', $report->getReturnOnEquity(), 'percent'),
            $isInsurer
                ? $this->tile('Combined ratio', self::combinedRatio($report), 'percent')
                : $this->tile('Operating margin', $margin, 'percent'),
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

    /** @return list<array{label: string, value: float, format: string}|null> */
    private function operatingTiles(CorporateReport $report): array
    {
        $netIncome = (float) $report->getNetIncome();
        $spread = $report->getRoic() !== null && $report->getWacc() !== null ? (float) $report->getRoic() - (float) $report->getWacc() : null;

        return [
            $this->tile('Operating margin', $report->getOperatingMargin(), 'percent'),
            $this->tile('ROIC − WACC', $spread, 'signed_percent'),
            $netIncome > 0.0 && $report->getFreeCashFlow() !== null
                ? $this->tile('FCF / net income', (float) $report->getFreeCashFlow() / $netIncome, 'percent')
                : null,
        ];
    }

    /** @return array{label: string, value: float, format: string}|null */
    private function tile(string $label, float|string|null $value, string $format): ?array
    {
        if ($value === null || !is_finite((float) $value)) {
            return null;
        }

        return ['label' => $label, 'value' => (float) $value, 'format' => $format];
    }
}
