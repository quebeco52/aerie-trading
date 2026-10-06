<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A district-wide story: the economy's (a crisis, a rescue, a recession) or the government's (a vote, a cabinet, a
 * budget, an appointment). It belongs to no listed instrument, so it is not filed against the benchmark fund.
 */
#[ORM\Entity(repositoryClass: \App\Repository\DistrictNewsRepository::class)]
#[ORM\Table(name: 'district_news')]
#[ORM\Index(name: 'idx_district_news_recorded', columns: ['recorded_at'])]
class DistrictNews
{
    /** The desk for the economy's stories. */
    public const DESK_ECONOMY = 'ECONOMY';
    /** The desk for the government's stories. */
    public const DESK_GOVERNMENT = 'GOVERNMENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** DESK_ECONOMY or DESK_GOVERNMENT; doubles as the event type the presenter routes on. */
    #[ORM\Column(length: 20)]
    private string $desk;

    /** The narrative event that made the story, a ShockEvent constant. */
    #[ORM\Column(length: 50)]
    private string $topic;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** What the benchmark did over the month before the story, in percent; null without buffered history. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $changePercent = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $recordedAt;

    /** Simulation time, in years, of the tick that published it; null for rows from before it was recorded. */
    #[ORM\Column(nullable: true)]
    private ?float $simTime = null;

    /** Whether the news desk ran it as a headline when it was published; null for rows from before it was recorded. */
    #[ORM\Column(nullable: true)]
    private ?bool $headline = null;

    public function __construct()
    {
        $this->recordedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDesk(): string
    {
        return $this->desk;
    }

    public function setDesk(string $desk): static
    {
        $this->desk = $desk;

        return $this;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setTopic(string $topic): static
    {
        $this->topic = $topic;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getChangePercent(): ?string
    {
        return $this->changePercent;
    }

    public function setChangePercent(?string $changePercent): static
    {
        $this->changePercent = $changePercent;

        return $this;
    }

    public function getRecordedAt(): \DateTimeInterface
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeInterface $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }

    public function getSimTime(): ?float
    {
        return $this->simTime;
    }

    public function setSimTime(?float $simTime): static
    {
        $this->simTime = $simTime;

        return $this;
    }

    public function getHeadline(): ?bool
    {
        return $this->headline;
    }

    public function setHeadline(?bool $headline): static
    {
        $this->headline = $headline;

        return $this;
    }
}
