<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\EtfEventRepository::class)]
#[ORM\Table(name: 'etf_events')]
#[ORM\Index(name: 'idx_etf_event_recorded', columns: ['etf_id', 'recorded_at'])]
#[ORM\Index(name: 'idx_etf_event_recorded_all', columns: ['recorded_at'])]
class EtfEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Etf::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Etf $etf;

    #[ORM\Column(length: 50)]
    private string $eventType;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

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

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;

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

    public function getEtf(): Etf
    {
        return $this->etf;
    }

    public function setEtf(Etf $etf): static
    {
        $this->etf = $etf;

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
