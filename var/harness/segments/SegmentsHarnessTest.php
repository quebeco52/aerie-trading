<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;

/**
 * Plain whole-board run plus a read-only log of the idle-capacity KPIs per firm-quarter. IDLE_OUT=<json>: {"rows": [[t,
 * ticker, industry, idle_plant_share|null, industry_price_level|null, cpi, log(need/plant)|null, filed capacity gap|null, catch-up growth|null, x_fv|null, filed log belief|null], ...],
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

    /** @var array<string, array{float, int, float, float}> ticker => [sum of squared tick log returns, n, configured vol, last price] */
    private array $realisedVol = [];

    /** @var array<string, array{list<array{float, array<string, mixed>}>, float, float, float, float}> KPI_TICKERS: ticker => [kpi rows, opening cap, opening eps, running peak, max drawdown] */
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
                ? \App\Service\Math\Valuation::capacityCatchUpGrowth((float) $gap, 0.062, $row['x_coe'] - $this->currentMacro->tipsBreakeven) : null,
            $row['x_fv'] ?? null,
            (($stock->getEarningsMomentumZ() ?? [])['carry:industry_outlook'] ?? null),
            ...$this->gapProbe($stock),
        ];
        if (getenv('SEG_OUT')) {
            $this->segmentProbe($stock, $report);
        }

        return $row;
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
    private function segmentProbe(\App\Entity\Stock $stock, CorporateReport $report): void
    {
        $ticker = $stock->getTicker();
        $z = $stock->getEarningsMomentumZ() ?? [];
        $storeId = 'App\\Service\\Corporate\\Industry\\IndustryShareStoreInterface';
        $store = static::getContainer()->get($storeId);
        $plan = \App\Data\Company\Segments::forTicker($ticker);
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

            return $r === null ? 0.0 : \App\Service\Corporate\Industry\IndustryOutlook::belief($r);
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
            $key = \App\Data\Company\Segments::segmentOf($ticker, (string) $stream);
            $segRev[$key] = ($segRev[$key] ?? 0.0) + (float) ($d['revenue'] ?? 0.0);
        }
        $rec = $store->readIndustry((string) $stock->getIndustry())[$ticker] ?? null;
        $this->segRows[] = [
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
    private function gapProbe(\App\Entity\Stock $stock): array
    {
        $z = $stock->getEarningsMomentumZ() ?? [];
        if (!getenv('GAPPROBE') || !isset($z['carry:capacity_gap']) || $this->debtEngine === null || $this->currentMacro === null || $this->tracker === null) {
            return [];
        }
        $out = [];
        foreach ([0.0, (float) $z['carry:capacity_gap'] + 0.1] as $gap) {
            $stock->setEarningsMomentumZ(['carry:capacity_gap' => $gap] + $z);
            $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $this->currentMacro);
            $ledger = (new ReflectionProperty(\App\Service\Market\Pricing\StockTracker::class, 'anchorStakes'))->getValue($this->tracker);
            $ctx = \App\DTO\MarketPricingContext::forStock($stock, $this->currentMacro, $h, $ledger);
            $out[] = (float) \App\Service\Market\Pricing\FairValue::fundamentalStateFor($ctx)['perceived_fair_value'];
        }
        $stock->setEarningsMomentumZ($z);
        // PGR: every perpetualGrowthRate call while striking fair value at the filed gap (ovr_pgr logs them);
        // columns 13-14 = the call with the largest input, its input and its struck output.
        if (getenv('PGR')) {
            $GLOBALS['PGR_LOG'] = [];
            $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $this->currentMacro);
            $ledger = (new ReflectionProperty(\App\Service\Market\Pricing\StockTracker::class, 'anchorStakes'))->getValue($this->tracker);
            \App\Service\Market\Pricing\FairValue::fundamentalStateFor(\App\DTO\MarketPricingContext::forStock($stock, $this->currentMacro, $h, $ledger));
            $calls = $GLOBALS['PGR_LOG'];
            unset($GLOBALS['PGR_LOG']);
            usort($calls, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
            $out[] = $calls[0][0] ?? null;
            $out[] = $calls[0][1] ?? null;
        }

        return $out;
    }

    /** log(need / plant) as the ledger's accelerator reads it (read-only); null where unavailable. */
    private function trendCapacityGap(\App\Entity\Stock $stock): ?float
    {
        $ledgerId = 'App\\Service\\Corporate\\Industry\\IndustryShareLedger';
        if ($this->currentMacro === null || !method_exists($ledgerId, 'secularExcessGrowth') || !static::getContainer()->has($ledgerId)) {
            return null;
        }
        $strategy = \App\Data\Company\Sectors::strategyFor((string) $stock->getIndustry());
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
            file_put_contents((string) getenv('SEG_OUT'), json_encode(['rows' => $this->segRows, 'leaks' => $this->segLeaks]));
        }
        $out = getenv('IDLE_OUT');
        if ($out) {
            file_put_contents($out, json_encode(['rows' => $this->idleRows, 'opening' => $this->openingRevenue, 'probe' => $GLOBALS['IDLE_PROBE'] ?? [], 'rv' => $this->realisedVol, 'tpy' => $this->tpy, 'kpi' => $this->kpiLog]));
        }
    }
}
