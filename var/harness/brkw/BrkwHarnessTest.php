<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * Breakwater Trust (BRKW) quarterly book, beta, valuation and payouts on the live-macro board. Read-only probe:
 * stored fields, the anchor-stake ledger's stake value, and CAPM at the stored levered beta.
 * BRKW_OUT=<jsonl>: one row per quarter end (state) and one per BRKW report (kind 'rep'); events kind 'ev'.
 */
final class BrkwHarnessTest extends BoardRun
{
    private const T = 'BRKW';

    private float $pfv = 0.0;

    private function emit(array $row): void
    {
        $out = getenv('BRKW_OUT');
        if ($out) {
            file_put_contents($out, json_encode($row, JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND);
        }
    }

    protected function afterStocks(array $result): void
    {
        foreach ($result['updates'] as $u) {
            if (($u['ticker'] ?? '') === self::T) {
                $this->pfv = (float) ($u['perceived_fair_value'] ?? 0.0);
            }
        }
        if ($this->tick % max(1, intdiv($this->tpy, 4)) !== 0 || !isset($this->stocks[self::T])) {
            return;
        }
        $s = $this->stocks[self::T];
        $m = $this->currentMacro;
        $ledger = (new ReflectionProperty(App\Service\Market\Pricing\StockTracker::class, 'anchorStakes'))->getValue($this->tracker);
        $stakeValue = $ledger->resolveStakeValue($s);
        $mbvps = $ledger->resolveMarkedBookValuePerShare($s);
        $shares = max(1.0, (float) $s->getSharesOutstanding());
        $lb = (float) ($s->getLeveredBeta() ?? 0.0);
        $this->emit([
            'kind' => 'q', 't' => round($this->currentTime, 3),
            'px' => (float) $s->getPrice(), 'pfv' => $this->pfv, 'sh' => $shares,
            'eq' => (float) $s->getTotalEquity(), 'stakes' => (float) ($s->getListedStakesCarrying() ?? 0.0),
            'stake_mv' => $stakeValue, 'mbvps' => $mbvps,
            'marked_share' => ($stakeValue !== null && $mbvps !== null && $mbvps > 0.0) ? min(1.0, $stakeValue / ($mbvps * $shares)) : null,
            'feq' => $s->getFairEquityValue(), 'lbeta' => $lb, 'beta' => (float) $s->getBeta(),
            'coe' => $m !== null ? App\Service\Math\Valuation::calculateCAPM($m->equityRiskFreeRate, $lb, App\Service\Math\MacroConstants::BASE_EQUITY_RISK_PREMIUM) : null,
            'roic' => (float) ($s->getCurrentRoic() ?? 0.0), 'roicTtm' => (float) $s->getRoicTtm(),
            'debt' => (float) $s->getTotalDebt(), 'tni' => (float) $s->getTotalNetIncome(),
            'dead' => $s->isBankrupt(),
        ]);
    }

    protected function reportRow(array $row, App\Entity\CorporateReport $report): array
    {
        if ($report->getStock()->getTicker() === self::T) {
            $this->emit(['kind' => 'rep', 't' => round($this->currentTime, 3), 'eva' => (float) ($report->getEva() ?? 0.0),
                'roic_rep' => (float) ($report->getRoic() ?? 0.0), 'ni' => (float) $report->getNetIncome(),
                'div' => (float) $report->getDividendPaid(), 'bb' => (float) $report->getStockBuybacks(),
                'cash' => (float) $report->getTreasury(), 'debt' => (float) $report->getTotalDebt(),
                'eq' => (float) $report->getEquity(), 'px' => (float) $report->getStock()->getPrice(),
                'sh' => (float) $report->getStock()->getSharesOutstanding()]);
        }

        return $row;
    }

    protected function onEvent(App\Entity\StockEvent $event): void
    {
        $t = $event->getStock()->getTicker();
        $type = $event->getEventType();
        $d = (string) $event->getDescription();
        if ($t === self::T || $type === 'DIVESTITURE' || preg_match('/ executed a \$/', $d)) {
            $this->emit(['kind' => 'ev', 't' => round($this->currentTime, 3), 'ticker' => $t, 'type' => $type, 'desc' => substr($d, 0, 200)]);
        }
    }
}
