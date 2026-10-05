<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Politics\CouncilAppointments as Appointments;
use App\Service\Politics\FinancialRegulator;
use App\Service\Politics\MonetaryAuthority as Authority;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class MonetaryAuthorityTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    /** One tick as the politics engine runs it: the Council, then the Authority, then the Regulator. */
    private static function tick(PoliticsState $state, float $dt, MathUtility $math): void
    {
        Appointments::advance($state, $math);
        Authority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.03), $dt, $math);
        FinancialRegulator::advance($state, $math);
    }

    /** Advances the Authority tick by tick to a moment. */
    private static function runTo(PoliticsState $state, float $until, float $dt = self::DT): PoliticsState
    {
        $math = new MathUtility();
        while ($state->totalTime + ($dt / 2.0) < $until) {
            $state->totalTime += $dt;
            self::tick($state, $dt, $math);
        }

        return $state;
    }

    private static function opened(int $seed = 7): PoliticsState
    {
        mt_srand($seed);
        $state = new PoliticsState();
        self::tick($state, self::DT, new MathUtility());

        return $state;
    }

    /**
     * Candidates for one vacancy, in the order drawn.
     *
     * @return list<array{name: string, birth: float, stance: float, regulation: float}>
     */
    private static function shortlist(int $salt, string $seat, float $since): array
    {
        $math = new MathUtility();
        $shortlist = [];
        for ($slot = 0; $slot < Appointments::SHORTLIST; ++$slot) {
            $shortlist[] = Appointments::candidate($salt, Appointments::vacancyKey($seat, $since), $slot, $since, [], $math);
        }

        return $shortlist;
    }

    /** The chosen candidate stands nearest the target, and no candidate drawn before it stands as near. */
    private function assertNearestFirstDrawn(array $shortlist, float $chosenStance, float $chosenBirth, float $target): void
    {
        $slot = null;
        foreach ($shortlist as $index => $candidate) {
            if (abs($candidate['birth'] - $chosenBirth) < 1e-9 && $candidate['stance'] === $chosenStance) {
                $slot = $index;
                break;
            }
        }
        $this->assertNotNull($slot, 'The one named was on the shortlist.');
        foreach ($shortlist as $index => $candidate) {
            $gap = abs($candidate['stance'] - $target);
            $this->assertGreaterThanOrEqual(abs($chosenStance - $target), $gap + 1e-12);
            if ($index < $slot) {
                $this->assertGreaterThan(abs($chosenStance - $target), $gap, 'A tie goes to the candidate drawn first.');
            }
        }
    }

    /**
     * At Year 1 everyone was seated before it: the councillors under their own names, the governor whose term began five
     * years earlier, and six members; each with a stance and an age at seating on the record's range; and the
     * committee's balance and supermajority are what the economy reads.
     */
    public function testTheAuthorityAtYearOneWasSeatedBeforeIt(): void
    {
        $state = self::opened();

        $this->assertGreaterThanOrEqual(0.0, $state->authoritySalt);
        $this->assertSame(AerieCouncil::OPENING_MEMBERS, $state->councilNames);
        $people = [];
        foreach (AerieCouncil::roster(0.0) as $seat => $holder) {
            $this->assertEqualsWithDelta($holder['since'], $state->councilSince[$seat], 1e-12);
            $people[] = [$state->councilSince[$seat], $state->councilBirths[$seat], $state->councilStances[$seat]];
        }
        $this->assertSame(AerieCouncil::OPENING_GOVERNOR, $state->governorName);
        $this->assertEqualsWithDelta(Authority::OPENING_GOVERNOR_TERM_END - Authority::GOVERNOR_TERM_YEARS, $state->governorTermStart, 1e-9);
        $this->assertCount(Appointments::SHORTLIST - 1, $state->governorPassedOver);
        $people[] = [$state->governorTermStart, $state->governorBirth, $state->governorStance];
        $this->assertCount(Authority::COMMITTEE_MEMBERS, $state->memberNames);
        foreach ($state->memberSince as $member => $since) {
            $this->assertLessThan(0.0, $since);
            $people[] = [$since, $state->memberBirths[$member], $state->memberStances[$member]];
        }
        foreach ($people as [$since, $birth, $stance]) {
            $this->assertContains($stance, Authority::STANCES);
            $this->assertGreaterThanOrEqual(Appointments::APPOINTMENT_AGE_MIN - 1e-9, $since - $birth);
            $this->assertLessThanOrEqual(Appointments::APPOINTMENT_AGE_MAX + 1e-9, $since - $birth);
        }
        $names = array_merge($state->councilNames, [$state->governorName], $state->memberNames);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $this->assertEqualsWithDelta(Authority::balance($state->governorStance, $state->memberStances), $state->committeeBalance, 1e-12);
        $this->assertSame(Authority::majority($state->committeeBalance), $state->committeeMajority);
        $this->assertSame(-1.0, $state->lastGovernorAppointedAt);
        $this->assertSame(-1.0, $state->lastMajorityShiftAt);
        $this->assertSame($state->committeeMajority, PoliticsStateDTO::fromState($state)->policy()->authorityMajority);
        $this->assertNull((new PoliticsStateDTO())->policy()->authorityMajority, 'No committee is handed over before the Authority has formed.');
    }

    /**
     * The governor serves one term: nothing changes before it ends, and at its end the Council names the candidate
     * nearest its median.
     */
    public function testTheGovernorChangesOnlyWhenTheTermEnds(): void
    {
        $state = self::runTo(self::opened(), Authority::OPENING_GOVERNOR_TERM_END - 0.1);
        $this->assertSame(-1.0, $state->lastGovernorAppointedAt);
        $this->assertSame(AerieCouncil::OPENING_GOVERNOR, $state->governorName);
        $median = Appointments::median($state->councilStances);

        self::runTo($state, Authority::OPENING_GOVERNOR_TERM_END + 0.1);

        $this->assertEqualsWithDelta(Authority::OPENING_GOVERNOR_TERM_END, $state->governorTermStart, 1e-9);
        $this->assertNotSame(AerieCouncil::OPENING_GOVERNOR, $state->governorName);
        $this->assertEqualsWithDelta(Authority::OPENING_GOVERNOR_TERM_END + Authority::GOVERNOR_TERM_YEARS, Authority::governorTermEnd($state->totalTime), 1e-9);
        $this->assertNearestFirstDrawn(self::shortlist((int) $state->authoritySalt, 'governor', Authority::OPENING_GOVERNOR_TERM_END), $state->governorStance, $state->governorBirth, $median);
    }

    /** A committee seat falling vacant goes to the candidate nearest the governor's stance: the governor's committee. */
    public function testACommitteeSeatGoesToTheCandidateNearestTheGovernor(): void
    {
        $state = self::runTo(self::opened(), Authority::memberOpeningTermEnd(0) + self::DT);
        $since = Authority::memberOpeningTermEnd(0);

        $this->assertEqualsWithDelta($since, $state->memberSince[0], 1e-12);
        $this->assertNearestFirstDrawn(self::shortlist((int) $state->authoritySalt, 'member:0', $since), $state->memberStances[0], $state->memberBirths[0], $state->governorStance);
    }

    /**
     * The balance is the committee's stances averaged, the governor's counting as one; it makes a hawkish supermajority
     * at the FOMC's top quarter, a dovish one at its bottom quarter.
     */
    public function testTheBalanceAndItsSupermajorities(): void
    {
        $this->assertEqualsWithDelta(1.0 / 7.0, Authority::balance(1.0, [1.0, 1.0, 0.0, 0.0, -1.0, -1.0]), 1e-12);
        $this->assertSame(1.0, Authority::majority(3.0 / 7.0));
        $this->assertSame(0.0, Authority::majority(2.0 / 7.0));
        $this->assertSame(0.0, Authority::majority(0.0));
        $this->assertSame(-1.0, Authority::majority(-1.0 / 7.0));
    }

    /** The supermajority changes only when someone is seated on the committee or a swing vote on it changes camp, and every change is marked for the headline. */
    public function testAShiftInTheMajorityIsMarkedWhenItHappens(): void
    {
        $state = self::opened(3);
        $dt = 1.0 / Authority::MEETINGS_PER_YEAR;
        $math = new MathUtility();
        $shifts = 0;
        for ($t = $dt; $t < 60.0; $t += $dt) {
            $majority = $state->committeeMajority;
            $members = $state->memberSince;
            $governor = $state->governorTermStart;
            $stances = [$state->governorStance, ...$state->memberStances];
            $state->totalTime = $t;
            self::tick($state, $dt, $math);
            if ($state->committeeMajority !== $majority) {
                ++$shifts;
                $this->assertSame($t, $state->lastMajorityShiftAt);
                $this->assertTrue(
                    $state->memberSince !== $members || $state->governorTermStart !== $governor || $stances !== [$state->governorStance, ...$state->memberStances],
                    'Only an appointment or a change of camp moves the majority.'
                );
            }
        }
        $this->assertGreaterThan(0, $shifts);
    }

    /**
     * Members dissent at the chance their type did on the FOMC: on the FOMC's mix of types the chances average exactly
     * to its 265 and 160 dissents in 6,707 votes, and over many meetings each type dissents at its own.
     */
    public function testMembersDissentAtTheirTypesRates(): void
    {
        $mix = static fn(bool $higher): float => array_sum(array_map(
            static fn(string $type): float => Authority::dissentChance($type, $higher) * Authority::TYPE_COUNTS[$type] / array_sum(Authority::TYPE_COUNTS),
            array_keys(Authority::TYPE_COUNTS)
        ));
        $this->assertEqualsWithDelta(265 / 6707, $mix(true), 1e-12);
        $this->assertEqualsWithDelta(160 / 6707, $mix(false), 1e-12);
        $this->assertEqualsWithDelta(0.0613, Authority::dissentChance('hawk', true), 0.0005);
        $this->assertEqualsWithDelta(0.0475, Authority::dissentChance('dove', false), 0.0005);

        $stances = [1.0, 1.0, 0.0, 0.0, -1.0, -1.0, 1.0];
        $higher = $lower = array_fill(0, 7, 0);
        $meetings = 20000;
        for ($meeting = 0; $meeting < $meetings; ++$meeting) {
            foreach (Authority::votes($stances, $meeting, 77) as $member => $vote) {
                $higher[$member] += $vote > 0.0 ? 1 : 0;
                $lower[$member] += $vote < 0.0 ? 1 : 0;
            }
        }
        foreach ($stances as $member => $stance) {
            $type = Authority::stanceName($stance);
            $this->assertEqualsWithDelta(Authority::dissentChance($type, true), $higher[$member] / $meetings, 0.006, "{$type} for higher");
            $this->assertEqualsWithDelta(Authority::dissentChance($type, false), $lower[$member] / $meetings, 0.006, "{$type} for lower");
        }
        $this->assertSame(Authority::votes($stances, 5, 9), Authority::votes($stances, 5, 9), 'A meeting votes the same whenever it is replayed.');
    }
}
