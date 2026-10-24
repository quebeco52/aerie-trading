<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Market\Pricing\StockTracker;

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * joint: the whole-board run (var/harness/lib/BoardRun.php) with working capital, leases, Street figures, lender and
 * insurer lines on each report row, yields, household debt and growth on each macro row (CREDIT=1 adds the credit
 * cycle), and every event's tick in the record.
 *
 * TRACE=<ticker>[,<ticker>...] TRACE_OUT=<jsonl>: those names' tracker update rows each tick with the pricing context.
 * TICK_CSV=<csv>: price and shares of every name each tick; ANOM_OUT=<jsonl>: the first non-finite macro field and the
 * first non-positive live price per name.
 */
class JointHarnessTest extends BoardRun
{
    /** @var array<string, bool> */
    private array $anomSeen = [];
    /** @var array<string, list<int>> */
    private array $eventTicks = [];
    /** @var array<string, list<array{t: float, e: string}>> insurer claim and cover event types */
    private array $eventTypes = [];

    protected function afterStocks(array $result): void
    {
        $tick = $this->tick;
        $m = $this->currentMacro;
        $list = $this->list;
        // TRACE=<ticker>[,<ticker>...]: those names' tracker update rows each tick, to TRACE_OUT.
        if (getenv('TRACE')) {
            foreach ($result['updates'] as $u) {
                if (in_array($u['ticker'], explode(',', (string) getenv('TRACE')), true)) {
                    unset($u['analyst_targets']);
                    file_put_contents((string) getenv('TRACE_OUT'), json_encode(['tick' => $tick] + $u + ['iv' => $this->stocks[$u['ticker']]->getImpactVarianceEma()] + $this->traceFv($this->stocks[$u['ticker']])) . "\n", FILE_APPEND);
                }
            }
        }
        // TICK_CSV: price and shares of every name each tick (header row first); bad macro fields and prices to
        // ANOM_OUT.
        if (getenv('TICK_CSV')) {
            if ($tick === 1) {
                file_put_contents((string) getenv('TICK_CSV'), 'tick,' . implode(',', array_map(fn($s) => $s->getTicker() . '_p,' . $s->getTicker() . '_n', $list)) . "\n");
            }
            file_put_contents((string) getenv('TICK_CSV'), $tick . ',' . implode(',', array_map(fn($s) => sprintf('%.6g,%.6g', (float) $s->getPrice(), (float) $s->getSharesOutstanding()), $list)) . "\n", FILE_APPEND);
            foreach ((fn(): array => get_object_vars($this))->call($m) as $k => $v) {
                if (is_float($v) && !is_finite($v) && !isset($this->anomSeen[$k])) {
                    $this->anomSeen[$k] = true;
                    file_put_contents((string) getenv('ANOM_OUT'), json_encode(['t' => $tick / $this->tpy, 'macro' => $k, 'v' => (string) $v]) . "\n", FILE_APPEND);
                }
            }
            foreach ($list as $s) {
                $p = (float) $s->getPrice();
                if ((!is_finite($p) || $p <= 0.0) && !$s->isBankrupt() && !isset($this->anomSeen[$s->getTicker()])) {
                    $this->anomSeen[$s->getTicker()] = true;
                    file_put_contents((string) getenv('ANOM_OUT'), json_encode(['t' => $tick / $this->tpy, 'ticker' => $s->getTicker(), 'px' => (string) $p]) . "\n", FILE_APPEND);
                }
            }
        }
    }

