<?php

namespace App\Command;

use App\Entity\Stock;
use App\Service\StockTracker;
use App\Service\EtfTracker;
use App\Service\MacroEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

#[AsCommand(
    name: 'app:market-simulate',
    description: 'Fast-forwards the market simulation by a specified number of years.',
)]
/**
 * Command to fast-forward the market simulation.
 *
 * This command allows for generating years worth of market data in a matter of seconds/minutes
 * by bypassing the real-time delay loop found in the standard MarketTickerCommand.
 * It is useful for:
 * 1. Generating initial historical data for a new environment.
 * 2. Stress testing the long-term stability of the math models (MacroEngine, MarketEngine).
 * 3. Observing long-term sector rotation cycles.
 */
class MarketSimulateCommand extends Command
{
    private const TICKS_PER_YEAR = 365;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockTracker $stockTracker,
        private EtfTracker $etfTracker,
        private MacroEngine $macroEngine,
        private \Redis $redis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('years', InputArgument::REQUIRED, 'Number of years to simulate');
    }

    /**
     * Executes the simulation loop.
     *
     * @param InputInterface  $input  The input interface containing the 'years' argument.
     * @param OutputInterface $output The output interface for writing progress bars and messages.
     *
     * @return int Command::SUCCESS on completion.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1G');
        $years = (float) $input->getArgument('years');
        $totalTicks = (int) ($years * self::TICKS_PER_YEAR);
        $dt = 1.0 / self::TICKS_PER_YEAR;

        $output->writeln("<info>Initializing Aerie God Engine...</info>");
        $output->writeln("Target: <comment>{$years} Years</comment> ({$totalTicks} ticks)");

        // Initialize Symfony ProgressBar
        $progressBar = new ProgressBar($output, $totalTicks);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% | %memory:6s%');
        $progressBar->start();



        // Load the stocks into RAM initially
        $stocks = $this->entityManager->getRepository(Stock::class)->findAll();

        for ($tick = 1; $tick <= $totalTicks; $tick++) {
            
            $liveSectorPEs = $this->macroEngine->updateSectorMultiples($dt);

            $isHistoryTick = ($tick % 30 === 0);
            
            // Pass the $stocks array in
            $result = $this->stockTracker->updateStocks($stocks, $dt, $liveSectorPEs, $isHistoryTick); 
            $this->etfTracker->updateIndex($result['total_cap']);

            // Batch flush every 1200 ticks to save RAM
            if ($tick % 365 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear(); // Wipes RAM 
                
                $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
                
                $this->redis->set('stocks_live_data', json_encode($result['updates']));
            }

            $progressBar->advance();
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $progressBar->finish();
        $output->writeln("");
        $output->writeln("<info>Simulation complete! The timeline has been altered.</info>");

        return Command::SUCCESS;
    }
}