<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieDiet as Diet;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Politics\Diet\CoalitionFormation;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\Policy\Lever;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

/**
 * The politics engine's long-run properties, each run over decades of simulated time or many draws: the Diet's
 * balance and spread, the engine beside the economy, and the cabinet hazards. Owned by the Realism suite.
 */
class PoliticsEngineRealismTest extends TestCase
{
    use PoliticsEngineFixtures;

    /**
     * Vote after vote a party strays around its home by the Chapel Hill survey's within-party spread, rather than
     * wandering off as a random walk would, and never leaves the axis it is defined by or the scale.
     */
    public function testPositionsStrayAroundTheirHomesAtTheSurveysSpread(): void
    {
        mt_srand(3);
        $engine = $this->engine(new MathUtility());
        $deviations = [Diet::AXIS_OPENNESS => [], Diet::AXIS_COUNCIL => []];
        $state = $this->electionTick();
        for ($vote = 0, $at = self::ELECTION_AT; $vote < 2000; ++$vote, $at += Election::TERM_YEARS) {
            $engine->advance($state, $this->economy($at), 0.01);
            Politics::takeOffice($state);
            foreach (Diet::PARTIES as $party) {
                foreach (Diet::AXES as $axis) {
                    $this->assertGreaterThanOrEqual(-1.0, $state->partyPositions[$party][$axis]);
                    $this->assertLessThanOrEqual(1.0, $state->partyPositions[$party][$axis]);
                }
                foreach (Diet::FIXED_POSITIONS[$party] as $axis => $value) {
                    $this->assertSame($value, $state->partyPositions[$party][$axis]);
                }
            }
            foreach ($deviations as $axis => $unused) {
                $deviations[$axis][] = $state->partyPositions[Diet::VANGUARD][$axis] - Diet::HOME_POSITIONS[Diet::VANGUARD][$axis];
            }
        }

        foreach ($deviations as $axis => $axisDeviations) {
            $mean = array_sum($axisDeviations) / count($axisDeviations);
            $sd = sqrt(array_sum(array_map(static fn(float $d): float => ($d - $mean) ** 2, $axisDeviations)) / count($axisDeviations));
            $this->assertEqualsWithDelta(0.0, $mean, 0.05, "The Vanguard has wandered from home on {$axis}.");
            $this->assertEqualsWithDelta(Election::POSITION_WITHIN_SD[$axis], $sd, 0.025, "The {$axis} spread is off the survey's.");
        }
    }

