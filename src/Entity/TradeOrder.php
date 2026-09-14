<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\TradeOrderRepository::class)]
#[ORM\Table(name: 'trade_orders')]
#[ORM\Index(columns: ['ticker', 'status', 'action', 'limit_price'], name: 'idx_trade_order_lookup')]
class TradeOrder
{
    // --- Order Status ---

    /** Resting on the book, still fillable, and its funds or shares still escrowed against the account. */
    public const STATUS_OPEN = 'OPEN';

    /** Executed in full; the row is now a permanent record of an execution and feeds the cost basis. */
    public const STATUS_FILLED = 'FILLED';

    /** Withdrawn before filling, by the trader or by a liquidation; escrow released, no execution. */
    public const STATUS_CANCELLED = 'CANCELLED';

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

    /**
     * Currency paid crossing the bid-ask spread on this fill.
     *
     * Recorded rather than derived so a fill can be explained after the fact. Without the breakdown, an
     * order that filled three percent above the last quote is indistinguishable from a bug; with it, the
     * spread and the size are each accounted for separately.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4, nullable: true)]
    private ?string $spreadCost = null;

    /** Currency paid to this order's own temporary market impact. */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4, nullable: true)]
    private ?string $impactCost = null;

    #[ORM\Column(length: 20)]
    private ?string $status = self::STATUS_OPEN;

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

    public function getSpreadCost(): ?string
    {
        return $this->spreadCost;
    }

    public function setSpreadCost(?string $spreadCost): static
    {
        $this->spreadCost = $spreadCost;

        return $this;
    }

    public function getImpactCost(): ?string
    {
        return $this->impactCost;
    }

    public function setImpactCost(?string $impactCost): static
    {
        $this->impactCost = $impactCost;

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
