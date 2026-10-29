<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Data\Company\Sectors;
use App\Entity\CorporateReport;

/**
 * How far each firm's revenue-mix weights drift from where they open (StreamContext::resolveActiveStreamWeights()).
 * Read-only probe: writes one JSON per seed to MIX_DRIFT_OUT with, per firm, its model and every quarter's active
 * weights and realised shares, read from the momentum the report left.
 */
final class MixDriftHarnessTest extends BoardRun
{
    /** @var array<string, array{model: string, q: list<array<string, mixed>>}> */
    private array $out = [];

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $s = $report->getStock();
        $t = (string) $s->getTicker();
        $weights = [];
        $shares = [];
        foreach ($s->getEarningsMomentumZ() ?? [] as $key => $value) {
            if (str_starts_with((string) $key, 'weight:')) {
                $weights[substr((string) $key, 7)] = round((float) $value, 5);
            } elseif (str_starts_with((string) $key, 'share:')) {
                $shares[substr((string) $key, 6)] = round((float) $value, 5);
            }
        }
        $this->out[$t]['model'] ??= Sectors::businessModelFor($s->getIndustry());
        $this->out[$t]['q'][] = ['t' => round($this->currentTime, 3), 'w' => $weights, 's' => $shares, 'rev' => (float) $report->getRevenue(), 'ni' => (float) $report->getNetIncome(), 'px' => (float) $s->getPrice()];

        return $row;
    }

    protected function quarterRows(): bool
    {
        return false;
    }

    protected function onEnd(): void
    {
        $path = getenv('MIX_DRIFT_OUT');
        if ($path) {
            file_put_contents((string) $path, (string) json_encode(['seed' => $this->seed, 'firms' => $this->out], JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}
