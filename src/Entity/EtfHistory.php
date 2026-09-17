<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'etf_history')]
#[ORM\Index(name: 'idx_etf_recorded', columns: ['etf_id', 'recorded_at'])]
#[ORM\Index(name: 'idx_etf_sim_time', columns: ['etf_id', 'sim_time'])]
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

    /**
     * Net asset value per share at the same instant, or null for rows written before the fund had one.
     *
     * Stored beside the price rather than derived, because the premium or discount between them is the
     * series worth charting and it cannot be reconstructed later: the basket has moved on, and the fee
     * ratchet means the fund does not own the same basket it owned then.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $nav = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $recordedAt;

    /**
     * Simulation time, in years, at which this row was written; null for rows written before the column
     * existed, or by anything that is not the ticker.
     *
     * The clock the market is actually keyed on. `recorded_at` is WALL time — the moment the container
     * happened to write the row — and the two are only proportional while the ticker runs uninterrupted. A
     * stopped ticker leaves an hour-wide gap in the archive that represents no simulated time at all, and
     * before the clock was committed alongside the data it was possible for simulated time to go BACKWARDS
     * while the timestamps marched on, which made a replayed period indistinguishable from a fresh one.
     * Ordering and pruning read this; `recorded_at` stays for the record of when the row was physically
     * written, which is a different and still useful fact.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $simTime = null;

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

    public function getNav(): ?string
    {
        return $this->nav;
    }

    public function setNav(?string $nav): static
    {
        $this->nav = $nav;

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

    public function getSimTime(): ?float
    {
        return $this->simTime;
    }

    public function setSimTime(?float $simTime): self
    {
        $this->simTime = $simTime;

        return $this;
    }
}
