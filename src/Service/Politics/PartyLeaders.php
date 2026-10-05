<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieDiet;
use App\Data\AerieNames;
use App\DTO\PoliticsStateDTO;
use App\Service\Math\MathUtility;

/**
 * The parties' leaders, and with them the prime minister: the leader of the largest party in the cabinet.
 *
 * A leader goes in one of two ways. Once the cabinet a general election produced takes office, every leader who fought
 * the vote draws whether they go within the year after it: a logit in the party's change of vote and in whether it now
 * leads the cabinet, fitted on Western European parties (COSPAL leader spells x ParlGov, 1957-2016; Belgium left out, its
 * party presidents never being prime minister; var/harness/leaders). Outside that year, a yearly hazard by the party's
 * place in government, rising with the leader's age as it did on the same records. A new leader of the prime
 * minister's party is prime minister at once, as in Sweden and Denmark every time since 1945.
 *
 * Every draw is hashed from the game and the leader (CouncilAppointments::uniform()), off the politics stream, so the
 * leaders change nothing else in the game.
 */
final class PartyLeaders
{
    // --- After an Election (COSPAL x ParlGov: 467 party-elections, 83 leaders gone within the year) ---
    /** Log-odds that a leader goes within the year after a vote, at an unchanged vote and outside the premiership: 14.5%. */
    public const ELECTION_EXIT_INTERCEPT = -1.7733;
    /** Log-odds per percentage point of the vote lost: a five-point loss lifts the chance to 36.6% (Ennser-Jedenastik & Schumacher 2015: a loss of over a point, HR 2.15). */
    public const ELECTION_EXIT_PER_POINT_LOST = 0.2445;
    /** Log-odds per percentage point of the vote gained, barely any (se 0.074; Ennser-Jedenastik & Schumacher 2015: a gain, HR 0.94, not significant). */
    public const ELECTION_EXIT_PER_POINT_GAINED = -0.0530;
    /** Log-odds for the leader whose party leads the cabinet formed after the vote: 1.3% at an unchanged vote. */
    public const ELECTION_EXIT_PRIME_MINISTER = -2.5757;
    /** Years after the vote its draw covers; the yearly hazard takes over from there. */
    public const ELECTION_EXIT_WINDOW_YEARS = 1.0;
    /** Share of the year's departures that come in its first half: 44 of the 83. */
    public const ELECTION_EXIT_FIRST_HALF_SHARE = 44.0 / 83.0;

    // --- Between Elections (same sample, from a year after the vote to the next) ---
    /** Yearly hazard for the leader of the prime minister's party: 25 departures in 205 leader-years. */
    public const MIDTERM_HAZARD_PRIME_MINISTER = 0.122;
    /** Yearly hazard for the leader of a junior party in the cabinet: 29 in 145. */
    public const MIDTERM_HAZARD_CABINET = 0.199;
    /** Yearly hazard for a leader outside the cabinet, supporters included: 95 in 785. */
    public const MIDTERM_HAZARD_OUTSIDE = 0.121;

    // --- Age (COSPAL leader spells, Western Europe excluding Belgium: 273 leaders, 1,532 leader-years) ---
    /** Log of the hazard's rise per year of the leader's age: 1.51 a decade (se 0.0072; Ennser-Jedenastik & Schumacher 2021, holding results and office fixed, find 60 and over at 1.53 against 46 to 59). */
    public const AGE_LOG_HAZARD_PER_YEAR = 0.0415;
    /** Age at which the age effect is one: its mean over the sample's leader-years is one there, so the hazards by place in government keep the averages they were measured at. */
    public const AGE_HAZARD_CENTRE = 52.6;

    // --- New Leaders (COSPAL: 354 leaders at their first selection) ---
    /** Mean age when chosen. */
    public const SELECTION_AGE_MEAN = 47.0;
    /** Standard deviation of age when chosen (10th percentile 36, 90th 60). */
    public const SELECTION_AGE_SD = 9.2;
    /** Youngest chosen on record, where the draw is truncated. */
    public const SELECTION_AGE_MIN = 23.0;
    /** Oldest chosen on record, where the draw is truncated. */
    public const SELECTION_AGE_MAX = 74.0;
    /** Yearly hazard over every leader and year of the sample, 238 departures in 1,634 leader-years: how long the leaders sitting when the parties are first read have led, as the time since the last renewal of a process renewing at that rate. */
    public const OVERALL_HAZARD = 238.0 / 1634.0;

    /**
     * One tick for the parties' leaders: drawn on the first read of a state without them, then each one weighed against
     * the last vote once its cabinet takes office, and each one who has run through their time replaced.
     *
     * @param PoliticsState $state The politics, advanced in place, its Council already read (the game's salt drawn).
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

        return MathUtility::logisticUnitInterval($logOdds, 0.0, 1.0);
    }

    /**
     * A leader's yearly hazard outside the year after a vote: by their party's place in government, scaled for their age.
     *
     * @param ?string $primeMinister The prime minister's party, or null for none.
     */
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

    /**
     * Whether a leader is in the year after a vote they fought, which its own draw covers.
     */
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
            if (CouncilAppointments::uniform($salt, $key) < $chance) {
                $half = CouncilAppointments::uniform($salt, "{$key}:half") < self::ELECTION_EXIT_FIRST_HALF_SHARE ? 0.0 : 0.5;
                $at = $state->lastElectionAt + (self::ELECTION_EXIT_WINDOW_YEARS * ($half + (0.5 * CouncilAppointments::uniform($salt, "{$key}:when"))));
                $state->leaderExitAt[$party] = max($at, $state->totalTime);
            }
        }
        $state->leadersReviewedElection = $state->lastElectionAt;
    }

    /** The hazard a leader runs through before they go: a unit exponential, drawn for them when they take the lead. */
    private static function hazardBudget(int $salt, string $party, float $since): float
    {
        return -log(max(1e-300, CouncilAppointments::uniform($salt, CouncilAppointments::vacancyKey("leader-clock:{$party}", $since))));
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
            $led = -log(max(1e-300, CouncilAppointments::uniform($salt, "leader-opening:{$party}"))) / self::OVERALL_HAZARD;
            $since = $state->totalTime - $led;
            self::seat($state, $party, self::draw($salt, $party, $since, $state, $math), $since);
        }
        $state->leadersReviewedElection = $state->lastElectionAt;
    }

    /**
     * A new leader: their age when chosen from the record of new leaders (a normal truncated to the youngest and oldest
     * on it), and a name for their birth decade no one sitting or who ever sat holds (CouncilAppointments::reservedNames()).
     *
     * @return array{name: string, birth: float}
     */
    private static function draw(int $salt, string $party, float $since, PoliticsState $state, MathUtility $math): array
    {
        $key = CouncilAppointments::vacancyKey("leader:{$party}", $since);
        $age = $math->truncatedNormalInverse(CouncilAppointments::uniform($salt, "{$key}:age"), self::SELECTION_AGE_MEAN, self::SELECTION_AGE_SD, self::SELECTION_AGE_MIN, self::SELECTION_AGE_MAX);
        $birth = $since - $age;

        return [
            'name' => AerieNames::draw($birth, static fn(string $part): float => CouncilAppointments::uniform($salt, "{$key}:name:{$part}"), CouncilAppointments::reservedNames($state)),
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
