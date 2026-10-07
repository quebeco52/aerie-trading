<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message to one account: a resting order that filled, a forced sale, an expiry, an alert that fired, news on a
 * watched name, a season result. Written by PlayerNotifier after the transaction that caused it commits, and pushed
 * to the account's open pages over the websocket.
 */
#[ORM\Entity(repositoryClass: \App\Repository\NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notification_user_created', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_notification_user_read', columns: ['user_id', 'read_at'])]
class Notification
{
    // --- Kinds ---
    /** A resting limit or stop order filled. */
    public const KIND_FILL = 'FILL';
    /** The broker sold or bought back positions to meet the maintenance requirement. */
    public const KIND_MARGIN_CALL = 'MARGIN_CALL';
    /** The lender recalled borrowed stock and the short was bought in. */
    public const KIND_BUY_IN = 'BUY_IN';
    /** A held or written option contract expired, exercised or worthless. */
    public const KIND_OPTION_EXPIRY = 'OPTION_EXPIRY';
    /** A price alert the account set has been reached. */
    public const KIND_PRICE_ALERT = 'PRICE_ALERT';
    /** A story on a company or fund on the account's watchlist. */
    public const KIND_NEWS = 'NEWS';
    /** A season opened or closed. */
    public const KIND_SEASON = 'SEASON';

    /** Badge text and tone for each kind, shared by the page and the live toast. */
    private const PRESENTATION = [
        self::KIND_FILL => ['Order filled', 'badge-accent'],
        self::KIND_MARGIN_CALL => ['Margin call', 'badge-warn'],
        self::KIND_BUY_IN => ['Buy-in', 'badge-warn'],
        self::KIND_OPTION_EXPIRY => ['Option expiry', 'badge-neutral'],
        self::KIND_PRICE_ALERT => ['Price alert', 'badge-accent'],
        self::KIND_NEWS => ['Watchlist', 'badge-neutral'],
        self::KIND_SEASON => ['Season', 'badge-accent'],
    ];

    /** @return array{label: string, tone: string} */
    public static function presentation(string $kind): array
    {
        [$label, $tone] = self::PRESENTATION[$kind] ?? ['Notice', 'badge-neutral'];

        return ['label' => $label, 'tone' => $tone];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /** The instrument the message is about, for the link and the badge; null for account-wide messages. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ticker = null;

    /** Page the message opens: the instrument's page, the portfolio or the league table. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $link = null;

    /** Simulation time the message was raised at, in years, for the District dateline. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $simTime = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $readAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getTicker(): ?string
    {
        return $this->ticker;
    }

    public function setTicker(?string $ticker): static
    {
        $this->ticker = $ticker;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getSimTime(): ?float
    {
        return $this->simTime;
    }

    public function setSimTime(?float $simTime): static
    {
        $this->simTime = $simTime;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeInterface
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function getLabel(): string
    {
        return self::presentation($this->kind)['label'];
    }

    public function getTone(): string
    {
        return self::presentation($this->kind)['tone'];
    }

    public function getDateline(): ?string
    {
        return $this->simTime === null ? null : \App\Data\DistrictCalendar::dateline($this->simTime);
    }
}
