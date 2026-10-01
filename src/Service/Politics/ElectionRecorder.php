<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieDiet;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
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
    public function record(PoliticsStateDTO $politics): ?DietElection
    {
        if ($politics->lastCabinetFellAt === $politics->totalTime) {
            return $this->recordFall($politics);
        }
        if ($politics->lastElectionAt !== $politics->totalTime) {
            return null;
        }

        // The cabinet going into the vote, which the talks weighed as the status quo.
        $outgoing = AerieDiet::governingParties($politics->electionOutgoingCabinet);

        $election = (new DietElection())
            ->setSimTime($politics->totalTime)
            ->setSeats(array_map('intval', $politics->dietSeats))
            ->setVoteShares($politics->dietVoteShares)
            ->setVoteSwings($politics->dietVoteSwings)
            ->setPositions($politics->partyPositions)
            ->setCoalition(AerieDiet::governingParties($politics->pendingCoalition))
            ->setSupport(AerieDiet::governingParties($politics->pendingSupport))
            ->setFormation($politics->formationLog)
            ->setFormationDays($politics->formationLog === [] ? 0.0 : (float) $politics->formationLog[array_key_last($politics->formationLog)]['day'])
            ->setOutgoingCoalition($outgoing)
            ->setGrowthGap($politics->electionGrowthGap)
            ->setInflationGap($politics->electionInflationGap)
            ->setIncumbentSwing($politics->electionIncumbentSwing)
            ->setVolatility(PoliticsEngine::pedersenVolatility($politics->dietVoteSwings));

        $this->entityManager->persist($election);

        return $election;
    }

    /**
     * Adds a fall to the vote that seated the Diet it happened in. The cabinet that fell is the caretaker still in
     * office, unless a party with its own majority took over the same day; a fall before the first vote has no vote to
     * go on.
     */
    private function recordFall(PoliticsStateDTO $politics): ?DietElection
    {
        $election = $this->elections->findLatest();
        if ($election === null) {
            return null;
        }

        $sameDay = $politics->lastGovernmentFormedAt === $politics->totalTime;
        $cabinets = $election->getCabinets();
        $this->entityManager->persist($election);

        return $election->addFall(
            $politics->totalTime,
            $sameDay ? $cabinets[array_key_last($cabinets)] : AerieDiet::governingParties($politics->governingCoalition),
            AerieDiet::governingParties($sameDay ? $politics->governingCoalition : $politics->pendingCoalition),
            AerieDiet::governingParties($sameDay ? $politics->supportParties : $politics->pendingSupport),
            $politics->formationLog,
        );
    }
}
