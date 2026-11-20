<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * Board scorecard plus risk-ladder and relever-volatility gates: split-adjusted realised volatility (full-run and
 * per-year), per-year leverage multiplier L, active sigma_LR, peak spot volatility, mispricing |log(px/fv)| > 0.25
 * tick share, and quarterly consensus EPS/revenue for EPS beat rate, revenue surprise SD and >10% revenue jump counts.
 * Read-only probe; works on both HEAD (BASE) and the working tree.
 */
final class RiskLadderHarnessTest extends BoardRun
{
    /**
     * @var array<string, array{industry: string, sector: string, model: string, fin: bool, beta: float, open_vol: float}>
     */
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
    private array $sweeps = [];
    /** @var array<string, int> */
    private array $mispGt25 = [];
    /** @var array<string, int> */
    private array $mispN = [];
    /**
     * @var array<string, array<int, array{rvSq: float, rvN: int, lSum: float, lMin: float, lMax: float, lN: int, sigSum: float, sigMax: float, cvolMax: float}>>
     */
    private array $yearly = [];

    protected function onStart(): void
    {
        foreach ($this->stocks as $t => $s) {
            $strategy = App\Data\Company\Sectors::strategyFor($s->getIndustry());
            $this->meta[$t] = [
                'industry' => (string) $s->getIndustry(),
                'sector' => $s->getSector(),
                'model' => (new ReflectionClass($strategy))->getShortName(),
                'fin' => $strategy->isFinancial(),
                'beta' => (float) $s->getBeta(),
                'open_vol' => (float) $s->getVolatility(),
            ];
            $this->prevPx[$t] = (float) $s->getPrice();
            $this->prevSh[$t] = (float) $s->getSharesOutstanding();
            $this->yearly[$t] = [];
        }
    }

    protected function afterStocks(array $result): void
    {
        if ($this->currentMacro !== null && $this->tick % max(1, intdiv($this->tpy, 4)) === 0) {
            $this->macro[] = [round($this->currentTime, 3), $this->currentMacro->nominalGdpIndex, $this->reading->equityMarketCap];
        }

        $year = intdiv(max(0, $this->tick - 1), max(1, $this->tpy));

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
            if (!isset($this->yearly[$t][$year])) {
                $this->yearly[$t][$year] = [
                    'rvSq' => 0.0,
                    'rvN' => 0,
                    'lSum' => 0.0,
                    'lMin' => INF,
                    'lMax' => -INF,
                    'lN' => 0,
                    'sigSum' => 0.0,
                    'sigMax' => 0.0,
                    'cvolMax' => 0.0,
                ];
            }

            $px = (float) $s->getPrice();
            $sh = (float) $s->getSharesOutstanding();
            if (($this->prevPx[$t] ?? 0.0) > 0.0 && $px > 0.0) {
                $ratio = ($this->prevSh[$t] ?? 0.0) > 0.0 ? ($sh / $this->prevSh[$t]) : 1.0;
                $splitMult = ($ratio > 1.4 || ($ratio > 0.0 && $ratio < (1.0 / 1.4))) ? $ratio : 1.0;
                $r = log(($px * $splitMult) / $this->prevPx[$t]);
                $r2 = $r * $r;
                $this->rvSq[$t] = ($this->rvSq[$t] ?? 0.0) + $r2;
                $this->rvN[$t] = ($this->rvN[$t] ?? 0) + 1;
                $this->yearly[$t][$year]['rvSq'] += $r2;
                $this->yearly[$t][$year]['rvN']++;
            }
            $this->prevPx[$t] = $px;
            $this->prevSh[$t] = $sh;

            $betaL = $s->getLeveredBeta();
            $baseBeta = $this->meta[$t]['beta'];
            if ($this->meta[$t]['fin']) {
                $L = 1.0;
            } elseif ($betaL !== null && abs($baseBeta) >= 1e-9) {
                $L = $betaL / $baseBeta;
            } else {
                $L = null;
            }

            $sigLR = method_exists($s, 'getLeveredVolatility') && $s->getLeveredVolatility() !== null
                ? (float) $s->getLeveredVolatility()
                : (float) $s->getVolatility();
            $cvol = (float) ($s->getCurrentVolatility() ?? $s->getVolatility());

            if ($L !== null) {
                $this->yearly[$t][$year]['lSum'] += $L;
                if ($L < $this->yearly[$t][$year]['lMin']) {
                    $this->yearly[$t][$year]['lMin'] = $L;
                }
                if ($L > $this->yearly[$t][$year]['lMax']) {
                    $this->yearly[$t][$year]['lMax'] = $L;
                }
                $this->yearly[$t][$year]['lN']++;
            }
            $this->yearly[$t][$year]['sigSum'] += $sigLR;
            if ($sigLR > $this->yearly[$t][$year]['sigMax']) {
                $this->yearly[$t][$year]['sigMax'] = $sigLR;
            }
            if ($cvol > $this->yearly[$t][$year]['cvolMax']) {
                $this->yearly[$t][$year]['cvolMax'] = $cvol;
            }

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
        // TreasuryEngine::processDeleveragingSweep()'s junk test: dynamic spread past the through-the-cycle spread
        // at the current rating + 11bp, read on the trailing health at report time.
        if ($this->debtEngine !== null && $this->currentMacro !== null) {
            $mx = $this->debtEngine->analyzeTrailingDebtHealth($report->getStock(), $this->currentMacro)->rawMetrics;
            $base = $mx->baselineSpread ?? $mx->dynamicSpread;
            $row['dsp'] = round($mx->dynamicSpread, 6);
            $row['bsp'] = round($base, 6);
            $row['junk'] = $mx->dynamicSpread > $base + 0.0011;
        }

        return $row;
    }

