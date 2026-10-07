<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Politics\CoalitionFormation;
use App\Service\Politics\ElectionForecast;
use App\Service\Politics\OpinionPolls;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class ElectionForecastTest extends TestCase
{
    /** A poll moves the average by the share of its uncertainty the poll's error leaves it, and leaves it surer. */
    public function testThePollsArePooledAsAKalmanFilter(): void
    {
        $average = Diet::SEED_VOTE_SHARES;
        $poll = array_merge($average, [Diet::CIVIC => $average[Diet::CIVIC] + 0.03, Diet::VANGUARD => $average[Diet::VANGUARD] - 0.03]);

        [$certain] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, 0.0), $poll, 0.0);
        $this->assertEqualsWithDelta($average[Diet::CIVIC], $certain[Diet::CIVIC], 1e-12, 'An average with no uncertainty and no time to drift ignores the poll.');

        [$vague] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, 1.0), $poll, 0.0);
        $this->assertEqualsWithDelta($poll[Diet::CIVIC], $vague[Diet::CIVIC], 1e-3, 'An average with no idea takes the poll.');

        $prior = 0.0004;
        [$pooled, $left] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, $prior), $poll, 0.0);
        $p = $average[Diet::CIVIC];
        $gain = $prior / ($prior + ($p * (1.0 - $p) / OpinionPolls::EFFECTIVE_SAMPLE_SIZE));
        $this->assertEqualsWithDelta((1.0 - $gain) * $prior, $left[Diet::CIVIC], 1e-15);
        $this->assertGreaterThan($average[Diet::CIVIC], $pooled[Diet::CIVIC]);
        $this->assertLessThan($poll[Diet::CIVIC], $pooled[Diet::CIVIC]);
        $this->assertEqualsWithDelta(1.0, array_sum($pooled), 1e-12);

        [, $drifted] = ElectionForecast::pool($average, array_fill_keys(Diet::PARTIES, $prior), $poll, 1.0);
        $this->assertGreaterThan($left[Diet::CIVIC], $drifted[Diet::CIVIC], 'A year since the last poll leaves more to learn.');
    }

    /** The odds are the cabinets the talks seat, as often as they seat them; a party with a majority governs alone for certain. */
    public function testTheOddsAreTheTalksOwn(): void
    {
        $seats = Politics::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);
        $statusQuo = Diet::governingParties(Diet::SEED_COALITION);
        $odds = ElectionForecast::cabinetOdds($seats, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, $statusQuo, Diet::SEED_BLOCS);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($odds, 'chance')), 1e-12);

        $draws = MathUtility::ownStream(77);
        $runs = 1200;
        $seen = [];
        for ($run = 0; $run < $runs; ++$run) {
            $talks = CoalitionFormation::talks($seats, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, $statusQuo, Diet::SEED_BLOCS, $draws);
            $key = implode('+', $talks['cabinet']) . '|' . implode('+', $talks['support']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
        usort($odds, static fn(array $a, array $b): int => $b['chance'] <=> $a['chance']);
        foreach (array_slice($odds, 0, 3) as $option) {
            $key = implode('+', $option['cabinet']) . '|' . implode('+', $option['support']);
            $share = ($seen[$key] ?? 0) / $runs;
            $this->assertEqualsWithDelta($option['chance'], $share, 4.0 * sqrt($option['chance'] * (1.0 - $option['chance']) / $runs), $key);
        }

        $landslide = array_merge(Diet::SEED_VOTE_SHARES, [Diet::CIVIC => 0.60, Diet::VANGUARD => 0.05]);
        $alone = ElectionForecast::cabinetOdds(Politics::dHondt($landslide, Diet::SEATS), $landslide, Diet::HOME_POSITIONS, $statusQuo, Diet::SEED_BLOCS);
        $this->assertSame([['cabinet' => [Diet::CIVIC], 'support' => [], 'chance' => 1.0]], $alone);
    }

    /**
     * A cabinet that falls between votes is replaced within weeks, not at the next vote: while the parties talk, the
     * coming budget is the talks' government's, each cabinet weighed by the odds the talks seat it with, the one that
     * fell weighed as any other cabinet, due the round after the talks are expected to end. After a vote the
     * caretaker passes nothing; the forecast weighs those talks.
     */
    public function testACabinetThatFallsBetweenVotesIsReplacedInTheComingBudget(): void
    {
        $seats = Politics::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);
        $fallen = Diet::governingParties(Diet::SEED_COALITION);
        $odds = ElectionForecast::cabinetOdds($seats, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS);
        $this->assertContains($fallen, array_column($odds, 'cabinet'), 'The cabinet that fell may form again.');
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($odds, 'chance')), 1e-12);

        $draws = MathUtility::ownStream(78);
        $runs = 1200;
        $seen = [];
        for ($run = 0; $run < $runs; ++$run) {
            $talks = CoalitionFormation::talks($seats, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, $draws);
            $key = implode('+', $talks['cabinet']) . '|' . implode('+', $talks['support']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
        usort($odds, static fn(array $a, array $b): int => $b['chance'] <=> $a['chance']);
        $option = $odds[0];
        $key = implode('+', $option['cabinet']) . '|' . implode('+', $option['support']);
        $this->assertEqualsWithDelta($option['chance'], ($seen[$key] ?? 0) / $runs, 4.0 * sqrt($option['chance'] * (1.0 - $option['chance']) / $runs), $key);

        $state = new PoliticsState();
        $state->dietSeats = $seats;
        $state->totalTime = 6.2;
        $state->lastElectionAt = 4.0;
        $state->talksStartedAt = 6.2;
        $state->coalitionTakesOfficeAt = 6.3;
        $standing = Politics::standingLevers($state);
        $expected = 0.0;
        foreach ($odds as $option) {
            $expected += $option['chance'] * Politics::budget($option['cabinet'], $option['support'], $seats, $state->partyPositions, $standing, 0.6)['levers']['corporateTax'];
        }
        // Within the weight of the cabinets too unlikely to work out, which the read leaves out.
        $this->assertEqualsWithDelta($expected, ElectionForecast::sittingBudget($state, 0.6)['corporateTax'], 1e-4);
        $this->assertNotEqualsWithDelta($standing['corporateTax'], ElectionForecast::sittingBudget($state, 0.6)['corporateTax'], 1e-3, 'The talks\' government is expected to change the law.');
        $endOfTalks = 6.2 + (CoalitionFormation::FORMATION_MEAN_DAYS / \App\Service\Math\FinancialConstants::DAYS_PER_YEAR);
        $this->assertEqualsWithDelta((floor($endOfTalks / MacroEngine::BUDGET_ROUND_PERIOD_YEARS) + 1.0) * MacroEngine::BUDGET_ROUND_PERIOD_YEARS, ElectionForecast::sittingTakesEffect($state), 1e-9);

        $state->talksStartedAt = 4.0;
        $state->totalTime = 4.1;
        $this->assertSame($standing, ElectionForecast::sittingBudget($state, 0.6), 'After a vote the caretaker passes nothing.');
        $this->assertEqualsWithDelta(4.5, ElectionForecast::sittingTakesEffect($state), 1e-9);
    }

    /** The laws forecast take effect at the first budget after their government takes office: half a year after a vote, its talks being shorter than a round. */
    public function testTheLawsTakeEffectAtTheNewGovernmentsFirstBudget(): void
    {
        $state = new PoliticsState();
        $state->totalTime = 6.2;
        $state->forecastFor = 8.0;
        $this->assertEqualsWithDelta(8.0 + MacroEngine::BUDGET_ROUND_PERIOD_YEARS, ElectionForecast::takesEffect($state), 1e-9);

        $state->totalTime = 8.4;
        $state->forecastFor = 8.0;
        $state->lastGovernmentFormedAt = 8.1;
        $this->assertEqualsWithDelta(8.5, ElectionForecast::takesEffect($state), 1e-9, 'A seated government passes its first budget the round after it took office.');
    }

    /**
     * Through a term run month by month: each poll is pooled and forecast from; on the vote's day the average is the
     * result and the odds are the talks'; the odds stand through the talks; once a government takes office it is the
     * forecast, for certain, with the laws of its first budget.
     */
    public function testTheForecastFollowsTheCalendar(): void
    {
        $engine = new Politics(MathUtility::ownStream(3), $this->inMemoryRedis());
        $state = new PoliticsState();
        $state->authoritySalt = 5.0;
        $forecasts = 0;
        for ($month = 1; $month < 48; ++$month) {
            $engine->advance($state, $this->economy($month / 12.0), 1.0 / 12.0);
            if ($state->forecastAt === $state->totalTime) {
                ++$forecasts;
                $this->assertSame(4.0, $state->forecastFor);
                $this->assertEqualsWithDelta(1.0, array_sum($state->forecastLeaders), 1e-9);
                $this->assertEqualsWithDelta((float) Diet::SEATS, array_sum($state->forecastSeats), 1e-6);
                $this->assertNotEmpty($state->forecastCabinets);
            }
        }
        $this->assertGreaterThan(40, $forecasts, 'A forecast at each poll.');

        // The vote, on the next tick: the result, and the talks weighed on its seats.
        $engine->advance($state, $this->economy(4.0), 1.0 / 12.0);
        $this->assertSame(4.0, $state->lastElectionAt);
        $this->assertSame($state->dietVoteShares, $state->pollAverage);
        $this->assertSame(4.0, $state->forecastFor);
        $this->assertEqualsWithDelta(1.0, array_sum($state->forecastLeaders), 1e-9);

        // The talks run past the next poll, and their odds stand until a government takes office.
        $odds = $state->forecastCabinets;
        $t = 4.0;
        while ($state->coalitionTakesOfficeAt >= 0.0) {
            $t += 1.0 / 52.0;
            $engine->advance($state, $this->economy($t), 1.0 / 52.0);
            if ($state->coalitionTakesOfficeAt >= 0.0) {
                $this->assertSame($odds, $state->forecastCabinets);
            }
        }
        // Once a government takes office its first budget is the sitting one, and the forecast turns to the next vote.
        $cabinet = Diet::governingParties($state->governingCoalition);
        $budget = Politics::budget($cabinet, Diet::governingParties($state->supportParties), $state->dietSeats, $state->partyPositions, Politics::standingLevers($state), 0.6);
        $this->assertEqualsWithDelta($budget['levers']['corporateTax'], $state->sittingLevers['corporateTax'], 1e-12);
        $this->assertSame(8.0, $state->forecastFor);
        $this->assertSame($state->totalTime, $state->forecastAt, 'Forecast on the day it takes office, before any poll.');
        $this->assertEqualsWithDelta(1.0, array_sum($state->forecastLeaders), 1e-9);
        $this->assertLessThan(1.0, $state->forecastCabinets[0]['chance'], 'Four years out, no government is certain.');
    }

    /** What the politics hands the economy carries the forecast laws and when they take effect; nothing before a forecast. */
    public function testThePolicyCarriesTheExpectedLaws(): void
    {
        $state = new PoliticsState();
        $this->assertNull(\App\DTO\PoliticsStateDTO::fromState($state)->policy()->expectedLevers);

        $state->totalTime = 6.2;
        $state->forecastFor = 8.0;
        $state->forecastAt = 6.2;
        $state->forecastLevers = ['corporateTax' => 0.03, 'bankLevyRate' => 0.002, 'extractionStringency' => 0.5] + Politics::standingLevers($state);
        $policy = \App\DTO\PoliticsStateDTO::fromState($state)->policy();
        $this->assertSame($state->forecastLevers, $policy->expectedLevers);
        $this->assertEqualsWithDelta(8.5, $policy->expectedPolicyFrom, 1e-9);
        $this->assertNull($policy->sittingLevers, 'The sitting government\'s budget is read each tick, not yet here.');

        ElectionForecast::advance($state, 0.6);
        $policy = \App\DTO\PoliticsStateDTO::fromState($state)->policy();
        $this->assertSame($state->sittingLevers, $policy->sittingLevers);
        $this->assertEqualsWithDelta(6.5, $policy->sittingPolicyFrom, 1e-9, 'The next budget round.');
    }

    /** The economy on trend, debt below the Council's line. */
    private function economy(float $totalTime): MacroStateDTO
    {
        return new MacroStateDTO(
            totalTime: $totalTime,
            sovereignDebtToGdp: 0.6,
            potentialGdpIndex: exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * $totalTime),
            consumerPriceLevel: exp(MacroEngine::TARGET_INFLATION * $totalTime),
        );
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
