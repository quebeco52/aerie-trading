<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's position in one listed contract, counted in contracts of
 * FinancialConstants::OPTION_CONTRACT_MULTIPLIER shares each.
 *
 * Signed, like UserStock: positive is long the contract and negative is short it. A written option is not a
 * sale of something owned — it is a new liability with its own margin requirement — so representing it as a
 * negative quantity on the same row keeps one position per user per contract and one place where the two
 * sides can ever disagree.
 *
 * The premium basis is carried per SHARE, matching the quote convention, so the cash a close realizes is
 * (fill - basis) * contracts * multiplier and never depends on which side opened the position.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_options')]
#[ORM\UniqueConstraint(name: 'user_option_unique', columns: ['user_id', 'option_contract_id'])]
class UserOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: OptionContract::class)]
    #[ORM\JoinColumn(name: 'option_contract_id', nullable: false, onDelete: 'CASCADE')]
    private OptionContract $contract;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $quantity = 0;

    /** Average premium paid or received per share, always positive. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0.00000000'])]
    private string $averagePremium = '0.00000000';

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

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

    public function getContract(): OptionContract
    {
        return $this->contract;
    }

    public function setContract(OptionContract $contract): static
    {
        $this->contract = $contract;

        return $this;
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

    public function getAveragePremium(): string
    {
        return $this->averagePremium;
    }

    public function setAveragePremium(string $averagePremium): static
    {
        $this->averagePremium = $averagePremium;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** Whether the holder has written this contract rather than bought it. */
    public function isShort(): bool
    {
        return (int) $this->quantity < 0;
    }
}