    /** Newsworthy forced-deleveraging sweeps ("Swept $X cash to aggressively deleverage"), per ticker. */
    protected function onEvent(App\Entity\StockEvent $event): void
    {
        $n = preg_match_all('/aggressively deleverage/i', (string) $event->getDescription());
        if ($n > 0) {
            $t = $event->getStock()->getTicker();
            $this->sweeps[$t] = ($this->sweeps[$t] ?? 0) + $n;
        }
    }

    protected function record(array $record): array
    {
        $rvol = [];
        $misp = [];
        $yearlyOut = [];
        foreach ($this->stocks as $t => $s) {
            $n = $this->rvN[$t] ?? 0;
            $rvol[$t] = $n > 0 ? sqrt(($this->rvSq[$t] / $n) / $this->dt) : null;
            $mn = $this->mispN[$t] ?? 0;
            $misp[$t] = ['gt25' => $this->mispGt25[$t] ?? 0, 'n' => $mn];

            $rows = [];
            foreach ($this->yearly[$t] as $y => $acc) {
                if ($acc['rvN'] <= 0 || $acc['lN'] <= 0) {
                    continue;
                }
                $rows[$y] = [
                    'rv' => round(sqrt(($acc['rvSq'] / $acc['rvN']) / $this->dt), 5),
                    'l' => round($acc['lSum'] / $acc['lN'], 5),
                    'l_min' => round($acc['lMin'], 5),
                    'l_max' => round($acc['lMax'], 5),
                    'sig' => round($acc['sigSum'] / $acc['lN'], 5),
                    'sig_max' => round($acc['sigMax'], 5),
                    'cvol_max' => round($acc['cvolMax'], 5),
                    'n' => $acc['rvN'],
                ];
            }
            $yearlyOut[$t] = $rows;
        }

        return $record + [
            'meta' => $this->meta,
            'macro' => $this->macro,
            'gdp_usd' => App\Service\Math\MacroConstants::DISTRICT_GDP_USD,
            'rvol' => $rvol,
            'misp' => $misp,
            'yearly' => $yearlyOut,
            'sweeps' => $this->sweeps,
        ];
    }
}
