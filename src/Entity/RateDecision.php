<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One meeting of the Monetary Authority's rate committee: the rate it left, the move since the meeting before, every
 * member's vote, and who was in the chair.
 *
 * Written by App\Service\Politics\PoliticsHistoryRecorder on the tick the meeting sits (MonetaryAuthority::advance()),
 * eight times a year; the politics state keeps only the last meeting, so this is the committee's record.
 */
#[ORM\Entity(repositoryClass: \App\Repository\RateDecisionRepository::class)]
#[ORM\Table(name: 'rate_decision')]
#[ORM\Index(name: 'idx_rate_decision_sim_time', columns: ['sim_time'])]
class RateDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Simulation time of the meeting, in years. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $simTime;

    /** The policy rate the meeting left, as a fraction. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $rate;

    /** The move since the meeting before, as a fraction; zero at the first meeting on record. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $rateChange = 0.0;

    /** @var list<float> Each member's vote, the governor first: 1 for a higher rate, -1 for a lower, 0 with the decision. */
    #[ORM\Column(type: Types::JSON)]
    private array $votes = [];

    /** The governor in the chair. */
    #[ORM\Column(length: 120)]
    private string $governor = '';

    /** The committee's supermajority at the meeting: 1 hawkish, -1 dovish, 0 none. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $committeeMajority = 0;

    /** Whether the cabinet was pressing the Authority for cheaper money. */
    #[ORM\Column]
    private bool $cabinetPressing = false;

    /** Whether the Authority was giving ground to that pressure. */
    #[ORM\Column]
    private bool $givingGround = false;

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

    public function getRate(): float
    {
        return $this->rate;
    }

    public function setRate(float $rate): static
    {
        $this->rate = $rate;

        return $this;
    }

    public function getRateChange(): float
    {
        return $this->rateChange;
    }

    public function setRateChange(float $rateChange): static
    {
        $this->rateChange = $rateChange;

        return $this;
    }

    /** @return list<float> */
    public function getVotes(): array
    {
        return $this->votes;
    }

    /** @param list<float> $votes */
    public function setVotes(array $votes): static
    {
        $this->votes = $votes;

        return $this;
    }

    /** Members who dissented for a higher rate. */
    public function getVotesHigher(): int
    {
        return count(array_filter($this->votes, static fn(float $vote): bool => $vote > 0.0));
    }

    /** Members who dissented for a lower rate. */
    public function getVotesLower(): int
    {
        return count(array_filter($this->votes, static fn(float $vote): bool => $vote < 0.0));
    }

    public function getGovernor(): string
    {
        return $this->governor;
    }

    public function setGovernor(string $governor): static
    {
        $this->governor = $governor;

        return $this;
    }

    public function getCommitteeMajority(): int
    {
        return $this->committeeMajority;
    }

    public function setCommitteeMajority(int $committeeMajority): static
    {
        $this->committeeMajority = $committeeMajority;

        return $this;
    }

    public function isCabinetPressing(): bool
    {
        return $this->cabinetPressing;
    }

    public function setCabinetPressing(bool $cabinetPressing): static
    {
        $this->cabinetPressing = $cabinetPressing;

        return $this;
    }

    public function isGivingGround(): bool
    {
        return $this->givingGround;
    }

    public function setGivingGround(bool $givingGround): static
    {
        $this->givingGround = $givingGround;

        return $this;
    }

    public function getRecordedAt(): \DateTimeInterface
    {
        return $this->recordedAt;
    }
}
