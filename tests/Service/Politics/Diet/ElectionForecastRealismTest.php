<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Diet;

use App\Data\Politics\AerieDiet as Diet;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Diet\CoalitionFormation;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\Policy\BudgetRound;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use App\Tests\Support\InMemoryRedis;
use PHPUnit\Framework\TestCase;

/**
 * The election forecast over whole runs of the calendar: the forecast through a term, a cabinet replaced between votes
 * and the talks' odds. Owned by the Realism suite.
 */
final class ElectionForecastRealismTest extends TestCase
{
    use ElectionForecastFixtures;
    /**
     * The odds are the cabinets the talks seat, as often as they seat them; a party with a majority governs alone for
     * certain.
     */
    public function testTheOddsAreTheTalksOwn(): void
    {
        $seats = Election::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);
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
        $alone = ElectionForecast::cabinetOdds(Election::dHondt($landslide, Diet::SEATS), $landslide, Diet::HOME_POSITIONS, $statusQuo, Diet::SEED_BLOCS);
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
        $seats = Election::dHondt(Diet::SEED_VOTE_SHARES, Diet::SEATS);
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
        $standing = BudgetRound::standingLevers($state);
        $expected = 0.0;
        foreach ($odds as $option) {
            $expected += $option['chance'] * BudgetRound::budget($option['cabinet'], $option['support'], $seats, $state->partyPositions, $standing)['levers']['corporateTax'];
        }
        // Within the weight of the cabinets too unlikely to work out, which the read leaves out.
        $this->assertEqualsWithDelta($expected, ElectionForecast::sittingBudget($state)['corporateTax'], 1e-4);
        $this->assertNotEqualsWithDelta($standing['corporateTax'], ElectionForecast::sittingBudget($state)['corporateTax'], 1e-3, 'The talks\' government is expected to change the law.');
        $endOfTalks = 6.2 + (CoalitionFormation::FORMATION_MEAN_DAYS / \App\Service\Math\FinancialConstants::DAYS_PER_YEAR);
        $this->assertEqualsWithDelta((floor($endOfTalks / MacroConstants::BUDGET_ROUND_PERIOD_YEARS) + 1.0) * MacroConstants::BUDGET_ROUND_PERIOD_YEARS, ElectionForecast::sittingTakesEffect($state), 1e-9);

        $state->talksStartedAt = 4.0;
        $state->totalTime = 4.1;
        $this->assertSame($standing, ElectionForecast::sittingBudget($state), 'After a vote the caretaker passes nothing.');
        $this->assertEqualsWithDelta(4.5, ElectionForecast::sittingTakesEffect($state), 1e-9);
    }

    /**
     * Through a term run month by month: each poll is pooled and forecast from; on the vote's day the average is the
     * result and the odds are the talks'; the odds stand through the talks; once a government takes office it is the
     * forecast, for certain, with the laws of its first budget.
     */
    public function testTheForecastFollowsTheCalendar(): void
    {
        $engine = new Politics(MathUtility::ownStream(3), new InMemoryRedis());
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
        $budget = BudgetRound::budget($cabinet, Diet::governingParties($state->supportParties), $state->dietSeats, $state->partyPositions, BudgetRound::standingLevers($state));
        $this->assertEqualsWithDelta($budget['levers']['corporateTax'], $state->sittingLevers['corporateTax'], 1e-12);
        $this->assertSame(8.0, $state->forecastFor);
        $this->assertSame($state->totalTime, $state->forecastAt, 'Forecast on the day it takes office, before any poll.');
        $this->assertEqualsWithDelta(1.0, array_sum($state->forecastLeaders), 1e-9);
        $this->assertLessThan(1.0, $state->forecastCabinets[0]['chance'], 'Four years out, no government is certain.');
    }
}
