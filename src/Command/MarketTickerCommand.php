<?php

namespace App\Command;

use App\Service\StockTracker;
use App\Service\EtfTracker;
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
        private EtfTracker $etfTracker
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

        $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        $redis = new \Redis();
        $redis->connect($redisUrl['host'], $redisUrl['port'] ?? 6379);

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $tickCount = 0;

        $conn = $this->entityManager->getConnection();
        $snapshotSql = "
            INSERT INTO portfolio_history (user_id, total_value, recorded_at)
            SELECT u.id, (u.cash_balance + COALESCE(SUM(us.quantity * s.price), 0)), :now
            FROM users u
            LEFT JOIN user_stocks us ON u.id = us.user_id
            LEFT JOIN stocks s ON us.stock_id = s.id
            GROUP BY u.id
        ";

        while ($this->keepRunning) {
            
            pcntl_signal_dispatch();

            if ($tickCount % 10 === 0) {
                $simDay = ($tickCount / self::TICKS_PER_YEAR) * 365;
                $output->writeln("Updating Market Prices... (Day: " . number_format($simDay, 1) . ") [Tick: $tickCount]");
            }

            try {
                $this->entityManager->beginTransaction();

                $result = $this->stockTracker->updateStocks($dt);
                $stockUpdates = $result['updates'];
                $totalMarketCap = $result['total_cap'];
                $events = $result['events'] ?? [];

                $etfUpdate = $this->etfTracker->updateIndex($totalMarketCap);
                
                $allUpdates = array_merge($stockUpdates, [$etfUpdate]);

                $this->entityManager->flush();

                if (!empty($allUpdates)) {
                    $redis->publish('market_updates', json_encode([
                        'timestamp' => time(),
                        'stocks' => $allUpdates,
                        'events' => $events
                    ]));

                    $redis->set('stocks_live_data', json_encode($stockUpdates));
                    $redis->set('etf_live_data', json_encode([$etfUpdate]));

                    if (!empty($events)) {
                        foreach ($events as $event) {
                            $redis->lPush('market_events_list', json_encode($event));
                        }
                        $redis->lTrim('market_events_list', 0, 49);
                    }
                }

                if ($tickCount % 10 === 0) {
                    $conn->executeStatement($snapshotSql, [
                        'now' => (new \DateTime())->format('Y-m-d H:i:s')
                    ]);
                }

                $this->entityManager->commit();

            } catch (\Exception $e) {
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
                $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
                sleep(5); 
            } finally {
                $this->entityManager->clear();
            }

            $tickCount++;

            // Wait 0.10
            usleep(self::TICK_INTERVAL_US);
        }

        $output->writeln("<comment> Market Ticker shut down.</comment>");
        return Command::SUCCESS;
    }
}