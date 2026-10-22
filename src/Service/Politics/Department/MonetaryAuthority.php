<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\TimeSeries;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Council\StanceRevision;
use App\Service\Politics\PoliticsState;

/**
 * The Monetary Authority: a governor the Council appoints for one fixed term, and the rate committee the governor
 * picks, who set the policy rate by the Authority's published rule. Each holds a stance on money: hawk, dove, or a
 * swing vote leaning to one camp at a time (StanceRevision), in the FOMC's mix (Bordo & Istrefi 2023). The governor
 * is the shortlisted candidate nearest the Council's median, a member the one nearest the governor. The committee's
 * balance makes the supermajority the economy reads; a member dissents when their camp's rate stands two steps from
 * the committee's, so only once inflation or slack opens the camps apart.
 */
final class MonetaryAuthority
{
    // --- Stances ---
    /** Each type's stance: +1 hawk, -1 dove, a swing vote scored in their camp; 0 marks one before a lean is drawn. */
    public const STANCES = ['hawk' => 1.0, 'swing' => 0.0, 'dove' => -1.0];
    /** FOMC members of each type the press placed, the mix candidates are drawn from (Bordo & Istrefi 2023). */
    public const TYPE_COUNTS = ['hawk' => 51, 'swing' => 31, 'dove' => 39];

    // --- The Rate Committee ---
    /** Members of the rate committee beside the governor. */
    public const COMMITTEE_MEMBERS = 6;
    /** Length of a member's term in years, staggered so a seat falls vacant once a year. */
    public const MEMBER_TERM_YEARS = 6.0;
    /** Rate meetings a year, the Authority's calendar (MonetaryPolicySubsystem::updatePolicyRate()). */
    public const MEETINGS_PER_YEAR = MonetaryPolicySubsystem::MEETINGS_PER_YEAR;

    // --- The Committee's Balance ---
    /** Seats of the seven one camp must hold to lean the rule its way: six, so a 5-2 committee follows the rule. */
    public const SUPERMAJORITY_SEATS = 6;
    /** Average stance at or above which the committee holds a hawkish supermajority: six hawks of seven, 5/7. */
    public const HAWKISH_MAJORITY_STANCE = ((2 * self::SUPERMAJORITY_SEATS) - (self::COMMITTEE_MEMBERS + 1)) / (self::COMMITTEE_MEMBERS + 1);
    /** Average stance at or below which it holds a dovish supermajority: six doves of seven, -5/7. */
    public const DOVISH_MAJORITY_STANCE = -self::HAWKISH_MAJORITY_STANCE;

    // --- Dissent ---
    /**
     * How far a member's camp must set the rate from the committee's before they dissent: two steps, 50bp, so a
     * committee splits a few times a year.
     */
    public const DISSENT_THRESHOLD = 2 * MonetaryPolicySubsystem::POLICY_RATE_STEP;

    // --- News ---
    /** A rate move that makes news on its own, a meeting's standard step: 25bp. */
    public const NEWSWORTHY_RATE_MOVE = 0.0025;

