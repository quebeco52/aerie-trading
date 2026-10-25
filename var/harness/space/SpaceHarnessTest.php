<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

use App\Entity\CorporateReport;
use App\Entity\StockEvent;

/**
 * Behaviour check for the space upstart SKYL beside GRIP. Read-only probe:
 * writes one JSON per seed to SPACE_OUT with the opening, every quarter of the four firms (KPIs, stream revenue,
 * the macro the bookings read), a daily price path with a cap-weighted board index, and industry/sector revenue.
 */
final class SpaceHarnessTest extends BoardRun
{
    private const FIRMS = ['SKYL', 'GRIP'];

    /** @var array<string, mixed> */
    private array $out = ['opening' => [], 'q' => [], 'daily' => [], 'agg' => [], 'events' => []];
    private float $index = 1.0;
    /** @var array<string, float> */
    private array $lastCap = [];

    protected function onStart(): void
    {
        foreach (self::FIRMS as $t) {
            $s = $this->stocks[$t];
            $this->out['opening'][$t] = [
                'px' => (float) $s->getPrice(), 'eps' => (float) $s->getEarningsPerShare(),
                'bvps' => (float) $s->getBookValuePerShare(), 'rev' => (float) $s->getTotalRevenue(),
                'om' => (float) ($s->getOperatingMargin() ?? 0.0), 'eq' => (float) $s->getTotalEquity(),
                'sector' => $s->getSector(), 'industry' => $s->getIndustry(),
            ];
        }
        foreach ($this->list as $s) {
            $this->out['board'][$s->getTicker()] = [
                'px' => (float) $s->getPrice(), 'eps' => (float) $s->getEarningsPerShare(),
                'bvps' => (float) $s->getBookValuePerShare(), 'beta' => (float) $s->getBeta(),
                'payout' => (float) $s->getTargetPayoutRatio(), 'sector' => $s->getSector(),
                'industry' => $s->getIndustry(),
            ];
        }
    }

    protected function afterStocks(array $result): void
    {
        // Cap-weighted board return over firms alive at both ends of the tick, on market value so a split (shares up,
        // price down) is not a loss. The first tick only records: the open strikes prices after onStart().
        $num = 0.0;
        $den = 0.0;
        foreach ($this->list as $s) {
            $t = $s->getTicker();
            $cap = (float) $s->getPrice() * (float) $s->getSharesOutstanding();
            $prev = $this->lastCap[$t] ?? 0.0;
            if (!$s->isBankrupt() && $prev > 0.0 && $cap > 0.0) {
                $num += $prev * ($cap / $prev - 1.0);
                $den += $prev;
            }
            $this->lastCap[$t] = $cap;
            if ($this->tick === 1) {
                $this->out['cap1'][$t] = $cap;
            }
        }
        $this->index *= 1.0 + ($den > 0.0 ? $num / $den : 0.0);
        $row = ['i' => $this->index];
        foreach (self::FIRMS as $t) {
            $row[$t] = (float) $this->stocks[$t]->getPrice();
            $row[$t . '_sh'] = (float) $this->stocks[$t]->getSharesOutstanding();
        }
        $this->out['daily'][] = $row;
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $s = $report->getStock();
        $t = $s->getTicker();
        $q = (string) round($this->currentTime, 2);
        $rev = (float) $report->getRevenue();
        $ind = (string) $s->getIndustry();
        $this->out['agg'][$q]['ind'][$ind] = ($this->out['agg'][$q]['ind'][$ind] ?? 0.0) + $rev;
        $this->out['agg'][$q]['sec'][$s->getSector()] = ($this->out['agg'][$q]['sec'][$s->getSector()] ?? 0.0) + $rev;
        if ((float) $s->getEarningsPerShare() > 0.0) {
            $this->out['agg'][$q]['pe'][$t] = (float) $s->getPrice() / (float) $s->getEarningsPerShare();
        }
        if (in_array($t, self::FIRMS, true)) {
            $m = $this->currentMacro;
            $this->out['q'][$t][] = [
                't' => round($this->currentTime, 3), 'rev' => $rev, 'om' => (float) $report->getOperatingMargin(),
                'ni' => (float) $report->getNetIncome(), 'eps' => (float) $s->getEarningsPerShare(),
                'px' => (float) $s->getPrice(), 'div' => (float) $report->getDividendPaid(),
                'sh' => (float) $s->getSharesOutstanding(), 'eq' => (float) $report->getEquity(),
                'debt' => (float) $report->getTotalDebt(), 'rating' => $s->getCreditRating(),
                'qid' => $s->getQuartersInDefault(), 'pd' => $s->isPaymentDefault(),
                'kpi' => $report->getReportedKpis() ?? [], 'streams' => $report->getRevenueStreams() ?? [],
                'gap' => $m?->outputGapEma ?? 0.0, 'gap_raw' => $m?->outputGap ?? 0.0,
                'spread' => $m !== null ? $m->macroCreditSpreadEma - $m->macroCreditSpreadTrend : 0.0,
                'deal' => $m?->dealActivityDeviation() ?? 0.0, 'dflt' => $m?->corporateDefaultDeviation() ?? 0.0,
                'epu' => $m?->policyUncertaintyIndexEma ?? 100.0, 'tt' => $m?->totalTime ?? 0.0,
                'sent' => $m?->sentimentResidual() ?? 0.0, 'ecp' => $m?->energyCostPushLag ?? 0.0, 'agri' => $m?->agriculturalCommodityIndexEma ?? 100.0, 'ddg' => $m?->domesticDemandGapEma ?? 0.0,
                'regime' => array_filter($s->getEarningsMomentumZ() ?? [], static fn($k): bool => str_starts_with((string) $k, 'state:regime:') || str_starts_with((string) $k, 'state:broadband_market'), ARRAY_FILTER_USE_KEY),
                'cap' => (float) $s->getPrice() * (float) $s->getSharesOutstanding(), 'cpi' => $m?->consumerPriceLevel ?? 1.0,
                'diag' => $t === 'SKYL' && $m !== null ? $this->growthDiag($s, $m, $report) : null,
                'ceps' => $report->getConsensusEps() !== null ? (float) $report->getConsensusEps() : null,
                'reps' => $report->getReportedEps() !== null ? (float) $report->getReportedEps() : null,
                'crev' => $report->getConsensusRevenue() !== null ? (float) $report->getConsensusRevenue() : null,
                'ebit' => (float) $report->getEbit(),
            ];
        }

        return $row;
    }

