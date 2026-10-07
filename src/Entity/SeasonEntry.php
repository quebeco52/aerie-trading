<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\Season\SeasonStatistics;
use App\Service\Season\SeasonStatisticsInput;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's record in one season: its value when it joined, and the running sums of its weekly returns, the
 * benchmark's and the cash rate's over the same weeks.
 *
 * Sums rather than a series, so the Sharpe ratio, beta, alpha and drawdown are read in constant time for the whole
 * table (SeasonStatistics) and nothing has to rescan portfolio history. An account that took a fresh start mid-season
 * is forfeited: its record stops and it rejoins next season.
 */
#[ORM\Entity(repositoryClass: \App\Repository\SeasonEntryRepository::class)]
#[ORM\Table(name: 'season_entries')]
#[ORM\UniqueConstraint(name: 'uniq_season_entry_user', columns: ['season_id', 'user_id'])]
class SeasonEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Season::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Season $season;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Simulation time the account entered the season. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $joinedTime;

    /** Net worth at entry: the base every return in the season is measured from. */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 2)]
    private string $startValue;

    /** Benchmark total-return level at entry. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $benchmarkStartLevel;

    // --- Weekly record ---
    #[ORM\Column(type: Types::FLOAT)]
    private float $lastValue;

    #[ORM\Column(type: Types::FLOAT)]
    private float $peakValue;

    #[ORM\Column(type: Types::FLOAT)]
    private float $lastBenchmarkLevel;

    /** Deepest fall from a weekly peak, as a fraction of the peak. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $maxDrawdown = 0.0;

    #[ORM\Column(options: ['default' => 0])]
    private int $periods = 0;

    /** Simulation years the recorded weeks span, which annualises the sums. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $sumDt = 0.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $sumReturn = 0.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $sumReturnSq = 0.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $sumBenchmark = 0.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $sumBenchmarkSq = 0.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $sumCross = 0.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $sumRiskFree = 0.0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $forfeited = false;

    // --- Final standing, written when the season closes ---
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 2, nullable: true)]
    private ?string $finalValue = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $finalReturn = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $finalBenchmarkReturn = null;

    /** Place among qualified entries; null for an entry that did not qualify or was forfeited. */
    #[ORM\Column(nullable: true)]
    private ?int $finalRank = null;

    public function __construct(Season $season, User $user, float $joinedTime, float $startValue, float $benchmarkLevel)
    {
        $this->season = $season;
        $this->user = $user;
        $this->joinedTime = $joinedTime;
        $this->startValue = number_format($startValue, 2, '.', '');
        $this->lastValue = $startValue;
        $this->peakValue = $startValue;
        $this->benchmarkStartLevel = $benchmarkLevel;
        $this->lastBenchmarkLevel = $benchmarkLevel;
    }

    /**
     * Folds one week into the record.
     *
     * @param float $value          Net worth at the end of the week.
     * @param float $benchmarkLevel Benchmark total-return level at the end of the week.
     * @param float $riskFree       The cash rate's return over the week, as a fraction.
     * @param float $dt             Simulation years the week spans.
     */
    public function recordWeek(float $value, float $benchmarkLevel, float $riskFree, float $dt): void
    {
        if ($this->forfeited) {
            return;
        }

        // A week that starts from nothing has no return; an account wiped out stays at -100% and stops adding weeks.
        if ($this->lastValue > 0.0 && $this->lastBenchmarkLevel > 0.0) {
            $r = $value / $this->lastValue - 1.0;
            $b = $benchmarkLevel / $this->lastBenchmarkLevel - 1.0;

            $this->periods++;
            $this->sumDt += $dt;
            $this->sumReturn += $r;
            $this->sumReturnSq += $r * $r;
            $this->sumBenchmark += $b;
            $this->sumBenchmarkSq += $b * $b;
            $this->sumCross += $r * $b;
            $this->sumRiskFree += $riskFree;
        }

        $this->lastValue = $value;
        $this->lastBenchmarkLevel = $benchmarkLevel;
        $this->peakValue = max($this->peakValue, $value);
        if ($this->peakValue > 0.0) {
            $this->maxDrawdown = max($this->maxDrawdown, 1.0 - max(0.0, $value) / $this->peakValue);
        }
    }

    /** Writes the closing standing. */
    public function finalize(float $value, float $benchmarkLevel, ?int $rank): void
    {
        $this->finalValue = number_format($value, 2, '.', '');
        $this->finalReturn = SeasonStatistics::growth($value, (float) $this->startValue);
        $this->finalBenchmarkReturn = SeasonStatistics::growth($benchmarkLevel, $this->benchmarkStartLevel);
        $this->finalRank = $rank;
    }

    public function forfeit(): void
    {
        $this->forfeited = true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getJoinedTime(): float
    {
        return $this->joinedTime;
    }

    public function getStartValue(): float
    {
        return (float) $this->startValue;
    }

    public function getBenchmarkStartLevel(): float
    {
        return $this->benchmarkStartLevel;
    }

    public function getLastValue(): float
    {
        return $this->lastValue;
    }

    public function getLastBenchmarkLevel(): float
    {
        return $this->lastBenchmarkLevel;
    }

    public function getMaxDrawdown(): float
    {
        return $this->maxDrawdown;
    }

    public function getPeriods(): int
    {
        return $this->periods;
    }

    public function isForfeited(): bool
    {
        return $this->forfeited;
    }

    public function isQualified(): bool
    {
        return !$this->forfeited && $this->periods >= Season::MIN_QUALIFYING_WEEKS;
    }

    public function getFinalValue(): ?float
    {
        return $this->finalValue === null ? null : (float) $this->finalValue;
    }

    public function getFinalReturn(): ?float
    {
        return $this->finalReturn;
    }

    public function getFinalBenchmarkReturn(): ?float
    {
        return $this->finalBenchmarkReturn;
    }

    public function getFinalRank(): ?int
    {
        return $this->finalRank;
    }

    /** The running sums, for SeasonStatistics. */
    public function statisticsInput(): SeasonStatisticsInput
    {
        return new SeasonStatisticsInput(
            periods: $this->periods,
            sumDt: $this->sumDt,
            sumReturn: $this->sumReturn,
            sumReturnSq: $this->sumReturnSq,
            sumBenchmark: $this->sumBenchmark,
            sumBenchmarkSq: $this->sumBenchmarkSq,
            sumCross: $this->sumCross,
            sumRiskFree: $this->sumRiskFree,
        );
    }
}
