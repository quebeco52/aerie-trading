<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;

/**
 * Plain whole-board run plus a read-only log of the idle-capacity KPIs per firm-quarter. IDLE_OUT=<json>: {"rows": [[t,
 * ticker, industry, idle_plant_share|null, industry_price_level|null, cpi, log(need/plant)|null], ...],
 *   "opening": {ticker: opening annual revenue}}.
 */
final class IdleHarnessTest extends BoardRun
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

    /** OUTLOOK_SALT=1 pre-writes the outlook salt 1000 + SEED so the outlook draws repeat across runs. */
    protected function afterStocks(array $result): void
    {
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
        }
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $kpis = $report->getReportedKpis() ?? [];
        $stock = $report->getStock();
        $this->idleRows[] = [
            round($this->currentTime, 3),
            $stock->getTicker(),
            $stock->getIndustry() ?? $stock->getSector(),
            isset($kpis['idle_plant_share']) ? (float) $kpis['idle_plant_share'] : null,
            isset($kpis['industry_price_level']) ? (float) $kpis['industry_price_level'] : null,
            $this->currentMacro?->consumerPriceLevel ?? 1.0,
            $this->trendCapacityGap($stock),
        ];

        return $row;
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
        $out = getenv('IDLE_OUT');
        if ($out) {
            file_put_contents($out, json_encode(['rows' => $this->idleRows, 'opening' => $this->openingRevenue, 'probe' => $GLOBALS['IDLE_PROBE'] ?? []]));
        }
    }
}
