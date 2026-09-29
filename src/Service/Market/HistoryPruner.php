<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\OptionContract;
use App\Entity\SimulationClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enforces the simulation's data retention, measured in SIMULATED years against the simulation clock.
 *
 * Every cutoff here is a span of simulated time, so the only cadence that can enforce them is the simulated
 * clock. Hung on a wall-clock cron instead, a one-simulated-year retention was being applied once per
 * thousand-odd simulated years — which is not a retention policy, and left option_contracts at 97% settled
 * rows carrying three secondary indexes that every expiry's UPDATE and every sweep's dedupe read paid for.
 *
 * Idempotent by construction: it deletes what is already older than a cutoff, so a run missed while the
 * ticker was down and a run repeated after it restarts are both harmless. That is what lets the caller
 * trigger it on a boundary crossing with no persisted marker and no dispatch gate.
 */
final class HistoryPruner
{
    // --- Retention ---
    /** Simulated years of full-resolution history kept by default; beyond it a chart is reading bars, not ticks. */
    public const DEFAULT_YEARS_KEPT = 5.0;

    /** Simulated years between retention passes, matching the units the cutoffs themselves are written in: the ticker runs one on each crossing. */
    public const PRUNE_INTERVAL_YEARS = 1.0;

    /** Simulated years a settled option contract is kept after expiry; the listing grid only reaches four months out. */
    public const EXPIRED_OPTION_YEARS_KEPT = 1.0;

    /**
     * Quarterly macro snapshots kept: 120 simulated years, matching App\Service\Macro\Recorder\OutputGapProbe.
     *
     * Was 100 rows — 25 years — which is shorter than the thing the table is used to study. A business
     * cycle runs about six years here, so 25 years is four episodes: too few to say whether a bust's depth
     * distribution moved, which is the question every calibration pass on this table asks. At ~1.5 KB a row
     * the whole 120 years is under a megabyte, so the retention is set by the horizon of the analysis
     * rather than by the cost of the rows.
     */
    public const MACRO_QUARTERS_KEPT = 480;

    /** Rows a series keeps per simulated year once older than the full-resolution window: one a week, the bar a chart of that span is drawn in. */
    public const THINNED_ROWS_PER_YEAR = 52;

    // --- Batching ---
    /** Primary-key ids one DELETE spans. Short statements keep the row locks and undo log small while the ticker keeps inserting behind them. */
    public const DELETE_BATCH_IDS = 50000;

    /** History tables carrying a sim_time stamp, all downsampled on the same cutoff: table => the column naming its series. */
    public const HISTORY_TABLES = ['stock_history' => 'stock_id', 'etf_history' => 'etf_id', 'bond_history' => 'bond_id'];

    /** History tables whose rows are OHLCV bars, folded into the row a thinned week keeps. */
    public const BAR_TABLES = ['stock_history'];

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * The simulated time below which a series has been thinned by the default retention: the cutoff of the pass
     * the ticker ran at the last interval boundary its newest row has crossed.
     */
    public static function thinnedBefore(float $newestSimTime): float
    {
        return floor($newestSimTime / self::PRUNE_INTERVAL_YEARS) * self::PRUNE_INTERVAL_YEARS - self::DEFAULT_YEARS_KEPT;
    }

    /**
     * Applies every retention rule once and reports what it removed.
     *
     * @return array{now: float, cutoff: float, optionCutoff: float, tables: array<string, int>, options: int, macro: int}
     */
    public function prune(float $years, int $rowsPerYear): array
    {
        $conn = $this->em->getConnection();

        $now = (float) $conn->fetchOne(
            'SELECT total_time FROM simulation_clock WHERE id = ?',
            [SimulationClock::SINGLETON_ID]
        );

        $cutoff = $now - $years;
        $optionCutoff = $now - self::EXPIRED_OPTION_YEARS_KEPT;

        $tables = [];

        foreach (self::HISTORY_TABLES as $table => $seriesColumn) {
            $tables[$table] = $this->downsampleTable($conn, $table, $seriesColumn, $cutoff, $rowsPerYear);
        }

        return [
            'now' => $now,
            'cutoff' => $cutoff,
            'optionCutoff' => $optionCutoff,
            'tables' => $tables,
            'options' => $this->pruneSettledOptions($conn, $optionCutoff),
            'macro' => $this->pruneMacroReports($conn),
        ];
    }

