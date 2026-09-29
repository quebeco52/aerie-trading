<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Market\HistoryPruner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Service\Math\FinancialConstants;

/**
 * Writes the recorded macro run out as newline-delimited JSON, one object per simulated quarter.
 *
 * The table this reads is the reason the headless harnesses can be retired for shape work. A harness
 * reproduces the engine's arithmetic but not its couplings — a run that passes no equity market cap
 * silently removes the wealth channel, and the numbers it produces look fine and describe a different
 * economy. macro_report is the economy that actually ran, at production dt, with every subsystem wired.
 *
 * NDJSON rather than one JSON array or a CSV: a quarter is a self-contained record, so the file streams,
 * `wc -l` counts quarters, and a nested decomposition needs no column explosion to survive the format.
 *
 * DECIMAL columns come back from the driver as strings. They are cast here rather than left as they are,
 * because a reader that does arithmetic on "0.0241" gets the right answer in Python and silently the wrong
 * one in anything that compares strings.
 *
 * TWO TRAPS FOR A READER OF THE FILE. The `last*_at` episode markers carry -1 for "has not happened", not a
 * simulated time, so an elapsed-since is meaningless until the marker is non-negative. And the state columns
 * are mostly DECIMAL(10, 4): 1bp resolution, enough for quarterly shape work and not for anything finer. Only
 * `gap_channels` and `quarter_diagnostics` are at full float precision, and `quarter_diagnostics.averages` holds
 * the quarter AVERAGES a comparison with quarterly-averaged data should use instead of the quarter-end columns.
 *
 * A dump can span a recalibration. Every row carries the fingerprint of the constants that ran it, and the
 * command says so when the file holds more than one economy.
 */
#[AsCommand(
    name: 'app:macro:gap-dump',
    description: 'Dumps recorded quarterly macro snapshots, with their output gap decomposition, as NDJSON.',
)]
class MacroGapDumpCommand extends Command
{
    // --- Output ---

    /** Default destination, inside the repo so the file is readable beside the code it is used to change. */
    public const DEFAULT_RELATIVE_PATH = 'var/macro-gap-history.jsonl';


    /** Columns that are neither part of the macro vector nor a decomposition, and are dropped from the dump. */
    private const NON_SERIES_COLUMNS = ['id', 'recorded_at'];

    /** JSON columns, decoded into the record rather than passed through as strings. */
    private const JSON_COLUMNS = ['gap_channels', 'quarter_diagnostics'];

    /** Columns that are labels rather than numbers. */
    private const STRING_COLUMNS = ['config_fingerprint'];

