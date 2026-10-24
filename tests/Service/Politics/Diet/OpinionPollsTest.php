<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Diet;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\OpinionPolls;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use App\Tests\Support\InMemoryRedis;
use PHPUnit\Framework\TestCase;

class OpinionPollsTest extends TestCase
{
    use OpinionPollsFixtures;

    /**
     * The normal votes take a neutral step, a share's variance times the years, and are put back on a whole; with no
     * draw nothing moves. The lasting shares move with their normals, keeping each party's lead on its normal vote.
     */
    public function testTheNormalVotesWalkAndCarryTheLastingShares(): void
    {
        $normals = Diet::SEED_VOTE_SHARES;
        $lasting = array_merge($normals, [Diet::CIVIC => $normals[Diet::CIVIC] + 0.02, Diet::VANGUARD => $normals[Diet::VANGUARD] - 0.02]);
        [$still, $unmoved] = OpinionPolls::walkNormals($normals, $lasting, 4.0, []);
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta($normals[$party], $still[$party], 1e-15, $party);
            $this->assertEqualsWithDelta($lasting[$party], $unmoved[$party], 1e-15, $party);
        }

        $n = $normals[Diet::CIVIC];
        $step = sqrt(Election::normalVoteDriftVariance(2.0) * $n);
        [$walked, $carried] = OpinionPolls::walkNormals($normals, $lasting, 2.0, [Diet::CIVIC => 1.0]);
        $this->assertEqualsWithDelta(($n + $step) / (1.0 + $step), $walked[Diet::CIVIC], 1e-15, 'The step, then back on a whole.');
        $this->assertEqualsWithDelta($normals[Diet::VANGUARD] / (1.0 + $step), $walked[Diet::VANGUARD], 1e-15);
        $this->assertEqualsWithDelta(1.0, array_sum($walked), 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($carried), 1e-12);
        $lead = static fn(array $shares, array $normal): float => log($shares[Diet::CIVIC] / $normal[Diet::CIVIC]) - log($shares[Diet::VANGUARD] / $normal[Diet::VANGUARD]);
        $this->assertEqualsWithDelta($lead($lasting, $normals), $lead($carried, $walked), 1e-12, 'The lead on the normal vote is carried, not reset.');

