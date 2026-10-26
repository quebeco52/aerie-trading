<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;

/**
 * Plain whole-board run plus a read-only log of the idle-capacity KPIs per firm-quarter. IDLE_OUT=<json>: {"rows": [[t,
 * ticker, industry, idle_plant_share|null, industry_price_level|null, cpi, log(need/plant)|null, filed capacity
 * gap|null, catch-up growth|null, x_fv|null, filed log belief|null], ...],
 *   "opening": {ticker: opening annual revenue}}.
 */
final class SegmentsHarnessTest extends BoardRun
{
    /** @var list<array{float, string, ?string, ?float, ?float, float}> */
    private array $idleRows = [];
    /** @var array<string, float> */
    private array $openingRevenue = [];

    /** @var list<array{float, int, array<string, mixed>}> Outlook state per quarter (OUTLOOK_OUT). */
    private array $outlookRows = [];
    private int $outlookQuarter = -2;

    private function outlookStore(): ?object
    {
        $id = 'App\\Service\\Corporate\\Industry\\IndustryShareStoreInterface';
        $store = static::getContainer()->has($id) ? static::getContainer()->get($id) : null;

        return $store !== null && method_exists($store, 'readOutlooks') ? $store : null;
    }

    /**
     * @var array<string, array{float, int, float, float}> ticker => [sum of squared tick log returns, n, configured
     *                                                     vol, last price]
     */
    private array $realisedVol = [];

    /**
     * @var array<string, array{list<array{float, array<string, mixed>}>, float, float, float, float}> KPI_TICKERS: ticker => [kpi rows, opening cap, opening eps, running peak, max drawdown]
     */
    private array $kpiLog = [];

    /** @var array<string, float> Each name's share count at the last tick, to tell a split from a price move. */
    private array $lastShares = [];

    /** OUTLOOK_SALT=1 pre-writes the outlook salt 1000 + SEED so the outlook draws repeat across runs. */
    protected function afterStocks(array $result): void
    {
        foreach ($this->stocks as $ticker => $stock) {
            $price = (float) $stock->getPrice();
            if (!isset($this->realisedVol[$ticker]) || $price <= 0.0 || $stock->isBankrupt()) {
                continue;
            }
            // A split multiplies the share count by a whole number (or divides it): restate the last price and the
            // running peak, so a 4-for-1 does not read as a 75% fall.
            $shares = (float) $stock->getSharesOutstanding();
            $ratio = ($this->lastShares[$ticker] ?? 0.0) > 0.0 ? $shares / $this->lastShares[$ticker] : 1.0;
            $this->lastShares[$ticker] = $shares;
            $split = ($ratio >= 1.5 && abs($ratio - round($ratio)) < 1e-6) || ($ratio > 0.0 && $ratio <= 0.67 && abs((1.0 / $ratio) - round(1.0 / $ratio)) < 1e-6) ? $ratio : 1.0;
            $last = $this->realisedVol[$ticker][3] / $split;
            if ($last > 0.0) {
                $this->realisedVol[$ticker][0] += log($price / $last) ** 2;
                ++$this->realisedVol[$ticker][1];
            }
            $this->realisedVol[$ticker][3] = $price;
            if (isset($this->kpiLog[$ticker])) {
                $this->kpiLog[$ticker][3] = max($this->kpiLog[$ticker][3] / $split, $price);
                $this->kpiLog[$ticker][4] = max($this->kpiLog[$ticker][4], 1.0 - ($price / $this->kpiLog[$ticker][3]));
            }
        }
        if (!getenv('OUTLOOK_OUT') || ($store = $this->outlookStore()) === null) {
            return;
        }
        $state = $store->readOutlooks();
        if ($state !== null && $state['quarter'] !== $this->outlookQuarter) {
            $this->outlookQuarter = $state['quarter'];
            $rows = [];
            foreach ($state['industries'] as $industry => $r) {
                $rows[$industry] = [$r['x'], $r['m'], $r['v'], $r['f'], $r['w']];
            }
            $this->outlookRows[] = [round($this->currentTime, 3), $state['quarter'], $rows];
        }
    }

