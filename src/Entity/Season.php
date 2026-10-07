<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One league season: a fixed span of simulation time over which accounts are ranked by return, not by net worth.
 *
 * Accounts carry from season to season; a season only fixes the window the return is measured over, so a player who
 * joins in Year 40 competes on the same terms as one who has traded since Year 1. The benchmark is the Lakebird 30
 * fund as a total-return level, its distributions reinvested at the ex-date price.
 */
#[ORM\Entity(repositoryClass: \App\Repository\SeasonRepository::class)]
#[ORM\Table(name: 'seasons')]
#[ORM\UniqueConstraint(name: 'uniq_season_number', columns: ['number'])]
class Season
{
    // --- Season Rules ---
    /** Length of a season in simulation years. Long enough to hold a turn of the credit cycle, short enough to finish. */
    public const LENGTH_YEARS = 4.0;
    /** Weekly records an entry needs before it is ranked: one quarter, so a lucky first week cannot top the table. */
    public const MIN_QUALIFYING_WEEKS = 13;
    /** The fund every account is measured against: the District's broad market index fund. */
    public const BENCHMARK_TICKER = 'LBI';

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_CLOSED = 'CLOSED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $number;

    /** Simulation time the season opened, in years. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $startTime;

    /** Simulation time the season closes, in years. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $endTime;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 20)]
    private string $benchmarkTicker = self::BENCHMARK_TICKER;

    /** Units of the benchmark one unit at the season's open has become with every distribution reinvested. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 1.0])]
    private float $benchmarkReinvestFactor = 1.0;

    /** The benchmark distribution already folded into the factor, so one payment is reinvested once. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $benchmarkDistributionSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $openedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $closedAt = null;

    public function __construct(int $number, float $startTime, float $endTime)
    {
        $this->number = $number;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->openedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): float
    {
        return $this->endTime;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function close(): static
    {
        $this->status = self::STATUS_CLOSED;
        $this->closedAt = new \DateTime();

        return $this;
    }

    public function getBenchmarkTicker(): string
    {
        return $this->benchmarkTicker;
    }

    public function getBenchmarkReinvestFactor(): float
    {
        return $this->benchmarkReinvestFactor;
    }

    /** Reinvests one distribution at the ex-date price: the factor grows by the yield the payment represented. */
    public function reinvestDistribution(float $perShare, float $exPrice, \DateTimeInterface $paidAt): static
    {
        if ($perShare > 0.0 && $exPrice > 0.0) {
            $this->benchmarkReinvestFactor *= 1.0 + $perShare / $exPrice;
        }
        $this->benchmarkDistributionSeenAt = $paidAt;

        return $this;
    }

    public function getBenchmarkDistributionSeenAt(): ?\DateTimeInterface
    {
        return $this->benchmarkDistributionSeenAt;
    }

    /** Marks a distribution as already reflected (the one before the season opened), without reinvesting it. */
    public function setBenchmarkDistributionSeenAt(?\DateTimeInterface $paidAt): static
    {
        $this->benchmarkDistributionSeenAt = $paidAt;

        return $this;
    }

    public function getOpenedAt(): \DateTimeInterface
    {
        return $this->openedAt;
    }

    public function getClosedAt(): ?\DateTimeInterface
    {
        return $this->closedAt;
    }
}
