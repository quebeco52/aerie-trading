<?php

namespace App\Command;

use App\DTO\MacroStateDTO;
use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\EventListener\DeferredWriteAudit;
use App\EventListener\FlushProfiler;
use App\Service\Corporate\FailureSweep;
use App\Service\District\DistrictRoster;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\SystemicEventReporter;
use App\Service\Macro\MacroConfigFingerprint;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Recorder\QuarterRecord;
use App\Service\Market\Bond\BondTracker;
use App\Service\Market\Chart\WireFrame;
use App\Service\Market\Index\EtfTracker;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\Option\OptionChainService;
use App\Service\Market\Option\OptionDeskService;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Market\Ticker\BondDeskPhase;
use App\Service\Market\Ticker\ChartBufferWriter;
use App\Service\Market\Ticker\HistoryPruner;
use App\Service\Market\Ticker\IndexFundPhase;
use App\Service\Market\Ticker\RestingOrderWatch;
use App\Service\Market\Ticker\SimulationClockService;
use App\Service\Market\Ticker\StockBarBook;
use App\Service\Market\Ticker\StockTickColumns;
use App\Service\Market\Ticker\TickCadence;
use App\Service\Market\Ticker\TickProfiler;
use App\Service\Market\Trading\ForcedLiquidationService;
use App\Service\Notification\PlayerNotifier;
use App\Service\Notification\PriceAlertService;
use App\Service\Politics\ElectionRecorder;
use App\Service\Politics\PoliticsEngine;
use App\Service\Season\SeasonService;
use App\Service\User\Portfolio;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:market-ticker',
    description: 'Runs the continuous market simulation loop and broadcasts prices.',
)]
/**
 * The market's daemon: one tick advances the economy, the Diet, the board, the funds, the bond ladder and the
 * option chain, writes what changed inside one transaction, and puts a frame on the wire.
 *
 * The phases live in App\Service\Market\Ticker; this class holds their order, the transaction around them, the
 * cadences that gate them and the recovery when a tick is lost.
 */
class MarketTickerCommand extends Command implements SignalableCommandInterface
{
    // --- Diagnostics ---
    /** Simulated years the tick counter may imply away from the clock before the start-up reports it as a divergence. */
    public const CLOCK_DIVERGENCE_TOLERANCE_YEARS = 0.01;

    private bool $keepRunning = true;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockTracker $stockTracker,
        private EtfTracker $etfTracker,
        private BondTracker $bondTracker,
        private ForcedLiquidationService $liquidationService,
        private FlushProfiler $flushProfiler,
        /** Catches a mutation that DEFERRED_EXPLICIT tracking would drop, and writes it anyway. */
        private DeferredWriteAudit $writeAudit,
        private OutputGapProbe $gapProbe,
        private MacroDiagnosticsProbe $diagnosticsProbe,
        private MacroConfigFingerprint $configFingerprint,
        private SimulationClockService $simulationClock,
        private OptionChainService $optionChain,
        private MacroEngine $macroEngine,
        private FailureSweep $failureSweep,
        private Portfolio $portfolio,
        private \Redis $redis,
        private SystemicEventReporter $systemicEvents,
        private MarketEventPublisher $marketEvent,
        private DistrictRoster $districtRoster,
        private OptionDeskService $optionDesk,
        private MessageBusInterface $messageBus,
        /** Reconstitution, distributions, the fund strike and the creation basket. */
        private IndexFundPhase $indexFundPhase,
        /** The ladder's daily mark, maturities, corporate issuance and the treasury refunding. */
        private BondDeskPhase $bondDeskPhase,
        /** Which resting limit orders and price alerts this tick's quotes may have reached. */
        private RestingOrderWatch $restingOrders,
        /** The Diet and the government, advanced after the economy each tick. */
        private PoliticsEngine $politicsEngine,
        /** Writes the Diet's vote on the tick it is held. */
        private ElectionRecorder $electionRecorder,
        /** Account messages raised inside the tick, sent once it commits. */
        private PlayerNotifier $notifier,
        /** Price alerts: bounds read once a tick, fired on the worker. */
        private PriceAlertService $priceAlerts,
        /** The league: each account's week, and the season's turn. */
        private SeasonService $seasons,

