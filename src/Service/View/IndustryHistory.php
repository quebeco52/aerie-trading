<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\District\DistrictCalendar;
use App\Entity\CorporateReport;
use App\Service\Math\FinancialConstants;

/**
 * An industry's quarters, summed from the reports its listed companies filed: what it sold and who sold it, the price
 * it realised against a balanced industry's, the plant it left idle and the plant it had under construction.
 *
 * A report is filed in the quarter after the one it covers (ReportCalendar), so each is put in the quarter before its
 * filing. The price is weighted by sales and idle plant by plant, as an industry's own figures would be.
 */
final class IndustryHistory
{
    /**
     * Tolerance on a filing time's quarter, so a report filed exactly on a quarter's turn is not put a quarter early.
     */
    private const QUARTER_EPSILON = 1e-6;

    /** The quarter a report covers, counted from the opening (Year 1 Q1 is 0), or null for one filed undated. */
    public static function quarterOf(CorporateReport $report): ?int
    {
        $filed = $report->getTotalTime();

        return $filed === null ? null : (int) floor((float) $filed * FinancialConstants::QUARTERS_PER_YEAR + self::QUARTER_EPSILON) - 1;
    }

    /**
     * Quarters oldest first. The newest is dropped when fewer companies have filed for it than for the one before: a
     * season still under way would read as a fall in sales and a jump in the shares of those already in.
     *
     * @param  iterable<CorporateReport> $reports
     * @return list<array{t: float, label: string, revenue: float, shares: array<string, float>, priceGap: ?float, idle: ?float, building: ?float, margin: ?float}>
     */
    public static function quarters(iterable $reports): array
    {
        $sums = [];
        foreach ($reports as $report) {
            $quarter = self::quarterOf($report);
            if ($quarter === null) {
                continue;
            }
            $kpis = $report->getReportedKpis() ?? [];
            $revenue = max(0.0, (float) $report->getRevenue());
            $plant = max(0.0, (float) $report->getNetPpe());
            $ticker = (string) $report->getStock()->getTicker();

            $sum = $sums[$quarter] ?? ['revenue' => 0.0, 'ebit' => 0.0, 'plant' => 0.0, 'cip' => 0.0, 'priceW' => 0.0, 'price' => 0.0, 'idleW' => 0.0, 'idle' => 0.0, 'sellers' => []];
            $sum['revenue'] += $revenue;
            $sum['ebit'] += (float) $report->getEbit();
            $sum['plant'] += $plant;
            $sum['cip'] += max(0.0, (float) $report->getCip());
            $sum['sellers'][$ticker] = ($sum['sellers'][$ticker] ?? 0.0) + $revenue;
            if (isset($kpis['industry_price_level'])) {
                $sum['priceW'] += $revenue;
                $sum['price'] += $revenue * (float) $kpis['industry_price_level'];
            }
            if (isset($kpis['idle_plant_share'])) {
                $sum['idleW'] += $plant;
                $sum['idle'] += $plant * (float) $kpis['idle_plant_share'];
            }
            $sums[$quarter] = $sum;
        }
        ksort($sums);

        $keys = array_keys($sums);
        if (count($keys) >= 2 && count($sums[$keys[count($keys) - 1]]['sellers']) < count($sums[$keys[count($keys) - 2]]['sellers'])) {
            unset($sums[$keys[count($keys) - 1]]);
        }

        $quarters = [];
        foreach ($sums as $quarter => $sum) {
            $t = $quarter / FinancialConstants::QUARTERS_PER_YEAR;
            arsort($sum['sellers']);
            $quarters[] = [
                't' => $t,
                'label' => DistrictCalendar::quarter($t),
                'revenue' => $sum['revenue'],
                'shares' => $sum['revenue'] > 0.0 ? array_map(static fn(float $r): float => $r / $sum['revenue'], $sum['sellers']) : [],
                'priceGap' => $sum['priceW'] > 0.0 ? $sum['price'] / $sum['priceW'] - 1.0 : null,
                'idle' => $sum['idleW'] > 0.0 ? $sum['idle'] / $sum['idleW'] : null,
                'building' => $sum['plant'] > 0.0 ? $sum['cip'] / $sum['plant'] : null,
                'margin' => $sum['revenue'] > 0.0 ? $sum['ebit'] / $sum['revenue'] : null,
            ];
        }

        return $quarters;
    }
}
