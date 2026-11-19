<?php

declare(strict_types=1);

use App\Entity\CorporateReport;
use App\Service\Corporate\EarningsEngine;

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * Analyst anchor roll: ARM=base strips EarningsEngine::STATE_LAST_MARKET_REVENUE from every stock before each stock
 * update, so the consensus falls back to the old expected-revenue roll; ARM=fix leaves the working tree as is.
 * Each quarter row gains the consensus revenue and EPS and the reported EPS.
 */
final class AnalystAnchorHarnessTest extends BoardRun
{
    /** Keys removed in base (each one a report that wrote it), and keys still present after the clear (must be 0). */
    private int $cleared = 0;
    private int $leftAfterClear = 0;

    protected function beforeStocks(): void
    {
        if (getenv('ARM') !== 'base') {
            return;
        }
        foreach ($this->stocks as $s) {
            $z = $s->getEarningsMomentumZ();
            if (is_array($z) && array_key_exists(EarningsEngine::STATE_LAST_MARKET_REVENUE, $z)) {
                unset($z[EarningsEngine::STATE_LAST_MARKET_REVENUE]);
                $s->setEarningsMomentumZ($z);
                $this->cleared++;
            }
            $z2 = $s->getEarningsMomentumZ() ?? [];
            if (array_key_exists(EarningsEngine::STATE_LAST_MARKET_REVENUE, $z2)) {
                $this->leftAfterClear++;
            }
        }
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $row['crev'] = (float) $report->getConsensusRevenue();
        $row['ceps'] = (float) $report->getConsensusEps();
        $row['reps'] = (float) $report->getReportedEps();

        return $row;
    }

    protected function record(array $record): array
    {
        $record['arm'] = getenv('ARM') ?: 'fix';
        $record['cleared'] = $this->cleared;
        $record['left_after_clear'] = $this->leftAfterClear;

        return $record;
    }
}
