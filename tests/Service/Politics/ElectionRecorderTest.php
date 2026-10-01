<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieDiet as Diet;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Politics\ElectionRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ElectionRecorderTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testNothingIsWrittenBetweenVotes(): void
    {
        $this->assertNull($this->recorder(null)->record(new PoliticsStateDTO(totalTime: 5.0, lastElectionAt: 4.0)));
        $this->assertSame([], $this->persisted);
    }

    /**
     * The vote is written on its tick, against the cabinet that went into it, and with the talks already settled: the
     * cabinet they produced, its supporters, every attempt, and how long it took.
     */
    public function testTheFirstVoteIsWrittenAgainstTheFoundingGovernment(): void
    {
        $swings = [Diet::CIVIC => 0.03, Diet::VANGUARD => -0.02, Diet::IRON_HARBOR => 0.0, Diet::EXCHANGE => -0.01, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0];
        $log = [
            ['day' => 12.5, 'formateur' => Diet::CIVIC, 'round' => 1, 'formed' => false, 'cabinet' => [Diet::CIVIC, Diet::VANGUARD], 'support' => []],
            ['day' => 30.25, 'formateur' => Diet::CIVIC, 'round' => 2, 'formed' => true, 'cabinet' => [Diet::CIVIC, Diet::IRON_HARBOR], 'support' => [Diet::COMMON_LOT]],
        ];
        $positions = Diet::HOME_POSITIONS;
        $positions[Diet::CIVIC][Diet::AXIS_COUNCIL] = 0.1;
        $election = $this->recorder(null)->record(new PoliticsStateDTO(
            totalTime: 4.0,
            lastElectionAt: 4.0,
            dietSeats: [Diet::CIVIC => 110.0, Diet::VANGUARD => 90.0, Diet::IRON_HARBOR => 35.0, Diet::EXCHANGE => 32.0, Diet::CHARTISTS => 18.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: $swings,
            partyPositions: $positions,
            governingCoalition: Diet::SEED_COALITION,
            electionOutgoingCabinet: Diet::SEED_COALITION,
            pendingCoalition: Diet::membership([Diet::CIVIC, Diet::IRON_HARBOR]),
            pendingSupport: Diet::membership([Diet::COMMON_LOT]),
            coalitionTakesOfficeAt: 4.0 + 30.25 / 365.0,
            formationLog: $log,
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
    }

    /** A party with a majority of its own takes office on the day, with no talks. */
    public function testAMajorityWinnerIsWrittenWithNoTalks(): void
    {
        $election = $this->recorder(null)->record(new PoliticsStateDTO(
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

    /** The outgoing cabinet is the one in office at the vote, which after a fall is not the one the last vote formed. */
    public function testAVoteIsWrittenAgainstTheCabinetInOfficeAfterAFall(): void
    {
        $last = (new DietElection())->setCoalition([Diet::VANGUARD]);
        $election = $this->recorder($last)->record(new PoliticsStateDTO(totalTime: 8.0, lastElectionAt: 8.0, electionOutgoingCabinet: Diet::membership([Diet::CIVIC, Diet::EXCHANGE])));

        $this->assertNotNull($election);
        $this->assertSame([Diet::CIVIC, Diet::EXCHANGE], $election->getOutgoingCoalition());
    }

    /** A cabinet that falls is added to the vote that seated its Diet, with the caretaker it leaves and the talks that follow. */
    public function testAFallIsAddedToTheVoteThatSeatedTheDiet(): void
    {
        $log = [['day' => 21.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC], 'support' => [Diet::IRON_HARBOR, Diet::BASTION_GUILDS]]];
        $last = (new DietElection())->setSimTime(4.0)->setCoalition([Diet::VANGUARD, Diet::EXCHANGE]);
        $recorded = $this->recorder($last)->record(new PoliticsStateDTO(
            totalTime: 6.5,
            lastElectionAt: 4.0,
            governingCoalition: Diet::membership([Diet::VANGUARD, Diet::EXCHANGE]),
            pendingCoalition: Diet::membership([Diet::CIVIC]),
            pendingSupport: Diet::membership([Diet::IRON_HARBOR, Diet::BASTION_GUILDS]),
            coalitionTakesOfficeAt: 6.5 + 21.0 / 365.0,
            formationLog: $log,
            lastCabinetFellAt: 6.5,
        ));

        $this->assertSame($last, $recorded);
        $this->assertSame([$last], $this->persisted);
        $this->assertSame([[
            'fellAt' => 6.5,
            'fallen' => [Diet::VANGUARD, Diet::EXCHANGE],
            'cabinet' => [Diet::CIVIC],
            'support' => [Diet::IRON_HARBOR, Diet::BASTION_GUILDS],
            'formation' => $log,
            'formationDays' => 21.0,
        ]], $last->getFalls());
        $this->assertSame([[Diet::VANGUARD, Diet::EXCHANGE], [Diet::CIVIC]], $last->getCabinets());
    }

    /** A founding cabinet that falls before the first vote has no vote to be recorded under. */
    public function testAFallBeforeTheFirstVoteIsNotWritten(): void
    {
        $this->assertNull($this->recorder(null)->record(new PoliticsStateDTO(totalTime: 2.0, lastCabinetFellAt: 2.0)));
        $this->assertSame([], $this->persisted);
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