    protected function macroRow(array $row, MacroStateDTO $m): array
    {
        $row = self::insertAfter($row, 'tax_rate', [
            'y10' => $m->yield10y ?? null, 'hh' => $m->householdDebtToIncome ?? null, 'gq' => $m->realGdpQuarterlyGrowth ?? null, 'scp' => $m->supplyChainPressureIndexEma, 'vol_ema' => $m->marketVolatilityEma,
        ]);

        return $row + (getenv('CREDIT') ? [
            'sloos' => $m->sloosTighteningIndexEma, 'sloos_raw' => $m->sloosTighteningIndex, 'ebp' => $m->excessBondPremium, 'ebp_trend' => $m->excessBondPremiumTrend,
            'cap_req' => $m->bankCapitalRequirement, 'ccyb' => $m->countercyclicalBufferRate, 'cap_built' => $m->bankCapitalBuilt,
            'infl_ema' => $m->inflationEma, 'bei' => $m->tipsBreakevenEma, 'supercore' => $m->supercoreInflationEma,
            'corp_dr' => $m->corporateDefaultRateEma, 'retail_dr' => $m->retailDefaultRateEma, 'starts' => $m->housingStartsIndex,
            'fci' => $m->financialConditionsIndex, 'cre' => $m->commercialPropertyIndexEma, 'freight' => $m->freightRateIndex,
            'recp' => $m->recessionProbability,
        ] : []);
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $m = $this->currentMacro;
        $kpis = $report->getReportedKpis() ?? [];
        $row = self::insertAfter($row, 'ta', [
            'lease' => (float) $report->getLeaseLiability(),
            'recv' => (float) $report->getReceivables(),
            'inv' => (float) $report->getInventory(),
            'pay' => (float) $report->getPayables(),
            'invwd' => (float) $report->getInventoryWriteDown(),
            'recprov' => (float) $report->getReceivablesProvision(),
            'reps' => (float) $report->getReportedEps(),
            'ceps' => (float) $report->getConsensusEps(),
            'crev' => (float) $report->getConsensusRevenue(),
        ]);

        return self::insertAfter($row, 'ea', [
            'nco' => (float) ($report->getNetChargeOffs() ?? 0.0),
            'prov' => (float) ($report->getCreditLossProvision() ?? 0.0),
            'allow' => (float) ($report->getCreditLossAllowance() ?? 0.0),
            'ii' => (float) ($report->getInterestIncome() ?? 0.0),
            // Insurer regime and earned rate, ILS principal, float, equity sleeve (value, expected return, the board
            // price index the sleeve was last marked at), the macro board index and the effective tax rate.
            'hm' => (float) (($report->getStock()->getEarningsMomentumZ() ?? [])['state:regime:hard_market'] ?? 0.0),
            'erl' => ($report->getStock()->getEarningsMomentumZ() ?? [])['state:capacity:earned_rate_level'] ?? null,
            'tgain' => ($report->getStock()->getEarningsMomentumZ() ?? [])['carry:equity_sleeve:trailing_gains'] ?? null,
            'ilsp' => ($report->getStock()->getEarningsMomentumZ() ?? [])['state:ils:principal_index'] ?? null,
            'catb' => (float) (($report->getRevenueStreams() ?? [])['catastrophe_bonds'] ?? 0.0),
            'lr' => isset($kpis['loss_ratio']) ? (float) $kpis['loss_ratio'] : null,
            'xr' => isset($kpis['expense_ratio']) ? (float) $kpis['expense_ratio'] : null,
            // The sector event (a claim headline, a cover attachment) rides on every stream detail entry.
            'evt' => array_values(array_filter(array_map(static fn($e) => is_array($e) ? ($e['event'] ?? null) : null, $report->getStreamDetails() ?? [])))[0] ?? null,
            'cdep' => (float) ($report->getStock()->getCustomerDeposits() ?? 0.0),
            'sl_pi' => ($report->getStock()->getEarningsMomentumZ() ?? [])['carry:equity_sleeve:price_index'] ?? null,
            'bpi' => $m?->boardPriceIndex ?? null,
            'btri' => $m?->boardTotalReturnIndex ?? null,
        ] + $this->sleeveRow($report->getStock()) + [
            'ttc' => App\Data\Company\Sectors::strategyFor($report->getStock()->getIndustry())->getThroughTheCycleCreditLossRate($report->getStock()),
        ]);
    }

    protected function onEvent(StockEvent $event): void
    {
        // Every event's tick, so a price jump can be told from a reported one.
        $this->eventTicks[$event->getStock()->getTicker()][] = (int) round($this->currentTime * $this->tpy);
        if (preg_match('/reinsur|claim|hard|catastroph/i', $event->getEventType())) {
            $this->eventTypes[$event->getStock()->getTicker()][] = ['t' => round($this->currentTime, 3), 'e' => $event->getEventType()];
        }
    }

    protected function record(array $record): array
    {
        return $record + ['event_ticks' => $this->eventTicks, 'event_types' => $this->eventTypes];
    }

    /**
     * TRACE: the pricing context's scalar inputs and the engine's own fundamental state for one name, struck as
     * MarketEngine::strikeFairValue does (FairValue::fundamentalStateFor(), no draw).
     *
     * @return array<string, mixed>
     */
    private function traceFv(Stock $stock): array
    {
        if ($this->debtEngine === null || $this->currentMacro === null || $this->tracker === null) {
            return [];
        }
        $m = $this->currentMacro;
        $ledger = (new ReflectionProperty(StockTracker::class, 'anchorStakes'))->getValue($this->tracker);
        $ctx = App\DTO\MarketPricingContext::forStock($stock, $m, $this->debtEngine->analyzeDebtHealth($stock, $m), $ledger);
        $row = [];
        foreach (get_object_vars($ctx) as $k => $v) {
            if (is_float($v) || is_int($v)) {
                $row['c_' . $k] = $v;
            } elseif (is_array($v) && $k === 'policyBasesPerShare') {
                $row['c_' . $k] = $v;
            }
        }
        $fs = App\Service\Market\Pricing\FairValue::fundamentalStateFor($ctx);
        $row['f_fv'] = $fs['perceived_fair_value'];
        $row['f_policy'] = $fs['policy_repricing'] ?? null;
        $row['f_targets'] = $fs['analyst_targets'];

        return $row;
    }

    /** @return array{sl_v: float, sl_er: float, tax: float} */
    private function sleeveRow(Stock $stock): array
    {
        $strategy = App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $m = $this->currentMacro ?? new MacroStateDTO();
        $sl = method_exists($strategy, 'resolveEquitySleeve') ? $strategy->resolveEquitySleeve($stock, $m) : ['value' => 0.0, 'expected_return' => 0.0];

        return ['sl_v' => (float) $sl['value'], 'sl_er' => (float) $sl['expected_return'], 'tax' => $strategy->getEffectiveTaxRate($m->corporateTaxRate)];
    }
}
