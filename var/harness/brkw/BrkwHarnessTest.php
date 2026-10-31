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

    /** @var array<string, float> per tick: held ticker => BRKW's shares in it, cap by ticker, return sums */
    private array $heldShares = [];
    private array $prevCap = [];
    private array $volSum = [];
    private ?float $trailingPrev = null;
    private float $collected = 0.0;
    private float $collectedQ = 0.0;
    private float $paidOnBlocks = 0.0;
    /** Paid on blocks at the shares of record: BRKW's fraction at the tick's start x the report's total paid. */
    private float $paidOnBlocksRecord = 0.0;
    /** @var array<string, float> held ticker => its shares outstanding at the last tick end */
    private array $heldOutstanding = [];
    private int $tenders = 0;
    private float $tenderValue = 0.0;
    /** Tax falling due on tenders: the ledger's min(1, proceeds / carrying) x deferred tax, on BRKW's last tick-end book. */
    private float $tenderTax = 0.0;
    private float $prevCarrying = 0.0;
    private float $prevDtax = 0.0;
    /** @var array<string, float> held ticker => redemption cash its report filed this tick (stockBuybacks) */
    private array $pendingRed = [];
    /** @var array<string, float> held ticker => redemption per share from the event fact this tick */
    private array $pendingPs = [];
    private bool $brkwReported = false;
    /** Redemptions: BRKW's carrying fall and deferred-tax fall on clean ticks vs fraction x cash; ticks skipped. */
    private float $redRecv = 0.0;
    private float $redExp = 0.0;
    private float $redExpPs = 0.0;
    private float $redTax = 0.0;
    private int $redN = 0;
    private int $redSkip = 0;
    private float $redExpAll = 0.0;

    /** BRKW's fraction of a held firm: the carried one where the ledger keeps it (working tree), else the declared one. */
    private static function fractionOf(App\Entity\Stock $held): float
    {
        return method_exists(App\Service\Corporate\Holdings\AnchorStakeLedger::class, 'fraction')
            ? App\Service\Corporate\Holdings\AnchorStakeLedger::fraction($held, self::T)
            : (float) (App\Data\Company\AnchorHoldings::forHolder(self::T)[$held->getTicker()] ?? 0.0);
    }

    /** Per tick: tenders out of BRKW's blocks, dividends it collected (inverted from its trailing rate), cap returns. */
    private function perTick(): void
    {
        $this->perTickRedemption();
        foreach (array_keys(App\Data\Company\AnchorHoldings::forHolder(self::T)) as $h) {
            $held = $this->stocks[$h] ?? null;
            if ($held === null || $held->isBankrupt()) {
                continue;
            }
            $n = self::fractionOf($held) * (float) $held->getSharesOutstanding();
            if (isset($this->heldShares[$h]) && $n < $this->heldShares[$h] * (1.0 - 1e-9)) {
                $this->tenders++;
                $proceeds = ($this->heldShares[$h] - $n) * (float) $held->getPrice();
                $this->tenderValue += $proceeds;
                $this->tenderTax += $this->prevCarrying > 0.0 ? min(1.0, $proceeds / $this->prevCarrying) * $this->prevDtax : 0.0;
            }
            $this->heldShares[$h] = $n;
            $this->heldOutstanding[$h] = (float) $held->getSharesOutstanding();
        }
        $bk = $this->stocks[self::T] ?? null;
        if ($bk !== null && method_exists($bk, 'getListedStakesDeferredTax')) {
            $this->prevCarrying = (float) ($bk->getListedStakesCarrying() ?? 0.0);
            $this->prevDtax = (float) $bk->getListedStakesDeferredTax();
        }
        $this->perTickCollect();
        $num = 0.0;
        $den = 0.0;
        foreach ($this->stocks as $t => $s) {
            $cap = $s->isBankrupt() ? 0.0 : (float) $s->getPrice() * (float) $s->getSharesOutstanding();
            if ($cap > 0.0 && ($this->prevCap[$t] ?? 0.0) > 0.0) {
                $num += $cap;
                $den += $this->prevCap[$t];
                if (in_array($t, [self::T, 'ALBT', 'CBIL', 'ALCA'], true)) {
                    $r = log($cap / $this->prevCap[$t]);
                    $this->volSum[$t][0] = ($this->volSum[$t][0] ?? 0) + 1;
                    $this->volSum[$t][1] = ($this->volSum[$t][1] ?? 0.0) + $r;
                    $this->volSum[$t][2] = ($this->volSum[$t][2] ?? 0.0) + ($r * $r);
                }
            }
            $this->prevCap[$t] = $cap;
        }
        if ($den > 0.0) {
            $r = log($num / $den);
            $this->volSum['board'][0] = ($this->volSum['board'][0] ?? 0) + 1;
            $this->volSum['board'][1] = ($this->volSum['board'][1] ?? 0.0) + $r;
            $this->volSum['board'][2] = ($this->volSum['board'][2] ?? 0.0) + ($r * $r);
        }
    }

    /** BRKW's receipt from held firms' redemptions this tick, read off its carrying, before the block counts update. */
    private function perTickRedemption(): void
    {
        $bk = $this->stocks[self::T] ?? null;
        if ($this->pendingRed !== [] && $bk !== null && method_exists($bk, 'getListedStakesDeferredTax')) {
            $exp = 0.0;
            $expPs = 0.0;
            $tender = false;
            $paidIn = 0.0;
            // Shares any held firm issued this tick (compensation) are subscribed at the tick price: carrying rises by it.
            foreach (array_keys(App\Data\Company\AnchorHoldings::forHolder(self::T)) as $h) {
                $hx = $this->stocks[$h] ?? null;
                $o = $this->heldOutstanding[$h] ?? 0.0;
                // A split (count up over 1.4x) moves no cash.
                if ($hx !== null && !$hx->isBankrupt() && $o > 0.0 && (float) $hx->getSharesOutstanding() < 1.4 * $o) {
                    $paidIn += (($this->heldShares[$h] ?? 0.0) / $o) * max(0.0, (float) $hx->getSharesOutstanding() - $o) * (float) $hx->getPrice();
                }
            }
            foreach ($this->pendingRed as $h => $cash) {
                $held = $this->stocks[$h];
                $out = $this->heldOutstanding[$h] ?? 0.0;
                $f = $out > 0.0 ? ($this->heldShares[$h] ?? 0.0) / $out : 0.0;
                $exp += $f * $cash;
                $expPs += ($this->heldShares[$h] ?? 0.0) * ($this->pendingPs[$h] ?? 0.0);
                if (self::fractionOf($held) * (float) $held->getSharesOutstanding() < ($this->heldShares[$h] ?? 0.0) * (1.0 - 1e-9)) {
                    $tender = true;
                }
                $this->emit(['kind' => 'red', 't' => round($this->currentTime, 3), 'ticker' => $h, 'cash' => $cash, 'ps' => $this->pendingPs[$h] ?? null,
                    'sh_rec' => $out, 'sh_now' => (float) $held->getSharesOutstanding(), 'px' => (float) $held->getPrice(), 'f' => $f]);
            }
            $this->redExpAll += $exp;
            if ($tender || $this->brkwReported) {
                $this->redSkip++;
            } else {
                $this->redN++;
                $this->redExp += $exp;
                $this->redExpPs += $expPs;
                $this->redRecv += $this->prevCarrying - (float) ($bk->getListedStakesCarrying() ?? 0.0) + $paidIn;
                $this->redTax += $this->prevDtax - (float) $bk->getListedStakesDeferredTax();
                $this->emit(['kind' => 'redtick', 't' => round($this->currentTime, 3), 'exp' => $exp, 'dcarry' => $this->prevCarrying - (float) ($bk->getListedStakesCarrying() ?? 0.0), 'paid_in' => $paidIn,
                    'dtax' => $this->prevDtax - (float) $bk->getListedStakesDeferredTax(), 'n' => count($this->pendingRed)]);
            }
        }
        $this->pendingRed = [];
        $this->pendingPs = [];
        $this->brkwReported = false;
    }

    /** @param array<mixed> $a */
    private static function findRedemption(array $a): ?float
    {
        if (($a['kind'] ?? null) === 'redemption') {
            return (float) ($a['perShare'] ?? 0.0);
        }
        foreach ($a as $v) {
            if (is_array($v) && ($r = self::findRedemption($v)) !== null) {
                return $r;
            }
        }

        return null;
    }

    /** Dividends BRKW collected since the last look: its trailing dividend rate steps only at a collect. */
    private function perTickCollect(): void
    {
        $b = $this->stocks[self::T] ?? null;
        if ($b !== null) {
            $tr = ($b->getEarningsMomentumZ() ?? [])[App\Service\Corporate\EarningsEngine::CARRIED_STATE_PREFIX . 'anchor:dividends_trailing'] ?? null;
            if ($tr !== null && $this->trailingPrev !== null && abs((float) $tr - $this->trailingPrev) > 1e-6 * max(1.0, abs($this->trailingPrev))) {
                $w = App\Service\Math\TimeSeries::smoothingWeight(0.25, 1.0);
                $got = 0.25 * ($this->trailingPrev + (((float) $tr - $this->trailingPrev) / $w));
                $this->collected += $got;
                $this->collectedQ += $got;
            }
            $this->trailingPrev = $tr !== null ? (float) $tr : null;
        }
    }

    protected function onEnd(): void
    {
        $vol = [];
        foreach ($this->volSum as $t => [$n, $s1, $s2]) {
            $vol[$t] = $n > 1 ? sqrt(max(0.0, ($s2 - ($s1 * $s1 / $n)) / ($n - 1)) * $this->tpy) : null;
        }
        $b = $this->stocks[self::T];
        $this->emit(['kind' => 'end', 'vol' => $vol, 'collected' => $this->collected, 'paid_on_blocks' => $this->paidOnBlocks, 'paid_on_blocks_record' => $this->paidOnBlocksRecord,
            'receivable' => (float) (($b->getEarningsMomentumZ() ?? [])[App\Service\Corporate\EarningsEngine::CARRIED_STATE_PREFIX . 'anchor:dividends_receivable'] ?? 0.0),
            'tenders' => $this->tenders, 'tender_value' => $this->tenderValue, 'tender_tax' => $this->tenderTax,
            'red_recv' => $this->redRecv, 'red_exp' => $this->redExp, 'red_exp_ps' => $this->redExpPs, 'red_tax' => $this->redTax, 'red_n' => $this->redN, 'red_skip' => $this->redSkip, 'red_exp_all' => $this->redExpAll]);
    }

    private function emit(array $row): void
    {
        $out = getenv('BRKW_OUT');
        if ($out) {
            file_put_contents($out, json_encode($row, JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND);
        }
    }

    protected function afterStocks(array $result): void
    {
        $this->perTick();
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
        $nav = $mbvps;
        if ($mbvps !== null && $m !== null && method_exists(App\Service\Corporate\DebtEngine::class, 'subsidiaryRevaluation')) {
            $nav = $mbvps + (App\Service\Corporate\DebtEngine::subsidiaryRevaluation($s, App\Data\Company\Sectors::strategyFor($s->getIndustry()), $m) / $shares);
        }
        $held = [];
        foreach (array_keys(App\Data\Company\AnchorHoldings::forHolder(self::T)) as $h) {
            $hs = $this->stocks[$h] ?? null;
            if ($hs !== null) {
                $held[$h] = ['f' => self::fractionOf($hs), 'fl' => (float) $hs->getPublicFloatPercentage(), 'px' => (float) $hs->getPrice(), 'dead' => $hs->isBankrupt()];
            }
        }
        $minFloat = 1.0;
        $minFloatT = '';
        foreach ($this->stocks as $t => $x) {
            if (!$x->isBankrupt() && (float) $x->getPublicFloatPercentage() < $minFloat) {
                $minFloat = (float) $x->getPublicFloatPercentage();
                $minFloatT = $t;
            }
        }
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
            'dead' => $s->isBankrupt(), 'nav_sop' => $nav, 'held' => $held,
            'dtax' => method_exists($s, 'getListedStakesDeferredTax') ? $s->getListedStakesDeferredTax() : null,
            'tenders' => $this->tenders, 'tender_value' => $this->tenderValue, 'min_float' => $minFloat, 'min_float_t' => $minFloatT,
            'cash' => (float) $s->getCorporateTreasury(),
        ]);
    }

    protected function reportRow(array $row, App\Entity\CorporateReport $report): array
    {
        $rt = $report->getStock()->getTicker();
        if ($rt !== self::T && isset(App\Data\Company\AnchorHoldings::forHolder(self::T)[$rt])) {
            $hsx = $report->getStock();
            if (method_exists(App\Data\Company\AnchorHoldings::class, 'redeemsProRata') && (float) $report->getStockBuybacks() > 0.0) {
                $this->pendingRed[$rt] = ($this->pendingRed[$rt] ?? 0.0) + (float) $report->getStockBuybacks();
            }
            $this->emit(['kind' => 'hrep', 't' => round($this->currentTime, 3), 'ticker' => $rt, 'ni' => (float) $report->getNetIncome(),
                'sbc' => (float) ($report->getStockCompensation() ?? 0.0), 'div' => (float) $report->getDividendPaid(), 'bb' => (float) $report->getStockBuybacks(),
                'eq' => (float) $report->getEquity(), 'cash' => (float) $report->getTreasury(), 'sh' => (float) $hsx->getSharesOutstanding(),
                'sh_rec' => $this->heldOutstanding[$rt] ?? null, 'px' => (float) $hsx->getPrice(), 'eps' => (float) $hsx->getEarningsPerShare(),
                'gwi' => (float) ($report->getGoodwillImpairment() ?? 0.0)]);
            $this->paidOnBlocks += self::fractionOf($report->getStock()) * (float) $report->getDividendPaid();
            if (($this->heldOutstanding[$rt] ?? 0.0) > 0.0) {
                $this->paidOnBlocksRecord += ($this->heldShares[$rt] / $this->heldOutstanding[$rt]) * (float) $report->getDividendPaid();
            }
        }
        if ($rt === self::T) {
            $this->brkwReported = true;
            // The collect runs inside the report; perTick() sees its trailing-rate step at the tick end, so read it here.
            $this->perTickCollect();
            $spread = null;
            if (getenv('BRKW_OUT') && $this->currentMacro !== null && method_exists(App\Service\Model\Trait\StandardOperatingPhysicsTrait::class, 'getExpectedHoldingReturn')) {
                // The allocator's trueReturn - hurdle (CapitalAllocationEngine ~:404), read after the report on trailing health.
                $bs = $report->getStock();
                $st = App\Data\Company\Sectors::strategyFor($bs->getIndustry());
                $hl = $this->debtEngine->analyzeTrailingDebtHealth($bs, $this->currentMacro);
                $hr = $st->getExpectedHoldingReturn($bs, App\Service\Corporate\DebtEngine::hurdleAssetCostOfCapital($bs, $this->currentMacro));
                $spread = ['true' => $st->getTrueReturn($bs), 'hold' => $hr, 'hurdle' => $st->getHurdleRate($hl)];
            }
            $this->emit(['kind' => 'rep', 'spread' => $spread, 't' => round($this->currentTime, 3), 'eva' => (float) ($report->getEva() ?? 0.0),
                'roic_rep' => (float) ($report->getRoic() ?? 0.0), 'ni' => (float) $report->getNetIncome(),
                'div' => (float) $report->getDividendPaid(), 'bb' => (float) $report->getStockBuybacks(),
                'cash' => (float) $report->getTreasury(), 'debt' => (float) $report->getTotalDebt(),
                'eq' => (float) $report->getEquity(), 'px' => (float) $report->getStock()->getPrice(),
                'sh' => (float) $report->getStock()->getSharesOutstanding(),
                'ebit' => (float) $report->getEbit(), 'int_inc' => (float) $report->getInterestIncome(),
                'int_exp' => (float) $report->getInterestExpense(), 'tax' => (float) $report->getTaxPaid(),
                'pretax' => (float) $report->getPreTaxIncome(), 'gwi' => (float) ($report->getGoodwillImpairment() ?? 0.0),
                'divrec' => $this->collectedQ, 'cap' => (float) $report->getStock()->getPrice() * (float) $report->getStock()->getSharesOutstanding()]);
            $this->collectedQ = 0.0;
        }

        return $row;
    }

    protected function onEvent(App\Entity\StockEvent $event): void
    {
        $t = $event->getStock()->getTicker();
        $type = $event->getEventType();
        $d = (string) $event->getDescription();
        if (isset(App\Data\Company\AnchorHoldings::forHolder(self::T)[$t]) && ($ps = self::findRedemption($event->getFacts() ?? [])) !== null) {
            $this->pendingPs[$t] = ($this->pendingPs[$t] ?? 0.0) + $ps;
        }
        if ($t === self::T || $type === 'DIVESTITURE' || preg_match('/ executed a \$/', $d)) {
            $this->emit(['kind' => 'ev', 't' => round($this->currentTime, 3), 'ticker' => $t, 'type' => $type, 'desc' => substr($d, 0, 200)]);
        }
    }
}
