<?php

namespace App\Command;

use App\Entity\Stock;
use App\Entity\Etf;
use App\Service\Market\StockTracker;
use App\Service\Market\EtfTracker;
use App\Service\Market\IndexCommittee;
use App\Service\Market\IndexFundAccountant;
use App\Service\Math\FinancialConstants;
use App\Service\Market\Index\MarketIndex;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketOperator;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\ShockEvent;
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
        private IndexCommittee $indexCommittee,
        private IndexFundAccountant $fundAccountant,
        private MacroEngine $macroEngine,
        private MarketOperator $marketOperator,
        private MarketEventPublisher $marketEvent,
        private NarrativeEngine $narrativeEngine,
        private \Redis $redis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('years', InputArgument::REQUIRED, 'Number of years to simulate');
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
        $quarterlyInterval = (int) max(1, self::TICKS_PER_YEAR / 4);

        $output->writeln("<info>Initializing Aerie God Engine...</info>");
        $output->writeln("Target: <comment>{$years} Years</comment> ({$totalTicks} ticks)");

        // Initialize Symfony ProgressBar
        $progressBar = new ProgressBar($output, $totalTicks);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% | %memory:6s%');
        $progressBar->start();





        // Load the stocks into RAM initially
        $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
        $indexFunds = $this->loadIndexFunds();
        $conn = $this->entityManager->getConnection();

        for ($tick = 1; $tick <= $totalTicks; $tick++) {

            $macroState = $this->macroEngine->updateMacroState($dt);

            $isHistoryTick = ($tick % 30 === 0);

            // Pass the $stocks array in
            $result = $this->stockTracker->updateStocks($stocks, $dt, $isHistoryTick, $macroState, $tick, self::TICKS_PER_YEAR);

            // Every published index, on the same calendar and the same arithmetic the live ticker uses.
            // Striking a single index off the whole board's capitalisation was generating fast-forward
            // history that no index actually had: the headline level ignored its own membership, and the
            // other three had no history at all until the ticker was next started.
            if (IndexCommittee::isReconstitutionTick($tick, self::TICKS_PER_YEAR)) {
                foreach (MarketIndex::cases() as $index) {
                    $fund = $indexFunds[$index->value] ?? null;
                    $reconstitution = $this->indexCommittee->reconstitute(
                        $index,
                        $stocks,
                        $tick,
                        // The level behind the fund's price, stripped of the fund's own fee drag and
                        // undistributed income; see MarketTickerCommand for why the price will not do.
                        $fund?->getIndexLevel()
                    );

                    // Fast-forward history has to carry the fund's costs or it prints a past the live
                    // market could not have produced: every quarter of it would be a free rebalance.
                    if ($fund !== null) {
                        $this->fundAccountant->chargeRebalance(
                            $fund,
                            $reconstitution['trading_cost'],
                            $reconstitution['level']
                        );
                    }
                }
            }

            $isDistributionTick = $tick > 0
                && $tick % max(1, intdiv(self::TICKS_PER_YEAR, FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR)) === 0;

            foreach ($indexFunds as $ticker => $fund) {
                $index = MarketIndex::from($ticker);

                // Paid before the strike, so the published price is already ex the cash that left.
                if ($isDistributionTick) {
                    $this->fundAccountant->distribute($fund, new \DateTime());
                }

                $this->etfTracker->updateIndex(
                    $this->indexCommittee->memberCapitalisation($index, $result['float_caps']),
                    $isHistoryTick,
                    $ticker,
                    $fund,
                    $macroState->totalTime,
                    $this->indexCommittee->memberDividendPoints($index, $result['dividend_points']),
                    $dt
                );
            }

            if ($macroState->eventType !== null) {
                $lbi = $indexFunds[MarketIndex::benchmark()->value] ?? null;
                if ($lbi) {
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
                    $shockPct = in_array($macroState->eventType, [ShockEvent::TITAN_INTERVENTION, ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT]) ? 5.0 : -5.0;
                    $this->marketEvent->publish($lbi, 'SHOCK', $desc, $shockPct);
                }
            }

            // Save Macro Report Snapshot once a "Simulation Quarter"
            if ($tick % $quarterlyInterval === 0) {
                $this->macroEngine->recordMacroSnapshot($macroState, $conn);
            }

            // Batch flush every 1200 ticks to save RAM
            if ($tick % 365 === 0) {
                \App\Service\Market\StockTickColumns::write($this->entityManager->getConnection(), $stocks);
                $this->entityManager->flush();
                $this->entityManager->clear(); // Wipes RAM 

                gc_collect_cycles();

                $stocks = $this->entityManager->getRepository(Stock::class)->findAll();
                // The funds were detached with everything else; a stale one would be written to and never
                // flushed, so the levels would silently stop advancing after the first year.
                $indexFunds = $this->loadIndexFunds();

                $this->redis->set('stocks_live_data', json_encode($result['updates']));
            }

            if ($tick % 100 === 0) {
                $this->marketOperator->enforceMarketStability($stocks, $macroState);
            }


            $progressBar->advance();
        }

        \App\Service\Market\StockTickColumns::write($this->entityManager->getConnection(), $stocks);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $progressBar->finish();
        $output->writeln("");
        $output->writeln("<info>Simulation complete! The timeline has been altered.</info>");

        return Command::SUCCESS;
    }
}
