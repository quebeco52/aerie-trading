<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;

/**
 * Plain whole-board run plus a read-only log of the idle-capacity KPIs per firm-quarter.
 * IDLE_OUT=<json>: {"rows": [[t, ticker, industry, idle_plant_share|null, industry_price_level|null], ...]}.
 */
final class IdleHarnessTest extends BoardRun
{
    /** @var list<array{float, string, ?string, ?float, ?float}> */
    private array $idleRows = [];

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
        ];

        return $row;
    }

    protected function onEnd(): void
    {
        $out = getenv('IDLE_OUT');
        if ($out) {
            file_put_contents($out, json_encode(['rows' => $this->idleRows]));
        }
    }
}
