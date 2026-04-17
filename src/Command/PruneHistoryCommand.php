<?php

namespace App\Command;

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
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Older than how many days should we downsample?', 30);
        $this->addOption('ratio', 'r', InputOption::VALUE_OPTIONAL, 'Keep 1 out of every X records?', 1000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        $ratio = (int) $input->getOption('ratio');

        $io->title("Downsampling Market History (Older than $days days | Keeping 1 in $ratio)");

        $cutoff = new \DateTimeImmutable("-{$days} days");
        $cutoffString = $cutoff->format('Y-m-d H:i:s');

        $io->text("Processing records older than: " . $cutoffString);

        // raw DBAL connection
        $conn = $this->em->getConnection();

        try {
            $sql = 'DELETE FROM :table WHERE recorded_at < :cutoff AND id % :ratio != 0';
            
            // Downsample Stocks
            $stockDeleted = $conn->executeStatement(
                str_replace(':table', 'stock_history', $sql),
                ['cutoff' => $cutoffString, 'ratio' => $ratio]
            );
            $io->success("Cleared $stockDeleted redundant rows from stock_history.");

            // Downsample ETFs
            $etfDeleted = $conn->executeStatement(
                str_replace(':table', 'etf_history', $sql),
                ['cutoff' => $cutoffString, 'ratio' => $ratio]
            );
            $io->success("Cleared $etfDeleted redundant rows from etf_history.");

        } catch (\Exception $e) {
            $io->error("An error occurred: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Downsampling complete! Long-term charts preserved, disk space recovered.');

        return Command::SUCCESS;
    }
}