<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieCouncil;
use App\Data\AerieNames;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;

/**
 * The Monetary Authority: a governor the Council appoints for one fixed term, and the rate committee the governor
 * picks, who set the policy rate by the Authority's published rule.
 *
 * Everyone involved -- councillors, the governor, the committee -- holds a stance on money: a hawk, who would rather
 * fight inflation, a dove, who would rather spare jobs, or a swing vote between them, in the mix the Fed watchers found on
 * the FOMC (Bordo & Istrefi 2023). A councillor does not set the rate; the Council's say runs through whom it appoints.
 *
 * Every vacancy is filled from a shortlist of three candidates, each with a stance and an age drawn from the record, and
 * the appointer names the one whose stance stands nearest their own: a Council seat goes to the candidate nearest the
 * sitting members' median, as a majority vote picks; the governorship to the one nearest the whole Council's median; a
 * committee seat to the one nearest the governor. A short list does not always offer a like mind, so the Council's lean
 * drifts as its seats turn over. The people sitting at Year 1 were chosen the same way before it.
 *
 * The committee's balance, its members' stances averaged with the governor's counting as one (as Bordo & Istrefi's HD0.5
 * weighs the chair), marks a hawkish or a dovish supermajority when it stands where the FOMC's top or bottom quarter of
 * meetings did; the supermajority is what the economy reads (App\DTO\GovernmentPolicyDTO). At each of its eight meetings a year every
 * member votes, dissenting at the rates the FOMC's members of their type did; the draws, like the candidates', are
 * hashed from a salt the Authority draws once, so they take nothing more from the politics engine's random stream.
 */
final class MonetaryAuthority
{
    // --- Stances (Bordo & Istrefi 2023; Hack, Istrefi & Meier 2023) ---
    /** Each type's stance, on the scale the Fed watchers' record is scored on: +1 a hawk, 0 a swing vote, -1 a dove (Hack, Istrefi & Meier 2023). */
    public const STANCES = ['hawk' => 1.0, 'swing' => 0.0, 'dove' => -1.0];
    /** FOMC members of each type, 1960-2015, of the 121 the press placed (Bordo & Istrefi 2023, Table 1): the mix candidates are drawn from. */
    public const TYPE_COUNTS = ['hawk' => 51, 'swing' => 31, 'dove' => 39];

    // --- Candidates ---
    /** Candidates on each shortlist: from three, a dovish appointer names a dove 69% of the time, as the Democratic presidents' nominees to the Federal Reserve Board were 65% doves, 17 of 26 (Bordo & Istrefi 2023, Fig. 4). */
    public const SHORTLIST = 3;
    /** Mean age at appointment, in years: the Federal Reserve Board's 101 governors at their first oath, 1914-2026. */
    public const APPOINTMENT_AGE_MEAN = 52.56;
    /** Standard deviation of age at appointment; the Board's percentiles fit a normal (10th 43.7, median 52.9, 90th 60.5). */
    public const APPOINTMENT_AGE_SD = 7.32;
    /** Youngest age at appointment on the Board's record, where the draw is truncated. */
    public const APPOINTMENT_AGE_MIN = 35.9;
    /** Oldest age at appointment on the Board's record. */
    public const APPOINTMENT_AGE_MAX = 71.7;

    // --- The Governor ---
    /** Length of the governor's single term, in years: one non-renewable term, as at the ECB and, since 2013, the Bank of England. */
    public const GOVERNOR_TERM_YEARS = 8.0;
    /** When the term of the governor sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_GOVERNOR_TERM_END = 3.0;

    // --- The Rate Committee ---
    /** Members of the rate committee beside the governor. */
    public const COMMITTEE_MEMBERS = 6;
    /** Length of a member's term in years, staggered so a seat falls vacant once a year. */
    public const MEMBER_TERM_YEARS = 6.0;
    /** Rate meetings a year, as at the FOMC, the ECB and the Bank of England. */
    public const MEETINGS_PER_YEAR = 8;

