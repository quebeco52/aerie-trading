<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_stocks')]
#[ORM\UniqueConstraint(name: 'user_stock_unique', columns: ['user_id', 'stock_id'])]
class UserStock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Stock $stock;

    /**
     * Shares held. NEGATIVE for a short position.
     *
     * A signed quantity rather than a separate short table, because the mark-to-market arithmetic is
     * genuinely the same in both directions: quantity times price is the position's value, and a short's
     * is negative. What is NOT symmetric is handled explicitly elsewhere — cost basis inverts, dividends
     * are owed rather than received, and net asset value has to subtract the margin debit.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $quantity = 0;

    /** Borrow fee accrued on this short and not yet charged, in currency. Always zero on a long. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, options: ['default' => '0.0000'])]
    private string $borrowAccrued = '0.0000';

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBorrowAccrued(): string
    {
        return $this->borrowAccrued;
    }

    public function setBorrowAccrued(string $borrowAccrued): static
    {
        $this->borrowAccrued = $borrowAccrued;

        return $this;
    }

    public function isShort(): bool
    {
        return (int) $this->quantity < 0;
    }

    public function getQuantity(): int|string
    {
        return $this->quantity;
    }

    public function setQuantity(int|string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
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

    public function getStock(): ?Stock
    {
        return $this->stock;
    }

    public function setStock(?Stock $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }
}