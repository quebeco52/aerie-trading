<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\EventListener\DeferredWriteAudit;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;

/**
 * Under DEFERRED_EXPLICIT tracking a change without a persist() is dropped in silence, so what is pinned
 * here is that the audit notices — and that noticing is also repairing, because computeChangeSet() puts the
 * entity into the very flush that was about to skip it.
 *
 * The unit of work is the real one. A stub could be made to return whatever the assertion wanted, and the
 * whole question is what Doctrine itself does with an entity nobody persisted; the connection is never
 * opened, because the server version is configured and the metadata comes from the attribute driver.
 */
class DeferredWriteAuditTest extends TestCase
{
    /** Classes the tests below mark explicit before registering anything, standing in for the mapping. */
    private const EXPLICIT = [Stock::class, Bond::class];

    private function entityManager(bool $explicit = true): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../../src/Entity'], false);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
            // Declared so the metadata factory never asks the driver for it, which is what would connect.
            'serverVersion' => '8.0.36',
        ], $config);

        $em = new EntityManager($connection, $config);

        if ($explicit) {
            foreach (self::EXPLICIT as $class) {
                $em->getClassMetadata($class)->setChangeTrackingPolicy(ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT);
            }
        }

        return $em;
    }

    /**
     * A managed, clean entity: every mapped field filled, and the same values registered as the snapshot a
     * hydration would have left behind, so nothing is dirty until a test makes it so.
     *
     * @param class-string $class
     */
    private function managed(EntityManager $em, string $class, int $id): object
    {
        $metadata = $em->getClassMetadata($class);
        $entity = $metadata->newInstance();
        $data = [];

        foreach ($metadata->fieldMappings as $field => $mapping) {
            $value = match ($mapping->type) {
                'integer', 'bigint', 'smallint' => $id,
                'float' => 1.0,
                'decimal' => '1.00',
                'boolean' => false,
                'datetime', 'datetime_immutable' => new \DateTime('2026-01-01 00:00:00'),
                'json' => [],
                default => 'x',
            };

            if ($field === $metadata->identifier[0]) {
                $value = $id;
            }

            $metadata->setFieldValue($entity, $field, $value);
            $data[$field] = $value;
        }

        $em->getUnitOfWork()->registerManaged($entity, [$metadata->identifier[0] => $id], $data);

        return $entity;
    }

    private function change(EntityManager $em, object $entity, string $field, mixed $value): void
    {
        $em->getClassMetadata($entity::class)->setFieldValue($entity, $field, $value);
    }

    public function testNothingIsAuditedUntilItIsAskedFor(): void
    {
        $audit = new DeferredWriteAudit();
        $em = $this->entityManager();
        $stock = $this->managed($em, Stock::class, 1);
        $this->change($em, $stock, 'creditRating', 'CCC');

        $audit->preFlush(new PreFlushEventArgs($em));

        $this->assertSame(0, $audit->total());
        $this->assertFalse($em->getUnitOfWork()->isScheduledForUpdate($stock), 'A disabled audit must not touch the flush.');
    }

    public function testAChangeWithoutPersistIsReportedAndRepaired(): void
    {
        $audit = new DeferredWriteAudit();
        $audit->enable();
        $em = $this->entityManager();
        $stock = $this->managed($em, Stock::class, 1);
        $this->change($em, $stock, 'creditRating', 'CCC');

        $audit->preFlush(new PreFlushEventArgs($em));

        $this->assertSame(1, $audit->total());
        $this->assertSame('Stock 1', $audit->summary());
        $this->assertTrue(
            $em->getUnitOfWork()->isScheduledForUpdate($stock),
            'The point of auditing at preFlush is that the dropped write is put back into this flush.'
        );
    }

    public function testAChangeHandedToPersistIsNotReported(): void
    {
        $audit = new DeferredWriteAudit();
        $audit->enable();
        $em = $this->entityManager();
        $stock = $this->managed($em, Stock::class, 1);
        $this->change($em, $stock, 'creditRating', 'AA');
        $em->persist($stock);

        $audit->preFlush(new PreFlushEventArgs($em));

        $this->assertSame(0, $audit->total());
        $this->assertSame('', $audit->summary());
    }

    public function testAnUntouchedEntityIsNotReported(): void
    {
        $audit = new DeferredWriteAudit();
        $audit->enable();
        $em = $this->entityManager();
        $stock = $this->managed($em, Stock::class, 1);

        $audit->preFlush(new PreFlushEventArgs($em));

        $this->assertSame(0, $audit->total());
        $this->assertFalse($em->getUnitOfWork()->isScheduledForUpdate($stock));
    }

    public function testEveryAuditedClassIsCounted(): void
    {
        $audit = new DeferredWriteAudit();
        $audit->enable();
        $em = $this->entityManager();
        $this->change($em, $this->managed($em, Stock::class, 1), 'creditRating', 'CCC');
        $this->change($em, $this->managed($em, Stock::class, 2), 'creditRating', 'CCC');
        $this->change($em, $this->managed($em, Bond::class, 3), 'status', Bond::STATUS_MATURED);

        $audit->preFlush(new PreFlushEventArgs($em));

        $this->assertSame(3, $audit->total());
        $this->assertSame('Stock 2, Bond 1', $audit->summary());
    }

    public function testAClassLeftOnImplicitTrackingIsNotAudited(): void
    {
        $audit = new DeferredWriteAudit();
        $audit->enable();
        $em = $this->entityManager();
        // Etf is deliberately left implicit: four entities are not worth a second write contract, and the
        // unit of work will find this change without being told about it.
        $this->change($em, $this->managed($em, Etf::class, 1), 'price', '2.00');

        $audit->preFlush(new PreFlushEventArgs($em));

        $this->assertSame(0, $audit->total());
    }

    /**
     * Read off the real mapping, with nothing overridden: the audit only looks at a class the mapping says
     * is tracked explicitly, so losing the attribute would quietly take both the saving and the check away.
     */
    public function testTheMappingItselfTracksBondExplicitlyAndStockImplicitly(): void
    {
        $em = $this->entityManager(explicit: false);

        $this->assertTrue(
            $em->getClassMetadata(Bond::class)->isChangeTrackingDeferredExplicit(),
            'Bond carries the policy: its three write sites all persist().'
        );
        $this->assertTrue(
            $em->getClassMetadata(Stock::class)->isChangeTrackingDeferredImplicit(),
            'Stock does NOT: its mutation surface is ~20 services deep and has not been audited.'
        );
        $this->assertTrue($em->getClassMetadata(Etf::class)->isChangeTrackingDeferredImplicit());
    }

    public function testAResetForgetsTheLastFlush(): void
    {
        $audit = new DeferredWriteAudit();
        $audit->enable();
        $em = $this->entityManager();
        $this->change($em, $this->managed($em, Stock::class, 1), 'creditRating', 'CCC');

        $audit->preFlush(new PreFlushEventArgs($em));
        $audit->reset();

        $this->assertSame(0, $audit->total());
        $this->assertSame('', $audit->summary());
    }
}
