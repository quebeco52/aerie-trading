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

    /** The vote is written on its tick, with the founding government as the outgoing one before any vote is on record. */
    public function testTheFirstVoteIsWrittenAgainstTheFoundingGovernment(): void
    {
        $swings = [Diet::CIVIC => 0.03, Diet::VANGUARD => -0.02, Diet::IRON_HARBOR => 0.0, Diet::EXCHANGE => -0.01];
        $election = $this->recorder(null)->record(new MacroStateDTO(
            totalTime: 4.0,
            lastElectionAt: 4.0,
            dietSeats: [Diet::CIVIC => 93.0, Diet::VANGUARD => 90.0, Diet::IRON_HARBOR => 35.0, Diet::EXCHANGE => 32.0],
            dietVoteSwings: $swings,
            governingCoalition: [Diet::CIVIC => 1.0, Diet::VANGUARD => 0.0, Diet::IRON_HARBOR => 1.0, Diet::EXCHANGE => 0.0],
            ironHarborCrisisShift: 0.01,
        ));

        $this->assertNotNull($election);
        $this->assertSame([$election], $this->persisted);
        $this->assertSame(4.0, $election->getSimTime());
        $this->assertSame([Diet::CIVIC => 93, Diet::VANGUARD => 90, Diet::IRON_HARBOR => 35, Diet::EXCHANGE => 32], $election->getSeats());
        $this->assertSame([Diet::CIVIC, Diet::IRON_HARBOR], $election->getCoalition());
        $this->assertSame(Diet::governingParties(Diet::SEED_COALITION), $election->getOutgoingCoalition());
        $this->assertEqualsWithDelta(0.03, $election->getVolatility(), 1e-12);
        $this->assertTrue($election->hasCrisisLift());
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
