<?php

declare(strict_types=1);

use App\DTO\BoardReadingDTO;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\FailureSweep;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroStateStore;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Math\TimeSeries;
use App\Service\Politics\PoliticsEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Whole-board headless run on the PRODUCTION service graph: real container, in-memory Redis
 * (var/harness/bootstrap.php), stub EM, the board seeded by app:market-seed, then the live ticker's per-tick order
 * (macro, the Diet if POLITICS=1, failure sweep every 1/24 year, stock update), the macro fed every board input
 * MarketTickerCommand feeds it, one tick behind: capitalisation, float, price return, dividends, net issuance, stamp
 * duty, strategic-stake cash and the bank levy.
 *
 * A topic harness extends this class and overrides the hooks it needs; every default leaves the run as it is. Hooks
 * read the run state below. Env, standard outputs and the hook order: var/harness/lib/README.md.
 */
abstract class BoardRun extends KernelTestCase
{
    protected int $seed = 1;
    protected float $years = 1.0;
    protected int $tpy = 360;
    protected float $dt = 1.0 / 360;
    protected int $ticks = 0;
    /** The tick being run, 1-based; the number of ticks run once the loop is over. */
    protected int $tick = 0;
    /** @var array<string, Stock> the seeded board by ticker, in seed order */
    protected array $stocks = [];
    /** @var list<Stock> */
    protected array $list = [];
    /** @var array<string, float> */
    protected array $seedPrice = [];
    /** The macro the board reads this tick (after macroFor()). */
    protected ?MacroStateDTO $currentMacro = null;
    protected float $currentTime = 0.0;
    /** What the Diet returned this tick; null without POLITICS=1. */
    protected ?PoliticsStateDTO $politicsState = null;
    /** The board as the macro reads it next tick. */
    protected BoardReadingDTO $reading;
    /** @var array<string, float> bankrupt ticker => the year it failed */
    protected array $dead = [];
    protected ?DebtEngine $debtEngine = null;
    protected ?StockTracker $tracker = null;

    /** @var list<object> */
    private array $persisted = [];
    private bool $capturing = true;
    /** @var array<string, list<array<string, mixed>>> */
    private array $quarters = [];
    /** @var array<string, list<array{t: float, d: string}>> */
    private array $events = [];
    /** @var array<string, float> */
    private array $replayLags = [];

    protected static function getKernelClass(): string
    {
        return HarnessKernel::class;
    }

    // --- Hooks ---

    /** After the seed and the services, before tick 1. */
    protected function onStart(): void {}

    /** The macro the board reads this tick, after the Diet and the recording; the recorded path is the macro's own. */
    protected function macroFor(MacroStateDTO $m): MacroStateDTO
    {
        return $m;
    }

    /** After the failure sweep, before the stock update. */
    protected function beforeStocks(): void {}

    /** @param array<string, mixed> $result StockTracker::updateStocks() for this tick */
    protected function afterStocks(array $result): void {}

    /**
     * The board as the macro reads it next tick. SIMCMD mirrors app:market-simulate, which feeds the macro no board at
     * all. Levels stand when not reported (as the ticker keeps the last); flows are this tick's.
     *
     * @param array<string, mixed> $result
     */
    protected function boardReading(BoardReadingDTO $reading, array $result): BoardReadingDTO
    {
        return getenv('SIMCMD') ? $reading : $reading->after($result);
    }

    /**
     * @param array<string, mixed> $row the standard MACRO_OUT row
     * @return array<string, mixed> the row written
     */
    protected function macroRow(array $row, MacroStateDTO $m): array
    {
        return $row;
    }

    /** False drops the standard quarter rows from the record; reportRow() still sees every report. */
    protected function quarterRows(): bool
    {
        return true;
    }

    /**
     * Every quarterly report, as it is persisted.
     *
     * @param array<string, mixed> $row the standard quarter row ([] when quarterRows() is false)
     * @return array<string, mixed> the row kept
     */
    protected function reportRow(array $row, CorporateReport $report): array
    {
        return $row;
    }

    /** Every stock event persisted after the seed. */
    protected function onEvent(StockEvent $event): void {}

