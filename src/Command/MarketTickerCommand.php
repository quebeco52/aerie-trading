<?php

namespace App\Command;

use App\Entity\Stock;
use App\Service\Market\StockTracker;
use App\Service\Market\EtfTracker;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketOperator;
use App\Service\User\Portfolio;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Entity\Etf;
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
        private NarrativeEngine $narrativeEngine,
        private MarketEventPublisher $marketEvent,
        private \Symfony\Component\Messenger\MessageBusInterface $messageBus,

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
        $lbiEtf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);

        $dt = 1.0 / $this->ticksPerYear;
        $tickCount = (int) ($this->redis->get('simulation_tick_count') ?: 0);

        $historyInterval = (int) max(1, $this->ticksPerYear / 2400); // 2400 points per year
        $operatorInterval = (int) max(1, $this->ticksPerYear / 24);  // Operator audits once a game "month"
        $snapshotInterval = (int) max(1, $this->ticksPerYear / 52);  // Snapshots once a game "week"
        $quarterlyInterval = (int) max(1, $this->ticksPerYear / 4);   // Snapshots once a game "quarter"

        $conn = $this->entityManager->getConnection();

        while ($this->keepRunning) {

            $tickStartTime = microtime(true);

            pcntl_signal_dispatch();

            if ($tickCount % 10 === 0) {
                $simDay = ($tickCount / $this->ticksPerYear) * 365;
                $output->writeln("Updating Market Prices... (Day: " . number_format($simDay, 1) . ") [Tick: $tickCount]");
            }

            $macroState = $this->macroEngine->updateMacroState($dt);

            if ($tickCount % $operatorInterval === 0) {
                $operatorEvents = $this->marketOperator->enforceMarketStability($stocks, $macroState);
            } else {
                $operatorEvents = [];
            }

            try {
                $this->entityManager->beginTransaction();

                $isHistoryTick = ($tickCount % $historyInterval === 0);

                $result = $this->stockTracker->updateStocks($stocks, $dt, $isHistoryTick, $macroState, $tickCount, $this->ticksPerYear);
                $stockUpdates = $result['updates'];
                $totalMarketCap = $result['total_cap'];
                $marketVol = $result['market_vol'];
                $events = $result['events'];

                if ($macroState->eventType !== null) {
                    if ($lbiEtf) {
                        $desc = $this->narrativeEngine->generateLore($macroState->eventType);
                        $shockPct = in_array($macroState->eventType, [\App\Service\Event\ShockEvent::TITAN_INTERVENTION, \App\Service\Event\ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT]) ? 5.0 : -5.0;
                        $events[] = $this->marketEvent->publish($lbiEtf, 'SHOCK', $desc, $shockPct);
                    }
                }

                if (!empty($operatorEvents)) {
                    $events = array_merge($events, $operatorEvents);
                }

                $etfUpdate = $this->etfTracker->updateIndex($totalMarketCap, $isHistoryTick, 'LBI', $lbiEtf);

                $allUpdates = array_merge($stockUpdates, [$etfUpdate]);

                // Limit Order Check
                foreach ($allUpdates as $update) {
                    $ticker = $update['ticker'];
                    $price = $update['price'];

                    $boundsJson = $this->redis->get("limit_bounds:$ticker");
                    if ($boundsJson) {
                        $bounds = json_decode($boundsJson, true);
                        if ($price <= ($bounds['buy'] ?? 0.0) || $price >= ($bounds['sell'] ?? 999999999.0)) {
                            $this->messageBus->dispatch(new \App\Message\ProcessLimitOrdersMessage($ticker, $price));
                        }
                    }
                }

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
                    $lbiEtf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);
                }

                $nowStr = (new \DateTime())->format('Y-m-d H:i:s');
                $redisBufferSize = (int) ceil($this->ticksPerYear / 12);

                $pipeline = $this->redis->multi(\Redis::PIPELINE);

                foreach ($allUpdates as $update) {
                    $cacheKey = "chart_buffer:{$update['ticker']}";
                    $point = json_encode(['price' => $update['price'], 'recorded_at' => $nowStr]);

                    $pipeline->lPush($cacheKey, $point);
                    $pipeline->lTrim($cacheKey, 0,  $redisBufferSize - 1);
                }

                $pipeline->exec();

                // Publish pub/sub updates
                $this->redis->publish('market_updates', json_encode([
                    'timestamp' => time(),
                    'stocks' => $allUpdates,
                    'events' => $events,
                    'market_vol' => $marketVol,
                    'economic_cycle' => $macroState->outputGap > 0.01 ? 'Boom' : ($macroState->outputGap < -0.01 ? 'Bust' : 'Neutral'),
                    'council_rate' => $macroState->policyRate,
                    'macro' => $macroState->toArray(),
                ]));

                $this->redis->set('stocks_live_data', json_encode($stockUpdates));
                $this->redis->set('etf_live_data', json_encode([$etfUpdate]));
                $this->redis->set(\App\Service\Macro\MacroEngine::REDIS_MACRO_STATE, json_encode($macroState->toArray()));
                $this->redis->set('simulation_tick_count', $tickCount);

                // Save Portfolio Snapshots once a "Simulation Week"
                if ($tickCount % $snapshotInterval === 0) {
                    // Force a flush to the DB if we haven't already, so the raw SQL query 
                    // used by recordBulkSnapshots calculates against the latest live prices.
                    if (!$isHistoryTick) {
                        $this->entityManager->flush();
                    }
                    $this->portfolio->recordBulkSnapshots();
                }

                // Save Macro Report Snapshot once a "Simulation Quarter"
                if ($tickCount % $quarterlyInterval === 0) {
                    $this->macroEngine->recordMacroSnapshot($macroState, $conn);
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
                $lbiEtf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);

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
