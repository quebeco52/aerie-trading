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
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_SEATS[$party], $seats[$party], 1.0, "{$party} is more than a seat off its founding count.");
        }
    }

    // --- Coalitions ---

    /** The declared founding government is the one the rule forms, so the constant cannot drift from the rule. */
    public function testTheFoundingGovernmentIsWhatTheRuleForms(): void
    {
        $formed = Politics::formCoalition(Diet::SEED_SEATS, Diet::SEED_SECONDARY_POSITIONS);

        $this->assertSame([Diet::VANGUARD, Diet::EXCHANGE], $formed);
        $this->assertSame($formed, Diet::governingParties(Diet::SEED_COALITION));
    }

    /** The Exchange Party drifting big-state leaves the Vanguard's nearest partner the Iron Harbor Coalition. */
    public function testDriftChangesTheVanguardsPartner(): void
    {
        $positions = [Diet::EXCHANGE => 1.0] + Diet::SEED_SECONDARY_POSITIONS;

        $this->assertSame([Diet::VANGUARD, Diet::IRON_HARBOR], Politics::formCoalition(Diet::SEED_SEATS, $positions));
    }

    /** At its founding size the Civic Front needs both small parties; a few seats more and one will do. */
    public function testTheCivicFrontGovernsWithOnePartnerOnlyOnceItOutgrowsTheFounding(): void
    {
        $this->assertFalse(Politics::isMinimalWinning([Diet::CIVIC, Diet::IRON_HARBOR], Diet::SEED_SEATS));

        $grown = [Diet::CIVIC => 92.0, Diet::VANGUARD => 88.0, Diet::IRON_HARBOR => 35.0, Diet::EXCHANGE => 35.0];

        $this->assertTrue(Politics::isMinimalWinning([Diet::CIVIC, Diet::IRON_HARBOR], $grown));
        $this->assertSame([Diet::CIVIC, Diet::IRON_HARBOR], Politics::formCoalition($grown, Diet::SEED_SECONDARY_POSITIONS));
    }

    /** A member whose departure still leaves a bare majority is not needed, so the coalition is not minimal. */
    public function testAPartyTheMajorityDoesNotNeedIsNotPartOfAMinimalCoalition(): void
    {
        // Without the Harbor's 5 seats the other two hold exactly 126; without either of them, well short.
        $seats = [Diet::CIVIC => 119.0, Diet::VANGUARD => 96.0, Diet::IRON_HARBOR => 5.0, Diet::EXCHANGE => 30.0];

        $this->assertTrue(Politics::isMinimalWinning([Diet::VANGUARD, Diet::EXCHANGE], $seats), 'Exactly a majority wins.');
        $this->assertFalse(Politics::isMinimalWinning([Diet::VANGUARD, Diet::IRON_HARBOR, Diet::EXCHANGE], $seats));
    }

    /** The grand coalition is the alliance of necessity: it forms when the alternative spans more, and not otherwise. */
    public function testTheGrandCoalitionFormsOnlyWhenNothingNarrowerWins(): void
    {
        $deadlock = [Diet::CIVIC => 100.0, Diet::VANGUARD => 100.0, Diet::IRON_HARBOR => 25.0, Diet::EXCHANGE => 25.0];
        // The small parties far apart: Civic with both of them spans more than the two big parties together.
        $apart = [Diet::EXCHANGE => -0.9] + Diet::SEED_SECONDARY_POSITIONS;

        $this->assertSame([Diet::CIVIC, Diet::VANGUARD], Politics::formCoalition($deadlock, $apart));
        $this->assertNotSame([Diet::CIVIC, Diet::VANGUARD], Politics::formCoalition(Diet::SEED_SEATS, Diet::SEED_SECONDARY_POSITIONS));
    }

    /** Whatever the Diet, the government is minimal winning and no minimal winning coalition spans less. */
    public function testEveryGovernmentIsTheNarrowestMinimalWinningCoalition(): void
    {
        mt_srand(11);
        for ($trial = 0; $trial < 400; ++$trial) {
            $shares = [];
            $positions = [];
            foreach (Diet::PARTIES as $party) {
                $shares[$party] = 0.02 + (mt_rand() / mt_getrandmax());
                $positions[$party] = -1.0 + (2.0 * mt_rand() / mt_getrandmax());
            }
            $seats = Politics::dHondt($shares, Diet::SEATS);

            $formed = Politics::formCoalition($seats, $positions);
            $this->assertTrue(Politics::isMinimalWinning($formed, $seats));

            $range = Politics::ideologicalRange($formed, $positions);
            foreach ($this->subsets() as $members) {
                if (Politics::isMinimalWinning($members, $seats)) {
                    $this->assertGreaterThanOrEqual($range - 1e-9, Politics::ideologicalRange($members, $positions));
                }
            }
        }
    }

    public function testTheGovernmentsPositionIsItsPartiesWeightedBySeats(): void
    {
        $position = Politics::coalitionPosition(Diet::SEED_COALITION, Diet::SEED_SEATS, Diet::SEED_SECONDARY_POSITIONS);

        // Vanguard 95 at (state -0.8, openness 0), Exchange 35 at (state 0, openness +0.8).
        $this->assertEqualsWithDelta(95.0 * -0.8 / 130.0, $position[Diet::AXIS_STATE], 1e-12);
        $this->assertEqualsWithDelta(35.0 * 0.8 / 130.0, $position[Diet::AXIS_OPENNESS], 1e-12);
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
     * Vote after vote at a trend economy: governments are seen to lose a little more than the record's cost of ruling,
     * the Diet is about two thirds as volatile as Western Europe's parliaments, and the party system stays balanced.
     */
    public function testTheDietStaysBalancedAtItsCalibratedVolatility(): void
    {
        mt_srand(23);
        $subsystem = new Politics(new MathUtility());
        $volatility = [];
        $incumbentSwings = [];
        $singleParty = 0;
        for ($run = 0; $run < 60; ++$run) {
            $state = $this->electionTick();
            for ($vote = 0; $vote < 40; ++$vote) {
                $subsystem->update($state, 0.01);
                // The first vote has no earlier short-term swing to give back, so it is not yet the steady state.
                if ($vote > 0) {
                    $volatility[] = Politics::pedersenVolatility($state->dietVoteSwings);
                    $incumbentSwings[] = $state->electionIncumbentSwing;
                    $singleParty += count(Diet::governingParties($state->governingCoalition)) === 1 ? 1 : 0;
                }
                $state = $this->nextElectionTick($state);
            }
        }

        $observedLoss = -array_sum($incumbentSwings) / count($incumbentSwings);
        $this->assertGreaterThan(MacroEngine::ELECTION_RECORDED_COST_OF_RULING, $observedLoss);
        $this->assertLessThan(MacroEngine::ELECTION_RECORDED_COST_OF_RULING + 0.01, $observedLoss);
        // Dassonneville & Hooghe (2017): mean Pedersen index 10 over 21 Western European countries, 1950-2013.
        $this->assertEqualsWithDelta(0.065, array_sum($volatility) / count($volatility), 0.01);
        $this->assertLessThan(0.05, $singleParty / count($volatility), 'The party system has drifted toward one-party rule.');
    }

    /** A party's short-term swing is given back whole at the next vote. */
    public function testAShortTermSwingIsUndoneAtTheNextVote(): void
    {
        $shocks = [Diet::CIVIC => 0.3, Diet::VANGUARD => -0.2, Diet::IRON_HARBOR => 0.1, Diet::EXCHANGE => -0.05];
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

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        $this->assertEqualsWithDelta(0.52 - 0.04, $shares[Diet::VANGUARD] + $shares[Diet::EXCHANGE], 1e-12);
        $this->assertEqualsWithDelta(0.34 / 0.14, $shares[Diet::CIVIC] / $shares[Diet::IRON_HARBOR], 1e-12);
        $this->assertEqualsWithDelta(0.38 / 0.14, $shares[Diet::VANGUARD] / $shares[Diet::EXCHANGE], 1e-12);
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

    /** Each vote moves a party's secondary position by Somer-Topcu's step, and never off the axis. */
    public function testPositionsDriftAtTheManifestoScaleAndStayOnTheAxis(): void
    {
        mt_srand(3);
        $subsystem = new Politics(new MathUtility());
        $steps = [];
        for ($i = 0; $i < 3000; ++$i) {
            $state = $this->electionTick();
            $subsystem->update($state, 0.01);
            $steps[] = $state->partySecondaryPositions[Diet::VANGUARD] - Diet::SEED_SECONDARY_POSITIONS[Diet::VANGUARD];
        }
        $sd = sqrt(array_sum(array_map(static fn(float $s): float => $s ** 2, $steps)) / count($steps));
        $this->assertEqualsWithDelta(Politics::SECONDARY_DRIFT_PER_ELECTION, $sd, 0.01);

        $state = $this->electionTick();
        for ($vote = 0; $vote < 300; ++$vote) {
            $state->lastElectionAt = $state->totalTime;
            $subsystem->update($state, 0.01);
            foreach (Diet::PARTIES as $party) {
                $this->assertGreaterThanOrEqual(-1.0, $state->partySecondaryPositions[$party]);
                $this->assertLessThanOrEqual(1.0, $state->partySecondaryPositions[$party]);
                $this->assertSame(Diet::PRIMARY_POSITION[$party], Diet::position($party, $state->partySecondaryPositions[$party])[Diet::PRIMARY_AXIS[$party]]);
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
        $state->partySecondaryPositions = [Diet::VANGUARD => 0.95] + Diet::SEED_SECONDARY_POSITIONS;

        $subsystem->update($state, 0.01);

        $this->assertEqualsWithDelta(2.0 - (0.95 + Politics::SECONDARY_DRIFT_PER_ELECTION), $state->partySecondaryPositions[Diet::VANGUARD], 1e-12);
    }

    // --- The calendar ---

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
        $this->assertSame(self::ELECTION_AT, $onTheDay->coalitionFormedAt);
        $this->assertSame(self::ELECTION_AT, $onTheDay->termStartedAt);
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

    /** Through the engine: the calendar's election tick is the one the Diet votes on, every term. */
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
            politicsSubsystem: new Politics($math),
        );

        $votes = [];
        $ticksPerYear = 52;
        for ($tick = 0; $tick < 9 * $ticksPerYear; ++$tick) {
            $macro = $engine->updateMacroState(1.0 / $ticksPerYear);
            if ($macro->lastElectionAt === $macro->totalTime) {
                $votes[] = $macro;
            }
        }

        $this->assertCount(2, $votes);
        foreach ($votes as $vote) {
            $this->assertSame($vote->totalTime, $vote->coalitionFormedAt);
            $this->assertNotSame([], $vote->dietVoteSwings);
            $this->assertSame((float) Diet::SEATS, array_sum($vote->dietSeats));
        }
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

    /** The same Diet a term later, on the tick of the next vote, with the economy at trend over the term just ended. */
    private function nextElectionTick(MacroState $previous): MacroState
    {
        $next = $this->electionTick();
        $next->dietSeats = $previous->dietSeats;
        $next->dietVoteShares = $previous->dietVoteShares;
        $next->partySecondaryPositions = $previous->partySecondaryPositions;
        $next->governingCoalition = $previous->governingCoalition;
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

    /** @return list<list<string>> Every non-empty set of parties. */
    private function subsets(): array
    {
        $subsets = [];
        $count = count(Diet::PARTIES);
        for ($mask = 1; $mask < (1 << $count); ++$mask) {
            $members = [];
            foreach (Diet::PARTIES as $index => $party) {
                if ($mask & (1 << $index)) {
                    $members[] = $party;
                }
            }
            $subsets[] = $members;
        }

        return $subsets;
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