    // --- The Committee's Balance (Bordo & Istrefi 2023; Hack, Istrefi & Meier 2023) ---
    /** Average stance at or above which the committee holds a hawkish supermajority: the FOMC's 75th percentile, its balance per meeting normal around a median of 0.10 with an sd of 0.34 (Hack, Istrefi & Meier 2023, 1960-2023), as Bordo & Istrefi cut theirs at its quartiles. */
    public const HAWKISH_MAJORITY_STANCE = 0.33;
    /** Average stance at or below which it holds a dovish supermajority: the FOMC's 25th percentile. */
    public const DOVISH_MAJORITY_STANCE = -0.13;

    // --- Votes (Malmendier, Nagel & Yan 2021; Bordo & Istrefi 2023, Table 7) ---
    /** Share of the FOMC's votes that dissented for a higher rate: 265 of 6,707, 1951-2014 (Malmendier, Nagel & Yan 2021). */
    public const HIGHER_DISSENT_SHARE = 265 / 6707;
    /** Share that dissented for a lower rate: 160 of 6,707. */
    public const LOWER_DISSENT_SHARE = 160 / 6707;
    /** Dissents for a higher rate by type, 1960-2015, each type's dissents by their split (Bordo & Istrefi 2023, Table 7). */
    public const HIGHER_DISSENTS = ['hawk' => 171, 'swing' => 84, 'dove' => 7];
    /** Dissents for a lower rate by type. */
    public const LOWER_DISSENTS = ['hawk' => 16, 'swing' => 43, 'dove' => 105];

    // --- News ---
    /** A rate move that makes news on its own, a meeting's standard step: 25bp. */
    public const NEWSWORTHY_RATE_MOVE = 0.0025;

    /**
     * One tick: seats that have fallen vacant refilled, the Council first, then the governor, then the committee; the
     * committee's balance and supermajority whenever it changed; and a meeting's votes when one falls due.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     * @param MacroStateDTO $macro The economy as this tick left it.
     * @param float         $dt    Time increment in years.
     */
    public static function advance(PoliticsState $state, MacroStateDTO $macro, float $dt, MathUtility $math): void
    {
        $time = $state->totalTime;
        if ($state->authoritySalt < 0.0) {
            self::open($state, $time, $math);
        }
        $salt = (int) $state->authoritySalt;

        foreach (AerieCouncil::roster($time) as $seat => $holder) {
            if (abs(($state->councilSince[$seat] ?? -INF) - $holder['since']) > 1e-9) {
                $sitting = $state->councilStances;
                unset($sitting[$seat]);
                [$chosen, $state->councillorPassedOver] = self::appoint($salt, "council:{$seat}", $holder['since'], self::median($sitting), self::sittingNames($state), $math);
                self::seatCouncillor($state, $seat, $chosen, $holder['since']);
                $state->lastCouncillorSeatedAt = $time;
            }
        }

        $changed = false;
        $governorTerm = self::termStart($time, self::OPENING_GOVERNOR_TERM_END, self::GOVERNOR_TERM_YEARS);
        if (abs($state->governorTermStart - $governorTerm) > 1e-9) {
            [$chosen, $state->governorPassedOver] = self::appoint($salt, 'governor', $governorTerm, self::median($state->councilStances), self::sittingNames($state), $math);
            self::seatGovernor($state, $chosen, $governorTerm);
            $state->lastGovernorAppointedAt = $time;
            $changed = true;
        }

        for ($member = 0; $member < self::COMMITTEE_MEMBERS; ++$member) {
            $since = self::termStart($time, self::memberOpeningTermEnd($member), self::MEMBER_TERM_YEARS);
            if (abs(($state->memberSince[$member] ?? -INF) - $since) > 1e-9) {
                [$chosen] = self::appoint($salt, "member:{$member}", $since, $state->governorStance, self::sittingNames($state), $math);
                self::seatMember($state, $member, $chosen, $since);
                $changed = true;
            }
        }

        if ($changed) {
            $majority = self::rebalance($state);
            if ($majority !== $state->committeeMajority) {
                $state->lastMajorityShiftAt = $time;
            }
            $state->committeeMajority = $majority;
        }

        if (MathUtility::crossedSimulatedBoundary($time, $dt, 1.0 / self::MEETINGS_PER_YEAR)) {
            self::meet($state, $macro->policyRate);
        }
    }

