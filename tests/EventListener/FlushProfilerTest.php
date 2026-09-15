<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Bond;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\UserStock;
use App\EventListener\FlushProfiler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;

/**
 * A slow flush has two causes with opposite fixes — a bloated identity map, or a tick that really did turn a
 * lot of rows over — and the ticker's lag warning reports both the map size and this attribution so they can
 * be told apart. What is pinned here is that the profiler stays silent until something asks for it, and that
 * a report describes ONE tick rather than the run so far.
 */
class FlushProfilerTest extends TestCase
{
    /**
     * @param array<int, object>   $insertions
     * @param array<int, object>   $updates
     * @param array<int, object>   $deletions
     * @param array<string, mixed> $changed Field => anything, the changeset every update is given.
     */
    private function flush(
        FlushProfiler $profiler,
        array $insertions,
        array $updates = [],
        array $deletions = [],
        array $changed = ['ticker' => null]
    ): void {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($insertions);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn($updates);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn($deletions);
        $unitOfWork->method('getEntityChangeSet')->willReturn($changed);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);
        $em->method('getClassMetadata')->willReturnCallback($this->metadata(...));

        $profiler->onFlush(new OnFlushEventArgs($em));
    }

    /**
     * The real mapping, so the non-updatable columns under test are the ones the entity actually declares.
     *
     * @param class-string $class
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $driver = new AttributeDriver([__DIR__ . '/../../src/Entity']);
        $driver->loadMetadataForClass($class, $metadata);

        return $metadata;
    }

    /** @return array<int, object> */
    private function many(string $class, int $count): array
    {
        $entities = [];

        for ($i = 0; $i < $count; $i++) {
            $entities[] = new $class();
        }

        return $entities;
    }

    public function testNothingIsRecordedUntilItIsAskedFor(): void
    {
        $profiler = new FlushProfiler();
        $this->flush($profiler, $this->many(Stock::class, 40));

        $this->assertSame(0, $profiler->total());
        $this->assertSame('', $profiler->summary());
    }

    public function testAFlushIsAttributedToTheEntitiesItWrites(): void
    {
        $profiler = new FlushProfiler();
        $profiler->enable();

        $this->flush($profiler, $this->many(Stock::class, 3), $this->many(Bond::class, 12), $this->many(Stock::class, 2));

        $this->assertSame(17, $profiler->total());
        $this->assertSame('Bond 12, Stock 5', $profiler->summary());
    }

    public function testOnlyTheHeaviestClassesAreNamedAndTheRestAreCounted(): void
    {
        $profiler = new FlushProfiler();
        $profiler->enable();

        $insertions = [];

        foreach ([Stock::class, Bond::class, TradeOrder::class, UserStock::class] as $index => $class) {
            $insertions = array_merge($insertions, $this->many($class, 10 - $index));
        }

        $this->flush($profiler, $insertions);

        $this->assertSame('Stock 10, Bond 9, TradeOrder 8, +1 more', $profiler->summary());
    }

    public function testAReportDescribesOneTickRatherThanTheRunSoFar(): void
    {
        $profiler = new FlushProfiler();
        $profiler->enable();

        $this->flush($profiler, $this->many(Stock::class, 5));
        $profiler->reset();
        $this->flush($profiler, $this->many(Bond::class, 2));

        $this->assertSame('Bond 2', $profiler->summary());
    }

    public function testTwoFlushesInsideOneTickAreAddedUp(): void
    {
        $profiler = new FlushProfiler();
        $profiler->enable();

        $this->flush($profiler, $this->many(Stock::class, 5));
        $this->flush($profiler, $this->many(Stock::class, 3));

        $this->assertSame('Stock 8', $profiler->summary());
    }

    // --- Intentions Are Not Statements ---

    public function testAnUpdateThatOnlyTouchesNonUpdatableColumnsIsNotAWrite(): void
    {
        $profiler = new FlushProfiler();
        $profiler->enable();

        // Exactly the tick's own columns, which StockTickColumns writes as data and the persister drops.
        $changed = array_fill_keys(['price', 'currentVolatility', 'impactVarianceEma'], null);
        $this->flush($profiler, [], $this->many(Stock::class, 67), [], $changed);

        $this->assertSame(0, $profiler->total(), 'A tick that writes no stock row must not report one.');
        $this->assertSame('', $profiler->summary());
    }

    public function testAnUpdateThatTouchesAnythingWritableIsAWrite(): void
    {
        $profiler = new FlushProfiler();
        $profiler->enable();

        // A split changes the share count alongside the price, and that column IS written.
        $changed = ['price' => null, 'sharesOutstanding' => null];
        $this->flush($profiler, [], $this->many(Stock::class, 4), [], $changed);

        $this->assertSame('Stock 4', $profiler->summary());
    }

    public function testAnInsertIsAlwaysAWriteBecauseItCarriesEveryColumn(): void
    {
        $profiler = new FlushProfiler();
        $profiler->enable();

        $changed = array_fill_keys(['price', 'currentVolatility'], null);
        $this->flush($profiler, $this->many(Stock::class, 2), [], $this->many(Stock::class, 1), $changed);

        $this->assertSame('Stock 3', $profiler->summary());
    }
}
