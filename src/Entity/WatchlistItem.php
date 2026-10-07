<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A company or fund an account follows: listed on its portfolio page, and every story on it is sent to the account.
 */
#[ORM\Entity]
#[ORM\Table(name: 'watchlist_items')]
#[ORM\UniqueConstraint(name: 'uniq_watchlist_user_ticker', columns: ['user_id', 'ticker'])]
#[ORM\Index(name: 'idx_watchlist_ticker', columns: ['ticker'])]
class WatchlistItem
{
    // --- Limits ---
    /** Most names one account may follow; a watchlist longer than a screen stops being one. */
    public const MAX_PER_USER = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $ticker;

    /** 'STOCK' or 'ETF', as TradeOrder names them. */
    #[ORM\Column(length: 10)]
    private string $assetType;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct(User $user, string $ticker, string $assetType)
    {
        $this->user = $user;
        $this->ticker = $ticker;
        $this->assetType = $assetType;
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

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getAssetType(): string
    {
        return $this->assetType;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
