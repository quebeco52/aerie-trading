<?php

namespace App\Command;

use App\Data\EconomicCycle;
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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private MessageBusInterface $messageBus,

        private int $tickIntervalUs,
        private int $ticksPerYear,
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

        // Fetch the stocks ONCE into RAM before the loop starts!
        $stocks = $this->entityManager->getRepository(Stock::class)->findAll();

        $dt = 1.0 / $this->ticksPerYear;
        $tickCount = 0;


        $historyInterval = (int) max(1, $this->ticksPerYear / 2400); // 2400 points per year
        $operatorInterval = (int) max(1, $this->ticksPerYear / 24);  // Operator audits once a game "month"
        $snapshotInterval = (int) max(1, $this->ticksPerYear / 52);  // Snapshots once a game "week"
        $garchInterval = (int) max(1, $this->ticksPerYear / 52);
        $historyPointsPerYear = (int) ($this->ticksPerYear / $historyInterval);

        $conn = $this->entityManager->getConnection();

        while ($this->keepRunning) {

            $tickStartTime = microtime(true);

            pcntl_signal_dispatch();

            if ($tickCount % 10 === 0) {
                $simDay = ($tickCount / $this->ticksPerYear) * 365;
                $output->writeln("Updating Market Prices... (Day: " . number_format($simDay, 1) . ") [Tick: $tickCount]");
            }

            if ($tickCount % $operatorInterval === 0) {
                $operatorEvents = $this->marketOperator->enforceMarketStability($stocks);
            } else {
                $operatorEvents = [];
            }

            try {
                $this->entityManager->beginTransaction();


                // Update the labels and the Sector P/E math
                $economicCycle = $this->macroEngine->updateBoomBust($dt);
                $liveSectorPEs = $this->macroEngine->updateSectorMultiples($dt, $economicCycle);

                // Check if it's time to record a database snapshot
                $isHistoryTick = ($tickCount % $historyInterval === 0);

                // Update the Stocks
                $result = $this->stockTracker->updateStocks($stocks, $dt, $liveSectorPEs, $isHistoryTick, $economicCycle, $tickCount, $this->ticksPerYear);
                $stockUpdates = $result['updates'];
                $totalMarketCap = $result['total_cap'];
                $events = $result['events'];
                $marketVol = $result['market_vol'];

                if (!empty($operatorEvents)) {
                    $events = array_merge($events, $operatorEvents);
                }

                $etfUpdate = $this->etfTracker->updateIndex($totalMarketCap, $isHistoryTick);

                $allUpdates = array_merge($stockUpdates, [$etfUpdate]);


                if ($isHistoryTick) {

                    $this->entityManager->flush();

                    $historyData = $result['history'];
                    if (!empty($historyData)) {
                        $sql = "INSERT INTO stock_history (stock_id, price, recorded_at) VALUES ";
                        $insertValues = [];
                        $params = [];
                        $now = (new \DateTime())->format('Y-m-d H:i:s');

                        foreach ($historyData as $row) {
                            $insertValues[] = "(?, ?, ?)";
                            $params[] = $row['stock_id'];
                            $params[] = $row['price'];
                            $params[] = $now;
                        }

                        $sql .= implode(', ', $insertValues);

                        $conn->executeStatement($sql, $params);
                    }

                    $this->entityManager->clear();
                    $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
                }

                if ($tickCount % $garchInterval === 0) {
                    foreach ($stocks as $stock) {
                        // Dispatch asynchronously
                        $this->messageBus->dispatch(
                        new \App\Message\UpdateStockVolatility($stock->getId(), $historyPointsPerYear)
                        );
                    }
                    $output->writeln("<info>Dispatched GARCH(1,1) volatility recalibration jobs.</info>");
                }

                $nowStr = (new \DateTime())->format('Y-m-d H:i:s');
                $redisBufferSize = (int) ceil($this->ticksPerYear / 12);

                // Open the pipeline
                $pipeline = $this->redis->multi(\Redis::PIPELINE);

                foreach ($allUpdates as $update) {
                    $cacheKey = "chart_buffer:{$update['ticker']}";
                    $point = json_encode(['price' => $update['price'], 'recorded_at' => $nowStr]);

                    // Queue the commands in the pipeline instead of executing them immediately
                    $pipeline->lPush($cacheKey, $point);
                    $pipeline->lTrim($cacheKey, 0,  $redisBufferSize - 1);
                }

                // Execute all queued commands in one massive, instantaneous burst
                $pipeline->exec();

                // Publish your standard pub/sub updates
                $this->redis->publish('market_updates', json_encode([
                    'timestamp' => time(),
                    'stocks' => $allUpdates,
                    'events' => $events,
                    'sectors' => $liveSectorPEs,
                    'market_vol' => $marketVol,
                    'economic_cycle' => $economicCycle->value,
                ]));

                $this->redis->set('stocks_live_data', json_encode($stockUpdates));
                $this->redis->set('etf_live_data', json_encode([$etfUpdate]));

                // Save Portfolio Snapshots once a "Simulation Week"
                if ($tickCount % $snapshotInterval === 0) {
                    $this->portfolio->recordBulkSnapshots();
                }

                $this->entityManager->commit();
            } catch (\Exception $e) {
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
                $output->writeln("<error>Error: " . $e->getMessage() . "</error>");

                // If the EntityManager has closed entirely, exit to let Supervisor restart the daemon
                if (!$this->entityManager->isOpen()) {
                    $output->writeln("<error>EntityManager is closed. Exiting Ticker to reboot...</error>");
                    return Command::FAILURE;
                }

                // Clear detached entities and reload fresh ones so the next tick has a valid state
                $this->entityManager->clear();
                $stocks = $this->entityManager->getRepository(Stock::class)->findAll();

                sleep(5);
            }

            $tickCount++;

            // Stop the stopwatch and calculate how long the work took
            $executionTimeSec = microtime(true) - $tickStartTime;
            $executionTimeUs = (int) ($executionTimeSec * 1000000);

            // LAG WARNING
            if ($executionTimeUs > $this->tickIntervalUs) {
                $overtimeMs = ($executionTimeUs - $this->tickIntervalUs) / 1000;
                $output->writeln("<comment>⚠️ Lag Spike: Tick {$tickCount} took too long! Dropped behind by " . round($overtimeMs, 2) . "ms</comment>");
            }

            $timeToSleepUs = $this->tickIntervalUs - $executionTimeUs;

            if ($timeToSleepUs > 0) {
                usleep($timeToSleepUs);
            }
        }

        $output->writeln("<comment> Market Ticker shut down.</comment>");
        return Command::SUCCESS;
    }
}
