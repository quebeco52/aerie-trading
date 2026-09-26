<?php

namespace App\Command;

use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Market\BondTracker;
use App\Service\Market\StockTracker;
use App\Service\Market\CorporateBondDesk;
use App\Service\Market\IndexCommittee;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\SimulationClockService;
use App\Service\Market\OptionChainService;
use App\Service\Market\OptionDeskService;
use App\Service\Market\StockTickColumns;
use App\Service\Market\WireFrame;
use App\Service\Market\TreasuryAuctionService;
use App\Service\Market\EtfTracker;
use App\Service\Market\IndexFundAccountant;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketOperator;
use App\Service\User\Portfolio;
use App\Service\Event\SystemicEventReporter;
use App\Service\Event\MarketEventPublisher;
use App\Service\District\DistrictRoster;
use App\Entity\Etf;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
    // --- Index Reconstitution ---
    /** Tickers named per side of a reconstitution event before the rest are counted; an event description holds 255 characters. */
    public const RECONSTITUTION_TICKERS_SHOWN = 8;

    // --- History Sampling ---
    /** Target price history rows written per simulated year; the actual rate is this or one row per tick, whichever is coarser. */
    public const TARGET_HISTORY_POINTS_PER_YEAR = 2400;

    // --- Working Set ---
    /** Times per simulated year the identity map is cleared and the working set re-read from the database: once a trading day. */
    public const WORKING_SET_RELOADS_PER_YEAR = 252;

    // --- Bond History Sampling ---
    /**
     * Bond history points written per simulated year: one per trading day, as a bond is quoted.
     *
     * A bond's price is a function of a curve that moves in basis points over weeks, and the market convention
     * for a fixed income series is an end-of-day mark rather than a print. Sampling the ladder at the equity
     * rate wrote two hundred rows eight times a second — more rows per real day than the whole equity board
     * writes in a week — and the insert into a table that size was half of the history tick's overrun. The
     * short-range chart is unaffected: it reads the Redis buffer, which still takes every tick.
     */
    public const BOND_HISTORY_POINTS_PER_YEAR = 252;

    // --- Diagnostics ---
    /** Phases named in a lag warning, most expensive first. */
    public const LAG_PHASES_SHOWN = 4;

    /** Simulated years the tick counter may imply away from the clock before the start-up reports it as a divergence. */
    public const CLOCK_DIVERGENCE_TOLERANCE_YEARS = 0.01;

    // --- Retention ---
    /** Simulated years between retention passes, matching the units the cutoffs themselves are written in. */
    public const PRUNE_INTERVAL_YEARS = 1.0;

    /** Ticks a steady-state phase report covers. Long enough that the slowest cadence in the tick — the option sweep, at a pass every few dozen ticks — is averaged over several of its own passes. */
    public const PHASE_REPORT_INTERVAL_TICKS = 600;

    /**
     * History bars between working-set reloads.
     *
     * A reload has to follow a flush, so it is counted in bars rather than ticks. Reloading on every bar
     * re-hydrated sixty companies and a couple of hundred bonds eight times a second, which was a quarter
     * of the history tick; the only thing a reload brings in that the ticker cannot see otherwise is a
     * short-interest figure the web process writes, and nothing on the pricing path reads that.
     */
    public static function reloadIntervalBars(int $ticksPerYear): int
    {
        return self::intervalBars($ticksPerYear, self::WORKING_SET_RELOADS_PER_YEAR);
    }

    /**
     * History bars between bond history rows.
     *
     * Counted in bars because a bond is only revalued on the history cadence, and a history row has to carry
     * a mark from the tick that wrote it rather than the last one it happens to remember.
     */
    public static function bondHistoryIntervalBars(int $ticksPerYear): int
    {
        return self::intervalBars($ticksPerYear, self::BOND_HISTORY_POINTS_PER_YEAR);
    }

    /** History bars between events that should happen a given number of times a simulated year. */
    private static function intervalBars(int $ticksPerYear, int $perYear): int
    {
        return max(1, (int) round(self::historyPointsPerYear($ticksPerYear) / $perYear));
    }

    /** Whether a history bar re-reads the working set. */
    public static function isReloadBar(int $bar, int $ticksPerYear): bool
    {
        return $bar % self::reloadIntervalBars($ticksPerYear) === 0;
    }

    /**
     * Whether a TICK re-reads the working set: the bar-counted job, addressed the way the loop has it.
     *
     * The loop holds a tick count, not a bar index, and asking it to carry one was a mistake that cost a
     * crash loop: `$bar` is a natural name for the open/high/low accumulator too, the history-row insert
     * reassigned it four hundred lines further down, and the reload below then received an array. Nothing
     * caught it — PHPStan reads the loop body as too complex to track a local through, and the loop itself
     * has no test, so the first thing to notice was the ticker restarting in production.
     *
     * Deriving the bar inside these wrappers is a multiply and an integer division against a job that
     * clears the identity map and re-hydrates four hundred entities. The name cannot collide with anything
     * because it no longer exists.
     */
    public static function isReloadTick(int $tickCount, int $ticksPerYear): bool
    {
        return self::isHistoryTick($tickCount, $ticksPerYear)
            && self::isReloadBar(self::barIndex($tickCount, $ticksPerYear), $ticksPerYear);
    }

    /**
     * Whether a history bar samples the bond ladder into bond_history.
     *
     * Offset half an interval from the reload bar. Both are once-a-day jobs counted in the same bars, and
     * at the same offset every bond history insert would land on the tick that had just thrown its working
     * set away and re-read it — one tick paying for both, which is the shape of an outlier rather than of a
     * cost. Away from each other they are two ordinary bars.
     */
    public static function isBondHistoryBar(int $bar, int $ticksPerYear): bool
    {
        $bars = self::bondHistoryIntervalBars($ticksPerYear);

        return $bar % $bars === intdiv($bars, 2);
    }

    /** Whether a TICK samples the bond ladder: see isReloadTick() for why this is addressed by tick. */
    public static function isBondHistoryTick(int $tickCount, int $ticksPerYear): bool
    {
        return self::isHistoryTick($tickCount, $ticksPerYear)
            && self::isBondHistoryBar(self::barIndex($tickCount, $ticksPerYear), $ticksPerYear);
    }

    /**
     * Price history rows written per simulated year at a given tick rate.
     *
     * The target, or the tick rate itself when that is the coarser of the two — a bar cannot be finer than
     * a tick. This is the whole of the sampling rule; the grid below is derived from it rather than the
     * other way round.
     *
     * Anything converting a chart range into a row LIMIT has to ask this rather than assume a tick rate:
     * the range buttons used to carry hardcoded row counts that only matched a 4,800-tick year, so every
     * span on the stock page was wrong by whatever ratio the configured rate differed by.
     */
    public static function historyPointsPerYear(int $ticksPerYear): int
    {
        return max(1, min(self::TARGET_HISTORY_POINTS_PER_YEAR, $ticksPerYear));
    }

    /**
     * History bars closed by a given tick.
     *
     * THE BAR GRID IS RATIONAL, NOT A WHOLE NUMBER OF TICKS. Spacing bars by dividing the tick rate by the
     * target and truncating only lands on the target when one divides the other, and truncation is biased
     * toward writing too many at every rate where it does not: 7,000 ticks a year wants a bar every 2.9
     * ticks and gets one every 2, which is 3,500 rows against a target of 2,400. At 3,600 the quotient is
     * 1.5, truncation reached the floor of 1, and a bar closed on EVERY tick — with the flush, the bond
     * mark, the tick-column write and the working-set reload all riding a flag that is never meant to be
     * true every tick. The tick rate is a resolution knob and nothing more; it must not decide which jobs
     * the ticker does.
     *
     * Counting bars rather than spacing them spreads the remainder evenly and closes exactly
     * historyPointsPerYear() of them per simulated year at any tick rate. It is a pure function of the tick
     * count, so it survives a restart with no accumulator to carry, and it is exact wherever the target
     * does divide the rate: at 14,400 this is still every sixth tick to the tick.
     */
    public static function barIndex(int $tickCount, int $ticksPerYear): int
    {
        return intdiv(max(0, $tickCount) * self::historyPointsPerYear($ticksPerYear), max(1, $ticksPerYear));
    }

    /**
     * Whether this tick crossed a boundary of the given period in SIMULATED time.
     *
     * The tick-count cadences elsewhere in this loop only equal a simulated interval because `dt` happens to
     * be `1/ticksPerYear` for the whole of a run; they carry no meaning across a rate change or a counter
     * that has drifted from the clock. A job whose period is written in simulated years asks the clock.
     */
    public static function crossedSimulatedBoundary(float $totalTime, float $dt, float $periodYears): bool
    {
        if ($periodYears <= 0.0 || $dt <= 0.0) {
            return false;
        }

        // Half a tick, expressed in periods, applied to BOTH samples and to the floor above zero.
        //
        // Neither end of the comparison is exact. The previous sample is reconstructed as `$totalTime - $dt`
        // rather than remembered, and that subtraction does not land back on the value the last tick held:
        // at 14,400 ticks a year the tick after the second year reconstructs its predecessor as
        // 1.99999999999999978, one ulp below a boundary it had already crossed, and the period fires twice.
        // The current sample is no better, because the loop ACCUMULATES it: 252 additions of 1/252 reach
        // 0.99999999999999989, so a plain `< $periodYears` guard rejects the first year outright and loses
        // it. Both samples are supposed to be tick multiples, so they are snapped to the nearest one; a
        // discrepancy smaller than half a tick is the float representation, not elapsed time.
        $epsilon = $dt / $periodYears / 2.0;
        $index = (int) floor($totalTime / $periodYears + $epsilon);
        $previous = (int) floor(max(0.0, $totalTime - $dt) / $periodYears + $epsilon);

        // Index zero is the period the simulation starts inside, which is entered rather than crossed.
        return $index >= 1 && $index > $previous;
    }

    /** Whether a tick closes a history bar: the tick the bar count rolls over on. */
    public static function isHistoryTick(int $tickCount, int $ticksPerYear): bool
    {
        return $tickCount <= 0
            || self::barIndex($tickCount, $ticksPerYear) > self::barIndex($tickCount - 1, $ticksPerYear);
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
        private \App\Service\Market\ForcedLiquidationService $liquidationService,
        private \App\EventListener\FlushProfiler $flushProfiler,
        /** Catches a mutation that DEFERRED_EXPLICIT tracking would drop, and writes it anyway. */
        private \App\EventListener\DeferredWriteAudit $writeAudit,
        private \App\Service\Macro\Recorder\OutputGapProbe $gapProbe,
        private \App\Service\Macro\Recorder\MacroDiagnosticsProbe $diagnosticsProbe,
        private \App\Service\Macro\MacroConfigFingerprint $configFingerprint,
        private SimulationClockService $simulationClock,
        private OptionChainService $optionChain,
        private TreasuryAuctionService $treasuryAuction,
        private MacroEngine $macroEngine,
        private MarketOperator $marketOperator,
        private Portfolio $portfolio,
        private \Redis $redis,
        private SystemicEventReporter $systemicEvents,
        private MarketEventPublisher $marketEvent,
        private DistrictRoster $districtRoster,
        private \App\Service\Market\OptionDeskService $optionDesk,
        private CorporateBondDesk $corporateBondDesk,
        private IndexCommittee $indexCommittee,
        private IndexFundAccountant $fundAccountant,
        private \Symfony\Component\Messenger\MessageBusInterface $messageBus,
        /** Splits a creation basket over the names it is made of. */
        private \App\Service\Market\AuthorizedParticipant $authorizedParticipant,
        /** Where a creation basket is posted, so it lands on the constituents next tick like any other order. */
        private \App\Service\Market\Flow\OrderFlowStoreInterface $orderFlow,
        /** One limit-order check per instrument per retry window, not one per tick while the worker answers. */
        private \App\Service\Market\LimitOrderDispatchGate $limitOrderGate,

        private int $tickIntervalUs,
        private int $ticksPerYear,
    ) {
        parent::__construct();
    }

    /**
     * The fund behind each published index, keyed by ticker. Reloaded after every EntityManager clear.
     *
     * @return array<string, Etf>
     */
    private function loadIndexFunds(): array
    {
        $funds = [];
        $repository = $this->entityManager->getRepository(Etf::class);

        foreach (MarketIndex::cases() as $index) {
            $fund = $repository->findOneBy(['ticker' => $index->value]);
            if ($fund !== null) {
                $funds[$index->value] = $fund;
            }
        }

        return $funds;
    }

    /**
     * The event text for a reconstitution, within the 255 characters an event description holds.
     *
     * A composite that has just lost a dozen names to a wave of bankruptcies would otherwise overflow the
     * column, so each list is cut and the remainder counted rather than dropped silently.
     *
     * @param list<string> $added
     * @param list<string> $deleted
     */
    public static function describeReconstitution(array $added, array $deleted): string
    {
        $list = static function (array $tickers): string {
            $shown = array_slice($tickers, 0, self::RECONSTITUTION_TICKERS_SHOWN);
            $rest = count($tickers) - count($shown);

            return implode(', ', $shown) . ($rest > 0 ? sprintf(' and %d more', $rest) : '');
        };

        $parts = [];
        if ($added !== []) {
            $parts[] = 'added ' . $list($added);
        }
        if ($deleted !== []) {
            $parts[] = 'dropped ' . $list($deleted);
        }

        return 'Quarterly reconstitution: ' . implode('; ', $parts) . '.';
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
     * Executes the market simulation loop.
     *
     * @return int Command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>Market Ticker Started...</info>");

        // Fetch the stocks ONCE into RAM before the loop starts!
        $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
        $indexFunds = $this->loadIndexFunds();
        $bonds = $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]);

        $dt = 1.0 / $this->ticksPerYear;

        // Resume simulation clock using persistent database and option chain state to prevent rewind.
        $furthestSerial = $this->optionChain->furthestListedSerial();
        $clock = $this->simulationClock->resume(
            (int) ($this->redis->get('simulation_tick_count') ?: 0),
            $this->macroEngine->getLiveState()->totalTime,
            $furthestSerial === null ? 0.0 : OptionChainService::earliestTimeFor($furthestSerial),
            (bool) $input->getOption('rebase-clock')
        );

        $tickCount = $clock->getTickCount();

        // The economic clock, carried alongside the loop counter because the two are not interchangeable:
        // this is what the option grid, the bond ladder and every retention cutoff are measured against,
        // and it is the only one of the pair that can be converted into a date.
        $simTime = $clock->getTotalTime();

        // Synchronize cached Redis macro clock with authoritative simulation clock.
        $this->macroEngine->alignClock($simTime);

        $output->writeln(sprintf(
            'Resuming at tick %d (simulation year %.4f, day %s).',
            $tickCount,
            $simTime,
            number_format($simTime * 365.0, 1)
        ));

        // The counter and the clock advance at the same rate by construction, so they can only differ by an
        // offset — and an offset means one of them was seeded from a cache the other did not come from.
        // Neither reading is corrupt on its own: every tick-keyed cadence stays self-consistent with the
        // counter and every dated fact stays self-consistent with the clock. What breaks is converting one
        // into the other, so the divergence is reported rather than silently repaired; --rebase-clock does
        // that on request, at the cost of shifting every cadence's phase once.
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

        $operatorInterval = (int) max(1, $this->ticksPerYear / 24);  // Operator audits once a game "month"
        $snapshotInterval = (int) max(1, $this->ticksPerYear / 52);  // Snapshots once a game "week"
        $quarterlyInterval = (int) max(1, $this->ticksPerYear / 4);   // Snapshots once a game "quarter"
        $auctionInterval = TreasuryAuctionService::auctionIntervalTicks($this->ticksPerYear);
        $optionSweepInterval = OptionDeskService::sweepIntervalTicks($this->ticksPerYear);
        $corporateIssuanceInterval = CorporateBondDesk::issuanceIntervalTicks($this->ticksPerYear);

        // What the configured tick rate actually buys, said once rather than inferred from the overrun rate.
        // A bar carries the flush, the bond mark, the tick-column write and the reload, so the share of
        // ticks that close one is the share that cannot finish inside the budget. When the tick rate is at
        // or below the target every tick closes a bar — correct, because a bar cannot be finer than a tick,
        // but it leaves no tick with slack and a hundred percent overrun rate is the expected reading rather
        // than a regression.
        $barsPerYear = self::historyPointsPerYear($this->ticksPerYear);
        $output->writeln(sprintf(
            'Sampling: %d bars/sim-year over %d ticks (%.0f%% of ticks close a bar), %.1f ms budget.',
            $barsPerYear,
            $this->ticksPerYear,
            100.0 * $barsPerYear / max(1, $this->ticksPerYear),
            $this->tickIntervalUs / 1000.0
        ));

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

        /**
         * Wall time per phase of the tick just run, in ms. Reported with a lag warning so an overrun names
         * what it spent its time on: the model, the flush, the reload or the wire.
         *
         * A phase is EVERYTHING SINCE THE LAST MARKER, so a missing marker never loses time — it charges it
         * to whatever is marked next, under that name. 'bonds' used to carry the shock narrative, both index
         * passes and every ETF update as well as the bond desk, which made the largest line in the profile
         * the one nobody could act on. Add a marker whenever a phase grows a second job.
         *
         * @var array<string, float>
         */
        $phases = [];
        $phaseStart = hrtime(true);

        /**
         * The same phases summed over the reporting window, with the window's tick count beside them.
         *
         * SUMMED RATHER THAN SAMPLED, because most of the tick is not on every tick. Options sweep on their
         * own interval, history rows and the reload on theirs, settlement on the expiry grid; one tick's
         * breakdown shows whichever of those happened to land on it and says nothing about the rest. A total
         * over the window divided by the window is what each subsystem actually costs per tick, cadence
         * included, which is the number to optimise against.
         *
         * @var array<string, float>
         */
        $phaseTotals = [];
        $windowTicks = 0;
        $windowOverruns = 0;
        $windowMaxMs = 0.0;
        $windowTotalMs = 0.0;

        // The tick is the only place a flush is timed, so it is the only place that wants the attribution.
        $this->flushProfiler->enable();

        $auditWrites = (bool) $input->getOption('audit-writes');

        if ($auditWrites) {
            $this->writeAudit->enable();
            $output->writeln('<comment>Write audit on: explicit-tracking misses will be reported and repaired.</comment>');
        }

        // The gap is only ever moved here, so this is the only process that can say what moved it.
        $this->gapProbe->enable();
        $this->diagnosticsProbe->enable();

        $lap = static function (string $name) use (&$phases, &$phaseStart): void {
            $now = hrtime(true);
            $phases[$name] = ($phases[$name] ?? 0.0) + (($now - $phaseStart) / 1e6);
            $phaseStart = $now;
        };

        // Lag equity market cap across ticks to avoid simultaneous feedback loops in macro step.
        $lastEquityMarketCap = null;

        $wireFrame = new WireFrame();

        while ($this->keepRunning) {

            $tickStartTime = microtime(true);
            $phases = [];
            $phaseStart = hrtime(true);
            $this->flushProfiler->reset();
            $this->writeAudit->reset();

            pcntl_signal_dispatch();

            if ($tickCount % 10 === 0) {
                // Dated off the simulation clock, never off the tick count. The counter is a loop position
                // whose absolute value only has to be consistent with itself; converting it into a date
                // reports the wrong day for the whole run whenever the two have drifted apart.
                $output->writeln("Updating Market Prices... (Day: " . number_format($simTime * 365.0, 1) . ") [Tick: $tickCount]");
            }

            $macroState = $this->macroEngine->updateMacroState($dt, $lastEquityMarketCap);
            $simTime = $macroState->totalTime;
            $lap('macro');

            // Retention, on the clock its cutoffs are measured against. The boundary test is the same one
            // the macro subsystems use for a simulated year-end: true exactly once per crossing, and
            // indifferent to the tick rate in a way `$tickCount % $interval` is not. No gate and no
            // persisted marker, because the pass is idempotent — a crossing missed while the ticker was
            // down and one repeated after it restarts both cost a second look at rows already too old to
            // keep. The work itself goes to the worker; only the dispatch happens here, once a simulated
            // year, which is why it carries no lap of its own.
            if (self::crossedSimulatedBoundary($simTime, $dt, self::PRUNE_INTERVAL_YEARS)) {
                $this->messageBus->dispatch(new \App\Message\PruneHistoryMessage(
                    \App\Service\Market\HistoryPruner::DEFAULT_YEARS_KEPT,
                    \App\Service\Market\HistoryPruner::DEFAULT_KEEP_RATIO
                ));
            }

            $isFrameTick = WireFrame::isFrameTick($tickCount, $this->tickIntervalUs);

            if ($tickCount % $operatorInterval === 0) {
                $operatorEvents = $this->marketOperator->enforceMarketStability($stocks, $macroState);
            } else {
                $operatorEvents = [];
            }

            $isHistoryTick = self::isHistoryTick($tickCount, $this->ticksPerYear);

            // Re-hedge option inventory on history bar cadence before draining order flow.
            if ($isHistoryTick) {
                $this->optionDesk->hedge($stocks);
            }

            try {
                $this->entityManager->beginTransaction();

                // Instruments whose resting orders need a look, collected here and dispatched AFTER the
                // commit below. The transport is Redis, so a dispatch is visible to the worker the instant
                // it is made and owes nothing to this transaction: sending from inside the tick handed the
                // worker a ticker whose rows the tick still held, and the worker takes them in the opposite
                // order it does (its user row first, by TradeExecutionService's pessimistic lock, then the
                // stock) -- the lock cycle InnoDB answers with a 1213. Routing the message to the worker
                // moved the work out of this transaction; it did not stop it running CONCURRENTLY with it.
                // Same rule the margin sweep already follows below, and a tick that rolls back now drops
                // these instead of having asked the worker to fill against a price that never happened.
                $pendingLimitOrderChecks = [];

                $lap('operator+hedge');
                $result = $this->stockTracker->updateStocks($stocks, $dt, $isHistoryTick, $macroState, $tickCount, $this->ticksPerYear);
                $lap('stocks');
                $stockUpdates = $result['updates'];
                $totalMarketCap = $result['total_cap'];

                // Household equity wealth is what the board is worth, so it is read here, off the board.
                // The index funds' levels are quotes on tradable instruments and are restated when those
                // instruments split; capitalisation is the quantity itself and survives that untouched.
                $lastEquityMarketCap = $totalMarketCap > 0.0 ? $totalMarketCap : $lastEquityMarketCap;
                $marketVol = $result['market_vol'];
                $events = $result['events'];

                $benchmarkFund = $indexFunds[MarketIndex::benchmark()->value] ?? null;
                if ($benchmarkFund !== null && ($headline = $this->systemicEvents->report($macroState, $benchmarkFund)) !== null) {
                    $events[] = $headline;
                }

                if (!empty($operatorEvents)) {
                    $events = array_merge($events, $operatorEvents);
                }

                $lap('events');

                // Reconstitute indexes on quarterly calendar and publish membership events.
                if (IndexCommittee::isReconstitutionTick($tickCount, $this->ticksPerYear)) {
                    foreach (MarketIndex::cases() as $index) {
                        $fund = $indexFunds[$index->value] ?? null;
                        $reconstitution = $this->indexCommittee->reconstitute(
                            $index,
                            $stocks,
                            $tickCount,
                            // Pass underlying index level excluding fund fee drag and accrued income.
                            $fund?->getIndexLevel()
                        );

                        // Charge fund rebalancing trading costs before checking membership changes.
                        if ($fund !== null) {
                            $this->fundAccountant->chargeRebalance(
                                $fund,
                                $reconstitution['trading_cost'],
                                $reconstitution['level']
                            );
                        }

                        if ($reconstitution['added'] === [] && $reconstitution['deleted'] === []) {
                            continue;
                        }

                        $output->writeln(sprintf(
                            '<info>%s reconstitution: +%s / -%s</info>',
                            $index->value,
                            implode(',', $reconstitution['added']) ?: 'none',
                            implode(',', $reconstitution['deleted']) ?: 'none'
                        ));

                        if ($fund !== null) {
                            $events[] = $this->marketEvent->publish(
                                $fund,
                                'INDEX',
                                self::describeReconstitution($reconstitution['added'], $reconstitution['deleted']),
                                0.0
                            );
                        }
                    }
                }

                // Strike each index against constituent float market caps and process quarterly fund distributions.
                $isDistributionTick = $tickCount > 0
                    && $tickCount % max(1, intdiv($this->ticksPerYear, FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR)) === 0;

                // Prices as the tick has just published them, for splitting a creation basket into shares.
                $memberPrices = [];
                foreach ($result['updates'] as $publishedUpdate) {
                    $memberPrices[$publishedUpdate['ticker']] = (float) $publishedUpdate['price'];
                }

                $etfUpdates = [];
                foreach (MarketIndex::cases() as $index) {
                    if (!isset($indexFunds[$index->value])) {
                        continue;
                    }

                    $fund = $indexFunds[$index->value];

                    // Paid BEFORE the strike below, so the price the tick publishes is already ex the cash
                    // that left. The drop is not a return: the holder has the money instead.
                    if ($isDistributionTick) {
                        $paid = $this->fundAccountant->distribute($fund, new \DateTime());

                        if ($paid > 0.0) {
                            $events[] = $this->marketEvent->publish(
                                $fund,
                                'DIVIDEND',
                                sprintf(
                                    '%s distributed $%s per share of income collected from its constituents.',
                                    $fund->getName(),
                                    number_format($paid, 2)
                                ),
                                0.0
                            );
                        }
                    }

                    // What the players did to the FUND this tick, and what its basket costs to trade.
                    // The first pushes the fund off its net asset value; the second decides how far it is
                    // allowed to go before an authorized participant closes the gap.
                    $fundFlowShares = (float) ($result['fund_flow'][$index->value] ?? 0.0);
                    $fundUpdate = $this->etfTracker->updateIndex(
                        $this->indexCommittee->memberCapitalisation($index, $result['float_caps']),
                        $isHistoryTick,
                        $index->value,
                        $fund,
                        $macroState->totalTime,
                        $this->indexCommittee->memberDividendPoints($index, $result['dividend_points']),
                        $dt,
                        $fundFlowShares * (float) $fund->getPrice(),
                        $this->indexCommittee->memberWeightedHalfSpread($index, $result['float_caps'], $result['half_spreads'])
                    );

                    $etfUpdates[] = $fundUpdate;

                    // The creation basket is a real order in every constituent, in index weight, and it
                    // pays each name's own impact when it lands next tick. This is the channel that makes
                    // money going INTO a fund reach the companies the fund holds instead of stopping at the
                    // fund — the other half of the index inclusion effect, and the reason a fund is worth
                    // having in the market rather than beside it.
                    $creationValue = (float) ($fundUpdate['creation_value'] ?? 0.0);

                    if ($creationValue !== 0.0) {
                        $basket = $this->authorizedParticipant->basketOrders(
                            $creationValue,
                            $this->indexCommittee->memberWeights($index, $result['float_caps']),
                            $memberPrices
                        );

                        foreach ($basket as $memberTicker => $shares) {
                            $this->orderFlow->record($memberTicker, $shares);
                        }
                    }
                }

                $lap('etfs');

                // Bond desk coupon/redemption processing, revaluing ladder on history cadence with live issuer spreads.
                $issuerSpreads = [];
                foreach ($stocks as $issuer) {
                    $issuerId = $issuer->getId();
                    if ($issuerId !== null) {
                        $issuerSpreads[$issuerId] = (float) $issuer->getDynamicCreditSpread();
                    }
                }

                // Marked on the history cadence, but only sampled into bond_history once a trading day:
                // see BOND_HISTORY_POINTS_PER_YEAR.
                $isBondHistoryTick = self::isBondHistoryTick($tickCount, $this->ticksPerYear);

                $bondResult = $this->bondTracker->updateBonds(
                    $bonds,
                    $macroState,
                    $isBondHistoryTick,
                    $issuerSpreads,
                    $isHistoryTick
                );
                $lap('bonds');

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

                // Companies come to the public market to keep their listed ladder in step with the debt the
                // balance sheet already carries. No new borrowing happens here — see CorporateBondDesk for
                // why a listed issue is a tranche of the scalar rather than an addition to it.
                if ($tickCount % $corporateIssuanceInterval === 0) {
                    foreach ($this->corporateBondDesk->reconcile($stocks, $macroState->sovereignCurve(), $macroState->totalTime) as $newIssue) {
                        $bonds[] = $newIssue;
                    }
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

                // Sweep option chain in staggered slices to settle expiries, list strikes, and update Greeks.
                if (OptionDeskService::isSweepTick($tickCount, $optionSweepInterval)) {
                    $sweepResult = $this->optionDesk->sweep(
                        $stocks,
                        $macroState,
                        $dt * $optionSweepInterval * OptionDeskService::SWEEP_SLICES,
                        OptionDeskService::sweepSlice($tickCount, $optionSweepInterval)
                    );
                    $lap('options');

                    // The sweep's own stages, so an overrun names the statement rather than the service.
                    foreach ($sweepResult['timing'] as $stage => $ms) {
                        $phases['opt:' . $stage] = $ms;
                    }

                    // What the pass actually moved. The stage timings say WHICH statement was slow and the
                    // row counts say whether it was slow for the amount of work it did — a settle that
                    // retires the whole front month and one that retires nothing look identical without
                    // this, which is why four consecutive elevated passes could not be told apart.
                    // Marking is every sweep's routine job, so only the two event stages are reported.
                    if ($sweepResult['settled'] > 0 || $sweepResult['listed'] > 0) {
                        // `marked` counts only contracts a player HOLDS, which are written a row at a time;
                        // the whole chain's marks go out in one bulk statement and are not counted here. On a
                        // market with no open option positions it is zero on every pass, so it is reported
                        // only when there is something to report rather than printed as noise.
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

                $allUpdates = array_merge($stockUpdates, $etfUpdates, $bondResult['updates']);

                // Publish changed asset quotes to websocket wire, omitting static bond quotes between history ticks.
                $published = $isHistoryTick
                    ? $allUpdates
                    : array_merge($stockUpdates, $etfUpdates);

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

                // Limit Order Check. The whole book's bounds come over in one MGET: a GET per instrument
                // was a synchronous round trip for every stock, the index and every bond, every tick.
                $tradable = array_values(array_filter(
                    $published,
                    static fn (array $update): bool => empty($update['is_bankrupt'])
                ));
                $boundsKeys = array_map(static fn (array $update): string => "limit_bounds:{$update['ticker']}", $tradable);
                $boundsRows = $boundsKeys === [] ? [] : $this->redis->mGet($boundsKeys);

                foreach ($tradable as $i => $update) {
                    $ticker = $update['ticker'];
                    $price = $update['price'];

                    $boundsJson = $boundsRows[$i] ?? false;

                    if (!is_string($boundsJson) || $boundsJson === '') {
                        // No cached book for this ticker. That is either genuinely no orders or a Redis that
                        // has been flushed since they were placed, and the two are indistinguishable from
                        // here — so check once. The handler rewrites the bounds either way, which means the
                        // uncertainty costs exactly one dispatch rather than stranding resting orders.
                        // The gate keeps it at one: the key stays missing until the worker has answered.
                        if ($this->limitOrderGate->allow($ticker, $tickCount)) {
                            $pendingLimitOrderChecks[$ticker] = $price;
                        }
                        continue;
                    }

                    $bounds = json_decode($boundsJson, true);
                    if ($price <= ($bounds['buy'] ?? 0.0) || $price >= ($bounds['sell'] ?? 999999999.0)) {
                        if ($this->limitOrderGate->allow($ticker, $tickCount)) {
                            $pendingLimitOrderChecks[$ticker] = $price;
                        }
                    } else {
                        $this->limitOrderGate->settle($ticker);
                    }
                }

                $lap('limit orders');

                if ($isHistoryTick) {

                    // The per-tick columns go to the database as data, before the flush: they are mapped
                    // non-updatable, so the flush below carries only what else changed on a company —
                    // a report, a split, a default — and a quiet tick writes no stock row at all.
                    StockTickColumns::write($conn, $stocks);
                    $lap('stock marks');

                    $this->entityManager->flush();
                    $lap('flush');

                    $historyData = $result['history'];
                    if (!empty($historyData)) {
                        $sql = "INSERT INTO stock_history (stock_id, price, open_price, high_price, low_price, volume, recorded_at, sim_time) VALUES ";
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

                            $insertValues[] = "(?, ?, ?, ?, ?, ?, ?, ?)";
                            $params[] = $row['stock_id'];
                            $params[] = $row['price'];
                            $params[] = $bar['open'];
                            $params[] = max($bar['high'], (float) $row['price']);
                            $params[] = min($bar['low'], (float) $row['price']);
                            $params[] = (int) round($bar['volume']);
                            $params[] = $now;
                            $params[] = $macroState->totalTime;
                        }

                        $sql .= implode(', ', $insertValues);

                        $conn->executeStatement($sql, $params);
                    }

                    // The bar is written; the next one opens at the next tick's price.
                    $bars = [];

                    // Empty on nine bars in ten: the ladder is sampled once a trading day, so this is a
                    // two-hundred-row insert a tenth as often rather than on every bar.
                    $this->bondTracker->recordHistory($bondResult['history']);

                    // The funds' own rows, accrued during the ETF phase above and written here as data for
                    // the same reason the two lines above are: see EtfTracker::recordHistory().
                    $this->etfTracker->recordHistory();
                    $lap('history rows');

                    // Once a trading day, not every bar: see reloadIntervalBars(). Everything the tick
                    // itself changed has just been flushed, so nothing is lost to the clear.
                    if (self::isReloadTick($tickCount, $this->ticksPerYear)) {
                        $this->entityManager->clear();
                        $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
                        $indexFunds = $this->loadIndexFunds();
                        $bonds = $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]);
                        $lap('reload');
                    }
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

                    // Volume rides along so the short ranges can draw the same bar the history table does.
                    // ETFs and bonds have no share volume; the key stays absent rather than zero, which is
                    // what tells the chart to draw no histogram at all instead of an empty one.
                    $point = ['price' => $chartPrice, 'recorded_at' => $nowStr];
                    if (isset($update['volume'])) {
                        $point['volume'] = $update['volume'];
                    }
                    $point = json_encode($point);

                    $pipeline->lPush($cacheKey, $point);

                    // Trim buffer once per history bar to cap list size efficiently.
                    if ($isHistoryTick) {
                        $pipeline->lTrim($cacheKey, 0, $redisBufferSize - 1);
                    }
                }

                $pipeline->exec();
                $lap('chart buffers');

                // Glasswater Row quarterly reconstitution: re-rank roster and publish promotion/eviction events.
                $districtReconstitution = null;
                if (DistrictRoster::isReconstitutionTick($tickCount, $this->ticksPerYear)) {
                    $districtReconstitution = $this->districtRoster->reconstitute($stocks, $tickCount);
                    $stocksByTicker = [];
                    foreach ($stocks as $stock) {
                        $stocksByTicker[$stock->getTicker()] = $stock;
                    }
                    foreach ($districtReconstitution['promoted'] as $ticker) {
                        if (isset($stocksByTicker[$ticker])) {
                            $events[] = $this->marketEvent->publish($stocksByTicker[$ticker], 'DISTRICT', 'Promoted to Glasswater Row at the quarterly reconstitution.', 0.0);
                        }
                    }
                    foreach ($districtReconstitution['evicted'] as $ticker) {
                        if (isset($stocksByTicker[$ticker])) {
                            $events[] = $this->marketEvent->publish($stocksByTicker[$ticker], 'DISTRICT', 'Lost its frontage on Glasswater Row at the quarterly reconstitution.', 0.0);
                        }
                    }
                    if ($districtReconstitution['promoted'] !== [] || $districtReconstitution['evicted'] !== []) {
                        $this->entityManager->flush();
                    }
                }

                // The wire carries frames, not ticks: the latest quote per instrument plus the per-tick
                // points the live chart draws, published WireFrame::FRAMES_PER_SECOND times a second.
                // The scalars are absorbed on frame ticks only: macro alone is ~200 fields, and a frame
                // carries the last tick's value whichever tick it was read on.
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

                    // The gap decomposition goes to its own key rather than into the frame: the admin panel
                    // is the only reader, and a frame is on every open browser's wire.
                    $this->redis->set(
                        \App\Service\Macro\Recorder\OutputGapProbe::REDIS_KEY,
                        (string) json_encode($this->gapProbe->snapshot())
                    );
                    $this->redis->set(
                        \App\Service\Macro\Recorder\MacroDiagnosticsProbe::REDIS_KEY,
                        (string) json_encode($this->diagnosticsProbe->snapshot())
                    );
                }

                // A cache of the committed clock, for the web process. Never the authority: see SimulationClock.
                $this->redis->set('simulation_tick_count', $tickCount);
                $lap('publish');

                // Save Portfolio Snapshots once a "Simulation Week"
                if ($tickCount % $snapshotInterval === 0) {
                    // Force a flush to the DB if we haven't already, so the raw SQL query
                    // used by recordBulkSnapshots calculates against the latest live prices.
                    if (!$isHistoryTick) {
                        StockTickColumns::write($conn, $stocks);
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
                    $lap('snapshots');
                }

                // Save Macro Report Snapshot once a "Simulation Quarter"
                if ($tickCount % $quarterlyInterval === 0) {
                    // Rolled BEFORE the snapshot, so the decomposition of the quarter that just ended is
                    // in hand to go into that quarter's own row rather than the next one's.
                    $this->gapProbe->rollWindow();
                    $this->diagnosticsProbe->rollWindow();
                    $closedQuarter = $this->gapProbe->snapshot()['previous'];
                    $closedDiagnostics = $this->diagnosticsProbe->snapshot()['previous'];

                    // A ticker that starts mid-quarter closes a PARTIAL first window, and a partial window
                    // is not a quarter: its contributions are short while its annualised rates divide by a
                    // near-zero horizon, so one start produced a +7.0pp/yr residual against a ±1.2 range.
                    // Dropped rather than corrected, because the reading it would carry is "this quarter
                    // was extraordinary" and it was only brief.
                    $wholeQuarter = $closedQuarter !== null && $closedQuarter['ticks'] >= $quarterlyInterval;

                    // One row carrying the state vector and what moved it, which is what makes macro_report
                    // readable as a run rather than as a series of unexplained levels. The decomposition
                    // needs no time of its own: it is in the row whose total_time already stamps it.
                    $this->macroEngine->recordMacroSnapshot($macroState, $conn, new \App\Service\Macro\Recorder\QuarterRecord(
                        gapChannels: $wholeQuarter ? $closedQuarter : null,
                        diagnostics: $wholeQuarter ? $closedDiagnostics : null,
                        configFingerprint: $this->configFingerprint->fingerprint(),
                        ticksPerYear: $this->ticksPerYear,
                    ));
                }

                // The clock goes in with the tick, on the tick's own connection: a tick that rolls back
                // rolls its clock back too, and one that commits cannot lose the fact that it happened.
                $this->simulationClock->advance($conn, $tickCount, $macroState->totalTime);

                $this->entityManager->commit();
                $lap('commit');

                // Drained here, with the tick's locks released: the worker is free to take the rows it needs.
                foreach ($pendingLimitOrderChecks as $pendingTicker => $pendingPrice) {
                    $this->messageBus->dispatch(new \App\Message\ProcessLimitOrdersMessage($pendingTicker, $pendingPrice));
                }
                $lap('limit dispatch');

                // The margin sweep runs AFTER the tick commits, never inside it. A forced sale goes through
                // the ordinary execution path, which opens its own transaction, and nesting one account's
                // liquidation inside the whole market's tick would couple the two.
                if ($tickCount % $snapshotInterval === 0) {
                    $this->liquidationService->sweep($macroState->policyRate, $snapshotInterval * $dt);
                    $lap('margin sweep');
                }
            } catch (RetryableException $e) {
                // A deadlock or a lock-wait timeout is not a fault in the tick: InnoDB saw two transactions
                // cross, picked one and rolled it back, and the state it left is consistent. What it costs
                // is THIS tick, and the next one recomputes everything from the rows on disk -- so the
                // handling is to take the loss and carry on, never the generic path's five-second pause,
                // which at a 10 ms interval freezes the market for 500 ticks over a fault that is already
                // over. The tick is not re-run in place either: the operator pass and the option hedge run
                // before the transaction opens, against the entity graph the clear() below discards, so a
                // second pass through the body would drop their work rather than repeat it.
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }

                $output->writeln("<comment>Tick {$tickCount} lost to lock contention: " . $e->getMessage() . "</comment>");

                if (!$this->entityManager->isOpen()) {
                    $output->writeln("<error>EntityManager is closed. Exiting Ticker to reboot...</error>");
                    return Command::FAILURE;
                }

                $this->entityManager->clear();
                $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
                $indexFunds = $this->loadIndexFunds();
                $bonds = $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]);
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
                $indexFunds = $this->loadIndexFunds();
                $bonds = $this->entityManager->getRepository(Bond::class)->findBy(['status' => Bond::STATUS_ACTIVE]);

                sleep(5);
            }

            // The tick that just ran, kept before the counter moves on. Every diagnostic below names THIS
            // number: the warnings used to print the incremented counter, so a lag report and the sweep
            // counts for the same tick disagreed by one and every reading of a log started by subtracting it.
            $ranTick = $tickCount;
            $tickCount++;

            // Reported on its own line rather than folded into the lag warning below: a dropped write has
            // nothing to do with how long the tick took, and the warning only ever fires on an overrun.
            $unpersisted = $this->writeAudit->summary();

            if ($unpersisted !== '') {
                $output->writeln("<error>⚠️ Unpersisted writes on tick {$ranTick}: {$unpersisted} — repaired, but a mutation is missing its persist().</error>");
            }

            // Stop the stopwatch and calculate how long the work took
            $executionTimeSec = microtime(true) - $tickStartTime;
            $executionTimeUs = (int) ($executionTimeSec * 1000000);

            // LAG WARNING
            if ($executionTimeUs > $this->tickIntervalUs) {
                $overtimeMs = ($executionTimeUs - $this->tickIntervalUs) / 1000;
                arsort($phases);
                $breakdown = implode(', ', array_map(
                    static fn (string $name, float $ms): string => sprintf('%s %.1f', $name, $ms),
                    array_keys(array_slice($phases, 0, self::LAG_PHASES_SHOWN, true)),
                    array_slice($phases, 0, self::LAG_PHASES_SHOWN, true)
                ));

                // Measure identity map size to distinguish flush overhead from write volume.
                $managed = $this->entityManager->getUnitOfWork()->size();
                $written = $this->flushProfiler->summary();
                $wrote = $written === '' ? 'wrote nothing' : $written;

                $output->writeln("<comment>⚠️ Lag Spike: Tick {$ranTick} took too long! Dropped behind by " . round($overtimeMs, 2) . "ms ({$breakdown} | map {$managed} | {$wrote})</comment>");
            }

            // Track steady-state execution time distribution across phase windows.
            $executionMs = $executionTimeSec * 1000.0;
            $windowTicks++;
            $windowTotalMs += $executionMs;
            $windowMaxMs = max($windowMaxMs, $executionMs);
            $windowOverruns += $executionTimeUs > $this->tickIntervalUs ? 1 : 0;

            foreach ($phases as $name => $ms) {
                $phaseTotals[$name] = ($phaseTotals[$name] ?? 0.0) + $ms;
            }

            if ($windowTicks >= self::PHASE_REPORT_INTERVAL_TICKS) {
                arsort($phaseTotals);
                $budgetMs = $this->tickIntervalUs / 1000.0;
                $perTick = implode(', ', array_map(
                    static fn (string $name, float $ms): string => sprintf('%s %.2f', $name, $ms / $windowTicks),
                    array_keys($phaseTotals),
                    $phaseTotals
                ));

                $output->writeln(sprintf(
                    '<info>⏱  Tick %d | %d ticks: mean %.1fms of %.1fms budget, max %.1fms, %d over (%.0f%%)</info>',
                    $tickCount,
                    $windowTicks,
                    $windowTotalMs / $windowTicks,
                    $budgetMs,
                    $windowMaxMs,
                    $windowOverruns,
                    100.0 * $windowOverruns / $windowTicks
                ));
                $output->writeln('<info>   ms/tick: ' . $perTick . '</info>');

                $phaseTotals = [];
                $windowTicks = 0;
                $windowOverruns = 0;
                $windowMaxMs = 0.0;
                $windowTotalMs = 0.0;
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
