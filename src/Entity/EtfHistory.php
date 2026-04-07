<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'etf_history')]
#[ORM\Index(name: 'idx_etf_recorded', columns: ['etf_id', 'recorded_at'])]
class EtfHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Etf::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Etf $etf;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getRecordedAt(): \DateTime
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTime $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }

    public function getEtf(): Etf
    {
        return $this->etf;
    }

    public function setEtf(?Etf $etf): static
    {
        $this->etf = $etf;

        return $this;
    }
}