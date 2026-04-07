<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'etf_events')]
#[ORM\Index(name: 'idx_etf_event_recorded', columns: ['etf_id', 'recorded_at'])]
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

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $changePercent = null;

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
}