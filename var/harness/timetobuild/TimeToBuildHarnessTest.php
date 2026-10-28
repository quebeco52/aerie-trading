<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;

/**
 * Plain whole-board run; each quarter row also carries construction in progress, gross PP&E, invested capital, the
 * industry and the idle-plant / industry-price KPIs. Each MACRO_OUT row adds real GDP and potential. Read-only.
 */
final class TimeToBuildHarnessTest extends BoardRun
{
    protected function reportRow(array $row, CorporateReport $report): array
    {
        $kpis = $report->getReportedKpis() ?? [];
        $stock = $report->getStock();

        return $row + [
            'cip' => (float) ($report->getCip() ?? 0.0),
            'cip_s' => $stock->getTotalCipAmount(),
            'gppe' => (float) ($report->getGrossPpe() ?? 0.0),
            'icap' => $stock->getInvestedCapital(),
            'ind' => $stock->getIndustry() ?? $stock->getSector(),
            'idle' => isset($kpis['idle_plant_share']) ? (float) $kpis['idle_plant_share'] : null,
            'pl' => isset($kpis['industry_price_level']) ? (float) $kpis['industry_price_level'] : null,
        ];
    }

    protected function macroRow(array $row, MacroStateDTO $m): array
    {
        return $row + [
            'rgdp_q' => $m->realGdpLastQuarter, 'rgdp_g' => $m->realGdpQuarterlyGrowth,
            'pot' => $m->potentialGdpIndex, 'cpi' => $m->consumerPriceLevel, 'defl' => $m->gdpDeflator,
        ];
    }
}
