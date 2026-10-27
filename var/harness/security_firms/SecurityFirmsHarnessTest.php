<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;
use App\Entity\StockEvent;

/**
 * Behaviour check for the new security-family firms NCKR (cash and vaults), WALL (correctional) and NJAR (screening and investigations), beside
 * WATCH and OSPR. Read-only probe: writes one JSON per seed to SECFIRMS_OUT with the opening, every quarter of the
 * five firms (KPIs, streams, rating, the macro the bookings read), a daily split-free price path with a cap-weighted
 * board index, shock-event counts from the earnings lore, and per-firm realised vs configured vol for the whole board.
 */
final class SecurityFirmsHarnessTest extends BoardRun
{
    private const FIRMS = ['NCKR', 'WALL', 'NJAR', 'WATCH', 'OSPR'];

    /** Earnings-lore patterns for the shock events (NarrativeEngine phrases). */
    private const SHOCKS = [
        'cash_heist' => '/cash van on its round|vault break-in|depot robbery/',
        'security_breach' => '/security breach|tactical failure|security breakdown/',
        'geopolitical_conflict' => '/geopolitical conflict|regional hostilities|international tensions fueled/',
        'client_file_leak' => '/screening files reached the press|investigation went wrong in public|leak of case files/',
        'prison_disturbance' => '/riot closed a wing|escaped from a remand centre|Disturbances at a prison/',
    ];

    /** @var array<string, mixed> */
    private array $out = ['opening' => [], 'q' => [], 'daily' => [], 'shocks' => [], 'events' => [], 'rv' => []];
    private float $index = 1.0;
    /** @var array<string, float> */
    private array $lastCap = [];

    protected function onStart(): void
    {
        $this->out['ledger_file'] = (string) (new \ReflectionClass(\App\Service\Corporate\Industry\IndustryShareLedger::class))->getFileName();
        foreach ($this->list as $s) {
            $t = $s->getTicker();
            $this->out['board'][$t] = [
                'px' => (float) $s->getPrice(), 'eps' => (float) $s->getEarningsPerShare(),
                'bvps' => (float) $s->getBookValuePerShare(), 'sigma' => (float) $s->getVolatility(),
                'beta' => (float) $s->getBeta(), 'sector' => $s->getSector(), 'industry' => $s->getIndustry(),
            ];
        }
        foreach (self::FIRMS as $t) {
            $s = $this->stocks[$t];
            $this->out['opening'][$t] = [
                'rev' => (float) $s->getTotalRevenue(), 'om' => (float) ($s->getOperatingMargin() ?? 0.0),
                'eq' => (float) $s->getTotalEquity(), 'debt' => (float) $s->getTotalDebt(),
                'rating' => $s->getCreditRating(),
            ];
        }
    }

