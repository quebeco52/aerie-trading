<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketOperator;
use App\Service\Market\StockTracker;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Whole-board headless replay on the PRODUCTION service graph: real container, in-memory Redis, stub EM,
 * the live ticker's per-tick order (macro, operator audit every 1/24 year, stock update).
 *
 * PF_OUT=<json>: price-formation fork (var/harness/price_formation/RUN.md): quarterly board median log(P/FV), trailing P/E,
 * yields; per name-year tick log total-return sums, all ticks and earnings-report ticks. Skips the quarterly report rows.
 * Env: YEARS TPY SEED OUT=<jsonl> MACRO_RECORD=<gz> | MACRO_REPLAY=<gz>
 */
final class PriceFormationHarnessTest extends KernelTestCase
{
    /** @var list<object> */
    private array $persisted = [];
    private bool $capturing = true;
    /** @var array<string, list<array<string, float>>> */
    private array $quarters = [];
    private ?MacroStateDTO $currentMacro = null;
    private float $currentTime = 0.0;
    private ?\App\Service\Corporate\DebtEngine $debtEngine = null;
    private ?StockTracker $fvTracker = null;
    private ?\App\Service\Market\MarketEngine $fvEngine = null;
    /** @var array<string, list<array{t: float, d: string}>> */
    private array $events = [];
    /** @var array<string, float> PF: dividend per share of a report filed this tick */
    private array $reportedThisTick = [];
    /** @var array<string, list<array<string, float>>> PF_G: per-report flows */
    private array $pfFlows = [];
    /** @var array<string, array<int, array<string, float>>> PF_B: equity-bridge accumulators per ticker-year */
    public array $pfB = [];

    /** PF_B: add an equity flow (or a count) to the ticker's bucket for the current sim year. */
    public function pfBook(Stock $s, string $key, float $v): void
    {
        $y = (int) ceil($this->currentTime - 1e-9);
        $this->pfB[$s->getTicker()][$y][$key] = ($this->pfB[$s->getTicker()][$y][$key] ?? 0.0) + $v;
    }

    /** @param array<string, Stock> $stocks @return array<string, float> */
    private function pfEq(array $stocks): array
    {
        return array_map(static fn (Stock $s): float => (float) $s->getTotalEquity(), $stocks);
    }

    /** @param array<string, Stock> $stocks @param array<string, float> $e0 */
    private function pfDelta(array $stocks, array $e0, string $key): void
    {
        foreach ($stocks as $t => $s) {
            $d = (float) $s->getTotalEquity() - $e0[$t];
            if ($d !== 0.0) {
                $this->pfBook($s, $key, $d);
            }
        }
    }

    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $years = (float) (getenv('YEARS') ?: 1);
        $tpy = (int) (getenv('TPY') ?: 360);
        $seed = (int) (getenv('SEED') ?: 1);
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
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        ob_start();
        $seedTester = new CommandTester($app->find('app:market-seed'));
        $seedStatus = $seedTester->execute([]);
        ob_end_clean();
        $this->assertSame(0, $seedStatus, 'the seed command failed: ' . substr($seedTester->getDisplay(), -600));

        $stocks = [];
        $id = 1;
        foreach ($this->persisted as $e) {
            if ($e instanceof Stock) {
                (new \ReflectionProperty(Stock::class, 'id'))->setValue($e, $id++);
                $stocks[$e->getTicker()] = $e;
            }
        }
        $this->persisted = [];
        $this->capturing = false;
        $this->assertNotEmpty($stocks, 'the seed command persisted no stocks');

        $seedPrice = [];
        foreach ($stocks as $t => $s) {
            $seedPrice[$t] = (float) $s->getPrice();
        }

        $seedMargin = [];
        $seedEquity = [];
        foreach ($stocks as $t => $s) {
            $seedMargin[$t] = (float) $s->getOperatingMargin();
            $seedEquity[$t] = (float) $s->getTotalEquity();
        }

        $macro = $c->get(MacroEngine::class);
        $this->debtEngine = $c->get(\App\Service\Corporate\DebtEngine::class);
        $tracker = $c->get(StockTracker::class);
        $this->fvTracker = $tracker;
        $this->fvEngine = $c->get(\App\Service\Market\MarketEngine::class);
        $operator = $c->get(MarketOperator::class);
        if (getenv('PF_B')) {
            $this->installBridgeProxies($tracker);
        }