    /**
     * Downsamples one history table below the cutoff to the last row of each series in each slice of simulated
     * time, walking the primary key in batches.
     *
     * Thinning by slice of each series' own time is what keeps every series charted. The rule it replaced kept
     * rows whose id was a multiple of the ratio, and every bar writes one row per stock in the same order, so the
     * kept ids fell on the same one or two stocks every time and every other stock lost all its old history.
     * A bar table first folds each slice's bar into the row it keeps (the first open, the extremes, the volume
     * summed), in the same transaction as the delete, so a thinned week still charts as the week it was and a
     * retry cannot count a volume twice.
     *
     * A single DELETE over the whole table was one transaction the size of the history: hours of row locks
     * and undo log against a ticker inserting thousands of rows a second, and the sim_time predicate has no
     * index of its own (every history index leads with the asset id). Ids are assigned in insertion order,
     * so simulated time is monotonic in id: the walk starts at the lowest id and stops at the first batch
     * that holds rows and none of them older than the cutoff. A slice split across two batches keeps a row in
     * each until a later run's batches put it in one.
     */
    private function downsampleTable(Connection $conn, string $table, string $seriesColumn, float $cutoff, int $rowsPerYear): int
    {
        /** @var array{0: int|string|null, 1: int|string|null}|false $bounds */
        $bounds = $conn->fetchNumeric("SELECT MIN(id), MAX(id) FROM $table");
        if ($bounds === false || $bounds[0] === null || $bounds[1] === null) {
            return 0;
        }

        $lo = (int) $bounds[0];
        $max = (int) $bounds[1];
        $deleted = 0;
        $sliceColumns = "SELECT $seriesColumn AS series, FLOOR(sim_time * :perYear) AS slice, MIN(id) AS first_id, MAX(id) AS kept_id";

        while ($lo <= $max) {
            $hi = $lo + self::DELETE_BATCH_IDS;
            $range = ['lo' => $lo, 'hi' => $hi, 'cutoff' => $cutoff];

            /** @var array{total: int|string, old: int|string|null}|false $probe */
            $probe = $conn->fetchAssociative(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN sim_time IS NULL OR sim_time < :cutoff THEN 1 ELSE 0 END) AS old
                 FROM $table WHERE id >= :lo AND id < :hi",
                $range
            );

            $total = $probe === false ? 0 : (int) $probe['total'];
            $old = $probe === false ? 0 : (int) ($probe['old'] ?? 0);

            if ($total > 0 && $old === 0) {
                break;
            }

            if ($old > 0) {
                $params = $range + ['perYear' => $rowsPerYear];
                $deleted += (int) $conn->transactional(static function (Connection $conn) use ($table, $seriesColumn, $sliceColumns, $params): int {
                    if (in_array($table, self::BAR_TABLES, true)) {
                        $conn->executeStatement(
                            "UPDATE $table kept
                             JOIN ($sliceColumns,
                                          MAX(COALESCE(high_price, price)) AS high_price,
                                          MIN(COALESCE(low_price, price)) AS low_price,
                                          SUM(volume) AS volume
                                   FROM $table
                                   WHERE id >= :lo AND id < :hi AND sim_time < :cutoff
                                   GROUP BY $seriesColumn, FLOOR(sim_time * :perYear)
                                   HAVING COUNT(*) > 1) slices ON kept.id = slices.kept_id
                             JOIN $table opener ON opener.id = slices.first_id
                             SET kept.open_price = COALESCE(opener.open_price, opener.price),
                                 kept.high_price = slices.high_price,
                                 kept.low_price = slices.low_price,
                                 kept.volume = slices.volume",
                            $params
                        );
                    }

                    // A row with no simulated time cannot be placed on a chart at all, so it goes whole.
                    return (int) $conn->executeStatement(
                        "DELETE h FROM $table h
                         LEFT JOIN ($sliceColumns
                                    FROM $table
                                    WHERE id >= :lo AND id < :hi AND sim_time < :cutoff
                                    GROUP BY $seriesColumn, FLOOR(sim_time * :perYear)) slices
                                ON slices.series = h.$seriesColumn AND slices.slice = FLOOR(h.sim_time * :perYear)
                         WHERE h.id >= :lo AND h.id < :hi
                           AND (h.sim_time IS NULL OR (h.sim_time < :cutoff AND h.id <> slices.kept_id))",
                        $params
                    );
                });
            }

            $lo = $hi;
        }

        return $deleted;
    }

    /**
     * Removes settled contracts past the option cutoff, in primary-key batches.
     *
     * Batched for the same reason the history tables are — this now runs against a live ticker rather than
     * an idle nightly database, and unbatched it was a single DELETE across the whole table with a
     * correlated subquery evaluated per candidate row.
     *
     * NO EARLY BREAK, unlike the history walk. A listing pass opens several serials at once and the furthest
     * of them expires half a year beyond the nearest, so expiry is only broadly monotonic in id: a batch
     * with nothing due can be followed by one that has some, and stopping at the first would strand rows
     * that no later run would reach either.
     *
     * The position guard is belt and braces: settlement removes every UserOption it settles, so an expired
     * contract should never have one. But user_options cascades on delete, so a contract that somehow kept a
     * position would take that position's record with it silently — the one outcome worth a subquery to
     * rule out.
     */
    private function pruneSettledOptions(Connection $conn, float $cutoff): int
    {
        /** @var array{0: int|string|null, 1: int|string|null}|false $bounds */
        $bounds = $conn->fetchNumeric('SELECT MIN(id), MAX(id) FROM option_contracts');
        if ($bounds === false || $bounds[0] === null || $bounds[1] === null) {
            return 0;
        }

        $lo = (int) $bounds[0];
        $max = (int) $bounds[1];
        $deleted = 0;

        while ($lo <= $max) {
            $hi = $lo + self::DELETE_BATCH_IDS;

            $deleted += (int) $conn->executeStatement(
                'DELETE FROM option_contracts
                 WHERE id >= :lo AND id < :hi
                   AND status = :status
                   AND expires_at_time < :cutoff
                   AND NOT EXISTS (
                       SELECT 1 FROM user_options WHERE user_options.option_contract_id = option_contracts.id
                   )',
                ['lo' => $lo, 'hi' => $hi, 'status' => OptionContract::STATUS_EXPIRED, 'cutoff' => $cutoff]
            );

            $lo = $hi;
        }

        return $deleted;
    }

    /** Keeps the newest MACRO_QUARTERS_KEPT snapshots; retention here is a row count, not a span of time. */
    private function pruneMacroReports(Connection $conn): int
    {
        $cutoffId = $conn->fetchOne(
            'SELECT id FROM macro_report ORDER BY id DESC LIMIT 1 OFFSET ' . (self::MACRO_QUARTERS_KEPT - 1)
        );

        if (!$cutoffId) {
            return 0;
        }

        return (int) $conn->executeStatement(
            'DELETE FROM macro_report WHERE id < :cutoff',
            ['cutoff' => $cutoffId]
        );
    }
}
