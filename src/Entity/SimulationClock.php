<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How far the simulation has run, kept beside the data that is indexed by it.
 *
 * One row, and it exists because the clock used to live somewhere else. The tick count and the macroeconomic
 * clock were both held in Redis while every consequence of them — price history, bond coupons, the option
 * expiry grid — was written to the database, with no shared transaction between the two. Redis persists by
 * periodic snapshot, so an unclean stop discards the writes since the last one: the clock came back MINUTES
 * of ticks behind a database that remembered everything, and the simulation replayed time it had already
 * lived through. It announced itself as duplicate option symbols, because the chain is the only thing keyed
 * on simulation time that has a unique constraint to fail on; everything else replayed silently.
 *
 * So the clock is committed by the same transaction as the tick it belongs to. A tick that rolls back rolls
 * its clock back with it, and a tick that commits cannot lose the fact that it happened. Redis still carries
 * the tick count for the web process to read, but as a cache of this row rather than the truth.
 */
#[ORM\Entity]
#[ORM\Table(name: 'simulation_clock')]
class SimulationClock
{
    // --- Identity ---
    /** The clock is a singleton: one simulation, one clock, at a known key so it can be read without a search. */
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    /** Ticks executed. The unit the ticker counts cadences in. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $tickCount = 0;

    /** Simulation time in years. The unit every model is written in. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $totalTime = 0.0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTickCount(): int
    {
        return (int) $this->tickCount;
    }

    public function setTickCount(int $tickCount): self
    {
        $this->tickCount = $tickCount;

        return $this;
    }

    public function getTotalTime(): float
    {
        return $this->totalTime;
    }

    public function setTotalTime(float $totalTime): self
    {
        $this->totalTime = $totalTime;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