        $list = array_values($stocks);
        $dt = 1.0 / $tpy;
        $ticks = (int) round($years * $tpy);
        // SIMCMD=1 mirrors app:market-simulate: no board cap fed to the macro, the operator every 100 ticks.
        $operatorInterval = getenv('SIMCMD') ? 100 : (int) max(1, $tpy / 24);
        $replay = $replayPath !== null ? unserialize((string) gzuncompress((string) file_get_contents($replayPath))) : null;
        $recorded = [];
        $lastCap = null;
        $dead = [];
        $reorgs = [];
        $defaults = [];
        $t0 = microtime(true);

        // TARGET_OUT: what the stock page shows before the ticker has published a target, then the published
        // target against price and fair value at a few horizons.
        $targetProbe = [];
        $probeTicks = [1 => 'tick1', (int) round($tpy / 52) => '1w', (int) round($tpy / 12) => '1m', (int) round($tpy / 4) => '3m', (int) round($tpy / 2) => '6m', $tpy => '12m'];
        if (getenv('TARGET_OUT')) {
            $snapshots = $c->get(\App\Service\View\CompanySnapshotBuilder::class);
            foreach ($stocks as $t => $s) {
                $page = (new \ReflectionMethod($snapshots, 'analystTargets'))->invoke($snapshots, $s, new MacroStateDTO());
                $targetProbe[$t]['page0'] = ['px' => (float) $s->getPrice(), 'consensus' => (float) ($page['consensus'] ?? 0.0), 'upside' => (float) ($page['upside_pct'] ?? 0.0), 'published' => $s->getAnalystPriceTarget()];
            }
        }

        // --- PF: price-formation accumulators ---
        $pfPrev = [];
        foreach ($stocks as $t => $s) {
            $pfPrev[$t] = ['px' => (float) $s->getPrice(), 'sh' => (float) $s->getSharesOutstanding(), 'split' => $s->getLastSplitAt()];
        }
        $pfOpen = array_map(static fn (array $p): float => $p['px'], $pfPrev);
        $pfQ = [];
        $pfNY = [];
        $pfSplits = 0;
        $pfRep = 0;
        $pfCum = [];
        $pfX = [];
        $pfBeat = 0;

