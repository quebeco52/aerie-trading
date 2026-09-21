<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\SimulationClock;
use App\Service\Market\OptionChainService;
use App\Service\Market\SimulationClockService;
use App\Service\Math\FinancialConstants;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The clock's one job is to never lose time.
 *
 * It used to live in Redis while everything indexed by it lived in the database, with no transaction between
 * them; a snapshot-persisted cache dropped its last minute of writes on an unclean stop and the simulation
 * replayed weeks it had already lived. That surfaced as duplicate option symbols — the only thing keyed on
 * simulation time with a unique constraint to fail on — and everything else replayed in silence.
 */
class SimulationClockTest extends TestCase
{
    private const TICKS_PER_YEAR = 14400;

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $sent = [];

    private function service(?SimulationClock $stored, ?object &$persisted = null): SimulationClockService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($stored);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });

        return new SimulationClockService($em, self::TICKS_PER_YEAR);
    }

    private function storedAt(int $ticks, float $time): SimulationClock
    {
        return (new SimulationClock())->setTickCount($ticks)->setTotalTime($time);
    }

    public function testTheDatabaseWinsOverTheCache(): void
    {
        // The cache is written after a tick commits, so anything it is missing is a tick the database has
        // already recorded. It is never consulted when a clock exists.
        $clock = $this->service($this->storedAt(8_772, 0.6094))->resume(3_100, 0.2153);

        $this->assertSame(8_772, $clock->getTickCount());
        $this->assertEqualsWithDelta(0.6094, $clock->getTotalTime(), 1e-9);
    }

    /**
     * The seed takes the TIME and derives the counter, rather than believing both caches at once.
     *
     * The two arguments come from different writers at different moments, so a seed that trusts each of them
     * separately can open a clock whose counter and time already disagree — and once open, nothing
     * reconciles them: the counter's cadences and the clock's dates each stay self-consistent while meaning
     * different things. The fixture here is the defect in miniature, three ticks apart.
     */
    public function testTheFirstStartSeedsTheCounterFromTheTimeRatherThanFromItsOwnCache(): void
    {
        $persisted = null;
        $clock = $this->service(null, $persisted)->resume(8_772, 0.6094);

        $this->assertInstanceOf(SimulationClock::class, $persisted);
        $this->assertSame((int) round(0.6094 * self::TICKS_PER_YEAR), $clock->getTickCount());
        $this->assertEqualsWithDelta(0.6094, $clock->getTotalTime(), 1e-9);
    }

    /** With no time to derive from there is nothing to be inconsistent with, so the cached count stands. */
    public function testAGenuinelyFreshInstallationFallsBackToTheCachedCount(): void
    {
        $persisted = null;
        $clock = $this->service(null, $persisted)->resume(0, 0.0);

        $this->assertSame(0, $clock->getTickCount());
        $this->assertEqualsWithDelta(0.0, $clock->getTotalTime(), 1e-9);
    }

    /**
     * A drifted counter is left alone unless the repair is asked for.
     *
     * The offset is not corruption — every cadence keyed on the counter is still self-consistent with it —
     * so moving it moves the phase of all of them at once. That is a decision, not a repair to make on
     * someone's behalf at start-up.
     */
    public function testADrifedCounterIsReportedByBeingLeftAlone(): void
    {
        $clock = $this->service($this->storedAt(10_591, 13.6))->resume(0, 0.0);

        $this->assertSame(10_591, $clock->getTickCount());
        $this->assertEqualsWithDelta(13.6, $clock->getTotalTime(), 1e-9);
    }

    public function testRebasingAlignsTheCounterToTheClock(): void
    {
        $clock = $this->service($this->storedAt(10_591, 13.6))->resume(0, 0.0, 0.0, true);

        $this->assertSame((int) round(13.6 * self::TICKS_PER_YEAR), $clock->getTickCount());
        $this->assertEqualsWithDelta(13.6, $clock->getTotalTime(), 1e-9);
    }

    public function testASeedBehindWhatTheDatabaseProvesIsCorrectedRatherThanReplayed(): void
    {
        $persisted = null;

        // The cache lost time: it says year 0.61, but the chain holds a serial that could only have been
        // listed from year 0.67 onwards. Resuming on the cache would live those weeks a second time.
        $proven = OptionChainService::earliestTimeFor(14);
        $clock = $this->service(null, $persisted)->resume(8_772, 0.6094, $proven);

        $this->assertEqualsWithDelta($proven, $clock->getTotalTime(), 1e-9);
        $this->assertSame((int) round($proven * self::TICKS_PER_YEAR), $clock->getTickCount());
    }

    public function testAnExistingClockIsAlsoHeldToWhatTheDatabaseProves(): void
    {
        $proven = OptionChainService::earliestTimeFor(20);
        $clock = $this->service($this->storedAt(8_772, 0.6094))->resume(0, 0.0, $proven);

        $this->assertEqualsWithDelta($proven, $clock->getTotalTime(), 1e-9);
    }

    public function testAClockAheadOfTheEvidenceIsLeftAlone(): void
    {
        // The chain is only ever a LOWER bound: nothing about a listed serial says the clock has not moved on.
        $clock = $this->service($this->storedAt(20_000, 1.3889))->resume(0, 0.0, OptionChainService::earliestTimeFor(14));

        $this->assertEqualsWithDelta(1.3889, $clock->getTotalTime(), 1e-9);
        $this->assertSame(20_000, $clock->getTickCount());
    }

    public function testATickIsRecordedOnTheConnectionItRanOn(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->sent[] = [$sql, $params];

                return 1;
            }
        );

        $this->service(null)->advance($connection, 8_773, 0.60944);

        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith('UPDATE simulation_clock SET tick_count = ?', $this->sent[0][0]);
        $this->assertSame(8_773, $this->sent[0][1][0]);
        $this->assertEqualsWithDelta(0.60944, $this->sent[0][1][1], 1e-9);
        $this->assertSame(SimulationClock::SINGLETON_ID, $this->sent[0][1][3]);
    }

    // --- The Chain As A Witness ---

    public function testAListedSerialProvesTheClockStoodAtLeastThatFarBack(): void
    {
        $furthest = max(FinancialConstants::OPTION_EXPIRY_MONTHS);

        // A serial is only ever opened at most that many months ahead, so its own expiry minus that span is
        // the earliest the clock can have been when it was written.
        foreach ([8, 14, 30, 120] as $serial) {
            $earliest = OptionChainService::earliestTimeFor($serial);

            $this->assertEqualsWithDelta(OptionChainService::expiryTime($serial - $furthest), $earliest, 1e-12);
            $this->assertContains($serial, OptionChainService::listedSerials($earliest), (string) $serial);
        }
    }
}
