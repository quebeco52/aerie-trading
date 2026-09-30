<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Recorder;

use App\Data\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Macro\Recorder\ElectionRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ElectionRecorderTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testNothingIsWrittenBetweenVotes(): void
    {
        $this->assertNull($this->recorder(null)->record(new MacroStateDTO(totalTime: 5.0, lastElectionAt: 4.0)));
        $this->assertSame([], $this->persisted);
    }

    /**
     * The vote is written on its tick, with the founding government as the outgoing one before any vote is on record,
     * and with the talks already settled: the cabinet they produced, its supporters, every attempt, and how long it took.
     */
    public function testTheFirstVoteIsWrittenAgainstTheFoundingGovernment(): void
    {
        $swings = [Diet::CIVIC => 0.03, Diet::VANGUARD => -0.02, Diet::IRON_HARBOR => 0.0, Diet::EXCHANGE => -0.01, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0];
        $log = [
            ['day' => 12.5, 'formateur' => Diet::CIVIC, 'round' => 1, 'formed' => false, 'cabinet' => [Diet::CIVIC, Diet::VANGUARD], 'support' => []],
            ['day' => 30.25, 'formateur' => Diet::CIVIC, 'round' => 2, 'formed' => true, 'cabinet' => [Diet::CIVIC, Diet::IRON_HARBOR], 'support' => [Diet::COMMON_LOT]],
        ];
        $positions = Diet::SEED_POSITIONS;
        $positions[Diet::CIVIC][Diet::AXIS_COUNCIL] = 0.1;
        $election = $this->recorder(null)->record(new MacroStateDTO(
            totalTime: 4.0,
            lastElectionAt: 4.0,
            dietSeats: [Diet::CIVIC => 110.0, Diet::VANGUARD => 90.0, Diet::IRON_HARBOR => 35.0, Diet::EXCHANGE => 32.0, Diet::CHARTISTS => 18.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: $swings,
            partyPositions: $positions,
            governingCoalition: Diet::SEED_COALITION,
            pendingCoalition: Diet::membership([Diet::CIVIC, Diet::IRON_HARBOR]),
            pendingSupport: Diet::membership([Diet::COMMON_LOT]),
            coalitionTakesOfficeAt: 4.0 + 30.25 / 365.0,
            formationLog: $log,
            ironHarborCrisisShift: 0.01,
        ));

        $this->assertNotNull($election);
        $this->assertSame([$election], $this->persisted);
        $this->assertSame(4.0, $election->getSimTime());
        $this->assertSame(110, $election->getSeats()[Diet::CIVIC]);
        $this->assertSame([Diet::CIVIC, Diet::IRON_HARBOR], $election->getCoalition());
        $this->assertSame([Diet::COMMON_LOT], $election->getSupport());
        $this->assertSame($log, $election->getFormation());
        $this->assertSame(30.25, $election->getFormationDays());
        $this->assertEqualsWithDelta(4.0 + 30.25 / 365.0, $election->getTakesOfficeAt(), 1e-12);
        $this->assertSame(0.1, $election->getPositions()[Diet::CIVIC][Diet::AXIS_COUNCIL]);
        $this->assertSame(Diet::governingParties(Diet::SEED_COALITION), $election->getOutgoingCoalition());
        $this->assertEqualsWithDelta(0.03, $election->getVolatility(), 1e-12);
        $this->assertTrue($election->hasCrisisLift());
    }

    /** A party with a majority of its own takes office on the day, with no talks. */
    public function testAMajorityWinnerIsWrittenWithNoTalks(): void
    {
        $election = $this->recorder(null)->record(new MacroStateDTO(
            totalTime: 4.0,
            lastElectionAt: 4.0,
            pendingCoalition: Diet::membership([Diet::VANGUARD]),
            formationLog: [],
        ));

        $this->assertNotNull($election);
        $this->assertSame([Diet::VANGUARD], $election->getCoalition());
        $this->assertSame([], $election->getSupport());
        $this->assertSame(0.0, $election->getFormationDays());
        $this->assertSame(4.0, $election->getTakesOfficeAt());
    }

    /** Afterwards the outgoing government is the one the last recorded vote formed. */
    public function testALaterVoteIsWrittenAgainstTheLastRecordedGovernment(): void
    {
        $last = (new DietElection())->setCoalition([Diet::CIVIC, Diet::EXCHANGE]);
        $election = $this->recorder($last)->record(new MacroStateDTO(totalTime: 8.0, lastElectionAt: 8.0));

        $this->assertNotNull($election);
        $this->assertSame([Diet::CIVIC, Diet::EXCHANGE], $election->getOutgoingCoalition());
    }

    private function recorder(?DietElection $latest): ElectionRecorder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $elections = $this->createStub(DietElectionRepository::class);
        $elections->method('findLatest')->willReturn($latest);

        return new ElectionRecorder($entityManager, $elections);
    }
}
