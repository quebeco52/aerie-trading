<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * Board scorecard plus risk-ladder gates: realised split-adjusted volatility per firm, mispricing |log(px/fv)| > 0.25
 * tick share, and quarterly consensus EPS/revenue for EPS beat rate, revenue surprise SD and >10% revenue jump counts.
 * Read-only probe; works on both HEAD (BASE) and the working tree.
 */
final class RiskLadderHarnessTest extends BoardRun
{
    /** @var array<string, array{industry: string, sector: string, model: string, fin: bool}> */
    private array $meta = [];
    /** @var list<array{0: float, 1: float, 2: float}> Time, nominal GDP index, board cap. */
    private array $macro = [];
    /** @var array<string, float> */
    private array $prevPx = [];
    /** @var array<string, float> */
    private array $prevSh = [];
    /** @var array<string, float> */
    private array $rvSq = [];
    /** @var array<string, int> */
    private array $rvN = [];
    /** @var array<string, int> */
    private array $mispGt25 = [];
    /** @var array<string, int> */
    private array $mispN = [];

    protected function onStart(): void
    {
        foreach ($this->stocks as $t => $s) {
            $strategy = App\Data\Company\Sectors::strategyFor($s->getIndustry());
            $this->meta[$t] = [
                'industry' => (string) $s->getIndustry(),
                'sector' => $s->getSector(),
                'model' => (new ReflectionClass($strategy))->getShortName(),
                'fin' => $strategy->isFinancial(),
            ];
            $this->prevPx[$t] = (float) $s->getPrice();
            $this->prevSh[$t] = (float) $s->getSharesOutstanding();
        }
    }

    protected function afterStocks(array $result): void
    {
        if ($this->currentMacro !== null && $this->tick % max(1, intdiv($this->tpy, 4)) === 0) {
            $this->macro[] = [round($this->currentTime, 3), $this->currentMacro->nominalGdpIndex, $this->reading->equityMarketCap];
        }

        $fvByTicker = [];
        foreach (($result['updates'] ?? []) as $u) {
            if (!empty($u['ticker']) && empty($u['is_bankrupt'])) {
                $fvByTicker[$u['ticker']] = (float) ($u['perceived_fair_value'] ?? 0.0);
            }
        }

        foreach ($this->stocks as $t => $s) {
            if ($s->isBankrupt()) {
                continue;
            }
            $px = (float) $s->getPrice();
            $sh = (float) $s->getSharesOutstanding();
            if (($this->prevPx[$t] ?? 0.0) > 0.0 && $px > 0.0) {
                $ratio = ($this->prevSh[$t] ?? 0.0) > 0.0 ? ($sh / $this->prevSh[$t]) : 1.0;
                $splitMult = ($ratio > 1.4 || ($ratio > 0.0 && $ratio < (1.0 / 1.4))) ? $ratio : 1.0;
                $r = log(($px * $splitMult) / $this->prevPx[$t]);
                $this->rvSq[$t] = ($this->rvSq[$t] ?? 0.0) + ($r * $r);
                $this->rvN[$t] = ($this->rvN[$t] ?? 0) + 1;
            }
            $this->prevPx[$t] = $px;
            $this->prevSh[$t] = $sh;

            $fv = $fvByTicker[$t] ?? 0.0;
            if ($px > 0.0 && $fv > 0.0) {
                $this->mispN[$t] = ($this->mispN[$t] ?? 0) + 1;
                if (abs(log($px / $fv)) > 0.25) {
                    $this->mispGt25[$t] = ($this->mispGt25[$t] ?? 0) + 1;
                }
            }
        }
    }

    protected function reportRow(array $row, App\Entity\CorporateReport $report): array
    {
        $row['ceps'] = $report->getConsensusEps() !== null ? (float) $report->getConsensusEps() : null;
        $row['reps'] = $report->getReportedEps() !== null ? (float) $report->getReportedEps() : null;
        $row['crev'] = $report->getConsensusRevenue() !== null ? (float) $report->getConsensusRevenue() : null;

        return $row;
    }

    protected function record(array $record): array
    {
        $rvol = [];
        $misp = [];
        foreach ($this->stocks as $t => $s) {
            $n = $this->rvN[$t] ?? 0;
            $rvol[$t] = $n > 0 ? sqrt(($this->rvSq[$t] / $n) / $this->dt) : null;
            $mn = $this->mispN[$t] ?? 0;
            $misp[$t] = ['gt25' => $this->mispGt25[$t] ?? 0, 'n' => $mn];
        }

        return $record + [
            'meta' => $this->meta,
            'macro' => $this->macro,
            'gdp_usd' => App\Service\Math\MacroConstants::DISTRICT_GDP_USD,
            'rvol' => $rvol,
            'misp' => $misp,
        ];
    }
}
