<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;

/**
 * Behaviour check for WELL (Wellspring Life, LifeInsurerBusinessModel), HONY (Honeyguide Partners,
 * InsuranceBrokerBusinessModel) and DOVE (its bond book now split by policy-reserve matching). Read-only probe: writes
 * one JSON per seed to LIFE_BROKER_OUT with each firm's opening and every quarter (revenue, margin, income, price,
 * book, rating, streams, float, securities mark and its split) and the macro the streams read.
 */
final class LifeBrokerHarnessTest extends BoardRun
{
    private const FIRMS = ['WELL', 'HONY', 'DOVE'];

    /** @var array<string, mixed> */
    private array $out = ['opening' => [], 'q' => []];

    protected function onStart(): void
    {
        $board = 0.0;
        foreach ($this->list as $x) {
            $board += (float) $x->getPrice() * (float) $x->getSharesOutstanding();
        }
        foreach (self::FIRMS as $t) {
            $s = $this->stocks[$t];
            $this->out['opening'][$t] = [
                'px' => (float) $s->getPrice(), 'eps' => (float) $s->getEarningsPerShare(),
                'bvps' => (float) $s->getBookValuePerShare(), 'sh' => (float) $s->getSharesOutstanding(),
                'rev' => (float) $s->getTotalRevenue(), 'eq' => (float) $s->getTotalEquity(),
                'debt' => (float) $s->getTotalDebt(), 'float' => (float) $s->getCustomerDeposits(),
                'htm' => $s->getSecuritiesHtmShare(), 'rating' => $s->getCreditRating(), 'board' => $board,
            ];
        }
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $s = $report->getStock();
        $t = $s->getTicker();
        $shares = max(1.0, (float) $s->getSharesOutstanding());
        // Every firm's price against the engine's fair value, for the board's discount over time.
        $this->out['pf'][$t][] = [round($this->currentTime, 3), (float) $s->getPrice(), (float) ($s->getFairEquityValue() ?? 0.0) / $shares, (float) ($s->getLeveredBeta() ?? 1.0), $this->currentMacro?->equityValuationShift ?? 0.0];
        if (!in_array($t, self::FIRMS, true)) {
            return $row;
        }
        $m = $this->currentMacro;
        $mom = $s->getEarningsMomentumZ() ?? [];
        $this->out['q'][$t][] = [
            't' => round($this->currentTime, 3), 'rev' => (float) $report->getRevenue(),
            'om' => (float) $report->getOperatingMargin(), 'ni' => (float) $report->getNetIncome(),
            'eps' => (float) $s->getEarningsPerShare(), 'px' => (float) $s->getPrice(),
            'bvps' => (float) $s->getBookValuePerShare(), 'sh' => (float) $s->getSharesOutstanding(),
            'eq' => (float) $report->getEquity(), 'debt' => (float) $report->getTotalDebt(),
            'rating' => $s->getCreditRating(), 'streams' => $report->getRevenueStreams() ?? [],
            'float' => (float) $s->getCustomerDeposits(), 'stat' => $s->getStatutorySurplus(),
            'mark' => (float) $s->getUnrealizedSecuritiesMark(), 'rec' => $s->getRecognizedSecuritiesMark(),
            'cy' => $s->getSecuritiesCarryingYield() ?? 0.0, 'life_res' => (float) ($mom['state:reserves:life'] ?? 0.0),
            'ann_tr' => (float) ($mom['state:life:annuity_yield_trend'] ?? 0.0),
            'rate' => $m?->policyRateEma ?? 0.0, 'neutral' => $m?->perceivedNeutralRate ?? 0.0,
            'y10' => $m?->yield10yEma ?? 0.0, 'y30' => $m?->yield30yEma ?? 0.0,
            'ins' => $m?->insuranceRateLevel ?? 0.0, 'ins_tr' => $m?->insuranceRateTrend ?? 0.0,
            'w_dev' => $m?->equityWealthDeviation() ?? 0.0, 'gap' => $m?->outputGapEma ?? 0.0,
            'board' => $m?->equityMarketCap ?? 0.0,
            'ebit' => (float) $report->getEbit(), 'ii' => (float) $report->getInterestIncome(),
            'ie' => (float) $report->getInterestExpense(), 'pti' => (float) $report->getPreTaxIncome(),
            'tax' => (float) $report->getTaxPaid(), 'gwi' => (float) $report->getGoodwillImpairment(),
            'asl' => (float) $report->getAssetSaleLoss(), 'gw' => (float) $report->getGoodwill(),
            'treas' => (float) $report->getTreasury(),
            'fv' => (float) ($s->getFairEquityValue() ?? 0.0) / $shares, 'tgt' => (float) $s->getAnalystPriceTarget(),
            'roe_ttm' => (float) $s->getRoeTtm(), 'cur_roe' => (float) $s->getCurrentRoe(), 'ke' => (float) $report->getCostOfEquity(),
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
        foreach (self::FIRMS as $t) {
            $this->out['distress'][$t] = $record['events'][$t] ?? [];
        }

        return $record;
    }

    protected function onEnd(): void
    {
        $this->out['dead'] = $this->dead;
        $this->out['seed'] = $this->seed;
        $path = getenv('LIFE_BROKER_OUT');
        if ($path) {
            file_put_contents((string) $path, (string) json_encode($this->out, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}
