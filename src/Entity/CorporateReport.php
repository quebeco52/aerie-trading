<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'corporate_report')]
#[ORM\Index(name: 'idx_report_recorded', columns: ['stock_id', 'recorded_at'])]
class CorporateReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Stock $stock;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $netIncome = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $equity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $totalDebt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $treasury = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, nullable: true)]
    private ?string $roic = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $shares = null;

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

    public function getStock(): Stock
    {
        return $this->stock;
    }

    public function setStock(Stock $stock): static
    {
        $this->stock = $stock;
        return $this;
    }

    public function getNetIncome(): ?string
    {
        return $this->netIncome;
    }

    public function setNetIncome(?string $netIncome): static
    {
        $this->netIncome = $netIncome;
        return $this;
    }

    public function getEquity(): ?string
    {
        return $this->equity;
    }

    public function setEquity(?string $equity): static
    {
        $this->equity = $equity;
        return $this;
    }

    public function getTreasury(): ?string
    {
        return $this->treasury;
    }

    public function setTreasury(?string $treasury): static
    {
        $this->treasury = $treasury;
        return $this;
    }

    public function getRoic(): ?string
    {
        return $this->roic;
    }

    public function setRoic(?string $roic): static
    {
        $this->roic = $roic;
        return $this;
    }

    public function getShares(): ?string
    {
        return $this->shares;
    }

    public function setShares(?string $shares): static
    {
        $this->shares = $shares;
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

    public function getTotalDebt(): ?string
    {
        return $this->totalDebt;
    }

    public function setTotalDebt(?string $totalDebt): static
    {
        $this->totalDebt = $totalDebt;

        return $this;
    }
}