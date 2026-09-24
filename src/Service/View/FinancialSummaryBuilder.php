<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Sectors;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
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

    /** Tiles on the strip; beyond this the summary stops being a summary. */
    private const MAX_TILES = 6;

    /** Quarters in a year: a quarter's charge-offs and income annualize by four. */
    private const QUARTERS_PER_YEAR = 4.0;

    /** Operating KPIs the business models report, as label and display format; anything else is not shown. */
    private const KPI_LABELS = [
        'book_to_bill' => ['Book-to-Bill', 'multiple'],
        'backlog_quarters' => ['Backlog', 'quarters'],
        'contract_book_to_bill' => ['Charter Book-to-Bill', 'multiple'],
        'contract_backlog_quarters' => ['Charter Backlog', 'quarters'],
        'bookings_to_revenue' => ['Bookings / Revenue', 'multiple'],
        'deferred_revenue_quarters' => ['Deferred Revenue', 'quarters'],
        'enrollment_to_revenue' => ['Enrollment / Revenue', 'multiple'],
        'deferred_tuition_quarters' => ['Deferred Tuition', 'quarters'],
        'rollout_wave_quarters' => ['Rollout Wave Age', 'quarters'],
        'withheld_royalty_quarters' => ['Royalties Withheld', 'quarters'],
        'subscriber_index' => ['Subscriber Index', 'index'],
        'quarterly_churn' => ['Quarterly Churn', 'percent'],
        'net_adds' => ['Net Adds', 'signed_index'],
        'arpu_index' => ['ARPU Index', 'index'],
        'walt_years' => ['WALT', 'years'],
        'releasing_spread' => ['Re-leasing Spread', 'signed_percent'],
        'in_place_rent_index' => ['In-Place Rent Index', 'index'],
        'realized_price_index' => ['Realized Price Index', 'index'],
        'power_price_index' => ['Realized Power Price', 'index'],
        'hedge_gain' => ['Hedge Gain / Revenue', 'signed_percent'],
        'capture_rate' => ['Crack Capture', 'percent'],
        'throughput_index' => ['Throughput Index', 'index'],
    ];

    public function __construct(
        private readonly CorporateReportRepository $reports,
    ) {}

    /**
     * @return array{financialSummary: list<array{label: string, value: float, format: string}>}
     */
    public function build(Stock $stock): array
    {
        $report = $stock->isBankrupt() ? null : $this->reports->findLatestFor($stock);
        if ($report === null) {
            return ['financialSummary' => []];
        }

        $businessModel = Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
        $strategy = Sectors::getBusinessModelStrategy($businessModel);

        $tiles = match (true) {
            (float) $report->getEarningAssets() > 0.0 && $strategy->isFinancial() => $this->lenderTiles($report),
            $strategy->requiresAlternativeZScore() => $this->capitalTiles($report, $strategy instanceof InsuranceBusinessModel),
            default => $this->operatingTiles($report),
        };

        foreach ($report->getReportedKpis() ?? [] as $key => $value) {
            if (isset(self::KPI_LABELS[$key]) && is_numeric($value)) {
                $tiles[] = $this->tile(self::KPI_LABELS[$key][0], (float) $value, self::KPI_LABELS[$key][1]);
            }
        }

        return ['financialSummary' => array_slice(array_values(array_filter($tiles)), 0, self::MAX_TILES)];
    }

    /** @return list<array{label: string, value: float, format: string}|null> */
    private function lenderTiles(CorporateReport $report): array
    {
        $book = (float) $report->getEarningAssets();

        return [
            $this->tile('Net Interest Margin', $report->getNetInterestMargin(), 'percent'),
            $report->getCet1Ratio() !== null
                ? $this->tile('CET1 Ratio', $report->getCet1Ratio(), 'percent')
                : $this->tile('Capital Ratio', $report->getCapitalRatio(), 'percent'),
            $this->tile('Reserve / Book', (float) $report->getCreditLossAllowance() / $book, 'percent'),
            $this->tile('Net Charge-Off Rate', ((float) $report->getNetChargeOffs() * self::QUARTERS_PER_YEAR) / $book, 'percent'),
            $this->tile('ROE', $report->getReturnOnEquity(), 'percent'),
        ];
    }

    /** @return list<array{label: string, value: float, format: string}|null> */
    private function capitalTiles(CorporateReport $report, bool $isInsurer): array
    {
        $margin = $report->getOperatingMargin();

        return [
            $this->tile('Capital Ratio', $report->getCapitalRatio(), 'percent'),
            $this->tile('ROE', $report->getReturnOnEquity(), 'percent'),
            // An insurer is read on its combined ratio: claims and expenses per dollar of premium.
            $isInsurer
                ? ($margin === null ? null : $this->tile('Combined Ratio', 1.0 - (float) $margin, 'percent'))
                : $this->tile('Operating Margin', $margin, 'percent'),
        ];
    }

    /** @return list<array{label: string, value: float, format: string}|null> */
    private function operatingTiles(CorporateReport $report): array
    {
        $netIncome = (float) $report->getNetIncome();
        $spread = $report->getRoic() !== null && $report->getWacc() !== null ? (float) $report->getRoic() - (float) $report->getWacc() : null;

        return [
            $this->tile('Operating Margin', $report->getOperatingMargin(), 'percent'),
            $this->tile('ROIC − WACC', $spread, 'signed_percent'),
            $netIncome > 0.0 && $report->getFreeCashFlow() !== null
                ? $this->tile('FCF / Net Income', (float) $report->getFreeCashFlow() / $netIncome, 'percent')
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
