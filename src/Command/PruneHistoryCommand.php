<?php

namespace App\Command;

use App\Entity\OptionContract;
use App\Entity\SimulationClock;
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
            // Downsample older records based on simulated time (or missing sim_time) by retention ratio.
            $sql = 'DELETE FROM :table WHERE (sim_time IS NULL OR sim_time < :cutoff) AND id % :ratio != 0';
            
            // Downsample Stocks
            $stockDeleted = $conn->executeStatement(
                str_replace(':table', 'stock_history', $sql),
                ['cutoff' => $cutoff, 'ratio' => $ratio]
            );
            $io->success("Cleared $stockDeleted redundant rows from stock_history.");

            // Downsample ETFs
            $etfDeleted = $conn->executeStatement(
                str_replace(':table', 'etf_history', $sql),
                ['cutoff' => $cutoff, 'ratio' => $ratio]
            );
            $io->success("Cleared $etfDeleted redundant rows from etf_history.");

            // Downsample Bonds
            $bondDeleted = $conn->executeStatement(
                str_replace(':table', 'bond_history', $sql),
                ['cutoff' => $cutoff, 'ratio' => $ratio]
            );
            $io->success("Cleared $bondDeleted redundant rows from bond_history.");

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
}