<?php

namespace App\Command;

use App\Entity\OptionContract;
use App\Entity\SimulationClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:prune-history',
    description: 'Downsamples old market history to save disk space while preserving long-term charts.',
)]
class PruneHistoryCommand extends Command
{
    // --- Retention ---
    /** Simulated years of full-resolution history kept by default; beyond it a chart is reading bars, not ticks. */
    public const DEFAULT_YEARS_KEPT = 5.0;

    /** Simulated years a settled option contract is kept after expiry; the listing grid only reaches four months out. */
    public const EXPIRED_OPTION_YEARS_KEPT = 1.0;

    // --- Batching ---
    /** Primary-key ids one DELETE spans. Short statements keep the row locks and undo log small while the ticker keeps inserting behind them. */
    public const DELETE_BATCH_IDS = 50000;

    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('years', 'y', InputOption::VALUE_OPTIONAL, 'Simulated years of full-resolution history to keep?', (string) self::DEFAULT_YEARS_KEPT);
        $this->addOption('ratio', 'r', InputOption::VALUE_OPTIONAL, 'Keep 1 out of every X records?', 1000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $years = (float) $input->getOption('years');
        $ratio = (int) $input->getOption('ratio');

        $io->title("Downsampling Market History (Older than $years simulated years | Keeping 1 in $ratio)");

        // raw DBAL connection
        $conn = $this->em->getConnection();

        $now = (float) $conn->fetchOne('SELECT total_time FROM simulation_clock WHERE id = ?', [SimulationClock::SINGLETON_ID]);
        $cutoff = $now - $years;

        $io->text(sprintf('Simulation stands at year %.4f; downsampling everything before year %.4f.', $now, $cutoff));

        try {
            foreach (['stock_history', 'etf_history', 'bond_history'] as $table) {
                $deleted = $this->downsampleTable($conn, $table, $cutoff, $ratio);
                $io->success("Cleared $deleted redundant rows from $table.");
            }

            // Settled option contracts, which nothing reads and nothing downsamples.
            //
            // A contract is a ROW, because open interest has to live somewhere, and settlement only stamps
            // it EXPIRED — so the table kept every contract the desk had ever listed, forever, while the
            // listing sweep read it on a cadence. Twelve simulated years of monthly serials across sixty
            // names is a few hundred thousand dead rows carrying three secondary indexes, and every one of
            // them was paid for on each expiry's UPDATE and each sweep's dedupe read.
            //
            // The position guard is belt and braces: settlement removes every UserOption it settles, so an
            // expired contract should never have one. But user_options cascades on delete, so a contract
            // that somehow kept a position would take that position's record with it silently — which is
            // the one outcome worth a subquery to rule out.
            $optionCutoff = $now - self::EXPIRED_OPTION_YEARS_KEPT;
            $optionsDeleted = $conn->executeStatement(
                'DELETE FROM option_contracts
                 WHERE status = :status
                   AND expires_at_time < :cutoff
                   AND NOT EXISTS (
                       SELECT 1 FROM user_options WHERE user_options.option_contract_id = option_contracts.id
                   )',
                ['status' => OptionContract::STATUS_EXPIRED, 'cutoff' => $optionCutoff]
            );
            $io->success("Cleared $optionsDeleted settled contracts from option_contracts (expired before year " . sprintf('%.4f', $optionCutoff) . ").");

            // Prune Macro Reports (Keep the latest 100 simulation quarters)
            $io->text("Pruning old macro reports (keeping the latest 100)...");
            $macroCutoffId = $conn->fetchOne('SELECT id FROM macro_report ORDER BY id DESC LIMIT 1 OFFSET 99');
            
            if ($macroCutoffId) {
                $macroDeleted = $conn->executeStatement(
                    'DELETE FROM macro_report WHERE id < :cutoff',
                    ['cutoff' => $macroCutoffId]
                );
                $io->success("Cleared $macroDeleted old rows from macro_report.");
            } else {
                $io->success("No old macro reports to clear.");
            }

        } catch (\Exception $e) {
            $io->error("An error occurred: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Downsampling complete! Long-term charts preserved, disk space recovered.');

        return Command::SUCCESS;
    }

    /**
     * Downsamples one history table below the cutoff, walking the primary key in batches.
     *
     * A single DELETE over the whole table was one transaction the size of the history: hours of row locks
     * and undo log against a ticker inserting thousands of rows a second, and the sim_time predicate has no
     * index of its own (every history index leads with the asset id). Ids are assigned in insertion order,
     * so simulated time is monotonic in id: the walk starts at the lowest id and stops at the first batch
     * that holds rows and none of them older than the cutoff. A batch with no rows at all is a region an
     * earlier run already thinned, and the walk continues through it.
     */
    private function downsampleTable(Connection $conn, string $table, float $cutoff, int $ratio): int
    {
        /** @var array{0: int|string|null, 1: int|string|null}|false $bounds */
        $bounds = $conn->fetchNumeric("SELECT MIN(id), MAX(id) FROM $table");
        if ($bounds === false || $bounds[0] === null || $bounds[1] === null) {
            return 0;
        }

        $lo = (int) $bounds[0];
        $max = (int) $bounds[1];
        $deleted = 0;

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
                $deleted += (int) $conn->executeStatement(
                    "DELETE FROM $table
                     WHERE id >= :lo AND id < :hi
                       AND (sim_time IS NULL OR sim_time < :cutoff)
                       AND id % :ratio != 0",
                    $range + ['ratio' => $ratio]
                );
            }

            $lo = $hi;
        }

        return $deleted;
    }
}
