<?php

declare(strict_types=1);

namespace App\Tests\Service\Season;

use App\Entity\Season;
use App\Entity\SeasonEntry;
use App\Entity\User;
use App\Service\Season\SeasonService;
use App\Service\Season\SeasonStanding;
use App\Service\Season\SeasonStatistics;
use PHPUnit\Framework\TestCase;

/**
 * The season record: running sums folded week by week must give the same Sharpe ratio, beta and alpha as the
 * textbook formulas applied to the whole series, the drawdown must be the deepest peak-to-trough fall, and the
 * table must rank only qualified entries.
 */
final class SeasonStatisticsTest extends TestCase
{
    private const WEEK = 1.0 / 52.0;

    /** Weekly portfolio and benchmark returns with a known relation: r = 0.001 + 1.5 b + noise. */
    private const BENCHMARK = [0.010, -0.020, 0.015, 0.005, -0.012, 0.030, -0.004, 0.008, -0.025, 0.018, 0.002, -0.006, 0.011, 0.004];
    private const NOISE = [0.002, -0.001, 0.0, 0.003, -0.002, 0.001, -0.003, 0.002, 0.0, -0.001, 0.001, 0.002, -0.002, 0.0];
    private const RISK_FREE_WEEK = 0.0004;

    /** @return array{0: list<float>, 1: list<float>} Portfolio and benchmark weekly returns. */
    private function series(): array
    {
        $r = [];
        foreach (self::BENCHMARK as $i => $b) {
            $r[] = 0.001 + 1.5 * $b + self::NOISE[$i];
        }

        return [$r, self::BENCHMARK];
    }

    private function entryFromSeries(): SeasonEntry
    {
        [$r, $b] = $this->series();
        $entry = new SeasonEntry(new Season(1, 0.0, Season::LENGTH_YEARS), new User(), 0.0, 10000.0, 100.0);

        $value = 10000.0;
        $level = 100.0;
        foreach ($r as $i => $ret) {
            $value *= 1.0 + $ret;
            $level *= 1.0 + $b[$i];
            $entry->recordWeek($value, $level, self::RISK_FREE_WEEK, self::WEEK);
        }

        return $entry;
    }

    private static function mean(array $xs): float
    {
        return array_sum($xs) / count($xs);
    }

    private static function sampleVar(array $xs): float
    {
        $m = self::mean($xs);

        return array_sum(array_map(static fn (float $x): float => ($x - $m) ** 2, $xs)) / (count($xs) - 1);
    }

    public function testSharpeFromSumsEqualsTheDirectFormula(): void
    {
        [$r] = $this->series();
        $expected = (self::mean($r) - self::RISK_FREE_WEEK) / sqrt(self::sampleVar($r)) * sqrt(52.0);

        $this->assertEqualsWithDelta($expected, SeasonStatistics::sharpe($this->entryFromSeries()->statisticsInput()), 1e-9);
    }

    public function testBetaAndAlphaRecoverTheRegression(): void
    {
        [$r, $b] = $this->series();
        $mr = self::mean($r);
        $mb = self::mean($b);
        $cov = 0.0;
        foreach ($r as $i => $x) {
            $cov += ($x - $mr) * ($b[$i] - $mb);
        }
        $beta = ($cov / (count($r) - 1)) / self::sampleVar($b);
        $alpha = (($mr - self::RISK_FREE_WEEK) - $beta * ($mb - self::RISK_FREE_WEEK)) * 52.0;

        $in = $this->entryFromSeries()->statisticsInput();
        $this->assertEqualsWithDelta($beta, SeasonStatistics::beta($in), 1e-9);
        $this->assertEqualsWithDelta($alpha, SeasonStatistics::alpha($in), 1e-9);
        // The series was built with a beta of 1.5; the noise moves the estimate only a little.
        $this->assertEqualsWithDelta(1.5, SeasonStatistics::beta($in), 0.1);
    }

    public function testAnnualisationReadsTheElapsedTimeNotAnAssumedWeek(): void
    {
        $entry = new SeasonEntry(new Season(1, 0.0, 4.0), new User(), 0.0, 100.0, 1.0);
        // Two-week periods: 26 a year, so volatility scales by √26, not √52.
        foreach ([0.02, -0.01, 0.03, -0.02] as $ret) {
            $entry->recordWeek($entry->getLastValue() * (1 + $ret), 1.0, 0.0, 2.0 / 52.0);
        }
        $sd = sqrt(self::sampleVar([0.02, -0.01, 0.03, -0.02]));

        $this->assertEqualsWithDelta($sd * sqrt(26.0), SeasonStatistics::volatility($entry->statisticsInput()), 1e-9);
    }

