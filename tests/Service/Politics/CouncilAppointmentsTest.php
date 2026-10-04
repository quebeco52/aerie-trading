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
     * on record on the banks, each as likely; at the Board's ages at appointment (a normal around 52.56, sd 7.32, cut at
     * the youngest and oldest on record); and the same vacancy always draws the same people.
     */
    public function testCandidatesAreDrawnFromTheRecord(): void
    {
        $math = new MathUtility();
        $types = ['hawk' => 0, 'swing' => 0, 'dove' => 0];
        $regimes = array_fill(0, count(FinancialRegulator::OBSERVED_REQUIREMENTS), 0);
        $ages = [];
        $draws = 20000;
        for ($i = 0; $i < $draws; ++$i) {
            $candidate = Appointments::candidate(99, "test:{$i}", 0, 10.0, [], $math);
            ++$types[MonetaryAuthority::stanceName($candidate['stance'])];
            $regime = array_search(round(FinancialRegulator::requirement($candidate['regulation']), 6), array_map(static fn(float $r): float => round($r, 6), FinancialRegulator::OBSERVED_REQUIREMENTS), true);
            $this->assertIsInt($regime, 'Every stance on the banks is a regime on record.');
            ++$regimes[$regime];
            $ages[] = 10.0 - $candidate['birth'];
        }
        foreach (MonetaryAuthority::TYPE_COUNTS as $type => $count) {
            $this->assertEqualsWithDelta($count / array_sum(MonetaryAuthority::TYPE_COUNTS), $types[$type] / $draws, 0.01, $type);
        }
        foreach ($regimes as $regime => $count) {
            $this->assertEqualsWithDelta(1.0 / count($regimes), $count / $draws, 0.006, "regime {$regime}");
        }
        sort($ages);
        $this->assertEqualsWithDelta(Appointments::APPOINTMENT_AGE_MEAN, array_sum($ages) / $draws, 0.2);
        $this->assertEqualsWithDelta(Appointments::APPOINTMENT_AGE_MEAN, $ages[intdiv($draws, 2)], 0.3);
        $this->assertGreaterThanOrEqual(Appointments::APPOINTMENT_AGE_MIN, $ages[0]);
        $this->assertLessThanOrEqual(Appointments::APPOINTMENT_AGE_MAX, $ages[$draws - 1]);
        $this->assertLessThan(0.005, count(array_filter($ages, static fn(float $age): bool => $age < Appointments::APPOINTMENT_AGE_MIN + 0.1)) / $draws, 'The cut does not pile people at the edge.');

        $this->assertSame(Appointments::candidate(5, 'seat', 1, 3.0, [], $math), Appointments::candidate(5, 'seat', 1, 3.0, [], $math));
        $first = Appointments::candidate(5, 'seat', 1, 3.0, [], $math);
        $this->assertNotSame($first['name'], Appointments::candidate(5, 'seat', 1, 3.0, [$first['name']], $math)['name'], 'A name already sitting is drawn again.');
    }

    /**
     * From three candidates the appointer names the one nearest their stances on the questions they weigh, the first drawn
     * of any tied; a dovish appointer weighing money alone names a dove whenever the shortlist holds one,
     * 1 - (82/121)^3 = 69% of the time.
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
                $doves += $named['stance'] === -1.0 ? 1 : 0;
            }
        }

        $doveShare = MonetaryAuthority::TYPE_COUNTS['dove'] / array_sum(MonetaryAuthority::TYPE_COUNTS);
        $this->assertEqualsWithDelta(1.0 - ((1.0 - $doveShare) ** Appointments::SHORTLIST), $doves / ($vacancies / 4), 0.03);
    }

    /**
     * At Year 1 every councillor was seated before it, under their own name, with a stance on money and one on the banks;
     * a vacant seat later goes to the candidate nearest the sitting twelve's medians on both, and the two passed over are
     * kept.
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
        $sitting = $state->councilNames;
        self::runTo($state, AerieCouncil::openingTermEnd(0) + self::DT);

        $since = AerieCouncil::openingTermEnd(0);
        $this->assertEqualsWithDelta($since, $state->councilSince[0], 1e-12);
        $this->assertNotSame(AerieCouncil::OPENING_MEMBERS[0], $state->councilNames[0]);
        $this->assertCount(Appointments::SHORTLIST - 1, $state->councillorPassedOver);
        [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'council:0', $since, $medians, $sitting, new MathUtility());
        $this->assertSame($expected['name'], $state->councilNames[0]);
        $this->assertSame($expected['regulation'], $state->councilRegulationStances[0]);
        $this->assertSame($passedOver, $state->councillorPassedOver);
        $this->assertGreaterThan($since - 0.1, $state->lastCouncillorSeatedAt);
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
}
