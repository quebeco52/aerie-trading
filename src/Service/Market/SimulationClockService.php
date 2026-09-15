<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\SimulationClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and advances the simulation clock, and refuses to let it run backwards.
 *
 * THE DATABASE IS THE CLOCK. Redis holds a copy for the web process, but a cached clock can only ever be
 * behind this row, never ahead of it: the cache is written after the tick commits, so anything Redis is
 * missing is a tick the database has already recorded, and anything Redis has that the database does not is
 * a tick that rolled back and took its data with it. Either way the row wins, and the only time the cache is
 * consulted at all is to seed a clock that does not exist yet — the first start after the clock moved here.
 *
 * Even that seed is floored by what the database can PROVE about itself. An installation resuming from a
 * cache that had already lost time would otherwise write the lost time into the new clock and make the
 * inconsistency permanent on the one start that could still fix it.
 */
final class SimulationClockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $ticksPerYear,
    ) {}

    /**
     * The clock to resume from, created if this is the first start since it moved into the database.
     *
     * @param int   $cachedTicks Tick count from the cache; used only to seed a clock that does not exist.
     * @param float $cachedTime  Simulation time from the cache, likewise.
     * @param float $provenTime  The earliest simulation time the database's own contents are consistent
     *                           with. The clock is never allowed to resume behind it.
     */
    public function resume(int $cachedTicks, float $cachedTime, float $provenTime = 0.0): SimulationClock
    {
        $clock = $this->em->find(SimulationClock::class, SimulationClock::SINGLETON_ID);

        if ($clock === null) {
            $clock = (new SimulationClock())
                ->setTickCount($cachedTicks)
                ->setTotalTime($cachedTime);

            $this->em->persist($clock);
        }

        if ($clock->getTotalTime() < $provenTime) {
            // Time the database can account for but the clock cannot. Trust the evidence, not the counter:
            // replaying it would write a second history for a period that already has one.
            $clock->setTotalTime($provenTime);
            $clock->setTickCount((int) round($provenTime * $this->ticksPerYear));
        }

        $clock->setUpdatedAt(new \DateTime());
        $this->em->flush();

        return $clock;
    }

    /**
     * Records a tick against the clock.
     *
     * Written as data on the connection the tick is already running in, so it commits with the tick rather
     * than after it, and so it does not depend on the clock entity surviving the working set's reload.
     */
    public function advance(Connection $connection, int $tickCount, float $totalTime): void
    {
        $connection->executeStatement(
            'UPDATE simulation_clock SET tick_count = ?, total_time = ?, updated_at = ? WHERE id = ?',
            [$tickCount, $totalTime, (new \DateTime())->format('Y-m-d H:i:s'), SimulationClock::SINGLETON_ID]
        );
    }
}
