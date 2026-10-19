<?php

declare(strict_types=1);

namespace App\Service\Politics\Diet;

use App\Data\Politics\AerieDiet;
use App\Data\Politics\AerieNames;
use App\DTO\PoliticsStateDTO;
use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;
use App\Service\Math\ResponseCurves;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The parties' leaders, and with them the prime minister: the leader of the largest party in the cabinet. Once a vote's
 * cabinet takes office, each leader who fought it draws whether they go within the year, a logit in the party's change
 * of vote and whether it leads the cabinet (COSPAL leader spells x ParlGov, Western Europe); outside that year, a
 * yearly hazard by the party's place in government, rising with age. A new leader of the prime minister's party is
 * prime minister at once. Every draw is hashed from the game and the leader (MathUtility::hashedUniform()), off the
 * politics stream, so the leaders change nothing else.
 */
final class PartyLeaders
{
    // --- After an Election ---
    /** Log-odds a leader goes within the year after a vote, at an unchanged vote and outside the premiership: 14.5%. */
    public const ELECTION_EXIT_INTERCEPT = -1.7733;
    /**
     * Log-odds per percentage point of the vote lost: a five-point loss lifts the chance to 36.6% (Ennser-Jedenastik &
     * Schumacher 2015).
     */
    public const ELECTION_EXIT_PER_POINT_LOST = 0.2445;
    /** Log-odds per percentage point of the vote gained, barely any (Ennser-Jedenastik & Schumacher 2015). */
    public const ELECTION_EXIT_PER_POINT_GAINED = -0.0530;
    /** Log-odds for the leader whose party leads the cabinet formed after the vote: 1.3% at an unchanged vote. */
    public const ELECTION_EXIT_PRIME_MINISTER = -2.5757;
    /** Years after the vote its draw covers; the yearly hazard takes over from there. */
    public const ELECTION_EXIT_WINDOW_YEARS = 1.0;
    /** Share of the year's departures that come in its first half. */
    public const ELECTION_EXIT_FIRST_HALF_SHARE = 44.0 / 83.0;

    // --- Between Elections ---
    /** Yearly hazard for the leader of the prime minister's party, from a year after the vote to the next. */
    public const MIDTERM_HAZARD_PRIME_MINISTER = 0.122;
    /** Yearly hazard for the leader of a junior party in the cabinet. */
    public const MIDTERM_HAZARD_CABINET = 0.199;
    /** Yearly hazard for a leader outside the cabinet, supporters included. */
    public const MIDTERM_HAZARD_OUTSIDE = 0.121;

    // --- Age ---
    /** Log of the hazard's rise per year of age: 1.51-fold a decade (Ennser-Jedenastik & Schumacher 2021). */
    public const AGE_LOG_HAZARD_PER_YEAR = 0.0415;
    /**
     * Age at which the age effect is one, set where its sample mean is one so the hazards by place in government keep
     * their measured averages.
     */
    public const AGE_HAZARD_CENTRE = 52.6;

    // --- New Leaders ---
    /** Mean age when chosen. */
    public const SELECTION_AGE_MEAN = 47.0;
    /** Standard deviation of age when chosen. */
    public const SELECTION_AGE_SD = 9.2;
    /** Youngest chosen on record, where the draw is truncated. */
    public const SELECTION_AGE_MIN = 23.0;
    /** Oldest chosen on record, where the draw is truncated. */
    public const SELECTION_AGE_MAX = 74.0;
    /**
     * Yearly hazard over every leader and year, which sets how long the opening leaders have led: the time since the
     * last renewal of a process renewing at that rate.
     */
    public const OVERALL_HAZARD = 238.0 / 1634.0;

