<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Market\Ticker\HistoryPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies the retention policy by hand.
 *
 * The rules and the SQL live in HistoryPruner, because the ticker triggers the same pass on a simulated
 * cadence — the only clock the cutoffs are measured against. This command remains the way to run a backlog
 * off, and the nightly schedule remains as a backstop for an installation whose ticker is not running.
 */
#[AsCommand(
    name: 'app:prune-history',
    description: 'Downsamples old market history to save disk space while preserving long-term charts.',
)]
class PruneHistoryCommand extends Command
{
    public function __construct(private HistoryPruner $pruner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Required values: a bare --years read as zero years kept, and thinned every series.
        $this->addOption('years', 'y', InputOption::VALUE_REQUIRED, 'Simulated years of full-resolution history to keep?', (string) HistoryPruner::DEFAULT_YEARS_KEPT);
        $this->addOption('per-year', 'p', InputOption::VALUE_REQUIRED, 'Rows each series keeps per simulated year beyond that?', (string) HistoryPruner::THINNED_ROWS_PER_YEAR);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $years = $input->getOption('years');
        $rowsPerYear = $input->getOption('per-year');

        if (!is_numeric($years) || (float) $years <= 0.0 || !is_numeric($rowsPerYear) || (int) $rowsPerYear < 1) {
            $io->error('--years must be a positive number of years and --per-year at least 1. Nothing was pruned.');

            return Command::INVALID;
        }
        $years = (float) $years;
        $rowsPerYear = (int) $rowsPerYear;

        $io->title("Downsampling Market History (Older than $years simulated years | Keeping $rowsPerYear a simulated year per series)");

        try {
            $report = $this->pruner->prune($years, $rowsPerYear);
        } catch (\Exception $e) {
            $io->error("An error occurred: " . $e->getMessage());

            return Command::FAILURE;
        }

        $io->text(sprintf(
            'Simulation stands at year %.4f; downsampling everything before year %.4f.',
            $report['now'],
            $report['cutoff'],
        ));

        foreach ($report['tables'] as $table => $deleted) {
            $io->success("Cleared $deleted redundant rows from $table.");
        }

        $io->success(sprintf(
            'Cleared %d settled option contracts (expired before year %.4f).',
            $report['options'],
            $report['optionCutoff'],
        ));

        $io->success($report['macro'] > 0
            ? "Cleared {$report['macro']} old rows from macro_report."
            : 'No old macro reports to clear.');

        $io->success('Downsampling complete! Long-term charts preserved, disk space recovered.');

        return Command::SUCCESS;
    }
}