    /**
     * Where SKYL's growth could bind, read-only: plant, capital budget gates (hurdle vs baseline ROIC), saturation and
     * the addressable share, the growth rates the model hands the engine, cash flows and leverage.
     *
     * @return array<string, mixed>
     */
    private function growthDiag(\App\Entity\Stock $s, \App\DTO\MacroStateDTO $m, CorporateReport $report): array
    {
        $strategy = \App\Data\Company\Sectors::strategyFor($s->getIndustry());
        $metrics = \App\Service\Corporate\CorporateMetrics::getInstance();
        $ic = $s->getInvestedCapital();
        $hurdle = $this->debtEngine !== null ? $strategy->getHurdleRate($this->debtEngine->analyzeDebtHealth($s, $m)) : null;

        return [
            'ic' => $ic, 'ppe' => $s->getNetPpe(), 'gppe' => (float) $s->getGrossPpe(), 'baseline_roic' => (float) $s->getBaselineRoic(),
            'roic_ttm' => (float) $s->getRoicTtm(), 'hurdle' => $hurdle,
            'applied_hurdle' => $hurdle !== null ? $s->getManagementProfile()->appliedHurdle($hurdle) : null,
            'capex_ratio' => (float) $s->getCapexRatio(), 'reinvest_bias' => $s->getManagementProfile()->reinvestmentBias(),
            'faded_g' => $strategy->getFadedSecularGrowthRate($s, $m->totalTime), 'secular_g' => $strategy->getSecularGrowthRate($s),
            'addr_share' => $metrics->calculateAddressableShare($s, $ic, $m), 'sat_penalty' => $metrics->calculateMarketSaturationPenalty($s, $ic, $m),
            'mkt_growth' => \App\Service\Corporate\CorporateMetrics::secularMarketGrowth($s, $m->totalTime),
            'icf' => (float) $report->getInvestingCashFlow(), 'ocf' => (float) $report->getOperatingCashFlow(), 'fcf' => (float) $report->getFreeCashFlow(),
            'ebitda' => (float) $report->getEbitda(), 'cash' => (float) $s->getCorporateTreasury(), 'payout' => (float) $s->getTargetPayoutRatio(),
            'infl' => $m->inflationEma,
        ];
    }

    protected function onEvent(StockEvent $event): void
    {
        $t = $event->getStock()->getTicker();
        if (in_array($t, self::FIRMS, true)) {
            $this->out['events'][$t][$event->getEventType()] = ($this->out['events'][$t][$event->getEventType()] ?? 0) + 1;
            // An accident or overrun onset reaches the report only as its lore line in the earnings event's text.
            $d = $event->getDescription();
            if ($event->getEventType() === 'EARNINGS' && preg_match('/rocket was lost|launch failure destroyed|upper-stage failure/', $d)) {
                $this->out['failures'][$t][] = round($this->currentTime, 3);
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

        return $record;
    }

    protected function onEnd(): void
    {
        $this->out['dead'] = $this->dead;
        $this->out['seed'] = $this->seed;
        $path = getenv('SPACE_OUT');
        if ($path) {
            file_put_contents((string) $path, (string) json_encode($this->out, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}
