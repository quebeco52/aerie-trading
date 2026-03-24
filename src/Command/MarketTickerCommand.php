<?php

namespace App\Command;

use App\Entity\Stock;
use App\Service\StockTracker;
use App\Service\EtfTracker;
use App\Service\MacroEngine;
use App\Service\MathUtility;
use App\Service\MarketOperator;
use App\Service\Portfolio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:market-ticker',
    description: 'Runs the continuous market simulation loop and broadcasts prices.',
)]
/**
 * Command to run the continuous market simulation.
 *
 * This command executes an infinite loop that updates stock prices, calculates ETF values,
 * generates market events, and broadcasts updates via Redis. It also periodically
 * takes snapshots of user portfolio values.
 */
class MarketTickerCommand extends Command implements SignalableCommandInterface
{
    private const TICK_INTERVAL_US = 100000; // 0.10 seconds per tick
    private const TICKS_PER_YEAR = 14400;    // 1 Simulation Year

    private bool $keepRunning = true;

    /**
     * @param EntityManagerInterface $entityManager Doctrine Entity Manager for database transactions.
     * @param StockTracker           $stockTracker  Service to update individual stock prices.
     * @param EtfTracker             $etfTracker    Service to update ETF prices based on market cap.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockTracker $stockTracker,
        private EtfTracker $etfTracker,
        private MacroEngine $macroEngine,
        private MarketOperator $marketOperator,
        private Portfolio $portfolio,
        private \Redis $redis,
    ) {
        parent::__construct();
    }

    /**
     * Returns the list of signals to subscribe to.
     *
     * @return array<int> The signals to listen for (SIGINT, SIGTERM).
     */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }


    /**
     * Handles a signal to gracefully stop the command.
     *
     * @param int       $signal           The signal number.
     * @param int|false $previousExitCode The previous exit code, if any.
     *
     * @return int|false False to continue execution (allow the loop to finish current iteration), or an exit code.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->keepRunning = false;
        return false; // Return false so doesn't forcefully exit immediately
    }

    /**
     * Executes the market simulation loop.
     *
     * @return int Command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>Market Ticker Started...</info>");

        // 1. Fetch the stocks ONCE into RAM before the loop starts!
        $stocks = $this->entityManager->getRepository(Stock::class)->findAll();

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $tickCount = 0;

        $conn = $this->entityManager->getConnection();

        while ($this->keepRunning) {

            $tickStartTime = microtime(true);

            pcntl_signal_dispatch();

            if ($tickCount % 10 === 0) {
                $simDay = ($tickCount / self::TICKS_PER_YEAR) * 365;
                $output->writeln("Updating Market Prices... (Day: " . number_format($simDay, 1) . ") [Tick: $tickCount]");
            }

            if ($tickCount % 1200 === 0) {
                $operatorEvents = $this->marketOperator->enforceMarketStability($stocks);
            } else {
                $operatorEvents = [];
            }

            try {
                $this->entityManager->beginTransaction();

                // 1. Update the Macro Economy (Sector P/Es drift)
                $liveSectorPEs = $this->macroEngine->updateSectorMultiples($dt);

                // Check if it's time to record a database snapshot
                $isHistoryTick = ($tickCount % 3 === 0);

                // 2. Update the Stocks
                $result = $this->stockTracker->updateStocks($stocks, $dt, $liveSectorPEs, $isHistoryTick);
                $stockUpdates = $result['updates'];
                $totalMarketCap = $result['total_cap'];
                $events = $result['events'] ?? [];
                
                if (!empty($operatorEvents)) {
                    $events = array_merge($events, $operatorEvents);
                }

                $etfUpdate = $this->etfTracker->updateIndex($totalMarketCap, $isHistoryTick);

                $allUpdates = array_merge($stockUpdates, [$etfUpdate]);

                $this->entityManager->flush();

                // Clear memory to prevent leaks
                if ($isHistoryTick) {
                    $this->entityManager->clear();
                    $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
                }

                if (!empty($allUpdates)) {

                    $nowStr = (new \DateTime())->format('Y-m-d H:i:s');

                    foreach ($allUpdates as $update) {
                        $cacheKey = "chart_buffer:{$update['ticker']}";
                        $point = json_encode(['price' => $update['price'], 'recorded_at' => $nowStr]);
                        $this->redis->lPush($cacheKey, $point);
                        $this->redis->lTrim($cacheKey, 0, 1199);
                    }

                    $this->redis->publish('market_updates', json_encode([
                        'timestamp' => time(),
                        'stocks' => $allUpdates,
                        'events' => $events,
                        'sectors' => $liveSectorPEs
                    ]));

                    $this->redis->set('stocks_live_data', json_encode($stockUpdates));
                    $this->redis->set('etf_live_data', json_encode([$etfUpdate]));
                }

                // Save Portfolio Snapshots once a "Simulation Week"
                if ($tickCount % 277 === 0) {
                    $this->portfolio->recordBulkSnapshots();
                }

                $this->entityManager->commit();
            } catch (\Exception $e) {
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
                $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
                sleep(5);
            }

            $tickCount++;

            // Stop the stopwatch and calculate how long the work took
            $executionTimeSec = microtime(true) - $tickStartTime;
            $executionTimeUs = (int) ($executionTimeSec * 1000000);

            // LAG WARNING
            if ($executionTimeUs > self::TICK_INTERVAL_US) {
                // Calculate how many milliseconds over the 100ms limit
                $overtimeMs = ($executionTimeUs - self::TICK_INTERVAL_US) / 1000;
                $output->writeln("<comment>⚠️ Lag Spike: Tick {$tickCount} took too long! Dropped behind by " . round($overtimeMs, 2) . "ms</comment>");
            }

            // Subtract execution time from 100,000 microsecond target
            $timeToSleepUs = self::TICK_INTERVAL_US - $executionTimeUs;

            // Only sleep if finished faster than 0.10 seconds!
            if ($timeToSleepUs > 0) {
                usleep($timeToSleepUs);
            }
        }

        $output->writeln("<comment> Market Ticker shut down.</comment>");
        return Command::SUCCESS;
    }
}
