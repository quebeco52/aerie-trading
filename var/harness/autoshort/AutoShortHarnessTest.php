<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;
use App\Service\Corporate\Industry\IndustryOutlook;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\IndustryShareStoreInterface;

/**
 * Read-only probe of the industry balance behind each report. SHORT_OUT=<json>: {"rows": [[t, ticker, industry,
 * firm price level, idle share, sum of roster trend shares, share-weighted log(trend plant / plant) G, x - m, x - f,
 * roster supply, fringe supply, own capacity, own trend plant, revenue, trend nominal GDP, filed capacity gap,
 * output gap ema, inflation ema, own anchor share], ...], "events": {ticker: [[t, text], ...]}}.
 */
final class AutoShortHarnessTest extends BoardRun
{
    /** @var list<list<mixed>> */
    private array $rows = [];
    /** @var array<string, list<array{float, string}>> */
    private array $events = [];

    /** Outlook salt 1000 + SEED, so the outlook draws pair across arms. */
    protected function onStart(): void
    {
        static::getContainer()->get(IndustryShareStoreInterface::class)->writeOutlooks(['salt' => 1000 + $this->seed, 'quarter' => -1, 'industries' => []]);
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $stock = $report->getStock();
        $industry = (string) $stock->getIndustry();
        $c = static::getContainer();
        $strategy = App\Data\Company\Sectors::strategyFor($industry);
        $kpis = $report->getReportedKpis() ?? [];
        if ($industry === '' || $strategy->isFinancial() || $this->currentMacro === null || !isset($kpis['industry_price_level'])) {
            return $row;
        }
        $ledger = $c->get(IndustryShareLedger::class);
        $store = $c->get(IndustryShareStoreInterface::class);
        $m = $this->currentMacro;
        $records = $store->readIndustry($industry);
        $ticker = $stock->getTicker();
        if (!isset($records[$ticker])) {
            return $row;
        }
        $o = $store->readOutlook($industry);
        $outlook = $o === null ? ['truth' => 0.0, 'belief' => 0.0, 'fringe' => 0.0] : ['truth' => $o['x'], 'belief' => IndustryOutlook::belief($o), 'fringe' => $o['f']];
        $tgdp = IndustryShareLedger::trendNominalGdp($m);
        $sx = IndustryShareLedger::secularExcessGrowth($strategy, $stock);
        $rm = new ReflectionMethod(IndustryShareLedger::class, 'capacityBalance');
        $bal = $rm->invoke($ledger, $records, $ticker, $tgdp, $m->totalTime, $sx, $this->tick, $this->tpy, $outlook);
        $tf = new ReflectionMethod(IndustryShareLedger::class, 'trendFactor');
        $pf = new ReflectionMethod(IndustryShareLedger::class, 'ownPathFactor');
        $sumS = 0.0;
        $sumSe = 0.0;
        $ownTrend = null;
        foreach ($records as $peer => $r) {
            if ((float) ($r['anchor_capacity_share'] ?? 0.0) <= 0.0 || (float) ($r['anchor_demand_share'] ?? 0.0) <= 0.0) {
                continue;
            }
            if ($peer !== $ticker && $this->tick - $r['tick'] > $this->tpy) {
                continue;
            }
            $growth = $tf->invoke(null, $r, $tgdp, $sx, $m->totalTime, $outlook['belief'] + (float) ($r['segment_outlook_gap'] ?? 0.0));
            $trendPlant = (float) $r['anchor_capacity_share'] * $growth * $pf->invoke(null, $r);
            $s = $trendPlant / ((float) $r['anchor_demand_share'] * $growth);
            $sumS += $s;
            $sumSe += $s * max(0.0, (float) $r['capacity']) / $trendPlant;
            if ($peer === $ticker) {
                $ownTrend = $trendPlant;
            }
        }
        $z = $stock->getEarningsMomentumZ() ?? [];
        $this->rows[] = array_map(static fn(mixed $v): mixed => is_float($v) && !is_finite($v) ? null : $v, [
            round($this->currentTime, 3), $ticker, $industry,
            (float) $kpis['industry_price_level'], isset($kpis['idle_plant_share']) ? (float) $kpis['idle_plant_share'] : null,
            $sumS, $sumS > 0.0 && $sumSe > 0.0 ? log($sumS / $sumSe) : null,
            $outlook['truth'] - $outlook['belief'], $outlook['truth'] - $outlook['fringe'],
            $bal['roster_supply'], $bal['fringe_supply'],
            (float) $records[$ticker]['capacity'], $ownTrend, (float) $report->getRevenue(), $tgdp,
            $z['carry:capacity_gap'] ?? null, $m->outputGapEma, $m->inflationEma, (float) $records[$ticker]['anchor_capacity_share'],
        ]);

        return $row;
    }

    protected function onEvent(App\Entity\StockEvent $event): void
    {
        $t = $event->getStock()->getTicker();
        if (in_array($t, ['FALC', 'EIDR'], true)) {
            $this->events[$t][] = [round($this->currentTime, 3), substr($event->getDescription(), 0, 120)];
        }
    }

    protected function onEnd(): void
    {
        if ($out = getenv('SHORT_OUT')) {
            file_put_contents($out, json_encode(['rows' => $this->rows, 'events' => $this->events], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