        for ($tick = 1; $tick <= $ticks; $tick++) {
            $this->reportedThisTick = [];
            $m = $replay !== null ? $this->withDemandLags($replay[$tick - 1], $dt) : $macro->updateMacroState($dt, $lastCap);
            if ($recordPath !== null) {
                $recorded[] = $m;
            }
            $this->currentMacro = $m;
            $this->currentTime = $tick / $tpy;

            if ($tick % $operatorInterval === 0) {
                foreach ($list as $s) {
                    if (!$s->isBankrupt() && $s->isPaymentDefault()) {
                        $defaults[$s->getTicker()] = ($defaults[$s->getTicker()] ?? 0) + 1;
                    }
                }
                $pfE0 = getenv('PF_B') ? $this->pfEq($stocks) : [];
                $pfOps = $operator->enforceMarketStability($list, $m);
                if (getenv('PF_B')) {
                    $this->pfDelta($stocks, $pfE0, 'op');
                }
                foreach ($pfOps as $ev) {
                    $t = (string) ($ev['ticker'] ?? '');
                    if (!isset($stocks[$t])) {
                        continue;
                    }
                    if (($ev['type'] ?? '') === 'BANKRUPTCY') {
                        $dead[$t] = round($tick / $tpy, 2);
                    } elseif (($ev['type'] ?? '') === 'REORGANIZATION') {
                        $reorgs[] = ['t' => $t, 'year' => round($tick / $tpy, 2)];
                    }
                }
            }

            if ($tick === 1 && getenv('OPENING_OUT')) {
                $opening = [];
                foreach ($stocks as $t => $s) {
                    $opening[$t] = ['eps0' => (float) $s->getEarningsPerShare(), 'fv0x' => getenv('FVX') ? $this->fvRow($s) : [], 'px0' => (float) $s->getPrice(), 'sh0' => (float) $s->getSharesOutstanding(), 'industry' => $s->getIndustry(), 'fin' => \App\Data\Company\Sectors::strategyFor($s->getIndustry())->isFinancial()];
                }
            }
            $pfE0 = getenv('PF_B') ? $this->pfEq($stocks) : [];
            $result = $tracker->updateStocks($list, $dt, false, $m, $tick, $tpy);
            if (getenv('PF_B')) {
                $this->pfDelta($stocks, $pfE0, 'tick');
            }
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
            $lastCap = getenv('SIMCMD') ? null : (float) $result['total_cap'];
            $pfYear = (int) ceil($tick / $tpy);
            foreach ($stocks as $t => $s) {
                $px = (float) $s->getPrice();
                $sh = (float) $s->getSharesOutstanding();
                $split = $s->getLastSplitAt();
                if (isset($dead[$t]) || $s->isBankrupt() || $px <= 0.0 || $pfPrev[$t]['px'] <= 0.0) {
                    $pfPrev[$t] = ['px' => $px, 'sh' => $sh, 'split' => $split];
                    continue;
                }
                $factor = 1.0;
                if ($split !== $pfPrev[$t]['split'] && $pfPrev[$t]['sh'] > 0.0) {
                    $factor = $sh / $pfPrev[$t]['sh'];
                    $pfSplits++;
                }
                $r = log(($px + ($this->reportedThisTick[$t] ?? 0.0)) * $factor / $pfPrev[$t]['px']);
                $c0 = $pfCum[$t] ?? ['r' => 0.0, 'd' => 0.0, 'f' => 1.0];
                $c0['r'] += $r;
                $c0['d'] += log(1.0 + ($this->reportedThisTick[$t] ?? 0.0) / $px);
                $c0['f'] *= $factor;
                $pfCum[$t] = $c0;
                $a = $pfNY[$t][$pfYear] ?? ['n' => 0, 's' => 0.0, 's2' => 0.0, 'ne' => 0, 'se' => 0.0, 'se2' => 0.0];
                $a['n']++;
                $a['s'] += $r;
                $a['s2'] += $r * $r;
                if (isset($this->reportedThisTick[$t])) {
                    $a['ne']++;
                    $sueHist = $s->getEarningsSurpriseHistory() ?? [];
                    if ($pfYear >= 2 && $sueHist !== []) {
                        $pfRep++;
                        $pfBeat += ((float) end($sueHist)) > 0.0 ? 1 : 0;
                    }
                    $a['se'] += $r;
                    $a['se2'] += $r * $r;
                }
                $pfNY[$t][$pfYear] = $a;
                $pfPrev[$t] = ['px' => $px, 'sh' => $sh, 'split' => $split];
            }
            if ($tick % max(1, intdiv($tpy, 4)) === 0) {
                $lpf = [];
                $pe = [];
                foreach ($result['updates'] as $u) {
                    $t = (string) $u['ticker'];
                    if (!isset($stocks[$t]) || isset($dead[$t]) || $stocks[$t]->isBankrupt()) {
                        continue;
                    }
                    $px = (float) $stocks[$t]->getPrice();
                    $fv = (float) ($u['perceived_fair_value'] ?? 0.0);
                    if ($px > 0.0 && $fv > 0.0) {
                        $lpf[] = log($px / $fv);
                    }
                    $eps = (float) $stocks[$t]->getEarningsPerShare();
                    if ($eps > 0.0 && $px > 0.0) {
                        $pe[] = $px / $eps;
                    }
                    if (getenv('PF_X')) {
                        $h = $this->debtEngine->analyzeTrailingDebtHealth($stocks[$t], $m);
                        $capm = (float) $m->yield10yEma + $h->leveredBeta * (float) $m->equityRiskPremium;
                        $pfX[$t][] = ['px' => $px, 'fv' => $fv, 'eps' => $eps, 'sh' => (float) $stocks[$t]->getSharesOutstanding(),
                            'ni' => (float) $stocks[$t]->getTotalNetIncome(), 'eq' => (float) $stocks[$t]->getTotalEquity(),
                            'cr' => $pfCum[$t]['r'] ?? 0.0, 'cd' => $pfCum[$t]['d'] ?? 0.0, 'cf' => $pfCum[$t]['f'] ?? 1.0,
                            'coe' => $h->costOfEquity, 'capm' => $capm, 'mr' => $h->rawMetrics->currentMarketRate, 'lb' => $h->leveredBeta];
                        if (getenv('PF_G')) {
                            $hd = $this->debtEngine->analyzeDebtHealth($stocks[$t], $m);
                            $ledger = (new \ReflectionProperty(StockTracker::class, 'anchorStakes'))->getValue($this->fvTracker);
                            $ctx = \App\DTO\MarketPricingContext::forStock($stocks[$t], $m, $hd, $ledger, 0.0, 0.0);
                            $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($ctx->businessModel);
                            $mu = new \App\Service\Math\MathUtility();
                            $atkd = ($ctx->costOfDebt ?? ($m->yield10yEma + $m->macroCreditSpread)) * (1.0 - $strategy->getEffectiveTaxRate($m->corporateTaxRate));
                            $sroic = $strategy->calculateStructuralRoic($ctx->roicTtm, $ctx->baselineRoic, $ctx->revenuePerShare, $ctx->bookValuePerShare, $ctx->baselineMargin);
                            $er = $ctx->investedCapitalPerShare > 0.0 ? $strategy->getEquityReturn($sroic, $ctx->investedCapitalPerShare, $ctx->bookValuePerShare, $atkd) : $sroic;
                            $outlook = Valuation::calculateExpectedNominalGrowth($ctx->secularGrowth, (float) $m->outputGap, $ctx->beta, (float) $m->inflation, $strategy->getMoatSpread());
                            $g = Valuation::calculateFundableGrowth($outlook, $er, $ctx->targetPayoutRatio);
                            $row = ['sec' => $ctx->secularGrowth, 'out' => $outlook, 'er' => $er, 'pay' => $ctx->targetPayoutRatio, 'g' => $g,
                                'rev' => (float) $stocks[$t]->getTotalRevenue(), 'bm' => $ctx->businessModel, 'ind' => (string) $stocks[$t]->getIndustry()];
                            if (getenv('PF_FVCHK')) {
                                $fs = (new \ReflectionMethod($this->fvEngine, 'evaluateFundamentalState'))->invoke($this->fvEngine,
                                    $px, (float) $m->outputGap, (float) $m->inflation, $ctx->beta, $ctx->costOfDebt, $ctx->reversionSpeed, $ctx->earningsPerShare,
                                    $ctx->currentRoic, $ctx->roicTtm, (float) $m->policyRate, $ctx->bookValuePerShare, $ctx->dividendPerShare, $ctx->baselineIndustryPE,
                                    $ctx->revenuePerShare, $ctx->businessModel, $ctx->liveCostOfEquity, $ctx->currentVolatility, $ctx->netDebtPerShare, $ctx->secularGrowth,
                                    $ctx->baselineRoic, $ctx->baselineMargin, $ctx->accrualsRatio, $ctx->investedCapitalPerShare, $m, $ctx->tangibleBookValuePerShare,
                                    $ctx->targetPayoutRatio, $ctx->dividendAdjustmentSpeed, $ctx->policyBasesPerShare);
                                $row['fvchk'] = (float) $fs['perceived_fair_value'];
                            }
                            $pfX[$t][count($pfX[$t]) - 1] += $row;
                            if (getenv('PF_B')) {
                                $pfX[$t][count($pfX[$t]) - 1] += ['ic' => (float) $stocks[$t]->getInvestedCapital(), 'ppe' => (float) $stocks[$t]->getNetPpe(),
                                    'ta' => (float) $stocks[$t]->getTotalAssets(), 'sector' => $stocks[$t]->getSector(), 'aoci' => (float) $stocks[$t]->getUnrealizedSecuritiesMark()];
                            }
                        }
                    }
                }
                $pfQ[] = ['t' => round($tick / $tpy, 3), 'lpf' => self::pfMedian($lpf), 'nlpf' => count($lpf), 'pe' => self::pfMedian($pe), 'npe' => count($pe),
                    'y10' => (float) $m->yield10y, 'pol' => (float) $m->policyRate,
                    'y10e' => (float) $m->yield10yEma, 'erp' => (float) $m->equityRiskPremium, 'infl' => (float) $m->inflation, 'ngdp' => (float) $m->nominalGdpIndex, 'defl' => (float) $m->gdpDeflator];
            }
            // MACRO_OUT: one macro row a quarter, for the macro-market loop.
            if (getenv('MACRO_OUT') && $tick % max(1, intdiv($tpy, 4)) === 0) {
                file_put_contents((string) getenv('MACRO_OUT'), json_encode([
                    'seed' => $seed, 't' => round($tick / $tpy, 3), 'gap' => $m->outputGap, 'cap' => $lastCap,
                    'nominal_gdp' => $m->nominalGdpIndex, 'ewr' => $m->equityWealthRatio, 'ewt' => $m->equityWealthTrend,
                    'fin' => $m->financeOutputGap ?? 0.0, 'nx' => $m->netExportGap ?? 0.0, 'supply' => $m->productivitySupplyGap,
                    'credit_gap' => $m->creditToGdpGap, 'policy' => $m->policyRate, 'infl' => $m->inflation, 'u' => $m->unemploymentRate,
                    'fx' => $m->exchangeRateIndex, 'deal' => $m->dealActivityIndex, 'vol' => $m->marketVolatility, 'erp' => $m->equityRiskPremium,
                    'fund' => $m->sovereignFundToGdp, 'fgap' => $m->foreignOutputGap, 'crisis' => $m->creditCrisisDrag,
                    'allied' => $m->alliedDefenseSpendingIndexEma, 'oil' => $m->energyPriceIndexEma,
                ]) . "\n", FILE_APPEND);
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

        $record = [
            'seed' => $seed,
            'years' => $years,
            'tpy' => $tpy,
            'secs' => round(microtime(true) - $t0, 1),
            'dead' => $dead,
            'reorgs' => $reorgs,
            'defaults' => $defaults,
            'final' => $final,
            'events' => $this->events,
        ];
        if ($out !== null) {
            file_put_contents($out, json_encode($record) . "\n", FILE_APPEND);
        }
        if (getenv('PF_OUT')) {
            file_put_contents((string) getenv('PF_OUT'), json_encode(['seed' => $seed, 'years' => $years, 'tpy' => $tpy, 'secs' => round(microtime(true) - $t0, 1),
                'dead' => $dead, 'splits' => $pfSplits, 'reports' => $pfRep, 'beats' => $pfBeat, 'open_px' => $pfOpen, 'q' => $pfQ, 'ny' => $pfNY, 'x' => $pfX, 'flows' => $this->pfFlows, 'bridge' => $this->pfB]));
        }
        fwrite(STDERR, sprintf("seed %d: %d ticks in %.1fs, deaths %s, reorgs %d\n", $seed, $ticks, microtime(true) - $t0, json_encode($dead), count($reorgs)));

        // RESET_PROBE: reopen the traded board exactly as app:market-reset does, one row at a time in seed order.
        if (getenv('RESET_PROBE')) {
            $builder = $c->get(\App\Service\Market\OpeningBoardBuilder::class);
            $openingMacro = new MacroStateDTO();
            $spheres = [];
            foreach (\App\Data\Company\InitialMarket::STOCKS as $row) {
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
                $probe[$t] = ['seed' => $seedPrice[$t], 'reset' => (float) $s->getPrice()];
            }
            file_put_contents((string) getenv('RESET_PROBE'), json_encode($probe));
        }
        $this->assertTrue(true);
    }

    /** One quarterly report as a named row, with the macro readings it was filed under. */
    /** @var array<string, float> */
    private array $replayLags = [];

    /** A recording predating the output gap's published lags gets them filtered off its own gap path, as the macro would. */
    private function withDemandLags(MacroStateDTO $recorded, float $dt): MacroStateDTO
    {
        $vars = (fn (): array => get_object_vars($this))->call($recorded);
        $math = new \App\Service\Math\MathUtility();
        foreach (\App\Service\Macro\Subsystem\MacroAggregateSubsystem::DEMAND_TRANSMISSION_LAGS as $field => $lagYears) {
            $this->replayLags[$field] = TimeSeries::calculateDistributedLag($this->replayLags[$field] ?? \App\Data\Macro\MacroFieldRegistry::defaults()[$field], $vars['outputGapEma'], $dt, $lagYears);
        }

        return new MacroStateDTO(...array_merge($vars, $this->replayLags));
    }

    private function captureReport(CorporateReport $report): void
    {
        $this->reportedThisTick[$report->getStock()->getTicker()] = max(0.0, (float) $report->getDividendPaid()) / max(1.0, (float) $report->getStock()->getSharesOutstanding());
        if (getenv('PF_B')) {
            $k = $report->getReportedKpis() ?? [];
            $st = $report->getStock();
            $this->pfBook($st, 'r_ni', (float) $report->getNetIncome());
            $this->pfBook($st, 'r_div', (float) $report->getDividendPaid());
            $this->pfBook($st, 'r_bb', (float) $report->getStockBuybacks());
            $this->pfBook($st, 'r_sbc', (float) $report->getStockCompensation());
            $this->pfBook($st, 'r_imp', (float) $report->getGoodwillImpairment());
            $this->pfBook($st, 'r_asl', (float) $report->getAssetSaleLoss());
            $this->pfBook($st, 'r_rev', (float) $report->getRevenue());
            $this->pfBook($st, 'r_capex', (float) $report->getCapitalExpenditures());
            $this->pfBook($st, 'r_dep', (float) $report->getDepreciation());
            $this->pfBook($st, 'r_prv', (float) ($k['price_revenue'] ?? 0.0));
            if (isset($k['industry_price_level'])) {
                $this->pfFlows[$st->getTicker() . '#ipl'][] = ['t' => round($this->currentTime, 3), 'ipl' => (float) $k['industry_price_level']];
            }
        }
        if (getenv('PF_G')) {
            $this->pfFlows[$report->getStock()->getTicker()][] = ['t' => round($this->currentTime, 3), 'ni' => (float) $report->getNetIncome(),
                'div' => (float) $report->getDividendPaid(), 'bb' => (float) $report->getStockBuybacks(), 'eq' => (float) $report->getEquity(),
                'rev' => (float) $report->getRevenue(), 'gw' => (float) $report->getGoodwill()];
        }
        if (getenv('PF_OUT')) {
            return;
        }
        if (getenv('DRIVERS_OUT')) {
            file_put_contents((string) getenv('DRIVERS_OUT'), json_encode(['t' => round($this->currentTime, 3), 'ticker' => $report->getStock()->getTicker(), 'streams' => $report->getStreamDetails()]) . "\n", FILE_APPEND);
        }
        $m = $this->currentMacro;
        $kpis = $report->getReportedKpis() ?? [];
        $this->quarters[$report->getStock()->getTicker()][] = [
            't' => round($this->currentTime, 3),
            'rev' => (float) $report->getRevenue(),
            'exp' => (float) (($report->getStock()->getEarningsMomentumZ() ?? [])[\App\Service\Math\FinancialConstants::STATE_LAST_EXPECTED_REVENUE] ?? 0.0),
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
     * FVX=1: the fair-value build MarketEngine::evaluateFundamentalState would strike right now, piece by piece, so a
     * move in the fair P/E can be attributed to the hurdle, the structural return, growth, accruals or the EPS smoothing.
     *
     * @return array<string, float>
     */
    private function fvRow(\App\Entity\Stock $stock): array
    {
        if (!getenv('FVX') || $this->debtEngine === null || $this->currentMacro === null || $this->fvEngine === null || $this->fvTracker === null) {
            return [];
        }
        $m = $this->currentMacro;
        $math = new \App\Service\Math\MathUtility();
        $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $m);
        $ledger = (new \ReflectionProperty(StockTracker::class, 'anchorStakes'))->getValue($this->fvTracker);
        $ctx = \App\DTO\MarketPricingContext::forStock($stock, $m, $h, $ledger, 0.0, 0.0);
        $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($ctx->businessModel);
        $rf = $m->policyRate ?? 0.04;
        $gap = $m->outputGap ?? 0.0;
        $infl = $m->inflation ?? 0.02;
        $hurdle = $strategy->isFinancial() ? $ctx->liveCostOfEquity : $ctx->liveWacc;
        $sroic = $strategy->calculateStructuralRoic($ctx->roicTtm, $ctx->baselineRoic, $ctx->revenuePerShare, $ctx->bookValuePerShare, $ctx->baselineMargin);
        $gNom = Valuation::calculateExpectedNominalGrowth($ctx->secularGrowth, $gap, $ctx->beta, $infl, $strategy->getMoatSpread());
        $gFund = Valuation::calculateFundableGrowth($gNom, $sroic, $ctx->targetPayoutRatio);
        $peRaw = Valuation::calculateIntrinsicFairValuePE($hurdle, $sroic, $gFund, null);
        $peShrunk = Valuation::calculateIntrinsicFairValuePE($hurdle, $sroic, $gFund, $ctx->baselineIndustryPE);
        $fvpe = Valuation::calculateQualityAdjustedFairValuePE($hurdle, $sroic, $gFund, $ctx->baselineIndustryPE, $ctx->accrualsRatio);
        $structEps = $strategy->calculateStructuralEps($ctx->bookValuePerShare, $sroic, $ctx->revenuePerShare, $rf, $ctx->investedCapitalPerShare > 0.0 ? $ctx->investedCapitalPerShare : null);
        $stress = max(0.0, -$gap) + abs($infl - 0.02);
        $normEps = $math->calculateKalmanSmoothedEps(max(0.01, $structEps), $ctx->earningsPerShare, $ctx->currentVolatility, $stress);
        $fs = (new \ReflectionMethod($this->fvEngine, 'evaluateFundamentalState'))->invoke($this->fvEngine,
            $ctx->currentPrice, $gap, $infl, $ctx->beta, $ctx->liveWacc, $ctx->reversionSpeed, $ctx->earningsPerShare, $ctx->currentRoic, $ctx->roicTtm,
            $rf, $ctx->bookValuePerShare, $ctx->dividendPerShare, $ctx->baselineIndustryPE, $ctx->revenuePerShare, $ctx->businessModel,
            $ctx->liveCostOfEquity, $ctx->currentVolatility, $ctx->netDebtPerShare, $ctx->secularGrowth, $ctx->baselineRoic, $ctx->baselineMargin,
            $ctx->accrualsRatio, $ctx->investedCapitalPerShare, $m, $ctx->tangibleBookValuePerShare, $ctx->targetPayoutRatio, $ctx->dividendAdjustmentSpeed);

        return ['x_rf' => $rf, 'x_erp' => (float) ($m->equityRiskPremium ?? 0.0), 'x_gap' => $gap, 'x_infl' => $infl, 'x_beta' => $ctx->beta,
            'x_wacc' => $ctx->liveWacc, 'x_coe' => $ctx->liveCostOfEquity, 'x_hurdle' => $hurdle, 'x_roicTtm' => $ctx->roicTtm, 'x_roicBase' => $ctx->baselineRoic,
            'x_sroic' => $sroic, 'x_sec' => $ctx->secularGrowth, 'x_gNom' => $gNom, 'x_gFund' => $gFund, 'x_payout' => $ctx->targetPayoutRatio,
            'x_sectorPE' => $ctx->baselineIndustryPE, 'x_acc' => $ctx->accrualsRatio, 'x_peRaw' => $peRaw, 'x_peShrunk' => $peShrunk, 'x_fvpe' => $fvpe,
            'x_eps' => $ctx->earningsPerShare, 'x_structEps' => $structEps, 'x_normEps' => $normEps, 'x_stress' => $stress, 'x_vol' => $ctx->currentVolatility,
            'x_bvps' => $ctx->bookValuePerShare, 'x_icps' => $ctx->investedCapitalPerShare, 'x_rps' => $ctx->revenuePerShare,
            'x_fv' => (float) $fs['perceived_fair_value'], 'x_growthAn' => (float) $fs['analyst_targets']['growth_analyst'],
            'x_valueAn' => (float) $fs['analyst_targets']['value_analyst'], 'x_incomeAn' => (float) $fs['analyst_targets']['income_analyst']];
    }

    /** PF_B: forwarding subclasses that book each engine's equity delta; the real engines do all the work. */
    private function installBridgeProxies(StockTracker $tracker): void
    {
        $h = $this;
        $earnProp = new \ReflectionProperty(StockTracker::class, 'earningsEngine');
        $earn = $earnProp->getValue($tracker);
        $earnProp->setValue($tracker, new class($earn, $h) extends \App\Service\Corporate\EarningsEngine {
            public function __construct(private \App\Service\Corporate\EarningsEngine $pfInner, private object $pfH) {}
            public function calculate(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
            {
                $e0 = (float) $stock->getTotalEquity();
                $r = $this->pfInner->calculate($stock, $macroState, $tickCount, $ticksPerYear);
                $this->pfH->pfBook($stock, 'earn', (float) $stock->getTotalEquity() - $e0);
                return $r;
            }
            public function evaluatePreAnnouncement(Stock $stock, int $tickCount, int $ticksPerYear): array
            {
                return $this->pfInner->evaluatePreAnnouncement($stock, $tickCount, $ticksPerYear);
            }
        });
        $capProp = new \ReflectionProperty(\App\Service\Corporate\EarningsEngine::class, 'capitalAllocationEngine');
        $cap = $capProp->getValue($earn);
        $trProp = new \ReflectionProperty(\App\Service\Corporate\CapitalAllocationEngine::class, 'treasuryEngine');
        $tr = $trProp->getValue($cap);
        $trProp->setValue($cap, new class($tr, $h) extends \App\Service\Corporate\TreasuryEngine {
            public function __construct(private \App\Service\Corporate\TreasuryEngine $pfInner, private object $pfH) {}
            public function executeCorporateStrategy(\App\DTO\CapitalAllocationContext $ctx): void
            {
                $e0 = (float) $ctx->stock->getTotalEquity();
                $r0 = $ctx->equityRaised;
                $this->pfInner->executeCorporateStrategy($ctx);
                $this->pfH->pfBook($ctx->stock, 'tr_exec', (float) $ctx->stock->getTotalEquity() - $e0);
                $this->pfH->pfBook($ctx->stock, 'tr_raised', $ctx->equityRaised - $r0);
            }
            public function finalizeLiquidity(\App\DTO\CapitalAllocationContext $ctx): void
            {
                $e0 = (float) $ctx->stock->getTotalEquity();
                $this->pfInner->finalizeLiquidity($ctx);
                $this->pfH->pfBook($ctx->stock, 'tr_fin', (float) $ctx->stock->getTotalEquity() - $e0);
            }
        });
        $maProp = new \ReflectionProperty(StockTracker::class, 'maEngine');
        $ma = $maProp->getValue($tracker);
        $maProp->setValue($tracker, new class($ma, $h) extends \App\Service\Corporate\MergerAndAcquisitionEngine {
            public function __construct(private \App\Service\Corporate\MergerAndAcquisitionEngine $pfInner, private object $pfH) {}
            public function evaluatePrivateAcquisition(Stock $acquirer, \App\DTO\MacroStateDTO $macroState, float $dt, int $tickCount = 0, int $ticksPerYear = 252): ?array
            {
                $e0 = (float) $acquirer->getTotalEquity(); $v0 = (float) $acquirer->getTotalRevenue(); $g0 = (float) $acquirer->getGoodwill();
                $r = $this->pfInner->evaluatePrivateAcquisition($acquirer, $macroState, $dt, $tickCount, $ticksPerYear);
                if ($r !== null) {
                    $this->pfH->pfBook($acquirer, 'ma_n', 1.0);
                    $this->pfH->pfBook($acquirer, 'ma_eq', (float) $acquirer->getTotalEquity() - $e0);
                    $this->pfH->pfBook($acquirer, 'ma_rev', (float) $acquirer->getTotalRevenue() - $v0);
                    $this->pfH->pfBook($acquirer, 'ma_gw', (float) $acquirer->getGoodwill() - $g0);
                    $this->pfH->pfBook($acquirer, 'ma_spent', (float) ($r['spent'] ?? 0.0));
                }
                return $r;
            }
            public function evaluateCorporateDivestiture(Stock $seller, \App\DTO\MacroStateDTO $macroState, float $dt): ?array
            {
                $e0 = (float) $seller->getTotalEquity(); $v0 = (float) $seller->getTotalRevenue();
                $r = $this->pfInner->evaluateCorporateDivestiture($seller, $macroState, $dt);
                if ($r !== null) {
                    $this->pfH->pfBook($seller, 'dv_n', 1.0);
                    $this->pfH->pfBook($seller, 'dv_eq', (float) $seller->getTotalEquity() - $e0);
                    $this->pfH->pfBook($seller, 'dv_rev', (float) $seller->getTotalRevenue() - $v0);
                }
                return $r;
            }
        });
    }

    /** @param list<float> $xs */
    private static function pfMedian(array $xs): ?float
    {
        if ($xs === []) {
            return null;
        }
        sort($xs);
        $n = count($xs);

        return $n % 2 ? $xs[intdiv($n, 2)] : 0.5 * ($xs[$n / 2 - 1] + $xs[$n / 2]);
    }

    /** @return array<string, float|bool> */
    private function healthRow(\App\Entity\Stock $stock): array
    {
        if ($this->debtEngine === null || $this->currentMacro === null || !getenv('HEALTH')) {
            return [];
        }
        $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $this->currentMacro);

        return ['uL' => $h->isUnderLeveraged, 'ndE' => $h->netDebtToEbitda, 'covL' => $h->ebitdaCovenantLimit, 'head' => $h->hasLeverageHeadroom,
            'tol' => $h->debtTolerance, 'icr' => $h->interestCoverage, 'can' => $h->canIssueDebt, 'hEbitda' => $h->rawMetrics->ebitda, 'hEbit' => $h->rawMetrics->ebit];
    }

    private function buildEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            if ($e instanceof CorporateReport) {
                $this->captureReport($e);
                return;
            }
            if ($e instanceof \App\Entity\StockEvent && !$this->capturing) {
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
                foreach ((new \ReflectionClass($class))->getAttributes(\Doctrine\ORM\Mapping\Entity::class) as $attribute) {
                    $repositoryClass = $attribute->newInstance()->repositoryClass ?? EntityRepository::class;
                }
                $repositories[$class] = $this->createStub($repositoryClass);
            }

            return $repositories[$class];
        });

        return $em;
    }
}
