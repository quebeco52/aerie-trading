<?php

declare(strict_types=1);

namespace App\Service\Macro\Recorder;

use App\Data\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use Doctrine\DBAL\Connection;

/**
 * Persists macroeconomic state vector snapshots into the historical macro_report database table.
 */
class MacroSnapshotRecorder
{
    // --- Snapshot Schema ---

    /** Column carrying the wall-clock time a snapshot was taken; the only column not owned by the macro vector. */
    private const TIMESTAMP_COLUMN = 'recorded_at';

    /**
     * Prepared INSERT statement, built once per process from the registry's column list.
     */
    private ?string $statement = null;

    /**
     * Persists an immutable historical econometric snapshot to the database.
     *
     * The column list and the value list are generated from the same
     * App\Data\MacroFieldRegistry mapping, so a field cannot land in the wrong column. The
     * previous hand-written statement named 107 columns, 107 placeholders and 106 property reads in
     * three separate lists that only a careful eye kept aligned; one insertion in the wrong place
     * silently shifted every following value into its neighbour's column.
     *
     * @param MacroStateDTO $macroState State snapshot to record.
     * @param Connection    $conn       Database connection.
     */
    public function recordSnapshot(MacroStateDTO $macroState, Connection $conn): void
    {
        $columns = MacroFieldRegistry::persistedColumns();

        $values = [(new \DateTimeImmutable())->format('Y-m-d H:i:s')];
        foreach (array_keys($columns) as $field) {
            $values[] = $macroState->$field;
        }

        $conn->executeStatement($this->statement ??= $this->buildStatement($columns), $values);
    }

    /**
     * Builds the INSERT statement for the timestamp column followed by every persisted macro field.
     *
     * @param  array<string, string> $columns PHP property name => macro_report column name.
     * @return string                The parameterised INSERT statement.
     */
    private function buildStatement(array $columns): string
    {
        $names = array_merge([self::TIMESTAMP_COLUMN], array_values($columns));
        $placeholders = array_fill(0, count($names), '?');

        return sprintf(
            'INSERT INTO macro_report (%s) VALUES (%s)',
            implode(', ', $names),
            implode(', ', $placeholders)
        );
    }
}
