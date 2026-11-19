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
 * A company's profitability over the years against a benchmark it can be read against: its trailing operating margin
 * beside the median of its listed rivals' (PeerGroup), or return on equity for a lender or insurer, whose margin is
 * not comparable. An edge that widens, holds or fades is the story the gap between the two lines tells.
 *
 * Rivals are only companies in the same industry. A business in another industry of the sector is no rival (an oil
 * producer's margin rides the crude price, a refiner's the crack spread), so with too few listed rivals the company is
 * read against its own three-year average instead: how far this year runs from its recent norm.
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

    /** Quarters the company's own average spans when it has too few rivals: three years. */
    private const AVERAGE_QUARTERS = 12;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly CorporateReportRepository $reports,
    ) {}

    /**
     * @return array{mode: 'rivals'|'own', measure: string, peerName: string, peers: int, chart: array<string, mixed>, gap: ?float, gapThen: ?float}|null
     */
    public function build(Stock $stock): ?array
    {
        $group = PeerGroup::of($stock, $this->stocks->findAll());
        $financial = Sectors::strategyFor($stock->getIndustry())->isFinancial();
        $measure = $financial ? 'Return on equity' : 'Operating margin';
        $ticker = (string) $stock->getTicker();
        $isOwn = self::modeFor($group) === 'own';

        if ($isOwn) {
            $reports = $this->reports->findForStocksNewestFirst([$stock], self::QUARTERS_SHOWN + self::AVERAGE_QUARTERS);
            ['own' => $own, 'average' => $benchmark] = self::ownSeries($reports, $ticker, $financial);
            $benchmarkName = 'Three-year average';
        } else {
            $reports = $this->reports->findForStocksNewestFirst([$stock, ...$group['members']], self::QUARTERS_SHOWN + self::TRAILING_QUARTERS);
            ['own' => $own, 'median' => $benchmark] = self::series($reports, $ticker, $financial);
            $benchmarkName = 'Industry median';
        }

        $chart = IndustryCharts::lines([
            ['name' => $ticker, 'points' => $own],
            ['name' => $benchmarkName, 'points' => $benchmark],
        ]);
        if ($chart === null || array_filter($benchmark, static fn(array $p): bool => $p['value'] !== null) === []) {
            return null;
        }

        $gaps = array_map(static fn(array $o, array $m): ?float => $o['value'] !== null && $m['value'] !== null ? $o['value'] - $m['value'] : null, $own, $benchmark);
        $n = count($gaps);

        return [
            'mode' => $isOwn ? 'own' : 'rivals',
            'measure' => $measure,
            'peerName' => (string) $stock->getIndustry(),
            'peers' => $isOwn ? 0 : count($group['members']),
            'chart' => $chart,
            'gap' => $gaps[$n - 1] ?? null,
            // Against itself there is no edge to widen or fade, only a distance from the norm.
            'gapThen' => !$isOwn && $n > self::GAP_CHANGE_QUARTERS ? $gaps[$n - 1 - self::GAP_CHANGE_QUARTERS] : null,
        ];
    }

    /**
     * Rivals when the industry has enough listed companies to be its own peer group; otherwise the company's own
     * record.
     *
     * @param array{label: 'industry'|'sector', name: string, members: list<Stock>} $group
     * @return 'rivals'|'own'
     */
    public static function modeFor(array $group): string
    {
        return $group['label'] === 'industry' && $group['members'] !== [] ? 'rivals' : 'own';
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
        $trailing = self::trailingByQuarter(self::byStock($reports), self::TRAILING_QUARTERS, $financial);

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

    /**
     * The company's trailing figure each quarter it has one, and the same figure over the last three years, oldest
     * first. The three-year figure sums twelve quarters of operating profit over twelve of sales (or averages twelve
     * filed returns on equity), and needs all twelve behind it.
     *
     * @param  iterable<CorporateReport>                                                                                           $reports the company's own
     * @return array{own: list<array{t: float, label: string, value: ?float}>, average: list<array{t: float, label: string, value: ?float}>}
     */
    public static function ownSeries(iterable $reports, string $ticker, bool $financial): array
    {
        $byStock = self::byStock($reports);
        $trailing = self::trailingByQuarter($byStock, self::TRAILING_QUARTERS, $financial)[$ticker] ?? [];
        $average = self::trailingByQuarter($byStock, self::AVERAGE_QUARTERS, $financial)[$ticker] ?? [];
        ksort($trailing);
        $trailing = array_slice($trailing, -self::QUARTERS_SHOWN, null, true);

        $own = [];
        $mean = [];
        foreach ($trailing as $quarter => $value) {
            $t = $quarter / FinancialConstants::QUARTERS_PER_YEAR;
            $label = DistrictCalendar::quarter($t);
            $own[] = ['t' => $t, 'label' => $label, 'value' => $value];
            $mean[] = ['t' => $t, 'label' => $label, 'value' => $average[$quarter] ?? null];
        }

        return ['own' => $own, 'average' => $mean];
    }

    /**
     * Reports by ticker and quarter.
     *
     * @param  iterable<CorporateReport>                 $reports
     * @return array<string, array<int, CorporateReport>>
     */
    private static function byStock(iterable $reports): array
    {
        $byStock = [];
        foreach ($reports as $report) {
            $quarter = IndustryHistory::quarterOf($report);
            if ($quarter !== null) {
                $byStock[(string) $report->getStock()->getTicker()][$quarter] = $report;
            }
        }

        return $byStock;
    }

    /**
     * Each company's figure over the `$window` quarters ending at each quarter that has all of them behind it.
     *
     * @param  array<string, array<int, CorporateReport>> $byStock
     * @return array<string, array<int, float>>
     */
    private static function trailingByQuarter(array $byStock, int $window, bool $financial): array
    {
        $trailing = [];
        foreach ($byStock as $ticker => $quarters) {
            foreach (array_keys($quarters) as $quarter) {
                $span = [];
                for ($back = 0; $back < $window; ++$back) {
                    if (!isset($quarters[$quarter - $back])) {
                        continue 2;
                    }
                    $span[] = $quarters[$quarter - $back];
                }
                $value = $financial ? self::meanReturnOnEquity($span) : self::margin($span);
                if ($value !== null) {
                    $trailing[$ticker][$quarter] = $value;
                }
            }
        }

        return $trailing;
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