    /**
     * Vote after vote at a trend economy, each cabinet taking office after its talks: the outgoing cabinet's parties
     * lose about what the record's governments lose, the Diet is as volatile as the Nordic vote process makes a
     * parliament of eight parties (13; Scandinavia 11 with four or five effective parties, ParlGov), the two big
     * parties hold their seats across the 76 years the record covers, no party drifts into a majority of its own, and
     * the talks run close to real ones. About a third need a second attempt (Golder 2010) and they last about a month
     * (Bäck, Hellström, Lindvall & Teorell 2023: 33.7 days, sd 33.9); in the full loop a quarter and 29 days, sd 30
     * (var/harness/polls/enp_an.py), and at a trend economy much the same. They seat the
     * governments Scandinavia's bloc parliaments have since 1945 (ParlGov, 68 cabinets): minority cabinets 76% (84%),
     * one party alone 46% (47%), and the parties at the Council axis's ends supporting far more often than they govern.
     * A bloc puts its leader in office, so the largest party sits in about 70% of cabinets (63%), the rest going to the
     * Vanguard's bloc while the Civic Front is the larger; the two largest govern together in 4% (1.5%), as the core
     * votes walk.
     */
    public function testTheDietStaysBalancedAtItsCalibratedVolatility(): void
    {
        mt_srand(23);
        $engine = $this->engine(new MathUtility());
        $volatility = [];
        $cabinetLosses = [];
        $bigTwo = ['early' => [], 'late' => []];
        $singlePartyMajority = 0;
        $talkDays = [];
        $retried = 0;
        $minority = 0;
        $largestIn = 0;
        $twoLargest = 0;
        $single = 0;
        $radicalIn = 0;
        $radicalSupports = 0;
        for ($run = 0; $run < 120; ++$run) {
            $state = $this->electionTick();
            for ($vote = 0; $vote < 19; ++$vote) {
                $outgoing = Diet::governingParties($state->governingCoalition);
                $before = $state->dietVoteShares;
                $this->vote($engine, $state);
                Politics::takeOffice($state);
                if ($vote < 5 || $vote >= 14) {
                    $bigTwo[$vote < 5 ? 'early' : 'late'][] = $state->dietSeats[Diet::CIVIC] + $state->dietSeats[Diet::VANGUARD];
                }
                // The first vote has no earlier short-term swing to give back, so it is not yet the steady state.
                if ($vote > 0) {
                    $volatility[] = Election::pedersenVolatility($state->dietVoteSwings);
                    $cabinetLosses[] = array_sum(array_map(static fn(string $party): float => $before[$party] - $state->dietVoteShares[$party], $outgoing));
                    $this->assertNotEmpty($state->dietSeats);
                    $singlePartyMajority += max($state->dietSeats) >= Diet::MAJORITY_SEATS ? 1 : 0;
                    $talkDays[] = $state->formationLog === [] ? 0.0 : $state->formationLog[array_key_last($state->formationLog)]['day'];
                    $retried += count($state->formationLog) > 1 ? 1 : 0;
                    $cabinet = Diet::governingParties($state->governingCoalition);
                    [$first, $second] = CoalitionFormation::bySize($state->dietSeats, $state->dietVoteShares);
                    $minority += Diet::governingParties($state->supportParties) === [] ? 0 : 1;
                    $largestIn += in_array($first, $cabinet, true) ? 1 : 0;
                    $twoLargest += in_array($first, $cabinet, true) && in_array($second, $cabinet, true) ? 1 : 0;
                    $single += count($cabinet) === 1 ? 1 : 0;
                    $radicalIn += count(array_intersect([Diet::CHARTISTS, Diet::COMMON_LOT], $cabinet));
                    $radicalSupports += count(array_intersect([Diet::CHARTISTS, Diet::COMMON_LOT], Diet::governingParties($state->supportParties)));
                }
                $state = $this->nextElectionTick($state);
            }
        }

        $meanDays = array_sum($talkDays) / count($talkDays);
        $this->assertEqualsWithDelta(0.26, $retried / count($talkDays), 0.04);
        $this->assertEqualsWithDelta(29.0, $meanDays, 3.0);
        $this->assertEqualsWithDelta(CoalitionFormation::FORMATION_MEAN_DAYS, $meanDays, 4.0, 'The uncertainty index is compensated for talks of another length.');
        $this->assertEqualsWithDelta(29.0, sqrt(array_sum(array_map(static fn(float $d): float => ($d - $meanDays) ** 2, $talkDays)) / count($talkDays)), 5.0);
        $this->assertEqualsWithDelta(0.74, $minority / count($talkDays), 0.06);
        $this->assertEqualsWithDelta(0.48, $single / count($talkDays), 0.04);
        $this->assertLessThan(0.05, $twoLargest / count($talkDays));
        $this->assertEqualsWithDelta(0.70, $largestIn / count($talkDays), 0.06);
        $this->assertLessThan(0.08, $radicalIn / (2 * count($talkDays)), 'The Council axis\'s ends sit in cabinet too often.');
        $this->assertGreaterThan(3 * $radicalIn, $radicalSupports, 'The Council axis\'s ends govern rather than support.');

        // The outgoing cabinet's own parties, as Nannestad & Paldam count them.
        $this->assertEqualsWithDelta(Election::RECORDED_COST_OF_RULING, array_sum($cabinetLosses) / count($cabinetLosses), 0.0075);
        $this->assertEqualsWithDelta(0.133, array_sum($volatility) / count($volatility), 0.015);
        $this->assertEqualsWithDelta(array_sum($bigTwo['early']) / count($bigTwo['early']), array_sum($bigTwo['late']) / count($bigTwo['late']), 6.0, 'The two big parties drift away from their normal votes.');
        $this->assertLessThan(0.05, $singlePartyMajority / count($volatility), 'The party system has drifted toward one-party rule.');
    }

    /**
     * Beside the engine, as the ticker runs them: a vote every term, each cabinet the talks produce takes office on its
     * day, and the economy runs each tick on the levers and the election pulse the government handed it the tick
     * before.
     */
    public function testTheEngineAndThePoliticsRunSideBySide(): void
    {
        mt_srand(17);
        $math = new MathUtility();
        $economy = new MacroEngine(
            $math,
            $this->inMemoryRedis(),
            new MacroSnapshotRecorder(),
            new MonetaryPolicySubsystem($math),
            new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($math),
            new CommodityLogisticsSubsystem($math),
            new AssetMarketSubsystem($math),
            new CreditFiscalSubsystem($math),
        );
        $engine = new Politics(MathUtility::ownStream(17), $this->inMemoryRedis());

        $votes = 0;
        $falls = 0;
        $formed = [];
        $pending = null;
        $ticksPerYear = 52;
        $policy = $engine->liveState()->policy();
        for ($tick = 0; $tick < 9 * $ticksPerYear; ++$tick) {
            $macro = $economy->updateMacroState(1.0 / $ticksPerYear, policy: $policy);
            $this->assertSame($policy->lever(Lever::CorporateTax), $macro->corporateTaxPolicyShift);
            $this->assertSame($policy->lever(Lever::LaborGrowth), $macro->laborForceGrowthRate);
            $this->assertSame($policy->electionPulse, $macro->electionPulse);

            $politics = $engine->updatePolitics($macro, 1.0 / $ticksPerYear);
            $policy = $politics->policy();
            $this->assertSame($macro->totalTime, $politics->totalTime);
            if ($politics->lastElectionAt === $politics->totalTime) {
                ++$votes;
                $this->assertNotSame([], $politics->dietVoteSwings);
                $this->assertSame((float) Diet::SEATS, array_sum($politics->dietSeats));
                $pending = [$politics->coalitionTakesOfficeAt, $politics->pendingCoalition];
            }
            if ($politics->lastCabinetFellAt === $politics->totalTime) {
                ++$falls;
                $pending = [$politics->coalitionTakesOfficeAt, $politics->pendingCoalition];
            }
            if ($politics->lastGovernmentFormedAt === $politics->totalTime && $pending !== null) {
                $this->assertGreaterThanOrEqual($pending[0], $politics->totalTime);
                $this->assertLessThan($pending[0] + (1.0 / $ticksPerYear), $politics->totalTime, 'The cabinet took office later than the first tick past its day.');
                $this->assertSame($pending[1], $politics->governingCoalition);
                $formed[] = $politics->totalTime;
            }
        }

        $this->assertSame(2, $votes);
        $this->assertCount($votes + $falls - ($politics->coalitionTakesOfficeAt >= 0.0 ? 1 : 0), $formed, 'Every talks but any still under way seated a cabinet.');
        $this->assertEquals($politics, $engine->liveState(), 'The politics are kept between ticks.');
    }

