<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieDiet as Diet;
use App\DTO\GovernmentPolicyDTO;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\CoalitionFormation;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class PoliticsEngineTest extends TestCase
{
    /** The Vanguard's whole bloc at the founding, a majority cabinet of 160 seats with no one outside to answer to. */
    private const RIGHT_BLOC = [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS, Diet::NEW_HORIZON];

    /** The first vote on the calendar. */
    private const ELECTION_AT = Politics::ELECTION_TERM_YEARS;

    // --- Seats ---

    /** The textbook D'Hondt case: 100k/80k/30k/20k votes for 8 seats go 4/3/1/0. */
    public function testDHondtAllocatesTheTextbookCase(): void
    {
        $seats = Politics::dHondt(['a' => 100000.0, 'b' => 80000.0, 'c' => 30000.0, 'd' => 20000.0], 8);

        $this->assertSame(['a' => 4, 'b' => 3, 'c' => 1, 'd' => 0], $seats);
    }

    public function testTheFoundingVoteReproducesTheFoundingDiet(): void
    {
        $seats = Politics::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);

        $this->assertSame(Diet::SEATS, array_sum($seats));
        $this->assertSame((float) Diet::SEATS, array_sum(Diet::SEED_SEATS));
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_SEATS[$party], $seats[$party], 1.0, "{$party} is more than a seat off its founding count.");
        }
    }

    /** Each party stands fixed on the axes it is defined by, at its home on the others. */
    public function testEachPartyIsFixedOnTheAxesItIsDefinedBy(): void
    {
        $drifted = [
            Diet::CHARTISTS => [Diet::AXIS_COUNCIL => -0.5, Diet::AXIS_STATE => 0.4],
            Diet::NEW_HORIZON => [Diet::AXIS_STATE => 0.9, Diet::AXIS_OPENNESS => -0.9, Diet::AXIS_COUNCIL => -0.2],
        ];

        $this->assertSame(Diet::FIXED_POSITIONS[Diet::CHARTISTS][Diet::AXIS_COUNCIL], Diet::position(Diet::CHARTISTS, $drifted)[Diet::AXIS_COUNCIL], 'A stored value cannot move a party off its own axis.');
        $this->assertSame(0.4, Diet::position(Diet::CHARTISTS, $drifted)[Diet::AXIS_STATE]);
        $this->assertSame(Diet::HOME_POSITIONS[Diet::CHARTISTS][Diet::AXIS_OPENNESS], Diet::position(Diet::CHARTISTS, $drifted)[Diet::AXIS_OPENNESS], 'A missing axis is the home.');
        $this->assertSame([Diet::AXIS_STATE => -0.5, Diet::AXIS_OPENNESS => 0.7, Diet::AXIS_COUNCIL => -0.2, Diet::AXIS_ENVIRONMENT => Diet::HOME_POSITIONS[Diet::NEW_HORIZON][Diet::AXIS_ENVIRONMENT]], Diet::position(Diet::NEW_HORIZON, $drifted), 'New Horizon is fixed on two axes.');
        $this->assertSame([Diet::AXIS_ENVIRONMENT], array_keys(Diet::FIXED_POSITIONS[Diet::TIDELINE]), 'The Tideline Accord is defined by the environment alone.');
        $defined = array_values(array_unique(array_merge(...array_map('array_keys', array_values(Diet::FIXED_POSITIONS)))));
        $this->assertEqualsCanonicalizing(Diet::AXES, $defined, 'Every axis has parties defined by it.');
        foreach (Diet::FIXED_POSITIONS as $party => $fixed) {
            foreach ($fixed as $axis => $value) {
                $this->assertSame($value, Diet::HOME_POSITIONS[$party][$axis]);
                $this->assertTrue(Diet::isFixed($party, $axis));
            }
        }
        $this->assertFalse(Diet::isFixed(Diet::NEW_HORIZON, Diet::AXIS_COUNCIL));
    }

    public function testTheGovernmentsPositionIsItsPartiesWeightedBySeats(): void
    {
        $position = Politics::coalitionPosition(Diet::membership([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS]), Diet::SEED_SEATS, Diet::HOME_POSITIONS);

        // Vanguard 95 at (-0.6, 0, +0.3), Exchange 25 at (-0.1, +0.8, +0.4), Chartists 20 at (-0.4, +0.6, +0.8).
        $this->assertEqualsWithDelta((95.0 * -0.6 + 25.0 * -0.1 + 20.0 * -0.4) / 140.0, $position[Diet::AXIS_STATE], 1e-12);
        $this->assertEqualsWithDelta((25.0 * 0.8 + 20.0 * 0.6) / 140.0, $position[Diet::AXIS_OPENNESS], 1e-12);
        $this->assertEqualsWithDelta((95.0 * 0.3 + 25.0 * 0.4 + 20.0 * 0.8) / 140.0, $position[Diet::AXIS_COUNCIL], 1e-12);
    }

    // --- The vote ---

    /** Inflation costs every cabinet; growth is credited to a party governing alone and to no coalition. */
    public function testTheEconomicVoteCreditsGrowthOnlyToAPartyGoverningAlone(): void
    {
        foreach ([true, false] as $alone) {
            $atTrend = Politics::economicVote(0.0, 0.0, 0.0, $alone);

            $this->assertEqualsWithDelta(-Politics::ELECTION_COST_OF_RULING, $atTrend, 1e-12);
            $this->assertEqualsWithDelta(-Politics::ELECTION_INFLATION_SLOPE * 0.01, Politics::economicVote(0.0, 0.01, 0.0, $alone) - $atTrend, 1e-12);
            $this->assertEqualsWithDelta(Politics::ELECTION_RESIDUAL_SD, Politics::economicVote(0.0, 0.0, 1.0, $alone) - $atTrend, 1e-12);
        }
        $this->assertEqualsWithDelta(Politics::ELECTION_SINGLE_PARTY_GROWTH_SLOPE * 0.01, Politics::economicVote(0.01, 0.0, 0.0, true) - Politics::economicVote(0.0, 0.0, 0.0, true), 1e-12);
        $this->assertSame(Politics::economicVote(0.0, 0.0, 0.0, false), Politics::economicVote(0.01, 0.0, 0.0, false), 'A coalition gets no credit for growth.');
    }

    /**
     * Vote after vote at a trend economy, each cabinet taking office after its talks: the outgoing cabinet's parties lose
     * about what the record's governments lose, the Diet is as volatile as the Nordic vote process makes a parliament of
     * eight parties (13; Scandinavia 11 with four or five effective parties, ParlGov), the two big parties hold their
     * seats across 160 years, no party drifts into a majority of its own, and the talks run close to real ones. In the
     * full loop about a third need a second attempt (Golder 2010) and they last about a month (Bäck, Hellström, Lindvall
     * & Teorell 2023: 33.7 days, sd 33.9), 32% and 33.5 days (var/harness/politics/formation_report.py); at a trend
     * economy they come easier, 26% and 30 days. They seat the governments Scandinavia's bloc parliaments have since 1945
     * (ParlGov, 68 cabinets): minority cabinets 74% (84%), one party alone 48% (47%), and the parties at the Council
     * axis's ends supporting far more often than they govern. A bloc puts its leader in office, so the largest party
     * sits in 76% of cabinets, above Scandinavia's 63%, where the largest party leads the losing bloc more often; the two
     * largest govern together in 2% (1.5%).
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
        for ($run = 0; $run < 60; ++$run) {
            $state = $this->electionTick();
            for ($vote = 0; $vote < 40; ++$vote) {
                $outgoing = Diet::governingParties($state->governingCoalition);
                $before = $state->dietVoteShares;
                $this->vote($engine, $state);
                Politics::takeOffice($state);
                if ($vote < 10 || $vote >= 30) {
                    $bigTwo[$vote < 10 ? 'early' : 'late'][] = $state->dietSeats[Diet::CIVIC] + $state->dietSeats[Diet::VANGUARD];
                }
                // The first vote has no earlier short-term swing to give back, so it is not yet the steady state.
                if ($vote > 0) {
                    $volatility[] = Politics::pedersenVolatility($state->dietVoteSwings);
                    $cabinetLosses[] = array_sum(array_map(static fn(string $party): float => $before[$party] - $state->dietVoteShares[$party], $outgoing));
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
        $this->assertEqualsWithDelta(30.0, $meanDays, 3.0);
        $this->assertEqualsWithDelta(CoalitionFormation::FORMATION_MEAN_DAYS, $meanDays, 4.0, 'The uncertainty index is compensated for talks of another length.');
        $this->assertEqualsWithDelta(29.0, sqrt(array_sum(array_map(static fn(float $d): float => ($d - $meanDays) ** 2, $talkDays)) / count($talkDays)), 5.0);
        $this->assertEqualsWithDelta(0.74, $minority / count($talkDays), 0.06);
        $this->assertEqualsWithDelta(0.48, $single / count($talkDays), 0.04);
        $this->assertLessThan(0.05, $twoLargest / count($talkDays));
        $this->assertEqualsWithDelta(0.76, $largestIn / count($talkDays), 0.04);
        $this->assertLessThan(0.08, $radicalIn / (2 * count($talkDays)), 'The Council axis\'s ends sit in cabinet too often.');
        $this->assertGreaterThan(3 * $radicalIn, $radicalSupports, 'The Council axis\'s ends govern rather than support.');

        // The outgoing cabinet's own parties, as Nannestad & Paldam count them.
        $this->assertEqualsWithDelta(Politics::ELECTION_RECORDED_COST_OF_RULING, array_sum($cabinetLosses) / count($cabinetLosses), 0.0075);
        $this->assertEqualsWithDelta(0.133, array_sum($volatility) / count($volatility), 0.015);
        $this->assertEqualsWithDelta(array_sum($bigTwo['early']) / count($bigTwo['early']), array_sum($bigTwo['late']) / count($bigTwo['late']), 6.0, 'The two big parties drift away from their normal votes.');
        $this->assertLessThan(0.05, $singlePartyMajority / count($volatility), 'The party system has drifted toward one-party rule.');
    }

    /** A party's short-term swing is undone by its negation. */
    public function testAShortTermSwingIsUndoneByItsNegation(): void
    {
        $shocks = [Diet::CIVIC => 0.3, Diet::VANGUARD => -0.2, Diet::IRON_HARBOR => 0.1, Diet::EXCHANGE => -0.05, Diet::CHARTISTS => 0.2, Diet::COMMON_LOT => -0.1];
        $swung = Politics::applyShortTermShocks(Diet::SEED_VOTE_SHARES, $shocks);
        $restored = Politics::applyShortTermShocks($swung, array_map(static fn(float $shock): float => -$shock, $shocks));

        $this->assertEqualsWithDelta(1.0, array_sum($swung), 1e-12);
        $this->assertGreaterThan(Diet::SEED_VOTE_SHARES[Diet::CIVIC], $swung[Diet::CIVIC]);
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_VOTE_SHARES[$party], $restored[$party], 1e-12);
        }
    }

    /** A party's swings scale with its size as real vote shares' do: the Common Lot, 8 seats to the Civic Front's 94, swings the square root of 94/8, 3.4 times, as far in log share. */
    public function testSmallPartiesSwingFurtherInLogShare(): void
    {
        $large = Politics::swingSd(Politics::ELECTION_SHORT_TERM_SWING_VARIANCE, Diet::CIVIC);
        $small = Politics::swingSd(Politics::ELECTION_SHORT_TERM_SWING_VARIANCE, Diet::COMMON_LOT);

        $this->assertEqualsWithDelta(sqrt(Politics::ELECTION_SHORT_TERM_SWING_VARIANCE / (94.0 / 300.0)), $large, 1e-12);
        $this->assertEqualsWithDelta(sqrt(94.0 / 8.0), $small / $large, 1e-12);
    }

    /** A boom over the campaign returns the government stronger; inflation over the term costs it. */
    public function testGrowthAndInflationMoveTheGovernmentsVote(): void
    {
        $engine = $this->engine($this->quietMath());
        $voted = function (array $cabinet, float $growthGap = 0.0, float $inflationGap = 0.0) use ($engine): PoliticsState {
            $state = $this->electionTick($growthGap, $inflationGap);
            $state->governingCoalition = Diet::membership($cabinet);
            $state->supportParties = Diet::membership([]);
            $this->vote($engine, $state);

            return $state;
        };

        $alone = $voted([Diet::VANGUARD]);
        $boomAlone = $voted([Diet::VANGUARD], growthGap: 0.03);
        $coalition = $voted(self::RIGHT_BLOC);
        $boomCoalition = $voted(self::RIGHT_BLOC, growthGap: 0.03);
        $inflation = $voted(self::RIGHT_BLOC, inflationGap: 0.03);

        $this->assertEqualsWithDelta(0.03, $boomAlone->electionGrowthGap, 1e-9);
        $this->assertEqualsWithDelta(0.03, $inflation->electionInflationGap, 1e-9);
        $this->assertEqualsWithDelta(Politics::ELECTION_SINGLE_PARTY_GROWTH_SLOPE * 0.03, $boomAlone->electionIncumbentSwing - $alone->electionIncumbentSwing, 1e-9, 'A boom returns a party governing alone stronger,');
        $this->assertEqualsWithDelta($coalition->electionIncumbentSwing, $boomCoalition->electionIncumbentSwing, 1e-12, 'and a coalition no stronger.');
        $this->assertEqualsWithDelta(-Politics::ELECTION_INFLATION_SLOPE * 0.03, $inflation->electionIncumbentSwing - $coalition->electionIncumbentSwing, 1e-9, 'Inflation costs a coalition too.');
    }

    /** The opposition takes what the government loses, each opposition party in proportion to its own share. */
    public function testTheSwingIsSharedInProportion(): void
    {
        $shares = Politics::applyIncumbentSwing(Diet::SEED_VOTE_SHARES, Diet::membership([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS]), -0.04);
        $seed = Diet::SEED_VOTE_SHARES;

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        $this->assertEqualsWithDelta(140.0 / 300.0 - 0.04, $shares[Diet::VANGUARD] + $shares[Diet::EXCHANGE] + $shares[Diet::CHARTISTS], 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::CIVIC] / $seed[Diet::IRON_HARBOR], $shares[Diet::CIVIC] / $shares[Diet::IRON_HARBOR], 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::VANGUARD] / $seed[Diet::EXCHANGE], $shares[Diet::VANGUARD] / $shares[Diet::EXCHANGE], 1e-12);
    }

    /** A party supporting the cabinet from outside shares the government's swing by the accountability voters hold it to. */
    public function testASupportPartySharesTheSwingByItsAccountability(): void
    {
        $seed = Diet::SEED_VOTE_SHARES;
        $cabinet = Diet::membership([Diet::VANGUARD]);
        $support = Diet::membership([Diet::EXCHANGE]);
        $weight = Politics::ELECTION_SUPPORT_ACCOUNTABILITY;
        $governing = $seed[Diet::VANGUARD] + ($weight * $seed[Diet::EXCHANGE]);
        $opposition = 1.0 - $governing;

        $shares = Politics::applyIncumbentSwing($seed, $cabinet, -0.04, $support);

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::VANGUARD] * (1.0 - (0.04 / $governing)), $shares[Diet::VANGUARD], 1e-12);
        $this->assertEqualsWithDelta(
            $seed[Diet::EXCHANGE] * (1.0 - ($weight * 0.04 / $governing) + ((1.0 - $weight) * 0.04 / $opposition)),
            $shares[Diet::EXCHANGE],
            1e-12
        );
        $this->assertLessThan($seed[Diet::EXCHANGE], $shares[Diet::EXCHANGE], 'It loses with the government,');
        $this->assertGreaterThan($seed[Diet::EXCHANGE] * $shares[Diet::VANGUARD] / $seed[Diet::VANGUARD], $shares[Diet::EXCHANGE], 'but less than a cabinet party does.');
        $this->assertEqualsWithDelta($seed[Diet::CIVIC] * (1.0 + (0.04 / $opposition)), $shares[Diet::CIVIC], 1e-12);
    }

    // --- Positions ---

    /** Left alone, a party's distance from home shrinks by the fitted persistence each term. */
    public function testAPartyIsPulledBackTowardItsHome(): void
    {
        foreach ([Diet::AXIS_STATE, Diet::AXIS_OPENNESS, Diet::AXIS_COUNCIL] as $axis) {
            $persistence = Politics::POSITION_ANNUAL_PERSISTENCE[$axis] ** Politics::ELECTION_TERM_YEARS;
            $this->assertEqualsWithDelta(0.1 + (0.5 * $persistence), Politics::movePosition(0.6, 0.1, $axis, 0.0), 1e-12, "{$axis}: the pull home.");
            $this->assertEqualsWithDelta(0.1, Politics::movePosition(0.1, 0.1, $axis, 0.0), 1e-12, "{$axis}: a party at home stays there.");
        }
    }

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
        for ($vote = 0, $at = self::ELECTION_AT; $vote < 2000; ++$vote, $at += Politics::ELECTION_TERM_YEARS) {
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
            $this->assertEqualsWithDelta(Politics::POSITION_WITHIN_SD[$axis], $sd, 0.025, "The {$axis} spread is off the survey's.");
        }
    }

    /** A step past the end of the axis comes back off it by the overshoot, rather than sticking at the end. */
    public function testAStepPastTheEndOfTheAxisIsReflected(): void
    {
        $persistence = Politics::POSITION_ANNUAL_PERSISTENCE[Diet::AXIS_OPENNESS] ** Politics::ELECTION_TERM_YEARS;
        $overshoot = (0.95 * $persistence) + (Politics::POSITION_WITHIN_SD[Diet::AXIS_OPENNESS] * sqrt(1.0 - ($persistence ** 2)) * 3.0);

        $this->assertGreaterThan(1.0, $overshoot);
        $this->assertEqualsWithDelta(2.0 - $overshoot, Politics::movePosition(0.95, 0.0, Diet::AXIS_OPENNESS, 3.0), 1e-12);
    }

    // --- The calendar and the talks ---

    /** The vote is held on the tick a term ends only; it opens the talks, and the outgoing cabinet stays on meanwhile. */
    public function testTheVoteIsHeldOnlyOnTheCalendarsElectionTick(): void
    {
        $engine = $this->engine($this->quietMath());

        $between = $this->electionTick();
        $engine->advance($between, $this->economy(self::ELECTION_AT + 0.5), 0.01);
        $this->assertSame(Diet::SEED_VOTE_SHARES, $between->dietVoteShares);
        $this->assertSame(-1.0, $between->lastElectionAt);

        $onTheDay = $this->electionTick();
        $this->vote($engine, $onTheDay);
        $this->assertSame(self::ELECTION_AT, $onTheDay->lastElectionAt);
        $this->assertNotSame(Diet::SEED_VOTE_SHARES, $onTheDay->dietVoteShares);
        $this->assertSame(self::ELECTION_AT, $onTheDay->termStartedAt);
        $this->assertNotSame([], $onTheDay->formationLog, 'No party won a majority, so there are talks.');
        $this->assertGreaterThan(self::ELECTION_AT, $onTheDay->coalitionTakesOfficeAt);
        $this->assertSame(Diet::SEED_COALITION, $onTheDay->governingCoalition, 'The outgoing cabinet stays on as caretaker.');
        $this->assertNotSame([], Diet::governingParties($onTheDay->pendingCoalition));
        $this->assertEqualsWithDelta(
            self::ELECTION_AT + ($onTheDay->formationLog[array_key_last($onTheDay->formationLog)]['day'] / FinancialConstants::DAYS_PER_YEAR),
            $onTheDay->coalitionTakesOfficeAt,
            1e-12,
            'The cabinet takes office the day the last attempt succeeds.'
        );
    }

    // --- Falls between votes ---

    /** A coalition can lose a partner and a minority cabinet its supporters; a party governing alone on its own majority has neither. */
    public function testAFallsHazardFollowsTheCabinetsKind(): void
    {
        $seats = [Diet::CIVIC => 160.0, Diet::VANGUARD => 60.0, Diet::IRON_HARBOR => 40.0, Diet::EXCHANGE => 40.0];

        $this->assertSame(0.0, Politics::fallHazard([Diet::CIVIC], $seats));
        $this->assertSame(Politics::CABINET_FALL_HAZARD_MAJORITY_COALITION, Politics::fallHazard([Diet::CIVIC, Diet::IRON_HARBOR], $seats));
        $this->assertSame(Politics::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY, Politics::fallHazard([Diet::VANGUARD], $seats));
        $this->assertSame(Politics::CABINET_FALL_HAZARD_MINORITY_COALITION, Politics::fallHazard([Diet::VANGUARD, Diet::EXCHANGE], $seats));
    }

    /**
     * On its day the cabinet falls: it stays on as caretaker, passes no budget, and the parties talk on the same seats for
     * any cabinet but the one that fell; the government the talks produce takes office on its day and draws a day of its own.
     */
    public function testACabinetFallsOnItsDayAndTheTalksSeatAnother(): void
    {
        $engine = $this->engine(MathUtility::ownStream(3));
        $state = $this->governedBy([Diet::VANGUARD => 85.0], [Diet::EXCHANGE => 35.0, Diet::CHARTISTS => 20.0, Diet::NEW_HORIZON => 20.0]);
        $state->dietSeats = Diet::SEED_SEATS;
        $state->cabinetFallsAt = 10.3;
        $openAgainstClosed = [Diet::CIVIC => Diet::CIVIC, Diet::VANGUARD => Diet::VANGUARD, Diet::IRON_HARBOR => Diet::VANGUARD, Diet::EXCHANGE => Diet::CIVIC,
            Diet::CHARTISTS => Diet::CIVIC, Diet::COMMON_LOT => Diet::VANGUARD, Diet::NEW_HORIZON => Diet::CIVIC, Diet::TIDELINE => Diet::VANGUARD];
        $state->dietBlocs = $openAgainstClosed;

        $engine->advance($state, $this->economy(10.3), 0.01);

        $this->assertSame(10.3, $state->lastCabinetFellAt);
        $this->assertSame($openAgainstClosed, $state->dietBlocs, 'A fall declares no blocs: the talks go on in those of the last vote.');
        $this->assertSame(10.3, $state->talksStartedAt);
        $this->assertSame(-1.0, $state->cabinetFallsAt);
        $this->assertSame([Diet::VANGUARD], Diet::governingParties($state->governingCoalition), 'The cabinet that fell stays on as caretaker.');
        $this->assertGreaterThan(10.3, $state->coalitionTakesOfficeAt);
        $this->assertNotSame([Diet::VANGUARD], Diet::governingParties($state->pendingCoalition));
        $this->assertNotSame([], $state->formationLog);
        $this->assertSame(Diet::SEED_SEATS, $state->dietSeats, 'No election is called.');

        $engine->advance($state, $this->economy($state->coalitionTakesOfficeAt), 0.01);

        $this->assertSame($state->totalTime, $state->lastGovernmentFormedAt);
        $this->assertGreaterThan($state->totalTime, $state->cabinetFallsAt, 'The new cabinet draws its own day.');
    }

    /**
     * The parties declare their blocs at the vote, on the seats they go into it with: a Diet where Iron Harbor has
     * outgrown the Civic Front campaigns with Iron Harbor leading the left, whatever the vote then does to the seats.
     */
    public function testTheVoteDeclaresTheBlocsOnTheSeatsGoingIntoIt(): void
    {
        $state = $this->electionTick();
        $state->dietSeats[Diet::IRON_HARBOR] = 80.0;
        $state->dietSeats[Diet::CIVIC] = 60.0;
        $going = $state->dietSeats;

        $this->vote($this->engine($this->quietMath()), $state);

        $this->assertSame(CoalitionFormation::declareBlocs($going, $state->partyPositions, Diet::SEED_BLOCS), $state->dietBlocs);
        $this->assertSame(Diet::IRON_HARBOR, $state->dietBlocs[Diet::CIVIC]);
        $this->assertGreaterThan($state->dietSeats[Diet::IRON_HARBOR], $state->dietSeats[Diet::CIVIC], 'The vote gives the Civic Front back its lead, after the blocs were declared.');
    }

    /** The talks never seat the cabinet that fell, however likely it was. */
    public function testTheTalksAfterAFallNeverSeatTheCabinetThatFell(): void
    {
        $stream = MathUtility::ownStream(8);
        $fallen = [Diet::VANGUARD];
        for ($trial = 0; $trial < 200; ++$trial) {
            $talks = CoalitionFormation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, $stream, $fallen);
            $this->assertNotSame($fallen, $talks['cabinet']);
            foreach ($talks['log'] as $attempt) {
                $this->assertNotSame($fallen, $attempt['cabinet']);
            }
        }
    }

    /** A cabinet's day is an exponential wait at its kind's hazard, drawn once when it has none: the waits average the hazard's inverse. */
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

        $this->assertEqualsWithDelta(1.0 / Politics::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY, array_sum($waits) / count($waits), 0.05 / Politics::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY);

        $majority = $this->governedBy([Diet::VANGUARD => 160.0]);
        $majority->cabinetFallsAt = -1.0;
        $engine->advance($majority, $this->economy(10.0), 0.01);
        $this->assertSame(-1.0, $majority->cabinetFallsAt, 'A party governing alone on its own majority never falls.');
    }

    /** A vote clears the sitting cabinet's day: the cabinet the vote seats draws its own once it takes office. */
    public function testAVoteClearsTheFallDay(): void
    {
        $state = $this->electionTick();
        $state->cabinetFallsAt = self::ELECTION_AT + 1.0;

        $this->vote($this->engine(MathUtility::ownStream(2)), $state);

        $this->assertGreaterThan($state->totalTime, $state->coalitionTakesOfficeAt, 'The founding Diet is hung, so talks follow.');
        $this->assertSame(-1.0, $state->cabinetFallsAt);
        $this->assertSame(Diet::SEED_COALITION, $state->electionOutgoingCabinet);
        $this->assertSame(self::ELECTION_AT, $state->talksStartedAt);
    }

    /** A party that wins a majority of its own governs alone from the day of the vote. */
    public function testAMajorityWonOutrightTakesOfficeOnTheDay(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = $this->electionTick();
        // A lead far enough past the Vanguard's normal vote to keep a majority as it drifts back.
        $state->dietVoteShares = [Diet::CIVIC => 0.05, Diet::VANGUARD => 0.80, Diet::IRON_HARBOR => 0.03, Diet::EXCHANGE => 0.03, Diet::CHARTISTS => 0.02, Diet::COMMON_LOT => 0.02, Diet::TIDELINE => 0.03, Diet::NEW_HORIZON => 0.02];

        $this->vote($engine, $state);

        $this->assertGreaterThanOrEqual(Diet::MAJORITY_SEATS, $state->dietSeats[Diet::VANGUARD]);
        $this->assertSame([Diet::VANGUARD], Diet::governingParties($state->governingCoalition));
        $this->assertSame([], Diet::governingParties($state->supportParties));
        $this->assertSame([], $state->formationLog);
        $this->assertSame(self::ELECTION_AT, $state->coalitionFormedAt);
        $this->assertSame(self::ELECTION_AT, $state->lastGovernmentFormedAt);
        $this->assertSame(-1.0, $state->coalitionTakesOfficeAt);
    }

    /** The talks weigh the outgoing cabinet's incumbency: a cabinet going into the vote re-forms far more often than it forms after another. */
    public function testTheTalksWeighTheOutgoingCabinet(): void
    {
        mt_srand(11);
        $engine = $this->engine(new MathUtility());
        $cabinet = [Diet::VANGUARD, Diet::EXCHANGE];
        $formedAfter = function (array $outgoing) use ($engine, $cabinet): int {
            $count = 0;
            for ($trial = 0; $trial < 300; ++$trial) {
                $state = $this->electionTick();
                $state->governingCoalition = Diet::membership($outgoing);
                $this->vote($engine, $state);
                $count += Diet::governingParties($state->pendingCoalition) === $cabinet ? 1 : 0;
            }

            return $count;
        };

        $this->assertGreaterThan(2 * $formedAfter([Diet::VANGUARD, Diet::NEW_HORIZON]), $formedAfter($cabinet));
    }

    /** The cabinet the talks produced takes office on the first tick at or past its day, supporters with it. */
    public function testTheCabinetTakesOfficeOnTheFirstTickAtOrPastItsDay(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = $this->pendingGovernment(takesOfficeAt: 4.1);

        $engine->advance($state, $this->economy(4.09), 0.01);
        $this->assertSame(Diet::SEED_COALITION, $state->governingCoalition);

        $engine->advance($state, $this->economy(4.1), 0.01);
        $this->assertSame([Diet::VANGUARD], Diet::governingParties($state->governingCoalition));
        $this->assertSame([Diet::EXCHANGE, Diet::CHARTISTS], Diet::governingParties($state->supportParties));
        $this->assertSame(4.1, $state->coalitionFormedAt);
        $this->assertSame(4.1, $state->lastGovernmentFormedAt);
        $this->assertSame(-1.0, $state->coalitionTakesOfficeAt);
    }

    /** A caretaker passes no budget, however long the talks run; the new cabinet legislates at the round after it takes office. */
    public function testTheCaretakerPassesNoBudget(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = $this->pendingGovernment(takesOfficeAt: 4.6);
        // A majority cabinet, so nothing but the talks stands between it and its budget.
        $state->pendingCoalition = Diet::membership(self::RIGHT_BLOC);
        $state->pendingSupport = Diet::membership([]);
        $state->governingCoalition = Diet::membership([Diet::CIVIC, Diet::IRON_HARBOR]);

        $engine->advance($state, $this->economy(4.5), 0.01);
        $this->assertSame(-1.0, $state->lastBudgetEnactedAt, 'The caretaker passed a budget.');

        $engine->advance($state, $this->economy(4.6), 0.01);
        $this->assertSame(4.6, $state->coalitionFormedAt);

        $engine->advance($state, $this->economy(5.0), 0.01);
        $this->assertSame(5.0, $state->lastBudgetEnactedAt);
    }

    /** The campaign opens on the tick that crosses nine months before the vote. */
    public function testTheCampaignMarkFallsNineMonthsBeforeTheVote(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = new PoliticsState();
        $state->campaignStartedAt = 0.0;
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = 1.0;

        // Ticks of 0.01y, the last before the campaign and the one it opens on.
        $campaignOpens = self::ELECTION_AT - Politics::ELECTION_CAMPAIGN_WINDOW_YEARS;
        $engine->advance($state, $this->economy($campaignOpens - 0.01), 0.01);
        $this->assertSame(0.0, $state->campaignStartedAt);

        $economy = new MacroStateDTO(totalTime: $campaignOpens, outputGap: 0.02);
        $engine->advance($state, $economy, 0.01);
        $this->assertSame($campaignOpens, $state->campaignStartedAt);
        $this->assertSame(Politics::realGdp($economy), $state->campaignStartRealGdp);
    }

    /**
     * Beside the engine, as the ticker runs them: a vote every term, each cabinet the talks produce takes office on its
     * day, and the economy runs each tick on the levers and the election pulse the government handed it the tick before.
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
            $this->assertSame($policy->corporateTaxPolicyShift, $macro->corporateTaxPolicyShift);
            $this->assertSame($policy->laborForceGrowthRate, $macro->laborForceGrowthRate);
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

    // --- The calendar ---

    /** The vote falls on the tick that reaches the term's end, and is not held again on the next. */
    public function testTheVoteFallsOnTheTickTheTermEnds(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = new PoliticsState();

        // Times sit on the tick grid, as the engine's accumulated clock does to within a rounding error.
        $engine->advance($state, $this->economy(3.99), 0.01);
        $this->assertSame(-1.0, $state->lastElectionAt, 'No election before the term is up.');

        $engine->advance($state, $this->economy(4.00), 0.01);
        $this->assertSame(4.00, $state->lastElectionAt, 'The vote falls on the tick that reaches the term boundary.');

        $engine->advance($state, $this->economy(4.01), 0.01);
        $this->assertSame(4.00, $state->lastElectionAt, 'and is not re-held on the next tick.');
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
        $this->assertTrue(MathUtility::crossedSimulatedBoundary($time, $dt, MacroEngine::BUDGET_ROUND_PERIOD_YEARS), 'The budget round falls on the same tick.');
    }

    // --- Headlines ---

    /** The vote is the day's news above everything else the Diet does, and yesterday's vote is no news. */
    public function testAnElectionIsReportedOnTheDayOnly(): void
    {
        $state = new PoliticsState();
        $state->totalTime = 4.0;
        $state->lastElectionAt = 4.0;
        $state->lastGovernmentFormedAt = 4.0;
        $state->lastBudgetEnactedAt = 4.0;
        $this->assertSame(ShockEvent::ELECTION_HELD, Politics::headline($state), 'A majority won outright takes office on the day of the vote, which is the news, and the vote outranks a budget.');

        $state->totalTime = 4.01;
        $this->assertNull(Politics::headline($state), 'Yesterday\'s election is not today\'s news.');
    }

    /** A cabinet falling is news on its day, above a party taking office the same day; a cabinet taking office after talks is news on its day. */
    public function testAFallAndAGovernmentAreReportedOnTheirDays(): void
    {
        $on = static function (array $marks): ?string {
            $state = new PoliticsState();
            $state->totalTime = 6.3;
            $state->lastElectionAt = 4.0;
            foreach ($marks as $field => $at) {
                $state->$field = $at;
            }

            return Politics::headline($state);
        };

        $this->assertSame(ShockEvent::GOVERNMENT_FELL, $on(['lastCabinetFellAt' => 6.3, 'lastGovernmentFormedAt' => 6.3]), 'A party that takes over on the day of the fall is part of the same news.');
        $this->assertSame(ShockEvent::GOVERNMENT_FORMED, $on(['lastGovernmentFormedAt' => 6.3]));
        $this->assertSame(ShockEvent::GOVERNMENT_FORMED, $on(['lastGovernmentFormedAt' => 6.3, 'lastBudgetEnactedAt' => 6.3]), 'A cabinet taking office outranks a budget.');
        $this->assertSame(ShockEvent::BUDGET_ENACTED, $on(['lastBudgetEnactedAt' => 6.3]));
        $this->assertSame(ShockEvent::BUDGET_ENACTED, $on(['lastBudgetEnactedAt' => 6.3, 'lastGovernorAppointedAt' => 6.3]), 'A budget outranks the Council\'s appointments.');
        $this->assertSame(ShockEvent::GOVERNOR_APPOINTED, $on(['lastGovernorAppointedAt' => 6.3, 'lastMajorityShiftAt' => 6.3]), 'A new governor outranks the majority their arrival tips.');
        $this->assertSame(ShockEvent::AUTHORITY_MAJORITY_SHIFT, $on(['lastMajorityShiftAt' => 6.3, 'lastCouncillorSeatedAt' => 6.3]));
        $this->assertSame(ShockEvent::GOVERNOR_APPOINTED, $on(['lastGovernorAppointedAt' => 6.3, 'lastCouncillorSeatedAt' => 6.3]));
        $this->assertSame(ShockEvent::COUNCILLOR_SEATED, $on(['lastCouncillorSeatedAt' => 6.3, 'lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.005]));
        $this->assertSame(ShockEvent::MONETARY_DECISION, $on(['lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.0025, 'lastMeetingVotes' => [0.0, 0.0]]), 'A full step makes news.');
        $this->assertSame(ShockEvent::MONETARY_DECISION, $on(['lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.0, 'lastMeetingVotes' => [0.0, 1.0]]), 'So does a dissent.');
        $this->assertNull($on(['lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.001, 'lastMeetingVotes' => [0.0, 0.0]]), 'A unanimous small move does not.');
    }

    /** Each tick names its headline, and the next tick clears it. */
    public function testTheHeadlineIsAPulse(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = $this->electionTick();

        $this->vote($engine, $state);
        $this->assertSame(ShockEvent::ELECTION_HELD, $state->eventType);

        $engine->advance($state, $this->economy($state->coalitionTakesOfficeAt), 0.01);
        $this->assertSame(ShockEvent::GOVERNMENT_FORMED, $state->eventType);

        $engine->advance($state, $this->economy($state->totalTime + 0.01), 0.01);
        $this->assertNull($state->eventType);
    }

    // --- The election pulse ---

    /** Nearness to the vote ramps in over the final year of the term, from nothing at mid-term to the full lift on the eve. */
    public function testThePulseBuildsIntoAScheduledElection(): void
    {
        $midTerm = Politics::electionPulse(1.5, -1.0, -1.0);
        $eve = Politics::electionPulse(3.95, -1.0, -1.0);

        $this->assertEqualsWithDelta(-Politics::MEAN_ELECTION_PROXIMITY, $midTerm, 1e-12);
        $this->assertEqualsWithDelta(0.95 - Politics::MEAN_ELECTION_PROXIMITY, $eve, 1e-9);
    }

    /**
     * The vote settles nothing until a government takes office: the pulse holds at its peak on the day of the vote and
     * through the talks after it, and falls back once the cabinet is seated.
     */
    public function testThePulseHoldsThroughTheTalks(): void
    {
        $peak = 1.0 - Politics::MEAN_ELECTION_PROXIMITY;

        $this->assertSame($peak, Politics::electionPulse(4.0, 4.0, -1.0), 'The vote\'s own tick is the peak, even with a majority seated the same day.');
        $this->assertSame($peak, Politics::electionPulse(4.1, 4.0, 4.2), 'The talks settle nothing.');
        $this->assertSame($peak, Politics::electionPulse(6.3, 4.0, 6.4), 'Nor do the talks after a fall.');
        $this->assertEqualsWithDelta(-Politics::MEAN_ELECTION_PROXIMITY, Politics::electionPulse(4.2, 4.0, -1.0), 1e-12, 'A seated government settles the regime.');
    }

    /**
     * Over a term with the average talks after the vote and after falls, the pulse averages nothing, so the calendar moves
     * policy uncertainty through the term and leaves its mean where it was.
     */
    public function testThePulseAveragesNothingOverATermOfAverageTalks(): void
    {
        $ticksPerYear = 3650;
        $talkYears = (CoalitionFormation::FORMATION_MEAN_DAYS + Politics::FALL_TALK_DAYS_PER_TERM) / FinancialConstants::DAYS_PER_YEAR;
        $sum = 0.0;
        $ticks = (int) round(Politics::ELECTION_TERM_YEARS * $ticksPerYear);
        for ($tick = 1; $tick <= $ticks; ++$tick) {
            $time = Politics::ELECTION_TERM_YEARS + ($tick / $ticksPerYear);
            $sum += Politics::electionPulse($time, Politics::ELECTION_TERM_YEARS, Politics::ELECTION_TERM_YEARS + $talkYears);
        }

        $this->assertEqualsWithDelta(0.0, $sum / $ticks, 1e-3);
    }

    // --- The Budget ---

    /** Each lever runs between the policies of the parties at the two ends of its axis. */
    public function testThePlatformRunsBetweenThePartiesAtTheEndsOfEachAxis(): void
    {
        $civic = Politics::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::CIVIC][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => 0.0]);
        $vanguard = Politics::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::VANGUARD][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => 0.0]);
        $this->assertEqualsWithDelta(Politics::POLICY_MANIFESTO_CORPORATE_TAX_GAP, $civic['corporateTax'] - $vanguard['corporateTax'], 1e-12, 'The two big parties are a manifesto gap apart.');
        $this->assertEqualsWithDelta(0.0, $civic['corporateTax'] + $vanguard['corporateTax'], 1e-12, 'The gap is centred on the neutral rate.');

        $harbor = Politics::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::IRON_HARBOR][Diet::AXIS_OPENNESS]]);
        $exchange = Politics::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::EXCHANGE][Diet::AXIS_OPENNESS]]);
        $this->assertEqualsWithDelta(Politics::POLICY_PROTECTIONIST_TARIFF, $harbor['tariff'], 1e-12, 'The protectionist party enacts its own tariff.');
        $this->assertSame(0.0, $exchange['tariff']);
        $this->assertEqualsWithDelta(Politics::MIGRATION_OPEN_REGIME - Politics::MIGRATION_CLOSED_REGIME, $exchange['laborGrowth'] - $harbor['laborGrowth'], 1e-12, 'The two immigration regimes are a regime apart.');
        $this->assertEqualsWithDelta(MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE, Politics::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => 0.0])['laborGrowth'], 1e-12, 'A neutral government keeps the structural rate.');
    }

    public function testANeutralOrOpenGovernmentKeepsTheFreePort(): void
    {
        foreach ([0.0, 0.2, 1.0] as $openness) {
            $this->assertSame(0.0, Politics::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => $openness])['tariff']);
        }
        $this->assertGreaterThan(0.0, Politics::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => -0.1])['tariff'], 'Any closed lean raises the tariff.');
    }

    public function testNoGovernmentGoesFurtherThanThePartyAtTheEndOfTheAxis(): void
    {
        $pole = Politics::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::CIVIC][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::IRON_HARBOR][Diet::AXIS_OPENNESS]]);

        $this->assertSame($pole, Politics::platform([Diet::AXIS_STATE => 1.0, Diet::AXIS_OPENNESS => -1.0]));
        $this->assertSame(
            Politics::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::VANGUARD][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::EXCHANGE][Diet::AXIS_OPENNESS]]),
            Politics::platform([Diet::AXIS_STATE => -1.0, Diet::AXIS_OPENNESS => 1.0])
        );
    }

    public function testAGovernmentLegislatesAtTheRoundAfterItTakesOffice(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = $this->pendingGovernment(takesOfficeAt: 4.1);
        $state->pendingCoalition = Diet::membership(self::RIGHT_BLOC);
        $state->pendingSupport = Diet::membership([]);

        $engine->advance($state, $this->economy(4.1), 0.01);
        $this->assertSame(0.0, $state->corporateTaxPolicyShift, 'Nothing is enacted on the tick the government takes office,');

        $engine->advance($state, $this->economy(4.5 - 0.01), 0.01);
        $this->assertSame(0.0, $state->corporateTaxPolicyShift, 'nor between rounds.');

        $engine->advance($state, $this->economy(4.5), 0.01);
        $platform = Politics::platform(Politics::coalitionPosition($state->governingCoalition, $state->dietSeats, $state->partyPositions));
        $this->assertLessThan(0.0, $platform['corporateTax'], 'The Vanguard-led government cuts the rate');
        $this->assertSame($platform['corporateTax'], $state->corporateTaxPolicyShift, 'and its first budget enacts the cut,');
        $this->assertSame($platform['tariff'], $state->importTariffRate);
        $this->assertSame($platform['laborGrowth'], $state->laborForceGrowthRate);
        $this->assertSame($state->totalTime, $state->lastBudgetEnactedAt);
        $this->assertSame(-1.0, $state->lastCouncilBrakeAt, 'with the debt below the line.');

        $policy = PoliticsStateDTO::fromState($state)->policy();
        $this->assertSame($state->corporateTaxPolicyShift, $policy->corporateTaxPolicyShift, 'The economy reads the levers the budget enacted.');
        $this->assertSame($state->importTariffRate, $policy->importTariffRate);
        $this->assertSame($state->laborForceGrowthRate, $policy->laborForceGrowthRate);
        $this->assertSame($state->mergerReviewLeniency, $policy->mergerReviewLeniency);
    }

    public function testTheCouncilHoldsARevenueCutAboveTheDebtLine(): void
    {
        $state = $this->governedBy([Diet::VANGUARD => 110.0, Diet::EXCHANGE => 45.0]);
        $state->importTariffRate = 0.08;

        Politics::enactBudget($state, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);

        $this->assertSame(0.0, $state->corporateTaxPolicyShift, 'The tax cut is never tabled.');
        $this->assertSame(0.08, $state->importTariffRate, 'Nor is the end of the tariff, which is revenue too.');
        $this->assertGreaterThan(MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE, $state->laborForceGrowthRate, 'The immigration regime costs no revenue and passes.');
        $this->assertSame($state->totalTime, $state->lastCouncilBrakeAt);
    }

    public function testTheCouncilLetsARevenueRiseThrough(): void
    {
        $state = $this->governedBy([Diet::CIVIC => 110.0, Diet::IRON_HARBOR => 45.0]);

        Politics::enactBudget($state, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);

        $platform = Politics::platform(Politics::coalitionPosition($state->governingCoalition, $state->dietSeats, $state->partyPositions));
        $this->assertGreaterThan(0.0, $platform['corporateTax']);
        $this->assertGreaterThan(0.0, $platform['tariff']);
        $this->assertSame($platform['corporateTax'], $state->corporateTaxPolicyShift);
        $this->assertSame($platform['tariff'], $state->importTariffRate);
        $this->assertSame(-1.0, $state->lastCouncilBrakeAt);
    }

    public function testAGovernmentThatCouldRemoveTheCouncilIsNotHeld(): void
    {
        $held = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 2.0, Diet::EXCHANGE => 1.0]);
        $free = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 1.0, Diet::EXCHANGE => 1.0]);

        Politics::enactBudget($held, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);
        Politics::enactBudget($free, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);

        $this->assertSame(0.0, $held->corporateTaxPolicyShift, 'One seat short of three quarters is held.');
        $this->assertLessThan(0.0, $free->corporateTaxPolicyShift, 'Three quarters is not.');
        $this->assertSame(-1.0, $free->lastCouncilBrakeAt);
    }

    /** The Council's loyalists never vote to remove a councillor, so their seats do not count toward three quarters; a supporter's do. */
    public function testTheLoyalistsDoNotCountTowardRemovalAndSupportersDo(): void
    {
        $line = MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05;
        $withLoyalists = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 20.0, Diet::CHARTISTS => 20.0]);
        $withExchange = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 20.0, Diet::EXCHANGE => 20.0]);
        $supported = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 20.0], [Diet::EXCHANGE => 20.0]);
        // A rate above the Exchange Party's own, so as a supporter it would vote for the cut: only the Council can hold it.
        $standing = Politics::POLICY_MANIFESTO_CORPORATE_TAX_GAP / 2.0;
        foreach ([$withLoyalists, $withExchange, $supported] as $state) {
            $state->corporateTaxPolicyShift = $standing;
            Politics::enactBudget($state, $line);
        }

        $this->assertSame($standing, $withLoyalists->corporateTaxPolicyShift, 'Three quarters counting the Chartists is held.');
        $this->assertLessThan($standing, $withExchange->corporateTaxPolicyShift);
        $this->assertLessThan($standing, $supported->corporateTaxPolicyShift, 'A supporter\'s seats count toward removal.');
    }

    public function testAtTheDebtLineItselfTheCouncilDoesNotStep(): void
    {
        $state = $this->governedBy([Diet::VANGUARD => 110.0, Diet::EXCHANGE => 45.0]);

        Politics::enactBudget($state, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD);

        $this->assertLessThan(0.0, $state->corporateTaxPolicyShift);
    }

    /**
     * A supporter accepts a lever no further from its own policy than the one in force (Romer & Rosenthal 1978): it
     * stops a move away from it, lets through one that comes its way or passes it by no more than it stood off, and a
     * cabinet with two supporters gets what both accept.
     */
    public function testASupporterAcceptsNothingFurtherFromItsOwnPolicyThanTheLeverInForce(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::HOME_POSITIONS;
        $standing = static fn(float $tax): array => ['corporateTax' => $tax] + Politics::standingLevers(new PoliticsStateDTO());
        $gap = Politics::POLICY_MANIFESTO_CORPORATE_TAX_GAP;
        $civicIdeal = Politics::platform(Diet::position(Diet::CIVIC, $positions))['corporateTax'];
        $vanguardIdeal = Politics::platform(Politics::coalitionPosition(Diet::membership([Diet::VANGUARD]), $seats, $positions))['corporateTax'];
        $this->assertEqualsWithDelta($gap / 2.0, $civicIdeal, 1e-12);
        $this->assertEqualsWithDelta(-$gap / 2.0, $vanguardIdeal, 1e-12);

        $blocked = Politics::budget([Diet::VANGUARD], [Diet::CIVIC], $seats, $positions, $standing(0.0), 0.5);
        $this->assertSame(0.0, $blocked['levers']['corporateTax'], 'The Civic Front will not vote the rate further from its own.');
        $this->assertTrue($blocked['supportHeld']['corporateTax']);
        $this->assertFalse($blocked['councilHeld']['corporateTax']);

        // The Exchange Party's own rate is just under the neutral one: from 3.5 points above neutral, a cut to the
        // Vanguard's 3.5 below is no further from the Exchange's rate than the rate in force stands.
        $exchangeIdeal = Politics::platform(Diet::position(Diet::EXCHANGE, $positions))['corporateTax'];
        $through = Politics::budget([Diet::VANGUARD], [Diet::EXCHANGE], $seats, $positions, $standing($gap / 2.0), 0.5);
        $this->assertEqualsWithDelta($vanguardIdeal, $through['levers']['corporateTax'], 1e-12);
        $this->assertFalse($through['supportHeld']['corporateTax']);

        $partial = Politics::budget([Diet::VANGUARD], [Diet::EXCHANGE], $seats, $positions, $standing(0.01), 0.5);
        $this->assertEqualsWithDelta($exchangeIdeal - (0.01 - $exchangeIdeal), $partial['levers']['corporateTax'], 1e-12, 'A cut goes only as far past the Exchange Party\'s rate as the rate in force stood above it.');
        $this->assertTrue($partial['supportHeld']['corporateTax']);

        $both = Politics::budget([Diet::VANGUARD], [Diet::EXCHANGE, Diet::CIVIC], $seats, $positions, $standing(0.01), 0.5);
        $this->assertEqualsWithDelta(0.01, $both['levers']['corporateTax'], 1e-12, 'With the Civic Front also needed, the rate cannot fall at all.');

        $majority = Politics::budget([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS], [], $seats, $positions, $standing(0.0), 0.5);
        $this->assertSame(array_fill_keys(array_keys(Politics::REVENUE_LEVERS), false), $majority['supportHeld'], 'A majority cabinet answers to no supporter.');
    }

    /**
     * Merger review runs along the Council axis, from the Common Lot's 2023 guidelines to the Chartists' 2010 ones; a
     * populist supporter can stop a technocratic cabinet loosening it, and the Council's brake, which guards revenue,
     * never holds it.
     */
    public function testMergerReviewRunsFromTheCommonLotsGuidelinesToTheChartists(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $leniency = static fn(float $council): float => Politics::platform([Diet::AXIS_COUNCIL => $council])['mergerReviewLeniency'];

        $this->assertSame(0.0, Politics::platform(Diet::position(Diet::COMMON_LOT, $positions))['mergerReviewLeniency']);
        $this->assertSame(1.0, Politics::platform(Diet::position(Diet::CHARTISTS, $positions))['mergerReviewLeniency']);
        $this->assertEqualsWithDelta(0.5, $leniency(0.0), 1e-12);
        $this->assertSame(1.0, $leniency(1.0), 'Nothing on record is more lenient than the 2010 guidelines.');
        $this->assertEqualsWithDelta((0.3 + 0.8) / 1.6, Politics::platform(Diet::position(Diet::VANGUARD, $positions))['mergerReviewLeniency'], 1e-12);

        $standing = Politics::standingLevers(new PoliticsStateDTO());
        $held = Politics::budget([Diet::VANGUARD], [Diet::COMMON_LOT], Diet::SEED_SEATS, $positions, $standing, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.1);
        $this->assertSame(0.0, $held['levers']['mergerReviewLeniency'], 'The Common Lot will not vote review looser than its own.');
        $this->assertTrue($held['supportHeld']['mergerReviewLeniency']);
        $this->assertFalse($held['councilHeld']['mergerReviewLeniency']);

        $loosened = Politics::budget([Diet::VANGUARD], [Diet::EXCHANGE], Diet::SEED_SEATS, $positions, $standing, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.1);
        $this->assertGreaterThan(0.0, $loosened['levers']['mergerReviewLeniency']);
        $this->assertFalse($loosened['councilHeld']['mergerReviewLeniency']);
    }

    public function testTheVoteReadsGrowthPerHead(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = $this->electionTick();

        $engine->advance($state, $this->economy(self::ELECTION_AT, laborForceGrowthRate: MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + 0.01), 0.01);

        $this->assertEqualsWithDelta(-0.01, $state->electionGrowthGap, 1e-9, 'Output growing only with the labour force is no growth per head.');
    }

    /**
     * The politics going into the first vote, with the campaign and term marks set so that, on the economy at its
     * openings on the day, growth and inflation run the given distance from trend and target.
     */
    private function electionTick(float $growthGap = 0.0, float $inflationGap = 0.0): PoliticsState
    {
        $state = new PoliticsState();
        $economy = $this->economy(self::ELECTION_AT);

        $trendGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT;
        $state->campaignStartedAt = self::ELECTION_AT - Politics::ELECTION_CAMPAIGN_WINDOW_YEARS;
        $state->campaignStartRealGdp = Politics::realGdp($economy) * exp(-($trendGrowth + $growthGap) * Politics::ELECTION_CAMPAIGN_WINDOW_YEARS);
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = $economy->gdpDeflator * exp(-(MacroEngine::TARGET_INFLATION + $inflationGap) * self::ELECTION_AT);

        return $state;
    }

    /** The tick of the first vote, on the economy at its openings. */
    private function vote(Politics $engine, PoliticsState $state): void
    {
        $engine->advance($state, $this->economy(self::ELECTION_AT), 0.01);
    }

    /** The economy as the macro hands it over: at its openings, on the given tick, debt and labour force growth. */
    private function economy(float $totalTime, float $debtToGdp = 0.5, float $laborForceGrowthRate = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE): MacroStateDTO
    {
        return new MacroStateDTO(totalTime: $totalTime, sovereignDebtToGdp: $debtToGdp, laborForceGrowthRate: $laborForceGrowthRate);
    }

    /**
     * Politics between votes with the given cabinet and supporters on the given seats and the founding positions.
     *
     * @param array<string, float> $seats   Seats of the cabinet's parties; the rest of the Diet holds none unless supporting.
     * @param array<string, float> $support Seats of the support parties.
     */
    private function governedBy(array $seats, array $support = []): PoliticsState
    {
        $state = new PoliticsState();
        $state->totalTime = 10.0;
        $state->dietSeats = [];
        foreach (Diet::PARTIES as $party) {
            $state->dietSeats[$party] = $seats[$party] ?? $support[$party] ?? 0.0;
        }
        $state->governingCoalition = Diet::membership(array_keys($seats));
        $state->supportParties = Diet::membership(array_keys($support));
        // The cabinet sees out the term.
        $state->cabinetFallsAt = 100.0;

        return $state;
    }

    /** The day after the first vote, with the talks settled on a Vanguard cabinet the Exchange Party and the Chartists support. */
    private function pendingGovernment(float $takesOfficeAt): PoliticsState
    {
        $economy = $this->economy(self::ELECTION_AT);
        $state = new PoliticsState();
        $state->totalTime = self::ELECTION_AT;
        $state->lastElectionAt = self::ELECTION_AT;
        $state->termStartedAt = self::ELECTION_AT;
        $state->termStartDeflator = $economy->gdpDeflator;
        $state->campaignStartedAt = self::ELECTION_AT - Politics::ELECTION_CAMPAIGN_WINDOW_YEARS;
        $state->campaignStartRealGdp = Politics::realGdp($economy);
        $state->pendingCoalition = Diet::membership([Diet::VANGUARD]);
        $state->pendingSupport = Diet::membership([Diet::EXCHANGE, Diet::CHARTISTS]);
        $state->coalitionTakesOfficeAt = $takesOfficeAt;

        return $state;
    }

    /** The same Diet a term later, going into the next vote, with the economy at trend over the term just ended. */
    private function nextElectionTick(PoliticsState $previous): PoliticsState
    {
        $next = $this->electionTick();
        $next->dietSeats = $previous->dietSeats;
        $next->dietVoteShares = $previous->dietVoteShares;
        $next->partyPositions = $previous->partyPositions;
        $next->governingCoalition = $previous->governingCoalition;
        $next->supportParties = $previous->supportParties;
        $next->partyShortTermShocks = $previous->partyShortTermShocks;

        return $next;
    }

    private function engine(MathUtility $draws): Politics
    {
        return new Politics($draws, $this->inMemoryRedis());
    }

    /** Every draw is zero: the vote is its economic part alone and positions hold. */
    private function quietMath(): MathUtility
    {
        return new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
        };
    }

    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }

    /**
     * The environment levers are none at all for a cabinet at or past the axis's middle toward growth and rise to the
     * strictest on record at the Tideline Accord's place: the green belt and the extraction rules from 0 to 1, the
     * carbon price from none to the EU ETS's. The founding cabinet, the Vanguard alone, enacts none of them.
     */
    public function testTheEnvironmentLeversRiseFromNoneToTheTidelineAccords(): void
    {
        $at = static fn(float $environment): array => Politics::platform([Diet::AXIS_ENVIRONMENT => $environment]);

        foreach ([-1.0, -0.5, 0.0] as $growth) {
            $this->assertSame(0.0, $at($growth)['greenBeltStringency']);
            $this->assertSame(0.0, $at($growth)['carbonPrice']);
            $this->assertSame(0.0, $at($growth)['extractionStringency']);
        }
        $this->assertEqualsWithDelta(0.5, $at(0.4)['greenBeltStringency'], 1e-12);
        $this->assertEqualsWithDelta(Politics::POLICY_GREEN_CARBON_PRICE / 2.0, $at(0.4)['carbonPrice'], 1e-12);
        $tideline = Politics::platform(Diet::position(Diet::TIDELINE, Diet::HOME_POSITIONS));
        $this->assertSame(1.0, $tideline['greenBeltStringency']);
        $this->assertSame(Politics::POLICY_GREEN_CARBON_PRICE, $tideline['carbonPrice']);
        $this->assertSame(1.0, $at(1.0)['extractionStringency'], 'Nothing on record is stricter.');

        $founding = Politics::platform(Politics::coalitionPosition(Diet::SEED_COALITION, Diet::SEED_SEATS, Diet::HOME_POSITIONS));
        $this->assertSame(0.0, $founding['greenBeltStringency'], 'The founding economy is the calibrated one.');
        $this->assertSame(0.0, $founding['carbonPrice']);
        $this->assertSame(0.0, $founding['extractionStringency']);
    }

    /** The standing levers, the platform, the revenue flags and the state fields all list the levers in one order, so a budget that changes nothing compares equal. */
    public function testEveryLeverListIsInPlatformOrder(): void
    {
        $order = array_keys(Politics::platform(Diet::HOME_POSITIONS[Diet::CIVIC]));

        $this->assertSame($order, array_keys(Politics::REVENUE_LEVERS));
        $this->assertSame($order, array_keys(Politics::LEVER_FIELDS));
        $this->assertSame($order, array_keys(Politics::LEVER_AXES));
        $this->assertSame($order, array_keys(Politics::standingLevers(new PoliticsStateDTO())));
        foreach (Politics::LEVER_FIELDS as $field) {
            $this->assertTrue(property_exists(PoliticsStateDTO::class, $field), "{$field} is kept on the state.");
            $this->assertTrue(property_exists(GovernmentPolicyDTO::class, $field), "{$field} is handed to the economy.");
        }
    }

    /** Each lever moves with the question it is listed under and with no other, from either side of the centre. */
    public function testEachLeverReadsOnlyItsOwnQuestion(): void
    {
        $centre = array_fill_keys(Diet::AXES, 0.0);
        foreach (Diet::AXES as $axis) {
            $towards = [Politics::platform([$axis => -0.4] + $centre), Politics::platform([$axis => 0.4] + $centre)];
            foreach (Politics::LEVER_AXES as $lever => $own) {
                $moved = $towards[0][$lever] !== $towards[1][$lever];
                $this->assertSame($own === $axis, $moved, "{$lever} " . ($moved ? 'moves' : 'does not move') . " with {$axis}.");
            }
        }
    }

    /**
     * The Tideline Accord, propping up a Civic cabinet, will not vote the carbon price down toward the Front's own; and
     * above the debt line the Council holds a carbon cut, which costs revenue, but never a green belt, which does not.
     */
    public function testTheAccordAndTheCouncilEachHoldTheCarbonPrice(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::HOME_POSITIONS;
        $standing = ['carbonPrice' => 50.0, 'greenBeltStringency' => 0.9] + Politics::standingLevers(new PoliticsStateDTO());
        $civicCarbon = Politics::platform(Diet::position(Diet::CIVIC, $positions))['carbonPrice'];
        $this->assertLessThan(50.0, $civicCarbon);

        $propped = Politics::budget([Diet::CIVIC], [Diet::TIDELINE], $seats, $positions, $standing, 0.5);
        $this->assertSame(50.0, $propped['levers']['carbonPrice'], 'The Accord accepts nothing further from its own price.');
        $this->assertTrue($propped['supportHeld']['carbonPrice']);

        $braked = Politics::budget([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS, Diet::NEW_HORIZON], [], $seats, $positions, $standing, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);
        $this->assertTrue($braked['councilHeld']['carbonPrice']);
        $this->assertSame(50.0, $braked['levers']['carbonPrice']);
        $this->assertFalse($braked['councilHeld']['greenBeltStringency']);
        $this->assertSame(0.0, $braked['levers']['greenBeltStringency'], 'A growth-first majority lifts the green belt whatever the debt.');
    }

    /**
     * The stamp duty runs along the size-of-state axis from the founding 0.05% a side at the Vanguard's end to France's
     * 0.4% on purchases, 0.2% a side, at the Civic Front's; it goes to the reserve fund, so the Council does not guard it.
     */
    public function testTheStampDutyRunsFromTheFoundingRateToFrancesTax(): void
    {
        $positions = Diet::HOME_POSITIONS;

        $this->assertEqualsWithDelta(FinancialConstants::STAMP_DUTY_RATE, Politics::platform(Diet::position(Diet::VANGUARD, $positions))['stampDutyRate'], 1e-15, 'The founding cabinet keeps the founding duty.');
        $this->assertEqualsWithDelta(Politics::POLICY_BIG_STATE_STAMP_DUTY, Politics::platform(Diet::position(Diet::CIVIC, $positions))['stampDutyRate'], 1e-15);
        $this->assertEqualsWithDelta(0.00125, Politics::platform([Diet::AXIS_STATE => 0.0])['stampDutyRate'], 1e-15);
        $this->assertFalse(Politics::REVENUE_LEVERS['stampDutyRate']);
    }

    /** The bank levy runs from none at the Vanguard's end to the UK's 2015 peak at the Civic Front's, and the Council guards it as revenue. */
    public function testTheBankLevyRunsFromNoneToTheUksPeak(): void
    {
        $positions = Diet::HOME_POSITIONS;

        $this->assertEqualsWithDelta(0.0, Politics::platform(Diet::position(Diet::VANGUARD, $positions))['bankLevyRate'], 1e-15, 'The founding cabinet levies none.');
        $this->assertEqualsWithDelta(0.0021, Politics::platform(Diet::position(Diet::CIVIC, $positions))['bankLevyRate'], 1e-15);
        $this->assertTrue(Politics::REVENUE_LEVERS['bankLevyRate']);
    }

    /**
     * The share of the reserve fund's return spent runs from the founding half at the Vanguard's end to 60% at the Civic
     * Front's. The fund holds the second key on anything above half: it consents only while unemployment shows a recession under way,
     * and out of one the share above lapses, whatever the Council would say of the revenue.
     */
    public function testTheFundHoldsTheSecondKeyOnTheDrawAboveHalf(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $this->assertEqualsWithDelta(MacroEngine::RESERVE_DRAW_CEILING, Politics::platform(Diet::position(Diet::VANGUARD, $positions))['reserveDrawShare'], 1e-15);
        $this->assertEqualsWithDelta(Politics::POLICY_BIG_STATE_RESERVE_DRAW_SHARE, Politics::platform(Diet::position(Diet::CIVIC, $positions))['reserveDrawShare'], 1e-15);
        $this->assertTrue(Politics::REVENUE_LEVERS['reserveDrawShare']);

        $founding = Politics::standingLevers(new PoliticsStateDTO());
        $refused = Politics::budget([Diet::CIVIC], [], Diet::SEED_SEATS, $positions, $founding, 0.5);
        $this->assertSame(MacroEngine::RESERVE_DRAW_CEILING, $refused['levers']['reserveDrawShare'], 'Out of a recession the fund refuses a draw above half.');
        $this->assertTrue($refused['fundHeld']);
        $this->assertEqualsWithDelta(Politics::POLICY_BIG_STATE_RESERVE_DRAW_SHARE, $refused['platform']['reserveDrawShare'], 1e-15);

        $consented = Politics::budget([Diet::CIVIC], [], Diet::SEED_SEATS, $positions, $founding, 0.5, recession: true);
        $this->assertEqualsWithDelta(Politics::POLICY_BIG_STATE_RESERVE_DRAW_SHARE, $consented['levers']['reserveDrawShare'], 1e-15, 'In a declared recession it consents.');
        $this->assertFalse($consented['fundHeld']);

        // The slump over, the draw goes back to half even above the debt line, where the Council holds any other cut in revenue.
        $drawn = Politics::standingLevers(new PoliticsStateDTO(reserveDrawShare: Politics::POLICY_BIG_STATE_RESERVE_DRAW_SHARE));
        $lapsed = Politics::budget([Diet::VANGUARD], [], Diet::SEED_SEATS, $positions, $drawn, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.1);
        $this->assertTrue($lapsed['councilGuards']);
        $this->assertSame(MacroEngine::RESERVE_DRAW_CEILING, $lapsed['levers']['reserveDrawShare']);
        $this->assertFalse($lapsed['councilHeld']['reserveDrawShare']);
        $this->assertTrue($lapsed['fundHeld']);

        // Spending less than half needs no consent, and the brake holds it as any revenue.
        $kept = Politics::budget([Diet::VANGUARD], [], Diet::SEED_SEATS, $positions, $founding, MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.1);
        $this->assertSame(MacroEngine::RESERVE_DRAW_CEILING, $kept['levers']['reserveDrawShare']);
        $this->assertFalse($kept['fundHeld']);
    }
}
