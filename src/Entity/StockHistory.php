<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_history')]
#[ORM\Index(name: 'idx_stock_recorded', columns: ['stock_id', 'recorded_at'])]
class StockHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Stock $stock;

    /**
     * The bar's CLOSE.
     *
     * Still named `price` because it was the only column before the bar existed, and every chart, range
     * query and API response reads it under that name. Renaming it would churn all of them to say the same
     * thing: a single-point series is a series of closes.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $price;

    /** First trade of the bar. Null on rows written before bars existed. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $openPrice = null;

    /** Highest price reached within the bar. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $highPrice = null;

    /** Lowest price reached within the bar. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $lowPrice = null;

    /** Shares traded across the bar, summed from the per-tick prints. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $volume = null;

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

    public function getOpenPrice(): ?string
    {
        return $this->openPrice;
    }

    public function setOpenPrice(?string $openPrice): static
    {
        $this->openPrice = $openPrice;

        return $this;
    }

    public function getHighPrice(): ?string
    {
        return $this->highPrice;
    }

    public function setHighPrice(?string $highPrice): static
    {
        $this->highPrice = $highPrice;

        return $this;
    }

    public function getLowPrice(): ?string
    {
        return $this->lowPrice;
    }

    public function setLowPrice(?string $lowPrice): static
    {
        $this->lowPrice = $lowPrice;

        return $this;
    }

    public function getVolume(): int|string|null
    {
        return $this->volume;
    }

    public function setVolume(int|string|null $volume): static
    {
        $this->volume = $volume;

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

    public function getStock(): Stock
    {
        return $this->stock;
    }

    public function setStock(?Stock $stock): static
    {
        $this->stock = $stock;

        return $this;
    }
}