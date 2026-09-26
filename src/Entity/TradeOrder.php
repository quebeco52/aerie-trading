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

    /** Fills now, at whatever the book quotes. */
    public const TYPE_MARKET = 'MARKET';
    /** Rests until the price comes to it, and never fills through its price. */
    public const TYPE_LIMIT = 'LIMIT';
    /** Rests until the price goes THROUGH its trigger, then becomes a market order and takes what it gets. */
    public const TYPE_STOP = 'STOP';
    /** Triggers like a stop and then rests like a limit: protection against the gap a plain stop would sell into. */
    public const TYPE_STOP_LIMIT = 'STOP_LIMIT';

    /** Every type an order may be placed as. */
    public const VALID_TYPES = [self::TYPE_MARKET, self::TYPE_LIMIT, self::TYPE_STOP, self::TYPE_STOP_LIMIT];

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

    #[ORM\Column(length: 20)]
    private ?string $orderType = null; // one of the TYPE_* constants

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

    /** Stamp duty paid on the fill: listed shares only, charged on the consideration whichever side the order was. */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4, nullable: true)]
    private ?string $stampDuty = null;

    /**
     * Price at which a stop becomes live, in the direction the market has to move to reach it.
     *
     * Distinct from the limit price because a stop-limit needs both and they mean opposite things: the stop
     * is the price that WAKES the order and the limit is the worst price it will then accept. Null on an
     * order that is not a stop.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4, nullable: true)]
    private ?string $stopPrice = null;

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

    public function getStopPrice(): ?string
    {
        return $this->stopPrice;
    }

    public function setStopPrice(?string $stopPrice): static
    {
        $this->stopPrice = $stopPrice;
        return $this;
    }

    /** Whether this order waits for the price to move THROUGH a trigger rather than come to a limit. */
    public function isStop(): bool
    {
        return $this->orderType === self::TYPE_STOP || $this->orderType === self::TYPE_STOP_LIMIT;
    }

    /**
     * Whether a fill is capped at the limit price.
     *
     * False for a plain stop, and that is the whole difference between the two: a stop accepts whatever the
     * book gives it, which is why one can fill far through its trigger and why a cluster of them cascades.
     */
    public function hasLimitCap(): bool
    {
        return $this->orderType === self::TYPE_LIMIT || $this->orderType === self::TYPE_STOP_LIMIT;
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

    public function getStampDuty(): ?string
    {
        return $this->stampDuty;
    }

    public function setStampDuty(?string $stampDuty): static
    {
        $this->stampDuty = $stampDuty;

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
