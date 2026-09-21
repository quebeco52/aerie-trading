<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Finds mutations that DEFERRED_EXPLICIT change tracking would drop, and writes them anyway.
 *
 * An entity mapped DEFERRED_EXPLICIT is examined by the unit of work only if it was handed to persist()
 * since the last flush. That is what spares the flush a comparison of every mapped field of every managed
 * entity — 96 of them on a Stock, over a map of several hundred, on two ticks in three — and its price is a
 * contract no compiler enforces: a setter called without a matching persist() is silently not written, and
 * the symptom arrives later as a company whose report never landed rather than as an error.
 *
 * So the same question is asked here from the other side. Anything managed, not scheduled for dirty check
 * and nonetheless changed is a site that has fallen out of step; it is counted, and computeChangeSet() puts
 * it back into the flush on its way past, so a run with this on cannot lose a write.
 *
 * PRE-FLUSH, NOT ON-FLUSH. UnitOfWork::commit() raises preFlush before it computes its change sets, and
 * on-flush after — by which point commit() has already taken its "nothing to do" early return whenever the
 * change sets came back empty, dispatching the event and returning without executing a single update. An
 * audit there could report a dropped write but not repair one, and it would fail exactly on the quiet bars
 * where a dropped write is least likely to be noticed any other way.
 *
 * Costs the walk it exists to replace, so it is off unless the ticker's --audit-writes asks for it: running
 * with it on is no slower than the implicit tracking it audits, which is what makes it usable against a live
 * market rather than only against a fixture.
 */
#[AsDoctrineListener(event: Events::preFlush)]
final class DeferredWriteAudit
{
    // --- Reporting ---
    /** Entity classes named in a report, heaviest first; the rest are summed into a remainder. */
    public const CLASSES_REPORTED = 3;

    private bool $enabled = false;

    /** @var array<string, int> Short class name => entities the last flush would have dropped. */
    private array $missed = [];

    /** Starts auditing. The ticker turns this on for a canary run; nothing else needs it. */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /** Forgets the last flush, so a report describes one tick rather than the run so far. */
    public function reset(): void
    {
        $this->missed = [];
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $manager = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();

        foreach ($unitOfWork->getIdentityMap() as $className => $entities) {
            $metadata = $manager->getClassMetadata($className);

            // Which classes are audited is read off the mapping rather than held as a list here. A list is a
            // second place to keep in step with the entities, and one that falls out of step silently is the
            // failure this whole listener exists to catch. Under implicit tracking there is nothing to miss:
            // the unit of work finds the change by itself, and persist() does not mark an entity for dirty
            // check at all, so every genuine change would be reported as a miss.
            if (!$metadata->isChangeTrackingDeferredExplicit()) {
                continue;
            }

            foreach ($entities as $entity) {
                // Handed to persist() by whoever changed it: the contract was kept, and computing its change
                // set here would only do the work the flush is about to do anyway.
                if ($unitOfWork->isScheduledForDirtyCheck($entity)) {
                    continue;
                }

                // Doctrine's own comparison rather than a second implementation of it, so a decimal held as a
                // string and a date held as an object are judged the way the persister would judge them. It
                // writes straight to the update schedule, which is what makes this a repair and not a report.
                $unitOfWork->computeChangeSet($metadata, $entity);

                if (!$unitOfWork->isScheduledForUpdate($entity)) {
                    continue;
                }

                $short = substr((string) strrchr('\\' . $entity::class, '\\'), 1);
                $this->missed[$short] = ($this->missed[$short] ?? 0) + 1;
            }
        }
    }

    /** Entities the flushes since the last reset would have dropped, in total. */
    public function total(): int
    {
        return array_sum($this->missed);
    }

    /**
     * What went unpersisted, heaviest first, as "Stock 2, Bond 1".
     *
     * Empty string when the contract held everywhere, so a caller can print nothing rather than print a zero
     * on every tick of a run that is behaving.
     */
    public function summary(): string
    {
        if ($this->missed === []) {
            return '';
        }

        $missed = $this->missed;
        arsort($missed);

        $named = array_slice($missed, 0, self::CLASSES_REPORTED, true);
        $parts = [];

        foreach ($named as $class => $entities) {
            $parts[] = $class . ' ' . $entities;
        }

        $remainder = count($missed) - count($named);

        if ($remainder > 0) {
            $parts[] = '+' . $remainder . ' more';
        }

        return implode(', ', $parts);
    }
}
