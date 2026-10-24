<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroStateStore;
use App\Service\Corporate\FailureSweep;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Politics\PoliticsEngine;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use App\Service\Corporate\EarningsEngine;

/**
 * Whole-board headless replay on the PRODUCTION service graph: real container, in-memory Redis, stub EM,
 * the live ticker's per-tick order (macro, the Diet if POLITICS=1, failure sweep every 1/24 year, stock update),
 * the macro fed every board input MarketTickerCommand feeds it, one tick behind: capitalisation, float, price return,
 * dividends, net issuance, stamp duty, strategic-stake cash and the bank levy.
 *
 * Env: YEARS TPY SEED OUT=<jsonl> MACRO_RECORD=<gz> | MACRO_REPLAY=<gz> POLITICS=1 (live macro only: the Diet
 * votes, budgets and hands its laws over as the ticker does; without it the opening laws stand).
 */
final class DisclosureHarnessTest extends KernelTestCase
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
    private ?\App\Service\Market\Pricing\MarketEngine $fvEngine = null;
    /** @var array<string, list<array{t: float, d: string}>> */
    private array $events = [];
    /** DISC_OUT: warnings (t, ticker, log move, economic part), reports (log move, bank/TA, eps gap), board returns. */
    private array $disc = ['w' => [], 'r' => [], 'ret' => []];

    /** PWT / EMS: force the Securities Commission's two fields on the DTO the board reads, every tick. */
    private function forceDisclosure(MacroStateDTO $m): MacroStateDTO
    {
        $pwt = getenv('PWT');
        $ems = getenv('EMS');
        if ($pwt === false && $ems === false) {
            return $m;
        }
        $vars = (fn (): array => get_object_vars($this))->call($m);
        // The threshold is EarningsEngine::PREANNOUNCEMENT_WARNING_THRESHOLD on trees after the 10-19 politics rework, not
        // a DTO field: PWT is then ignored and only EMS is forced.
        if ($pwt !== false && property_exists(MacroStateDTO::class, 'profitWarningThreshold')) {
            $vars['profitWarningThreshold'] = (float) $pwt;
        }
        if ($ems !== false) {
            $vars['earningsManagementScale'] = (float) $ems;
        }
        $new = (new \ReflectionClass(MacroStateDTO::class))->newInstanceWithoutConstructor();
        (function (array $v): void {
            foreach ($v as $k => $x) {
                $this->{$k} = $x;
            }
        })->call($new, $vars);

        return $new;
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
        $macroStore = $c->get(MacroStateStore::class);
        $economy = $macroStore->load();
        $this->debtEngine = $c->get(\App\Service\Corporate\DebtEngine::class);
        $tracker = $c->get(StockTracker::class);
        $this->fvTracker = $tracker;
        $this->fvEngine = $c->get(\App\Service\Market\Pricing\MarketEngine::class);
        $sweep = $c->get(FailureSweep::class);
        // The Diet, as the ticker runs it: it reads this tick's economy and the economy reads its laws next tick.
        $politicsEngine = getenv('POLITICS') && $replayPath === null ? $c->get(PoliticsEngine::class) : null;
        $policy = $politicsEngine?->liveState()->policy();

        $list = array_values($stocks);
        $dt = 1.0 / $tpy;
        $ticks = (int) round($years * $tpy);
        // SIMCMD=1 mirrors app:market-simulate: no board cap fed to the macro, the operator every 100 ticks.
        $operatorInterval = getenv('SIMCMD') ? 100 : (int) max(1, $tpy / 24);
        $replay = $replayPath !== null ? unserialize((string) gzuncompress((string) file_get_contents($replayPath))) : null;
        $recorded = [];
        $reading = new \App\DTO\BoardReadingDTO();
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

        for ($tick = 1; $tick <= $ticks; $tick++) {
            $m = $replay !== null ? $this->withDemandLags($replay[$tick - 1], $dt)
                : $macro->step($economy, $dt, $reading, $policy);
            if ($replay === null) {
                $macroStore->save($economy);
            }
            if ($politicsEngine !== null) {
                $policy = $politicsEngine->updatePolitics($m, $dt)->policy();
            }
            $m = $this->forceDisclosure($m);
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
                foreach ($sweep->sweep($list, $m) as $ev) {
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
            // DIAG=1: the warning's shortfall ratio, rebuilt from EarningsEngine::evaluatePreAnnouncement's inputs on the
            // tick it is evaluated (before this tick's diffusion; the state it reads is the last report's).
            if (getenv('DIAG')) {
                foreach ($list as $s) {
                    if ($s->isBankrupt() || ($tick % intdiv($tpy, 4)) !== EarningsEngine::resolvePreAnnouncementTick($s->getTicker(), $tpy)) {
                        continue;
                    }
                    $strat = \App\Data\Company\Sectors::getBusinessModelStrategy(\App\Data\Company\Sectors::businessModelFor($s->getIndustry()));
                    $at = 1.0 - $strat->getEffectiveTaxRate($m->corporateTaxRate);
                    $rev = $s->getManagedAccrualBank() * EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE;
                    $drag = $strat->projectInputCostDragChange($s, $m);
                    $qr = max(0.0, (float) $s->getTotalRevenue()) / 4.0;
                    $sq = max(0.0, (float) $drag) * $qr * (1.0 - \App\Service\Math\FinancialConstants::ANALYST_COST_BASE_VISIBILITY) * $at;
                    $se = max(1.0, $qr * max(EarningsEngine::PREANNOUNCEMENT_MIN_MARGIN_SCALE, (float) $s->getOperatingMargin()) * $at);
                    $this->disc['diag'][] = [round(($rev + $sq) / $se, 5), round($rev / $se, 5), round($sq / $se, 5), $drag === null ? null : round($drag, 5)];
                }
            }
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
            // SIMCMD mirrors app:market-simulate, which feeds the macro no board at all. Levels stand when not
            // reported (as the ticker keeps the last); flows are this tick's.
            if (!getenv('SIMCMD')) {
                $reading = $reading->after($result);
            }
            $this->disc['ret'][] = round((float) $result['board_price_return'], 7);
            $this->disc['cap'][] = $tick % $tpy === 0 ? (float) $result['total_cap'] : null;
            // MACRO_OUT: one macro row a quarter, for the macro-market loop.
            // FISCAL=1 adds the primary deficit and the net-tax rate, and a founding row at tick 1.
            if (getenv('MACRO_OUT') && ($tick % max(1, intdiv($tpy, 4)) === 0 || ($tick === 1 && getenv('FISCAL')))) {
                file_put_contents((string) getenv('MACRO_OUT'), json_encode([
                    'seed' => $seed, 't' => round($tick / $tpy, 3), 'gap' => $m->outputGap, 'cap' => $reading->equityMarketCap,
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
                ] + (getenv('BOARD_NI') ? $this->boardNetIncome($list) + ['gap_ema' => $m->outputGapEma, 'net_tax' => $m->netTaxRate ?? null] : [])
                    + (getenv('FISCAL') ? ['primary' => $m->primaryDeficitToGdp, 'net_tax' => $m->netTaxRate, 'spend_idx' => $m->governmentSpendingIndex] : [])) . "\n", FILE_APPEND);
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
        if (getenv('DISC_OUT')) {
            file_put_contents((string) getenv('DISC_OUT'), json_encode($this->disc + ['seed' => $seed, 'years' => $years, 'tpy' => $tpy, 'n_firms' => count($stocks), 'dead' => $dead, 'px_final' => array_map(fn ($s) => (float) $s->getPrice(), $stocks), 'px_seed' => $seedPrice]));
        }
        if ($out !== null) {
            file_put_contents($out, json_encode($record) . "\n", FILE_APPEND);
        }
        fwrite(STDERR, sprintf("seed %d: %d ticks in %.1fs, deaths %s, reorgs %d\n", $seed, $ticks, microtime(true) - $t0, json_encode($dead), count($reorgs)));

        // RESET_PROBE: reopen the traded board exactly as app:market-reset does, one row at a time in seed order.
        if (getenv('RESET_PROBE')) {
            $builder = $c->get(\App\Service\Market\Pricing\OpeningBoardBuilder::class);
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
        foreach (\App\Service\Macro\Subsystem\Aggregate\MovingAverages::DEMAND_TRANSMISSION_LAGS as $field => $lagYears) {
            $this->replayLags[$field] = TimeSeries::calculateDistributedLag($this->replayLags[$field] ?? \App\Data\Macro\MacroFieldRegistry::defaults()[$field], $vars['outputGapEma'], $dt, $lagYears);
        }

        return new MacroStateDTO(...array_merge($vars, $this->replayLags));
    }

    /**
     * BOARD_NI=1: the board's trailing net income (sum of Stock::totalNetIncome, the TTM figure EarningsEngine sets)
     * over firms not bankrupt, and how many firms it covers.
     *
     * @param list<\App\Entity\Stock> $list
     * @return array{board_tni: float, board_n: int}
     */
    private function boardNetIncome(array $list): array
    {
        $sum = 0.0;
        $n = 0;
        foreach ($list as $s) {
            if (!$s->isBankrupt()) {
                $sum += (float) $s->getTotalNetIncome();
                $n++;
            }
        }

        return ['board_tni' => $sum, 'board_n' => $n];
    }

    private function captureReport(CorporateReport $report): void
    {
        if (getenv('DRIVERS_OUT')) {
            file_put_contents((string) getenv('DRIVERS_OUT'), json_encode(['t' => round($this->currentTime, 3), 'ticker' => $report->getStock()->getTicker(), 'streams' => $report->getStreamDetails()]) . "\n", FILE_APPEND);
        }
        $m = $this->currentMacro;
        if (getenv('DISC_OUT')) {
            $st = $report->getStock();
            $this->disc['r'][] = [round($this->currentTime, 3), $st->getTicker(), $st->getManagedAccrualBank() / max(1.0, (float) $report->getTotalAssets()),
                $report->getConsensusEps() === null ? null : (float) $report->getReportedEps() - (float) $report->getConsensusEps(), (float) $st->getPrice()];
            return;
        }
        $kpis = $report->getReportedKpis() ?? [];
        $this->quarters[$report->getStock()->getTicker()][] = [
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
        $gFund = Valuation::calculateFundableGrowth($gNom, $sroic, $ctx->targetPayoutRatio, $infl);
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
            if ($e instanceof \App\Entity\StockEvent && !$this->capturing && in_array($e->getEventType(), ['GUIDANCE', 'EARNINGS'], true)) {
                $chg = (float) $e->getChangePercent() / 100.0;
                $lm = log(max(1e-6, 1.0 + $chg));
                if ($e->getEventType() === 'GUIDANCE') {
                    $this->disc['w'][] = [round($this->currentTime, 3), $e->getStock()->getTicker(), $lm, (float) ($e->getStock()->getPreAnnouncedEconomicShortfall() ?? 0.0)];
                } else {
                    $this->disc['rj'][] = $lm;
                }
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
