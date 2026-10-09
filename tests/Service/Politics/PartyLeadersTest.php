<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieDiet;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;
use App\Service\Politics\CouncilAppointments;
use App\Service\Politics\PartyLeaders as Leaders;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class PartyLeadersTest extends TestCase
{
    /** A game whose Council has been read, governed by the Vanguard alone, its leaders drawn at the given time. */
    private static function game(float $salt = 4242.0, float $time = 0.0): PoliticsState
    {
        $state = new PoliticsState();
        $state->authoritySalt = $salt;
        $state->totalTime = $time;
        $state->governingCoalition = AerieDiet::membership([AerieDiet::VANGUARD]);
        Leaders::advance($state, 0.0, new MathUtility());

        return $state;
    }

    /**
     * The year after a vote follows the logit fitted on Western Europe: 14.5% at an unchanged vote, 36.6% after a
     * five-point loss, 1.3% for the prime minister's party, and a gain barely moves it.
     */
    public function testTheYearAfterAVoteFollowsTheFittedLogit(): void
    {
        $this->assertEqualsWithDelta(0.145, Leaders::electionExitChance(0.0, false), 0.0005);
        $this->assertEqualsWithDelta(0.366, Leaders::electionExitChance(-5.0, false), 0.0005);
        $this->assertEqualsWithDelta(0.013, Leaders::electionExitChance(0.0, true), 0.0005);
        $this->assertLessThan(Leaders::electionExitChance(0.0, false), Leaders::electionExitChance(5.0, false));
        $this->assertGreaterThan(0.11, Leaders::electionExitChance(5.0, false), 'A gain barely moves it.');
    }

    /**
     * Between votes the hazard is the prime minister's, a junior partner's or an outsider's at the age the records centre
     * on, and rises half again with each decade of the leader's age.
     */
    public function testTheHazardFollowsThePlaceInGovernmentAndTheAge(): void
    {
        $state = self::game(time: 10.0);
        $state->governingCoalition = AerieDiet::membership([AerieDiet::CIVIC, AerieDiet::IRON_HARBOR]);
        foreach (AerieDiet::PARTIES as $party) {
            $state->leaderBirths[$party] = 10.0 - Leaders::AGE_HAZARD_CENTRE;
        }
        $premier = Leaders::primeMinisterParty($state);
        $this->assertSame(AerieDiet::CIVIC, $premier, 'The cabinet\'s largest party leads it.');

        $this->assertEqualsWithDelta(Leaders::MIDTERM_HAZARD_PRIME_MINISTER, Leaders::hazard($state, AerieDiet::CIVIC, $premier), 1e-15);
        $this->assertEqualsWithDelta(Leaders::MIDTERM_HAZARD_CABINET, Leaders::hazard($state, AerieDiet::IRON_HARBOR, $premier), 1e-15);
        $this->assertEqualsWithDelta(Leaders::MIDTERM_HAZARD_OUTSIDE, Leaders::hazard($state, AerieDiet::VANGUARD, $premier), 1e-15);

        $state->leaderBirths[AerieDiet::VANGUARD] -= 10.0;
        $this->assertEqualsWithDelta(1.51, Leaders::hazard($state, AerieDiet::VANGUARD, $premier) / Leaders::MIDTERM_HAZARD_OUTSIDE, 0.005);
    }

    /**
     * Every party has a leader once the Council has been read, none before; each was chosen at an age on the record and
     * has led for a while before the game opens, under a name nobody else holds. The leaders are the game's own.
     */
    public function testEveryPartyHasALeaderOfItsOwnAndTheyRepeatWithTheGame(): void
    {
        $unread = new PoliticsState();
        Leaders::advance($unread, 0.0, new MathUtility());
        $this->assertSame([], $unread->leaderNames, 'No leaders before the game\'s salt is drawn.');

        $state = self::game();
        $this->assertSame(AerieDiet::PARTIES, array_keys($state->leaderNames));
        $this->assertSame(array_values($state->leaderNames), array_values(array_unique($state->leaderNames)));
        $this->assertSame([], array_intersect($state->leaderNames, CouncilAppointments::sittingNames($state)));
        foreach (AerieDiet::PARTIES as $party) {
            $this->assertLessThan(0.0, $state->leaderSince[$party], 'Each has led since before Year 1.');
            $chosenAt = $state->leaderSince[$party] - $state->leaderBirths[$party];
            $this->assertGreaterThanOrEqual(Leaders::SELECTION_AGE_MIN, $chosenAt);
            $this->assertLessThanOrEqual(Leaders::SELECTION_AGE_MAX, $chosenAt);
        }

        $this->assertSame(self::game()->leaderNames, $state->leaderNames);
        $this->assertNotSame(self::game(777.0)->leaderNames, $state->leaderNames);
    }

    /**
     * A vote is weighed only once its cabinet takes office, and only against the leaders who fought it; a leader it
     * dooms goes within the year after it, and the hazard does not run for them meanwhile.
     */
    public function testAVoteIsWeighedOnceItsCabinetTakesOfficeAgainstTheLeadersWhoFoughtIt(): void
    {
        $math = new MathUtility();
        $state = self::game(time: 3.9);
        $state->totalTime = 4.0;
        $state->lastElectionAt = 4.0;
        $state->coalitionTakesOfficeAt = 4.1;
        $state->dietVoteSwings = array_fill_keys(AerieDiet::PARTIES, -0.30);
        Leaders::advance($state, 0.1, $math);
        $this->assertSame(-1.0, $state->leadersReviewedElection, 'Not while the talks run.');

        $state->totalTime = 4.1;
        $state->coalitionTakesOfficeAt = -1.0;
        $state->leaderSince[AerieDiet::CHARTISTS] = 4.05;
        $used = $state->leaderHazardUsed;
        Leaders::advance($state, 0.1, $math);
        $this->assertSame(4.0, $state->leadersReviewedElection);
        $this->assertSame(-1.0, $state->leaderExitAt[AerieDiet::CHARTISTS], 'A leader chosen after the vote is not weighed by it.');
        $this->assertGreaterThan($used[AerieDiet::CHARTISTS], $state->leaderHazardUsed[AerieDiet::CHARTISTS], 'and runs the hazard.');
        foreach (array_diff(AerieDiet::PARTIES, [AerieDiet::CHARTISTS]) as $party) {
            $this->assertSame($used[$party], $state->leaderHazardUsed[$party], 'The year after the vote is its own draw\'s.');
            if ($state->leaderExitAt[$party] >= 0.0) {
                $this->assertGreaterThanOrEqual(4.1, $state->leaderExitAt[$party]);
                $this->assertLessThan(5.0, $state->leaderExitAt[$party]);
            }
        }
        $this->assertGreaterThan(4, count(array_filter($state->leaderExitAt, static fn(float $at): bool => $at >= 0.0)), 'A thirty-point collapse dooms most of them.');
    }

    /**
     * When the prime minister's party changes its leader, the new leader is prime minister and the news is of the
     * premiership; another party's change is a party's news. The leader stepping down joins the party's record.
     */
    public function testANewLeaderOfThePrimeMinistersPartyIsPrimeMinister(): void
    {
        $math = new MathUtility();
        $state = self::game(time: 2.0);
        $premier = $state->leaderNames[AerieDiet::VANGUARD];
        $state->leaderExitAt[AerieDiet::VANGUARD] = 2.0;
        Leaders::advance($state, 0.0, $math);

        $this->assertNotSame($premier, $state->leaderNames[AerieDiet::VANGUARD]);
        $this->assertSame(2.0, $state->leaderSince[AerieDiet::VANGUARD]);
        $this->assertSame($premier, $state->leaderHistory[AerieDiet::VANGUARD][0]['name']);
        $this->assertSame(2.0, $state->leaderHistory[AerieDiet::VANGUARD][0]['until']);
        $this->assertSame(ShockEvent::PRIME_MINISTER_CHANGED, PoliticsEngine::headline($state));

        $state->totalTime = 2.5;
        $state->leaderExitAt[AerieDiet::EXCHANGE] = 2.5;
        Leaders::advance($state, 0.0, $math);
        $this->assertSame(ShockEvent::PARTY_LEADER_CHANGED, PoliticsEngine::headline($state));

        $round = PoliticsState::fromArray(json_decode((string) json_encode($state->toArray()), true));
        $this->assertSame($state->leaderNames, $round->leaderNames);
        $this->assertSame($state->leaderHistory, $round->leaderHistory, 'The record survives the round trip.');
        $this->assertSame($state->leaderSince, $round->leaderSince);
    }

    /**
     * Over many terms the leaders come and go as Western Europe's did: with the record's spread of results (a standard
     * deviation of 4.4 points) and one party in eight leading the cabinet, 18.8% leave within the year after a vote; half
     * have gone after four to six years (4.1 to 6.3 on the records), and a fifth to two fifths last ten.
     */
    public function testALongRunMatchesTheRecord(): void
    {
        $math = new MathUtility();
        $state = self::game(time: 0.0);
        $dt = 1.0 / 12.0;
        $weighed = $left = 0;
        for ($month = 1; $month <= 12 * 1600; ++$month) {
            $state->totalTime = $month * $dt;
            if ($month % 48 === 0) {
                $state->lastElectionAt = $state->totalTime;
                // The record's spread of results: changes of vote with a standard deviation of 4.4 points.
                foreach (AerieDiet::PARTIES as $k => $party) {
                    $state->dietVoteSwings[$party] = 0.044 * Distributions::calculateInverseNormalCDF(CouncilAppointments::uniform(99, "swing:{$month}:{$k}"));
                }
                $fought = array_filter(AerieDiet::PARTIES, static fn(string $party): bool => $state->leaderSince[$party] < $state->totalTime);
                $weighed += count($fought);
                Leaders::advance($state, $dt, $math);
                $left += count(array_filter($fought, static fn(string $party): bool => $state->leaderExitAt[$party] >= 0.0 || $state->leaderSince[$party] === $state->totalTime));
                continue;
            }
            Leaders::advance($state, $dt, $math);
        }

        $tenures = [];
        foreach ($state->leaderHistory as $leaders) {
            foreach ($leaders as $leader) {
                if ($leader['since'] >= 0.0) {
                    $tenures[] = $leader['until'] - $leader['since'];
                }
            }
        }
        sort($tenures);
        $median = $tenures[intdiv(count($tenures), 2)];
        $lastingTen = count(array_filter($tenures, static fn(float $years): bool => $years > 10.0)) / count($tenures);

        $this->assertEqualsWithDelta(0.188, $left / $weighed, 0.02, 'Nearly a fifth leave within the year after a vote.');
        $this->assertGreaterThan(4.0, $median);
        $this->assertLessThan(6.3, $median);
        $this->assertGreaterThan(0.18, $lastingTen);
        $this->assertLessThan(0.39, $lastingTen);
    }
}