    public function testTheVoteFallsOnTheBoundaryTickWhenTheClockRunsAHairShort(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = new PoliticsState();

        // 720 additions of 1/180 land just short of 4.0; the vote must still fall on that tick, with the budget round.
        $dt = 1.0 / 180.0;
        $time = 0.0;
        for ($tick = 1; $tick <= 720; ++$tick) {
            $time += $dt;
            $engine->advance($state, $this->economy($time), $dt);
        }

        $this->assertSame($time, $state->lastElectionAt);
        $this->assertTrue(TimeSeries::crossedSimulatedBoundary($time, $dt, MacroConstants::BUDGET_ROUND_PERIOD_YEARS), 'The budget round falls on the same tick.');
    }

    /**
     * The talks weigh the outgoing cabinet's incumbency: a cabinet going into the vote re-forms far more often than it
     * forms after another.
     */
    public function testTheTalksWeighTheOutgoingCabinet(): void
    {
        mt_srand(11);
        $engine = $this->engine(new MathUtility());
        $cabinet = [Diet::VANGUARD, Diet::EXCHANGE];

        $this->assertGreaterThan(
            2 * $this->timesFormedAfter($engine, [Diet::VANGUARD, Diet::NEW_HORIZON], $cabinet),
            $this->timesFormedAfter($engine, $cabinet, $cabinet),
        );
    }

    /**
     * After a fall the parties that fell may govern together again, as at least 42% of West European successors between
     * votes do (ParlGov), with no incumbency weight: the talks seat them as often as their odds among all cabinets say.
     */
    public function testThePartiesThatFellMayFormAgainWithoutIncumbency(): void
    {
        $fallen = Diet::governingParties(Diet::SEED_COALITION);
        $odds = ElectionForecast::cabinetOdds(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS);
        $chance = 0.0;
        foreach ($odds as $option) {
            $chance += $option['cabinet'] === $fallen ? $option['chance'] : 0.0;
        }
        $this->assertGreaterThan(0.0, $chance, 'The cabinet that fell is among the cabinets the talks may seat.');

        $stream = MathUtility::ownStream(8);
        $runs = 1500;
        $again = 0;
        for ($trial = 0; $trial < $runs; ++$trial) {
            $talks = CoalitionFormation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, $stream);
            $again += $talks['cabinet'] === $fallen ? 1 : 0;
        }
        $this->assertGreaterThan(0, $again, 'The parties that fell can govern together again.');
    }

    /**
     * A cabinet's day is an exponential wait at its kind's hazard, drawn once when it has none: the waits average the
     * hazard's inverse.
     */
    public function testTheFallDayIsDrawnAtTheCabinetsHazard(): void
    {
        $engine = $this->engine(MathUtility::ownStream(5));
        $waits = [];
        for ($draw = 0; $draw < 4000; ++$draw) {
            $state = $this->governedBy([Diet::VANGUARD => 80.0], [Diet::EXCHANGE => 80.0]);
            $state->cabinetFallsAt = -1.0;
            $engine->advance($state, $this->economy(10.0), 0.01);
            $waits[] = $state->cabinetFallsAt - $state->totalTime;
        }

        $this->assertEqualsWithDelta(1.0 / Election::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY, array_sum($waits) / count($waits), 0.05 / Election::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY);

        $majority = $this->governedBy([Diet::VANGUARD => 160.0]);
        $majority->cabinetFallsAt = -1.0;
        $engine->advance($majority, $this->economy(10.0), 0.01);
        $this->assertSame(-1.0, $majority->cabinetFallsAt, 'A party governing alone on its own majority never falls.');
    }
}
