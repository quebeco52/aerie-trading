<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Diet;

use App\Data\Politics\AerieDiet as Diet;
use App\Service\Math\MacroConstants;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\Diet\OpinionPolls;
use App\Service\Politics\Policy\BudgetRound;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class ElectionForecastTest extends TestCase
{
    use ElectionForecastFixtures;

    /** A poll moves the average by the share of its uncertainty the poll's error leaves it, and leaves it surer. */
    public function testThePollsArePooledAsAKalmanFilter(): void
    {
        $average = Diet::SEED_VOTE_SHARES;
        $poll = array_merge($average, [Diet::CIVIC => $average[Diet::CIVIC] + 0.03, Diet::VANGUARD => $average[Diet::VANGUARD] - 0.03]);

        [$certain] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, 0.0), $poll, 0.0, Diet::SEED_VOTE_SHARES);
        $this->assertEqualsWithDelta($average[Diet::CIVIC], $certain[Diet::CIVIC], 1e-12, 'An average with no uncertainty and no time to drift ignores the poll.');

        [$vague] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, 1.0), $poll, 0.0, Diet::SEED_VOTE_SHARES);
        $this->assertEqualsWithDelta($poll[Diet::CIVIC], $vague[Diet::CIVIC], 1e-3, 'An average with no idea takes the poll.');

        $prior = 0.0004;
        [$pooled, $left] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, $prior), $poll, 0.0, Diet::SEED_VOTE_SHARES);
        $p = $average[Diet::CIVIC];
        $gain = $prior / ($prior + ($p * (1.0 - $p) / OpinionPolls::EFFECTIVE_SAMPLE_SIZE));
        $this->assertEqualsWithDelta((1.0 - $gain) * $prior, $left[Diet::CIVIC], 1e-15);
        $this->assertGreaterThan($average[Diet::CIVIC], $pooled[Diet::CIVIC]);
        $this->assertLessThan($poll[Diet::CIVIC], $pooled[Diet::CIVIC]);
        $this->assertEqualsWithDelta(1.0, array_sum($pooled), 1e-12);

        [, $drifted] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, $prior), $poll, 1.0, Diet::SEED_VOTE_SHARES);
        $this->assertGreaterThan($left[Diet::CIVIC], $drifted[Diet::CIVIC], 'A year since the last poll leaves more to learn.');

        // The year's drift: the lasting share's and the normal vote's, each over the normal vote.
        $persistence = Election::NORMAL_VOTE_PERSISTENCE;
        $termPersistence = $persistence ** Election::TERM_YEARS;
        $stepPrior = $prior + (($p ** 2) * ((Election::LASTING_SWING_VARIANCE / (1.0 - ($termPersistence ** 2)) * (1.0 - ($persistence ** 2))) + Election::normalVoteDriftVariance(1.0)) / $p);
        $stepGain = $stepPrior / ($stepPrior + ($p * (1.0 - $p) / OpinionPolls::EFFECTIVE_SAMPLE_SIZE));
        $this->assertEqualsWithDelta((1.0 - $stepGain) * $stepPrior, $drifted[Diet::CIVIC], 1e-15, 'The normal vote\'s walk adds to what a year leaves uncertain.');
    }

    /**
     * The talks' budget is weighed once for its inputs and afresh whenever one of them moves, the laws in force and the
     * fund's consent in a recession among them.
     */
    public function testTheTalksBudgetIsWeighedAfreshWhenItsInputsMove(): void
    {
        $state = new PoliticsState();
        $state->dietSeats = Election::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);
        $state->totalTime = 6.2;
        $state->lastElectionAt = 4.0;
        $state->talksStartedAt = 6.2;
        $state->coalitionTakesOfficeAt = 6.3;
        $fresh = static function (PoliticsState $state, bool $recession): array {
            (new \ReflectionProperty(ElectionForecast::class, 'budget'))->setValue(null, null);

            return ElectionForecast::sittingBudget($state, $recession);
        };

        $before = ElectionForecast::sittingBudget($state);
        $state->corporateTaxPolicyShift += 0.01;
        $moved = ElectionForecast::sittingBudget($state);
        $this->assertNotSame($before, $moved, 'The laws in force moved.');
        $this->assertSame($fresh($state, false), $moved);

        $state->reserveDrawShare = MacroConstants::RESERVE_DRAW_CEILING * 2.0;
        ElectionForecast::sittingBudget($state);
        $slump = ElectionForecast::sittingBudget($state, true);
        $this->assertSame($fresh($state, true), $slump, 'The fund consents in a recession.');
    }

    /**
     * A sitting cabinet's budget is worked out once for its inputs and afresh whenever one moves: the laws in force,
     * the cabinet and its supporters, and the fund's consent in a recession.
     */
    public function testTheSittingBudgetIsWorkedOutAfreshWhenItsInputsMove(): void
    {
        $state = new PoliticsState();
        $state->dietSeats = Election::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);
        $state->governingCoalition = Diet::membership([Diet::VANGUARD, Diet::CIVIC]);
        $state->coalitionTakesOfficeAt = -1.0;
        $fresh = static function (PoliticsState $state, bool $recession): array {
            (new \ReflectionProperty(ElectionForecast::class, 'budget'))->setValue(null, null);

            return ElectionForecast::sittingBudget($state, $recession);
        };

        $before = ElectionForecast::sittingBudget($state);
        $this->assertSame($fresh($state, false), $before, 'Held for the same inputs.');

        $state->corporateTaxPolicyShift += 0.01;
        $moved = ElectionForecast::sittingBudget($state);
        $this->assertNotSame($before, $moved, 'The laws in force moved.');
        $this->assertSame($fresh($state, false), $moved);

        $state->governingCoalition = Diet::membership([Diet::EXCHANGE]);
        $recast = ElectionForecast::sittingBudget($state);
        $this->assertNotSame($moved, $recast, 'The cabinet changed.');
        $this->assertSame($fresh($state, false), $recast);

        $state->reserveDrawShare = MacroConstants::RESERVE_DRAW_CEILING * 2.0;
        $calm = ElectionForecast::sittingBudget($state);
        $slump = ElectionForecast::sittingBudget($state, true);
        $this->assertNotSame($calm, $slump, 'The fund consents in a recession.');
        $this->assertSame($fresh($state, true), $slump);
    }

    /**
     * A forecast worked out off the tick is published only while it answers the request still pending: one a later poll
     * or the vote has overtaken, or one already published, is dropped.
     */
    public function testAForecastSentBackIsPublishedOnlyForTheRequestStillPending(): void
    {
        $state = new PoliticsState();
        $state->authoritySalt = 5.0;
        $state->totalTime = 2.05;
        $state->forecastRequestedAt = 2.04;
        $forecast = ['cabinets' => [], 'leaders' => [Diet::VANGUARD => 1.0], 'seats' => [], 'levers' => ['corporateTax' => 0.01] + BudgetRound::standingLevers($state)];

        $this->assertFalse(ElectionForecast::receive($state, $forecast, 2.0), 'Overtaken by a later poll.');
        $this->assertSame(-1.0, $state->forecastAt);
        $this->assertTrue(ElectionForecast::receive($state, $forecast, 2.04));
        $this->assertSame(2.05, $state->forecastAt);
        $this->assertSame(ElectionForecast::nextVote(2.04), $state->forecastFor);
        $this->assertSame($forecast['levers'], $state->forecastLevers);
        $this->assertFalse(ElectionForecast::receive($state, $forecast, 2.04), 'Already published.');

        $state->forecastRequestedAt = 3.99;
        $state->totalTime = 4.0;
        $state->lastElectionAt = 4.0;
        ElectionForecast::advance($state, false, true);
        $this->assertFalse(ElectionForecast::receive($state, $forecast, 3.99), 'Overtaken by the vote.');
        $this->assertSame(4.0, $state->forecastFor, 'The talks stand.');
    }

    /**
     * The laws forecast take effect at the first budget after their government takes office: half a year after a vote,
     * its talks being shorter than a round.
     */
    public function testTheLawsTakeEffectAtTheNewGovernmentsFirstBudget(): void
    {
        $state = new PoliticsState();
        $state->totalTime = 6.2;
        $state->forecastFor = 8.0;
        $this->assertEqualsWithDelta(8.0 + MacroConstants::BUDGET_ROUND_PERIOD_YEARS, ElectionForecast::takesEffect($state), 1e-9);

        $state->totalTime = 8.4;
        $state->forecastFor = 8.0;
        $state->lastGovernmentFormedAt = 8.1;
        $this->assertEqualsWithDelta(8.5, ElectionForecast::takesEffect($state), 1e-9, 'A seated government passes its first budget the round after it took office.');
    }

    /**
     * What the politics hands the economy carries the forecast laws and when they take effect; nothing before a
     * forecast.
     */
    public function testThePolicyCarriesTheExpectedLaws(): void
    {
        $state = new PoliticsState();
        $this->assertNull(\App\DTO\PoliticsStateDTO::fromState($state)->policy()->expectedLevers);

        $state->totalTime = 6.2;
        $state->forecastFor = 8.0;
        $state->forecastAt = 6.2;
        $state->forecastLevers = ['corporateTax' => 0.03, 'bankLevyRate' => 0.002, 'extractionStringency' => 0.5] + BudgetRound::standingLevers($state);
        $policy = \App\DTO\PoliticsStateDTO::fromState($state)->policy();
        $this->assertSame($state->forecastLevers, $policy->expectedLevers);
        $this->assertEqualsWithDelta(8.5, $policy->expectedPolicyFrom, 1e-9);
        $this->assertNull($policy->sittingLevers, 'The sitting government\'s budget is read each tick, not yet here.');

        ElectionForecast::advance($state);
        $policy = \App\DTO\PoliticsStateDTO::fromState($state)->policy();
        $this->assertSame($state->sittingLevers, $policy->sittingLevers);
        $this->assertEqualsWithDelta(6.5, $policy->sittingPolicyFrom, 1e-9, 'The next budget round.');
    }
}
