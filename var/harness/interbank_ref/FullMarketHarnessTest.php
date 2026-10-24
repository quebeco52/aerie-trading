<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * interbank_ref: the whole-board run (var/harness/lib/BoardRun.php) with lender returns against target, funding
 * stress and market access on each report row; a split-restated cap-weighted board return per tick, one macro row a
 * quarter and the non-finite count in the record. VR_OUT=<json>: per-tick price, reported vol, spread, ADV, volume
 * and cap per name, reported against consensus EPS per report, and the 30-day option skew each quarter.
 */
final class FullMarketHarnessTest extends BoardRun
{
    /** @var array<string, array{0: float, 1: float}> last price and cap per name */
    private array $irPrev = [];
    /** @var list<float> */
    private array $irIdx = [];
    /** @var list<array<string, float>> */
    private array $irMacro = [];
    /** @var array<int, float> */
    private array $irMcoe0 = [];
    private int $nonFinite = 0;
    /** @var array<string, mixed> */
    private array $vr = [];

    protected function afterStocks(array $result): void
    {
        $tick = $this->tick;
        $tpy = $this->tpy;
        $m = $this->currentMacro;
        // Cap-weighted board return per tick (last tick's caps as weights), non-finite counts, and one macro row a
        // quarter (plus tick 1, for the pairing check).
        $wsum = 0.0;
        $rsum = 0.0;
        foreach ($result['updates'] as $u) {
            $tk = (string) $u['ticker'];
            $px = (float) $u['price'];
            $cap = (float) ($u['market_cap'] ?? 0.0);
            if (!is_finite($px) || !is_finite($cap)) {
                $this->nonFinite++;
                continue;
            }
            if (empty($u['is_bankrupt']) && isset($this->irPrev[$tk]) && $this->irPrev[$tk][0] > 0.0 && $this->irPrev[$tk][1] > 0.0 && $px > 0.0) {
                $ratio = $px / $this->irPrev[$tk][0];
                // A split moves the share count by its factor and the price by the inverse: restate it at constant
                // shares, or a 4-for-1 reads as a 75% fall.
                $shareRatio = ($cap / $px) / ($this->irPrev[$tk][1] / $this->irPrev[$tk][0]);
                if ($shareRatio >= 1.5 || $shareRatio <= 1.0 / 1.5) {
                    $ratio *= $shareRatio;
                }
                $wsum += $this->irPrev[$tk][1];
                $rsum += $this->irPrev[$tk][1] * ($ratio - 1.0);
            }
            $this->irPrev[$tk] = [$px, $cap];
        }
        $this->irIdx[] = $wsum > 0.0 ? $rsum / $wsum : 0.0;
        if ($tick === 1) {
            $this->irMcoe0 = [$m->marketCostOfEquity(), (new MacroStateDTO())->marketCostOfEquity()];
        }
        if ($tick === 1 || $tick % intdiv($tpy, 4) === 0) {
            $this->irMacro[] = ['t' => round($tick / $tpy, 3), 'pol' => $m->policyRate, 'pole' => $m->policyRateEma,
                'ib' => $m->interbankLiquiditySpreadEma, 'ibr' => $m->interbankLiquiditySpread, 'gap' => $m->outputGap,
                'infl' => $m->inflation, 'u' => $m->unemploymentRate, 'erp' => $m->equityRiskPremium, 'vol' => $m->marketVolatility];
        }
        if (getenv('VR_OUT')) {
            foreach ($result['updates'] as $u) {
                if (!empty($u['is_bankrupt'])) {
                    continue;
                }
                $this->vr['tk'][$u['ticker']][$tick] = [(float) $u['price'], (float) $u['current_volatility'] / 100.0,
                    (float) ($u['spread_bps'] ?? 0.0), (float) ($u['adv_shares'] ?? 0.0), (float) ($u['volume'] ?? 0.0),
                    (float) ($u['market_cap'] ?? 0.0)];
            }
            if ($tick % intdiv($tpy, 4) === 0) {
                $opt = static::getContainer()->get(App\Service\Market\Option\OptionPricingEngine::class);
                foreach ($this->list as $s) {
                    if (!$s->isBankrupt()) {
                        $sf = $opt->surfaceFor($s, 30.0 / 360.0);
                        $this->vr['skew'][$s->getTicker()][$tick] = [$sf['skewness'], $sf['atm_volatility']];
                    }
                }
            }
        }
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        if (getenv('VR_OUT')) {
            // Reported EPS against consensus, keyed to the tick the report lands on.
            $this->vr['er'][] = [$report->getStock()->getTicker(), (int) round($this->currentTime * $this->tpy),
                $report->getReportedEps() !== null ? (float) $report->getReportedEps() : null,
                $report->getConsensusEps() !== null ? (float) $report->getConsensusEps() : null];
        }
        $m = $this->currentMacro;

        // Lender returns against target, funding stress and market access.
        return self::insertAfter($row, 'aris', [
            'pti' => (float) $report->getPreTaxIncome(),
            'teq' => $report->getStock()->getTangibleEquity(),
            'broe' => (float) ($report->getStock()->getBaselineRoe() ?? 0.0),
            'ib' => $m?->interbankLiquiditySpreadEma ?? 0.0,
            'pol' => $m?->policyRateEma ?? 0.0,
            'open' => $m !== null && $this->debtEngine !== null ? $this->debtEngine->primaryMarketOpenTo($report->getStock(), $m) : null,
        ]);
    }

    protected function record(array $record): array
    {
        return $record + ['idx' => $this->irIdx, 'macro' => $this->irMacro, 'mcoe0' => $this->irMcoe0, 'nonfinite' => $this->nonFinite];
    }

    protected function onEnd(): void
    {
        if (getenv('VR_OUT')) {
            $this->vr['dead'] = $this->dead;
            file_put_contents((string) getenv('VR_OUT'), json_encode($this->vr));
        }
    }
}