    protected function onStart(): void
    {
        // CAP_PROBE: per report, from EarningsReportedEvent's context (read-only): ticker, time, segmented, financial,
        // the one-plant growth gate failing, treasury organic capex, operating/maintenance/scheduled capex, revenue,
        // net PP&E.
        if (getenv('SEG_OUT')) {
            static::getContainer()->get('event_dispatcher')->addListener(\App\Service\Event\EarningsReportedEvent::class, function (\App\Service\Event\EarningsReportedEvent $e): void {
                $ctx = $e->getContext();
                $stock = $ctx->stock;
                $fails = null;
                try {
                    $fails = \App\Service\Corporate\Earnings\CapitalBudgetEngine::returnOnNewPlant($ctx)
                        < $stock->getManagementProfile()->growthHurdle($ctx->strategy->getHurdleRate($ctx->health), false);
                } catch (\Throwable) {
                }
                $this->capRows[] = [round($this->currentTime, 3), $stock->getTicker(), $ctx->segments !== [], $ctx->strategy->isFinancial(), $fails,
                    (float) ($ctx->allocation['organic_capex'] ?? 0.0), $ctx->operatingCapex, $ctx->maintenanceCapex, $ctx->scheduledCapex,
                    $ctx->actualRevenue, $stock->getNetPpe(), $stock->getManagementProfile()->style->value ?? null,
                    property_exists($ctx, 'plantReplaced') ? $ctx->plantReplaced : null, $ctx->plantLoad, $ctx->industryPriceLevel,
                    $ctx->macroState->outputGap ?? null, (string) $stock->getIndustry()];
            });
        }
        if (getenv('OUTLOOK_SALT') && ($store = $this->outlookStore()) !== null) {
            $store->writeOutlooks(['salt' => 1000 + $this->seed, 'quarter' => -1, 'industries' => []]);
        }
        foreach ($this->stocks as $ticker => $stock) {
            $this->openingRevenue[$ticker] = (float) $stock->getTotalRevenue();
            $this->realisedVol[$ticker] = [0.0, 0, (float) $stock->getVolatility(), 0.0];
            if (in_array($ticker, explode(',', (string) getenv('KPI_TICKERS')), true)) {
                $this->kpiLog[$ticker] = [[], (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(), (float) $stock->getEarningsPerShare(), 0.0, 0.0];
            }
        }
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $kpis = $report->getReportedKpis() ?? [];
        $stock = $report->getStock();
        if (isset($this->kpiLog[$stock->getTicker()])) {
            $this->kpiLog[$stock->getTicker()][0][] = [round($this->currentTime, 3), $kpis];
        }
        $this->idleRows[] = [
            round($this->currentTime, 3),
            $stock->getTicker(),
            $stock->getIndustry() ?? $stock->getSector(),
            isset($kpis['idle_plant_share']) ? (float) $kpis['idle_plant_share'] : null,
            isset($kpis['industry_price_level']) ? (float) $kpis['industry_price_level'] : null,
            $this->currentMacro?->consumerPriceLevel ?? 1.0,
            $this->trendCapacityGap($stock),
            $gap = (($stock->getEarningsMomentumZ() ?? [])['carry:capacity_gap'] ?? null),
            ($gap !== null && isset($row['x_coe']) && $this->currentMacro !== null && method_exists('App\\Service\\Math\\Valuation', 'capacityCatchUpGrowth'))
                ? App\Service\Math\Valuation::capacityCatchUpGrowth((float) $gap, 0.062, $row['x_coe'] - $this->currentMacro->tipsBreakeven) : null,
            $row['x_fv'] ?? null,
            (($stock->getEarningsMomentumZ() ?? [])['carry:industry_outlook'] ?? null),
            ...$this->gapProbe($stock),
        ];
        if (getenv('SEG_OUT')) {
            $this->segmentProbe($stock, $report);
        }

        return $row;
    }

    /** @var list<list<mixed>> CAP_PROBE rows (see onStart). */
    private array $capRows = [];

    /** @var list<array<string, mixed>> SEG_OUT deal events of the segmented tickers: {t, tk, d, kappa after}. */
    private array $segDeals = [];

    /** Deal events (divestiture "sold its", acquisition "executed a") of the segmented tickers, with kappa after. */
    protected function onEvent(App\Entity\StockEvent $event): void
    {
        $stock = $event->getStock();
        $desc = (string) $event->getDescription();
        if (!getenv('SEG_OUT') || App\Data\Company\Segments::forTicker($stock->getTicker()) === null
            || !preg_match('/ sold its | executed a /', $desc)) {
            return;
        }
        $kappa = [];
        foreach ($stock->getEarningsMomentumZ() ?? [] as $k => $v) {
            if (str_starts_with((string) $k, 'carry:segment_plant:')) {
                $kappa[substr((string) $k, 20)] = (float) $v;
            }
        }
        $this->segDeals[] = ['t' => round($this->currentTime, 3), 'tk' => $stock->getTicker(), 'd' => substr($desc, 0, 200), 'kappa' => $kappa];
    }

    /**
     * The segment prices and loads an ovr_* SegmentBooks copy logged for the last aggregate() call, if its segments are
     * this company's (otherwise null); cleared once read.
     *
     * @param array<string, float> $kappa
     * @return array<string, mixed>|null
     */
    private function takeSegmentPl(array $kappa): ?array
    {
        $pl = $GLOBALS['SEG_PL_LAST'] ?? null;
        unset($GLOBALS['SEG_PL_LAST']);

        return is_array($pl) && array_keys($pl['plant']) == array_keys(array_filter($kappa, static fn(float $v): bool => $v > 0.0)) ? $pl : null;
    }

    /**
     * SKYL's satellite internet: the planned plant share (SpaceSystemsBusinessModel::resolveSegmentPath) and the
     * ledger's capacity gap for the segment record; nulls on a tree without them.
     *
     * @return array{plan: ?float, cgap: ?float}
     */
    private function skylPath(\App\Entity\Stock $stock): array
    {
        $model = \App\Data\Company\Sectors::strategyFor((string) $stock->getIndustry());
        $plan = method_exists($model, 'resolveSegmentPath') ? $model->resolveSegmentPath($stock, 'satellite_internet') : null;
        $ledgerId = 'App\\Service\\Corporate\\Industry\\IndustryShareLedger';
        $gap = null;
        if ($this->currentMacro !== null && method_exists($ledgerId, 'resolveSegmentCapacityGap')) {
            $gap = static::getContainer()->get($ledgerId)->resolveSegmentCapacityGap($stock, 'satellite_internet', $this->currentMacro,
                $ledgerId::secularExcessGrowth(\App\Data\Company\Sectors::strategyFor('Telecom Services'), $stock));
        }

        return ['plan' => is_array($plan) ? (float) ($plan['share'] ?? 0.0) : null, 'cgap' => $gap];
    }

    /** @var list<array<string, mixed>> SEG_OUT rows for the segmented tickers. */
    private array $segRows = [];
    /** @var array<string, int> SEG_OUT identity violations: unsegmented firm with a segment key or a ledger gap. */
    private array $segLeaks = ['momentum' => 0, 'ledger' => 0, 'checked' => 0];

    /**
     * Read-only segment log per report: filed shares, tilts, blend, each segment industry's belief (read from the
     * outlook store, so it works on a tree without resolveSegmentBeliefs), segment revenue grouped by
     * Segments::segmentOf, report revenue. Unsegmented firms: counts of segment keys in momentum or ledger record.
     */
    private function segmentProbe(App\Entity\Stock $stock, CorporateReport $report): void
    {
        $ticker = $stock->getTicker();
        $z = $stock->getEarningsMomentumZ() ?? [];
        $storeId = 'App\\Service\\Corporate\\Industry\\IndustryShareStoreInterface';
        $store = static::getContainer()->get($storeId);
        $plan = App\Data\Company\Segments::forTicker($ticker);
        // Venture state (5b): any momentum key for an entered segment (':x:') or pending/learning plant, on any firm.
        foreach (array_keys($z) as $k) {
            if (str_contains((string) $k, ':x:') || str_contains((string) $k, 'segment_pending') || str_contains((string) $k, 'segment_learning')) {
                $this->segLeaks['venture'] = ($this->segLeaks['venture'] ?? 0) + 1;
                break;
            }
        }
        if ($plan === null) {
            ++$this->segLeaks['checked'];
            foreach (array_keys($z) as $k) {
                if (str_starts_with((string) $k, 'carry:segment_')) {
                    ++$this->segLeaks['momentum'];
                    break;
                }
            }
            $rec = $store->readIndustry((string) $stock->getIndustry())[$ticker] ?? null;
            if (is_array($rec) && array_key_exists('segment_outlook_gap', $rec)) {
                ++$this->segLeaks['ledger'];
            }

            return;
        }
        $belief = static function (string $industry) use ($store): float {
            $r = $store->readOutlook($industry);

            return $r === null ? 0.0 : App\Service\Corporate\Industry\IndustryOutlook::belief($r);
        };
        $beliefs = ['main' => $belief((string) $stock->getIndustry())];
        foreach ($plan['segments'] as $key => $segment) {
            $beliefs[$key] = $belief($segment['industry']);
        }
        $shares = [];
        $tilts = [];
        foreach ($z as $k => $v) {
            if (str_starts_with((string) $k, 'carry:segment_share:')) {
                $shares[substr((string) $k, 20)] = (float) $v;
            } elseif (str_starts_with((string) $k, 'carry:segment_tilt:')) {
                $tilts[substr((string) $k, 19)] = (float) $v;
            }
        }
        $segRev = [];
        foreach ($report->getStreamDetails() ?? [] as $stream => $d) {
            $key = App\Data\Company\Segments::segmentOf($ticker, (string) $stream);
            $segRev[$key] = ($segRev[$key] ?? 0.0) + (float) ($d['revenue'] ?? 0.0);
        }
        $rec = $store->readIndustry((string) $stock->getIndustry())[$ticker] ?? null;
        // Phase 3 (segment books): kappa, targets, segment outlooks, gap/spare, each segment record, KPIs, penalty.
        $kappa = [];
        $targets = [];
        $segOutlook = [];
        $segMargin = [];
        $segBuilds = [];
        $segTurnover = [];
        foreach ($z as $k => $v) {
            foreach (['carry:segment_plant:' => 'kappa', 'carry:segment_target:' => 'targets', 'carry:segment_outlook:' => 'segOutlook', 'carry:segment_margin:' => 'segMargin', 'carry:segment_turnover:' => 'segTurnover', 'carry:segment_builds:' => 'segBuilds'] as $p => $var) {
                if (str_starts_with((string) $k, $p)) {
                    ${$var}[substr((string) $k, strlen($p))] = (float) $v;
                }
            }
        }
        $industries = ['main' => (string) $stock->getIndustry()];
        foreach ($plan['segments'] as $key => $segment) {
            $industries[$key] = $segment['industry'];
        }
        $recs = [];
        $truths = [];
        foreach ($industries as $seg => $ind) {
            $r = $store->readIndustry($ind)[$seg === 'main' ? $ticker : $ticker . '/' . $seg] ?? null;
            if (is_array($r)) {
                $recs[$seg] = ['cap' => $r['capacity'], 'rev' => $r['revenue'], 'acs' => $r['anchor_capacity_share'], 'as' => $r['addressable_share'], 'tick' => $r['tick'],
                    'path' => $r['path'] ?? null, 'apath' => $r['anchor_path'] ?? null];
            }
            $o = $store->readOutlook($ind);
            $truths[$seg] = is_array($o) ? (float) ($o['x'] ?? 0.0) : null;
        }
        $kpis = $report->getReportedKpis() ?? [];
        $pen = null;
        $addr = null;
        $cmId = 'App\\Service\\Corporate\\CorporateMetrics';
        if ($this->currentMacro !== null && static::getContainer()->has($cmId)) {
            $cm = static::getContainer()->get($cmId);
            $cap = App\Data\Company\Sectors::strategyFor($stock->getIndustry())->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital());
            $pen = $cm->calculateMarketSaturationPenalty($stock, $cap, $this->currentMacro);
            $addr = $cm->calculateAddressableShare($stock, $cap, $this->currentMacro);
        }
        $this->segRows[] = [
            'kappa' => $kappa, 'targets' => $targets, 'sego' => $segOutlook, 'recs' => $recs, 'truths' => $truths,
            'gap' => isset($z['carry:capacity_gap']) ? (float) $z['carry:capacity_gap'] : null,
            'spare' => isset($z['carry:capacity_spare']) ? (float) $z['carry:capacity_spare'] : null,
            'ipl' => isset($kpis['industry_price_level']) ? (float) $kpis['industry_price_level'] : null,
            'idle' => isset($kpis['idle_plant_share']) ? (float) $kpis['idle_plant_share'] : null,
            'pen' => $pen, 'addr' => $addr, 'tick' => $this->tick,
            'pl' => $this->takeSegmentPl($kappa), 'ni' => (float) $report->getNetIncome(), 'ii' => (float) $report->getInterestIncome(),
            'ie' => (float) $report->getInterestExpense(), 'ebit' => (float) $report->getEbit(), 'eq' => (float) $report->getEquity(),
            'nd' => (float) $stock->getTotalDebt() - (float) $stock->getCorporateTreasury(), 'ebitda' => (float) $report->getEbitda(),
            'gw' => (float) $stock->getGoodwill(), 'tax' => $this->currentMacro === null ? null : \App\Data\Company\Sectors::strategyFor($stock->getIndustry())->getEffectiveTaxRate($this->currentMacro->corporateTaxRate),
            'sky' => $ticker === 'SKYL' ? $this->skylPath($stock) : null,
            'builds' => $segBuilds,
            'smar' => $segMargin, 'stur' => $segTurnover, 'fmar' => (float) $stock->getOperatingMargin(), 'ftur' => (float) $stock->getAssetTurnover(),
            't' => round($this->currentTime, 3), 'tk' => $ticker, 'rev' => (float) $report->getRevenue(),
            'om' => (float) $report->getOperatingMargin(), 'shares' => $shares, 'tilts' => $tilts,
            'blend' => isset($z['carry:industry_outlook']) ? (float) $z['carry:industry_outlook'] : null,
            'beliefs' => $beliefs, 'segrev' => $segRev,
            'lgap' => is_array($rec) && isset($rec['segment_outlook_gap']) ? (float) $rec['segment_outlook_gap'] : null,
        ];
    }