    /**
     * One tick, after CouncilAppointments::advance(): the governor and committee seated on the first read of a state
     * without them, vacated seats refilled, the governor first; the balance and supermajority when it changed; and a
     * meeting's votes when one falls due.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already set to this tick's.
     * @param MacroStateDTO $macro The economy as this tick left it.
     * @param float         $dt    Time increment in years.
     */
    public static function advance(PoliticsState $state, MacroStateDTO $macro, float $dt): void
    {
        $time = $state->totalTime;
        if ($state->head(Office::Governor)['name'] === '') {
            self::open($state, $time);
        }
        $salt = (int) $state->authoritySalt;

        $changed = StanceRevision::revise($state, $dt);
        $changed = self::seatGovernor($state) || $changed;

        for ($member = 0; $member < self::COMMITTEE_MEMBERS; ++$member) {
            $since = CouncilAppointments::termStart($time, self::memberOpeningTermEnd($member), self::MEMBER_TERM_YEARS);
            if (abs(($state->memberSince[$member] ?? -INF) - $since) > 1e-9) {
                [$chosen] = CouncilAppointments::appoint($salt, "member:{$member}", $since, [CouncilAppointments::AXIS_MONEY => $state->head(Office::Governor)['stance']], CouncilAppointments::reservedNames($state));
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

        if (TimeSeries::crossedSimulatedBoundary($time, $dt, 1.0 / self::MEETINGS_PER_YEAR)) {
            self::meet($state, $macro);
        }
    }

    /**
     * The Authority at Year 1, or when a state that predates it is first read: the governor and committee chosen from
     * shortlists, the governor sitting at Year 1 under their own name.
     */
    private static function open(PoliticsState $state, float $time): void
    {
        $salt = (int) $state->authoritySalt;
        $state->memberNames = $state->memberBirths = $state->memberSince = $state->memberStances = $state->memberSwingers = [];
        self::seatGovernor($state);

        for ($member = 0; $member < self::COMMITTEE_MEMBERS; ++$member) {
            $since = CouncilAppointments::termStart($time, self::memberOpeningTermEnd($member), self::MEMBER_TERM_YEARS);
            [$chosen] = CouncilAppointments::appoint($salt, "member:{$member}", $since, [CouncilAppointments::AXIS_MONEY => $state->head(Office::Governor)['stance']], CouncilAppointments::reservedNames($state));
            self::seatMember($state, $member, $chosen, $since);
        }
        $state->committeeMajority = self::rebalance($state);
    }

    /** A stance on money drawn from a uniform: hawk, swing vote or dove in the FOMC's mix (TYPE_COUNTS). */
    public static function drawStance(float $uniform): float
    {
        $draw = $uniform * array_sum(self::TYPE_COUNTS);
        foreach (self::TYPE_COUNTS as $type => $count) {
            if ($draw < $count) {
                return self::STANCES[$type];
            }
            $draw -= $count;
        }

        return self::STANCES['dove'];
    }

    /** A swing vote's camp when appointed, from a uniform: either, alike. */
    public static function swingLean(float $uniform): float
    {
        return $uniform < 0.5 ? self::STANCES['hawk'] : self::STANCES['dove'];
    }

    /**
     * Someone's type on money: 'swing' for a swing vote, whichever camp they lean to, else their camp.
     *
     * @return 'hawk'|'swing'|'dove'
     */
    public static function typeName(float $stance, float $swing): string
    {
        return $swing > 0.0 ? 'swing' : self::stanceName($stance);
    }

    /**
     * The committee's balance: its members' stances averaged, the governor counting as one (Bordo & Istrefi's HD0.5).
     *
     * @param list<float> $memberStances
     */
    public static function balance(float $governorStance, array $memberStances): float
    {
        return ($governorStance + array_sum($memberStances)) / (1 + count($memberStances));
    }

    /** The supermajority a balance makes: 1 hawkish, -1 dovish, 0 neither. */
    public static function majority(float $balance): float
    {
        return $balance >= self::HAWKISH_MAJORITY_STANCE ? 1.0 : ($balance <= self::DOVISH_MAJORITY_STANCE ? -1.0 : 0.0);
    }

    /**
     * Each vote at a meeting on the governor's decision: the governor's for it; every other member's for it, or a
     * dissent toward their camp's rate when it stands more than DISSENT_THRESHOLD from the committee's.
     *
     * @param list<float> $stances      The members' stances, the governor first.
     * @param float       $majority     The committee's supermajority: 1 hawkish, -1 dovish, 0 neither.
     * @param float       $inflationGap The rule's inflation over target at the meeting.
     * @param float       $gap          The rule's output gap at the meeting.
     * @return list<float> 1 for a higher rate, -1 for a lower, 0 with the decision.
     */
    public static function votes(array $stances, float $majority, float $inflationGap, float $gap): array
    {
        $committee = MonetaryPolicySubsystem::campTilt($majority, $inflationGap, $gap);
        $votes = [];
        foreach ($stances as $member => $stance) {
            $votes[] = $member === 0 ? 0.0 : self::vote(MonetaryPolicySubsystem::campTilt($stance, $inflationGap, $gap) - $committee);
        }

        return $votes;
    }

    /** A member's vote: a dissent toward their camp past DISSENT_THRESHOLD from the committee's rate, else 0. */
    public static function vote(float $gap): float
    {
        return $gap > self::DISSENT_THRESHOLD ? 1.0 : ($gap < -self::DISSENT_THRESHOLD ? -1.0 : 0.0);
    }

    /**
     * The type a stance belongs to.
     *
     * @return 'hawk'|'swing'|'dove'
     */
    public static function stanceName(float $stance): string
    {
        return $stance >= 0.5 ? 'hawk' : ($stance <= -0.5 ? 'dove' : 'swing');
    }

    /**
     * When the term of committee seat m's member sitting at Year 1 ends: the seats fall vacant a year apart, half a
     * year off Year 1, so all were seated before it.
     */
    public static function memberOpeningTermEnd(int $member): float
    {
        return ($member + 0.5) * self::MEMBER_TERM_YEARS / self::COMMITTEE_MEMBERS;
    }

    /** Whether the last meeting, if it sat this tick, moved the rate a full step or split the committee. */
    public static function meetingMadeNews(PoliticsState|PoliticsStateDTO $state): bool
    {
        return $state->lastMeetingAt === $state->totalTime
            && (abs($state->lastMeetingChange) >= self::NEWSWORTHY_RATE_MOVE || array_filter($state->lastMeetingVotes, static fn(float $vote): bool => $vote !== 0.0) !== []);
    }

    /**
     * A meeting: the rate it leaves, and the votes on it, cast against the supermajority the economy set that rate
     * with, not one this tick's appointments have since made.
     */
    private static function meet(PoliticsState $state, MacroStateDTO $macro): void
    {
        $state->lastMeetingChange = $state->lastMeetingAt < 0.0 ? 0.0 : $macro->policyRate - $state->lastMeetingRate;
        $state->lastMeetingVotes = self::votes(
            array_merge([$state->head(Office::Governor)['stance']], array_values($state->memberStances)),
            $macro->authorityCommitteeSeated > 0.0 ? $macro->authorityMajority : 0.0,
            $macro->ruleInflationGap,
            $macro->ruleOutputGap,
        );
        $state->lastMeetingRate = $macro->policyRate;
        $state->lastMeetingAt = $state->totalTime;
    }

    /** The committee's balance refreshed, and the supermajority it now makes. */
    private static function rebalance(PoliticsState $state): float
    {
        $state->committeeBalance = self::balance($state->head(Office::Governor)['stance'], array_values($state->memberStances));

        return self::majority($state->committeeBalance);
    }

    /** Seats a governor when the post is vacant or the term has ended (Office::fill()); whether one was. */
    private static function seatGovernor(PoliticsState $state): bool
    {
        $seated = Office::Governor->fill($state);
        if ($seated === null) {
            return false;
        }
        $state->governorSwinger = $seated[0]['swing'] ?? 0.0;

        return true;
    }

    /**
     * @param array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float} $person
     */
    private static function seatMember(PoliticsState $state, int $member, array $person, float $since): void
    {
        CouncilAppointments::retire($state, $state->memberNames[$member] ?? '');
        $state->memberNames[$member] = $person['name'];
        $state->memberBirths[$member] = $person['birth'];
        $state->memberSince[$member] = $since;
        $state->memberStances[$member] = $person['stance'];
        $state->memberSwingers[$member] = $person['swing'] ?? 0.0;
    }
}
