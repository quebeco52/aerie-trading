<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Service\Corporate\EarningsEngine;
use App\Service\View\ReportCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The page's results calendar reads the engine's own schedule: the next date is the next tick on which the ticker
 * reports, and each report covers the quarter before the one it was filed in.
 */
class ReportCalendarTest extends TestCase
{
    private const TICKS_PER_YEAR = 400;

    private const TICKS_PER_QUARTER = 100;

    public function testTheEngineReportsExactlyWhenTheCalendarSaysItLastDid(): void
    {
        $reportingTick = EarningsEngine::resolveReportingTick('CAL', self::TICKS_PER_YEAR);
        $onTick = 3 * self::TICKS_PER_QUARTER + $reportingTick;

        $this->assertSame(0, EarningsEngine::ticksSinceReportingTick('CAL', $onTick, self::TICKS_PER_YEAR));
        $this->assertSame(self::TICKS_PER_QUARTER - 1, EarningsEngine::ticksSinceReportingTick('CAL', $onTick - 1, self::TICKS_PER_YEAR));
    }

    public function testTheNextReportIsTheNextReportingTickAndNeverNow(): void
    {
        $reportingTick = EarningsEngine::resolveReportingTick('CAL', self::TICKS_PER_YEAR);
        $tick = 5 * self::TICKS_PER_QUARTER + $reportingTick - 7;
        $now = $tick / self::TICKS_PER_YEAR;

        $this->assertEqualsWithDelta($now + 7 / self::TICKS_PER_YEAR, ReportCalendar::nextReportTime('CAL', $tick, self::TICKS_PER_YEAR, $now), 1e-12);

        // On the reporting tick itself the report is filed; the next one is a quarter on.
        $onTick = 5 * self::TICKS_PER_QUARTER + $reportingTick;
        $onTime = $onTick / self::TICKS_PER_YEAR;
        $this->assertEqualsWithDelta($onTime + 0.25, ReportCalendar::nextReportTime('CAL', $onTick, self::TICKS_PER_YEAR, $onTime), 1e-12);
    }

    public function testEachReportIsLabelledWithTheQuarterBeforeItWasFiled(): void
    {
        $reportingTick = EarningsEngine::resolveReportingTick('CAL', self::TICKS_PER_YEAR);
        // Year 4 Q2, just after the ticker's reporting tick: the latest report covers Year 4 Q1.
        $tick = 3 * self::TICKS_PER_YEAR + self::TICKS_PER_QUARTER + $reportingTick + 2;

        $labels = ReportCalendar::periodLabels('CAL', $tick, self::TICKS_PER_YEAR, $tick / self::TICKS_PER_YEAR, 5);

        $this->assertSame(['Year 3 Q1', 'Year 3 Q2', 'Year 3 Q3', 'Year 3 Q4', 'Year 4 Q1'], $labels);
    }

    public function testMoreReportsThanQuartersElapsedCannotBeDated(): void
    {
        $tick = self::TICKS_PER_QUARTER + EarningsEngine::resolveReportingTick('CAL', self::TICKS_PER_YEAR);
        $now = $tick / self::TICKS_PER_YEAR;

        $this->assertSame(['Year 1 Q1'], ReportCalendar::periodLabels('CAL', $tick, self::TICKS_PER_YEAR, $now, 1));
        $this->assertNull(ReportCalendar::periodLabels('CAL', $tick, self::TICKS_PER_YEAR, $now, 2));
    }
}