    /**
     * @param array<string, mixed> $record the standard OUT record
     * @return array<string, mixed> the record written
     */
    protected function record(array $record): array
    {
        return $record;
    }

    /** After the last tick and the OUT record, before RESET_PROBE reopens the board. */
    protected function onEnd(): void {}

    // --- The run ---

    final public function testRun(): void
    {
        $this->years = (float) (getenv('YEARS') ?: 1);
        $this->tpy = (int) (getenv('TPY') ?: 360);
        $this->seed = (int) (getenv('SEED') ?: 1);
        $tpy = $this->tpy;
        $seed = $this->seed;
        $out = getenv('OUT') ?: null;
        $recordPath = getenv('MACRO_RECORD') ?: null;
        $replayPath = getenv('MACRO_REPLAY') ?: null;

        mt_srand($seed);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
        $_SERVER['KERNEL_CLASS'] = 'App\Kernel';
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $c = static::getContainer();
        $c->set('doctrine.orm.default_entity_manager', $this->buildEntityManager());

        // --- Seed exactly as production does ---
        $app = new Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        ob_start();
        $seedTester = new CommandTester($app->find('app:market-seed'));
        $seedStatus = $seedTester->execute([]);
        ob_end_clean();
        $this->assertSame(0, $seedStatus, 'the seed command failed: ' . substr($seedTester->getDisplay(), -600));

        $stocks = [];
        $id = 1;
        foreach ($this->persisted as $e) {
            if ($e instanceof Stock) {
                (new ReflectionProperty(Stock::class, 'id'))->setValue($e, $id++);
                $stocks[$e->getTicker()] = $e;
            }
        }
        $this->persisted = [];
        $this->capturing = false;
        $this->assertNotEmpty($stocks, 'the seed command persisted no stocks');
        $this->stocks = $stocks;

        $seedMargin = [];
        $seedEquity = [];
        foreach ($stocks as $t => $s) {
            $this->seedPrice[$t] = (float) $s->getPrice();
            $seedMargin[$t] = (float) $s->getOperatingMargin();
            $seedEquity[$t] = (float) $s->getTotalEquity();
        }

        $macro = $c->get(MacroEngine::class);
        $macroStore = $c->get(MacroStateStore::class);
        $economy = $macroStore->load();
        $this->debtEngine = $c->get(DebtEngine::class);
        $tracker = $c->get(StockTracker::class);
        $this->tracker = $tracker;
        $sweep = $c->get(FailureSweep::class);
        // The Diet, as the ticker runs it: it reads this tick's economy and the economy reads its laws next tick.
        $politicsEngine = getenv('POLITICS') && $replayPath === null ? $c->get(PoliticsEngine::class) : null;
        $policy = $politicsEngine?->liveState()->policy();

        $list = array_values($stocks);
        $this->list = $list;
        $dt = 1.0 / $tpy;
        $this->dt = $dt;
        $ticks = (int) round($this->years * $tpy);
        $this->ticks = $ticks;
        // SIMCMD=1 mirrors app:market-simulate: no board cap fed to the macro, the operator every 100 ticks.
        $operatorInterval = getenv('SIMCMD') ? 100 : (int) max(1, $tpy / 24);
        $replay = $replayPath !== null ? unserialize((string) gzuncompress((string) file_get_contents($replayPath))) : null;
        $recorded = [];
        $this->reading = new BoardReadingDTO();
        $reorgs = [];
        $defaults = [];
        $t0 = microtime(true);

        // TARGET_OUT: what the stock page shows before the ticker has published a target, then the published
        // target against price and fair value at a few horizons.
        $targetProbe = [];
        $probeTicks = [1 => 'tick1', (int) round($tpy / 52) => '1w', (int) round($tpy / 12) => '1m', (int) round($tpy / 4) => '3m', (int) round($tpy / 2) => '6m', $tpy => '12m'];
        if (getenv('TARGET_OUT')) {
            $snapshots = $c->get(App\Service\View\CompanySnapshotBuilder::class);
            foreach ($stocks as $t => $s) {
                $page = (new ReflectionMethod($snapshots, 'analystTargets'))->invoke($snapshots, $s, new MacroStateDTO());
                $targetProbe[$t]['page0'] = ['px' => (float) $s->getPrice(), 'consensus' => (float) ($page['consensus'] ?? 0.0), 'upside' => (float) ($page['upside_pct'] ?? 0.0), 'published' => $s->getAnalystPriceTarget()];
            }
        }

        $this->onStart();

        for ($tick = 1; $tick <= $ticks; $tick++) {
            $this->tick = $tick;
            $m = $replay !== null ? $this->withDemandLags($replay[$tick - 1], $dt)
                : $macro->step($economy, $dt, $this->reading, $policy);
            if ($replay === null) {
                $macroStore->save($economy);
            }
            if ($politicsEngine !== null) {
                $this->politicsState = $politicsEngine->updatePolitics($m, $dt);
                $policy = $this->politicsState->policy();
            }
            if ($recordPath !== null) {
                $recorded[] = $m;
            }
            $m = $this->macroFor($m);
            $this->currentMacro = $m;
            $this->currentTime = $tick / $tpy;

            if ($tick % $operatorInterval === 0) {
                foreach ($list as $s) {
                    if (!$s->isBankrupt() && $s->isPaymentDefault()) {
                        $defaults[$s->getTicker()] = ($defaults[$s->getTicker()] ?? 0) + 1;
                    }
                }
                foreach ($sweep->sweep($list, $m) as $ev) {
                    $t = (string) ($ev['ticker'] ?? '');
                    if (!isset($stocks[$t])) {
                        continue;
                    }
                    if (($ev['type'] ?? '') === 'BANKRUPTCY') {
                        $this->dead[$t] = round($tick / $tpy, 2);
                    } elseif (($ev['type'] ?? '') === 'REORGANIZATION') {
                        $reorgs[] = ['t' => $t, 'year' => round($tick / $tpy, 2)];
                    }
                }
            }

            if ($tick === 1 && getenv('OPENING_OUT')) {
                $opening = [];
                foreach ($stocks as $t => $s) {
                    $opening[$t] = ['eps0' => (float) $s->getEarningsPerShare(), 'fv0x' => getenv('FVX') ? $this->fvRow($s) : [], 'px0' => (float) $s->getPrice(), 'sh0' => (float) $s->getSharesOutstanding(), 'industry' => $s->getIndustry(), 'fin' => App\Data\Company\Sectors::strategyFor($s->getIndustry())->isFinancial()];
                }
            }
            $this->beforeStocks();
            $result = $tracker->updateStocks($list, $dt, false, $m, $tick, $tpy);
            if (getenv('TARGET_OUT') && isset($probeTicks[$tick])) {
                foreach ($result['updates'] as $u) {
                    $target = (float) ($u['analyst_price_target'] ?? 0.0);
                    $targetProbe[$u['ticker']][$probeTicks[$tick]] = ['px' => (float) $u['price'], 'fv' => (float) $u['perceived_fair_value'], 'target' => $target, 'upside' => $u['price'] > 0 ? 100.0 * ($target / (float) $u['price'] - 1.0) : 0.0];
                }
                if ($tick === $tpy || $tick === $ticks) {
                    file_put_contents((string) getenv('TARGET_OUT'), json_encode($targetProbe));
                }
            }
            if ($tick === 1 && getenv('OPENING_OUT')) {
                foreach ($result['updates'] as $u) {
                    $opening[$u['ticker']]['fv1'] = (float) $u['perceived_fair_value'];
                    $opening[$u['ticker']]['px1'] = (float) $u['price'];
                    $opening[$u['ticker']]['sh1'] = (float) $stocks[$u['ticker']]->getSharesOutstanding();
                }
                file_put_contents((string) getenv('OPENING_OUT'), json_encode($opening));
            }
            $this->afterStocks($result);
            $this->reading = $this->boardReading($this->reading, $result);
            // MACRO_OUT: one macro row a quarter, for the macro-market loop.
            // FISCAL=1 adds the primary deficit and the net-tax rate, and a founding row at tick 1.
            if (getenv('MACRO_OUT') && ($tick % max(1, intdiv($tpy, 4)) === 0 || ($tick === 1 && getenv('FISCAL')))) {
                file_put_contents((string) getenv('MACRO_OUT'), json_encode($this->macroRow($this->standardMacroRow($m), $m)) . "\n", FILE_APPEND);
            }
        }

        if ($recordPath !== null) {
            file_put_contents($recordPath, gzcompress(serialize($recorded), 6));
        }

        $final = [];
        foreach ($stocks as $t => $s) {
            $final[$t] = [
                'dead' => $s->isBankrupt(),
                'om_seed' => round($seedMargin[$t], 4),
                'eq_x' => round((float) $s->getTotalEquity() / max(1.0, $seedEquity[$t]), 3),
                'px' => (float) $s->getPrice(),
                'vol' => (float) $s->getCurrentVolatility(),
                'q' => $this->quarters[$t] ?? [],
            ];
        }

        $record = $this->record([
            'seed' => $seed,
            'years' => $this->years,
            'tpy' => $tpy,
            'secs' => round(microtime(true) - $t0, 1),
            'dead' => $this->dead,
            'reorgs' => $reorgs,
            'defaults' => $defaults,
            'final' => $final,
            'events' => $this->events,
        ]);
        if ($out !== null) {
            $json = json_encode($record);
            if ($json === false) {
                // A NaN or INF somewhere: keep the record, substituting 0, and say so.
                $record['json_error'] = json_last_error_msg();
                $json = (string) json_encode($record, JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            file_put_contents($out, $json . "\n", FILE_APPEND);
        }
        $this->onEnd();
        fwrite(STDERR, sprintf("seed %d: %d ticks in %.1fs, deaths %s, reorgs %d\n", $seed, $ticks, microtime(true) - $t0, json_encode($this->dead), count($reorgs)));

        // RESET_PROBE: reopen the traded board exactly as app:market-reset does, one row at a time in seed order.
        if (getenv('RESET_PROBE')) {
            $builder = $c->get(App\Service\Market\Pricing\OpeningBoardBuilder::class);
            $openingMacro = new MacroStateDTO();
            $spheres = [];
            foreach (App\Data\Company\InitialMarket::STOCKS as $row) {
                $s = $stocks[$row['ticker']];
                $s->resetToUnseeded();
                $sphere = $builder->open($s, $row, $openingMacro);
                if ($sphere !== null) {
                    $spheres[] = $sphere;
                }
            }
            $builder->openSpheres($spheres, $stocks, $openingMacro);
            $probe = [];
            foreach ($stocks as $t => $s) {
                $probe[$t] = ['seed' => $this->seedPrice[$t], 'reset' => (float) $s->getPrice()];
            }
            file_put_contents((string) getenv('RESET_PROBE'), json_encode($probe));
        }
        $this->assertTrue(true);
    }

    // --- Standard rows ---

    /**
     * MACRO_OUT: the macro-market loop (gap, cap, float, fund), the laws, debt. BOARD_NI=1 adds the board's trailing
     * net income; FISCAL=1 the primary deficit, the net-tax rate and the spending index.
     *
     * @return array<string, mixed>
     */
    private function standardMacroRow(MacroStateDTO $m): array
    {
        return [
            'seed' => $this->seed, 't' => round($this->tick / $this->tpy, 3), 'gap' => $m->outputGap, 'cap' => $this->reading->equityMarketCap,
            'nominal_gdp' => $m->nominalGdpIndex, 'ewr' => $m->equityWealthRatio, 'ewt' => $m->equityWealthTrend,
            'fin' => $m->financeOutputGap ?? 0.0, 'nx' => $m->netExportGap ?? 0.0, 'supply' => $m->productivitySupplyGap,
            'credit_gap' => $m->creditToGdpGap, 'policy' => $m->policyRate, 'infl' => $m->inflation, 'u' => $m->unemploymentRate,
            'fx' => $m->exchangeRateIndex, 'deal' => $m->dealActivityIndex, 'vol' => $m->marketVolatility, 'erp' => $m->equityRiskPremium,
            'fund' => $m->sovereignFundToGdp, 'fgap' => $m->foreignOutputGap, 'crisis' => $m->creditCrisisDrag,
            'allied' => $m->alliedDefenseSpendingIndexEma, 'oil' => $m->energyPriceIndexEma,
            'float' => $m->boardFloatCap, 'fund_own' => $m->sovereignFundOwnershipShare,
            'corp_tax_shift' => $m->corporateTaxPolicyShift, 'div_tax' => $m->dividendTaxRate, 'home_law' => $m->reserveHomeEquityShare,
            'debt' => $m->sovereignDebtToGdp, 'spend_law' => $m->publicSpendingShare,
            'net_debt' => $m->sovereignNetDebtToGdp,
            'crisis_at' => $m->lastCreditCrisisAt,
            'spread' => $m->sovereignRiskSpread, 'tax_rate' => $m->corporateTaxRate,
        ] + (getenv('BOARD_NI') ? $this->boardNetIncome() + ['gap_ema' => $m->outputGapEma, 'net_tax' => $m->netTaxRate ?? null] : [])
            + (getenv('FISCAL') ? ['primary' => $m->primaryDeficitToGdp, 'net_tax' => $m->netTaxRate, 'spend_idx' => $m->governmentSpendingIndex] : []);
    }

    /**
     * BOARD_NI=1: the board's trailing net income (sum of Stock::totalNetIncome, the TTM figure EarningsEngine sets)
     * over firms not bankrupt, and how many firms it covers.
     *
     * @return array{board_tni: float, board_n: int}
     */
    protected function boardNetIncome(): array
    {
        $sum = 0.0;
        $n = 0;
        foreach ($this->list as $s) {
            if (!$s->isBankrupt()) {
                $sum += (float) $s->getTotalNetIncome();
                $n++;
            }
        }

        return ['board_tni' => $sum, 'board_n' => $n];
    }

    /**
     * One quarterly report as a named row, with the macro readings it was filed under. HEALTH=1 and FVX=1 add their
     * probes after the stock's own fields.
     *
     * @return array<string, mixed>
     */
    protected function quarterRow(CorporateReport $report): array
    {
        $m = $this->currentMacro;
        $kpis = $report->getReportedKpis() ?? [];

        return [
            't' => round($this->currentTime, 3),
            'rev' => (float) $report->getRevenue(),
            'exp' => (float) (($report->getStock()->getEarningsMomentumZ() ?? [])[EarningsEngine::STATE_LAST_EXPECTED_REVENUE] ?? 0.0),
            'ni' => (float) $report->getNetIncome(),
            'om' => (float) $report->getOperatingMargin(),
            'ebit' => (float) $report->getEbit(),
            'eq' => (float) $report->getEquity(),
            'cash' => (float) $report->getTreasury(),
            'debt' => (float) $report->getTotalDebt(),
            'div' => (float) $report->getDividendPaid(),
            'bb' => (float) $report->getStockBuybacks(),
            'capex' => (float) $report->getCapitalExpenditures(),
            'dep' => (float) $report->getDepreciation(),
            'gw' => (float) $report->getGoodwill(),
            'ppe' => (float) $report->getNetPpe(),
            'ta' => (float) $report->getTotalAssets(),
            'ea' => (float) $report->getEarningAssets(),
            'fin' => $report->getStock()->getCustomerDeposits() !== null && (float) $report->getStock()->getCustomerDeposits() > 0.0,
            'rating' => $report->getStock()->getCreditRating(),
            'int' => (float) $report->getInterestExpense(),
            'ebitda' => (float) $report->getEbitda(),
            'spread' => (float) $report->getStock()->getCreditSpread(),
            'rev_drawn' => (float) $report->getStock()->getRevolverDrawn(),
            'rev_commit' => (float) $report->getStock()->getRevolverCommitment(),
            'wd' => (float) $report->getStock()->getWholesaleDebt(),
            'qid' => $report->getStock()->getQuartersInDefault(),
            'price' => (float) $report->getStock()->getPrice(),
            'shares' => (float) $report->getStock()->getSharesOutstanding(),
            'eps' => (float) $report->getStock()->getEarningsPerShare(),
            'apt' => (float) $report->getStock()->getAnalystPriceTarget(),
            'cvol' => (float) $report->getStock()->getCurrentVolatility(),
            'aris' => $report->getStock()->isDividendAristocrat(),
        ] + $this->healthRow($report->getStock()) + $this->fvRow($report->getStock()) + [
            'tni' => (float) $report->getStock()->getTotalNetIncome(),
            'crude' => $m?->energyPriceIndexEma ?? 0.0,
            'crack' => $m?->refiningCrackSpreadEma ?? 0.0,
            'gas' => $m?->naturalGasPriceIndexEma ?? 0.0,
            'metals' => $m?->industrialMetalsIndexEma ?? 0.0,
            'gold' => $m?->goldPriceIndexEma ?? 0.0,
            'power' => $m?->wholesalePowerPriceIndexEma ?? 0.0,
            'agri' => $m?->agriculturalCommodityIndexEma ?? 0.0,
            'freight' => $m?->freightRateIndexEma ?? 0.0,
            'gap' => $m?->outputGapEma ?? 0.0,
            'rwg' => $m?->realWageGap ?? 0.0,
            'wage' => $m?->wageGrowthEma ?? 0.0,
            'hedge' => (float) ($kpis['hedge_gain'] ?? 0.0),
            'capture' => (float) ($kpis['capture_rate'] ?? 0.0),
            'power_kpi' => (float) ($kpis['power_price_index'] ?? 0.0),
        ];
    }

    /**
     * FVX=1: the fair value the market would strike on the stock right now (FairValue::fundamentalStateFor()), beside
     * the inputs it is struck on, so a move in it can be read against the hurdle, the return, growth and accruals.
     *
     * @return array<string, float>
     */
    protected function fvRow(Stock $stock): array
    {
        if (!getenv('FVX') || $this->debtEngine === null || $this->currentMacro === null || $this->tracker === null) {
            return [];
        }
        $m = $this->currentMacro;
        $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $m);
        $ledger = (new ReflectionProperty(StockTracker::class, 'anchorStakes'))->getValue($this->tracker);
        $ctx = App\DTO\MarketPricingContext::forStock($stock, $m, $h, $ledger);
        $fs = App\Service\Market\Pricing\FairValue::fundamentalStateFor($ctx);

        return ['x_rf' => (float) ($m->policyRate ?? 0.0), 'x_erp' => (float) ($m->equityRiskPremium ?? 0.0), 'x_gap' => (float) ($m->outputGap ?? 0.0),
            'x_infl' => (float) ($m->inflation ?? 0.0), 'x_beta' => $ctx->beta, 'x_coe' => $ctx->liveCostOfEquity, 'x_roicTtm' => $ctx->trailingReturn ?? ($ctx->roicTtm ?? null),
            'x_roicBase' => $ctx->baselineRoic, 'x_longRun' => (float) ($ctx->longRunReturn ?? 0.0), 'x_sec' => $ctx->secularGrowth,
            'x_payout' => $ctx->targetPayoutRatio, 'x_sectorPE' => $ctx->baselineIndustryPE, 'x_acc' => $ctx->accrualsRatio, 'x_eps' => (float) $stock->getEarningsPerShare(),
            'x_vol' => $ctx->currentVolatility, 'x_bvps' => $ctx->bookValuePerShare, 'x_icps' => $ctx->investedCapitalPerShare, 'x_rps' => $ctx->revenuePerShare,
            'x_fv' => (float) $fs['perceived_fair_value'], 'x_pull' => (float) $fs['dynamic_reversion'], 'x_policy' => (float) ($fs['policy_repricing'] ?? 1.0),
            'x_growthAn' => (float) $fs['analyst_targets']['growth_analyst'], 'x_valueAn' => (float) $fs['analyst_targets']['value_analyst'],
            'x_incomeAn' => (float) $fs['analyst_targets']['income_analyst']];
    }

    /**
     * HEALTH=1: the trailing debt-health reading the treasury acts on.
     *
     * @return array<string, float|bool>
     */
    protected function healthRow(Stock $stock): array
    {
        if ($this->debtEngine === null || $this->currentMacro === null || !getenv('HEALTH')) {
            return [];
        }
        $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $this->currentMacro);

        return ['uL' => $h->isUnderLeveraged, 'ndE' => $h->netDebtToEbitda, 'covL' => $h->ebitdaCovenantLimit, 'head' => $h->hasLeverageHeadroom,
            'tol' => $h->debtTolerance, 'icr' => $h->interestCoverage, 'can' => $h->canIssueDebt, 'hEbitda' => $h->rawMetrics->ebitda, 'hEbit' => $h->rawMetrics->ebit];
    }