    /**
     * One tick for the parties' leaders: drawn on the first read of a state without them, then each one weighed against
     * the last vote once its cabinet takes office, and each one who has run through their time replaced.
     *
     * @param PoliticsState $state Advanced in place; its Council must already be read (the game's salt drawn).
     */
    public static function advance(PoliticsState $state, float $dt, MathUtility $math): void
    {
        if ($state->authoritySalt < 0.0) {
            return;
        }
        $salt = (int) $state->authoritySalt;
        if ($state->leaderNames === []) {
            self::open($state, $salt, $math);

            return;
        }

        if ($state->lastElectionAt > $state->leadersReviewedElection && $state->coalitionTakesOfficeAt < 0.0) {
            self::review($state, $salt);
        }

        // The prime minister's party first, so a tick that changes two leaders makes its news of the premiership.
        $primeMinister = self::primeMinisterParty($state);
        $parties = AerieDiet::PARTIES;
        usort($parties, static fn(string $a, string $b): int => ($b === $primeMinister) <=> ($a === $primeMinister));
        foreach ($parties as $party) {
            if (!self::inElectionWindow($state, $party)) {
                $state->leaderHazardUsed[$party] = ($state->leaderHazardUsed[$party] ?? 0.0) + (self::hazard($state, $party, $primeMinister) * $dt);
            }
            $exitAt = $state->leaderExitAt[$party] ?? -1.0;
            if (($exitAt >= 0.0 && $state->totalTime >= $exitAt) || $state->leaderHazardUsed[$party] >= self::hazardBudget($salt, $party, $state->leaderSince[$party])) {
                self::replace($state, $party, $salt, $math);
            }
        }
    }

    /**
     * The chance a leader goes within the year after a vote.
     *
     * @param float $voteChangePoints The party's change in vote share at the vote, in percentage points.
     * @param bool  $primeMinister    Whether the party leads the cabinet the vote produced.
     */
    public static function electionExitChance(float $voteChangePoints, bool $primeMinister): float
    {
        $logOdds = self::ELECTION_EXIT_INTERCEPT
            + (self::ELECTION_EXIT_PER_POINT_LOST * max(0.0, -$voteChangePoints))
            + (self::ELECTION_EXIT_PER_POINT_GAINED * max(0.0, $voteChangePoints))
            + ($primeMinister ? self::ELECTION_EXIT_PRIME_MINISTER : 0.0);

        return ResponseCurves::logisticUnitInterval($logOdds, 0.0, 1.0);
    }

    /** A leader's yearly hazard outside the year after a vote: by their party's place in government, scaled for age. */
    public static function hazard(PoliticsState|PoliticsStateDTO $state, string $party, ?string $primeMinister): float
    {
        $rate = match (true) {
            $party === $primeMinister => self::MIDTERM_HAZARD_PRIME_MINISTER,
            ($state->governingCoalition[$party] ?? 0.0) > 0.5 => self::MIDTERM_HAZARD_CABINET,
            default => self::MIDTERM_HAZARD_OUTSIDE,
        };
        $age = $state->totalTime - ($state->leaderBirths[$party] ?? $state->totalTime + self::AGE_HAZARD_CENTRE);

        return $rate * exp(self::AGE_LOG_HAZARD_PER_YEAR * ($age - self::AGE_HAZARD_CENTRE));
    }

    /** The party that leads the cabinet, whose leader is prime minister: its largest by seats; null for no cabinet. */
    public static function primeMinisterParty(PoliticsState|PoliticsStateDTO $state): ?string
    {
        $cabinet = AerieDiet::governingParties($state->governingCoalition);

        return $cabinet === [] ? null : CoalitionFormation::leader($cabinet, CoalitionFormation::bySize($state->dietSeats, $state->dietVoteShares));
    }

    /** Whether a leader is in the year after a vote they fought, which its own draw covers. */
    private static function inElectionWindow(PoliticsState $state, string $party): bool
    {
        return $state->lastElectionAt >= 0.0
            && $state->leaderSince[$party] < $state->lastElectionAt
            && $state->totalTime < $state->lastElectionAt + self::ELECTION_EXIT_WINDOW_YEARS;
    }

