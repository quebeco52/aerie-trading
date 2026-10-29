<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;

/**
 * Behaviour check for FINC (Finch Securities, retail broker on BrokerageBusinessModel). Read-only probe: writes one
 * JSON per seed to ROOKERY_OUT with the opening, every FINC quarter (revenue, margin, income, price, book, rating,
 * streams, the macro the streams read) and the board's opening and final caps.
 */
final class RookeryHarnessTest extends BoardRun
{
    private const FIRM = 'ROOK';

    /** @var array<string, mixed> */
    private array $out = ['opening' => [], 'q' => []];

    protected function onStart(): void
    {
        $s = $this->stocks[self::FIRM];
        $board = 0.0;
        foreach ($this->list as $x) {
            $board += (float) $x->getPrice() * (float) $x->getSharesOutstanding();
        }
        $this->out['opening'] = [
            'px' => (float) $s->getPrice(), 'eps' => (float) $s->getEarningsPerShare(),
            'bvps' => (float) $s->getBookValuePerShare(), 'sh' => (float) $s->getSharesOutstanding(),
            'rev' => (float) $s->getTotalRevenue(), 'eq' => (float) $s->getTotalEquity(),
            'debt' => (float) $s->getTotalDebt(), 'rating' => $s->getCreditRating(), 'board' => $board,
        ];
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $s = $report->getStock();
        if ($s->getTicker() !== self::FIRM) {
            return $row;
        }
        $m = $this->currentMacro;
        $this->out['q'][] = [
            't' => round($this->currentTime, 3), 'rev' => (float) $report->getRevenue(),
            'om' => (float) $report->getOperatingMargin(), 'ni' => (float) $report->getNetIncome(),
            'eps' => (float) $s->getEarningsPerShare(), 'px' => (float) $s->getPrice(),
            'bvps' => (float) $s->getBookValuePerShare(), 'sh' => (float) $s->getSharesOutstanding(),
            'eq' => (float) $report->getEquity(), 'debt' => (float) $report->getTotalDebt(),
            'rating' => $s->getCreditRating(), 'streams' => $report->getRevenueStreams() ?? [],
            'vix' => $m?->marketVolatilityEma ?? 0.0, 'rate' => $m?->policyRateEma ?? 0.0,
            'gap' => $m?->outputGapEma ?? 0.0,
            'to' => $m?->boardTurnoverEma ?? 0.0, 'to_tr' => $m?->boardTurnoverTrend ?? 0.0,
            'to_dev' => $m?->boardTurnoverDeviation() ?? 0.0, 'w_dev' => $m?->equityWealthDeviation() ?? 0.0,
            'deal_dev' => $m?->dealActivityDeviation() ?? 0.0, 'board' => $m?->equityMarketCap ?? 0.0,
            'cvar' => (float) (($s->getEarningsMomentumZ() ?? [])['state:commodity_variance'] ?? 0.0),
            'cvar_tr' => (float) (($s->getEarningsMomentumZ() ?? [])['state:commodity_variance_trend'] ?? 0.0),
            'float' => (float) $s->getPublicFloatPercentage(),
        ];

        return $row;
    }

    protected function quarterRows(): bool
    {
        return false;
    }

    protected function record(array $record): array
    {
        $this->out['reorgs'] = $record['reorgs'];
        $this->out['defaults'] = $record['defaults'];
        $this->out['distress'] = $record['events'][self::FIRM] ?? [];

        return $record;
    }

    protected function onEnd(): void
    {
        $this->out['dead'] = $this->dead;
        $this->out['seed'] = $this->seed;
        $path = getenv('ROOKERY_OUT');
        if ($path) {
            file_put_contents((string) $path, (string) json_encode($this->out, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}