    /**
     * The Authority as it stood at Year 1, or as it stands when a state that predates it is first read: its salt drawn;
     * the councillors sitting drawn from the type mix, each at an age drawn at their seating, the ones seated before Year 1
     * under their own names; then the governor and the committee chosen from shortlists as they would have been.
     */
    private static function open(PoliticsState $state, float $time, MathUtility $math): void
    {
        $state->authoritySalt = floor($math->generateUniform() * (2 ** 31));
        $salt = (int) $state->authoritySalt;
        $state->councilNames = $state->councilBirths = $state->councilSince = $state->councilStances = [];
        $state->memberNames = $state->memberBirths = $state->memberSince = $state->memberStances = [];
        $state->governorName = '';

        foreach (AerieCouncil::roster($time) as $seat => $holder) {
            $drawn = self::candidate($salt, self::vacancyKey("council:{$seat}", $holder['since']), 0, $holder['since'], self::sittingNames($state), $math);
            if ($holder['beforeYearOne']) {
                $drawn['name'] = AerieCouncil::OPENING_MEMBERS[$seat];
            }
            self::seatCouncillor($state, $seat, $drawn, $holder['since']);
        }
        $state->councillorPassedOver = [];

        $governorTerm = self::termStart($time, self::OPENING_GOVERNOR_TERM_END, self::GOVERNOR_TERM_YEARS);
        [$chosen, $state->governorPassedOver] = self::appoint($salt, 'governor', $governorTerm, self::median($state->councilStances), self::sittingNames($state), $math);
        if ($governorTerm < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_GOVERNOR;
        }
        self::seatGovernor($state, $chosen, $governorTerm);

        for ($member = 0; $member < self::COMMITTEE_MEMBERS; ++$member) {
            $since = self::termStart($time, self::memberOpeningTermEnd($member), self::MEMBER_TERM_YEARS);
            [$chosen] = self::appoint($salt, "member:{$member}", $since, $state->governorStance, self::sittingNames($state), $math);
            self::seatMember($state, $member, $chosen, $since);
        }
        $state->committeeMajority = self::rebalance($state);
    }

    /**
     * A vacancy filled: a shortlist drawn, and the candidate whose stance stands nearest the appointer's target named,
     * the first drawn of any tied.
     *
     * @param string       $seat   The office and seat, e.g. "council:4", "governor", "member:2".
     * @param float        $since  When the term begins.
     * @param float        $target The appointer's stance: the sitting Council's median, or the governor's own.
     * @param list<string> $taken  Names already sitting, which no candidate shares.
     * @return array{0: array{name: string, birth: float, stance: float}, 1: list<array{name: string, birth: float, stance: float}>} The one named, and the ones passed over.
     */
    public static function appoint(int $salt, string $seat, float $since, float $target, array $taken, MathUtility $math): array
    {
        $vacancy = self::vacancyKey($seat, $since);
        $shortlist = [];
        for ($slot = 0; $slot < self::SHORTLIST; ++$slot) {
            $shortlist[] = $candidate = self::candidate($salt, $vacancy, $slot, $since, $taken, $math);
            $taken[] = $candidate['name'];
        }

        $chosen = 0;
        foreach ($shortlist as $slot => $candidate) {
            if (abs($candidate['stance'] - $target) < abs($shortlist[$chosen]['stance'] - $target) - 1e-12) {
                $chosen = $slot;
            }
        }
        $named = $shortlist[$chosen];
        unset($shortlist[$chosen]);

        return [$named, array_values($shortlist)];
    }

