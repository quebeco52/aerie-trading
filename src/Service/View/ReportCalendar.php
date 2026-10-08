<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\DistrictCalendar;
use App\Service\Corporate\EarningsEngine;

/**
 * When a company reports, as its page dates it: the next results date, and the quarter each filed report covers.
 *
 * The schedule is EarningsEngine's: a ticker files on a fixed tick of every quarter, inside the reporting season
 * that follows the quarter's close. A report therefore covers the quarter before the one it is filed in, and a
 * listed company files every quarter, so the latest report fixes the period of every one before it. Times are
 * placed on the economic clock (where the page's dates come from) by the tick distance the counter gives.
 */
final class ReportCalendar
{
    // --- Reporting Calendar ---

    /** A quarter, in years: the distance between two reports and between a filing and the quarter it covers. */
    private const QUARTER_YEARS = EarningsEngine::REPORT_INTERVAL_YEARS;

    /**
     * Simulation time of the company's next results, strictly after now: a report filed on this very tick is past.
     */
    public static function nextReportTime(string $ticker, int $tickCount, int $ticksPerYear, float $totalTime): float
    {
        $ticksPerQuarter = max(1, intdiv($ticksPerYear, 4));
        $since = EarningsEngine::ticksSinceReportingTick($ticker, $tickCount, $ticksPerYear);

        return $totalTime + ($ticksPerQuarter - $since) / max(1, $ticksPerYear);
    }

    /** Simulation time of the most recent reporting tick, at or before now. */
    public static function latestReportTime(string $ticker, int $tickCount, int $ticksPerYear, float $totalTime): float
    {
        return $totalTime - EarningsEngine::ticksSinceReportingTick($ticker, $tickCount, $ticksPerYear) / max(1, $ticksPerYear);
    }

    /**
     * The quarter each of a company's last $count reports covers ("Year 13 Q2"), oldest first, ending with the
     * report filed on the latest reporting tick. Null when that would reach back before the District's records
     * begin: more reports than quarters means they were not filed one a quarter, and no period can be read off.
     *
     * @return list<string>|null
     */
    public static function periodLabels(string $ticker, int $tickCount, int $ticksPerYear, float $totalTime, int $count): ?array
    {
        $latestCovered = self::latestReportTime($ticker, $tickCount, $ticksPerYear, $totalTime) - self::QUARTER_YEARS;
        if ($latestCovered - ($count - 1) * self::QUARTER_YEARS < 0.0) {
            return null;
        }

        $labels = [];
        for ($age = $count - 1; $age >= 0; $age--) {
            $labels[] = DistrictCalendar::quarter($latestCovered - $age * self::QUARTER_YEARS);
        }

        return $labels;
    }
}
