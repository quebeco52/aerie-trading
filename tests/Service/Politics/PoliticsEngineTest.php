<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Diet\CoalitionFormation;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Policy\BudgetBargain;
use App\Service\Politics\Policy\BudgetRound;
use App\Service\Politics\Policy\Lever;
use App\Service\Politics\Policy\Platforms;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class PoliticsEngineTest extends TestCase
{
    use PoliticsEngineFixtures;

    // --- Seats ---

    /** The textbook D'Hondt case: 100k/80k/30k/20k votes for 8 seats go 4/3/1/0. */
    public function testDHondtAllocatesTheTextbookCase(): void
    {
        $seats = Election::dHondt(['a' => 100000.0, 'b' => 80000.0, 'c' => 30000.0, 'd' => 20000.0], 8);

        $this->assertSame(['a' => 4, 'b' => 3, 'c' => 1, 'd' => 0], $seats);
    }

    public function testTheFoundingVoteReproducesTheFoundingDiet(): void
    {
        $seats = Election::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);

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
        $seats = array_merge(Diet::SEED_SEATS, [Diet::VANGUARD => 95.0, Diet::EXCHANGE => 25.0, Diet::CHARTISTS => 20.0]);
        $position = Platforms::coalitionPosition(Diet::membership([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS]), $seats, Diet::HOME_POSITIONS);

        // Vanguard 95 at (-0.65, +0.3, +0.3), Exchange 25 at (-0.3, +0.8, +0.4), Chartists 20 at (-0.4, +0.6, +0.8).
        $this->assertEqualsWithDelta((95.0 * -0.65 + 25.0 * -0.3 + 20.0 * -0.4) / 140.0, $position[Diet::AXIS_STATE], 1e-12);
        $this->assertEqualsWithDelta((95.0 * 0.3 + 25.0 * 0.8 + 20.0 * 0.6) / 140.0, $position[Diet::AXIS_OPENNESS], 1e-12);
        $this->assertEqualsWithDelta((95.0 * 0.3 + 25.0 * 0.4 + 20.0 * 0.8) / 140.0, $position[Diet::AXIS_COUNCIL], 1e-12);
    }

    // --- The vote ---

    /**
     * Inflation costs every cabinet; growth is credited to a party governing alone, and in a coalition to the party
     * leading it at half the rate, its partners none.
     */
    public function testTheEconomicVoteCreditsGrowthToWhoeverLeadsTheGovernment(): void
    {
        foreach ([true, false] as $alone) {
            $atTrend = Election::economicVote(0.0, 0.0, 0.0, $alone);

            $this->assertEqualsWithDelta(-Election::COST_OF_RULING, $atTrend, 1e-12);
            $this->assertEqualsWithDelta(-Election::INFLATION_SLOPE * 0.01, Election::economicVote(0.0, 0.01, 0.0, $alone) - $atTrend, 1e-12);
            $this->assertEqualsWithDelta(Election::RESIDUAL_SD, Election::economicVote(0.0, 0.0, 1.0, $alone) - $atTrend, 1e-12);
        }
        $this->assertEqualsWithDelta(Election::SINGLE_PARTY_GROWTH_SLOPE * 0.01, Election::economicVote(0.01, 0.0, 0.0, true) - Election::economicVote(0.0, 0.0, 0.0, true), 1e-12);
        $this->assertSame(Election::economicVote(0.0, 0.0, 0.0, false), Election::economicVote(0.01, 0.0, 0.0, false), 'A coalition as a whole gets no credit for growth.');
        $this->assertEqualsWithDelta(0.5 * Election::SINGLE_PARTY_GROWTH_SLOPE * 0.01, Election::primeMinisterGrowthVote(0.01, false), 1e-15, 'Its leading party does.');
        $this->assertSame(0.0, Election::primeMinisterGrowthVote(0.01, true), 'A party governing alone takes it with the government, not twice.');
    }

    /** A party's short-term swing is undone by its negation. */
    public function testAShortTermSwingIsUndoneByItsNegation(): void
    {
        $shocks = [Diet::CIVIC => 0.3, Diet::VANGUARD => -0.2, Diet::IRON_HARBOR => 0.1, Diet::EXCHANGE => -0.05, Diet::CHARTISTS => 0.2, Diet::COMMON_LOT => -0.1];
        $swung = Election::applyShortTermShocks(Diet::SEED_VOTE_SHARES, $shocks);
        $restored = Election::applyShortTermShocks($swung, array_map(static fn(float $shock): float => -$shock, $shocks));

        $this->assertEqualsWithDelta(1.0, array_sum($swung), 1e-12);
        $this->assertGreaterThan(Diet::SEED_VOTE_SHARES[Diet::CIVIC], $swung[Diet::CIVIC]);
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_VOTE_SHARES[$party], $restored[$party], 1e-12);
        }
    }

    /**
     * A party's swings scale with its size as real vote shares' do: the Common Lot, 8 seats to the Civic Front's 94,
     * swings the square root of 94/8, 3.4 times, as far in log share.
     */
    public function testSmallPartiesSwingFurtherInLogShare(): void
    {
        $large = Election::swingSd(Election::SHORT_TERM_SWING_VARIANCE, Diet::SEED_VOTE_SHARES[Diet::CIVIC]);
        $small = Election::swingSd(Election::SHORT_TERM_SWING_VARIANCE, Diet::SEED_VOTE_SHARES[Diet::COMMON_LOT]);

        $this->assertEqualsWithDelta(sqrt(Election::SHORT_TERM_SWING_VARIANCE / Diet::SEED_VOTE_SHARES[Diet::CIVIC]), $large, 1e-12);
        $this->assertEqualsWithDelta(sqrt(Diet::SEED_VOTE_SHARES[Diet::CIVIC] / Diet::SEED_VOTE_SHARES[Diet::COMMON_LOT]), $small / $large, 1e-12);
    }

    /**
     * A boom over the campaign returns the government stronger, a coalition only through the party leading it;
     * inflation over the term costs it.
     */
    public function testGrowthAndInflationMoveTheGovernmentsVote(): void
    {
        $engine = $this->engine($this->quietMath());

        $alone = $this->votedUnder($engine, [Diet::VANGUARD]);
        $boomAlone = $this->votedUnder($engine, [Diet::VANGUARD], growthGap: 0.03);
        $coalition = $this->votedUnder($engine, self::RIGHT_BLOC);
        $boomCoalition = $this->votedUnder($engine, self::RIGHT_BLOC, growthGap: 0.03);
        $inflation = $this->votedUnder($engine, self::RIGHT_BLOC, inflationGap: 0.03);

        $this->assertEqualsWithDelta(0.03, $boomAlone->electionGrowthGap, 1e-9);
        $this->assertEqualsWithDelta(0.03, $inflation->electionInflationGap, 1e-9);
        $this->assertEqualsWithDelta(Election::SINGLE_PARTY_GROWTH_SLOPE * 0.03, $boomAlone->electionIncumbentSwing - $alone->electionIncumbentSwing, 1e-9, 'A boom returns a party governing alone stronger,');
        $leaderGain = Election::COALITION_PRIME_MINISTER_GROWTH_SLOPE * 0.03;
        $this->assertEqualsWithDelta($leaderGain, $boomCoalition->dietVoteShares[Diet::VANGUARD] - $coalition->dietVoteShares[Diet::VANGUARD], 1e-9, 'a coalition\'s leading party by half that,');
        $this->assertLessThan($coalition->dietVoteShares[Diet::EXCHANGE], $boomCoalition->dietVoteShares[Diet::EXCHANGE], 'its partners not at all,');
        $this->assertGreaterThan($coalition->electionIncumbentSwing, $boomCoalition->electionIncumbentSwing);
        $this->assertLessThan($coalition->electionIncumbentSwing + $leaderGain, $boomCoalition->electionIncumbentSwing, 'so the coalition by less.');
        $this->assertEqualsWithDelta(-Election::INFLATION_SLOPE * 0.03, $inflation->electionIncumbentSwing - $coalition->electionIncumbentSwing, 1e-9, 'Inflation costs a coalition too.');
    }

    /** The opposition takes what the government loses, each opposition party in proportion to its own share. */
    public function testTheSwingIsSharedInProportion(): void
    {
        $shares = Election::applyIncumbentSwing(Diet::SEED_VOTE_SHARES, Diet::membership([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS]), -0.04);
        $seed = Diet::SEED_VOTE_SHARES;

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::VANGUARD] + $seed[Diet::EXCHANGE] + $seed[Diet::CHARTISTS] - 0.04, $shares[Diet::VANGUARD] + $shares[Diet::EXCHANGE] + $shares[Diet::CHARTISTS], 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::CIVIC] / $seed[Diet::IRON_HARBOR], $shares[Diet::CIVIC] / $shares[Diet::IRON_HARBOR], 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::VANGUARD] / $seed[Diet::EXCHANGE], $shares[Diet::VANGUARD] / $shares[Diet::EXCHANGE], 1e-12);
    }

    /**
     * A party supporting the cabinet from outside shares the government's swing by the accountability voters hold it
     * to.
     */
    public function testASupportPartySharesTheSwingByItsAccountability(): void
    {
        $seed = Diet::SEED_VOTE_SHARES;
        $cabinet = Diet::membership([Diet::VANGUARD]);
        $support = Diet::membership([Diet::EXCHANGE]);
        $weight = Election::SUPPORT_ACCOUNTABILITY;
        $governing = $seed[Diet::VANGUARD] + ($weight * $seed[Diet::EXCHANGE]);
        $opposition = 1.0 - $governing;

        $shares = Election::applyIncumbentSwing($seed, $cabinet, -0.04, $support);

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        $this->assertEqualsWithDelta($seed[Diet::VANGUARD] * (1.0 - (0.04 / $governing)), $shares[Diet::VANGUARD], 1e-12);
        $this->assertEqualsWithDelta(
            $seed[Diet::EXCHANGE] * (1.0 - ($weight * 0.04 / $governing) + ((1.0 - $weight) * 0.04 / $opposition)),
            $shares[Diet::EXCHANGE],
            1e-12,
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
            $persistence = Election::POSITION_ANNUAL_PERSISTENCE[$axis] ** Election::TERM_YEARS;
            $this->assertEqualsWithDelta(0.1 + (0.5 * $persistence), Election::movePosition(0.6, 0.1, $axis, 0.0), 1e-12, "{$axis}: the pull home.");
            $this->assertEqualsWithDelta(0.1, Election::movePosition(0.1, 0.1, $axis, 0.0), 1e-12, "{$axis}: a party at home stays there.");
        }
    }

    /** A step past the end of the axis comes back off it by the overshoot, rather than sticking at the end. */
    public function testAStepPastTheEndOfTheAxisIsReflected(): void
    {
        $persistence = Election::POSITION_ANNUAL_PERSISTENCE[Diet::AXIS_OPENNESS] ** Election::TERM_YEARS;
        $overshoot = (0.95 * $persistence) + (Election::POSITION_WITHIN_SD[Diet::AXIS_OPENNESS] * sqrt(1.0 - ($persistence ** 2)) * 3.0);

        $this->assertGreaterThan(1.0, $overshoot);
        $this->assertEqualsWithDelta(2.0 - $overshoot, Election::movePosition(0.95, 0.0, Diet::AXIS_OPENNESS, 3.0), 1e-12);
    }

    // --- The calendar and the talks ---

    /**
     * The vote is held on the tick a term ends only; it opens the talks, and the outgoing cabinet stays on meanwhile.
     */
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
            'The cabinet takes office the day the last attempt succeeds.',
        );
    }

    // --- Falls between votes ---

    /**
     * A coalition can lose a partner and a minority cabinet its supporters; a party governing alone on its own majority
     * has neither.
     */
    public function testAFallsHazardFollowsTheCabinetsKind(): void
    {
        $seats = [Diet::CIVIC => 160.0, Diet::VANGUARD => 60.0, Diet::IRON_HARBOR => 40.0, Diet::EXCHANGE => 40.0];

        $this->assertSame(0.0, Election::fallHazard([Diet::CIVIC], $seats));
        $this->assertSame(Election::CABINET_FALL_HAZARD_MAJORITY_COALITION, Election::fallHazard([Diet::CIVIC, Diet::IRON_HARBOR], $seats));
        $this->assertSame(Election::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY, Election::fallHazard([Diet::VANGUARD], $seats));
        $this->assertSame(Election::CABINET_FALL_HAZARD_MINORITY_COALITION, Election::fallHazard([Diet::VANGUARD, Diet::EXCHANGE], $seats));
    }

    /**
     * A coalition falls sooner while the economy slides and later while it recovers, its day moved each tick by the
     * hazard's excess over the rate it was drawn at; a single party's day, and a gap low but steady, move nothing.
     */
    public function testASlidingEconomyBringsACoalitionsFallForward(): void
    {
        $this->assertSame(1.0, Election::fallHazardScale([Diet::VANGUARD], -0.03));
        $this->assertSame(1.0, Election::fallHazardScale([Diet::VANGUARD, Diet::EXCHANGE], 0.0));
        $this->assertEqualsWithDelta(exp(0.18), Election::fallHazardScale([Diet::VANGUARD, Diet::EXCHANGE], -0.03), 1e-12, 'About 1.2 through a year the gap loses 3 points.');
        $this->assertLessThan(1.0, Election::fallHazardScale([Diet::VANGUARD, Diet::EXCHANGE], 0.02));

        $slide = new MacroStateDTO(totalTime: 10.01, sovereignDebtToGdp: 0.5, outputGap: -0.03, outputGapLag12m: 0.0);
        $coalition = $this->governedBy([Diet::VANGUARD => 80.0, Diet::EXCHANGE => 80.0]);
        $this->engine($this->quietMath())->advance($coalition, $slide, 0.01);
        $this->assertEqualsWithDelta(100.0 - ((exp(0.18) - 1.0) * 0.01), $coalition->cabinetFallsAt, 1e-12);

        $alone = $this->governedBy([Diet::VANGUARD => 80.0], [Diet::EXCHANGE => 80.0]);
        $this->engine($this->quietMath())->advance($alone, $slide, 0.01);
        $this->assertSame(100.0, $alone->cabinetFallsAt);

        $lowButSteady = $this->governedBy([Diet::VANGUARD => 80.0, Diet::EXCHANGE => 80.0]);
        $this->engine($this->quietMath())->advance($lowButSteady, new MacroStateDTO(totalTime: 10.01, sovereignDebtToGdp: 0.5, outputGap: -0.03, outputGapLag12m: -0.03), 0.01);
        $this->assertSame(100.0, $lowButSteady->cabinetFallsAt);
    }

    /**
     * On its day the cabinet falls: it stays on as caretaker, passes no budget, and the parties talk on the same seats
     * for any cabinet but the one that fell; the government the talks produce takes office on its day and draws a day
     * of its own.
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

    /**
     * A caretaker passes no budget, however long the talks run; the new cabinet legislates at the round after it takes
     * office.
     */
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
        $campaignOpens = self::ELECTION_AT - Election::CAMPAIGN_WINDOW_YEARS;
        $engine->advance($state, $this->economy($campaignOpens - 0.01), 0.01);
        $this->assertSame(0.0, $state->campaignStartedAt);

        $economy = new MacroStateDTO(totalTime: $campaignOpens, outputGap: 0.02);
        $engine->advance($state, $economy, 0.01);
        $this->assertSame($campaignOpens, $state->campaignStartedAt);
        $this->assertSame(Election::realGdp($economy), $state->campaignStartRealGdp);
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

    /**
     * A cabinet falling is news on its day, above a party taking office the same day; a cabinet taking office after
     * talks is news on its day.
     */
    public function testAFallAndAGovernmentAreReportedOnTheirDays(): void
    {
        $on = static function (array $marks): ?string {
            $state = new PoliticsState();
            $state->totalTime = 6.3;
            $state->lastElectionAt = 4.0;
            foreach ($marks as $field => $at) {
                if ($field === 'governorAppointedAt') {
                    $state->heads[Office::Governor->value] = ['appointedAt' => $at] + Office::VACANT;
                } else {
                    $state->$field = $at;
                }
            }

            return Politics::headline($state);
        };

        $this->assertSame(ShockEvent::GOVERNMENT_FELL, $on(['lastCabinetFellAt' => 6.3, 'lastGovernmentFormedAt' => 6.3]), 'A party that takes over on the day of the fall is part of the same news.');
        $this->assertSame(ShockEvent::GOVERNMENT_FORMED, $on(['lastGovernmentFormedAt' => 6.3]));
        $this->assertSame(ShockEvent::GOVERNMENT_FORMED, $on(['lastGovernmentFormedAt' => 6.3, 'lastBudgetEnactedAt' => 6.3]), 'A cabinet taking office outranks a budget.');
        $this->assertSame(ShockEvent::BUDGET_ENACTED, $on(['lastBudgetEnactedAt' => 6.3]));
        $this->assertSame(ShockEvent::BUDGET_ENACTED, $on(['lastBudgetEnactedAt' => 6.3, 'governorAppointedAt' => 6.3]), 'A budget outranks the Council\'s appointments.');
        $this->assertSame(ShockEvent::GOVERNOR_APPOINTED, $on(['governorAppointedAt' => 6.3, 'lastMajorityShiftAt' => 6.3]), 'A new governor outranks the majority their arrival tips.');
        $this->assertSame(ShockEvent::AUTHORITY_MAJORITY_SHIFT, $on(['lastMajorityShiftAt' => 6.3, 'lastCouncillorSeatedAt' => 6.3]));
        $this->assertSame(ShockEvent::GOVERNOR_APPOINTED, $on(['governorAppointedAt' => 6.3, 'lastCouncillorSeatedAt' => 6.3]));
        $this->assertSame(ShockEvent::COUNCILLOR_SEATED, $on(['lastCouncillorSeatedAt' => 6.3, 'lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.005]));
        $this->assertSame(ShockEvent::MONETARY_DECISION, $on(['lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.0025, 'lastMeetingVotes' => [0.0, 0.0]]), 'A full step makes news.');
        $this->assertSame(ShockEvent::MONETARY_DECISION, $on(['lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.0, 'lastMeetingVotes' => [0.0, 1.0]]), 'So does a dissent.');
        $this->assertNull($on(['lastMeetingAt' => 6.3, 'lastMeetingChange' => 0.001, 'lastMeetingVotes' => [0.0, 0.0]]), 'A unanimous small move does not.');
    }

    /** Each tick names its headline, and the next tick clears it. */
    public function testTheHeadlineIsAPulse(): void
    {
        // Talks take a drawn time; seeded, the cabinet takes office ticks after the vote, not inside its tick.
        mt_srand(1);
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

    /**
     * Nearness to the vote ramps in over the final year of the term, from nothing at mid-term to the full lift on the
     * eve.
     */
    public function testThePulseBuildsIntoAScheduledElection(): void
    {
        $midTerm = Election::pulse(1.5, -1.0, -1.0);
        $eve = Election::pulse(3.95, -1.0, -1.0);

        $this->assertEqualsWithDelta(-Election::MEAN_ELECTION_PROXIMITY, $midTerm, 1e-12);
        $this->assertEqualsWithDelta(0.95 - Election::MEAN_ELECTION_PROXIMITY, $eve, 1e-9);
    }

    /**
     * The campaign the parties pay for is the final-year ramp alone: none in the talks after the vote, and read at
     * quarter midpoints it averages its stated mean over the term.
     */
    public function testTheCampaignIsTheRampWithoutTheTalks(): void
    {
        $this->assertSame(0.0, Election::campaignProximity(4.1), 'The talks after the vote are no campaign.');
        $this->assertEqualsWithDelta(0.95, Election::campaignProximity(3.95), 1e-9);

        $quarters = array_map(static fn(int $quarter): float => Election::campaignProximity(($quarter + 0.5) / 4.0), range(0, 15));
        $this->assertEqualsWithDelta(Election::MEAN_CAMPAIGN_PROXIMITY, array_sum($quarters) / count($quarters), 1e-12);
    }

    /**
     * The vote settles nothing until a government takes office: the pulse holds at its peak on the day of the vote and
     * through the talks after it, and falls back once the cabinet is seated.
     */
    public function testThePulseHoldsThroughTheTalks(): void
    {
        $peak = 1.0 - Election::MEAN_ELECTION_PROXIMITY;

        $this->assertSame($peak, Election::pulse(4.0, 4.0, -1.0), 'The vote\'s own tick is the peak, even with a majority seated the same day.');
        $this->assertSame($peak, Election::pulse(4.1, 4.0, 4.2), 'The talks settle nothing.');
        $this->assertSame($peak, Election::pulse(6.3, 4.0, 6.4), 'Nor do the talks after a fall.');
        $this->assertEqualsWithDelta(-Election::MEAN_ELECTION_PROXIMITY, Election::pulse(4.2, 4.0, -1.0), 1e-12, 'A seated government settles the regime.');
    }

    /**
     * Over a term with the average talks after the vote and after falls, the pulse averages nothing, so the calendar
     * moves policy uncertainty through the term and leaves its mean where it was.
     */
    public function testThePulseAveragesNothingOverATermOfAverageTalks(): void
    {
        $ticksPerYear = 3650;
        $talkYears = (CoalitionFormation::FORMATION_MEAN_DAYS + Election::FALL_TALK_DAYS_PER_TERM) / FinancialConstants::DAYS_PER_YEAR;
        $sum = 0.0;
        $ticks = (int) round(Election::TERM_YEARS * $ticksPerYear);
        for ($tick = 1; $tick <= $ticks; ++$tick) {
            $time = Election::TERM_YEARS + ($tick / $ticksPerYear);
            $sum += Election::pulse($time, Election::TERM_YEARS, Election::TERM_YEARS + $talkYears);
        }

        $this->assertEqualsWithDelta(0.0, $sum / $ticks, 1e-3);
    }

    // --- The Budget ---

    /** Each lever runs between the policies of the parties at the two ends of its axis. */
    public function testThePlatformRunsBetweenThePartiesAtTheEndsOfEachAxis(): void
    {
        $civic = Platforms::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::CIVIC][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => 0.0]);
        $vanguard = Platforms::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::VANGUARD][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => 0.0]);
        $this->assertEqualsWithDelta(Platforms::POLICY_MANIFESTO_CORPORATE_TAX_GAP, $civic['corporateTax'] - $vanguard['corporateTax'], 1e-12, 'The two big parties are a manifesto gap apart.');
        $this->assertEqualsWithDelta(0.0, $civic['corporateTax'] + $vanguard['corporateTax'], 1e-12, 'The gap is centred on the neutral rate.');

        $harbor = Platforms::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::IRON_HARBOR][Diet::AXIS_OPENNESS]]);
        $exchange = Platforms::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::EXCHANGE][Diet::AXIS_OPENNESS]]);
        $this->assertEqualsWithDelta(Platforms::POLICY_PROTECTIONIST_TARIFF, $harbor['tariff'], 1e-12, 'The protectionist party enacts its own tariff.');
        $this->assertSame(0.0, $exchange['tariff']);
        $this->assertEqualsWithDelta(Platforms::MIGRATION_OPEN_REGIME - Platforms::MIGRATION_CLOSED_REGIME, $exchange['laborGrowth'] - $harbor['laborGrowth'], 1e-12, 'The two immigration regimes are a regime apart.');
        $this->assertEqualsWithDelta(MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE, Platforms::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => 0.0])['laborGrowth'], 1e-12, 'A neutral government keeps the structural rate.');
        $this->assertEqualsWithDelta(MacroConstants::NATURAL_LABOR_FORCE_GROWTH + Platforms::MIGRATION_CLOSED_REGIME, $harbor['laborGrowth'], 1e-12, 'The closed end admits Japan\'s regime on top of natural growth.');
        $this->assertEqualsWithDelta(MacroConstants::NATURAL_LABOR_FORCE_GROWTH + Platforms::MIGRATION_OPEN_REGIME, $exchange['laborGrowth'], 1e-12, 'The open end admits Canada\'s and Australia\'s.');
    }

    /**
     * The laws the manifestos set either side of the District's average party move half their gap toward each end, each
     * side scaled to its own end party, so how far an end party stands from the average does not tilt the split.
     */
    public function testEachSideOfTheStateAxisIsScaledToItsOwnEnd(): void
    {
        $civic = Platforms::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::CIVIC][Diet::AXIS_STATE] / 2.0]);
        $vanguard = Platforms::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::VANGUARD][Diet::AXIS_STATE] / 2.0]);
        $this->assertEqualsWithDelta(Platforms::POLICY_MANIFESTO_CORPORATE_TAX_GAP / 4.0, $civic['corporateTax'], 1e-12);
        $this->assertEqualsWithDelta(-Platforms::POLICY_MANIFESTO_CORPORATE_TAX_GAP / 4.0, $vanguard['corporateTax'], 1e-12);
        $this->assertEqualsWithDelta(MacroConstants::PUBLIC_PURCHASES_TO_GDP * exp(Platforms::POLICY_MANIFESTO_PUBLIC_SPENDING_LOG_GAP / 4.0), $civic['publicSpending'], 1e-12);
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE - (Platforms::POLICY_MANIFESTO_CONSOLIDATION_TAX_SHARE_GAP / 4.0), $vanguard['consolidationTaxShare'], 1e-12);
        $this->assertSame(Platforms::platform([Diet::AXIS_STATE => -2.0])['corporateTax'], Platforms::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::VANGUARD][Diet::AXIS_STATE]])['corporateTax'], 'Past its end a government enacts the end party\'s law and no more.');
    }

    public function testANeutralOrOpenGovernmentKeepsTheFreePort(): void
    {
        foreach ([0.0, 0.2, 1.0] as $openness) {
            $this->assertSame(0.0, Platforms::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => $openness])['tariff']);
        }
        $this->assertGreaterThan(0.0, Platforms::platform([Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => -0.1])['tariff'], 'Any closed lean raises the tariff.');
    }

    public function testNoGovernmentGoesFurtherThanThePartyAtTheEndOfTheAxis(): void
    {
        $pole = Platforms::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::CIVIC][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::IRON_HARBOR][Diet::AXIS_OPENNESS]]);

        $this->assertSame($pole, Platforms::platform([Diet::AXIS_STATE => 1.0, Diet::AXIS_OPENNESS => -1.0]));
        $this->assertSame(
            Platforms::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::VANGUARD][Diet::AXIS_STATE], Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::EXCHANGE][Diet::AXIS_OPENNESS]]),
            Platforms::platform([Diet::AXIS_STATE => -1.0, Diet::AXIS_OPENNESS => 1.0]),
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
        $platform = Platforms::platform(Platforms::coalitionPosition($state->governingCoalition, $state->dietSeats, $state->partyPositions));
        $this->assertLessThan(0.0, $platform['corporateTax'], 'The Vanguard-led government cuts the rate');
        $this->assertSame($platform['corporateTax'], $state->corporateTaxPolicyShift, 'and its first budget enacts the cut,');
        $this->assertSame($platform['tariff'], $state->importTariffRate);
        $this->assertSame($platform['laborGrowth'], $state->laborForceGrowthRate);
        $this->assertSame($state->totalTime, $state->lastBudgetEnactedAt);

        $policy = PoliticsStateDTO::fromState($state)->policy();
        $this->assertSame($state->corporateTaxPolicyShift, $policy->lever(Lever::CorporateTax), 'The economy reads the levers the budget enacted.');
        $this->assertSame($state->importTariffRate, $policy->lever(Lever::Tariff));
        $this->assertSame($state->laborForceGrowthRate, $policy->lever(Lever::LaborGrowth));
    }

    /**
     * The closed end of the openness axis taxes foreign home buyers at Singapore's 60%; the open end and the middle tax
     * none.
     */
    public function testTheClosedEndTaxesForeignHomeBuyers(): void
    {
        $this->assertSame(Platforms::POLICY_PROTECTIONIST_FOREIGN_BUYER_TAX, Platforms::platform(Diet::position(Diet::IRON_HARBOR, []))['foreignBuyerTax']);
        $this->assertSame(0.0, Platforms::platform(Diet::position(Diet::EXCHANGE, []))['foreignBuyerTax']);
        $this->assertSame(0.0, Platforms::platform([Diet::AXIS_OPENNESS => 0.0])['foreignBuyerTax']);
        $this->assertEqualsWithDelta(Platforms::POLICY_PROTECTIONIST_FOREIGN_BUYER_TAX / 2.0, Platforms::platform([Diet::AXIS_OPENNESS => Diet::FIXED_POSITIONS[Diet::IRON_HARBOR][Diet::AXIS_OPENNESS] / 2.0])['foreignBuyerTax'], 1e-15);
    }

    /**
     * The ends of the size-of-state axis raise a debt repair's share by tax the 2010 UK manifestos apart, the neutral
     * position keeping the average plan's mix; no revenue the brake guards.
     */
    public function testTheConsolidationMixPlatformsSpanTheManifestoGap(): void
    {
        $civic = Platforms::platform(Diet::position(Diet::CIVIC, []))['consolidationTaxShare'];
        $vanguard = Platforms::platform(Diet::position(Diet::VANGUARD, []))['consolidationTaxShare'];
        $this->assertEqualsWithDelta(10.0 / 71.0, $civic - $vanguard, 1e-12, 'Labour\'s 2:1 against the Conservatives\' 4:1.');
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE, Platforms::platform([Diet::AXIS_STATE => 0.0])['consolidationTaxShare'], 1e-12);
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE, (new PoliticsStateDTO())->consolidationTaxShare, 1e-12);
        $this->assertSame(Diet::AXIS_STATE, Lever::axes()['consolidationTaxShare']);
    }

    /**
     * The technocratic half of the Council axis keeps the appointed commission, which grants two thirds of a request;
     * the populist end grants as an elected one, less than the appointed one does.
     */
    public function testTheUtilityCommissionGrantsLessTowardThePopulistEnd(): void
    {
        $appointed = FirmEconomics::APPOINTED_COMMISSION_GRANTED_SHARE;
        $elected = $appointed * FirmEconomics::ELECTED_COMMISSION_GRANT_RATIO;
        $this->assertLessThan($appointed, $elected, 'An elected commission grants less of a request.');
        $this->assertEqualsWithDelta($elected, Platforms::platform(Diet::position(Diet::COMMON_LOT, []))['utilityRateGrant'], 1e-12);
        $this->assertEqualsWithDelta($appointed, Platforms::platform(Diet::position(Diet::CHARTISTS, []))['utilityRateGrant'], 1e-12);
        $this->assertEqualsWithDelta($appointed, Platforms::platform([Diet::AXIS_COUNCIL => 0.0])['utilityRateGrant'], 1e-12);
        $this->assertEqualsWithDelta(($appointed + $elected) / 2.0, Platforms::platform([Diet::AXIS_COUNCIL => Diet::FIXED_POSITIONS[Diet::COMMON_LOT][Diet::AXIS_COUNCIL] / 2.0])['utilityRateGrant'], 1e-12);
        $this->assertSame($appointed, (new PoliticsStateDTO())->utilityRateCaseGrantShare);
    }

    /**
     * The big-state end and the middle keep the founding 15%, no financial centre's left proposing more; the
     * small-state end exempts dividends, as Singapore's one-tier system does.
     */
    public function testTheDividendTaxPlatformsRunFromExemptionToTheFoundingRate(): void
    {
        $half = Diet::FIXED_POSITIONS[Diet::CIVIC][Diet::AXIS_STATE] / 2.0;
        $this->assertEqualsWithDelta(0.15, Platforms::platform(Diet::position(Diet::CIVIC, []))['dividendTax'], 1e-12);
        $this->assertEqualsWithDelta(0.0, Platforms::platform(Diet::position(Diet::VANGUARD, []))['dividendTax'], 1e-12);
        $this->assertEqualsWithDelta(0.15, Platforms::platform([Diet::AXIS_STATE => 0.0])['dividendTax'], 1e-12);
        $this->assertEqualsWithDelta(0.15, Platforms::platform([Diet::AXIS_STATE => $half])['dividendTax'], 1e-12, 'Left of the middle the rate stays where it was founded.');
        $this->assertEqualsWithDelta(0.075, Platforms::platform([Diet::AXIS_STATE => -$half])['dividendTax'], 1e-12);
        $this->assertSame(Valuation::FOUNDING_DIVIDEND_TAX_RATE, (new PoliticsStateDTO())->dividendTaxRate);
        $this->assertSame(Diet::AXIS_STATE, Lever::axes()['dividendTax']);
    }

    /**
     * The closed end keeps half the reserve fund's shares at home, as GPIF has since 2014; the open end none, as
     * Norway's fund; the middle a quarter.
     */
    public function testTheReserveHomeSharePlatformsRunFromNorwayToGpif(): void
    {
        $this->assertEqualsWithDelta(0.5, Platforms::platform(Diet::position(Diet::IRON_HARBOR, []))['reserveHomeShare'], 1e-12);
        $this->assertEqualsWithDelta(0.0, Platforms::platform(Diet::position(Diet::EXCHANGE, []))['reserveHomeShare'], 1e-12);
        $this->assertEqualsWithDelta(0.25, Platforms::platform([Diet::AXIS_OPENNESS => 0.0])['reserveHomeShare'], 1e-12);
        $this->assertEqualsWithDelta(SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE, (new PoliticsStateDTO())->reserveHomeEquityShare, 1e-12);
        $this->assertSame(Diet::AXIS_OPENNESS, Lever::axes()['reserveHomeShare']);
    }

    /**
     * The two ends of the size-of-state axis plan public spending 0.7% of GDP apart, the budget Singapore's parties
     * argue over, the neutral position keeping the founding share.
     */
    public function testThePublicSpendingPlatformsSpanTheManifestoGap(): void
    {
        $civic = Platforms::platform(Diet::position(Diet::CIVIC, []))['publicSpending'];
        $vanguard = Platforms::platform(Diet::position(Diet::VANGUARD, []))['publicSpending'];

        $this->assertEqualsWithDelta(Platforms::POLICY_MANIFESTO_PUBLIC_SPENDING_LOG_GAP, log($civic / $vanguard), 1e-12);
        $this->assertEqualsWithDelta(0.007, $civic - $vanguard, 3e-5, 'The ends part by 0.7% of GDP.');
        $this->assertEqualsWithDelta(MacroConstants::PUBLIC_PURCHASES_TO_GDP, Platforms::platform([Diet::AXIS_STATE => 0.0])['publicSpending'], 1e-15);
    }

    /**
     * A supporter accepts a budget it likes no less than the levers in force, weighing the whole package (Romer &
     * Rosenthal 1978; Tsebelis 2002): a supporter the cabinet's programme would leave worse off holds the budget at its
     * own indifference, every supporter of a cabinet with two is left no worse off, and a majority cabinet answers to
     * no supporter.
     */
    public function testASupporterAcceptsABudgetItLikesNoLessThanTheLeversInForce(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::HOME_POSITIONS;
        $standing = static fn(float $tax): array => ['corporateTax' => $tax] + BudgetRound::standingLevers(new PoliticsStateDTO());
        $programme = static fn(string $party): array => Platforms::partyProgramme($party, $positions);

        $civicHeld = BudgetRound::budget([Diet::VANGUARD], [Diet::CIVIC], $seats, $positions, $standing(0.0));
        $this->assertGreaterThan(
            BudgetBargain::separation($standing(0.0), $programme(Diet::CIVIC)),
            BudgetBargain::separation($civicHeld['platform'], $programme(Diet::CIVIC)),
            'The Vanguard\'s programme would leave the Civic Front worse off than the levers in force',
        );
        $this->assertEqualsWithDelta(
            BudgetBargain::separation($standing(0.0), $programme(Diet::CIVIC)),
            BudgetBargain::separation($civicHeld['levers'], $programme(Diet::CIVIC)),
            1e-9,
            'so the budget stops where the Civic Front is indifferent.',
        );
        $this->assertTrue($civicHeld['supportHeld']['corporateTax']);

        $inForce = Platforms::POLICY_MANIFESTO_CORPORATE_TAX_GAP / 7.0;
        $both = BudgetRound::budget([Diet::VANGUARD], [Diet::EXCHANGE, Diet::CIVIC], $seats, $positions, $standing($inForce));
        foreach ([Diet::EXCHANGE, Diet::CIVIC] as $supporter) {
            $this->assertLessThanOrEqual(
                BudgetBargain::separation($standing($inForce), $programme($supporter)) + 1e-9,
                BudgetBargain::separation($both['levers'], $programme($supporter)),
                "{$supporter} is no worse off.",
            );
        }
        foreach ($both['levers'] as $lever => $value) {
            $this->assertSame($value !== $both['platform'][$lever], $both['supportHeld'][$lever], "{$lever} is held exactly where it falls short of the programme.");
        }

        $majority = BudgetRound::budget([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS], [], $seats, $positions, $standing(0.0));
        $this->assertSame(array_fill_keys(array_keys(Lever::fields()), false), $majority['supportHeld'], 'A majority cabinet answers to no supporter.');
    }

    public function testTheVoteReadsGrowthPerHead(): void
    {
        $engine = $this->engine($this->quietMath());
        $state = $this->electionTick();

        $engine->advance($state, $this->economy(self::ELECTION_AT, laborForceGrowthRate: MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + 0.01), 0.01);

        $this->assertEqualsWithDelta(-0.01, $state->electionGrowthGap, 1e-9, 'Output growing only with the labour force is no growth per head.');
    }

    /**
     * The environment levers are none at all for a cabinet at or past the axis's middle toward growth and rise to the
     * strictest on record at the Tideline Accord's place: the green belt and the extraction rules from 0 to 1, the
     * carbon price from none to the EU ETS's. The founding cabinet, the Vanguard alone, enacts none of them.
     */
    public function testTheEnvironmentLeversRiseFromNoneToTheTidelineAccords(): void
    {
        $at = static fn(float $environment): array => Platforms::platform([Diet::AXIS_ENVIRONMENT => $environment]);

        foreach ([-1.0, -0.5, 0.0] as $growth) {
            $this->assertSame(0.0, $at($growth)['greenBeltStringency']);
            $this->assertSame(0.0, $at($growth)['carbonPrice']);
            $this->assertSame(0.0, $at($growth)['extractionStringency']);
        }
        $this->assertEqualsWithDelta(0.5, $at(0.4)['greenBeltStringency'], 1e-12);
        $this->assertEqualsWithDelta(Platforms::POLICY_GREEN_CARBON_PRICE / 2.0, $at(0.4)['carbonPrice'], 1e-12);
        $tideline = Platforms::platform(Diet::position(Diet::TIDELINE, Diet::HOME_POSITIONS));
        $this->assertSame(1.0, $tideline['greenBeltStringency']);
        $this->assertSame(Platforms::POLICY_GREEN_CARBON_PRICE, $tideline['carbonPrice']);
        $this->assertSame(1.0, $at(1.0)['extractionStringency'], 'Nothing on record is stricter.');

        $founding = Platforms::platform(Platforms::coalitionPosition(Diet::SEED_COALITION, Diet::SEED_SEATS, Diet::HOME_POSITIONS));
        $this->assertSame(0.0, $founding['greenBeltStringency'], 'The founding economy is the calibrated one.');
        $this->assertSame(0.0, $founding['carbonPrice']);
        $this->assertSame(0.0, $founding['extractionStringency']);
    }

    /**
     * A party's programme, the standing levers and the policy handed to the economy all list the levers in one order,
     * so a budget that changes nothing compares equal; and each lever has its property on all four states.
     */
    public function testEveryLeverListIsInPlatformOrder(): void
    {
        $order = array_keys(Platforms::partyProgramme(Diet::CIVIC, Diet::HOME_POSITIONS));

        $this->assertSame($order, array_column(Lever::cases(), 'value'));
        $this->assertSame($order, array_keys(BudgetRound::standingLevers(new PoliticsStateDTO())));
        $this->assertSame($order, array_keys((new PoliticsStateDTO())->policy()->levers));
        foreach (Lever::cases() as $lever) {
            foreach ([PoliticsState::class, PoliticsStateDTO::class, MacroState::class, MacroStateDTO::class] as $state) {
                $this->assertTrue(property_exists($state, $lever->field()), "{$state} keeps {$lever->field()}.");
            }
        }
    }

    /** Each lever moves with the question it is listed under and with no other, from either side of the centre. */
    public function testEachLeverReadsOnlyItsOwnQuestion(): void
    {
        $centre = array_fill_keys(Diet::AXES, 0.0);
        foreach (Diet::AXES as $axis) {
            $towards = [Platforms::platform([$axis => -0.4] + $centre), Platforms::platform([$axis => 0.4] + $centre)];
            foreach (array_intersect_key(Lever::axes(), $towards[0]) as $lever => $own) {
                $moved = $towards[0][$lever] !== $towards[1][$lever];
                $this->assertSame($own === $axis, $moved, "{$lever} " . ($moved ? 'moves' : 'does not move') . " with {$axis}.");
            }
        }
    }

    /**
     * The Tideline Accord, propping up a Civic cabinet, will not vote the carbon price down toward the Front's own; a
     * growth-first majority answers to no one and lifts both.
     */
    public function testTheAccordHoldsTheCarbonPrice(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::HOME_POSITIONS;
        $standing = ['carbonPrice' => 50.0, 'greenBeltStringency' => 0.9] + BudgetRound::standingLevers(new PoliticsStateDTO());
        $civicCarbon = Platforms::platform(Diet::position(Diet::CIVIC, $positions))['carbonPrice'];
        $this->assertLessThan(50.0, $civicCarbon);

        $propped = BudgetRound::budget([Diet::CIVIC], [Diet::TIDELINE], $seats, $positions, $standing);
        $this->assertSame(50.0, $propped['levers']['carbonPrice'], 'The Accord accepts nothing further from its own price.');
        $this->assertTrue($propped['supportHeld']['carbonPrice']);

        $braked = BudgetRound::budget([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS, Diet::NEW_HORIZON], [], $seats, $positions, $standing);
        $this->assertSame($braked['platform']['carbonPrice'], $braked['levers']['carbonPrice'], 'A majority cabinet enacts its own price.');
        $this->assertLessThan(50.0, $braked['levers']['carbonPrice']);
        $this->assertSame(0.0, $braked['levers']['greenBeltStringency'], 'A growth-first majority lifts the green belt whatever the debt.');
    }

    /**
     * The stamp duty runs along the size-of-state axis from the founding 0.05% a side at the Vanguard's end to Hong
     * Kong's 2021 duty, 0.13% a side, at the Civic Front's; it goes to the reserve fund, so the Council does not guard
     * it.
     */
    public function testTheStampDutyRunsFromTheFoundingRateToHongKongsDuty(): void
    {
        $positions = Diet::HOME_POSITIONS;

        $this->assertEqualsWithDelta(FinancialConstants::STAMP_DUTY_RATE, Platforms::platform(Diet::position(Diet::VANGUARD, $positions))['stampDutyRate'], 1e-15, 'The founding cabinet keeps the founding duty.');
        $this->assertEqualsWithDelta(Platforms::POLICY_BIG_STATE_STAMP_DUTY, Platforms::platform(Diet::position(Diet::CIVIC, $positions))['stampDutyRate'], 1e-15);
        $this->assertEqualsWithDelta((FinancialConstants::STAMP_DUTY_RATE + Platforms::POLICY_BIG_STATE_STAMP_DUTY) / 2.0, Platforms::platform([Diet::AXIS_STATE => 0.0])['stampDutyRate'], 1e-15);
    }

    /**
     * The bank levy runs from none at the Vanguard's end to Ireland's levy at the Civic Front's; net taxes give back
     * what it raises, so the Council does not guard it.
     */
    public function testTheBankLevyRunsFromNoneToTheUksPeak(): void
    {
        $positions = Diet::HOME_POSITIONS;

        $this->assertEqualsWithDelta(0.0, Platforms::platform(Diet::position(Diet::VANGUARD, $positions))['bankLevyRate'], 1e-15, 'The founding cabinet levies none.');
        $this->assertEqualsWithDelta(Platforms::POLICY_BIG_STATE_BANK_LEVY, Platforms::platform(Diet::position(Diet::CIVIC, $positions))['bankLevyRate'], 1e-15);
    }

    /**
     * The share of the reserve fund's return spent runs from the founding half at the Vanguard's end to 60% at the
     * Civic Front's. The fund holds the second key on anything above half: it consents only while unemployment shows a
     * recession under way, and out of one the share above lapses, whatever the Council would say of the revenue.
     */
    public function testTheFundHoldsTheSecondKeyOnTheDrawAboveHalf(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $this->assertEqualsWithDelta(MacroConstants::RESERVE_DRAW_CEILING, Platforms::platform(Diet::position(Diet::VANGUARD, $positions))['reserveDrawShare'], 1e-15);
        $this->assertEqualsWithDelta(Platforms::POLICY_BIG_STATE_RESERVE_DRAW_SHARE, Platforms::platform(Diet::position(Diet::CIVIC, $positions))['reserveDrawShare'], 1e-15);

        $founding = BudgetRound::standingLevers(new PoliticsStateDTO());
        $refused = BudgetRound::budget([Diet::CIVIC], [], Diet::SEED_SEATS, $positions, $founding);
        $this->assertSame(MacroConstants::RESERVE_DRAW_CEILING, $refused['levers']['reserveDrawShare'], 'Out of a recession the fund refuses a draw above half.');
        $this->assertTrue($refused['fundHeld']);
        $this->assertEqualsWithDelta(Platforms::POLICY_BIG_STATE_RESERVE_DRAW_SHARE, $refused['platform']['reserveDrawShare'], 1e-15);

        $consented = BudgetRound::budget([Diet::CIVIC], [], Diet::SEED_SEATS, $positions, $founding, recession: true);
        $this->assertEqualsWithDelta(Platforms::POLICY_BIG_STATE_RESERVE_DRAW_SHARE, $consented['levers']['reserveDrawShare'], 1e-15, 'In a declared recession it consents.');
        $this->assertFalse($consented['fundHeld']);

        // The slump over, a big-state cabinet's draw lapses back to half on the fund's refusal.
        $drawn = BudgetRound::standingLevers(new PoliticsStateDTO(reserveDrawShare: Platforms::POLICY_BIG_STATE_RESERVE_DRAW_SHARE));
        $lapsed = BudgetRound::budget([Diet::CIVIC], [], Diet::SEED_SEATS, $positions, $drawn);
        $this->assertSame(MacroConstants::RESERVE_DRAW_CEILING, $lapsed['levers']['reserveDrawShare']);
        $this->assertTrue($lapsed['fundHeld']);

        // Spending less than half needs no consent.
        $kept = BudgetRound::budget([Diet::VANGUARD], [], Diet::SEED_SEATS, $positions, $founding);
        $this->assertSame(MacroConstants::RESERVE_DRAW_CEILING, $kept['levers']['reserveDrawShare']);
        $this->assertFalse($kept['fundHeld']);
    }

    /**
     * A cabinet's debt plan is its parties' planks weighted by seats. A supporter that does not own the size-of-state
     * question trades debt within the package; one that does will not vote debt further from its own target than it
     * stands.
     */
    public function testTheDebtPlanIsTheCabinetsPlanksWeightedBySeats(): void
    {
        $seats = [Diet::VANGUARD => 60, Diet::EXCHANGE => 40];
        $plan = Platforms::debtPlanks([Diet::VANGUARD, Diet::EXCHANGE], $seats);
        $this->assertEqualsWithDelta(0.6 * 0.35 + 0.4 * 0.45, $plan['debtTarget'], 1e-12);
        $this->assertEqualsWithDelta(0.6 * 0.010 + 0.4 * 0.005, $plan['debtPace'], 1e-12);

        $standing = BudgetRound::standingLevers(new PoliticsStateDTO());
        $alone = BudgetRound::budget([Diet::VANGUARD], [], Diet::SEED_SEATS, Diet::HOME_POSITIONS, $standing);
        $this->assertSame(Diet::DEBT_PLANKS[Diet::VANGUARD]['target'], $alone['levers']['debtTarget']);
        $this->assertSame(Diet::DEBT_PLANKS[Diet::VANGUARD]['pace'], $alone['levers']['debtPace']);

        $traded = BudgetRound::budget([Diet::VANGUARD], [Diet::COMMON_LOT], Diet::SEED_SEATS, Diet::HOME_POSITIONS, $standing);
        $this->assertLessThan($standing['debtTarget'], $traded['levers']['debtTarget'], 'The Common Lot trades debt for the rest of the package');
        $this->assertGreaterThan(Diet::DEBT_PLANKS[Diet::VANGUARD]['target'], $traded['levers']['debtTarget'], 'but not all of it.');
        $this->assertTrue($traded['supportHeld']['debtTarget']);

        $guarded = BudgetRound::budget([Diet::VANGUARD], [Diet::CIVIC], Diet::SEED_SEATS, Diet::HOME_POSITIONS, $standing);
        $this->assertSame($standing['debtTarget'], $guarded['levers']['debtTarget'], 'The Civic Front owns the size of the state and will not vote debt down.');
    }
}
