<?php

namespace App\Command;

use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Market\BondTracker;
use App\Service\Market\StockTracker;
use App\Service\Market\TreasuryAuctionService;
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
    // --- History Sampling ---
    /** Target price history rows written per simulated year; the actual rate is this or one row per tick, whichever is coarser. */
    public const TARGET_HISTORY_POINTS_PER_YEAR = 2400;

    /** Ticks between price history rows: one per tick until the tick rate outruns the target sampling rate. */
    public static function historyIntervalTicks(int $ticksPerYear): int
    {
        return max(1, (int) ($ticksPerYear / self::TARGET_HISTORY_POINTS_PER_YEAR));
    }

    /**
     * Price history rows actually written per simulated year at a given tick rate.
     *
     * Anything converting a chart range into a row LIMIT has to ask this rather than assume a tick rate:
     * the range buttons used to carry hardcoded row counts that only matched a 4,800-tick year, so every
     * span on the stock page was wrong by whatever ratio the configured rate differed by.
     */
    public static function historyPointsPerYear(int $ticksPerYear): int
    {
        return max(1, (int) ($ticksPerYear / self::historyIntervalTicks($ticksPerYear)));
    }

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
        private BondTracker $bondTracker,
        private TreasuryAuctionService $treasuryAuction,
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
        $bonds = $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]);

        $dt = 1.0 / $this->ticksPerYear;
        $tickCount = (int) ($this->redis->get('simulation_tick_count') ?: 0);

        $historyInterval = self::historyIntervalTicks($this->ticksPerYear);
        $operatorInterval = (int) max(1, $this->ticksPerYear / 24);  // Operator audits once a game "month"
        $snapshotInterval = (int) max(1, $this->ticksPerYear / 52);  // Snapshots once a game "week"
        $quarterlyInterval = (int) max(1, $this->ticksPerYear / 4);   // Snapshots once a game "quarter"
        $auctionInterval = TreasuryAuctionService::auctionIntervalTicks($this->ticksPerYear);

        $conn = $this->entityManager->getConnection();

        /**
         * Open, high, low and volume accumulating between history writes, keyed by ticker.
         *
         * A history tick is one bar and many ticks fall inside it, so the extremes have to be carried
         * rather than sampled: the close alone cannot show that a name traded eight percent lower at some
         * point in the bar and recovered. Plain arrays, deliberately — this has to survive the
         * EntityManager clear that happens on every history tick.
         *
         * @var array<string, array{open: float, high: float, low: float, volume: float}>
         */
        $bars = [];

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
                        $macroContext = [
                            'interbank_spread_bps' => number_format($macroState->interbankLiquiditySpread * 10000.0, 0),
                            'hy_spread_pct' => number_format($macroState->highYieldCreditSpread * 100.0, 2),
                            'recession_prob_pct' => number_format($macroState->recessionProbability * 100.0, 1),
                            'output_gap_pct' => number_format($macroState->outputGap * 100.0, 2),
                            'inversion_months' => number_format($macroState->inversionDuration * 12.0, 1),
                            'erp_pct' => number_format($macroState->equityRiskPremium * 100.0, 2),
                            'qe_intensity_pct' => number_format($macroState->qeIntensity * 100.0, 2),
                        ];
                        $desc = $this->narrativeEngine->generateLore($macroState->eventType, $macroContext);
                        $shockPct = in_array($macroState->eventType, [\App\Service\Event\ShockEvent::TITAN_INTERVENTION, \App\Service\Event\ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT]) ? 5.0 : -5.0;
                        $events[] = $this->marketEvent->publish($lbiEtf, 'SHOCK', $desc, $shockPct);
                    }
                }

                if (!empty($operatorEvents)) {
                    $events = array_merge($events, $operatorEvents);
                }

                $etfUpdate = $this->etfTracker->updateIndex($totalMarketCap, $isHistoryTick, 'LBI', $lbiEtf);

                // The bond desk. Coupons, redemptions and the mark all happen inside the same tick
                // transaction as the equity book, so a crash mid-tick cannot leave a coupon credited
                // against a mark that was rolled back.
                $bondResult = $this->bondTracker->updateBonds($bonds, $macroState, $isHistoryTick);

                // A matured issue stops trading, so drop it from the working set immediately rather than
                // waiting for the next reload: it would otherwise be re-marked and re-redeemed every tick
                // until the next history tick refreshed the list.
                if ($bondResult['matured'] !== []) {
                    $maturedIds = array_map(static fn (Bond $b): ?int => $b->getId(), $bondResult['matured']);
                    $bonds = array_values(array_filter(
                        $bonds,
                        static fn (Bond $b): bool => !in_array($b->getId(), $maturedIds, true)
                    ));
                }

                // Quarterly refunding: a fresh on-the-run at every tenor, so a benchmark maturity is always
                // available to trade rather than ageing out of existence.
                if ($tickCount % $auctionInterval === 0) {
                    $newIssues = $this->treasuryAuction->conductAuction(
                        $macroState->sovereignCurve(),
                        $macroState->totalTime,
                        $bonds
                    );

                    foreach ($newIssues as $newIssue) {
                        $bonds[] = $newIssue;
                    }
                }

                $allUpdates = array_merge($stockUpdates, [$etfUpdate], $bondResult['updates']);

                foreach ($stockUpdates as $update) {
                    if (!empty($update['is_bankrupt'])) {
                        continue;
                    }

                    $ticker = $update['ticker'];
                    $price = (float) $update['price'];

                    if (!isset($bars[$ticker])) {
                        $bars[$ticker] = ['open' => $price, 'high' => $price, 'low' => $price, 'volume' => 0.0];
                    }

                    $bars[$ticker]['high'] = max($bars[$ticker]['high'], $price);
                    $bars[$ticker]['low'] = min($bars[$ticker]['low'], $price);
                    $bars[$ticker]['volume'] += (float) ($update['volume'] ?? 0.0);
                }

                // Limit Order Check
                foreach ($allUpdates as $update) {
                    if (!empty($update['is_bankrupt'])) {
                        continue;
                    }

                    $ticker = $update['ticker'];
                    $price = $update['price'];

                    $boundsJson = $this->redis->get("limit_bounds:$ticker");

                    if (!$boundsJson) {
                        // No cached book for this ticker. That is either genuinely no orders or a Redis that
                        // has been flushed since they were placed, and the two are indistinguishable from
                        // here — so check once. The handler rewrites the bounds either way, which means the
                        // uncertainty costs exactly one dispatch rather than stranding resting orders.
                        $this->messageBus->dispatch(new \App\Message\ProcessLimitOrdersMessage($ticker, $price));
                        continue;
                    }

                    $bounds = json_decode($boundsJson, true);
                    if ($price <= ($bounds['buy'] ?? 0.0) || $price >= ($bounds['sell'] ?? 999999999.0)) {
                        $this->messageBus->dispatch(new \App\Message\ProcessLimitOrdersMessage($ticker, $price));
                    }
                }

                if ($isHistoryTick) {

                    $this->entityManager->flush();

                    $historyData = $result['history'];
                    if (!empty($historyData)) {
                        $sql = "INSERT INTO stock_history (stock_id, price, open_price, high_price, low_price, volume, recorded_at) VALUES ";
                        $insertValues = [];
                        $params = [];
                        $now = (new \DateTime())->format('Y-m-d H:i:s');

                        foreach ($historyData as $row) {
                            // The bar the closing price belongs to. Absent only for a name that appeared
                            // mid-bar, in which case a one-tick bar is the honest answer rather than a
                            // fabricated range.
                            $bar = $bars[$row['ticker']] ?? [
                                'open' => $row['price'],
                                'high' => $row['price'],
                                'low' => $row['price'],
                                'volume' => 0.0,
                            ];

                            $insertValues[] = "(?, ?, ?, ?, ?, ?, ?)";
                            $params[] = $row['stock_id'];
                            $params[] = $row['price'];
                            $params[] = $bar['open'];
                            $params[] = max($bar['high'], (float) $row['price']);
                            $params[] = min($bar['low'], (float) $row['price']);
                            $params[] = (int) round($bar['volume']);
                            $params[] = $now;
                        }

                        $sql .= implode(', ', $insertValues);

                        $conn->executeStatement($sql, $params);
                    }

                    // The bar is written; the next one opens at the next tick's price.
                    $bars = [];

                    $this->bondTracker->recordHistory($bondResult['history']);

                    $this->entityManager->clear();
                    $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
                    $lbiEtf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);
                    $bonds = $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]);
                }

                $nowStr = (new \DateTime())->format('Y-m-d H:i:s');
                $redisBufferSize = (int) ceil($this->ticksPerYear / 12);

                $pipeline = $this->redis->multi(\Redis::PIPELINE);

                foreach ($allUpdates as $update) {
                    if (!empty($update['is_bankrupt'])) {
                        continue; // Keep chart buffer frozen in place
                    }

                    $cacheKey = "chart_buffer:{$update['ticker']}";

                    // Bonds buffer the CLEAN price, because that is what bond_history stores. Buffering the
                    // dirty price instead would splice an accrual sawtooth onto a flat historical series at
                    // the join between the two, and the chart would show a jump the instrument never made.
                    $chartPrice = $update['clean_price'] ?? $update['price'];
                    $point = json_encode(['price' => $chartPrice, 'recorded_at' => $nowStr]);

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
                    'economic_cycle' => $macroState->economicCycleLabel(),
                    'council_rate' => $macroState->policyRate,
                    'macro' => $macroState->toArray(),
                    'bond_curve' => $bondResult['curve'],
                ]));

                $this->redis->set('stocks_live_data', json_encode($stockUpdates));
                $this->redis->set('etf_live_data', json_encode([$etfUpdate]));
                $this->redis->set('bond_live_data', json_encode($bondResult['updates']));
                $this->redis->set('simulation_tick_count', $tickCount);

                // Save Portfolio Snapshots once a "Simulation Week"
                if ($tickCount % $snapshotInterval === 0) {
                    // Force a flush to the DB if we haven't already, so the raw SQL query
                    // used by recordBulkSnapshots calculates against the latest live prices.
                    if (!$isHistoryTick) {
                        $this->entityManager->flush();
                    }

                    // Idle brokerage cash is swept overnight and earns the policy rate net of the
                    // intermediary's spread. Accrued before the snapshot so the recorded net asset
                    // value includes the interest credited for the week just elapsed.
                    $this->portfolio->accrueCashInterest(
                        max(0.0, $macroState->policyRateEma - MacroEngine::CASH_YIELD_SPREAD),
                        $snapshotInterval * $dt
                    );

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
                $bonds = $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]);

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