    /**
     * $fields placed right after $key in $row (at the end when $key is absent), so a topic's columns sit beside the
     * ones they belong with.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected static function insertAfter(array $row, string $key, array $fields): array
    {
        $at = array_search($key, array_keys($row), true);
        if ($at === false) {
            return $row + $fields;
        }

        return array_slice($row, 0, $at + 1, true) + $fields + array_slice($row, $at + 1, null, true);
    }

    // --- Plumbing ---

    /**
     * A recording predating the output gap's published lags gets them filtered off its own gap path, as the macro
     * would.
     */
    private function withDemandLags(MacroStateDTO $recorded, float $dt): MacroStateDTO
    {
        $vars = (fn(): array => get_object_vars($this))->call($recorded);
        foreach (App\Service\Macro\Subsystem\Aggregate\MovingAverages::DEMAND_TRANSMISSION_LAGS as $field => $lagYears) {
            $this->replayLags[$field] = TimeSeries::calculateDistributedLag($this->replayLags[$field] ?? App\Data\Macro\MacroFieldRegistry::defaults()[$field], $vars['outputGapEma'], $dt, $lagYears);
        }

        return new MacroStateDTO(...array_merge($vars, $this->replayLags));
    }

    private function captureReport(CorporateReport $report): void
    {
        if (getenv('DRIVERS_OUT')) {
            file_put_contents((string) getenv('DRIVERS_OUT'), json_encode(['t' => round($this->currentTime, 3), 'ticker' => $report->getStock()->getTicker(), 'streams' => $report->getStreamDetails()]) . "\n", FILE_APPEND);
        }
        $keep = $this->quarterRows();
        $row = $this->reportRow($keep ? $this->quarterRow($report) : [], $report);
        if ($keep) {
            $this->quarters[$report->getStock()->getTicker()][] = $row;
        }
    }

