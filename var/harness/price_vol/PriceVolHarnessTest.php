<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * Realised against configured share-price volatility, at 1/21/63-tick horizons.
 *
 * Always (any tree BoardRun runs on): per name, non-overlapping window sums of split-adjusted end-of-tick log price
 * changes; the board's float-weighted price return; the macro's marketVolatility. With PROBE=1 the channel probe
 * (Probe.php) also splits every tick's return by channel. Writes PV_OUT (JSON). Read-only: draws nothing, sets nothing.
 */
final class PriceVolHarnessTest extends BoardRun
{
    private ?PriceVolProbe $probe = null;
    /** @var list<int> */
    private array $horizons = [1, 21, 63];
    private int $skip = 0;
    /** @var array<string, array{0: float, 1: float}> last price, last shares */
    private array $last = [];
    /** @var array<int, array<string, array{n: int, s: float, ss: float}>> */
    private array $lite = [];
    /** @var array<int, array<string, array{k: int, v: float, start: int, t: int}>> */
    private array $litePart = [];
    /** @var array<string, float> */
    private array $sigma = [];
    /** @var array<string, float> */
    private array $beta = [];
    private float $mvS = 0.0;
    private float $mvSS = 0.0;
    private int $mvN = 0;

    protected function onStart(): void
    {
        $this->skip = (int) (getenv('SKIP') !== false ? getenv('SKIP') : $this->tpy);
        foreach ($this->stocks as $tk => $s) {
            $this->sigma[$tk] = (float) $s->getVolatility();
            $this->beta[$tk] = (float) $s->getBeta();
            $this->last[$tk] = [(float) $s->getPrice(), (float) $s->getSharesOutstanding()];
        }
        if (getenv('PROBE') && $this->tracker !== null) {
            require_once __DIR__ . '/Probe.php';
            $this->probe = PriceVolProbe::install($this->tracker, $this->skip, $this->horizons);
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

    private function push(string $key, float $x): void
    {
        $t = $this->tick - $this->skip - 1;
        foreach ($this->horizons as $h) {
            $p = $this->litePart[$h][$key] ?? null;
            if ($p === null || $p['t'] !== $t - 1 || $t % $h === 0) {
                $p = ['k' => 0, 'v' => 0.0, 'start' => $t, 't' => $t - 1];
            }
            $p['v'] += $x;
            ++$p['k'];
            $p['t'] = $t;
            if ($p['k'] === $h && $p['start'] % $h === 0) {
                $w = $this->lite[$h][$key] ?? ['n' => 0, 's' => 0.0, 'ss' => 0.0];
                ++$w['n'];
                $w['s'] += $p['v'];
                $w['ss'] += $p['v'] * $p['v'];
                $this->lite[$h][$key] = $w;
                unset($this->litePart[$h][$key]);
            } else {
                $this->litePart[$h][$key] = $p;
            }
        }
    }

    protected function afterStocks(array $result): void
    {
        $this->probe?->endTick($this->stocks);
        $measure = $this->tick > $this->skip;
        foreach ($this->stocks as $tk => $s) {
            $price = (float) $s->getPrice();
            $shares = (float) $s->getSharesOutstanding();
            [$lp, $lsh] = $this->last[$tk] ?? [0.0, 0.0];
            $this->last[$tk] = [$price, $shares];
            if ($s->isBankrupt() || $price <= 0.0 || $lp <= 0.0 || $lsh <= 0.0) {
                continue;
            }
            // A split multiplies the count by a whole number (or divides it): restate the price, not a return.
            $r = $shares / $lsh;
            $adj = 1.0;
            if ($r >= 1.5 && abs($r - round($r)) < 1e-6) {
                $adj = $r;
            } elseif ($r > 0.0 && $r <= 0.67 && abs(1.0 / $r - round(1.0 / $r)) < 1e-6) {
                $adj = $r;
            }
            if ($measure) {
                $this->push($tk, log($price * $adj / $lp));
            }
        }
        if ($measure) {
            $this->push('__MKT__', log(1.0 + (float) ($result['board_price_return'] ?? 0.0)));
            $mv = (float) ($this->currentMacro?->marketVolatility ?? 0.0);
            $this->mvS += $mv;
            $this->mvSS += $mv * $mv;
            ++$this->mvN;
        }
    }

    protected function onEnd(): void
    {
        $out = getenv('PV_OUT');
        if (!$out) {
            return;
        }
        $anchor = class_exists(\App\Service\Math\MacroConstants::class) && defined(\App\Service\Math\MacroConstants::class . '::MACRO_VOL_BASE_ANCHOR')
            ? \App\Service\Math\MacroConstants::MACRO_VOL_BASE_ANCHOR : null;
        file_put_contents($out, json_encode([
            'tpy' => $this->tpy, 'skip' => $this->skip, 'sigma' => $this->sigma, 'beta' => $this->beta,
            'dead' => array_keys($this->dead), 'lite' => $this->lite,
            'mvol' => ['n' => $this->mvN, 's' => $this->mvS, 'ss' => $this->mvSS], 'anchor' => $anchor,
            'probe' => $this->probe?->result(),
        ]));
    }
}
