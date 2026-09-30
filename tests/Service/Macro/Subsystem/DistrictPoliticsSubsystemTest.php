<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem;

use App\Data\AerieDiet as Diet;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\DistrictPoliticsSubsystem as Politics;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class DistrictPoliticsSubsystemTest extends TestCase
{
    /** The first vote on the calendar. */
    private const ELECTION_AT = MacroEngine::ELECTION_TERM_YEARS;

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

    /** Each party stands fixed on the axis it is defined by, at its founding place on the others. */
    public function testEachPartyIsFixedOnTheAxisItIsDefinedBy(): void
    {
        $drifted = [Diet::CHARTISTS => [Diet::AXIS_COUNCIL => -0.5, Diet::AXIS_STATE => 0.4]];

        $this->assertSame(Diet::PRIMARY_POSITION[Diet::CHARTISTS], Diet::position(Diet::CHARTISTS, $drifted)[Diet::AXIS_COUNCIL], 'A stored value cannot move a party off its own axis.');
        $this->assertSame(0.4, Diet::position(Diet::CHARTISTS, $drifted)[Diet::AXIS_STATE]);
        $this->assertSame(Diet::SEED_POSITIONS[Diet::CHARTISTS][Diet::AXIS_OPENNESS], Diet::position(Diet::CHARTISTS, $drifted)[Diet::AXIS_OPENNESS], 'A missing axis is the founding place.');
        $this->assertEqualsCanonicalizing(Diet::AXES, array_values(array_unique(Diet::PRIMARY_AXIS)), 'Every axis has parties defined by it.');
        foreach (Diet::PARTIES as $party) {
            $this->assertSame(Diet::PRIMARY_POSITION[$party], Diet::SEED_POSITIONS[$party][Diet::PRIMARY_AXIS[$party]]);
        }
    }

    public function testTheGovernmentsPositionIsItsPartiesWeightedBySeats(): void
    {
        $position = Politics::coalitionPosition(Diet::SEED_COALITION, Diet::SEED_SEATS, Diet::SEED_POSITIONS);

        // Vanguard 95 at (-0.6, 0, +0.3), Exchange 40 at (0, +0.8, +0.4), Chartists 20 at (0, +0.2, +0.8).
        $this->assertEqualsWithDelta(95.0 * -0.6 / 155.0, $position[Diet::AXIS_STATE], 1e-12);
        $this->assertEqualsWithDelta((40.0 * 0.8 + 20.0 * 0.2) / 155.0, $position[Diet::AXIS_OPENNESS], 1e-12);
        $this->assertEqualsWithDelta((95.0 * 0.3 + 40.0 * 0.4 + 20.0 * 0.8) / 155.0, $position[Diet::AXIS_COUNCIL], 1e-12);
    }

    // --- The vote ---

    public function testTheEconomicVoteCarriesFairsSlopesLessTheCostOfRuling(): void
    {
        $atTrend = Politics::economicVote(0.0, 0.0, 0.0);

        $this->assertEqualsWithDelta(-MacroEngine::ELECTION_COST_OF_RULING, $atTrend, 1e-12);
        $this->assertEqualsWithDelta(MacroEngine::ELECTION_GROWTH_SLOPE * 0.01, Politics::economicVote(0.01, 0.0, 0.0) - $atTrend, 1e-12);
        $this->assertEqualsWithDelta(-MacroEngine::ELECTION_INFLATION_SLOPE * 0.01, Politics::economicVote(0.0, 0.01, 0.0) - $atTrend, 1e-12);
        $this->assertEqualsWithDelta(MacroEngine::ELECTION_RESIDUAL_SD, Politics::economicVote(0.0, 0.0, 1.0) - $atTrend, 1e-12);
    }

    /**
     * Vote after vote at a trend economy, each cabinet taking office after its talks: governments lose about what the
     * record's governments lose, the Diet is about two thirds as volatile as Western Europe's parliaments, no party
     * drifts into a majority of its own, and the talks run as real ones have -- about a third need a second attempt
     * (Golder 2010), and they last about a month (Bäck, Hellström, Lindvall & Teorell 2023: 33.7 days, sd 33.9).
     */
    public function testTheDietStaysBalancedAtItsCalibratedVolatility(): void
    {
        mt_srand(23);
        $subsystem = new Politics(new MathUtility());
        $volatility = [];
        $incumbentSwings = [];
        $singlePartyMajority = 0;
        $talkDays = [];
        $retried = 0;
        for ($run = 0; $run < 60; ++$run) {
            $state = $this->electionTick();
            for ($vote = 0; $vote < 40; ++$vote) {
                $subsystem->update($state, 0.01);
                Politics::takeOffice($state);
                // The first vote has no earlier short-term swing to give back, so it is not yet the steady state.
                if ($vote > 0) {
                    $volatility[] = Politics::pedersenVolatility($state->dietVoteSwings);
                    $incumbentSwings[] = $state->electionIncumbentSwing;
                    $singlePartyMajority += max($state->dietSeats) >= Diet::MAJORITY_SEATS ? 1 : 0;
                    $talkDays[] = $state->formationLog === [] ? 0.0 : $state->formationLog[array_key_last($state->formationLog)]['day'];
                    $retried += count($state->formationLog) > 1 ? 1 : 0;
                }
                $state = $this->nextElectionTick($state);
            }
        }

        $meanDays = array_sum($talkDays) / count($talkDays);
        $this->assertEqualsWithDelta(0.32, $retried / count($talkDays), 0.04);
        $this->assertEqualsWithDelta(33.7, $meanDays, 3.0);
        $this->assertEqualsWithDelta(MacroEngine::FORMATION_MEAN_DAYS, $meanDays, 3.0, 'The uncertainty index is compensated for talks of another length.');
        $this->assertEqualsWithDelta(33.9, sqrt(array_sum(array_map(static fn(float $d): float => ($d - $meanDays) ** 2, $talkDays)) / count($talkDays)), 5.0);

        $observedLoss = -array_sum($incumbentSwings) / count($incumbentSwings);
        $this->assertEqualsWithDelta(MacroEngine::ELECTION_RECORDED_COST_OF_RULING, $observedLoss, 0.0075);
        // Dassonneville & Hooghe (2017): mean Pedersen index 10 over 21 Western European countries, 1950-2013.
        $this->assertEqualsWithDelta(0.068, array_sum($volatility) / count($volatility), 0.01);
        $this->assertLessThan(0.05, $singlePartyMajority / count($volatility), 'The party system has drifted toward one-party rule.');
    }

    /** A party's short-term swing is given back whole at the next vote. */
    public function testAShortTermSwingIsUndoneAtTheNextVote(): void
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

    /** A boom over the campaign returns the government stronger; inflation over the term costs it. */
    public function testGrowthAndInflationMoveTheGovernmentsVote(): void
    {
        $subsystem = new Politics($this->quietMath());

        $trend = $this->electionTick();
        $subsystem->update($trend, 0.01);
        $boom = $this->electionTick(growthGap: 0.03);
        $subsystem->update($boom, 0.01);
        $inflation = $this->electionTick(inflationGap: 0.03);
        $subsystem->update($inflation, 0.01);

        $this->assertEqualsWithDelta(0.03, $boom->electionGrowthGap, 1e-9);
        $this->assertEqualsWithDelta(0.03, $inflation->electionInflationGap, 1e-9);
        $this->assertGreaterThan($trend->electionIncumbentSwing, $boom->electionIncumbentSwing);
        $this->assertLessThan($trend->electionIncumbentSwing, $inflation->electionIncumbentSwing);
    }

    /** The opposition takes what the government loses, each opposition party in proportion to its own share. */
    public function testTheSwingIsSharedInProportion(): void
    {
        $shares = Politics::applyIncumbentSwing(Diet::SEED_VOTE_SHARES, Diet::SEED_COALITION, -0.04);
        $seed = Diet::SEED_VOTE_SHARES;

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        $this->assertEqualsWithDelta(155.0 / 300.0 - 0.04, $shares[Diet::VANGUARD] + $shares[Diet::EXCHANGE] + $shares[Diet::CHARTISTS], 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::CIVIC] / $seed[Diet::IRON_HARBOR], $shares[Diet::CIVIC] / $shares[Diet::IRON_HARBOR], 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::VANGUARD] / $seed[Diet::EXCHANGE], $shares[Diet::VANGUARD] / $shares[Diet::EXCHANGE], 1e-12);
    }

    /** A party supporting the cabinet from outside shares the government's swing by the accountability voters hold it to. */
    public function testASupportPartySharesTheSwingByItsAccountability(): void
    {
        $seed = Diet::SEED_VOTE_SHARES;
        $cabinet = Diet::membership([Diet::VANGUARD]);
        $support = Diet::membership([Diet::EXCHANGE]);
        $weight = MacroEngine::ELECTION_SUPPORT_ACCOUNTABILITY;
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

    // --- After a financial crisis ---

    /** A crisis inside the window lifts the Iron Harbor Coalition 30%; one outside it does nothing. */
    public function testACrisisLiftsTheClosedEconomyPartyOnlyInsideItsWindow(): void
    {
        $subsystem = new Politics($this->quietMath());

        $calm = $this->electionTick();
        $subsystem->update($calm, 0.01);
        $recent = $this->electionTick();
        $recent->lastCreditCrisisAt = self::ELECTION_AT - 2.0;
        $subsystem->update($recent, 0.01);
        $old = $this->electionTick();
        $old->lastCreditCrisisAt = self::ELECTION_AT - MacroEngine::ELECTION_CRISIS_WINDOW_YEARS - 0.5;
        $subsystem->update($old, 0.01);

        $this->assertEqualsWithDelta(
            1.0 + MacroEngine::ELECTION_CRISIS_CLOSED_PARTY_LIFT,
            $recent->dietVoteShares[Diet::IRON_HARBOR] / $calm->dietVoteShares[Diet::IRON_HARBOR],
            1e-9
        );
        $this->assertGreaterThan(0.0, $recent->ironHarborCrisisShift);
        $this->assertSame($calm->dietVoteShares, $old->dietVoteShares);
        $this->assertSame(0.0, $old->ironHarborCrisisShift);
    }

    /** The lift is given back whole: returning it restores every party's share. */
    public function testTheCrisisLiftIsGivenBackWhole(): void
    {
        [$lifted, $shift] = Politics::applyCrisisShift(Diet::SEED_VOTE_SHARES);
        $restored = Politics::returnCrisisShift($lifted, $shift);

        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_VOTE_SHARES[$party], $restored[$party], 1e-12);
        }
    }

    // --- Positions ---

    /** Each vote moves a party on both axes it is not defined by, by Somer-Topcu's step, and never off them. */
    public function testPositionsDriftAtTheManifestoScaleOnBothFreeAxes(): void
    {
        mt_srand(3);
        $subsystem = new Politics(new MathUtility());
        $steps = [Diet::AXIS_OPENNESS => [], Diet::AXIS_COUNCIL => []];
        for ($i = 0; $i < 3000; ++$i) {
            $state = $this->electionTick();
            $subsystem->update($state, 0.01);
            foreach ($steps as $axis => $unused) {
                $steps[$axis][] = $state->partyPositions[Diet::VANGUARD][$axis] - Diet::SEED_POSITIONS[Diet::VANGUARD][$axis];
            }
            $this->assertSame(Diet::PRIMARY_POSITION[Diet::VANGUARD], $state->partyPositions[Diet::VANGUARD][Diet::AXIS_STATE]);
        }
        foreach ($steps as $axis => $axisSteps) {
            $sd = sqrt(array_sum(array_map(static fn(float $s): float => $s ** 2, $axisSteps)) / count($axisSteps));
            $this->assertEqualsWithDelta(Politics::POSITION_DRIFT_PER_ELECTION, $sd, 0.01, "The {$axis} step is off the manifesto scale.");
        }

        $state = $this->electionTick();
        for ($vote = 0; $vote < 300; ++$vote) {
            $state->lastElectionAt = $state->totalTime;
            $subsystem->update($state, 0.01);
            Politics::takeOffice($state);
            foreach (Diet::PARTIES as $party) {
                foreach (Diet::AXES as $axis) {
                    $this->assertGreaterThanOrEqual(-1.0, $state->partyPositions[$party][$axis]);
                    $this->assertLessThanOrEqual(1.0, $state->partyPositions[$party][$axis]);
                }
                $this->assertSame(Diet::PRIMARY_POSITION[$party], $state->partyPositions[$party][Diet::PRIMARY_AXIS[$party]]);
            }
            $this->assertSame((float) Diet::SEATS, array_sum($state->dietSeats));
            $state->totalTime += MacroEngine::ELECTION_TERM_YEARS;
        }
    }

    /** A step past the end of the axis comes back off it by the overshoot, rather than sticking at the end. */
    public function testAStepPastTheEndOfTheAxisIsReflected(): void
    {
        $subsystem = new Politics(new class extends MathUtility {
            public function generateStandardNormal(): float { return 1.0; }
        });
        $state = $this->electionTick();
        $state->partyPositions[Diet::VANGUARD][Diet::AXIS_OPENNESS] = 0.95;

        $subsystem->update($state, 0.01);

        $this->assertEqualsWithDelta(2.0 - (0.95 + Politics::POSITION_DRIFT_PER_ELECTION), $state->partyPositions[Diet::VANGUARD][Diet::AXIS_OPENNESS], 1e-12);
    }

    // --- The calendar and the talks ---

    /** The vote is held on the calendar's tick only; it opens the talks, and the outgoing cabinet stays on meanwhile. */
    public function testTheVoteIsHeldOnlyOnTheCalendarsElectionTick(): void
    {
        $subsystem = new Politics($this->quietMath());

        $between = $this->electionTick();
        $between->lastElectionAt = 0.0;
        $subsystem->update($between, 0.01);
        $this->assertSame(Diet::SEED_VOTE_SHARES, $between->dietVoteShares);

        $onTheDay = $this->electionTick();
        $subsystem->update($onTheDay, 0.01);
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

    /** A party that wins a majority of its own governs alone from the day of the vote. */
    public function testAMajorityWonOutrightTakesOfficeOnTheDay(): void
    {
        $subsystem = new Politics($this->quietMath());
        $state = $this->electionTick();
        $state->dietVoteShares = [Diet::CIVIC => 0.15, Diet::VANGUARD => 0.60, Diet::IRON_HARBOR => 0.08, Diet::EXCHANGE => 0.08, Diet::CHARTISTS => 0.05, Diet::COMMON_LOT => 0.04];

        $subsystem->update($state, 0.01);

        $this->assertGreaterThanOrEqual(Diet::MAJORITY_SEATS, $state->dietSeats[Diet::VANGUARD]);
        $this->assertSame([Diet::VANGUARD], Diet::governingParties($state->governingCoalition));
        $this->assertSame([], Diet::governingParties($state->supportParties));
        $this->assertSame([], $state->formationLog);
        $this->assertSame(self::ELECTION_AT, $state->coalitionFormedAt);
        $this->assertSame(self::ELECTION_AT, $state->lastGovernmentFormedAt);
        $this->assertSame(-1.0, $state->coalitionTakesOfficeAt);
    }

    /**
     * The talks weigh the outgoing cabinet's incumbency: with the grand coalition going into the vote, it is the Vanguard's
     * likeliest majority, where from the founding cabinet it is not.
     */
    public function testTheTalksWeighTheOutgoingCabinet(): void
    {
        $firstAttemptFails = static fn(): MathUtility => new class extends MathUtility {
            private int $draw = 0;
            public function generateStandardNormal(): float { return 0.0; }
            // Attempt one: its length, then a draw nothing clears; attempt two: its length, a sure success, the first cabinet.
            public function generateUniform(): float { return [0.5, 0.9999999, 0.5, 0.0, 0.0][$this->draw++] ?? 0.0; }
        };

        $grand = $this->electionTick();
        $grand->governingCoalition = Diet::membership([Diet::CIVIC, Diet::VANGUARD]);
        (new Politics($firstAttemptFails()))->update($grand, 0.01);
        $founding = $this->electionTick();
        (new Politics($firstAttemptFails()))->update($founding, 0.01);

        $this->assertFalse($grand->formationLog[0]['formed']);
        $this->assertSame([Diet::CIVIC, Diet::VANGUARD], $grand->formationLog[0]['cabinet']);
        $this->assertSame(Diet::governingParties(Diet::SEED_COALITION), $founding->formationLog[0]['cabinet']);
    }

    /** The cabinet the talks produced takes office on the first tick at or past its day, supporters with it. */
    public function testTheCabinetTakesOfficeOnTheFirstTickAtOrPastItsDay(): void
    {
        $subsystem = new Politics($this->quietMath());
        $state = $this->pendingGovernment(takesOfficeAt: 4.1);

        $state->totalTime = 4.09;
        $subsystem->update($state, 0.01);
        $this->assertSame(Diet::SEED_COALITION, $state->governingCoalition);

        $state->totalTime = 4.1;
        $subsystem->update($state, 0.01);
        $this->assertSame([Diet::VANGUARD], Diet::governingParties($state->governingCoalition));
        $this->assertSame([Diet::EXCHANGE, Diet::CHARTISTS], Diet::governingParties($state->supportParties));
        $this->assertSame(4.1, $state->coalitionFormedAt);
        $this->assertSame(4.1, $state->lastGovernmentFormedAt);
        $this->assertSame(-1.0, $state->coalitionTakesOfficeAt);
    }

    /** A caretaker passes no budget, however long the talks run; the new cabinet legislates at the round after it takes office. */
    public function testTheCaretakerPassesNoBudget(): void
    {
        $subsystem = new Politics($this->quietMath());
        $state = $this->pendingGovernment(takesOfficeAt: 4.6);
        // A majority cabinet, so nothing but the talks stands between it and its budget.
        $state->pendingCoalition = Diet::SEED_COALITION;
        $state->pendingSupport = Diet::SEED_SUPPORT;
        $state->governingCoalition = Diet::membership([Diet::CIVIC, Diet::IRON_HARBOR]);
        $state->sovereignDebtToGdp = 0.5;

        $state->totalTime = 4.5;
        $subsystem->update($state, 0.01);
        $this->assertSame(-1.0, $state->lastBudgetEnactedAt, 'The caretaker passed a budget.');

        $state->totalTime = 4.6;
        $subsystem->update($state, 0.01);
        $this->assertSame(4.6, $state->coalitionFormedAt);

        $state->totalTime = 5.0;
        $subsystem->update($state, 0.01);
        $this->assertSame(5.0, $state->lastBudgetEnactedAt);
    }

    /** The campaign opens on the tick that crosses nine months before the vote. */
    public function testTheCampaignMarkFallsNineMonthsBeforeTheVote(): void
    {
        $subsystem = new Politics($this->quietMath());
        $state = new MacroState();
        $state->campaignStartedAt = 0.0;
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = 1.0;

        // Ticks of 0.01y, the last before the campaign and the one it opens on.
        $campaignOpens = self::ELECTION_AT - MacroEngine::ELECTION_CAMPAIGN_WINDOW_YEARS;
        $state->totalTime = $campaignOpens - 0.01;
        $subsystem->update($state, 0.01);
        $this->assertSame(0.0, $state->campaignStartedAt);

        $state->totalTime = $campaignOpens;
        $subsystem->update($state, 0.01);
        $this->assertSame($campaignOpens, $state->campaignStartedAt);
        $this->assertSame(Politics::realGdp($state), $state->campaignStartRealGdp);
    }

    /** Through the engine: a vote every term, and each cabinet the talks produce takes office on its day. */
    public function testTheEngineHoldsAVoteEveryTerm(): void
    {
        mt_srand(17);
        $math = new MathUtility();
        $engine = new MacroEngine(
            $math,
            $this->inMemoryRedis(),
            new MacroSnapshotRecorder(),
            new MonetaryPolicySubsystem($math),
            new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($math),
            new CommodityLogisticsSubsystem($math),
            new AssetMarketSubsystem($math),
            new CreditFiscalSubsystem($math),
            politicsSubsystem: new Politics(MathUtility::ownStream(17)),
        );

        $votes = 0;
        $formed = [];
        $pending = null;
        $ticksPerYear = 52;
        for ($tick = 0; $tick < 9 * $ticksPerYear; ++$tick) {
            $macro = $engine->updateMacroState(1.0 / $ticksPerYear);
            if ($macro->lastElectionAt === $macro->totalTime) {
                ++$votes;
                $this->assertNotSame([], $macro->dietVoteSwings);
                $this->assertSame((float) Diet::SEATS, array_sum($macro->dietSeats));
                $pending = [$macro->coalitionTakesOfficeAt, $macro->pendingCoalition];
            }
            if ($macro->lastGovernmentFormedAt === $macro->totalTime && $pending !== null) {
                $this->assertGreaterThanOrEqual($pending[0], $macro->totalTime);
                $this->assertLessThan($pending[0] + (1.0 / $ticksPerYear), $macro->totalTime, 'The cabinet took office later than the first tick past its day.');
                $this->assertSame($pending[1], $macro->governingCoalition);
                $formed[] = $macro->totalTime;
            }
        }

        $this->assertSame(2, $votes);
        $this->assertCount(2, $formed);
    }

    // --- The Budget ---

    /** Each lever runs between the policies of the parties at the two ends of its axis. */
    public function testThePlatformRunsBetweenThePartiesAtTheEndsOfEachAxis(): void
    {
        $civic = Politics::platform([Diet::AXIS_STATE => Diet::PRIMARY_POSITION[Diet::CIVIC], Diet::AXIS_OPENNESS => 0.0]);
        $vanguard = Politics::platform([Diet::AXIS_STATE => Diet::PRIMARY_POSITION[Diet::VANGUARD], Diet::AXIS_OPENNESS => 0.0]);
        $this->assertEqualsWithDelta(MacroEngine::POLICY_MANIFESTO_CORPORATE_TAX_GAP, $civic['corporateTax'] - $vanguard['corporateTax'], 1e-12, 'The two big parties are a manifesto gap apart.');
        $this->assertEqualsWithDelta(0.0, $civic['corporateTax'] + $vanguard['corporateTax'], 1e-12, 'The gap is centred on the neutral rate.');

        $harbor = Politics::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => Diet::PRIMARY_POSITION[Diet::IRON_HARBOR]]);
        $exchange = Politics::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => Diet::PRIMARY_POSITION[Diet::EXCHANGE]]);
        $this->assertEqualsWithDelta(MacroEngine::POLICY_PROTECTIONIST_TARIFF, $harbor['tariff'], 1e-12, 'The protectionist party enacts its own tariff.');
        $this->assertSame(0.0, $exchange['tariff']);
        $this->assertEqualsWithDelta(MacroEngine::MIGRATION_OPEN_REGIME - MacroEngine::MIGRATION_CLOSED_REGIME, $exchange['laborGrowth'] - $harbor['laborGrowth'], 1e-12, 'The two immigration regimes are a regime apart.');
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
        $pole = Politics::platform([Diet::AXIS_STATE => Diet::PRIMARY_POSITION[Diet::CIVIC], Diet::AXIS_OPENNESS => Diet::PRIMARY_POSITION[Diet::IRON_HARBOR]]);

        $this->assertSame($pole, Politics::platform([Diet::AXIS_STATE => 1.0, Diet::AXIS_OPENNESS => -1.0]));
        $this->assertSame(
            Politics::platform([Diet::AXIS_STATE => Diet::PRIMARY_POSITION[Diet::VANGUARD], Diet::AXIS_OPENNESS => Diet::PRIMARY_POSITION[Diet::EXCHANGE]]),
            Politics::platform([Diet::AXIS_STATE => -1.0, Diet::AXIS_OPENNESS => 1.0])
        );
    }

    public function testAGovernmentLegislatesAtTheRoundAfterItTakesOffice(): void
    {
        $subsystem = new Politics($this->quietMath());
        $state = $this->pendingGovernment(takesOfficeAt: 4.1);
        $state->pendingCoalition = Diet::SEED_COALITION;
        $state->pendingSupport = Diet::SEED_SUPPORT;
        $state->sovereignDebtToGdp = 0.5;

        $state->totalTime = 4.1;
        $subsystem->update($state, 0.01);
        $this->assertSame(0.0, $state->corporateTaxPolicyShift, 'Nothing is enacted on the tick the government takes office,');

        $state->totalTime = 4.5 - 0.01;
        $subsystem->update($state, 0.01);
        $this->assertSame(0.0, $state->corporateTaxPolicyShift, 'nor between rounds.');

        $state->totalTime = 4.5;
        $subsystem->update($state, 0.01);
        $platform = Politics::platform(Politics::coalitionPosition($state->governingCoalition, $state->dietSeats, $state->partyPositions));
        $this->assertLessThan(0.0, $platform['corporateTax'], 'The Vanguard-led government cuts the rate');
        $this->assertSame($platform['corporateTax'], $state->corporateTaxPolicyShift, 'and its first budget enacts the cut,');
        $this->assertSame($platform['tariff'], $state->importTariffRate);
        $this->assertSame($platform['laborGrowth'], $state->laborForceGrowthRate);
        $this->assertSame($state->totalTime, $state->lastBudgetEnactedAt);
        $this->assertSame(-1.0, $state->lastCouncilBrakeAt, 'with the debt below the line.');
    }

    public function testTheCouncilHoldsARevenueCutAboveTheDebtLine(): void
    {
        $state = $this->governedBy([Diet::VANGUARD => 110.0, Diet::EXCHANGE => 45.0], MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);
        $state->importTariffRate = 0.08;

        Politics::enactBudget($state);

        $this->assertSame(0.0, $state->corporateTaxPolicyShift, 'The tax cut is never tabled.');
        $this->assertSame(0.08, $state->importTariffRate, 'Nor is the end of the tariff, which is revenue too.');
        $this->assertGreaterThan(MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE, $state->laborForceGrowthRate, 'The immigration regime costs no revenue and passes.');
        $this->assertSame($state->totalTime, $state->lastCouncilBrakeAt);
    }

    public function testTheCouncilLetsARevenueRiseThrough(): void
    {
        $state = $this->governedBy([Diet::CIVIC => 110.0, Diet::IRON_HARBOR => 45.0], MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);

        Politics::enactBudget($state);

        $platform = Politics::platform(Politics::coalitionPosition($state->governingCoalition, $state->dietSeats, $state->partyPositions));
        $this->assertGreaterThan(0.0, $platform['corporateTax']);
        $this->assertGreaterThan(0.0, $platform['tariff']);
        $this->assertSame($platform['corporateTax'], $state->corporateTaxPolicyShift);
        $this->assertSame($platform['tariff'], $state->importTariffRate);
        $this->assertSame(-1.0, $state->lastCouncilBrakeAt);
    }

    public function testAGovernmentThatCouldRemoveTheCouncilIsNotHeld(): void
    {
        $held = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 2.0, Diet::EXCHANGE => 1.0], MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);
        $free = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 1.0, Diet::EXCHANGE => 1.0], MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05);

        Politics::enactBudget($held);
        Politics::enactBudget($free);

        $this->assertSame(0.0, $held->corporateTaxPolicyShift, 'One seat short of three quarters is held.');
        $this->assertLessThan(0.0, $free->corporateTaxPolicyShift, 'Three quarters is not.');
        $this->assertSame(-1.0, $free->lastCouncilBrakeAt);
    }

    /** The Council's loyalists never vote to remove a councillor, so their seats do not count toward three quarters; a supporter's do. */
    public function testTheLoyalistsDoNotCountTowardRemovalAndSupportersDo(): void
    {
        $line = MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.05;
        $withLoyalists = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 20.0, Diet::CHARTISTS => 20.0], $line);
        $withExchange = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 20.0, Diet::EXCHANGE => 20.0], $line);
        $supported = $this->governedBy([Diet::VANGUARD => Diet::SUPERMAJORITY_SEATS - 20.0], $line, [Diet::EXCHANGE => 20.0]);
        // A rate above the Exchange Party's own, so as a supporter it would vote for the cut: only the Council can hold it.
        $standing = MacroEngine::POLICY_MANIFESTO_CORPORATE_TAX_GAP / 2.0;
        foreach ([$withLoyalists, $withExchange, $supported] as $state) {
            $state->corporateTaxPolicyShift = $standing;
            Politics::enactBudget($state);
        }

        $this->assertSame($standing, $withLoyalists->corporateTaxPolicyShift, 'Three quarters counting the Chartists is held.');
        $this->assertLessThan($standing, $withExchange->corporateTaxPolicyShift);
        $this->assertLessThan($standing, $supported->corporateTaxPolicyShift, 'A supporter\'s seats count toward removal.');
    }

    public function testAtTheDebtLineItselfTheCouncilDoesNotStep(): void
    {
        $state = $this->governedBy([Diet::VANGUARD => 110.0, Diet::EXCHANGE => 45.0], MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD);

        Politics::enactBudget($state);

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
        $positions = Diet::SEED_POSITIONS;
        $standing = static fn(float $tax): array => ['corporateTax' => $tax, 'tariff' => 0.0, 'laborGrowth' => MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE];
        $gap = MacroEngine::POLICY_MANIFESTO_CORPORATE_TAX_GAP;
        $civicIdeal = Politics::platform(Diet::position(Diet::CIVIC, $positions))['corporateTax'];
        $vanguardIdeal = Politics::platform(Politics::coalitionPosition(Diet::membership([Diet::VANGUARD]), $seats, $positions))['corporateTax'];
        $this->assertEqualsWithDelta($gap / 2.0, $civicIdeal, 1e-12);
        $this->assertEqualsWithDelta(-$gap / 2.0, $vanguardIdeal, 1e-12);

        $blocked = Politics::budget([Diet::VANGUARD], [Diet::CIVIC], $seats, $positions, $standing(0.0), 0.5);
        $this->assertSame(0.0, $blocked['levers']['corporateTax'], 'The Civic Front will not vote the rate further from its own.');
        $this->assertTrue($blocked['supportHeld']['corporateTax']);
        $this->assertFalse($blocked['councilHeld']['corporateTax']);

        // The Exchange Party's own rate is the neutral one: from 3.5 points above it, a cut to 3.5 below is no further.
        $through = Politics::budget([Diet::VANGUARD], [Diet::EXCHANGE], $seats, $positions, $standing($gap / 2.0), 0.5);
        $this->assertEqualsWithDelta($vanguardIdeal, $through['levers']['corporateTax'], 1e-12);
        $this->assertFalse($through['supportHeld']['corporateTax']);

        $partial = Politics::budget([Diet::VANGUARD], [Diet::EXCHANGE], $seats, $positions, $standing(0.01), 0.5);
        $this->assertEqualsWithDelta(-0.01, $partial['levers']['corporateTax'], 1e-12, 'A cut goes only as far past the Exchange Party\'s rate as the rate in force stood above it.');
        $this->assertTrue($partial['supportHeld']['corporateTax']);

        $both = Politics::budget([Diet::VANGUARD], [Diet::EXCHANGE, Diet::CIVIC], $seats, $positions, $standing(0.01), 0.5);
        $this->assertEqualsWithDelta(0.01, $both['levers']['corporateTax'], 1e-12, 'With the Civic Front also needed, the rate cannot fall at all.');

        $majority = Politics::budget([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS], [], $seats, $positions, $standing(0.0), 0.5);
        $this->assertSame([false, false, false], array_values($majority['supportHeld']), 'A majority cabinet answers to no supporter.');
    }

    public function testATariffCostsProductivityAndItsRepealGivesItBack(): void
    {
        $state = $this->governedBy([Diet::CIVIC => 110.0, Diet::IRON_HARBOR => 45.0], 0.5);
        $index = $state->totalFactorProductivityIndex;

        Politics::enactBudget($state);
        $loss = -MacroEngine::TARIFF_OUTPUT_LOSS * $state->importTariffRate;
        $this->assertLessThan(0.0, $loss);
        $this->assertEqualsWithDelta($loss, $state->tfpShockLevel, 1e-15, 'The level potential absorbs falls by the output loss,');
        $this->assertEqualsWithDelta($index * exp($loss), $state->totalFactorProductivityIndex, 1e-9, 'and productivity with it.');

        Politics::enactBudget($state);
        $this->assertEqualsWithDelta($loss, $state->tfpShockLevel, 1e-15, 'A tariff already in force costs nothing more.');

        $state->governingCoalition = Diet::membership([Diet::EXCHANGE]);
        $state->dietSeats = [Diet::EXCHANGE => 160.0] + array_fill_keys(Diet::PARTIES, 0.0);
        Politics::enactBudget($state);
        $this->assertSame(0.0, $state->importTariffRate);
        $this->assertEqualsWithDelta(0.0, $state->tfpShockLevel, 1e-15, 'Repeal gives it back.');
    }

    public function testTheVoteReadsGrowthPerHead(): void
    {
        $subsystem = new Politics($this->quietMath());
        $state = $this->electionTick();
        $state->laborForceGrowthRate = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + 0.01;

        $subsystem->update($state, 0.01);

        $this->assertEqualsWithDelta(-0.01, $state->electionGrowthGap, 1e-9, 'Output growing only with the labour force is no growth per head.');
    }

    /**
     * A state on the tick of the first vote, with the campaign and term marks set so growth and inflation run the given
     * distance from trend and target.
     */
    private function electionTick(float $growthGap = 0.0, float $inflationGap = 0.0): MacroState
    {
        $state = new MacroState();
        $state->totalTime = self::ELECTION_AT;
        $state->lastElectionAt = self::ELECTION_AT;

        $trendGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT;
        $state->campaignStartedAt = self::ELECTION_AT - MacroEngine::ELECTION_CAMPAIGN_WINDOW_YEARS;
        $state->campaignStartRealGdp = Politics::realGdp($state) * exp(-($trendGrowth + $growthGap) * MacroEngine::ELECTION_CAMPAIGN_WINDOW_YEARS);
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = $state->gdpDeflator * exp(-(MacroEngine::TARGET_INFLATION + $inflationGap) * self::ELECTION_AT);

        return $state;
    }

    /**
     * A state between votes with the given cabinet and supporters on the given seats and the founding positions.
     *
     * @param array<string, float> $seats   Seats of the cabinet's parties; the rest of the Diet holds none unless supporting.
     * @param array<string, float> $support Seats of the support parties.
     */
    private function governedBy(array $seats, float $debtToGdp, array $support = []): MacroState
    {
        $state = new MacroState();
        $state->totalTime = 10.0;
        $state->sovereignDebtToGdp = $debtToGdp;
        $state->dietSeats = [];
        foreach (Diet::PARTIES as $party) {
            $state->dietSeats[$party] = $seats[$party] ?? $support[$party] ?? 0.0;
        }
        $state->governingCoalition = Diet::membership(array_keys($seats));
        $state->supportParties = Diet::membership(array_keys($support));

        return $state;
    }

    /** The day after the first vote, with the talks settled on a Vanguard cabinet the Exchange Party and the Chartists support. */
    private function pendingGovernment(float $takesOfficeAt): MacroState
    {
        $state = new MacroState();
        $state->lastElectionAt = self::ELECTION_AT;
        $state->termStartedAt = self::ELECTION_AT;
        $state->termStartDeflator = $state->gdpDeflator;
        $state->campaignStartedAt = self::ELECTION_AT - MacroEngine::ELECTION_CAMPAIGN_WINDOW_YEARS;
        $state->campaignStartRealGdp = Politics::realGdp($state);
        $state->pendingCoalition = Diet::membership([Diet::VANGUARD]);
        $state->pendingSupport = Diet::membership([Diet::EXCHANGE, Diet::CHARTISTS]);
        $state->coalitionTakesOfficeAt = $takesOfficeAt;

        return $state;
    }

    /** The same Diet a term later, on the tick of the next vote, with the economy at trend over the term just ended. */
    private function nextElectionTick(MacroState $previous): MacroState
    {
        $next = $this->electionTick();
        $next->dietSeats = $previous->dietSeats;
        $next->dietVoteShares = $previous->dietVoteShares;
        $next->partyPositions = $previous->partyPositions;
        $next->governingCoalition = $previous->governingCoalition;
        $next->supportParties = $previous->supportParties;
        $next->partyShortTermShocks = $previous->partyShortTermShocks;
        $next->ironHarborCrisisShift = $previous->ironHarborCrisisShift;

        return $next;
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
}
