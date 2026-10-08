<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\ElectionOdds;
use App\Entity\RateDecision;
use App\Service\Math\MathUtility;
use App\Service\Politics\MonetaryAuthority;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PoliticsHistoryRecorderTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testNothingIsWrittenBetweenMeetingsAndForecasts(): void
    {
        $this->assertSame([], $this->recorder()->record(new PoliticsStateDTO(totalTime: 5.0, lastMeetingAt: 4.875, forecastAt: 4.9, forecastFor: 8.0)));
        $this->assertSame([], $this->persisted);
    }

    public function testAMeetingIsWrittenWithTheVotesItCast(): void
    {
        $rows = $this->recorder()->record(new PoliticsStateDTO(
            totalTime: 5.125,
            governorName: 'Ada Marsh',
            committeeMajority: 1.0,
            lastMeetingAt: 5.125,
            lastMeetingRate: 0.0425,
            lastMeetingChange: 0.0025,
            lastMeetingVotes: [0.0, 1.0, 0.0, 0.0, -1.0, 1.0, 0.0],
            pressureSince: 4.9,
            pressureGivingIn: 1.0,
        ));

        $this->assertCount(1, $rows);
        $decision = $rows[0];
        $this->assertInstanceOf(RateDecision::class, $decision);
        $this->assertSame($rows, $this->persisted);
        $this->assertSame(5.125, $decision->getSimTime());
        $this->assertSame(0.0425, $decision->getRate());
        $this->assertSame(0.0025, $decision->getRateChange());
        $this->assertSame([0.0, 1.0, 0.0, 0.0, -1.0, 1.0, 0.0], $decision->getVotes());
        $this->assertSame(2, $decision->getVotesHigher());
        $this->assertSame(1, $decision->getVotesLower());
        $this->assertSame('Ada Marsh', $decision->getGovernor());
        $this->assertSame(1, $decision->getCommitteeMajority());
        $this->assertTrue($decision->isCabinetPressing());
        $this->assertTrue($decision->isGivingGround());
    }

    public function testAForecastIsWrittenWithItsOdds(): void
    {
        $cabinets = array_map(static fn(float $chance): array => ['cabinet' => [Diet::CIVIC], 'support' => [], 'chance' => $chance], [0.4, 0.3, 0.15, 0.1, 0.05]);
        $leaders = [Diet::CIVIC => 0.6, Diet::VANGUARD => 0.4] + array_fill_keys(Diet::PARTIES, 0.0);
        $rows = $this->recorder()->record(new PoliticsStateDTO(totalTime: 6.0, forecastAt: 6.0, forecastFor: 8.0, forecastLeaders: $leaders, forecastCabinets: $cabinets, forecastSeats: [Diet::CIVIC => 120.0]));

        $this->assertCount(1, $rows);
        $odds = $rows[0];
        $this->assertInstanceOf(ElectionOdds::class, $odds);
        $this->assertSame(8.0, $odds->getVoteAt());
        $this->assertSame($leaders, $odds->getLeaders());
        $this->assertSame(array_slice($cabinets, 0, PoliticsHistoryRecorder::CABINETS_KEPT), $odds->getCabinets(), 'Only the likeliest governments the panel lists are kept.');
        $this->assertSame([Diet::CIVIC => 120.0], $odds->getSeats());
    }

    /**
     * A year and a half of the politics engine, its snapshot recorded every tick as the ticker does: one decision per
     * meeting on the eight-a-year grid, each with the votes that meeting cast, and one row per forecast the market made.
     */
    public function testEveryMeetingAndEveryForecastIsWrittenOnce(): void
    {
        // Eight ticks a meeting, so every meeting lands on a tick: time here is set as tick / rate rather than accumulated
        // as the engine does, and at a rate putting a meeting exactly half a tick past a tick that tie can fire twice.
        $ticksPerYear = 64;
        $engine = new PoliticsEngine(MathUtility::ownStream(23), $this->inMemoryRedis());
        $recorder = $this->recorder();
        $meetings = [];
        $forecasts = [];
        for ($tick = 1; $tick <= (int) (1.5 * $ticksPerYear); ++$tick) {
            $time = $tick / $ticksPerYear;
            $politics = $engine->updatePolitics(new MacroStateDTO(totalTime: $time, policyRate: 0.03 + (0.01 * sin($time))), 1.0 / $ticksPerYear);
            $recorder->record($politics);
            if ($politics->lastMeetingAt === $politics->totalTime) {
                $meetings[] = $politics;
            }
            if ($politics->forecastAt === $politics->totalTime) {
                $forecasts[] = $politics;
            }
        }

        $decisions = array_values(array_filter($this->persisted, static fn(object $row): bool => $row instanceof RateDecision));
        $odds = array_values(array_filter($this->persisted, static fn(object $row): bool => $row instanceof ElectionOdds));
        $this->assertCount((int) (1.5 * MonetaryAuthority::MEETINGS_PER_YEAR), $decisions, 'One row for each meeting on the grid.');
        $this->assertCount(count($meetings), $decisions);
        foreach ($decisions as $i => $decision) {
            $this->assertSame($meetings[$i]->totalTime, $decision->getSimTime());
            $this->assertSame($meetings[$i]->lastMeetingVotes, $decision->getVotes());
            $this->assertSame($meetings[$i]->lastMeetingRate, $decision->getRate());
            $this->assertSame($meetings[$i]->governorName, $decision->getGovernor());
        }
        $this->assertGreaterThanOrEqual(12, count($odds), 'A forecast follows every monthly poll.');
        $this->assertCount(count($forecasts), $odds);
        foreach ($odds as $i => $row) {
            $this->assertSame($forecasts[$i]->totalTime, $row->getSimTime());
            $this->assertSame($forecasts[$i]->forecastLeaders, $row->getLeaders());
        }
    }

    private function recorder(): PoliticsHistoryRecorder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return new PoliticsHistoryRecorder($entityManager);
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
