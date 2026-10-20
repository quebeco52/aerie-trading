<?php

declare(strict_types=1);

namespace App\Service\Politics\Recorder;

use App\DTO\PoliticsStateDTO;
use App\Entity\ElectionOdds;
use App\Entity\RateDecision;
use App\Service\Politics\Department\Office;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the politics the state keeps only the latest of: each rate meeting to rate_decision on the tick it sits, and
 * each forecast of the next vote to election_odds on the tick the market makes it. Keyed on the state's own clocks
 * (lastMeetingAt, forecastAt); persisted only, the tick's own flush writes it.
 */
class PoliticsHistoryRecorder
{
    // --- Election Odds ---
    /** Likeliest governments kept with each forecast: the four the market panel lists. */
    public const CABINETS_KEPT = 4;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * Persists whatever this snapshot's tick added to the record.
     *
     * @return list<RateDecision|ElectionOdds> The rows written, none on most ticks.
     */
    public function record(PoliticsStateDTO $politics): array
    {
        $rows = [];
        if ($politics->lastMeetingAt === $politics->totalTime) {
            $rows[] = (new RateDecision())
                ->setSimTime($politics->totalTime)
                ->setRate($politics->lastMeetingRate)
                ->setRateChange($politics->lastMeetingChange)
                ->setVotes(array_values(array_map('floatval', $politics->lastMeetingVotes)))
                ->setGovernor($politics->head(Office::Governor)['name'])
                ->setCommitteeMajority((int) $politics->committeeMajority);
        }
        if ($politics->forecastAt === $politics->totalTime && $politics->forecastFor >= 0.0) {
            $rows[] = (new ElectionOdds())
                ->setSimTime($politics->totalTime)
                ->setVoteAt($politics->forecastFor)
                ->setLeaders($politics->forecastLeaders)
                ->setCabinets(array_slice($politics->forecastCabinets, 0, self::CABINETS_KEPT))
                ->setSeats($politics->forecastSeats);
        }
        foreach ($rows as $row) {
            $this->entityManager->persist($row);
        }

        return $rows;
    }
}
