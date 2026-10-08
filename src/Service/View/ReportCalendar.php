<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\DistrictCalendar;
use App\Service\Corporate\EarningsEngine;

/**
 * When a company reports, as its page dates it: the next results date, and the quarter each filed report covers.
 *
 * The schedule is EarningsEngine's: a ticker files on a fixed tick of every quarter, inside the reporting season
 * that follows the quarter's close, so a report covers the quarter before the one it is filed in. A report carries
 * the time it was filed; one filed before reports did is dated by counting back a quarter at a time from the next
 * dated report, or, with none, from the latest reporting tick on the economic clock.
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

    /** The quarter a report filed at this time covers ("Year 13 Q2"); null before the District's records begin. */
    public static function coveredQuarter(float $filingTime): ?string
    {
        $covered = $filingTime - self::QUARTER_YEARS;

        return $covered < 0.0 ? null : DistrictCalendar::quarter($covered);
    }

    /**
     * The quarter each report covers, oldest first: from its own filing time where it carries one, else counted back
     * a quarter per report from the next report that does, else the schedule's count-back label.
     *
     * @param list<float|null>  $filingTimes  Each report's filing time, oldest first; null where it was not recorded.
     * @param list<string>|null $countedBack  periodLabels() for the same reports, or null where it cannot place them.
     * @return list<string|null>
     */
    public static function reportLabels(array $filingTimes, ?array $countedBack): array
    {
        $labels = [];
        $next = null;
        for ($index = count($filingTimes) - 1; $index >= 0; $index--) {
            $time = $filingTimes[$index];
            if ($time !== null) {
                $next = [$index, $time];
                $labels[$index] = self::coveredQuarter($time);
            } elseif ($next !== null) {
                $labels[$index] = self::coveredQuarter($next[1] - ($next[0] - $index) * self::QUARTER_YEARS);
            } else {
                $labels[$index] = $countedBack[$index] ?? null;
            }
        }
        ksort($labels);

        return array_values($labels);
    }
}