    /** Columns that are counts rather than readings. */
    private const INTEGER_COLUMNS = ['ticks_per_year'];

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'years',
            'y',
            InputOption::VALUE_OPTIONAL,
            'Simulated years to dump, counting back from the most recent quarter. 0 dumps everything retained.',
            '0'
        );
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_OPTIONAL,
            'Destination file; relative paths resolve against the project root.',
            self::DEFAULT_RELATIVE_PATH
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $years = max(0.0, (float) $input->getOption('years'));
        $path = (string) $input->getOption('output');

        if (!str_starts_with($path, '/')) {
            $path = $this->projectDir . '/' . $path;
        }

        $conn = $this->em->getConnection();

        // Newest first with a LIMIT, then reversed, so --years reads back from the present. Taking the
        // oldest rows instead would hand back the start of the run, which is the one stretch of it that
        // is still converging off its seed.
        $limit = $years > 0.0
            ? (int) ceil($years * FinancialConstants::QUARTERS_PER_YEAR)
            : HistoryPruner::MACRO_QUARTERS_KEPT;

        $rows = $conn->fetchAllAssociative(
            'SELECT * FROM macro_report ORDER BY id DESC LIMIT ' . $limit
        );

        if ($rows === []) {
            $io->warning('macro_report is empty: no ticker has recorded a quarter yet.');

            return Command::SUCCESS;
        }

        $rows = array_reverse($rows);

        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $io->error("Could not create {$directory}.");

            return Command::FAILURE;
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $io->error("Could not open {$path} for writing.");

            return Command::FAILURE;
        }

        $decomposed = 0;
        $diagnosed = 0;
        $fingerprints = [];
        foreach ($rows as $index => $row) {
            $record = $this->toRecord($row, $index + 1);
            $decomposed += isset($record['gap_channels']) ? 1 : 0;
            $diagnosed += isset($record['quarter_diagnostics']) ? 1 : 0;
            $fingerprints[] = $record['config_fingerprint'] ?? null;
            fwrite($handle, json_encode($record, JSON_THROW_ON_ERROR) . "\n");
        }

        fclose($handle);

        $first = $this->time($rows[0]);
        $last = $this->time($rows[array_key_last($rows)]);

        $io->success(sprintf(
            'Wrote %d quarters (%.2f simulated years, %s) to %s.',
            \count($rows),
            ($last - $first) + (1.0 / FinancialConstants::QUARTERS_PER_YEAR),
            sprintf('year %.4f to %.4f', $first, $last),
            $path
        ));

        // A run recorded before the decomposition column existed, or by a ticker with the probe off, is
        // still a usable state history — but it cannot answer "which channel", so say so rather than let
        // a reader discover it as a file full of nulls.
        if ($decomposed < \count($rows)) {
            $io->note(sprintf(
                '%d of %d quarters carry no gap decomposition.',
                \count($rows) - $decomposed,
                \count($rows)
            ));
        }
        if ($diagnosed < \count($rows)) {
            $io->note(sprintf(
                '%d of %d quarters carry no inflation, policy or shock diagnostics.',
                \count($rows) - $diagnosed,
                \count($rows)
            ));
        }

        // Pooling two calibrations measures neither, so a file that holds more than one is said to.
        $segments = self::segments($fingerprints);
        if (\count($segments) > 1) {
            $io->warning(array_merge(
                ['The dump spans ' . \count($segments) . ' constant sets; split on config_fingerprint before measuring:'],
                array_map(
                    static fn (array $segment): string => sprintf('%s  quarters %d-%d', $segment['fingerprint'] ?? 'unrecorded', $segment['from'], $segment['to']),
                    $segments
                )
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Runs of consecutive quarters recorded under one fingerprint, in file order.
     *
     * @param  list<string|null> $fingerprints One per quarter, in file order.
     * @return list<array{fingerprint: string|null, from: int, to: int}> 1-based quarter ranges.
     */
    public static function segments(array $fingerprints): array
    {
        $segments = [];
        foreach ($fingerprints as $index => $fingerprint) {
            $last = array_key_last($segments);
            if ($last !== null && $segments[$last]['fingerprint'] === $fingerprint) {
                $segments[$last]['to'] = $index + 1;

                continue;
            }
            $segments[] = ['fingerprint' => $fingerprint, 'from' => $index + 1, 'to' => $index + 1];
        }

        return $segments;
    }

    /**
     * One database row as the object written to the file.
     *
     * @param  array<string, mixed> $row     Raw row as the driver returned it.
     * @param  int                  $quarter 1-based position in the dump, so a reader can index without
     *                                       depending on surrogate ids that pruning makes non-contiguous.
     * @return array<string, mixed>
     */
    private function toRecord(array $row, int $quarter): array
    {
        $record = ['quarter' => $quarter];

        foreach ($row as $column => $value) {
            if (\in_array($column, self::NON_SERIES_COLUMNS, true)) {
                continue;
            }

            if (\in_array($column, self::JSON_COLUMNS, true)) {
                $decoded = \is_string($value) && $value !== '' ? json_decode($value, true) : null;
                $record[$column] = \is_array($decoded) ? $decoded : null;

                continue;
            }

            if (\in_array($column, self::STRING_COLUMNS, true)) {
                $record[$column] = $value === null ? null : (string) $value;

                continue;
            }

            if (\in_array($column, self::INTEGER_COLUMNS, true)) {
                $record[$column] = $value === null ? null : (int) $value;

                continue;
            }

            $record[$column] = $value === null ? null : (float) $value;
        }

        return $record;
    }

    /**
     * A row's simulated time, or 0.0 for a row written before the column existed.
     *
     * @param array<string, mixed> $row Raw row as the driver returned it.
     */
    private function time(array $row): float
    {
        return isset($row['total_time']) ? (float) $row['total_time'] : 0.0;
    }
}