    /**
     * A candidate for a vacancy: their stance drawn from the type mix, their age at the term's start from the Board's
     * record (a normal truncated to the youngest and oldest on it, by inverse transform), and a name no one sitting holds.
     *
     * @param list<string> $taken
     * @return array{name: string, birth: float, stance: float}
     */
    public static function candidate(int $salt, string $vacancy, int $slot, float $since, array $taken, MathUtility $math): array
    {
        $draw = self::uniform($salt, "{$vacancy}:{$slot}:stance") * array_sum(self::TYPE_COUNTS);
        $type = 'dove';
        foreach (self::TYPE_COUNTS as $name => $count) {
            if ($draw < $count) {
                $type = $name;
                break;
            }
            $draw -= $count;
        }

        $low = $math->calculateNormalCDF((self::APPOINTMENT_AGE_MIN - self::APPOINTMENT_AGE_MEAN) / self::APPOINTMENT_AGE_SD);
        $high = $math->calculateNormalCDF((self::APPOINTMENT_AGE_MAX - self::APPOINTMENT_AGE_MEAN) / self::APPOINTMENT_AGE_SD);
        $quantile = $low + (self::uniform($salt, "{$vacancy}:{$slot}:age") * ($high - $low));
        $age = self::APPOINTMENT_AGE_MEAN + (self::APPOINTMENT_AGE_SD * $math->calculateInverseNormalCDF($quantile));

        for ($attempt = 0; ; ++$attempt) {
            $name = AerieNames::pick(
                self::uniform($salt, "{$vacancy}:{$slot}:tradition:{$attempt}"),
                self::uniform($salt, "{$vacancy}:{$slot}:given:{$attempt}"),
                self::uniform($salt, "{$vacancy}:{$slot}:family:{$attempt}")
            );
            if (!in_array($name, $taken, true)) {
                break;
            }
        }

        return ['name' => $name, 'birth' => $since - $age, 'stance' => self::STANCES[$type]];
    }

    /**
     * The committee's balance: its members' stances averaged, the governor's counting as one, which is Bordo & Istrefi's
     * HD0.5 (half the chair's stance and half each other voter's) over the seats, the hawks less the doves per seat.
     *
     * @param list<float> $memberStances
     */
    public static function balance(float $governorStance, array $memberStances): float
    {
        return ($governorStance + array_sum($memberStances)) / (1 + count($memberStances));
    }

    /** The supermajority a balance makes: 1 hawkish at or above the FOMC's top quarter's edge, -1 dovish at or below its bottom's, 0 neither. */
    public static function majority(float $balance): float
    {
        return $balance >= self::HAWKISH_MAJORITY_STANCE ? 1.0 : ($balance <= self::DOVISH_MAJORITY_STANCE ? -1.0 : 0.0);
    }

    /**
     * Each member's vote at a meeting: with the decision, or a dissent for a higher or a lower rate at the chance a member
     * of their type dissented on the FOMC. No count of votes by type survives, so the chance is the share of all votes
     * that dissented that way, times the type's share of those dissents, over its share of the members (Bayes' rule,
     * votes split as members are). The uniform is hashed from the salt, the meeting and the member.
     *
     * @param list<float> $stances The members' stances, the governor first.
     * @param int         $meeting The meeting's number since Year 1.
     * @param int         $salt    The Authority's salt.
     * @return list<float> 1 for a higher rate, -1 for a lower, 0 with the decision.
     */
    public static function votes(array $stances, int $meeting, int $salt): array
    {
        $votes = [];
        foreach ($stances as $member => $stance) {
            $type = self::stanceName($stance);
            $uniform = self::uniform($salt, "vote:{$meeting}:{$member}");
            $votes[] = $uniform < self::dissentChance($type, true) ? 1.0 : ($uniform > 1.0 - self::dissentChance($type, false) ? -1.0 : 0.0);
        }

        return $votes;
    }

    /** The chance a member of a type dissents at a meeting, for a higher rate or a lower. */
    public static function dissentChance(string $type, bool $higher): float
    {
        $dissents = $higher ? self::HIGHER_DISSENTS : self::LOWER_DISSENTS;
        $share = $higher ? self::HIGHER_DISSENT_SHARE : self::LOWER_DISSENT_SHARE;

        return $share * ($dissents[$type] / array_sum($dissents)) / (self::TYPE_COUNTS[$type] / array_sum(self::TYPE_COUNTS));
    }

    /** The type a stance belongs to: 'hawk', 'swing' or 'dove'. */
    public static function stanceName(float $stance): string
    {
        return $stance >= 0.5 ? 'hawk' : ($stance <= -0.5 ? 'dove' : 'swing');
    }

