<?php

declare(strict_types=1);

namespace App\Service\Macro\Recorder;

use App\Data\Macro\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Persists macroeconomic state vector snapshots into the historical macro_report database table.
 */
class MacroSnapshotRecorder
{
    // --- Snapshot Schema ---

    /** Column carrying the wall-clock time a snapshot was taken; not owned by the macro vector. */
    private const TIMESTAMP_COLUMN = 'recorded_at';

    /** Column carrying the quarter's gap drift decomposition; not owned by the macro vector either. */
    private const GAP_CHANNELS_COLUMN = 'gap_channels';

    /** Column carrying the quarter's inflation and policy accounts, averages and shock log. */
    private const DIAGNOSTICS_COLUMN = 'quarter_diagnostics';

    /** Column carrying the hash of the constants that ran the quarter. */
    private const FINGERPRINT_COLUMN = 'config_fingerprint';

    /** Column carrying the tick rate the quarter ran at. */
    private const TICKS_PER_YEAR_COLUMN = 'ticks_per_year';

    /**
     * Prepared INSERT statement, built once per process from the registry's column list.
     */
    private ?string $statement = null;

    /**
     * Persists an immutable historical econometric snapshot to the database.
     *
     * The column list and the value list are generated from the same
     * App\Data\Macro\MacroFieldRegistry mapping, so a field cannot land in the wrong column. The
     * previous hand-written statement named 107 columns, 107 placeholders and 106 property reads in
     * three separate lists that only a careful eye kept aligned; one insertion in the wrong place
     * silently shifted every following value into its neighbour's column.
     *
     * The probes' accounts ride along in the same row rather than in a store of their own. They are
     * produced on this exact tick boundary, so writing it here is what makes "which channels moved
     * the gap" and "what state the economy was in" the same record: a reader needs no join, and no
     * alignment can slip when a ticker restart drops a partial window from one series and not the
     * other.
     *
     * Every value is bound with an explicit type, because DBAL falls back to a string bind for an
     * unnamed one and a bool then reaches the driver as '' rather than as 0.
     *
     * @param MacroStateDTO      $macroState State snapshot to record.
     * @param Connection         $conn       Database connection.
     * @param QuarterRecord|null $quarter    The probes' closed windows and the run's identity; null records the
     *                                       vector alone.
     */
    public function recordSnapshot(MacroStateDTO $macroState, Connection $conn, ?QuarterRecord $quarter = null): void
    {
        $columns = MacroFieldRegistry::persistedColumns();

        $values = [(new \DateTimeImmutable())->format('Y-m-d H:i:s')];
        $types = [ParameterType::STRING];
        foreach (array_keys($columns) as $field) {
            $value = $macroState->$field;

            // A parameter bound without a type binds as a string, and PHP renders false as '', which
            // MySQL in strict mode rejects for the TINYINT a bool column is. Naming the type sends the
            // regime flags through Doctrine's BooleanType, which writes the platform's own literal.
            $types[] = is_bool($value) ? Types::BOOLEAN : ParameterType::STRING;
            $values[] = $value;
        }

        // Last, matching buildStatement's order. The statement is prepared once per process, so the
        // columns are always named and carry NULL on a quarter with no decomposition rather than
        // making the shape of the statement depend on the row.
        $values[] = $quarter?->gapChannels === null ? null : json_encode($quarter->gapChannels);
        $values[] = $quarter?->diagnostics === null ? null : json_encode($quarter->diagnostics);
        $values[] = $quarter?->configFingerprint;
        $values[] = $quarter?->ticksPerYear;
        array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER);

        $conn->executeStatement($this->statement ??= $this->buildStatement($columns), $values, $types);
    }

    /**
     * Builds the INSERT statement for the timestamp column followed by every persisted macro field.
     *
     * @param  array<string, string> $columns PHP property name => macro_report column name.
     * @return string                The parameterised INSERT statement.
     */
    private function buildStatement(array $columns): string
    {
        $names = array_merge(
            [self::TIMESTAMP_COLUMN],
            array_values($columns),
            [self::GAP_CHANNELS_COLUMN, self::DIAGNOSTICS_COLUMN, self::FINGERPRINT_COLUMN, self::TICKS_PER_YEAR_COLUMN]
        );
        $placeholders = array_fill(0, count($names), '?');

        return sprintf(
            'INSERT INTO macro_report (%s) VALUES (%s)',
            implode(', ', $names),
            implode(', ', $placeholders)
        );
    }
}
