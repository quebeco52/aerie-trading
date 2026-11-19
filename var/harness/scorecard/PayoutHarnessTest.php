<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * Scorecard record plus the dividend policy: each quarter row carries the firm's target payout ratio (`tpr`) and
 * dividend speed (`dspd`), and the record carries every "dividend policy" event line (`pol`). Read-only; runs on any
 * tree (getters that exist on both sides of the payout-policy change).
 */
final class PayoutHarnessTest extends BoardRun
{
    /** @var array<string, array{industry: string, sector: string, model: string, fin: bool}> */
    private array $meta = [];
    /** @var list<array{0: float, 1: float, 2: float}> Time, nominal GDP index, board cap. */
    private array $macro = [];
    /** @var array<string, list<array{t: float, d: string}>> */
    private array $pol = [];
    /** @var array<string, list<array{0: float, 1: float, 2: float}>> affo1: REIT time, price, perceived fair value. */
    private array $reitTrace = [];

    protected function onStart(): void
    {
        foreach ($this->stocks as $t => $s) {
            $strategy = App\Data\Company\Sectors::strategyFor($s->getIndustry());
            $this->meta[$t] = [
                'industry' => (string) $s->getIndustry(),
                'sector' => $s->getSector(),
                'model' => (new ReflectionClass($strategy))->getShortName(),
                'fin' => $strategy->isFinancial(),
                'tpr0' => (float) $s->getTargetPayoutRatio(),
                'st0' => method_exists($s, 'getDividendStreakYears') ? $s->getDividendStreakYears() : null,
            ];
        }
    }

    protected function afterStocks(array $result): void
    {
        if ($this->currentMacro !== null && $this->tick % max(1, intdiv($this->tpy, 4)) === 0) {
            $this->macro[] = [round($this->currentTime, 3), $this->currentMacro->nominalGdpIndex, $this->reading->equityMarketCap];
        }
        if ($this->tick <= 30 || $this->tick % 9 === 0) {
            foreach ($result['updates'] as $u) {
                if (($this->meta[$u['ticker']]['model'] ?? '') === 'ReitBusinessModel') {
                    $this->reitTrace[$u['ticker']][] = [round($this->currentTime, 4), (float) $u['price'], (float) $u['perceived_fair_value']];
                }
            }
        }
    }

    /** reit1: the parent's health columns plus the recap test's D/E (`deH`) and the firm's leverage target (`lt`). */
    protected function healthRow(App\Entity\Stock $stock): array
    {
        if ($this->debtEngine === null || $this->currentMacro === null || !getenv('HEALTH')) {
            return [];
        }
        $h = $this->debtEngine->analyzeTrailingDebtHealth($stock, $this->currentMacro);

        return ['uL' => $h->isUnderLeveraged, 'ndE' => $h->netDebtToEbitda, 'covL' => $h->ebitdaCovenantLimit, 'head' => $h->hasLeverageHeadroom,
            'tol' => $h->debtTolerance, 'icr' => $h->interestCoverage, 'can' => $h->canIssueDebt, 'hEbitda' => $h->rawMetrics->ebitda, 'hEbit' => $h->rawMetrics->ebit,
            'deH' => $h->debtToEquity, 'lt' => $h->leverageTarget];
    }

    protected function reportRow(array $row, App\Entity\CorporateReport $report): array
    {
        $s = $report->getStock();
        $row['tpr'] = (float) $s->getTargetPayoutRatio();
        $row['dspd'] = method_exists($s, 'getDividendSpeed') ? (float) $s->getDividendSpeed() : null;
        // pay2: the reinvestment driver the annual review reads, and the year-end dividend the roll compares against.
        $st = $s->getEarningsMomentumZ() ?? [];
        foreach (['ref' => 'carry:payout_reference', 'rinv' => 'carry:payout_reinvested', 'earn' => 'carry:payout_earned', 'rq' => 'carry:payout_quarters', 'yed' => 'carry:year_end_dividend'] as $k => $key) {
            $row[$k] = isset($st[$key]) ? (float) $st[$key] : null;
        }

        return $row;
    }

    protected function onEvent(App\Entity\StockEvent $event): void
    {
        foreach (preg_split('/\n/', (string) $event->getDescription()) ?: [] as $line) {
            if (stripos($line, 'dividend policy') !== false) {
                $this->pol[$event->getStock()->getTicker()][] = ['t' => round($this->currentTime, 3), 'd' => substr(ltrim($line, "• "), 0, 120)];
            }
        }
    }

    protected function record(array $record): array
    {
        return $record + ['meta' => $this->meta, 'macro' => $this->macro, 'pol' => $this->pol, 'reit_trace' => $this->reitTrace, 'gdp_usd' => App\Service\Math\MacroConstants::DISTRICT_GDP_USD];
    }
}
