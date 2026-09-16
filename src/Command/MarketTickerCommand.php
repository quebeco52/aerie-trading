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
use App\Service\Market\TreasuryAuctionService;
use App\Service\Market\EtfTracker;
use App\Service\Market\IndexFundAccountant;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketOperator;
use App\Service\User\Portfolio;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\District\DistrictRoster;
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
        return max(1, (int) round(($ticksPerYear / $perYear) / self::historyIntervalTicks($ticksPerYear)));
    }

    /** Whether a history bar re-reads the working set. */
    public static function isReloadBar(int $bar, int $ticksPerYear): bool
    {
        return $bar % self::reloadIntervalBars($ticksPerYear) === 0;
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
        private \App\Service\Market\ForcedLiquidationService $liquidationService,
        private \App\EventListener\FlushProfiler $flushProfiler,
        private SimulationClockService $simulationClock,
        private OptionChainService $optionChain,
        private TreasuryAuctionService $treasuryAuction,
        private MacroEngine $macroEngine,
        private MarketOperator $marketOperator,
        private Portfolio $portfolio,
        private \Redis $redis,
        private NarrativeEngine $narrativeEngine,
        private MarketEventPublisher $marketEvent,
        private DistrictRoster $districtRoster,
        private \App\Service\Market\OptionDeskService $optionDesk,
        private CorporateBondDesk $corporateBondDesk,
        private IndexCommittee $indexCommittee,
        private IndexFundAccountant $fundAccountant,
        private \Symfony\Component\Messenger\MessageBusInterface $messageBus,

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

        // Where the simulation actually got to, from the database rather than the cache. The option chain is
        // handed over as a witness: a listed expiry serial proves the clock once stood at least that far, so
        // a cache that has lost time cannot talk the simulation into living the same weeks twice.
        $furthestSerial = $this->optionChain->furthestListedSerial();
        $clock = $this->simulationClock->resume(
            (int) ($this->redis->get('simulation_tick_count') ?: 0),
            $this->macroEngine->getLiveState()->totalTime,
            $furthestSerial === null ? 0.0 : OptionChainService::earliestTimeFor($furthestSerial)
        );

        $tickCount = $clock->getTickCount();

        // The macro state is cached in Redis and carries its own copy of the clock, so it is corrected to
        // the authoritative one before the first tick reads it.
        $this->macroEngine->alignClock($clock->getTotalTime());

        $output->writeln(sprintf(
            'Resuming at tick %d (simulation year %.4f).',
            $tickCount,
            $clock->getTotalTime()
        ));

        $historyInterval = self::historyIntervalTicks($this->ticksPerYear);
        $operatorInterval = (int) max(1, $this->ticksPerYear / 24);  // Operator audits once a game "month"
        $snapshotInterval = (int) max(1, $this->ticksPerYear / 52);  // Snapshots once a game "week"
        $quarterlyInterval = (int) max(1, $this->ticksPerYear / 4);   // Snapshots once a game "quarter"
        $auctionInterval = TreasuryAuctionService::auctionIntervalTicks($this->ticksPerYear);
        $optionSweepInterval = OptionDeskService::sweepIntervalTicks($this->ticksPerYear);
        $corporateIssuanceInterval = CorporateBondDesk::issuanceIntervalTicks($this->ticksPerYear);

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

        $lap = static function (string $name) use (&$phases, &$phaseStart): void {
            $now = hrtime(true);
            $phases[$name] = ($phases[$name] ?? 0.0) + (($now - $phaseStart) / 1e6);
            $phaseStart = $now;
        };

        while ($this->keepRunning) {

            $tickStartTime = microtime(true);
            $phases = [];
            $phaseStart = hrtime(true);
            $this->flushProfiler->reset();

            pcntl_signal_dispatch();

            if ($tickCount % 10 === 0) {
                $simDay = ($tickCount / $this->ticksPerYear) * 365;
                $output->writeln("Updating Market Prices... (Day: " . number_format($simDay, 1) . ") [Tick: $tickCount]");
            }

            $macroState = $this->macroEngine->updateMacroState($dt);
            $lap('macro');

            if ($tickCount % $operatorInterval === 0) {
                $operatorEvents = $this->marketOperator->enforceMarketStability($stocks, $macroState);
            } else {
                $operatorEvents = [];
            }

            // The option desk re-hedges the move it has just seen, BEFORE the tick drains its order flow.
            // A desk observes a price and then trades; hedging the move it is itself causing would close an
            // algebraic loop inside one tick, and whether the market was stable would then depend on how
            // much open interest happened to be outstanding.
            //
            // On the history cadence rather than every tick. The hedge is gamma times the move since the
            // last one, so it telescopes: one hedge across the bar trades exactly what a hedge on each of
            // its ticks would have, and the flow still lands inside the same bar.
            if ($tickCount % $historyInterval === 0) {
                $this->optionDesk->hedge($stocks);
            }

            try {
                $this->entityManager->beginTransaction();

                $isHistoryTick = ($tickCount % $historyInterval === 0);

                $lap('operator+hedge');
                $result = $this->stockTracker->updateStocks($stocks, $dt, $isHistoryTick, $macroState, $tickCount, $this->ticksPerYear);
                $lap('stocks');
                $stockUpdates = $result['updates'];
                $totalMarketCap = $result['total_cap'];
                $marketVol = $result['market_vol'];
                $events = $result['events'];

                if ($macroState->eventType !== null) {
                    $benchmarkFund = $indexFunds[MarketIndex::benchmark()->value] ?? null;
                    if ($benchmarkFund !== null) {
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
                        $events[] = $this->marketEvent->publish($benchmarkFund, 'SHOCK', $desc, $shockPct);
                    }
                }

                if (!empty($operatorEvents)) {
                    $events = array_merge($events, $operatorEvents);
                }

                $lap('events');

                // Every index re-ranks the market on the same quarterly calendar. Passive money follows the
                // benchmark's membership rather than the whole board, so an addition is bought and a deletion
                // is sold by the agent population itself — see IndexCommittee for why the inclusion effect is
                // emergent here rather than scripted. A change is published as an event on the fund, which is
                // how it reaches the index page and the live feed through the one channel everything uses.
                if (IndexCommittee::isReconstitutionTick($tickCount, $this->ticksPerYear)) {
                    foreach (MarketIndex::cases() as $index) {
                        $fund = $indexFunds[$index->value] ?? null;
                        $reconstitution = $this->indexCommittee->reconstitute(
                            $index,
                            $stocks,
                            $tickCount,
                            // The LEVEL behind the fund's price, not the price. The price carries the
                            // fund's fee drag and its undistributed income, and restating a divisor
                            // against those would fold the fund's own costs into the index.
                            $fund?->getIndexLevel()
                        );

                        // The fund pays for the review before anything else is published about it. A
                        // re-weighting with no membership change is still a trade — the low-volatility fund
                        // restrikes every quarter — so the charge is taken above the early exit below, not
                        // inside the branch that only fires when the roster moved.
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

                // Each index is struck on its MEMBERS' float-adjusted capitalisation, not the whole board's
                // total. An index measures what it actually holds, and it weights on what a passive fund
                // could actually buy.
                //
                // An index whose fund is not on this market (a fund added to the seed after the market was
                // seeded, before a reset has created it) is not struck at all: the tracker would otherwise
                // look the missing row up on every tick and have nowhere to write the level.
                //
                // Each fund also collects the dividends its members just paid and is charged for the time
                // just elapsed, and pays out what it has collected once a quarter. An index is a price
                // index and knows nothing about any of that; a FUND that ignored it would lag the basket it
                // claims to hold by the whole dividend yield of the market, every year.
                $isDistributionTick = $tickCount > 0
                    && $tickCount % max(1, intdiv($this->ticksPerYear, FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR)) === 0;

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

                    $etfUpdates[] = $this->etfTracker->updateIndex(
                        $this->indexCommittee->memberCapitalisation($index, $result['float_caps']),
                        $isHistoryTick,
                        $index->value,
                        $fund,
                        $macroState->totalTime,
                        $this->indexCommittee->memberDividendPoints($index, $result['dividend_points']),
                        $dt
                    );
                }

                $lap('etfs');

                // The bond desk. Coupons, redemptions and the mark all happen inside the same tick
                // transaction as the equity book, so a crash mid-tick cannot leave a coupon credited
                // against a mark that was rolled back. The ladder is revalued and written on the history
                // cadence, in bulk, and quotes its last mark on the ticks between; the working set is
                // reloaded right after the flush, so no entity outlives the mark it was loaded with.
                // What each issuer's credit costs right now, taken off the working set the tick already
                // holds. Handing it to the tracker keeps the corporate ladder from loading a company per
                // bond just to read one number off it.
                $issuerSpreads = [];
                foreach ($stocks as $issuer) {
                    $issuerId = $issuer->getId();
                    if ($issuerId !== null) {
                        $issuerSpreads[$issuerId] = (float) $issuer->getDynamicCreditSpread();
                    }
                }

                // Marked on the history cadence, but only sampled into bond_history once a trading day:
                // see BOND_HISTORY_POINTS_PER_YEAR.
                $isBondHistoryTick = $isHistoryTick
                    && self::isBondHistoryBar(intdiv($tickCount, $historyInterval), $this->ticksPerYear);

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

                // The option desk's own sweep: settle what has expired, list what the market has moved into,
                // mark the chain, rebuild the public's book and re-measure what the desk is short.
                //
                // One SLICE of the market per pass, on its own slow interval. A full pass rewrites every
                // listed contract in the market, and wiring that to the history tick put forty-five thousand
                // option UPDATEs a second through the database for a mark nothing reads at that resolution.
                // See OptionDeskService for why nothing downstream wants it fresher.
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
                }

                $allUpdates = array_merge($stockUpdates, $etfUpdates, $bondResult['updates']);

                // What goes out on the wire. The bond ladder is only re-marked on the history tick, so on
                // every other tick its quotes are byte-for-byte what the browser already holds — and they
                // were two thirds of a payload that every connected browser received fifty times a second.
                // The chart buffer below still takes bonds on every tick: the short-range chart and the
                // change figure count buffer entries as ticks, and thinning one asset class would silently
                // stretch its month. The limit-order check follows the wire, since a mark that has not
                // moved cannot have crossed a resting price.
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
                        $this->messageBus->dispatch(new \App\Message\ProcessLimitOrdersMessage($ticker, $price));
                        continue;
                    }

                    $bounds = json_decode($boundsJson, true);
                    if ($price <= ($bounds['buy'] ?? 0.0) || $price >= ($bounds['sell'] ?? 999999999.0)) {
                        $this->messageBus->dispatch(new \App\Message\ProcessLimitOrdersMessage($ticker, $price));
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
                    $lap('history rows');

                    // Once a trading day, not every bar: see reloadIntervalBars(). Everything the tick
                    // itself changed has just been flushed, so nothing is lost to the clear.
                    if (self::isReloadBar(intdiv($tickCount, $historyInterval), $this->ticksPerYear)) {
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

                    // Trimming is idempotent, so once per bar keeps the buffer within a handful of entries
                    // of its size at half the commands; a trim on every push doubled the pipeline for
                    // nothing the reader could see.
                    if ($isHistoryTick) {
                        $pipeline->lTrim($cacheKey, 0, $redisBufferSize - 1);
                    }
                }

                $pipeline->exec();
                $lap('chart buffers');

                // Glasswater Row reconstitution: once a simulated quarter the street's roster is
                // re-ranked and frozen (DistrictRoster). Promotions and evictions are ordinary stock
                // events, so they reach the stock page, the district's event cards and the live
                // feed through the one channel everything else uses. The district page reloads its
                // frame when it sees the `district` key. Flushed here because the publisher only
                // persists and this tick may not otherwise flush.
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

                // Publish pub/sub updates
                $this->redis->publish('market_updates', json_encode([
                    'timestamp' => time(),
                    'tick' => $tickCount,
                    'stocks' => $published,
                    'events' => $events,
                    'district' => $districtReconstitution,
                    'market_vol' => $marketVol,
                    'economic_cycle' => $macroState->economicCycleLabel(),
                    'council_rate' => $macroState->policyRate,
                    'macro' => $macroState->toArray(),
                    'bond_curve' => $bondResult['curve'],
                ]));

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
                    $this->macroEngine->recordMacroSnapshot($macroState, $conn);
                }

                // The clock goes in with the tick, on the tick's own connection: a tick that rolls back
                // rolls its clock back too, and one that commits cannot lose the fact that it happened.
                $this->simulationClock->advance($conn, $tickCount, $macroState->totalTime);

                $this->entityManager->commit();
                $lap('commit');

                // The margin sweep runs AFTER the tick commits, never inside it. A forced sale goes through
                // the ordinary execution path, which opens its own transaction, and nesting one account's
                // liquidation inside the whole market's tick would couple the two.
                if ($tickCount % $snapshotInterval === 0) {
                    $this->liquidationService->sweep($macroState->policyRate, $snapshotInterval * $dt);
                    $lap('margin sweep');
                }
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

            $tickCount++;

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

                // How many entities the unit of work is carrying. A flush that is slow because the identity
                // map has filled up looks exactly like a flush that is slow because the tick genuinely wrote
                // a lot, and the two have opposite fixes; this is the number that tells them apart.
                $managed = $this->entityManager->getUnitOfWork()->size();
                $written = $this->flushProfiler->summary();
                $wrote = $written === '' ? 'wrote nothing' : $written;

                $output->writeln("<comment>⚠️ Lag Spike: Tick {$tickCount} took too long! Dropped behind by " . round($overtimeMs, 2) . "ms ({$breakdown} | map {$managed} | {$wrote})</comment>");
            }

            // STEADY STATE, not spikes. The warning above only ever fires on the overruns, so the log shows
            // the tail of the distribution and never its middle — and a subsystem that is slow on every
            // single tick never appears in it at all.
            //
            // The `opt:` entries are STAGES OF `options`, not phases beside it, so the list does not sum to
            // the tick: the measured mean on the line above is the total.
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
