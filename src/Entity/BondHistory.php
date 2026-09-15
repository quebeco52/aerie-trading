<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One point on a bond's price and yield series.
 *
 * Carries the yield alongside the price because the two answer different questions and neither implies the
 * other without the issue's remaining life: a price series alone cannot show a bond pulling to par as it
 * ages, which looks like a drift a trader would otherwise read as a market view.
 *
 * The recorded price is the clean price, so a chart does not show the coupon accrual sawtooth as volatility.
 *
 * Nothing loads this class through the ORM: the table is written in batches by
 * App\Service\Market\BondTracker, read by App\Controller\StockController and pruned by
 * App\Command\PruneHistoryCommand, all in raw SQL, because a tick series is bulk rows rather than an
 * object graph. The mapping exists so Doctrine can diff the table into a migration, so do not delete
 * it as an unused class — the next migration would drop a live table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bond_history')]
#[ORM\Index(name: 'idx_bond_history_bond_recorded', columns: ['bond_id', 'recorded_at'])]
#[ORM\Index(name: 'idx_bond_history_sim_time', columns: ['bond_id', 'sim_time'])]
class BondHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Bond::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Bond $bond;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $cleanPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6)]
    private string $yieldToMaturity;

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

    public function getBond(): Bond
    {
        return $this->bond;
    }

    public function setBond(Bond $bond): static
    {
        $this->bond = $bond;

        return $this;
    }

    public function getCleanPrice(): string
    {
        return $this->cleanPrice;
    }

    public function setCleanPrice(string $cleanPrice): static
    {
        $this->cleanPrice = $cleanPrice;

        return $this;
    }

    public function getYieldToMaturity(): string
    {
        return $this->yieldToMaturity;
    }

    public function setYieldToMaturity(string $yieldToMaturity): static
    {
        $this->yieldToMaturity = $yieldToMaturity;

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
