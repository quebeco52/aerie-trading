<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's holding in one bond issue, counted in whole bonds of Bond::$faceValue each.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_bonds')]
#[ORM\UniqueConstraint(name: 'user_bond_unique', columns: ['user_id', 'bond_id'])]
class UserBond
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Bond::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Bond $bond;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $quantity = 0;

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

    public function getBond(): Bond
    {
        return $this->bond;
    }

    public function setBond(Bond $bond): static
    {
        $this->bond = $bond;

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

    public function getVersion(): int
    {
        return $this->version;
    }
}
