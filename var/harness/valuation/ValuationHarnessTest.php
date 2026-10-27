<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';
require_once __DIR__ . '/../price_vol/Probe.php';

/**
 * Where the board's valuation predictability comes from. Live macro, no politics; the price_vol channel probe
 * (Probe.php, read-only) feeds each name's per-tick channel log returns and the engine's perceived fair value into
 * quarterly accumulators. At every quarter end (tick % (TPY/4) == 0) writes, per live name: split-adjusted price
 * index, price, perceived fair value per share, EPS as the stock carries it, shares, float-adjusted cap, and the
 * quarter's channel sums (15 columns, Probe::NAMES; dfv = d log fair value), then the engine's live cost of equity and
 * a financial flag. Deal events counted by type. VAL_OUT=<json>. Draws nothing.
 */
final class ValuationHarnessTest extends BoardRun
{
    private ?PriceVolProbe $probe = null;
    /** @var array<string, list<float>> */
    private array $acc = [];
    /** @var array<string, float> */
    private array $fv = [];
    /** @var array<string, ?float> */
    private array $coe = [];
    /** @var array<string, array{0: float, 1: int}> per name: sum of squared split-adjusted tick log returns, n */
    private array $rv = [];
    /** @var array{0: float, 1: int} board float-weighted price return: sum of squares, n */
    private array $rvIndex = [0.0, 0];
    /** @var array<string, array{0: ?float, 1: ?float}> StockTracker yield-exposure EMA (cov, var) at years 5/10/20 */
    private array $bhat = [];
    /** @var array<string, int> deal events by type */
    private array $deals = [];
    /** @var array<string, float> split-adjusted price index (product of split ratios applied) */
    private array $adj = [];
    /** @var array<string, array{0: float, 1: float}> */
    private array $last = [];
    /** @var list<array{0: float, 1: array<string, list<float>>}> */
    private array $rows = [];

    protected function onStart(): void
    {
        foreach ($this->stocks as $tk => $s) {
            $this->adj[$tk] = 1.0;
            $this->last[$tk] = [(float) $s->getPrice(), (float) $s->getSharesOutstanding()];
        }
        if ($this->tracker !== null) {
            $this->probe = PriceVolProbe::install($this->tracker, 0, [1]);
            $this->probe->sink = function (string $tk, array $c, float $fv, ?float $coe = null): void {
                $this->coe[$tk] = $coe;
                $a = $this->acc[$tk] ?? array_fill(0, count($c), 0.0);
                foreach ($c as $i => $v) {
                    $a[$i] += $v;
                }
                $this->acc[$tk] = $a;
                $this->fv[$tk] = $fv;
            };
        }
    }

    protected function beforeStocks(): void
    {
        if ($this->probe === null) {
            return;
        }
        $this->probe->tick = $this->tick;
        $this->probe->macro = $this->currentMacro;
        $this->probe->queue = array_values(array_filter($this->stocks, static fn ($s) => !$s->isBankrupt()));
    }

    protected function afterStocks(array $result): void
    {
        $this->probe?->endTick($this->stocks);
        foreach ($this->stocks as $tk => $s) {
            $price = (float) $s->getPrice();
            $shares = (float) $s->getSharesOutstanding();
            [$lp, $lsh] = $this->last[$tk] ?? [0.0, 0.0];
            $this->last[$tk] = [$price, $shares];
            if ($lsh > 0.0 && $lp > 0.0 && $price > 0.0 && $this->tick > $this->tpy) {
                $r0 = $shares / $lsh;
                $sp = (($r0 >= 1.5 && abs($r0 - round($r0)) < 1e-6) || ($r0 > 0.0 && $r0 <= 0.67 && abs(1.0 / $r0 - round(1.0 / $r0)) < 1e-6)) ? $r0 : 1.0;
                $lr = log($price * $sp / $lp);
                $this->rv[$tk] = [($this->rv[$tk][0] ?? 0.0) + $lr * $lr, ($this->rv[$tk][1] ?? 0) + 1];
            }
            if ($lsh > 0.0) {
                $r = $shares / $lsh;
                if (($r >= 1.5 && abs($r - round($r)) < 1e-6) || ($r > 0.0 && $r <= 0.67 && abs(1.0 / $r - round(1.0 / $r)) < 1e-6)) {
                    $this->adj[$tk] = ($this->adj[$tk] ?? 1.0) * $r;
                }
            }
        }
        if ($this->tick > $this->tpy) {
            $br = log(1.0 + (float) ($result['board_price_return'] ?? 0.0));
            $this->rvIndex = [$this->rvIndex[0] + $br * $br, $this->rvIndex[1] + 1];
        }
        foreach ([5, 10, 20] as $yr) {
            if ($this->tick === $yr * $this->tpy && $this->tracker !== null && property_exists($this->tracker, 'yieldRevisionCovariance')) {
                // The d2/d2b trackers' yield exposure moments, read by name: the kept tracker has none.
                $vars = \Closure::bind(fn () => get_object_vars($this), $this->tracker, \App\Service\Market\Pricing\StockTracker::class)();
                $this->bhat[(string) $yr] = [$vars['yieldRevisionCovariance'], $vars['yieldMoveVariance']];
            }
        }
        $q = max(1, intdiv($this->tpy, 4));
        if ($this->tick % $q !== 0) {
            return;
        }
        $snap = [];
        foreach ($this->stocks as $tk => $s) {
            if ($s->isBankrupt() || (float) $s->getPrice() <= 0.0) {
                continue;
            }
            $snap[$tk] = [
                (float) $s->getPrice() * ($this->adj[$tk] ?? 1.0), (float) $s->getPrice(), $this->fv[$tk] ?? 0.0,
                (float) $s->getEarningsPerShare(), (float) $s->getSharesOutstanding(),
                \App\Service\Market\Index\IndexCommittee::floatAdjustedCap($s),
                ...($this->acc[$tk] ?? array_fill(0, 15, 0.0)),
                $this->coe[$tk] ?? null, $s->getSector() === 'Financial Services' || \App\Data\Company\Sectors::strategyFor((string) $s->getIndustry())->isFinancial() ? 1.0 : 0.0,
            ];
        }
        $this->acc = [];
        $this->rows[] = [round($this->currentTime, 4), $snap];
    }

    protected function onEvent(\App\Entity\StockEvent $event): void
    {
        $d = (string) $event->getDescription();
        if (preg_match('/ executed a \$/', $d)) {
            $this->deals[$event->getEventType()] = ($this->deals[$event->getEventType()] ?? 0) + 1;
        }
    }

    protected function onEnd(): void
    {
        $out = getenv('VAL_OUT');
        if ($out) {
            file_put_contents($out, json_encode(['tpy' => $this->tpy, 'names' => PriceVolProbe::NAMES, 'unmatched' => $this->probe?->unmatched,
                'sector' => array_map(static fn ($s) => $s->getSector(), $this->stocks), 'deals' => $this->deals, 'rv' => $this->rv, 'rvIndex' => $this->rvIndex, 'bhat' => $this->bhat, 'audit' => ($this->probe?->result() ?? [])['audit'] ?? [], 'rows' => $this->rows]));
        }
    }
}
