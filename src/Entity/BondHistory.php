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
 */
#[ORM\Entity]
#[ORM\Table(name: 'bond_history')]
#[ORM\Index(name: 'idx_bond_history_bond_recorded', columns: ['bond_id', 'recorded_at'])]
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
}