    protected function afterStocks(array $result): void
    {
        // Cap-weighted board return on market value (split-free); per-firm log cap returns after year 1 accumulate
        // into n, sum, sum of squares and the cross product with the index return.
        $num = 0.0;
        $den = 0.0;
        $rets = [];
        foreach ($this->list as $s) {
            $t = $s->getTicker();
            $cap = (float) $s->getPrice() * (float) $s->getSharesOutstanding();
            $prev = $this->lastCap[$t] ?? 0.0;
            if (!$s->isBankrupt() && $prev > 0.0 && $cap > 0.0) {
                $num += $prev * ($cap / $prev - 1.0);
                $den += $prev;
                $rets[$t] = log($cap / $prev);
            }
            $this->lastCap[$t] = $cap;
            if ($this->tick === 1) {
                $this->out['cap1'][$t] = $cap;
            }
        }
        $ri = $den > 0.0 ? $num / $den : 0.0;
        $this->index *= 1.0 + $ri;
        if ($this->tick > $this->tpy) {
            foreach ($rets as $t => $r) {
                $a = $this->out['rv'][$t] ?? [0, 0.0, 0.0];
                $this->out['rv'][$t] = [$a[0] + 1, $a[1] + $r, $a[2] + $r * $r];
            }
        }
        $row = ['i' => $this->index];
        foreach (self::FIRMS as $t) {
            $row[$t] = (float) $this->stocks[$t]->getPrice() * (float) $this->stocks[$t]->getSharesOutstanding();
        }
        $this->out['daily'][] = $row;
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $s = $report->getStock();
        $t = $s->getTicker();
        $q = (string) round($this->currentTime, 2);
        // Every firm: revenue, operating margin and the rival share drain it booked (for the overlap-rule arms).
        $this->out['all'][$t][] = [
            round($this->currentTime, 3), (float) $report->getRevenue(), (float) $report->getOperatingMargin(),
            (float) (($report->getReportedKpis() ?? [])['rival_share_drain'] ?? 0.0),
        ];
        if ((float) $s->getEarningsPerShare() > 0.0) {
            $this->out['pe'][$q][$t] = (float) $s->getPrice() / (float) $s->getEarningsPerShare();
        }
        if (in_array($t, self::FIRMS, true)) {
            $m = $this->currentMacro;
            $this->out['q'][$t][] = [
                't' => round($this->currentTime, 3), 'rev' => (float) $report->getRevenue(),
                'om' => (float) $report->getOperatingMargin(), 'ni' => (float) $report->getNetIncome(),
                'eps' => (float) $s->getEarningsPerShare(), 'px' => (float) $s->getPrice(),
                'bvps' => (float) $s->getBookValuePerShare(), 'sh' => (float) $s->getSharesOutstanding(),
                'eq' => (float) $report->getEquity(), 'debt' => (float) $report->getTotalDebt(),
                'rating' => $s->getCreditRating(), 'qid' => $s->getQuartersInDefault(), 'pd' => $s->isPaymentDefault(),
                'kpi' => $report->getReportedKpis() ?? [], 'streams' => $report->getRevenueStreams() ?? [],
                'ebit' => (float) $report->getEbit(),
                'ddg' => $m?->domesticDemandGapEma ?? 0.0,
                'ib' => $m !== null ? $m->interbankLiquiditySpreadEma - $m->interbankLiquiditySpreadTrend : 0.0,
                'gold' => $m?->goldPriceIndexEma ?? 0.0,
                'ugap' => $m !== null ? $m->unemploymentRateEma - $m->nairuEma : 0.0,
                'gsi' => $m?->governmentSpendingIndexEma ?? 0.0,
            ];
        }

        return $row;
    }

    protected function onEvent(StockEvent $event): void
    {
        $t = $event->getStock()->getTicker();
        if (!in_array($t, self::FIRMS, true)) {
            return;
        }
        $type = $event->getEventType();
        $this->out['events'][$t][$type] = ($this->out['events'][$t][$type] ?? 0) + 1;
        if ($type === 'EARNINGS') {
            foreach (self::SHOCKS as $name => $re) {
                if (preg_match($re, $event->getDescription())) {
                    $this->out['shocks'][$t][$name] = ($this->out['shocks'][$t][$name] ?? 0) + 1;
                }
            }
        }
    }

    protected function quarterRows(): bool
    {
        return false;
    }

    /** Keeps the run record's reorganisations, payment defaults and treasury distress lines in the topic output. */
    protected function record(array $record): array
    {
        $this->out['reorgs'] = $record['reorgs'];
        $this->out['defaults'] = $record['defaults'];
        $this->out['distress_lines'] = array_intersect_key($record['events'], array_flip(self::FIRMS));
        // Board-wide strict distress lines (default, rescue, covenant, restructuring, liquidation), per ticker.
        $this->out['distress_all'] = array_filter(array_map(
            static fn(array $ls): int => count(array_filter($ls, static fn(array $l): bool => (bool) preg_match('/default|stave off|Chapter|liquidat|rescue|covenant|restructur/i', $l['d']))),
            $record['events'],
        ));

        return $record;
    }

    protected function onEnd(): void
    {
        $this->out['dead'] = $this->dead;
        $this->out['seed'] = $this->seed;
        $path = getenv('SECFIRMS_OUT');
        if ($path) {
            file_put_contents((string) $path, (string) json_encode($this->out, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}
