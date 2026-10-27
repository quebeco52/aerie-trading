<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Sectors;
use App\Data\District\DistrictCalendar;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Repository\StockRepository;
use App\Service\Math\FinancialConstants;

/**
 * A company's profitability against its rivals' over the years: its trailing operating margin beside the median of its
 * peer group's (PeerGroup), or return on equity for a lender or insurer, whose margin is not comparable. An edge that
 * widens, holds or fades is the story the gap between the two lines tells.
 *
 * Rivals that have since failed count in the quarters they reported, so the median is not only the survivors'.
 */
final class RivalComparisonBuilder
{
    // --- History ---
    /** Quarters drawn: twelve years. */
    private const QUARTERS_SHOWN = 48;

    /** Quarters a trailing figure sums: a year. */
    private const TRAILING_QUARTERS = 4;

    /** Quarters back the gap is compared with: three years. */
    private const GAP_CHANGE_QUARTERS = 12;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly CorporateReportRepository $reports,
    ) {}

    /**
     * @return array{measure: string, peerLabel: string, peerName: string, peers: int, chart: array<string, mixed>, gap: ?float, gapThen: ?float}|null
     */
    public function build(Stock $stock): ?array
    {
        $group = PeerGroup::of($stock, $this->stocks->findAll());
        if ($group['members'] === []) {
            return null;
        }
        $financial = Sectors::strategyFor($stock->getIndustry())->isFinancial();
        $reports = $this->reports->findForStocksNewestFirst([$stock, ...$group['members']], self::QUARTERS_SHOWN + self::TRAILING_QUARTERS);
        ['own' => $own, 'median' => $median] = self::series($reports, (string) $stock->getTicker(), $financial);

        $measure = $financial ? 'Return on equity' : 'Operating margin';
        $chart = IndustryCharts::lines([
            ['name' => (string) $stock->getTicker(), 'points' => $own],
            ['name' => ucfirst($group['label']) . ' median', 'points' => $median],
        ]);
        if ($chart === null || array_filter($median, static fn(array $p): bool => $p['value'] !== null) === []) {
            return null;
        }

        $gaps = array_map(static fn(array $o, array $m): ?float => $o['value'] !== null && $m['value'] !== null ? $o['value'] - $m['value'] : null, $own, $median);
        $n = count($gaps);

        return [
            'measure' => $measure,
            'peerLabel' => $group['label'],
            'peerName' => $group['name'],
            'peers' => count($group['members']),
            'chart' => $chart,
            'gap' => $gaps[$n - 1] ?? null,
            'gapThen' => $n > self::GAP_CHANGE_QUARTERS ? $gaps[$n - 1 - self::GAP_CHANGE_QUARTERS] : null,
        ];
    }

    /**
     * The company's trailing figure each quarter it has one, and its peers' median the same quarters, oldest first. A
     * trailing margin sums four quarters of operating profit over four of sales; a trailing return on equity averages
     * four filed returns. A quarter needs all four behind it.
     *
     * @param  iterable<CorporateReport>                                                                                           $reports the company's and its peers'
     * @return array{own: list<array{t: float, label: string, value: ?float}>, median: list<array{t: float, label: string, value: ?float}>}
     */
    public static function series(iterable $reports, string $ownTicker, bool $financial): array
    {
        $byStock = [];
        foreach ($reports as $report) {
            $quarter = IndustryHistory::quarterOf($report);
            if ($quarter !== null) {
                $byStock[(string) $report->getStock()->getTicker()][$quarter] = $report;
            }
        }

        $trailing = [];
        foreach ($byStock as $ticker => $quarters) {
            foreach (array_keys($quarters) as $quarter) {
                $window = [];
                for ($back = 0; $back < self::TRAILING_QUARTERS; ++$back) {
                    if (!isset($quarters[$quarter - $back])) {
                        continue 2;
                    }
                    $window[] = $quarters[$quarter - $back];
                }
                $value = $financial ? self::meanReturnOnEquity($window) : self::margin($window);
                if ($value !== null) {
                    $trailing[$ticker][$quarter] = $value;
                }
            }
        }

        $ownQuarters = $trailing[$ownTicker] ?? [];
        ksort($ownQuarters);
        $ownQuarters = array_slice($ownQuarters, -self::QUARTERS_SHOWN, null, true);
        $own = [];
        $median = [];
        foreach ($ownQuarters as $quarter => $value) {
            $t = $quarter / FinancialConstants::QUARTERS_PER_YEAR;
            $label = DistrictCalendar::quarter($t);
            $peers = [];
            foreach ($trailing as $ticker => $values) {
                if ($ticker !== $ownTicker && isset($values[$quarter])) {
                    $peers[] = $values[$quarter];
                }
            }
            $own[] = ['t' => $t, 'label' => $label, 'value' => $value];
            $median[] = ['t' => $t, 'label' => $label, 'value' => self::median($peers)];
        }

        return ['own' => $own, 'median' => $median];
    }

    /** @param list<CorporateReport> $window */
    private static function margin(array $window): ?float
    {
        $revenue = array_sum(array_map(static fn(CorporateReport $r): float => (float) $r->getRevenue(), $window));

        return $revenue > 0.0 ? array_sum(array_map(static fn(CorporateReport $r): float => (float) $r->getEbit(), $window)) / $revenue : null;
    }

    /** @param list<CorporateReport> $window */
    private static function meanReturnOnEquity(array $window): ?float
    {
        $returns = array_filter(array_map(static fn(CorporateReport $r): ?string => $r->getReturnOnEquity(), $window), static fn(?string $v): bool => $v !== null);

        return count($returns) === count($window) ? array_sum(array_map('floatval', $returns)) / count($returns) : null;
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2.0;
    }
}
