<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The market's forecast of a vote as it stood when it was made: each party's chance of leading the next government,
 * the likeliest governments, and the seats expected.
 *
 * Written by App\Service\Politics\PoliticsHistoryRecorder each time App\Service\Politics\ElectionForecast publishes,
 * on every poll, on the day of a vote and when a cabinet takes office; the politics state keeps only the latest, so
 * this is the odds' path. Party-keyed maps are keyed by App\Data\Politics\AerieDiet::PARTIES.
 */
#[ORM\Entity(repositoryClass: \App\Repository\ElectionOddsRepository::class)]
#[ORM\Table(name: 'election_odds')]
#[ORM\Index(name: 'idx_election_odds_vote_at', columns: ['vote_at', 'sim_time'])]
class ElectionOdds
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Simulation time the forecast was made, in years. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $simTime;

    /** Simulation time of the vote the forecast is for. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $voteAt;

    /** @var array<string, float> Each party's chance of leading the next government. */
    #[ORM\Column(type: Types::JSON)]
    private array $leaders = [];

    /** @var list<array{cabinet: list<string>, support: list<string>, chance: float}> The likeliest governments, likeliest first. */
    #[ORM\Column(type: Types::JSON)]
    private array $cabinets = [];

    /** @var array<string, float> The seats the market expects each party to win. */
    #[ORM\Column(type: Types::JSON)]
    private array $seats = [];

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSimTime(): float
    {
        return $this->simTime;
    }

    public function setSimTime(float $simTime): static
    {
        $this->simTime = $simTime;

        return $this;
    }

    public function getVoteAt(): float
    {
        return $this->voteAt;
    }

    public function setVoteAt(float $voteAt): static
    {
        $this->voteAt = $voteAt;

        return $this;
    }

    /** @return array<string, float> */
    public function getLeaders(): array
    {
        return $this->leaders;
    }

    /** @param array<string, float> $leaders */
    public function setLeaders(array $leaders): static
    {
        $this->leaders = $leaders;

        return $this;
    }

    /** @return list<array{cabinet: list<string>, support: list<string>, chance: float}> */
    public function getCabinets(): array
    {
        return $this->cabinets;
    }

    /** @param list<array{cabinet: list<string>, support: list<string>, chance: float}> $cabinets */
    public function setCabinets(array $cabinets): static
    {
        $this->cabinets = $cabinets;

        return $this;
    }

    /** @return array<string, float> */
    public function getSeats(): array
    {
        return $this->seats;
    }

    /** @param array<string, float> $seats */
    public function setSeats(array $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getRecordedAt(): \DateTimeInterface
    {
        return $this->recordedAt;
    }
}