    /** Where the term in progress at a moment began, for a seat whose first term after Year 1 ends at $firstEnd. */
    public static function termStart(float $time, float $firstEnd, float $term): float
    {
        return $time < $firstEnd ? $firstEnd - $term : $firstEnd + ($term * floor((($time - $firstEnd) / $term) + 1e-12));
    }

    /** When the term of committee seat m's member sitting at Year 1 ends: the seats fall vacant a year apart, half a year off Year 1, so all were seated before it. */
    public static function memberOpeningTermEnd(int $member): float
    {
        return ($member + 0.5) * self::MEMBER_TERM_YEARS / self::COMMITTEE_MEMBERS;
    }

    /** When the governor in office at a moment leaves. */
    public static function governorTermEnd(float $time): float
    {
        return self::termStart($time, self::OPENING_GOVERNOR_TERM_END, self::GOVERNOR_TERM_YEARS) + self::GOVERNOR_TERM_YEARS;
    }

    /** @param list<float>|array<int, float> $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        $values = array_values($values);
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /** Whether the last meeting, if it sat this tick, moved the rate a full step or split the committee. */
    public static function meetingMadeNews(PoliticsState $state): bool
    {
        return $state->lastMeetingAt === $state->totalTime
            && (abs($state->lastMeetingChange) >= self::NEWSWORTHY_RATE_MOVE || array_filter($state->lastMeetingVotes, static fn(float $vote): bool => $vote !== 0.0) !== []);
    }

    /** A meeting: the votes, and the rate the meeting leaves. */
    private static function meet(PoliticsState $state, float $policyRate): void
    {
        $meeting = (int) round($state->totalTime * self::MEETINGS_PER_YEAR);
        $state->lastMeetingVotes = self::votes(array_merge([$state->governorStance], array_values($state->memberStances)), $meeting, (int) $state->authoritySalt);
        $state->lastMeetingChange = $state->lastMeetingAt < 0.0 ? 0.0 : $policyRate - $state->lastMeetingRate;
        $state->lastMeetingRate = $policyRate;
        $state->lastMeetingAt = $state->totalTime;
    }

    /** The committee's balance refreshed, and the supermajority it now makes. */
    private static function rebalance(PoliticsState $state): float
    {
        $state->committeeBalance = self::balance($state->governorStance, array_values($state->memberStances));

        return self::majority($state->committeeBalance);
    }

    /** @param array{name: string, birth: float, stance: float} $person */
    private static function seatCouncillor(PoliticsState $state, int $seat, array $person, float $since): void
    {
        $state->councilNames[$seat] = $person['name'];
        $state->councilBirths[$seat] = $person['birth'];
        $state->councilSince[$seat] = $since;
        $state->councilStances[$seat] = $person['stance'];
    }

    /** @param array{name: string, birth: float, stance: float} $person */
    private static function seatGovernor(PoliticsState $state, array $person, float $termStart): void
    {
        $state->governorName = $person['name'];
        $state->governorBirth = $person['birth'];
        $state->governorTermStart = $termStart;
        $state->governorStance = $person['stance'];
    }

    /** @param array{name: string, birth: float, stance: float} $person */
    private static function seatMember(PoliticsState $state, int $member, array $person, float $since): void
    {
        $state->memberNames[$member] = $person['name'];
        $state->memberBirths[$member] = $person['birth'];
        $state->memberSince[$member] = $since;
        $state->memberStances[$member] = $person['stance'];
    }

    /**
     * Everyone sitting, councillors, governor and members.
     *
     * @return list<string>
     */
    private static function sittingNames(PoliticsState $state): array
    {
        return array_values(array_filter(array_merge($state->councilNames, [$state->governorName], $state->memberNames), static fn(string $name): bool => $name !== ''));
    }

    /** A vacancy's key: the seat and when its term begins, to the microyear. */
    public static function vacancyKey(string $seat, float $since): string
    {
        return $seat . ':' . (int) round($since * 1e6);
    }

    /** A uniform on (0, 1) hashed from the salt and a key. */
    private static function uniform(int $salt, string $key): float
    {
        return (hexdec(substr(hash('sha256', "{$salt}:{$key}"), 0, 13)) + 0.5) / (2 ** 52);
    }
}
