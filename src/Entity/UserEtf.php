<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_etfs')]
#[ORM\UniqueConstraint(name: 'user_etf_unique', columns: ['user_id', 'etf_id'])]
class UserEtf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Etf::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Etf $etf;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $quantity = 0;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEtf(): ?Etf
    {
        return $this->etf;
    }

    public function setEtf(?Etf $etf): static
    {
        $this->etf = $etf;

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