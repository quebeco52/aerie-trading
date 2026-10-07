<?php

declare(strict_types=1);

namespace App\Service\Season;

use App\Data\DistrictCalendar;
use App\Entity\Etf;
use App\Entity\Notification;
use App\Entity\Season;
use App\Entity\SeasonEntry;
use App\Entity\User;
use App\Repository\SeasonEntryRepository;
use App\Repository\SeasonRepository;
use App\Service\Notification\PlayerNotifier;
use App\Service\User\Portfolio;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs the league: records every account's week, closes a season when its span of simulation time is up, ranks it
 * and opens the next, and reads the live table.
 *
 * Called by the ticker once a simulated week, after the tick commits and alongside the weekly portfolio snapshot, so
 * a season's record has exactly the snapshot's cadence. Seasons open on the first week the ticker records, which is
 * how a world that predates the league gets its first one.
 */
class SeasonService
{
    // --- Starting Capital ---
    /** Cash a new or restarted account receives, in Year-1 dollars; scaled by the consumer price level when paid. */
    public const STARTING_CAPITAL_YEAR_ONE = 10000.0;

    /** Slack on the season's end time, so float drift in the clock never carries a season a week past its end. */
    private const END_TOLERANCE_YEARS = 1e-6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonRepository $seasons,
        private readonly SeasonEntryRepository $entries,
        private readonly Portfolio $portfolio,
        private readonly PlayerNotifier $notifier,
    ) {}

    /** Starting cash at a given consumer price level, so a Year-40 account starts with the same purchasing power as a Year-1 one. */
    public static function startingCapital(float $consumerPriceLevel): string
    {
        return number_format(self::STARTING_CAPITAL_YEAR_ONE * max(0.0, $consumerPriceLevel ?: 1.0), 2, '.', '');
    }

    /**
     * Folds one simulated week into every open entry, and turns the season over when its time is up.
     *
     * @param float $simTime  Simulation time at the end of the week.
     * @param float $dt       Simulation years the week spans.
     * @param float $cashRate Annual rate idle cash earned over the week, the risk-free leg of the Sharpe ratio.
     */
    public function recordWeek(float $simTime, float $dt, float $cashRate): void
    {
        $benchmark = $this->benchmarkFund();
        $season = $this->seasons->findOpen() ?? $this->open($this->nextNumber(), $simTime, $benchmark);

        $level = $this->benchmarkLevel($season, $benchmark, true);
        $values = $this->portfolio->netWorths();
        $riskFree = exp($cashRate * $dt) - 1.0;

        $byUser = [];
        foreach ($this->entries->forSeason($season) as $entry) {
            $byUser[(int) $entry->getUser()->getId()] = $entry;
        }

        foreach ($values as $userId => $value) {
            $entry = $byUser[$userId] ?? null;
            if ($entry === null) {
                $this->entityManager->persist(new SeasonEntry($season, $this->userRef($userId), $simTime, $value, $level));
                continue;
            }
            $entry->recordWeek($value, $level, $riskFree, $dt);
        }

        $this->entityManager->flush();

        if ($simTime >= $season->getEndTime() - self::END_TOLERANCE_YEARS) {
            $this->turnOver($season, array_values($byUser), $values, $level, $simTime, $benchmark);
        }
    }

    /** Enters an account into the open season at its current net worth: on registration and after a fresh start's season. */
    public function join(User $user, float $simTime, float $value): void
    {
        $season = $this->seasons->findOpen();
        if ($season === null || $this->entries->findOne($season, $user) !== null) {
            return;
        }

        $level = $this->benchmarkLevel($season, $this->benchmarkFund(), false);
        $this->entityManager->persist(new SeasonEntry($season, $user, $simTime, $value, $level));
    }

    /** Takes an account out of the open season's ranking; it rejoins at the next season's open. */
    public function forfeit(User $user): void
    {
        $season = $this->seasons->findOpen();
        $entry = $season === null ? null : $this->entries->findOne($season, $user);
        $entry?->forfeit();
    }

    /**
     * The open season's table, ranked by return since entry, with risk figures from the weekly record.
     *
     * @param array<int, float> $liveValues Net worth by account id, at live prices.
     * @return list<SeasonStanding> Qualified entries first, by return; then the rest, by return.
     */
    public function standings(Season $season, array $liveValues): array
    {
        $level = $this->benchmarkLevel($season, $this->benchmarkFund(), false);

        $rows = [];
        foreach ($this->entries->forSeason($season) as $entry) {
            $userId = (int) $entry->getUser()->getId();
            $value = $liveValues[$userId] ?? $entry->getLastValue();
            $rows[] = SeasonStanding::fromEntry($entry, $value, $level);
        }

        return self::rank($rows);
    }

    /** @return list<SeasonStanding> The open season's table at live prices; empty before the first season opens. */
    public function liveTable(?Season $season = null): array
    {
        $season ??= $this->seasons->findOpen();

        return $season === null ? [] : $this->standings($season, $this->portfolio->netWorths());
    }

    /** One account's row of a ranked table, or null when it has none. */
    public static function rowFor(array $table, User $user): ?SeasonStanding
    {
        foreach ($table as $row) {
            if ($row->userId === $user->getId()) {
                return $row;
            }
        }

        return null;
    }

    /** Entries ranked in a table. */
    public static function rankedCount(array $table): int
    {
        return count(array_filter($table, static fn (SeasonStanding $row): bool => $row->rank !== null));
    }

    /**
     * Ranks standings: qualified entries by return, best first, numbered from one; then unqualified and forfeited
     * ones, unnumbered.
     *
     * @param list<SeasonStanding> $rows
     * @return list<SeasonStanding>
     */
    public static function rank(array $rows): array
    {
        $byReturn = static fn (SeasonStanding $a, SeasonStanding $b): int => ($b->return ?? -INF) <=> ($a->return ?? -INF);

        $ranked = array_values(array_filter($rows, static fn (SeasonStanding $r): bool => $r->qualified));
        $rest = array_values(array_filter($rows, static fn (SeasonStanding $r): bool => !$r->qualified));
        usort($ranked, $byReturn);
        usort($rest, $byReturn);

        foreach ($ranked as $i => $row) {
            $row->rank = $i + 1;
        }

        return array_merge($ranked, $rest);
    }

    /** Season time left, in simulation years; zero once due. */
    public static function yearsLeft(Season $season, float $simTime): float
    {
        return max(0.0, $season->getEndTime() - $simTime);
    }

    /**
     * Ranks and closes a season, tells every entrant where they finished, and opens the next from current values.
     *
     * @param list<SeasonEntry> $entries
     * @param array<int, float> $values
     */
    private function turnOver(Season $season, array $entries, array $values, float $level, float $simTime, ?Etf $benchmark): void
    {
        $standings = [];
        foreach ($entries as $entry) {
            $standings[] = SeasonStanding::fromEntry($entry, $values[(int) $entry->getUser()->getId()] ?? $entry->getLastValue(), $level);
        }
        $standings = self::rank($standings);
        $qualified = count(array_filter($standings, static fn (SeasonStanding $s): bool => $s->rank !== null));

        foreach ($standings as $standing) {
            $standing->entry->finalize($standing->value, $level, $standing->rank);
        }
        $season->close();

        $next = $this->open($season->getNumber() + 1, $simTime, $benchmark);
        $nextLevel = $this->benchmarkLevel($next, $benchmark, false);
        foreach ($values as $userId => $value) {
            $this->entityManager->persist(new SeasonEntry($next, $this->userRef($userId), $simTime, $value, $nextLevel));
        }
        $this->entityManager->flush();

        foreach ($standings as $standing) {
            if ($standing->forfeited) {
                continue;
            }
            $this->notifier->queue(
                (int) $standing->entry->getUser()->getId(),
                Notification::KIND_SEASON,
                self::closingTitle($season->getNumber(), $standing, $qualified),
                sprintf(
                    'The index returned %s over the season. Season %d runs to %s and starts from your current net worth.',
                    self::signedPercent($standing->benchmarkReturn),
                    $next->getNumber(),
                    DistrictCalendar::quarter($next->getEndTime())
                ),
                null,
                '/leaderboard'
            );
        }
        $this->notifier->publish();
    }

    private static function closingTitle(int $number, SeasonStanding $standing, int $qualified): string
    {
        if ($standing->rank === null) {
            return sprintf('Season %d closed: you returned %s, with too few weeks to be ranked', $number, self::signedPercent($standing->return));
        }

        return sprintf('Season %d closed: you finished %s of %d with %s', $number, self::ordinal($standing->rank), $qualified, self::signedPercent($standing->return));
    }

    private static function signedPercent(?float $fraction): string
    {
        return $fraction === null ? 'n/a' : sprintf('%+.1f%%', $fraction * 100.0);
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n . $suffix;
    }

    private function open(int $number, float $simTime, ?Etf $benchmark): Season
    {
        $season = new Season($number, $simTime, $simTime + Season::LENGTH_YEARS);
        // A distribution paid before the season opened is already out of the price; it must not be reinvested again.
        $season->setBenchmarkDistributionSeenAt($benchmark?->getLastDistributionAt());
        $this->entityManager->persist($season);
        $this->entityManager->flush();

        return $season;
    }

    private function nextNumber(): int
    {
        return ($this->seasons->findLatest()?->getNumber() ?? 0) + 1;
    }

    private function benchmarkFund(): ?Etf
    {
        return $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => Season::BENCHMARK_TICKER]);
    }

    /**
     * The benchmark's total-return level: price times the units one unit has become by reinvesting distributions.
     * With $reinvest, a distribution paid since the last look is folded in at the current (ex-date) price first.
     */
    private function benchmarkLevel(Season $season, ?Etf $fund, bool $reinvest): float
    {
        if ($fund === null) {
            return 1.0;
        }

        $price = (float) $fund->getPrice();
        $paidAt = $fund->getLastDistributionAt();
        $seen = $season->getBenchmarkDistributionSeenAt();

        if ($reinvest && $paidAt !== null && ($seen === null || $paidAt > $seen)) {
            $season->reinvestDistribution((float) ($fund->getRecentDistributions()[0] ?? 0.0), $price, $paidAt);
        }

        return $price * $season->getBenchmarkReinvestFactor();
    }

    private function userRef(int $userId): User
    {
        /** @var User */
        return $this->entityManager->getReference(User::class, $userId);
    }
}
