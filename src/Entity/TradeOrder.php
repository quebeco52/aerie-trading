<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'trade_orders')]
#[ORM\Index(columns: ['ticker', 'status', 'action', 'limit_price'], name: 'idx_trade_order_lookup')]
class TradeOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 10)]
    private ?string $assetType = null; // 'STOCK' or 'ETF'

    #[ORM\Column(length: 20)]
    private ?string $ticker = null;

    #[ORM\Column(length: 10)]
    private ?string $action = null; // 'BUY' or 'SELL'

    #[ORM\Column(length: 10)]
    private ?string $orderType = null; // 'MARKET' or 'LIMIT'

    #[ORM\Column(type: Types::BIGINT)]
    private int|string|null $quantity = null;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $filledQuantity = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4, nullable: true)]
    private ?string $limitPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4, nullable: true)]
    private ?string $executionPrice = null;

    #[ORM\Column(length: 20)]
    private ?string $status = 'OPEN'; // 'OPEN', 'FILLED', 'CANCELLED'

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $filledAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getAssetType(): ?string
    {
        return $this->assetType;
    }

    public function setAssetType(string $assetType): static
    {
        $this->assetType = $assetType;
        return $this;
    }

    public function getTicker(): ?string
    {
        return $this->ticker;
    }

    public function setTicker(string $ticker): static
    {
        $this->ticker = $ticker;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getOrderType(): ?string
    {
        return $this->orderType;
    }

    public function setOrderType(string $orderType): static
    {
        $this->orderType = $orderType;
        return $this;
    }

    public function getQuantity(): int|string|null
    {
        return $this->quantity;
    }

    public function setQuantity(int|string $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getFilledQuantity(): int|string
    {
        return $this->filledQuantity;
    }

    public function setFilledQuantity(int|string $filledQuantity): static
    {
        $this->filledQuantity = $filledQuantity;
        return $this;
    }

    public function getLimitPrice(): ?string
    {
        return $this->limitPrice;
    }

    public function setLimitPrice(?string $limitPrice): static
    {
        $this->limitPrice = $limitPrice;
        return $this;
    }

    public function getExecutionPrice(): ?string
    {
        return $this->executionPrice;
    }

    public function setExecutionPrice(?string $executionPrice): static
    {
        $this->executionPrice = $executionPrice;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getFilledAt(): ?\DateTimeInterface
    {
        return $this->filledAt;
    }

    public function setFilledAt(?\DateTimeInterface $filledAt): static
    {
        $this->filledAt = $filledAt;
        return $this;
    }
}
