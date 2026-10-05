<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieCouncil;
use App\Service\Math\MathUtility;
use App\Service\Politics\CouncilAppointments as Appointments;
use App\Service\Politics\FinancialRegulator;
use App\Service\Politics\MonetaryAuthority;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class CouncilAppointmentsTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    /** A Council opened at Year 1 on a seed. */
    private static function opened(int $seed = 7): PoliticsState
    {
        mt_srand($seed);
        $state = new PoliticsState();
        Appointments::advance($state, new MathUtility());

        return $state;
    }

    /** Advances the Council tick by tick to a moment. */
    private static function runTo(PoliticsState $state, float $until): PoliticsState
    {
        $math = new MathUtility();
        while ($state->totalTime + (self::DT / 2.0) < $until) {
            $state->totalTime += self::DT;
            Appointments::advance($state, $math);
        }

        return $state;
    }

    /**
     * Candidates come in the FOMC's mix on money, 51 hawks, 31 swing votes and 39 doves in 121; as one of the 17 regimes
     * on record on the banks, each as likely; at the Board's ages at appointment (a normal around 52.56, sd 7.32), cut at
     * the charter's window of 40 to 60; a swing vote leaning to either camp alike; and the same vacancy always draws the
     * same people.
     */
    public function testCandidatesAreDrawnFromTheRecord(): void
    {
        $math = new MathUtility();
        $types = ['hawk' => 0, 'swing' => 0, 'dove' => 0];
        $swingHawks = 0;
        $regimes = array_fill(0, count(FinancialRegulator::OBSERVED_REQUIREMENTS), 0);
        $ages = [];
        $draws = 20000;
        for ($i = 0; $i < $draws; ++$i) {
            $candidate = Appointments::candidate(99, "test:{$i}", 0, 10.0, [], $math);
            ++$types[MonetaryAuthority::typeName($candidate['stance'], $candidate['swing'])];
            $this->assertContains($candidate['stance'], [1.0, -1.0], 'Everyone sits in a camp, a swing vote in the one they lean to.');
            $swingHawks += $candidate['swing'] > 0.0 && $candidate['stance'] > 0.0 ? 1 : 0;
            $regime = array_search(round(FinancialRegulator::requirement($candidate['regulation']), 6), array_map(static fn(float $r): float => round($r, 6), FinancialRegulator::OBSERVED_REQUIREMENTS), true);
            $this->assertIsInt($regime, 'Every stance on the banks is a regime on record.');
            ++$regimes[$regime];
            $ages[] = 10.0 - $candidate['birth'];
        }
        foreach (MonetaryAuthority::TYPE_COUNTS as $type => $count) {
            $this->assertEqualsWithDelta($count / array_sum(MonetaryAuthority::TYPE_COUNTS), $types[$type] / $draws, 0.01, $type);
        }
        $this->assertEqualsWithDelta(0.5, $swingHawks / $types['swing'], 0.02, 'A swing vote leans either way alike.');
        foreach ($regimes as $regime => $count) {
            $this->assertEqualsWithDelta(1.0 / count($regimes), $count / $draws, 0.006, "regime {$regime}");
        }
        sort($ages);
        // The Board's normal cut at the charter's window: its mean and median move to the truncated normal's.
        $a = (Appointments::APPOINTMENT_AGE_MIN - Appointments::APPOINTMENT_AGE_MEAN) / Appointments::APPOINTMENT_AGE_SD;
        $b = (Appointments::APPOINTMENT_AGE_MAX - Appointments::APPOINTMENT_AGE_MEAN) / Appointments::APPOINTMENT_AGE_SD;
        $pdf = static fn(float $z): float => exp(-0.5 * $z * $z) / sqrt(2.0 * M_PI);
        $mass = $math->calculateNormalCDF($b) - $math->calculateNormalCDF($a);
        $mean = Appointments::APPOINTMENT_AGE_MEAN + (Appointments::APPOINTMENT_AGE_SD * ($pdf($a) - $pdf($b)) / $mass);
        $median = Appointments::APPOINTMENT_AGE_MEAN + (Appointments::APPOINTMENT_AGE_SD * $math->calculateInverseNormalCDF($math->calculateNormalCDF($a) + (0.5 * $mass)));
        $this->assertEqualsWithDelta(51.2, $mean, 0.05, 'Cutting at 40 and 60 takes about a year and a half off the Board\'s mean.');
        $this->assertEqualsWithDelta($mean, array_sum($ages) / $draws, 0.2);
        $this->assertEqualsWithDelta($median, $ages[intdiv($draws, 2)], 0.3);
        $this->assertGreaterThanOrEqual(Appointments::APPOINTMENT_AGE_MIN, $ages[0]);
        $this->assertLessThanOrEqual(Appointments::APPOINTMENT_AGE_MAX, $ages[$draws - 1]);
        $this->assertLessThan(0.005, count(array_filter($ages, static fn(float $age): bool => $age < Appointments::APPOINTMENT_AGE_MIN + 0.1)) / $draws, 'The cut does not pile people at the edge.');

        $this->assertSame(Appointments::candidate(5, 'seat', 1, 3.0, [], $math), Appointments::candidate(5, 'seat', 1, 3.0, [], $math));
        $first = Appointments::candidate(5, 'seat', 1, 3.0, [], $math);
        $this->assertNotSame($first['name'], Appointments::candidate(5, 'seat', 1, 3.0, [$first['name']], $math)['name'], 'A name already sitting is drawn again.');
    }

    /**
     * From three candidates the appointer names the one nearest their stances on the questions they weigh, the first drawn
     * of any tied; a dovish appointer weighing money alone names someone leaning dovish whenever the shortlist holds one,
     * a dove or a swing vote leaning their way, and a dove (39 of the 54.5 leaning dovish in 121) 60% of the time.
     */
    public function testTheAppointerNamesTheNearestCandidate(): void
    {
        $math = new MathUtility();
        $doves = 0;
        $vacancies = 4000;
        for ($i = 0; $i < $vacancies; ++$i) {
            $target = match ($i % 4) {
                0 => [Appointments::AXIS_MONEY => 1.0],
                1 => [Appointments::AXIS_REGULATION => 0.3],
                2 => [Appointments::AXIS_MONEY => 0.0, Appointments::AXIS_REGULATION => -0.5],
                default => [Appointments::AXIS_MONEY => -1.0],
            };
            [$named, $passedOver] = Appointments::appoint(11, "test:{$i}", 2.0, $target, [], $math);
            $this->assertCount(Appointments::SHORTLIST - 1, $passedOver);

            $distance = static fn(array $candidate): float => sqrt(array_sum(array_map(
                static fn(string $axis, float $stance): float => ($candidate[$axis === Appointments::AXIS_MONEY ? 'stance' : $axis] - $stance) ** 2,
                array_keys($target),
                $target
            )));
            $shortlist = [];
            for ($slot = 0; $slot < Appointments::SHORTLIST; ++$slot) {
                $shortlist[] = Appointments::candidate(11, Appointments::vacancyKey("test:{$i}", 2.0), $slot, 2.0, array_column($shortlist, 'name'), $math);
            }
            $chosen = array_search($named, $shortlist, true);
            $this->assertIsInt($chosen, 'The one named was on the shortlist.');
            foreach ($shortlist as $slot => $candidate) {
                $this->assertGreaterThanOrEqual($distance($named), $distance($candidate) + 1e-12);
                if ($slot < $chosen) {
                    $this->assertGreaterThan($distance($named), $distance($candidate), 'A tie goes to the candidate drawn first.');
                }
            }
            if ($i % 4 === 3) {
                $doves += $named['stance'] === -1.0 && $named['swing'] === 0.0 ? 1 : 0;
            }
        }

        $doveShare = MonetaryAuthority::TYPE_COUNTS['dove'] / array_sum(MonetaryAuthority::TYPE_COUNTS);
        $leaningDovish = $doveShare + (0.5 * MonetaryAuthority::TYPE_COUNTS['swing'] / array_sum(MonetaryAuthority::TYPE_COUNTS));
        $this->assertEqualsWithDelta((1.0 - ((1.0 - $leaningDovish) ** Appointments::SHORTLIST)) * $doveShare / $leaningDovish, $doves / ($vacancies / 4), 0.03);
    }

    /**
     * At Year 1 every councillor was seated before it, under their own name, with a stance on money and one on the banks;
     * a vacant seat later goes to the candidate nearest the sitting twelve's medians on both, from the shortlist the
     * Council Appointment Board puts forward, and the two passed over are kept.
     */
    public function testAVacantCouncilSeatGoesToTheCandidateNearestTheSittingMedians(): void
    {
        $this->assertSame(AerieCouncil::OPENING_MEMBERS, self::opened()->councilNames);
        $state = self::opened();
        $this->assertCount(AerieCouncil::SEATS, $state->councilRegulationStances);
        foreach ($state->councilRegulationStances as $stance) {
            $this->assertGreaterThanOrEqual(-1.0 - 1e-12, $stance);
            $this->assertLessThanOrEqual(1.0 + 1e-12, $stance);
        }

        self::runTo($state, AerieCouncil::openingTermEnd(0) - self::DT);
        $medians = Appointments::councilMedians($state, 0);
        $board = Appointments::boardMedians($state);
        $reserved = Appointments::reservedNames($state);
        self::runTo($state, AerieCouncil::openingTermEnd(0) + self::DT);

        $since = AerieCouncil::openingTermEnd(0);
        $this->assertEqualsWithDelta($since, $state->councilSince[0], 1e-12);
        $this->assertNotSame(AerieCouncil::OPENING_MEMBERS[0], $state->councilNames[0]);
        $this->assertCount(Appointments::SHORTLIST - 1, $state->councillorPassedOver);
        $shortlist = Appointments::boardShortlist((int) $state->authoritySalt, 'council:0', $since, $board, $reserved, new MathUtility());
        [$expected, $passedOver] = Appointments::choose($shortlist, $medians);
        $this->assertSame($expected['name'], $state->councilNames[0]);
        $this->assertSame($expected['regulation'], $state->councilRegulationStances[0]);
        $this->assertSame($passedOver, $state->councillorPassedOver);
        $this->assertGreaterThan($since - 0.1, $state->lastCouncillorSeatedAt);
    }

    /**
     * A councillor who leaves keeps their name: it joins the former holders, and no later appointee or party leader may
     * take it, nor the name of anyone sitting, any party's leader past or present, or anyone seated before Year 1.
     */
    public function testANameOnceHeldIsNeverDrawnAgain(): void
    {
        $state = self::opened();
        $this->assertSame([], $state->formerNames);
        $state->leaderNames = ['civic' => 'Carol Ward'];
        $state->leaderHistory = ['civic' => [['name' => 'Gary Fields', 'birth' => -60.0, 'since' => -9.0, 'until' => -1.0]]];

        $state = self::runTo($state, AerieCouncil::openingTermEnd(0) + self::DT);

        $this->assertContains(AerieCouncil::OPENING_MEMBERS[0], $state->formerNames);
        $this->assertSame(array_values(array_unique($state->formerNames)), $state->formerNames);
        $reserved = Appointments::reservedNames($state);
        foreach ([...$state->councilNames, ...$state->formerNames, ...array_column($state->boardMembers, 'name'), AerieCouncil::OPENING_GOVERNOR, AerieCouncil::OPENING_REGULATOR, AerieCouncil::OPENING_FUND_HEAD, 'Carol Ward', 'Gary Fields'] as $name) {
            $this->assertContains($name, $reserved);
        }

        $former = $state->formerNames;
        Appointments::retire($state, AerieCouncil::OPENING_MEMBERS[0]);
        Appointments::retire($state, '');
        $this->assertSame($former, $state->formerNames, 'Each name kept once; an empty post retires no one.');
    }

    /**
     * A running game whose councillors were seated before the Council answered the question of the banks keeps every
     * councillor and their stance on money, and gains a stance on the banks for each, drawn once.
     */
    public function testCouncillorsSeatedBeforeTheBanksQuestionGainAStanceOnIt(): void
    {
        $state = self::opened(3);
        $names = $state->councilNames;
        $money = $state->councilStances;
        $drawn = $state->councilRegulationStances;
        $state->councilRegulationStances = [];

        $state->totalTime += self::DT;
        Appointments::advance($state, new MathUtility());

        $this->assertSame($names, $state->councilNames);
        $this->assertSame($money, $state->councilStances);
        $this->assertCount(AerieCouncil::SEATS, $state->councilRegulationStances);
        $this->assertSame(range(0, AerieCouncil::SEATS - 1), array_keys($state->councilRegulationStances));
        $this->assertNotSame($drawn, $state->councilRegulationStances, 'The catch-up draw is keyed apart from the candidates\' own.');

        $again = $state->councilRegulationStances;
        $state->totalTime += self::DT;
        Appointments::advance($state, new MathUtility());
        $this->assertSame($again, $state->councilRegulationStances, 'It is drawn once.');
    }

    /**
     * A Council seated on an earlier term length keeps every councillor when the charter's term changes: their seats are
     * dated on the new schedule, no seat is filled for it, and the seats then fall vacant on the new term's calendar.
     */
    public function testATermChangeKeepsTheSittingCouncillors(): void
    {
        $state = self::runTo(self::opened(9), 30.0);
        $names = $state->councilNames;
        $stances = $state->councilStances;
        $seatedAt = $state->lastCouncillorSeatedAt;
        $state->councilTermYears = 0.0;
        $state->councilSince = array_map(static fn(float $since): float => $since - 3.7, $state->councilSince);

        self::runTo($state, 30.0 + self::DT);

        $this->assertSame(AerieCouncil::TERM_YEARS, $state->councilTermYears);
        $this->assertSame($names, $state->councilNames, 'The same people sit.');
        $this->assertSame($stances, $state->councilStances);
        $this->assertSame($seatedAt, $state->lastCouncillorSeatedAt, 'The change itself fills no seat.');
        foreach (AerieCouncil::roster($state->totalTime) as $seat => $holder) {
            $this->assertEqualsWithDelta($holder['since'], $state->councilSince[$seat], 1e-12);
        }

        $next = AerieCouncil::nextVacancy($state->totalTime);
        self::runTo($state, $next['termEnds'] + self::DT);
        $this->assertNotSame($names[$next['seat'] - 1], $state->councilNames[$next['seat'] - 1], 'The next seat falls vacant on the new calendar.');
        $this->assertEqualsWithDelta(AerieCouncil::TERM_YEARS / AerieCouncil::SEATS, AerieCouncil::nextVacancy($state->totalTime)['termEnds'] - $next['termEnds'], 1e-9, 'A seat falls vacant every twelve thirteenths of a year.');
    }

    /**
     * A councillor who dies or resigns mid-term is succeeded at once by a councillor who serves the rest of the same
     * term: the seat's term start and end stay on the schedule, the successor is dated to the vacancy, and the one who
     * left is never drawn again.
     */
    public function testAMidTermSuccessorServesOutTheTerm(): void
    {
        $state = self::opened(11);
        $math = new MathUtility();
        $vacancies = 0;
        while ($state->totalTime < 60.0) {
            $names = $state->councilNames;
            $since = $state->councilSince;
            $state->totalTime += self::DT;
            Appointments::advance($state, $math);
            foreach ($state->councilNames as $seat => $name) {
                if ($name === $names[$seat] || abs($state->councilSince[$seat] - $since[$seat]) > 1e-9) {
                    continue;
                }
                ++$vacancies;
                $this->assertSame($state->totalTime, $state->lastCouncilVacancyAt);
                $this->assertContains($state->lastVacancyName, array_diff($names, $state->councilNames), 'the last of this tick\'s vacancies');
                $this->assertContains($state->lastVacancyCause, ['died', 'resigned']);
                $this->assertContains($names[$seat], $state->formerNames);
                $this->assertGreaterThan($state->councilSince[$seat], $state->councilSeatedAt[$seat]);
                $this->assertLessThanOrEqual($state->totalTime, $state->councilSeatedAt[$seat]);
                $this->assertGreaterThan($state->totalTime - self::DT, $state->councilSeatedAt[$seat], 'filled the tick the seat fell vacant');
                $termEnds = AerieCouncil::roster($state->totalTime)[$seat]['termEnds'];
                $this->assertEqualsWithDelta($state->councilSince[$seat] + AerieCouncil::TERM_YEARS, $termEnds, 1e-9);
                $leaves = $state->councilLeavesAt[$seat];
                $this->assertTrue($leaves === -1.0 || ($leaves > $state->councilSeatedAt[$seat] && $leaves < $termEnds), 'the successor leaves early only within the term');
            }
        }
        $this->assertGreaterThan(10, $vacancies, 'About 0.45 a year: 41% of twelve-year terms end early.');
    }

    /**
     * The share of terms cut short is the Gompertz-Makeham hazard's: someone seated at 51 for twelve years leaves early
     * with probability 1 - exp(-H), H the hazard summed over the term.
     */
    public function testTheShareOfTermsCutShortFollowsTheHazard(): void
    {
        $age = 51.0;
        $term = AerieCouncil::TERM_YEARS;
        $level = Appointments::MORTALITY_LEVEL * exp(Appointments::MORTALITY_SLOPE * $age);
        $expected = 1.0 - exp(-(Appointments::RESIGNATION_RATE * $term) - ($level * (exp(Appointments::MORTALITY_SLOPE * $term) - 1.0) / Appointments::MORTALITY_SLOPE));

        $draws = 20000;
        $early = 0;
        for ($salt = 1; $salt <= $draws; ++$salt) {
            $leaves = Appointments::departure($salt, 3, 10.0, 10.0 - $age, 10.0, 10.0 + $term);
            if ($leaves >= 0.0) {
                ++$early;
                $this->assertGreaterThan(10.0, $leaves);
                $this->assertLessThan(10.0 + $term, $leaves);
            }
        }
        $this->assertEqualsWithDelta($expected, $early / $draws, 4.0 * sqrt($expected * (1.0 - $expected) / $draws));
    }

    /** Departures are drawn once at seating, so the Council's history is the same at any tick rate. */
    public function testVacanciesDoNotDependOnTheTickRate(): void
    {
        $histories = [];
        foreach ([1.0 / 52.0, 1.0 / 12.0] as $dt) {
            $state = self::opened(5);
            $math = new MathUtility();
            $history = [];
            while ($state->totalTime + ($dt / 2.0) < 40.0) {
                $state->totalTime += $dt;
                Appointments::advance($state, $math);
                foreach ($state->councilNames as $seat => $name) {
                    $history["{$seat}:{$name}"] = round($state->councilSeatedAt[$seat], 9);
                }
            }
            ksort($history);
            $histories[] = $history;
        }
        $this->assertSame($histories[0], $histories[1]);
    }

    /** A Council read from a payload that predates mid-term vacancies keeps its councillors and gains their departures. */
    public function testCouncillorsSeatedBeforeVacanciesGainADeparture(): void
    {
        $state = self::runTo(self::opened(3), 20.0);
        $names = $state->councilNames;
        $state->councilSeatedAt = [];
        $state->councilLeavesAt = [];
        Appointments::advance($state, new MathUtility());

        $this->assertSame($names, $state->councilNames);
        $this->assertSame(array_keys($names), array_keys($state->councilSeatedAt));
        foreach ($state->councilLeavesAt as $seat => $leaves) {
            $this->assertEqualsWithDelta($state->councilSince[$seat], $state->councilSeatedAt[$seat], 1e-12);
            $this->assertTrue($leaves === -1.0 || $leaves >= $state->totalTime, 'nobody is drawn to have left before now');
        }
    }

    /**
     * The Council Appointment Board puts forward the three of its fourteen applicants nearest its own median on every
     * question, the first drawn of any tied, in the order they applied; the same vacancy always gets the same list.
     */
    public function testTheBoardPutsForwardTheApplicantsNearestItsMedian(): void
    {
        $math = new MathUtility();
        $distance = static fn(array $candidate, array $target): float => sqrt(
            (($candidate['stance'] - $target[Appointments::AXIS_MONEY]) ** 2)
            + (($candidate['regulation'] - $target[Appointments::AXIS_REGULATION]) ** 2)
            + (($candidate['fund'] - $target[Appointments::AXIS_FUND]) ** 2)
        );
        for ($i = 0; $i < 200; ++$i) {
            $target = [Appointments::AXIS_MONEY => $i % 2 === 0 ? 1.0 : -1.0, Appointments::AXIS_REGULATION => 0.2, Appointments::AXIS_FUND => -0.3];
            $shortlist = Appointments::boardShortlist(21, "council:{$i}", 7.0, $target, [], $math);
            $this->assertCount(Appointments::SHORTLIST, $shortlist);

            $field = [];
            for ($slot = 0; $slot < Appointments::BOARD_FIELD; ++$slot) {
                $field[] = Appointments::candidate(21, Appointments::vacancyKey("council:{$i}", 7.0), $slot, 7.0, array_column($field, 'name'), $math);
            }
            $slots = array_map(static fn(array $kept): int => (int) array_search($kept, $field, true), $shortlist);
            $this->assertSame($slots, array_values(array_unique($slots)));
            $this->assertSame($slots, (static function (array $sorted): array { sort($sorted); return $sorted; })($slots), 'kept in the order they applied');
            $worstKept = max(array_map(static fn(array $kept): float => $distance($kept, $target), $shortlist));
            foreach ($field as $slot => $applicant) {
                if (!in_array($slot, $slots, true)) {
                    $this->assertGreaterThanOrEqual($worstKept - 1e-9, $distance($applicant, $target), 'No one left off stands nearer the board than anyone put forward.');
                }
            }
        }
        $this->assertSame(Appointments::boardShortlist(3, 'council:1', 2.0, [Appointments::AXIS_MONEY => 1.0], [], $math), Appointments::boardShortlist(3, 'council:1', 2.0, [Appointments::AXIS_MONEY => 1.0], [], $math));
    }

    /**
     * The board's seven seats turn over on their own five-year schedule, staggered, whoever sits on the Council; and a
     * game read from a payload that predates the board gains one without touching the Council.
     */
    public function testTheBoardIsNamedOnItsOwnSchedule(): void
    {
        $state = self::runTo(self::opened(8), 23.0);
        $this->assertCount(Appointments::BOARD_SEATS, $state->boardMembers);
        $this->assertSame(Appointments::boardTermStarts($state->totalTime), $state->boardSince);
        $starts = Appointments::boardTermStarts(23.0);
        sort($starts);
        $this->assertEqualsWithDelta(Appointments::BOARD_TERM_YEARS / Appointments::BOARD_SEATS, $starts[1] - $starts[0], 1e-9, 'one seat every five-sevenths of a year');
        foreach ($state->boardMembers as $member) {
            $this->assertNotContains($member['name'], $state->councilNames);
        }

        $names = $state->councilNames;
        $state->boardMembers = [];
        $state->boardSince = [];
        $state->totalTime += self::DT;
        Appointments::advance($state, new MathUtility());
        $this->assertCount(Appointments::BOARD_SEATS, $state->boardMembers);
        $this->assertSame($names, $state->councilNames);
    }
}
