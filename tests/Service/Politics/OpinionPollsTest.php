<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\OpinionPolls;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class OpinionPollsTest extends TestCase
{
    /** The first vote on the calendar. */
    private const ELECTION_AT = Politics::ELECTION_TERM_YEARS;

    /** A lasting lead on the normal vote decays by a term's persistence over a term, a draw moves the party by a term's lasting swing, and the Diet at its normal votes stays there. */
    public function testALastingLeadDriftsBackTowardTheNormalVote(): void
    {
        $math = new MathUtility();
        $persistence = Politics::ELECTION_NORMAL_VOTE_PERSISTENCE ** Politics::ELECTION_TERM_YEARS;
        $lead = static fn(array $shares): float => log($shares[Diet::CIVIC] / $shares[Diet::VANGUARD]) - log(Diet::SEED_VOTE_SHARES[Diet::CIVIC] / Diet::SEED_VOTE_SHARES[Diet::VANGUARD]);

        $atNormal = OpinionPolls::driftLasting(Diet::SEED_VOTE_SHARES, Politics::ELECTION_TERM_YEARS, [], $math);
        $ahead = [Diet::CIVIC => Diet::SEED_VOTE_SHARES[Diet::CIVIC] + 0.08, Diet::VANGUARD => Diet::SEED_VOTE_SHARES[Diet::VANGUARD] - 0.08] + Diet::SEED_VOTE_SHARES;
        $reverted = OpinionPolls::driftLasting($ahead, Politics::ELECTION_TERM_YEARS, [], $math);
        $swung = OpinionPolls::driftLasting(Diet::SEED_VOTE_SHARES, Politics::ELECTION_TERM_YEARS, [Diet::CIVIC => 1.0], $math);

        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta(Diet::SEED_VOTE_SHARES[$party], $atNormal[$party], 1e-12);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($reverted), 1e-12);
        $this->assertEqualsWithDelta($persistence * $lead($ahead), $lead($reverted), 1e-12);
        $this->assertLessThan($ahead[Diet::CIVIC], $reverted[Diet::CIVIC]);
        $this->assertGreaterThan(Diet::SEED_VOTE_SHARES[Diet::CIVIC], $reverted[Diet::CIVIC]);
        $this->assertEqualsWithDelta(Politics::swingSd(Politics::LASTING_SWING_VARIANCE, Diet::CIVIC), $lead($swung), 1e-12, 'A term in one step takes the vote\'s lasting swing.');
    }

    /** A term walked a month at a time drifts back as far, and spreads the parties as far, as a term in one step. */
    public function testMonthsAddUpToATerm(): void
    {
        $months = (int) (12 * Politics::ELECTION_TERM_YEARS);
        $ahead = [Diet::CIVIC => Diet::SEED_VOTE_SHARES[Diet::CIVIC] + 0.08, Diet::VANGUARD => Diet::SEED_VOTE_SHARES[Diet::VANGUARD] - 0.08] + Diet::SEED_VOTE_SHARES;
        $quiet = new MathUtility();
        $stepped = $ahead;
        for ($month = 0; $month < $months; ++$month) {
            $stepped = OpinionPolls::driftLasting($stepped, 1.0 / 12.0, [], $quiet);
        }
        $whole = OpinionPolls::driftLasting($ahead, Politics::ELECTION_TERM_YEARS, [], $quiet);
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
                $shares = OpinionPolls::driftLasting($shares, 1.0 / 12.0, $draws, $math);
            }
            $gaps[] = log($shares[Diet::CIVIC] / $shares[Diet::VANGUARD]) - log(Diet::SEED_VOTE_SHARES[Diet::CIVIC] / Diet::SEED_VOTE_SHARES[Diet::VANGUARD]);
        }
        // The two parties' lasting swings are independent: their log lead takes both variances.
        $expected = Politics::LASTING_SWING_VARIANCE * ((1.0 / Diet::SEED_VOTE_SHARES[Diet::CIVIC]) + (1.0 / Diet::SEED_VOTE_SHARES[Diet::VANGUARD]));
        $variance = array_sum(array_map(static fn(float $gap): float => $gap ** 2, $gaps)) / count($gaps);
        $this->assertEqualsWithDelta($expected, $variance, 0.08 * $expected);
    }

    /** A short-term swing halves over the post-vote half-life; the campaign's final days build the vote's swing however they are cut, and a span outside them builds none. */
    public function testTheShortTermSwingBuildsInTheCampaignAndFadesAfter(): void
    {
        $math = new MathUtility();
        $faded = OpinionPolls::fadeShortTerm([Diet::CIVIC => 0.2], OpinionPolls::POST_VOTE_HALF_LIFE_YEARS, 0.0, [Diet::CIVIC => 1.0], $math);
        $this->assertEqualsWithDelta(0.1, $faded[Diet::CIVIC], 1e-12);
        $this->assertSame(0.0, $faded[Diet::VANGUARD]);

        $campaign = OpinionPolls::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR;
        $whole = OpinionPolls::fadeShortTerm([], $campaign, $campaign, [Diet::CIVIC => 1.0], $math)[Diet::CIVIC];
        $this->assertEqualsWithDelta(Politics::swingSd(Politics::ELECTION_SHORT_TERM_SWING_VARIANCE, Diet::CIVIC), $whole, 1e-12);

        // Two halves: the first's swing, faded through the second, and the second's add to the whole's variance.
        $half = OpinionPolls::fadeShortTerm([], $campaign / 2.0, $campaign / 2.0, [Diet::CIVIC => 1.0], $math)[Diet::CIVIC];
        $carried = OpinionPolls::fadeShortTerm([Diet::CIVIC => $half], $campaign / 2.0, $campaign / 2.0, [], $math)[Diet::CIVIC];
        $this->assertEqualsWithDelta($whole ** 2, ($carried ** 2) + ($half ** 2), 1e-12);
    }

    /** The campaign's final days are those before the vote a span runs toward, even when the clock lands a hair past it. */
    public function testTheCampaignIsTheFinalDaysBeforeTheVote(): void
    {
        $campaign = OpinionPolls::CAMPAIGN_SWING_DAYS / FinancialConstants::DAYS_PER_YEAR;
        $this->assertEqualsWithDelta($campaign, OpinionPolls::campaignOverlap(self::ELECTION_AT - (1.0 / 12.0), self::ELECTION_AT), 1e-12);
        $this->assertEqualsWithDelta($campaign, OpinionPolls::campaignOverlap(self::ELECTION_AT - (1.0 / 12.0), self::ELECTION_AT + 1e-9), 1e-8);
        $this->assertEqualsWithDelta(10.0 / FinancialConstants::DAYS_PER_YEAR, OpinionPolls::campaignOverlap(self::ELECTION_AT - (10.0 / FinancialConstants::DAYS_PER_YEAR), self::ELECTION_AT), 1e-12);
        $this->assertSame(0.0, OpinionPolls::campaignOverlap(self::ELECTION_AT - (2.0 / 12.0), self::ELECTION_AT - (1.0 / 12.0)));
        $this->assertSame(0.0, OpinionPolls::campaignOverlap(self::ELECTION_AT, self::ELECTION_AT + (1.0 / 12.0)), 'The next campaign is a term away.');
    }

    /** The government pays half its term's cost of ruling within the half-life of the vote and all of it by the next. */
    public function testTheGovernmentPaysMostOfItsCostEarly(): void
    {
        $half = OpinionPolls::POST_VOTE_HALF_LIFE_YEARS;
        $this->assertSame(0.0, OpinionPolls::termClock(0.0));
        $this->assertEqualsWithDelta(1.0, OpinionPolls::termClock(Politics::ELECTION_TERM_YEARS), 1e-12);
        $this->assertEqualsWithDelta(0.5 / (1.0 - (0.5 ** (Politics::ELECTION_TERM_YEARS / $half))), OpinionPolls::termClock($half), 1e-12);
        $this->assertGreaterThan(0.5, OpinionPolls::termClock(1.0), 'Most of it in the first year.');
        $this->assertSame(1.0, OpinionPolls::termClock(Politics::ELECTION_TERM_YEARS + 1.0));
    }

    /** The voters count none of the campaign's growth before it opens, and on the day count the growth and inflation the vote weighs. */
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
        $state->campaignStartedAt = self::ELECTION_AT - Politics::ELECTION_CAMPAIGN_WINDOW_YEARS;
        $economy = new MacroStateDTO(totalTime: self::ELECTION_AT, potentialGdpIndex: 1.03, consumerPriceLevel: 1.12);
        $trend = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT;
        $this->assertEqualsWithDelta((log(1.03) / Politics::ELECTION_CAMPAIGN_WINDOW_YEARS) - $trend, OpinionPolls::growthCounted($state, $economy), 1e-12);
        $this->assertEqualsWithDelta((log(1.12) / self::ELECTION_AT) - MacroEngine::TARGET_INFLATION, OpinionPolls::inflationCounted($state, $economy), 1e-12);
    }

    /** With nothing drawn, a term walked a month at a time votes as a term in one step: the cabinet pays the cost of ruling either way. */
    public function testAMonthlyTermVotesAsATermInOneStep(): void
    {
        $quiet = $this->quietMath();
        $whole = $this->termStart();
        (new Politics($quiet, $this->inMemoryRedis()))->advance($whole, $this->economy(self::ELECTION_AT), 0.01);

        $monthly = $this->termStart();
        $engine = new Politics($quiet, $this->inMemoryRedis());
        for ($month = 1; $month <= 48; ++$month) {
            $engine->advance($monthly, $this->economy($month / 12.0), 1.0 / 12.0);
        }

        $this->assertSame(self::ELECTION_AT, $monthly->lastElectionAt);
        $this->assertSame([], $monthly->polls, 'The vote puts the term\'s polls away.');
        $this->assertEqualsWithDelta(-Politics::ELECTION_COST_OF_RULING, $whole->electionIncumbentSwing, 1e-9);
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta($whole->dietVoteShares[$party], $monthly->dietVoteShares[$party], 2e-5, $party);
        }
    }

    /** A poll is published at each month's turn, none on the vote's tick, and every one samples support as it stood. */
    public function testAPollIsPublishedEachMonthOfTheTerm(): void
    {
        mt_srand(5);
        $engine = new Politics(MathUtility::ownStream(5), $this->inMemoryRedis());
        $state = $this->termStart();
        $ticksPerYear = 48;
        $published = 0;
        for ($tick = 1; $tick < 4 * $ticksPerYear; ++$tick) {
            $engine->advance($state, $this->economy($tick / $ticksPerYear), 1.0 / $ticksPerYear);
            if ($state->polls !== [] && end($state->polls)['t'] === $state->totalTime) {
                ++$published;
                $poll = end($state->polls)['shares'];
                $support = OpinionPolls::support($state);
                $this->assertEqualsWithDelta(1.0, array_sum($poll), 5e-4);
                foreach (Diet::PARTIES as $party) {
                    $this->assertLessThan(5.0 * sqrt($support[$party] / OpinionPolls::EFFECTIVE_SAMPLE_SIZE), abs($poll[$party] - $support[$party]), $party);
                }
            }
        }
        $this->assertSame(47, $published);
        $this->assertCount(47, $state->polls);
    }

    /** The polls are drawn off the politics stream: a game polled on another salt votes the same, on different polls. */
    public function testPublishingThePollsChangesNothingElse(): void
    {
        $results = [];
        $polls = [];
        foreach ([11, 12] as $salt) {
            $engine = new Politics(MathUtility::ownStream(9), $this->inMemoryRedis());
            $state = $this->termStart();
            $state->authoritySalt = (float) $salt;
            for ($month = 1; $month <= 48; ++$month) {
                $engine->advance($state, $this->economy($month / 12.0), 1.0 / 12.0);
                if ($month === 47) {
                    $polls[] = end($state->polls)['shares'];
                }
            }
            $results[] = $state->dietVoteShares;
        }

        $this->assertSame($results[0], $results[1]);
        $this->assertNotSame($polls[0], $polls[1]);
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
        $engine = new Politics(MathUtility::ownStream(2), $this->inMemoryRedis());
        $state = $this->termStart();
        for ($month = 1; $month <= 6; ++$month) {
            $engine->advance($state, $this->economy($month / 12.0), 1.0 / 12.0);
        }

        $decoded = json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($state, PoliticsState::fromArray($decoded));
        $this->assertCount(6, $state->polls);
    }

    /** The founding Diet a coalition of two governs, at the start of the first term, the term counted from Year 1 and the game's salt drawn. */
    private function termStart(): PoliticsState
    {
        $state = new PoliticsState();
        $state->governingCoalition = Diet::membership([Diet::VANGUARD, Diet::EXCHANGE]);
        $state->supportParties = [];
        $state->cabinetFallsAt = 100.0;
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = $this->economy(0.0)->consumerPriceLevel;
        $state->campaignStartedAt = 0.0;
        $state->campaignStartRealGdp = Politics::realGdp($this->economy(0.0));
        // The game's salt, which a fresh game draws on its first tick, after that tick's poll would have been published.
        $state->authoritySalt = 7.0;

        return $state;
    }

    /** The economy on trend: output growing at its trend and prices at the target. */
    private function economy(float $totalTime): MacroStateDTO
    {
        return new MacroStateDTO(
            totalTime: $totalTime,
            potentialGdpIndex: exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * $totalTime),
            consumerPriceLevel: exp(MacroEngine::TARGET_INFLATION * $totalTime),
        );
    }

    /** Every draw is zero. */
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