        private int $tickIntervalUs,
        private int $ticksPerYear,
    ) {
        parent::__construct();
    }

    /**
     * The stocks, the fund behind each published index and the active bond ladder, held in RAM between ticks.
     * Reloaded after every EntityManager clear.
     *
     * @return array{0: array<int, Stock>, 1: array<string, Etf>, 2: array<int, Bond>}
     */
    private function loadWorkingSet(): array
    {
        $funds = [];
        $repository = $this->entityManager->getRepository(Etf::class);

        foreach (MarketIndex::cases() as $index) {
            $fund = $repository->findOneBy(['ticker' => $index->value]);
            if ($fund !== null) {
                $funds[$index->value] = $fund;
            }
        }

        return [
            $this->entityManager->getRepository(Stock::class)->findAll(),
            $funds,
            $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]),
        ];
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

    protected function configure(): void
    {
        $this->addOption(
            'audit-writes',
            null,
            InputOption::VALUE_NONE,
            'Report and repair any Stock or Bond change that was not handed to persist(). Costs the identity-map walk that explicit change tracking exists to avoid, so it is for a canary run rather than for steady state.'
        );

        $this->addOption(
            'rebase-clock',
            null,
            InputOption::VALUE_NONE,
            'Align the tick counter to the simulation clock once at start-up. Shifts the phase of every tick-keyed cadence — earnings seasons, auctions, reconstitutions — and ages out industry-ledger records stamped against the old numbering, so it is a deliberate repair rather than a default.'
        );
    }

    /**
     * Resumes the tick counter and the simulation clock from their committed state.
     *
     * @return array{0: int, 1: float} The tick counter and the simulation clock.
     */
    private function resumeClock(InputInterface $input, OutputInterface $output): array
    {
        // Resume from the database and the option chain as well as Redis, so a flushed cache cannot rewind time.
        $furthestSerial = $this->optionChain->furthestListedSerial();
        $clock = $this->simulationClock->resume(
            (int) ($this->redis->get('simulation_tick_count') ?: 0),
            $this->macroEngine->getLiveState()->totalTime,
            $furthestSerial === null ? 0.0 : OptionChainService::earliestTimeFor($furthestSerial),
            (bool) $input->getOption('rebase-clock')
        );

        $tickCount = $clock->getTickCount();

        // The economic clock, carried alongside the loop counter because the two are not interchangeable: this is
        // what the option grid, the bond ladder and every retention cutoff are measured against, and it is the
        // only one of the pair that can be converted into a date.
        $simTime = $clock->getTotalTime();

        // Synchronize cached Redis macro clock with authoritative simulation clock.
        $this->macroEngine->alignClock($simTime);

        $output->writeln(sprintf(
            'Resuming at tick %d (simulation year %.4f, day %s).',
            $tickCount,
            $simTime,
            number_format($simTime * 365.0, 1)
        ));

        // The counter and the clock advance at the same rate, so they can only differ by an offset, and an offset
        // means one was seeded from a cache the other did not come from. Each stays self-consistent: cadences
        // with the counter, dated facts with the clock. Converting one into the other is what breaks, so the
        // divergence is reported rather than silently repaired; --rebase-clock does that on request.
        $impliedTime = $tickCount / max(1, $this->ticksPerYear);

        if (abs($impliedTime - $simTime) > self::CLOCK_DIVERGENCE_TOLERANCE_YEARS) {
            $output->writeln(sprintf(
                '<error>⚠️ Clock divergence: tick %d implies simulation year %.4f, but the clock reads %.4f (a %.4f-year offset). Dated output follows the clock; cadences follow the counter. Run with --rebase-clock to align the counter.</error>',
                $tickCount,
                $impliedTime,
                $simTime,
                $impliedTime - $simTime
            ));
        }

        return [$tickCount, $simTime];
    }

    /**
     * Executes the market simulation loop.
     *
     * @return int Command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>Market Ticker Started...</info>");

        [$stocks, $indexFunds, $bonds] = $this->loadWorkingSet();

        $dt = 1.0 / $this->ticksPerYear;

        [$tickCount, $simTime] = $this->resumeClock($input, $output);

        $operatorInterval = (int) max(1, $this->ticksPerYear / 24);  // Operator audits once a game "month"
        $snapshotInterval = (int) max(1, $this->ticksPerYear / 52);  // Snapshots once a game "week"
        $quarterlyInterval = (int) max(1, $this->ticksPerYear / 4);   // Snapshots once a game "quarter"
        $optionSweepInterval = OptionDeskService::sweepIntervalTicks($this->ticksPerYear);

        // What the configured tick rate buys, said once rather than inferred from the overrun rate. A bar carries
        // the flush, the bond mark, the tick-column write and the reload, so the share of ticks that close one is
        // the share that cannot finish inside the budget; at or below the bar rate every tick closes one.
        $barsPerYear = TickCadence::historyPointsPerYear($this->ticksPerYear);
        $output->writeln(sprintf(
            'Sampling: %d bars/sim-year over %d ticks (%.0f%% of ticks close a bar), %.1f ms budget.',
            $barsPerYear,
            $this->ticksPerYear,
            100.0 * $barsPerYear / max(1, $this->ticksPerYear),
            $this->tickIntervalUs / 1000.0
        ));

        $conn = $this->entityManager->getConnection();

        // Alert bounds live in Redis as a cache of the table; rebuilt here so a flushed Redis loses no alert.
        $this->priceAlerts->rebuildAll();

        $bars = new StockBarBook();
        $chartBuffers = new ChartBufferWriter($this->redis);
        $profiler = new TickProfiler();
        $wireFrame = new WireFrame();

        // The tick is the only place a flush is timed, so it is the only place that wants the attribution.
        $this->flushProfiler->enable();

        if ((bool) $input->getOption('audit-writes')) {
            $this->writeAudit->enable();
            $output->writeln('<comment>Write audit on: explicit-tracking misses will be reported and repaired.</comment>');
        }

        // The gap is only ever moved here, so this is the only process that can say what moved it.
        $this->gapProbe->enable();
        $this->diagnosticsProbe->enable();

        // Lag equity market cap across ticks to avoid simultaneous feedback loops in macro step.
        $lastEquityMarketCap = null;
        // What the sovereign fund reads of the board, on the same one-tick lag as the capitalisation.
        $lastBoardFloatCap = null;
        $lastBoardPriceReturn = null;
        $lastBoardDividendCash = null;
        $lastBoardNetIssuance = null;
        $lastBoardStampDuty = null;
        $lastStrategicStakeCash = null;
        // The banks' levy bill is a standing amount, not a flow, so it is kept until the board is priced again.
        $lastBoardBankLevy = null;
        // What the government hands the economy, on the same lag: the levers in force and the election pulse.
        $policy = $this->politicsEngine->liveState()->policy();

        while ($this->keepRunning) {

            $tickStartTime = microtime(true);
            $profiler->startTick();
            $this->flushProfiler->reset();
            $this->writeAudit->reset();

            pcntl_signal_dispatch();

            if ($tickCount % 10 === 0) {
                // Dated off the simulation clock, never off the tick count: the counter is a loop position whose
                // absolute value only has to be consistent with itself.
                $output->writeln("Updating Market Prices... (Day: " . number_format($simTime * 365.0, 1) . ") [Tick: $tickCount]");
            }

            $macroState = $this->macroEngine->updateMacroState(
                $dt,
                $lastEquityMarketCap,
                $lastBoardFloatCap,
                $lastBoardPriceReturn,
                $lastBoardDividendCash,
                $lastBoardNetIssuance,
                $lastBoardStampDuty,
                $lastStrategicStakeCash,
                $policy,
                boardBankLevy: $lastBoardBankLevy,
            );
            // The Diet votes on the calendar's election tick, on this tick's economy; the economy reads what it decides next tick.
            $politics = $this->politicsEngine->updatePolitics($macroState, $dt);
            $policy = $politics->policy();
            // Flows are consumed once; a tick that prices no board must not replay the last one's return.
            $lastBoardPriceReturn = null;
            $lastBoardDividendCash = null;
            $lastBoardNetIssuance = null;
            $lastBoardStampDuty = null;
            $lastStrategicStakeCash = null;
            $simTime = $macroState->totalTime;
            $this->marketEvent->stampSimTime($simTime);
            $this->notifier->stampSimTime($simTime);
            $profiler->lap('macro');

            // Retention, on the clock its cutoffs are measured against: true exactly once per simulated-year
            // crossing whatever the tick rate. The pass is idempotent, so it needs no gate or persisted marker,
            // and the work goes to the worker; only the dispatch happens here.
            if (TickCadence::crossedSimulatedBoundary($simTime, $dt, HistoryPruner::PRUNE_INTERVAL_YEARS)) {
                $this->messageBus->dispatch(new \App\Message\PruneHistoryMessage(
                    HistoryPruner::DEFAULT_YEARS_KEPT,
                    HistoryPruner::THINNED_ROWS_PER_YEAR
                ));
            }

            $isFrameTick = WireFrame::isFrameTick($tickCount, $this->tickIntervalUs);

            $operatorEvents = $tickCount % $operatorInterval === 0
                ? $this->failureSweep->sweep($stocks, $macroState)
                : [];

            $isHistoryTick = TickCadence::isHistoryTick($tickCount, $this->ticksPerYear);

            // Re-hedge option inventory on history bar cadence before draining order flow.
            if ($isHistoryTick) {
                $this->optionDesk->hedge($stocks, 1.0 / TickCadence::historyPointsPerYear($this->ticksPerYear));
            }

            try {
                $this->entityManager->beginTransaction();

                $profiler->lap('operator+hedge');
                $result = $this->stockTracker->updateStocks($stocks, $dt, $isHistoryTick, $macroState, $tickCount, $this->ticksPerYear);
                $profiler->lap('stocks');
                $stockUpdates = $result['updates'];
                $totalMarketCap = $result['total_cap'];

                // Household equity wealth is what the board is worth, so it is read here, off the board. The index
                // funds' levels are quotes restated on a split; capitalisation is the quantity itself.
                $lastEquityMarketCap = $totalMarketCap > 0.0 ? $totalMarketCap : $lastEquityMarketCap;
                $lastBoardFloatCap = $result['board_float_cap'] > 0.0 ? $result['board_float_cap'] : $lastBoardFloatCap;
                $lastBoardPriceReturn = $result['board_price_return'];
                $lastBoardDividendCash = $result['board_dividend_cash'];
                $lastBoardNetIssuance = $result['board_net_issuance'];
                $lastBoardStampDuty = $result['board_stamp_duty'];
                $lastBoardBankLevy = $result['board_bank_levy'];
                $lastStrategicStakeCash = $result['strategic_stake_cash'];
                $marketVol = $result['market_vol'];
                $events = $result['events'];

                $benchmarkFund = $indexFunds[MarketIndex::benchmark()->value] ?? null;
                if ($benchmarkFund !== null && ($headline = $this->systemicEvents->report($macroState, $politics, $benchmarkFund)) !== null) {
                    $events[] = $headline;
                }
                $this->electionRecorder->record($politics);

                if (!empty($operatorEvents)) {
                    $events = array_merge($events, $operatorEvents);
                }

                $profiler->lap('events');

                $indexPhase = $this->indexFundPhase->run($indexFunds, $stocks, $result, $macroState, $tickCount, $this->ticksPerYear, $dt, $isHistoryTick);
                foreach ($indexPhase['log'] as $line) {
                    $output->writeln($line);
                }
                $events = array_merge($events, $indexPhase['events']);
                $etfUpdates = $indexPhase['updates'];
                $profiler->lap('etfs');

                $bondPhase = $this->bondDeskPhase->run($bonds, $stocks, $macroState, $tickCount, $this->ticksPerYear);
                $bonds = $bondPhase['bonds'];
                $bondResult = $bondPhase['result'];
                $profiler->lap('bonds');

                // Sweep option chain in staggered slices to settle expiries, list strikes, and update Greeks.
                if (OptionDeskService::isSweepTick($tickCount, $optionSweepInterval)) {
                    $this->sweepOptions($stocks, $macroState, $dt, $tickCount, $optionSweepInterval, $profiler, $output);
                }

                $quotes = array_merge($stockUpdates, $etfUpdates);

                // Publish changed asset quotes to websocket wire, omitting static bond quotes between history ticks.
                $published = $isHistoryTick
                    ? array_merge($quotes, $bondResult['updates'])
                    : $quotes;

                $bars->accumulate($stockUpdates);

                // Collected inside the tick, dispatched after the commit below: see RestingOrderWatch.
                $pending = $this->restingOrders->collect($quotes, $bondResult['struck'], $tickCount);
                $profiler->lap('limit orders');

                if ($isHistoryTick) {
                    // The per-tick columns go to the database as data, before the flush: they are mapped
                    // non-updatable, so the flush carries only what else changed on a company and a quiet tick
                    // writes no stock row at all.
                    StockTickColumns::write($conn, $stocks);
                    $profiler->lap('stock marks');

                    $this->entityManager->flush();
                    $profiler->lap('flush');

                    $bars->write($conn, $result['history'], $macroState->totalTime);

                    // Empty except on the day's mark: see BOND_MARKS_PER_YEAR.
                    $this->bondTracker->recordHistory($bondResult['history']);

                    // The funds' own rows, accrued during the fund phase and written here as data: see
                    // EtfTracker::recordHistory().
                    $this->etfTracker->recordHistory();
                    $profiler->lap('history rows');

                    // Once a trading day, not every bar: see reloadIntervalBars(). Everything the tick changed has
                    // just been flushed, so nothing is lost to the clear.
                    if (TickCadence::isReloadTick($tickCount, $this->ticksPerYear)) {
                        $this->entityManager->clear();
                        [$stocks, $indexFunds, $bonds] = $this->loadWorkingSet();
                        $profiler->lap('reload');
                    }
                }

                $chartBuffers->write($quotes, $bondResult['updates'], $tickCount, $this->ticksPerYear);
                $profiler->lap('chart buffers');

                $districtReconstitution = $this->reconstituteDistrict($stocks, $tickCount, $events);

                // The wire carries frames, not ticks: the latest quote per instrument plus the per-tick points the
                // live chart draws. The scalars are absorbed on frame ticks only: macro alone is ~200 fields, and
                // a frame carries the last tick's value whichever tick it was read on.
                $wireFrame->absorb(
                    $tickCount,
                    $published,
                    $events,
                    $districtReconstitution,
                    $isFrameTick ? [
                        'market_vol' => $marketVol,
                        'economic_cycle' => $macroState->economicCycleLabel(),
                        'council_rate' => $macroState->policyRate,
                        'macro' => $macroState->toArray(),
                        'bond_curve' => $bondResult['curve'],
                    ] : []
                );
                if ($isFrameTick) {
                    $this->redis->publish('market_updates', json_encode($wireFrame->flush($tickCount, time())));

                    // The gap decomposition goes to its own key rather than into the frame: the admin panel is the
                    // only reader, and a frame is on every open browser's wire.
                    $this->redis->set(OutputGapProbe::REDIS_KEY, (string) json_encode($this->gapProbe->snapshot()));
                    $this->redis->set(MacroDiagnosticsProbe::REDIS_KEY, (string) json_encode($this->diagnosticsProbe->snapshot()));
                }

                // A cache of the committed clock, for the web process. Never the authority: see SimulationClock.
                $this->redis->set('simulation_tick_count', $tickCount);
                $profiler->lap('publish');

                // Save Portfolio Snapshots once a "Simulation Week"
                if ($tickCount % $snapshotInterval === 0) {
                    // Write the live prices first, so recordBulkSnapshots' raw SQL values holdings at them.
                    if (!$isHistoryTick) {
                        StockTickColumns::write($conn, $stocks);
                        $this->entityManager->flush();
                    }

                    // Idle brokerage cash is swept overnight and earns the policy rate net of the intermediary's
                    // spread, accrued before the snapshot so its net asset value includes the week's interest.
                    $this->portfolio->accrueCashInterest(
                        Portfolio::cashSweepRate($macroState->policyRateEma),
                        $snapshotInterval * $dt
                    );

                    $this->portfolio->recordBulkSnapshots();
                    $profiler->lap('snapshots');
                }

                if ($tickCount % $quarterlyInterval === 0) {
                    $this->recordQuarter($macroState, $conn, $quarterlyInterval);
                }

                // The clock goes in with the tick, on the tick's own connection: a tick that rolls back rolls its
                // clock back too, and one that commits cannot lose the fact that it happened.
                $this->simulationClock->advance($conn, $tickCount, $macroState->totalTime);

                $this->entityManager->commit();
                $profiler->lap('commit');

                // Expiries and watched-name news raised inside the tick go out only now it has committed.
                $this->notifier->publish();

                // Drained here, with the tick's locks released: the worker is free to take the rows it needs.
                foreach ($pending['limit_orders'] as $pendingTicker => $pendingPrice) {
                    $this->messageBus->dispatch(new \App\Message\ProcessLimitOrdersMessage($pendingTicker, $pendingPrice));
                }
                foreach ($pending['alerts'] as $pendingTicker => $pendingPrice) {
                    $this->messageBus->dispatch(new \App\Message\ProcessPriceAlertsMessage($pendingTicker, $pendingPrice));
                }
                $profiler->lap('limit dispatch');

                // The margin sweep runs AFTER the tick commits, never inside it: a forced sale goes through the
                // ordinary execution path, which opens its own transaction.
                if ($tickCount % $snapshotInterval === 0) {
                    $this->liquidationService->sweep($macroState->policyRate, $snapshotInterval * $dt);
                    $profiler->lap('margin sweep');

                    // The season's week, on the snapshot's cadence and after the sweep, so a forced sale is in it.
                    $this->seasons->recordWeek(
                        $macroState->totalTime,
                        $snapshotInterval * $dt,
                        Portfolio::cashSweepRate($macroState->policyRateEma)
                    );
                    $profiler->lap('season');
                }
            } catch (RetryableException $e) {
                // A deadlock or a lock-wait timeout is not a fault in the tick: InnoDB rolled one transaction back
                // and left a consistent state. It costs THIS tick, and the next recomputes from the rows on disk,
                // so the ticker takes the loss and carries on without the generic path's pause. The tick is not
                // re-run in place: the operator pass and the hedge ran before the transaction opened, against the
                // entity graph the clear() below discards.
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
                $this->notifier->discard();

                $output->writeln("<comment>Tick {$tickCount} lost to lock contention: " . $e->getMessage() . "</comment>");

                if (!$this->entityManager->isOpen()) {
                    $output->writeln("<error>EntityManager is closed. Exiting Ticker to reboot...</error>");
                    return Command::FAILURE;
                }

                $this->entityManager->clear();
                [$stocks, $indexFunds, $bonds] = $this->loadWorkingSet();
            } catch (\Exception $e) {
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
                $this->notifier->discard();
                $output->writeln("<error>Error: " . $e->getMessage() . "</error>");

                // If the EntityManager has closed entirely, exit to let Supervisor restart the daemon
                if (!$this->entityManager->isOpen()) {
                    $output->writeln("<error>EntityManager is closed. Exiting Ticker to reboot...</error>");
                    return Command::FAILURE;
                }

                // Clear detached entities and reload fresh ones so the next tick has a valid state
                $this->entityManager->clear();
                [$stocks, $indexFunds, $bonds] = $this->loadWorkingSet();

                sleep(5);
            }

            // The tick that just ran, kept before the counter moves on, so every diagnostic below names it.
            $ranTick = $tickCount;
            $tickCount++;

            // Its own line rather than part of the lag warning: a dropped write has nothing to do with how long
            // the tick took, and the warning only fires on an overrun.
            $unpersisted = $this->writeAudit->summary();

            if ($unpersisted !== '') {
                $output->writeln("<error>⚠️ Unpersisted writes on tick {$ranTick}: {$unpersisted} — repaired, but a mutation is missing its persist().</error>");
            }

            $executionTimeSec = microtime(true) - $tickStartTime;
            $executionTimeUs = (int) ($executionTimeSec * 1000000);
            $overran = $executionTimeUs > $this->tickIntervalUs;

            if ($overran) {
                $overtimeMs = ($executionTimeUs - $this->tickIntervalUs) / 1000;

                // The identity map's size separates flush overhead from write volume.
                $managed = $this->entityManager->getUnitOfWork()->size();
                $written = $this->flushProfiler->summary();
                $wrote = $written === '' ? 'wrote nothing' : $written;

                $output->writeln("<comment>⚠️ Lag Spike: Tick {$ranTick} took too long! Dropped behind by " . round($overtimeMs, 2) . "ms ({$profiler->breakdown()} | map {$managed} | {$wrote})</comment>");
            }

            foreach ($profiler->closeTick($executionTimeSec * 1000.0, $overran, $tickCount, $this->tickIntervalUs / 1000.0) as $line) {
                $output->writeln($line);
            }

            $timeToSleepUs = $this->tickIntervalUs - $executionTimeUs;

            if ($timeToSleepUs > 0) {
                usleep($timeToSleepUs);
            }
        }

        $output->writeln("<comment> Market Ticker shut down.</comment>");
        return Command::SUCCESS;
    }

    /**
     * One staggered slice of the option chain: settles expiries, lists strikes and refreshes the Greeks.
     *
     * @param array<int, Stock> $stocks
     */
    private function sweepOptions(
        array $stocks,
        MacroStateDTO $macroState,
        float $dt,
        int $tickCount,
        int $optionSweepInterval,
        TickProfiler $profiler,
        OutputInterface $output,
    ): void {
        $sweepResult = $this->optionDesk->sweep(
            $stocks,
            $macroState,
            $dt * $optionSweepInterval * OptionDeskService::SWEEP_SLICES,
            OptionDeskService::sweepSlice($tickCount, $optionSweepInterval)
        );
        $profiler->lap('options');

        // The sweep's own stages, so an overrun names the statement rather than the service.
        foreach ($sweepResult['timing'] as $stage => $ms) {
            $profiler->record('opt:' . $stage, $ms);
        }

        // What the pass moved, so a slow settle can be told from a large one. Marking is every sweep's routine
        // job, so only the two event stages are reported.
        if ($sweepResult['settled'] > 0 || $sweepResult['listed'] > 0) {
            // `marked` counts only contracts a player HOLDS; the chain's own marks go out in one bulk statement.
            $held = $sweepResult['marked'] > 0 ? sprintf(', held marks %d', $sweepResult['marked']) : '';

            $output->writeln(sprintf(
                '<info>   opt: settled %d (exercised %d), listed %d%s [tick %d]</info>',
                $sweepResult['settled'],
                $sweepResult['exercised'],
                $sweepResult['listed'],
                $held,
                $tickCount
            ));
        }
    }

    /**
     * Glasswater Row's quarterly reconstitution: re-ranks the roster and publishes each promotion and eviction.
     *
     * @param array<int, Stock>        $stocks
     * @param array<int|string, mixed> $events The tick's events, appended to.
     * @return array{tick: int, tickers: list<string>, promoted: list<string>, evicted: list<string>}|null Null off the reconstitution tick.
     */
    private function reconstituteDistrict(array $stocks, int $tickCount, array &$events): ?array
    {
        if (!DistrictRoster::isReconstitutionTick($tickCount, $this->ticksPerYear)) {
            return null;
        }

        $reconstitution = $this->districtRoster->reconstitute($stocks, $tickCount);
        $stocksByTicker = [];
        foreach ($stocks as $stock) {
            $stocksByTicker[$stock->getTicker()] = $stock;
        }
        foreach ($reconstitution['promoted'] as $ticker) {
            if (isset($stocksByTicker[$ticker])) {
                $events[] = $this->marketEvent->publish($stocksByTicker[$ticker], 'DISTRICT', 'Promoted to Glasswater Row at the quarterly reconstitution.', 0.0);
            }
        }
        foreach ($reconstitution['evicted'] as $ticker) {
            if (isset($stocksByTicker[$ticker])) {
                $events[] = $this->marketEvent->publish($stocksByTicker[$ticker], 'DISTRICT', 'Lost its frontage on Glasswater Row at the quarterly reconstitution.', 0.0);
            }
        }
        if ($reconstitution['promoted'] !== [] || $reconstitution['evicted'] !== []) {
            $this->entityManager->flush();
        }

        return $reconstitution;
    }

    /**
     * The quarter's macro_report row: the state vector and what moved the output gap over the quarter.
     */
    private function recordQuarter(MacroStateDTO $macroState, Connection $conn, int $quarterlyInterval): void
    {
        // Rolled BEFORE the snapshot, so the quarter that just ended goes into its own row, not the next one's.
        $this->gapProbe->rollWindow();
        $this->diagnosticsProbe->rollWindow();
        $closedQuarter = $this->gapProbe->snapshot()['previous'];
        $closedDiagnostics = $this->diagnosticsProbe->snapshot()['previous'];

        // A ticker that starts mid-quarter closes a PARTIAL first window, whose short contributions over a
        // near-zero horizon annualise into nonsense (+7.0pp/yr against a ±1.2 range). Dropped, not corrected.
        $wholeQuarter = $closedQuarter !== null && $closedQuarter['ticks'] >= $quarterlyInterval;

        $this->macroEngine->recordMacroSnapshot($macroState, $conn, new QuarterRecord(
            gapChannels: $wholeQuarter ? $closedQuarter : null,
            diagnostics: $wholeQuarter ? $closedDiagnostics : null,
            configFingerprint: $this->configFingerprint->fingerprint(),
            ticksPerYear: $this->ticksPerYear,
        ));
    }
}