    private function buildEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            if ($e instanceof CorporateReport) {
                $this->captureReport($e);
                return;
            }
            if ($e instanceof StockEvent && !$this->capturing) {
                $this->onEvent($e);
                // Treasury distress lines ride inside the earnings description as bullets: keep only those.
                foreach (preg_split('/\n/', (string) $e->getDescription()) ?: [] as $line) {
                    if (preg_match('/borrow|revolv|bond market|stave off|repay|default|record of dividend|Chapter|liquidat|rescue|covenant|restructur|in bonds for|Deployed/i', $line)) {
                        $this->events[$e->getStock()->getTicker()][] = ['t' => round($this->currentTime, 3), 'd' => substr(ltrim($line, "• "), 0, 160)];
                    }
                }
                return;
            }
            if ($this->capturing) {
                $this->persisted[] = $e;
            }
        });
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));

        $repositories = [];
        $em->method('getRepository')->willReturnCallback(function (string $class) use (&$repositories) {
            if (!isset($repositories[$class])) {
                $repositoryClass = EntityRepository::class;
                foreach ((new ReflectionClass($class))->getAttributes(Doctrine\ORM\Mapping\Entity::class) as $attribute) {
                    $repositoryClass = $attribute->newInstance()->repositoryClass ?? EntityRepository::class;
                }
                $repositories[$class] = $this->createStub($repositoryClass);
            }

            return $repositories[$class];
        });

        return $em;
    }
}
