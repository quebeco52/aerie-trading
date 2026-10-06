<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The District's calendar: a simulation time, in years since its records begin, as its pages date things. Year 1 is
 * the first year; each year has the twelve months of the civil calendar, as twelve equal twelfths of the year, so a
 * date's quarter is always the quarter its month falls in.
 */
final class DistrictCalendar
{
    // --- Calendar ---

    /** Month names as a dateline abbreviates them. */
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Days in each month of a common year. */
    private const MONTH_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    /** Months in a quarter. */
    private const MONTHS_PER_QUARTER = 3;

    /**
     * The year and quarter ("Year 13 Q2"). The time is read to the microyear, so a vote the accumulated clock puts a
     * hair short of the term's end is dated on it.
     */
    public static function quarter(float $simTime): string
    {
        [$year, $month] = self::yearAndMonth($simTime);

        return sprintf('Year %d Q%d', $year, intdiv($month, self::MONTHS_PER_QUARTER) + 1);
    }

    /** A news dateline: the day, month and year ("3 Mar, Year 13"). */
    public static function dateline(float $simTime): string
    {
        [$year, $month, $monthFraction] = self::yearAndMonth($simTime);
        $day = min(self::MONTH_DAYS[$month], (int) floor($monthFraction * self::MONTH_DAYS[$month] + 1e-9) + 1);

        return sprintf('%d %s, Year %d', $day, self::MONTHS[$month], $year);
    }

    /**
     * @return array{int, int, float} The year counted from 1, the month from 0, and how far through the month.
     */
    private static function yearAndMonth(float $simTime): array
    {
        $simTime = round($simTime, 6);
        $year = (int) floor($simTime);
        $months = ($simTime - $year) * count(self::MONTHS);
        $month = min(count(self::MONTHS) - 1, (int) floor($months + 1e-9));

        return [$year + 1, $month, max(0.0, $months - $month)];
    }
}
