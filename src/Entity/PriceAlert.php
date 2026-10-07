<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A one-shot price alert: fires the first time the price reaches the target from the side it was set on, then stays
 * as a record of when it fired.
 */
#[ORM\Entity]
#[ORM\Table(name: 'price_alerts')]
#[ORM\Index(name: 'idx_price_alert_ticker_open', columns: ['ticker', 'triggered_at'])]
#[ORM\Index(name: 'idx_price_alert_user', columns: ['user_id', 'triggered_at'])]
class PriceAlert
{
    // --- Direction ---
    /** Fires when the price rises to or through the target. */
    public const ABOVE = 'ABOVE';
    /** Fires when the price falls to or through the target. */
    public const BELOW = 'BELOW';

    // --- Limits ---
    /** Most alerts one account may have waiting at once. */
    public const MAX_OPEN_PER_USER = 25;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $ticker;

    #[ORM\Column(length: 5)]
    private string $direction;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4)]
    private string $targetPrice;

    /** The price when the alert was set, so the message can say how far it has come. */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4)]
    private string $priceAtCreation;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $triggeredAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 4, nullable: true)]
    private ?string $triggeredPrice = null;

    public function __construct(User $user, string $ticker, string $direction, string $targetPrice, string $priceAtCreation)
    {
        $this->user = $user;
        $this->ticker = $ticker;
        $this->direction = $direction;
        $this->targetPrice = $targetPrice;
        $this->priceAtCreation = $priceAtCreation;
        $this->createdAt = new \DateTime();
    }

    /** The side the target sits on from the current price: an alert above the market waits for a rise. */
    public static function directionFor(float $target, float $current): string
    {
        return $target >= $current ? self::ABOVE : self::BELOW;
    }

    /** Whether a price has reached a target from the given side. */
    public static function isReached(string $direction, float $target, float $price): bool
    {
        return $direction === self::ABOVE ? $price >= $target : $price <= $target;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function getTargetPrice(): string
    {
        return $this->targetPrice;
    }

    public function getPriceAtCreation(): string
    {
        return $this->priceAtCreation;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getTriggeredAt(): ?\DateTimeInterface
    {
        return $this->triggeredAt;
    }

    public function getTriggeredPrice(): ?string
    {
        return $this->triggeredPrice;
    }
}
