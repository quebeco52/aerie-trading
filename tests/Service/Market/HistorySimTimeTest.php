<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\BondHistory;
use App\Entity\EtfHistory;
use App\Entity\StockHistory;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Index;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every history table records WHEN in the simulation its row belongs, not only when it was physically written.
 *
 * `recorded_at` is the wall clock of whichever container wrote the row. The two clocks only track each other
 * while the ticker runs uninterrupted: a restart leaves an hour-wide gap in one and none in the other, and
 * before the simulation clock was committed alongside the data it was possible for simulated time to run
 * BACKWARDS while the timestamps marched on — which made a replayed period indistinguishable from a fresh
 * one in every chart. An archive indexed by a clock the market does not keep cannot answer when something
 * happened, so ordering and retention read this column instead.
 */
class HistorySimTimeTest extends TestCase
{
    /** @return array<string, array{0: class-string, 1: string}> */
    public static function historyTables(): array
    {
        return [
            'stocks' => [StockHistory::class, 'stock_id'],
            'bonds' => [BondHistory::class, 'bond_id'],
            'etfs' => [EtfHistory::class, 'etf_id'],
        ];
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('historyTables')]
    public function testEveryHistoryTableCarriesTheSimulationClock(string $entity, string $foreignKey): void
    {
        $reflection = new \ReflectionClass($entity);

        $this->assertTrue($reflection->hasProperty('simTime'), $entity . ' records no simulation time.');

        $column = $reflection->getProperty('simTime')->getAttributes(Column::class);
        $this->assertCount(1, $column, $entity . '::$simTime is not a mapped column.');

        // Nullable, because a row written before the column existed has no honest value to put here and a
        // zero would sort it before the beginning of the simulation rather than outside it.
        $this->assertTrue($column[0]->newInstance()->nullable, $entity . '::$simTime must tolerate legacy rows.');
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('historyTables')]
    public function testTheSimulationClockIsIndexedAlongsideItsOwner(string $entity, string $foreignKey): void
    {
        // A chart reads one asset's newest rows in simulation order. Without the composite index that is a
        // filesort over the name's entire history on every chart load.
        $indexed = [];

        foreach ((new \ReflectionClass($entity))->getAttributes(Index::class) as $attribute) {
            $indexed[] = $attribute->newInstance()->columns;
        }

        $this->assertContains([$foreignKey, 'sim_time'], $indexed, $entity . ' cannot be read in simulation order.');
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('historyTables')]
    public function testTheWallClockIsKeptAsWell(string $entity, string $foreignKey): void
    {
        // The two answer different questions — when it happened, and when it was written — and losing the
        // second would make a stalled or replaying ticker invisible after the fact.
        $this->assertTrue((new \ReflectionClass($entity))->hasProperty('recordedAt'), $entity);
    }
}