    /**
     * The vote's verdict on every leader who fought it, drawn once its cabinet has taken office: whether they go within
     * the year after it, and when, a departure already due falling on this tick.
     */
    private static function review(PoliticsState $state, int $salt): void
    {
        $primeMinister = self::primeMinisterParty($state);
        foreach (AerieDiet::PARTIES as $party) {
            if ($state->leaderSince[$party] >= $state->lastElectionAt) {
                continue;
            }
            $key = CouncilAppointments::vacancyKey("leader-review:{$party}", $state->lastElectionAt);
            $chance = self::electionExitChance(100.0 * ($state->dietVoteSwings[$party] ?? 0.0), $party === $primeMinister);
            if (MathUtility::hashedUniform($salt, $key) < $chance) {
                $half = MathUtility::hashedUniform($salt, "{$key}:half") < self::ELECTION_EXIT_FIRST_HALF_SHARE ? 0.0 : 0.5;
                $at = $state->lastElectionAt + (self::ELECTION_EXIT_WINDOW_YEARS * ($half + (0.5 * MathUtility::hashedUniform($salt, "{$key}:when"))));
                $state->leaderExitAt[$party] = max($at, $state->totalTime);
            }
        }
        $state->leadersReviewedElection = $state->lastElectionAt;
    }

    /** The hazard a leader runs through before they go: a unit exponential, drawn for them when they take the lead. */
    private static function hazardBudget(int $salt, string $party, float $since): float
    {
        return -log(max(1e-300, MathUtility::hashedUniform($salt, CouncilAppointments::vacancyKey("leader-clock:{$party}", $since))));
    }

    /** A leader steps down, into the party's record, and a successor takes the lead. */
    private static function replace(PoliticsState $state, string $party, int $salt, MathUtility $math): void
    {
        $state->leaderHistory[$party][] = [
            'name' => $state->leaderNames[$party],
            'birth' => $state->leaderBirths[$party],
            'since' => $state->leaderSince[$party],
            'until' => $state->totalTime,
        ];
        self::seat($state, $party, self::draw($salt, $party, $state->totalTime, $state, $math), $state->totalTime);
        if ($state->lastLeaderChangeAt !== $state->totalTime) {
            $state->lastLeaderChangeAt = $state->totalTime;
            $state->lastLeaderChangeParty = $party;
        }
    }

    /**
     * The leaders as they stand when the parties are first read: each drawn as a new leader at an age drawn at their
     * selection, having led for a time drawn at the overall hazard, and none weighed against a vote already past.
     */
    private static function open(PoliticsState $state, int $salt, MathUtility $math): void
    {
        $state->leaderNames = $state->leaderBirths = $state->leaderSince = $state->leaderHazardUsed = $state->leaderExitAt = $state->leaderHistory = [];
        foreach (AerieDiet::PARTIES as $party) {
            $led = -log(max(1e-300, MathUtility::hashedUniform($salt, "leader-opening:{$party}"))) / self::OVERALL_HAZARD;
            $since = $state->totalTime - $led;
            self::seat($state, $party, self::draw($salt, $party, $since, $state, $math), $since);
        }
        $state->leadersReviewedElection = $state->lastElectionAt;
    }

    /**
     * A new leader: an age when chosen, a normal truncated to the youngest and oldest on record, and a name for their
     * birth decade that no one sitting or who ever sat holds (CouncilAppointments::reservedNames()).
     *
     * @return array{name: string, birth: float}
     */
    private static function draw(int $salt, string $party, float $since, PoliticsState $state, MathUtility $math): array
    {
        $key = CouncilAppointments::vacancyKey("leader:{$party}", $since);
        $age = Distributions::truncatedNormalInverse(MathUtility::hashedUniform($salt, "{$key}:age"), self::SELECTION_AGE_MEAN, self::SELECTION_AGE_SD, self::SELECTION_AGE_MIN, self::SELECTION_AGE_MAX);
        $birth = $since - $age;

        return [
            'name' => AerieNames::draw($birth, static fn(string $part): float => MathUtility::hashedUniform($salt, "{$key}:name:{$part}"), CouncilAppointments::reservedNames($state)),
            'birth' => $birth,
        ];
    }

    /**
     * @param array{name: string, birth: float} $leader
     */
    private static function seat(PoliticsState $state, string $party, array $leader, float $since): void
    {
        $state->leaderNames[$party] = $leader['name'];
        $state->leaderBirths[$party] = $leader['birth'];
        $state->leaderSince[$party] = $since;
        $state->leaderHazardUsed[$party] = 0.0;
        $state->leaderExitAt[$party] = -1.0;
    }
}
