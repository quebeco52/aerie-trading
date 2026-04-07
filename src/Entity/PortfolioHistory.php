<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'portfolio_history')]
#[ORM\Index(name: 'idx_user_recorded', columns: ['user_id', 'recorded_at'])]
class PortfolioHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalValue;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTotalValue(): ?string
    {
        return $this->totalValue;
    }

    public function setTotalValue(string $totalValue): static
    {
        $this->totalValue = $totalValue;

        return $this;
    }

    public function getRecordedAt(): \DateTime
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTime $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
}