<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\DistrictCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The District's calendar: Year 1 starts at simulation time 0, months are twelfths of a year, and a dateline's month
 * always sits in the quarter the quarter label names.
 */
class DistrictCalendarTest extends TestCase
{
    public function testTheCalendarStartsInYearOne(): void
    {
        $this->assertSame('1 Jan, Year 1', DistrictCalendar::dateline(0.0));
        $this->assertSame('Year 1 Q1', DistrictCalendar::quarter(0.0));
    }

    public function testDatelinesFallOnTheRightMonthAndDay(): void
    {
        $this->assertSame('1 Apr, Year 14', DistrictCalendar::dateline(13.25));
        $this->assertSame('16 Jul, Year 3', DistrictCalendar::dateline(2.5 + 15.0 / 31.0 / 12.0));
        $this->assertSame('31 Dec, Year 5', DistrictCalendar::dateline(4.9999));
    }

    /** A clock a hair short of a boundary is dated on it, as the government page dates a vote at the end of a term. */
    public function testAClockAHairShortOfABoundaryIsDatedOnIt(): void
    {
        $this->assertSame('Year 5 Q1', DistrictCalendar::quarter(4.0 - 1e-9));
        $this->assertSame('1 Jan, Year 5', DistrictCalendar::dateline(4.0 - 1e-9));
    }

    public function testEveryDatelineSitsInItsQuarter(): void
    {
        $quarterOfMonth = ['Jan' => 1, 'Feb' => 1, 'Mar' => 1, 'Apr' => 2, 'May' => 2, 'Jun' => 2, 'Jul' => 3, 'Aug' => 3, 'Sep' => 3, 'Oct' => 4, 'Nov' => 4, 'Dec' => 4];

        for ($t = 0.0; $t < 3.0; $t += 1.0 / 1000.0) {
            preg_match('/^(\d+) (\w{3}), Year (\d+)$/', DistrictCalendar::dateline($t), $date);
            $this->assertSame(sprintf('Year %d Q%d', $date[3], $quarterOfMonth[$date[2]]), DistrictCalendar::quarter($t), "t={$t}");
            $this->assertGreaterThanOrEqual(1, (int) $date[1]);
            $this->assertLessThanOrEqual(31, (int) $date[1]);
        }
    }
}