        [$floored] = OpinionPolls::walkNormals($normals, [], 100.0, [Diet::COMMON_LOT => -50.0]);
        $this->assertGreaterThanOrEqual(Election::MIN_VOTE_SHARE * 0.99, $floored[Diet::COMMON_LOT], 'A collapsing party is kept a rump.');
    }

    /**
     * A normal vote away from its founding share comes halfway back over a half-life with nothing drawn, and the
     * shares stay a whole; one at its founding share stays there.
     */
    public function testANormalVoteIsPulledBackTowardItsFoundingShare(): void
    {
        $founding = Diet::SEED_VOTE_SHARES;
        $moved = array_merge($founding, [Diet::CIVIC => $founding[Diet::CIVIC] + 0.10, Diet::VANGUARD => $founding[Diet::VANGUARD] - 0.10]);

        [$back] = OpinionPolls::walkNormals($moved, [], Election::NORMAL_VOTE_HALF_LIFE_YEARS, []);

        $this->assertEqualsWithDelta($founding[Diet::CIVIC] + 0.05, $back[Diet::CIVIC], 1e-12);
        $this->assertEqualsWithDelta($founding[Diet::VANGUARD] - 0.05, $back[Diet::VANGUARD], 1e-12);
        $this->assertEqualsWithDelta($founding[Diet::EXCHANGE], $back[Diet::EXCHANGE], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($back), 1e-12);
        $this->assertEqualsWithDelta(Election::NORMAL_VOTE_DRIFT_VARIANCE, Election::normalVoteDriftVariance(1e-6) / 1e-6, 1e-8, 'Over a moment the drift is the yearly variance\'s.');
        $this->assertLessThan(Election::NORMAL_VOTE_DRIFT_VARIANCE * 100.0, Election::normalVoteDriftVariance(100.0), 'Over a century the pull back holds it in.');
    }

    /**
     * A lasting lead on the normal vote decays by a term's persistence over a term, a draw moves the party by a term's
     * lasting swing, and the Diet at its normal votes stays there.
     */
    public function testALastingLeadDriftsBackTowardTheNormalVote(): void
    {
        $persistence = Election::NORMAL_VOTE_PERSISTENCE ** Election::TERM_YEARS;
        $lead = static fn(array $shares): float => log($shares[Diet::CIVIC] / $shares[Diet::VANGUARD]) - log(Diet::SEED_VOTE_SHARES[Diet::CIVIC] / Diet::SEED_VOTE_SHARES[Diet::VANGUARD]);

        $atNormal = OpinionPolls::driftLasting(Diet::SEED_VOTE_SHARES, array_fill_keys(Diet::PARTIES, Election::TERM_YEARS), [], Diet::SEED_VOTE_SHARES);
        $ahead = [Diet::CIVIC => Diet::SEED_VOTE_SHARES[Diet::CIVIC] + 0.08, Diet::VANGUARD => Diet::SEED_VOTE_SHARES[Diet::VANGUARD] - 0.08] + Diet::SEED_VOTE_SHARES;
        $reverted = OpinionPolls::driftLasting($ahead, array_fill_keys(Diet::PARTIES, Election::TERM_YEARS), [], Diet::SEED_VOTE_SHARES);
        $swung = OpinionPolls::driftLasting(Diet::SEED_VOTE_SHARES, array_fill_keys(Diet::PARTIES, Election::TERM_YEARS), [Diet::CIVIC => 1.0], Diet::SEED_VOTE_SHARES);

        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_VOTE_SHARES[$party], $atNormal[$party], 1e-12);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($reverted), 1e-12);
        $this->assertEqualsWithDelta($persistence * $lead($ahead), $lead($reverted), 1e-12);
        $this->assertLessThan($ahead[Diet::CIVIC], $reverted[Diet::CIVIC]);
        $this->assertGreaterThan(Diet::SEED_VOTE_SHARES[Diet::CIVIC], $reverted[Diet::CIVIC]);
        $this->assertEqualsWithDelta(Election::swingSd(Election::LASTING_SWING_VARIANCE, Diet::SEED_VOTE_SHARES[Diet::CIVIC]), $lead($swung), 1e-12, 'A term in one step takes the vote\'s lasting swing.');
    }

    /** A term walked a month at a time drifts back as far, and spreads the parties as far, as a term in one step. */
    public function testMonthsAddUpToATerm(): void
    {
        $months = (int) (12 * Election::TERM_YEARS);
        $ahead = [Diet::CIVIC => Diet::SEED_VOTE_SHARES[Diet::CIVIC] + 0.08, Diet::VANGUARD => Diet::SEED_VOTE_SHARES[Diet::VANGUARD] - 0.08] + Diet::SEED_VOTE_SHARES;
        $stepped = $ahead;
        for ($month = 0; $month < $months; ++$month) {
            $stepped = OpinionPolls::driftLasting($stepped, array_fill_keys(Diet::PARTIES, 1.0 / 12.0), [], Diet::SEED_VOTE_SHARES);
        }
        $whole = OpinionPolls::driftLasting($ahead, array_fill_keys(Diet::PARTIES, Election::TERM_YEARS), [], Diet::SEED_VOTE_SHARES);
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta($whole[$party], $stepped[$party], 1e-12);
        }

        mt_srand(41);
        $math = new MathUtility();
        $gaps = [];
        for ($run = 0; $run < 3000; ++$run) {
            $shares = Diet::SEED_VOTE_SHARES;
            for ($month = 0; $month < $months; ++$month) {
                $draws = [];
                foreach (Diet::PARTIES as $party) {
                    $draws[$party] = $math->generateStandardNormal();
                }
                $shares = OpinionPolls::driftLasting($shares, array_fill_keys(Diet::PARTIES, 1.0 / 12.0), $draws, Diet::SEED_VOTE_SHARES);
            }
            $gaps[] = log($shares[Diet::CIVIC] / $shares[Diet::VANGUARD]) - log(Diet::SEED_VOTE_SHARES[Diet::CIVIC] / Diet::SEED_VOTE_SHARES[Diet::VANGUARD]);
        }
        // The two parties' lasting swings are independent: their log lead takes both variances.
        $expected = Election::LASTING_SWING_VARIANCE * ((1.0 / Diet::SEED_VOTE_SHARES[Diet::CIVIC]) + (1.0 / Diet::SEED_VOTE_SHARES[Diet::VANGUARD]));
        $variance = array_sum(array_map(static fn(float $gap): float => $gap ** 2, $gaps)) / count($gaps);
        $this->assertEqualsWithDelta($expected, $variance, 0.08 * $expected);
    }

    /**
     * A short-term swing halves over the post-vote half-life; the campaign's final days build the vote's swing however
     * they are cut, and a span outside them builds none.
     */
    public function testTheShortTermSwingBuildsInTheCampaignAndFadesAfter(): void
    {
        $faded = OpinionPolls::fadeShortTerm([Diet::CIVIC => 0.2], OpinionPolls::POST_VOTE_HALF_LIFE_YEARS, 0.0, [Diet::CIVIC => 1.0], Diet::SEED_VOTE_SHARES);
        $this->assertEqualsWithDelta(0.1, $faded[Diet::CIVIC], 1e-12);
        $this->assertSame(0.0, $faded[Diet::VANGUARD]);

        $campaign = OpinionPolls::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR;
        $whole = OpinionPolls::fadeShortTerm([], $campaign, $campaign, [Diet::CIVIC => 1.0], Diet::SEED_VOTE_SHARES)[Diet::CIVIC];
        $this->assertEqualsWithDelta(Election::swingSd(Election::SHORT_TERM_SWING_VARIANCE, Diet::SEED_VOTE_SHARES[Diet::CIVIC]), $whole, 1e-12);

        // Two halves: the first's swing, faded through the second, and the second's add to the whole's variance.
        $half = OpinionPolls::fadeShortTerm([], $campaign / 2.0, $campaign / 2.0, [Diet::CIVIC => 1.0], Diet::SEED_VOTE_SHARES)[Diet::CIVIC];
        $carried = OpinionPolls::fadeShortTerm([Diet::CIVIC => $half], $campaign / 2.0, $campaign / 2.0, [], Diet::SEED_VOTE_SHARES)[Diet::CIVIC];
        $this->assertEqualsWithDelta($whole ** 2, ($carried ** 2) + ($half ** 2), 1e-12);
    }

    /**
     * The campaign's final days are those before the vote a span runs toward, even when the clock lands a hair past it.
     */
    public function testTheCampaignIsTheFinalDaysBeforeTheVote(): void
    {
        $campaign = OpinionPolls::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR;
        $this->assertEqualsWithDelta($campaign, OpinionPolls::campaignOverlap(self::ELECTION_AT - (1.0 / 12.0), self::ELECTION_AT), 1e-12);
        $this->assertEqualsWithDelta($campaign, OpinionPolls::campaignOverlap(self::ELECTION_AT - (1.0 / 12.0), self::ELECTION_AT + 1e-9), 1e-8);
        $this->assertEqualsWithDelta(10.0 / FinancialConstants::DAYS_PER_YEAR, OpinionPolls::campaignOverlap(self::ELECTION_AT - (10.0 / FinancialConstants::DAYS_PER_YEAR), self::ELECTION_AT), 1e-12);
        $this->assertSame(0.0, OpinionPolls::campaignOverlap(self::ELECTION_AT - (2.0 / 12.0), self::ELECTION_AT - (1.0 / 12.0)));
        $this->assertSame(0.0, OpinionPolls::campaignOverlap(self::ELECTION_AT, self::ELECTION_AT + (1.0 / 12.0)), 'The next campaign is a term away.');
    }

    /**
     * The government pays half its term's cost of ruling within the half-life of the vote and all of it by the next.
     */
    public function testTheGovernmentPaysMostOfItsCostEarly(): void
    {
        $half = OpinionPolls::POST_VOTE_HALF_LIFE_YEARS;
        $this->assertSame(0.0, OpinionPolls::termClock(0.0));
        $this->assertEqualsWithDelta(1.0, OpinionPolls::termClock(Election::TERM_YEARS), 1e-12);
        $this->assertEqualsWithDelta(0.5 / (1.0 - (0.5 ** (Election::TERM_YEARS / $half))), OpinionPolls::termClock($half), 1e-12);
        $this->assertGreaterThan(0.5, OpinionPolls::termClock(1.0), 'Most of it in the first year.');
        $this->assertSame(1.0, OpinionPolls::termClock(Election::TERM_YEARS + 1.0));
    }

    /**
     * A party governing from vote to vote drifts toward its normal vote on the term clock, mostly in the first year;
     * any other on the calendar; and every party has drifted exactly a term by the vote, a party that joins the cabinet
     * mid-term included.
     */
    public function testTheGovernmentsDriftComesEarlyAndEveryPartyDriftsATerm(): void
    {
        $state = $this->termStart();
        $state->supportDriftYears = [];
        $total = array_fill_keys(Diet::PARTIES, 0.0);
        $atTwelve = [];
        for ($month = 1; $month <= 48; ++$month) {
            if ($month === 25) {
                $state->governingCoalition = Diet::membership([Diet::VANGUARD, Diet::EXCHANGE, Diet::CIVIC]);
            }
            $years = OpinionPolls::driftYears($state, ($month - 1) / 12.0, $month / 12.0);
            foreach ($years as $party => $partyYears) {
                $total[$party] += $partyYears;
                $state->supportDriftYears[$party] = $total[$party];
            }
            if ($month === 12) {
                $atTwelve = $total;
            }
        }

        $this->assertEqualsWithDelta(Election::TERM_YEARS * OpinionPolls::termClock(1.0), $atTwelve[Diet::VANGUARD], 1e-9, 'The cabinet takes the term clock\'s first year.');
        $this->assertEqualsWithDelta(1.0, $atTwelve[Diet::IRON_HARBOR], 1e-9, 'The opposition drifts on the calendar.');
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Election::TERM_YEARS, $total[$party], 1e-9, $party);
        }
    }

    /**
     * The polls misread opinion yet to settle: the whole misreading until the campaign's final days, nothing on the
     * day; across many polls a poll's error on support is its sampling error and that misreading together, and the vote
     * never carries it.
     */
    public function testThePollsMisreadUnsettledOpinion(): void
    {
        $this->assertSame(0.0, OpinionPolls::unsettledSd(0.25, 0.0));
        $this->assertEqualsWithDelta(sqrt(OpinionPolls::UNSETTLED_VARIANCE * 3.0), OpinionPolls::unsettledSd(0.25, 1.0 / 12.0), 1e-12);
        $this->assertEqualsWithDelta(OpinionPolls::unsettledSd(0.25, 1.0 / 12.0) / sqrt(2.0), OpinionPolls::unsettledSd(0.25, (OpinionPolls::CAMPAIGN_SWING_DAYS / 2.0) / FinancialConstants::DAYS_PER_YEAR), 1e-12);

        $publish = new \ReflectionMethod(OpinionPolls::class, 'publish');
        $state = $this->termStart();
        $state->dietVoteShares = Diet::SEED_VOTE_SHARES;
        $party = Diet::CIVIC;
        $z2 = [];
        $misread = [];
        for ($month = 1; $month <= 12 * 400; ++$month) {
            $state->totalTime = $month / 12.0 + 1e-6;
            $publish->invoke(null, $state, 7);
            $share = OpinionPolls::support($state)[$party];
            $poll = end($state->polls);
            $this->assertNotFalse($poll, 'every month publishes a poll');
            $z2[] = (($poll['shares'][$party] - $share) ** 2) / ($share * (1.0 - $share));
            $misread[] = $poll['shares'][Diet::VANGUARD] - OpinionPolls::support($state)[Diet::VANGUARD];
        }
        $expected = (1.0 / OpinionPolls::EFFECTIVE_SAMPLE_SIZE) + OpinionPolls::UNSETTLED_VARIANCE;
        $this->assertEqualsWithDelta($expected, array_sum($z2) / count($z2), 0.25 * $expected);
        $this->assertGreaterThan(1.6 / OpinionPolls::EFFECTIVE_SAMPLE_SIZE, array_sum($z2) / count($z2), 'More than sampling alone.');
        $this->assertEqualsWithDelta(0.0, array_sum($misread) / count($misread), 0.003, 'The misreading favours no party: a large one is not read low on average.');
    }

    /**
     * The voters count none of the campaign's growth before it opens, and on the day count the growth and inflation the
     * vote weighs.
     */
    public function testTheEconomyIsCountedAsTheVoteWillWeighIt(): void
    {
        $state = new PoliticsState();
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = 1.0;
        $state->campaignStartedAt = 0.0;
        $state->campaignStartRealGdp = 1.0;
        $boom = new MacroStateDTO(totalTime: 2.0, potentialGdpIndex: 1.1, consumerPriceLevel: 1.2);
        $this->assertSame(0.0, OpinionPolls::growthCounted($state, $boom), 'The campaign has not opened this term.');

        $state->totalTime = self::ELECTION_AT;
        $state->campaignStartedAt = self::ELECTION_AT - Election::CAMPAIGN_WINDOW_YEARS;
        $economy = new MacroStateDTO(totalTime: self::ELECTION_AT, potentialGdpIndex: 1.03, consumerPriceLevel: 1.12);
        $trend = MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + MacroConstants::TFP_DRIFT;
        $this->assertEqualsWithDelta((log(1.03) / Election::CAMPAIGN_WINDOW_YEARS) - $trend, OpinionPolls::growthCounted($state, $economy), 1e-12);
        $this->assertEqualsWithDelta((log(1.12) / self::ELECTION_AT) - MacroConstants::TARGET_INFLATION, OpinionPolls::inflationCounted($state, $economy), 1e-12);
    }

    /**
     * In a coalition the growth vote goes to the party leading it: a year of growth above trend lifts the Vanguard's
     * support and leaves its partner's no higher.
     */
    public function testACoalitionsGrowthVoteGoesToItsLeadingParty(): void
    {
        $at = self::ELECTION_AT - 0.25;
        $opened = self::ELECTION_AT - Election::CAMPAIGN_WINDOW_YEARS;
        [$trend, $boom] = [$this->termStart(), $this->termStart()];
        foreach ([$trend, $boom] as $state) {
            $state->totalTime = $at;
            $state->campaignStartedAt = $opened;
            $state->campaignStartRealGdp = Election::realGdp($this->economy($opened));
        }
        OpinionPolls::update($trend, $this->economy($at), $this->quietMath());
        $booming = $this->economy($at);
        OpinionPolls::update($boom, new MacroStateDTO(totalTime: $at, potentialGdpIndex: $booming->potentialGdpIndex * exp(0.02), consumerPriceLevel: $booming->consumerPriceLevel), $this->quietMath());

        $this->assertSame(Diet::VANGUARD, \App\Service\Politics\Diet\PartyLeaders::primeMinisterParty($boom));
        $this->assertGreaterThan(OpinionPolls::support($trend)[Diet::VANGUARD], OpinionPolls::support($boom)[Diet::VANGUARD]);
        $this->assertLessThanOrEqual(OpinionPolls::support($trend)[Diet::EXCHANGE], OpinionPolls::support($boom)[Diet::EXCHANGE]);
    }

    /** A poll's errors sum to nothing and have the multinomial's variance, p(1 - p) / n, on each party. */
    public function testAPollHasTheSamplesError(): void
    {
        mt_srand(3);
        $math = new MathUtility();
        $sum = array_fill_keys(Diet::PARTIES, 0.0);
        $squares = array_fill_keys(Diet::PARTIES, 0.0);
        $runs = 20000;
        for ($run = 0; $run < $runs; ++$run) {
            $draws = [];
            foreach (Diet::PARTIES as $party) {
                $draws[$party] = $math->generateStandardNormal();
            }
            $poll = OpinionPolls::sample(Diet::SEED_VOTE_SHARES, $draws, 1000.0);
            $this->assertEqualsWithDelta(1.0, array_sum($poll), 1e-12);
            foreach (Diet::PARTIES as $party) {
                $sum[$party] += $poll[$party] - Diet::SEED_VOTE_SHARES[$party];
                $squares[$party] += ($poll[$party] - Diet::SEED_VOTE_SHARES[$party]) ** 2;
            }
        }

        foreach ([Diet::CIVIC, Diet::VANGUARD, Diet::EXCHANGE] as $party) {
            $share = Diet::SEED_VOTE_SHARES[$party];
            $this->assertEqualsWithDelta(0.0, $sum[$party] / $runs, 0.0005, $party);
            $this->assertEqualsWithDelta($share * (1.0 - $share) / 1000.0, $squares[$party] / $runs, 0.05 * $share * (1.0 - $share) / 1000.0, $party);
        }
    }

    /** Support read off a state that has none yet is the last vote's result. */
    public function testSupportBeforeTheFirstUpdateIsTheLastResult(): void
    {
        $state = new PoliticsStateDTO(partyShortTermShocks: [Diet::CIVIC => 0.1, Diet::COMMON_LOT => -0.2]);
        $support = OpinionPolls::support($state);
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_VOTE_SHARES[$party], $support[$party], 1e-12);
        }
    }

    /** The polls and support survive the round trip through Redis. */
    public function testThePollsSurviveTheRoundTrip(): void
    {
        $engine = new Politics(MathUtility::ownStream(2), new InMemoryRedis());
        $state = $this->termStart();
        for ($month = 1; $month <= 6; ++$month) {
            $engine->advance($state, $this->economy($month / 12.0), 1.0 / 12.0);
        }

        $decoded = json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($state, PoliticsState::fromArray($decoded));
        $this->assertCount(6, $state->polls);
    }
}