    public function testDrawdownIsTheDeepestFallFromAPeak(): void
    {
        $entry = new SeasonEntry(new Season(1, 0.0, 4.0), new User(), 0.0, 100.0, 1.0);
        foreach ([120.0, 90.0, 130.0, 104.0, 125.0] as $value) {
            $entry->recordWeek($value, 1.0, 0.0, self::WEEK);
        }

        // 120 -> 90 is 25%; 130 -> 104 is 20%.
        $this->assertEqualsWithDelta(0.25, $entry->getMaxDrawdown(), 1e-12);
    }

    public function testTooFewWeeksGiveNoFigures(): void
    {
        $entry = new SeasonEntry(new Season(1, 0.0, 4.0), new User(), 0.0, 100.0, 1.0);
        $entry->recordWeek(101.0, 1.0, 0.0, self::WEEK);
        $in = $entry->statisticsInput();

        $this->assertNull(SeasonStatistics::sharpe($in));
        $this->assertNull(SeasonStatistics::beta($in));
        $this->assertNull(SeasonStatistics::alpha($in));
    }

    public function testAWipedOutAccountStopsAddingWeeks(): void
    {
        $entry = new SeasonEntry(new Season(1, 0.0, 4.0), new User(), 0.0, 100.0, 1.0);
        $entry->recordWeek(0.0, 1.0, 0.0, self::WEEK);
        $entry->recordWeek(0.0, 1.01, 0.0, self::WEEK);

        $this->assertSame(1, $entry->getPeriods(), 'A week starting from nothing has no return to record.');
        $this->assertEqualsWithDelta(1.0, $entry->getMaxDrawdown(), 1e-12);
    }

    public function testAForfeitedEntryRecordsNothingMore(): void
    {
        $entry = new SeasonEntry(new Season(1, 0.0, 4.0), new User(), 0.0, 100.0, 1.0);
        $entry->forfeit();
        $entry->recordWeek(150.0, 1.0, 0.0, self::WEEK);

        $this->assertSame(0, $entry->getPeriods());
        $this->assertFalse($entry->isQualified());
    }

    public function testOnlyQualifiedEntriesAreRankedAndBestReturnLeads(): void
    {
        $season = new Season(1, 0.0, 4.0);
        $make = function (float $finalValue, int $weeks) use ($season): SeasonStanding {
            $entry = new SeasonEntry($season, new User(), 0.0, 100.0, 1.0);
            for ($i = 0; $i < $weeks; $i++) {
                $entry->recordWeek(100.0, 1.0, 0.0, self::WEEK);
            }

            return SeasonStanding::fromEntry($entry, $finalValue, 1.0);
        };

        $late = $make(300.0, Season::MIN_QUALIFYING_WEEKS - 1);
        $second = $make(110.0, Season::MIN_QUALIFYING_WEEKS);
        $first = $make(140.0, Season::MIN_QUALIFYING_WEEKS + 5);

        $table = SeasonService::rank([$late, $second, $first]);

        $this->assertSame([$first, $second, $late], $table);
        $this->assertSame(1, $first->rank);
        $this->assertSame(2, $second->rank);
        $this->assertNull($late->rank, 'A lucky late joiner is listed, not ranked.');
    }

    public function testStartingCapitalKeepsYearOnePurchasingPower(): void
    {
        $this->assertSame('10000.00', SeasonService::startingCapital(1.0));
        $this->assertSame('23456.00', SeasonService::startingCapital(2.3456));
        $this->assertSame('10000.00', SeasonService::startingCapital(0.0), 'A missing price level falls back to Year 1.');
    }

    public function testADistributionIsReinvestedOnceAtTheExPrice(): void
    {
        $season = new Season(1, 0.0, 4.0);
        $paid = new \DateTimeImmutable('2026-01-01');

        $season->reinvestDistribution(2.0, 100.0, $paid);

        $this->assertEqualsWithDelta(1.02, $season->getBenchmarkReinvestFactor(), 1e-12);
        $this->assertEquals($paid, $season->getBenchmarkDistributionSeenAt());
    }
}