    /**
     * GAPPROBE=1: perceived fair value with the filed capacity gap set to 0 and to gap + 0.1 (restored after), so the
     * fair value's own elasticity to the gap is measured. Columns 11-12; empty otherwise.
     *
     * @return list<?float>
     */
    private function gapProbe(App\Entity\Stock $stock): array
    {
        $z = $stock->getEarningsMomentumZ() ?? [];
        if (!getenv('GAPPROBE') || !isset($z['carry:capacity_gap']) || $this->debtEngine === null || $this->currentMacro === null || $this->tracker === null) {
            return [];
        }
        $out = [];
        foreach ([0.0, (float) $z['carry:capacity_gap'] + 0.1] as $gap) {
            $stock->setEarningsMomentumZ(['carry:capacity_gap' => $gap] + $z);
            $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $this->currentMacro);
            $ledger = (new ReflectionProperty(App\Service\Market\Pricing\StockTracker::class, 'anchorStakes'))->getValue($this->tracker);
            $ctx = App\DTO\MarketPricingContext::forStock($stock, $this->currentMacro, $h, $ledger);
            $out[] = (float) App\Service\Market\Pricing\FairValue::fundamentalStateFor($ctx)['perceived_fair_value'];
        }
        $stock->setEarningsMomentumZ($z);
        // PGR: every perpetualGrowthRate call while striking fair value at the filed gap (ovr_pgr logs them);
        // columns 13-14 = the call with the largest input, its input and its struck output.
        if (getenv('PGR')) {
            $GLOBALS['PGR_LOG'] = [];
            $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $this->currentMacro);
            $ledger = (new ReflectionProperty(App\Service\Market\Pricing\StockTracker::class, 'anchorStakes'))->getValue($this->tracker);
            App\Service\Market\Pricing\FairValue::fundamentalStateFor(App\DTO\MarketPricingContext::forStock($stock, $this->currentMacro, $h, $ledger));
            $calls = $GLOBALS['PGR_LOG'];
            unset($GLOBALS['PGR_LOG']);
            usort($calls, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
            $out[] = $calls[0][0] ?? null;
            $out[] = $calls[0][1] ?? null;
        }

