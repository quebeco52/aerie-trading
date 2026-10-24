<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Diet;

use App\Data\Politics\AerieDiet as Diet;
use App\Service\Math\MathUtility;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\OpinionPolls;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Tests\Support\InMemoryRedis;
use PHPUnit\Framework\TestCase;

/**
 * The opinion polls run over whole terms: the normal votes' walk, the monthly polls and the monthly step. Owned by the
 * Realism suite.
 */
final class OpinionPollsRealismTest extends TestCase
{
    use OpinionPollsFixtures;
    /** Through a term the normal votes walk with the polls' clock, and the vote is cast against the walked normals. */
    public function testTheNormalVotesWalkThroughATerm(): void
    {
        mt_srand(9);
        $engine = new Politics(MathUtility::ownStream(9), new InMemoryRedis());
        $state = $this->termStart();
        for ($tick = 1; $tick <= 4 * 48; ++$tick) {
            $engine->advance($state, $this->economy($tick / 48.0), 1.0 / 48.0);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($state->normalVoteShares), 1e-12);
        $moved = 0.0;
        foreach (Diet::PARTIES as $party) {
            $moved += abs($state->normalVoteShares[$party] - Diet::SEED_VOTE_SHARES[$party]);
        }
        $this->assertGreaterThan(0.0, $moved, 'A term on, the normals have moved.');
        $this->assertLessThan(0.15, $moved, 'But only as far as a term of the walk takes them.');
    }

    /**
     * With nothing drawn, a term walked a month at a time votes as a term in one step: the cabinet pays the cost of
     * ruling either way.
     */
    public function testAMonthlyTermVotesAsATermInOneStep(): void
    {
        $quiet = $this->quietMath();
        $whole = $this->termStart();
        (new Politics($quiet, new InMemoryRedis()))->advance($whole, $this->economy(self::ELECTION_AT), 0.01);

        $monthly = $this->termStart();
        $engine = new Politics($quiet, new InMemoryRedis());
        for ($month = 1; $month <= 48; ++$month) {
            $engine->advance($monthly, $this->economy($month / 12.0), 1.0 / 12.0);
        }

        $this->assertSame(self::ELECTION_AT, $monthly->lastElectionAt);
        $this->assertSame([], $monthly->polls, 'The vote puts the term\'s polls away.');
        $this->assertEqualsWithDelta(-Election::COST_OF_RULING, $whole->electionIncumbentSwing, 1e-9);
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta($whole->dietVoteShares[$party], $monthly->dietVoteShares[$party], 2e-5, $party);
        }
    }

    /** A poll is published at each month's turn, none on the vote's tick, and every one samples support as it stood. */
    public function testAPollIsPublishedEachMonthOfTheTerm(): void
    {
        mt_srand(5);
        $engine = new Politics(MathUtility::ownStream(5), new InMemoryRedis());
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
                    $this->assertLessThan(5.0 * sqrt(($support[$party] / OpinionPolls::EFFECTIVE_SAMPLE_SIZE) + (OpinionPolls::UNSETTLED_VARIANCE * $support[$party])), abs($poll[$party] - $support[$party]), $party);
                }
            }
        }
        $this->assertSame(47, $published);
        $this->assertCount(47, $state->polls);
    }

    /**
     * The polls are drawn off the politics stream: a game polled on another salt votes the same, on different polls.
     */
    public function testPublishingThePollsChangesNothingElse(): void
    {
        $results = [];
        $polls = [];
        foreach ([11, 12] as $salt) {
            $engine = new Politics(MathUtility::ownStream(9), new InMemoryRedis());
            $state = $this->termStart();
            $state->authoritySalt = (float) $salt;
            for ($month = 1; $month <= 48; ++$month) {
                $engine->advance($state, $this->economy($month / 12.0), 1.0 / 12.0);
                if ($month === 47) {
                    $poll = end($state->polls);
                    $this->assertNotFalse($poll, 'four years in, the polls have been published');
                    $polls[] = $poll['shares'];
                }
            }
            $results[] = $state->dietVoteShares;
        }

        $this->assertSame($results[0], $results[1]);
        $this->assertNotSame($polls[0], $polls[1]);
    }
}
