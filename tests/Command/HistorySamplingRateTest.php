<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MarketTickerCommand;
use PHPUnit\Framework\TestCase;

/**
 * Chart ranges are spans of simulated time, so they have to be derived from the configured tick rate.
 *
 * The stock page's range buttons used to carry hardcoded row counts ('1y' => 4800) that only matched a
 * 4,800-tick year. At the configured rate the "1Y" button was fetching sixteen months of prices and "3M"
 * four, so every span on the chart was wrong by the ratio between the two rates — and silently, because
 * a chart with too much data on it still draws.
 */
final class HistorySamplingRateTest extends TestCase
{
    /** Below the target, every tick is recorded and the rate is the tick rate itself. */
    public function testFineTickRatesRecordEveryTick(): void
    {
        $this->assertSame(3600, MarketTickerCommand::historyPointsPerYear(3600));
        $this->assertSame(252, MarketTickerCommand::historyPointsPerYear(252));
    }

    /** Above it, sampling thins out toward the target rather than growing without bound. */
    public function testCoarseTickRatesSampleDownTowardTheTarget(): void
    {
        $points = MarketTickerCommand::historyPointsPerYear(54000);

        $this->assertLessThanOrEqual(MarketTickerCommand::TARGET_HISTORY_POINTS_PER_YEAR * 2, $points);
        $this->assertGreaterThanOrEqual(MarketTickerCommand::TARGET_HISTORY_POINTS_PER_YEAR, $points);
    }

    /** Never zero, whatever the configuration, since it is used as a divisor and a LIMIT. */
    public function testRateIsAlwaysPositive(): void
    {
        foreach ([1, 4, 252, 365, 3600, 7200, 14400, 54000, 864000] as $ticksPerYear) {
            $this->assertGreaterThan(0, MarketTickerCommand::historyPointsPerYear($ticksPerYear));
        }
    }

    /** It matches what the ticker loop actually writes: one row every historyInterval ticks. */
    public function testRateMatchesTheTickerWriteInterval(): void
    {
        foreach ([252, 3600, 14400, 54000] as $ticksPerYear) {
            $interval = MarketTickerCommand::historyIntervalTicks($ticksPerYear);

            $this->assertSame(
                intdiv($ticksPerYear, $interval),
                MarketTickerCommand::historyPointsPerYear($ticksPerYear),
                "Sampling rate disagrees with the ticker's own write interval at {$ticksPerYear} ticks/year."
            );
        }
    }

    /** And the controller derives its range limits rather than carrying tick counts of its own. */
    public function testHistoryEndpointDoesNotHardcodeTickCounts(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Controller/StockController.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'MarketTickerCommand::historyPointsPerYear',
            $source,
            'Range limits must be derived from the sampling rate, not hardcoded.'
        );
        // A bare integer against a range name is a row count; a fraction is a span of years.
        $this->assertDoesNotMatchRegularExpression(
            "/'(?:3m|6m|1y|3y|5y|10y)'\s*=>\s*\d+\s*[,\]]/",
            $source,
            'A hardcoded row count for a named range pins the chart to one tick rate.'
        );
        $this->assertStringContainsString("'3m' => 0.25", $source, 'Ranges are declared as spans of years.');
    }
}