        return $out;
    }

    /** log(need / plant) as the ledger's accelerator reads it (read-only); null where unavailable. */
    private function trendCapacityGap(App\Entity\Stock $stock): ?float
    {
        $ledgerId = 'App\\Service\\Corporate\\Industry\\IndustryShareLedger';
        if ($this->currentMacro === null || !method_exists($ledgerId, 'secularExcessGrowth') || !static::getContainer()->has($ledgerId)) {
            return null;
        }
        $strategy = App\Data\Company\Sectors::strategyFor((string) $stock->getIndustry());
        if ($strategy->isFinancial()) {
            return null;
        }

        return static::getContainer()->get($ledgerId)->resolveTrendCapacityGap($stock, $this->currentMacro, $ledgerId::secularExcessGrowth($strategy, $stock));
    }

    protected function onEnd(): void
    {
        if (getenv('OUTLOOK_OUT')) {
            file_put_contents((string) getenv('OUTLOOK_OUT'), json_encode(['quarters' => $this->outlookRows]));
        }
        if (getenv('SEG_OUT')) {
            // Every TICKER/seg record in any industry the board or a segment plan names (identity: only the eight).
            $store = static::getContainer()->get('App\\Service\\Corporate\\Industry\\IndustryShareStoreInterface');
            $inds = [];
            foreach ($this->stocks as $tk => $s) {
                $inds[(string) $s->getIndustry()] = true;
                foreach (App\Data\Company\Segments::forTicker((string) $tk)['segments'] ?? [] as $seg) {
                    $inds[$seg['industry']] = true;
                }
            }
            $slashKeys = [];
            foreach (array_keys($inds) as $ind) {
                foreach (array_keys($store->readIndustry((string) $ind)) as $key) {
                    if (str_contains((string) $key, '/')) {
                        $slashKeys[] = $ind . '|' . $key;
                    }
                }
            }
            file_put_contents((string) getenv('SEG_OUT'), json_encode(['rows' => $this->segRows, 'leaks' => $this->segLeaks, 'slash' => $slashKeys, 'deals' => $this->segDeals, 'cap' => $this->capRows]));
        }
        $out = getenv('IDLE_OUT');
        if ($out) {
            file_put_contents($out, json_encode(['rows' => $this->idleRows, 'opening' => $this->openingRevenue, 'probe' => $GLOBALS['IDLE_PROBE'] ?? [], 'rv' => $this->realisedVol, 'tpy' => $this->tpy, 'kpi' => $this->kpiLog]));
        }
    }
}
