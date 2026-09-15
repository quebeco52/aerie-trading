<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;

/**
 * Records what a flush is about to write, so a slow flush can be attributed rather than guessed at.
 *
 * A flush that runs long has two completely different causes with opposite fixes: an identity map that has
 * filled up with entities nothing is writing, so the unit of work walks thousands of objects to find nothing;
 * or a tick that genuinely turned a lot of rows over. The ticker's lag warning already reports the map size,
 * which separates those two — but when it IS real work, the next question is immediately "written by whom",
 * and that is not answerable from outside Doctrine at all.
 *
 * `onFlush` fires after the unit of work has computed its change sets and before it writes anything, so the
 * counts are free here: the arrays already exist and are only being counted. Off unless something turns it
 * on, because nothing outside the ticker needs it.
 *
 * WHAT IS COUNTED IS STATEMENTS, NOT INTENTIONS. An entity whose every changed column is mapped
 * `updatable: false` — as the six per-tick columns of a Stock are, see StockTickColumns — is scheduled for
 * update like any other and then written by nobody: the persister prepares its data, finds it empty and
 * returns before it reaches the database. Counting the schedule would report sixty-seven stocks written on a
 * tick that wrote no stock at all, and a diagnostic that reads like the bug it is meant to rule out is worse
 * than none. So the same question the persister asks is asked here first.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class FlushProfiler
{
    // --- Reporting ---
    /** Entity classes named in a report, heaviest first; the rest are summed into a remainder. */
    public const CLASSES_REPORTED = 3;

    private bool $enabled = false;

    /** @var array<string, int> Short class name => rows the last flush scheduled. */
    private array $writes = [];

    /** Starts recording. The ticker turns this on; the web process leaves it off. */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /** Forgets the last flush, so a report describes one tick rather than the run so far. */
    public function reset(): void
    {
        $this->writes = [];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $manager = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();

        // An insert carries every column and a delete needs none, so only an update can turn out to be a
        // statement that is never sent.
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->record($entity);
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $this->record($entity);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$this->reachesTheDatabase($manager, $entity, $unitOfWork->getEntityChangeSet($entity))) {
                continue;
            }

            $this->record($entity);
        }
    }

    /**
     * Whether an update will actually produce SQL.
     *
     * The persister drops every column mapped non-updatable and returns without a statement if nothing is
     * left. An association always maps to a column that is written, and a field it has never heard of is
     * assumed to be written rather than assumed away — guessing in the other direction would hide a real
     * write from the one tool meant to find it.
     *
     * @param array<string, mixed> $changeSet
     */
    private function reachesTheDatabase(ObjectManager $manager, object $entity, array $changeSet): bool
    {
        $metadata = $manager->getClassMetadata($entity::class);

        foreach (array_keys($changeSet) as $field) {
            if (!isset($metadata->fieldMappings[$field])) {
                return true;
            }

            if ($metadata->fieldMappings[$field]->notUpdatable !== true) {
                return true;
            }
        }

        return false;
    }

    private function record(object $entity): void
    {
        $short = substr((string) strrchr('\\' . $entity::class, '\\'), 1);
        $this->writes[$short] = ($this->writes[$short] ?? 0) + 1;
    }

    /** Rows the flushes since the last reset scheduled, in total. */
    public function total(): int
    {
        return array_sum($this->writes);
    }

    /**
     * What was written, heaviest first, as "Stock 67, Bond 12, +3 more".
     *
     * Empty string when nothing was written, so a caller can tell "the flush wrote nothing and was still
     * slow" — which would mean the cost is the walk rather than the writes — from "it wrote a lot".
     */
    public function summary(): string
    {
        if ($this->writes === []) {
            return '';
        }

        $writes = $this->writes;
        arsort($writes);

        $named = array_slice($writes, 0, self::CLASSES_REPORTED, true);
        $parts = [];

        foreach ($named as $class => $rows) {
            $parts[] = $class . ' ' . $rows;
        }

        $remainder = count($writes) - count($named);

        if ($remainder > 0) {
            $parts[] = '+' . $remainder . ' more';
        }

        return implode(', ', $parts);
    }
}
