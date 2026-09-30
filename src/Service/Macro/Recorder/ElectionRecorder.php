<?php

declare(strict_types=1);

namespace App\Service\Macro\Recorder;

use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Macro\Subsystem\DistrictPoliticsSubsystem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the Diet's vote to diet_election on the tick it is held, and each cabinet that falls before the next vote to
 * that vote's record on the tick it falls.
 *
 * Keyed on the calendar (lastElectionAt on this tick), not on the tick's headline: a crisis on the same tick
 * outranks the election as the headline, and the vote still happened. Persisted only; the tick's own flush writes it.
 */
class ElectionRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DietElectionRepository $elections,
    ) {}

    /**
     * Persists the vote when this snapshot is the tick it was held on.
     *
     * @return DietElection|null The recorded vote, or null on any other tick.
     */
    public function record(MacroStateDTO $macro): ?DietElection
    {
        if ($macro->lastCabinetFellAt === $macro->totalTime) {
            return $this->recordFall($macro);
        }
        if ($macro->lastElectionAt !== $macro->totalTime) {
            return null;
        }

        // The cabinet going into the vote, which the talks weighed as the status quo.
        $outgoing = AerieDiet::governingParties($macro->electionOutgoingCabinet);

        $election = (new DietElection())
            ->setSimTime($macro->totalTime)
            ->setSeats(array_map('intval', $macro->dietSeats))
            ->setVoteShares($macro->dietVoteShares)
            ->setVoteSwings($macro->dietVoteSwings)
            ->setPositions($macro->partyPositions)
            ->setCoalition(AerieDiet::governingParties($macro->pendingCoalition))
            ->setSupport(AerieDiet::governingParties($macro->pendingSupport))
            ->setFormation($macro->formationLog)
            ->setFormationDays($macro->formationLog === [] ? 0.0 : (float) $macro->formationLog[array_key_last($macro->formationLog)]['day'])
            ->setOutgoingCoalition($outgoing)
            ->setGrowthGap($macro->electionGrowthGap)
            ->setInflationGap($macro->electionInflationGap)
            ->setIncumbentSwing($macro->electionIncumbentSwing)
            ->setVolatility(DistrictPoliticsSubsystem::pedersenVolatility($macro->dietVoteSwings))
            ->setCrisisLift($macro->ironHarborCrisisShift > 0.0);

        $this->entityManager->persist($election);

        return $election;
    }

    /**
     * Adds a fall to the vote that seated the Diet it happened in. The cabinet that fell is the caretaker still in
     * office, unless a party with its own majority took over the same day; a fall before the first vote has no vote to
     * go on.
     */
    private function recordFall(MacroStateDTO $macro): ?DietElection
    {
        $election = $this->elections->findLatest();
        if ($election === null) {
            return null;
        }

        $sameDay = $macro->lastGovernmentFormedAt === $macro->totalTime;
        $cabinets = $election->getCabinets();
        $this->entityManager->persist($election);

        return $election->addFall(
            $macro->totalTime,
            $sameDay ? $cabinets[array_key_last($cabinets)] : AerieDiet::governingParties($macro->governingCoalition),
            AerieDiet::governingParties($sameDay ? $macro->governingCoalition : $macro->pendingCoalition),
            AerieDiet::governingParties($sameDay ? $macro->supportParties : $macro->pendingSupport),
            $macro->formationLog,
        );
    }
}
