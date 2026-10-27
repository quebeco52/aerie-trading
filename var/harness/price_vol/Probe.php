<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Market\Index\IndexCommittee;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Market\Pricing\VarianceBudget;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\StochasticProcesses;

/**
 * Price-channel probe (HEAD layout: VarianceBudget, StockTracker::applyOrderFlow). Swaps the tracker's MarketEngine
 * and EarningsEngine for subclasses that copy the originals' state and call the parent, splits each tick's log return
 * into channels and accumulates, per name and horizon, non-overlapping window sums and their cross products, so the
 * analysis gets every channel's variance and covariance with the total without a per-tick file.
 *
 * Channels (sum = log((P_after_report + dividend) / P_end_of_previous_tick)):
 *   0 mkt   beta x diffusion market vol x marketZ          1 sec   sector factor
 *   2 idio  idio diffusion + drift                         3 pull  log blend toward fair value
 *   4 brk   whole remainder when the circuit breaker binds 5 jI    own Kou jump
 *   6 jS    market jump through beta                       7 ma    deal shock
 *   8 pol   policy repricing                               9 flow  agent + corporate impact incl. all transient
 *  10 fund  sovereign fund impact, permanent share         11 warn  pre-announcement
 *  12 rep   report (dividend added back)                   13 betw  move between ticks (sweep, reorganisation)
 * Non-additive context column 14: d log fair value (engine's perceived fair value).
 */
final class PriceVolProbe
{
    public const K = 14;
    public const NAMES = ['mkt', 'sec', 'idio', 'pull', 'brk', 'jI', 'jS', 'ma', 'pol', 'flow', 'fund', 'warn', 'rep', 'betw', 'dfv'];

    private ?array $cur = null;
    private ?float $pImp = null;
    private ?float $pWarn = null;
    /** @var array<string, float> end-of-tick price, post split */
    private array $lastEnd = [];
    /** @var array<string, float> */
    private array $lastFv = [];
    /** @var array<string, array<string, float>> */
    private array $audit = [];
    /** @var array<string, array<string, mixed>> */
    private array $meta = [];
    /** @var array<int, array<string, array{n: int, S: list<float>, M: list<float>}>> */
    private array $win = [];
    /** @var array<int, array<string, array{k: int, v: list<float>}>> */
    private array $part = [];
    public ?MacroStateDTO $macro = null;
    public int $tick = 0;
    /** @var list<Stock> live names in the tracker's order, set before each stock update */
    public array $queue = [];
    public int $unmatched = 0;
    /** Optional per-tick consumer: fn(string $tk, list<float> $c, float $fv, ?float $coe) (coe = the engine's live cost of equity). */
    public ?\Closure $sink = null;
    /** @var array<string, float> */
    private array $coeOf = [];

    /** @param list<int> $horizons */
    public function __construct(private StockTracker $tracker, private LiquidityEngine $liq, private int $skip, private array $horizons) {}

    public static function install(StockTracker $tracker, int $skip, array $horizons): self
    {
        $origM = \Closure::bind(fn () => $this->marketEngine, $tracker, StockTracker::class)();
        $origE = \Closure::bind(fn () => $this->earningsEngine, $tracker, StockTracker::class)();
        $liq = \Closure::bind(fn () => $this->liquidityEngine, $tracker, StockTracker::class)();
        $probe = new self($tracker, $liq, $skip, $horizons);
        $m = (new \ReflectionClass(ProbeMarketEngine::class))->newInstanceWithoutConstructor();
        self::copyInto($origM, $m, MarketEngine::class);
        $m->probe = $probe;
        $e = (new \ReflectionClass(ProbeEarningsEngine::class))->newInstanceWithoutConstructor();
        self::copyInto($origE, $e, EarningsEngine::class);
        $e->probe = $probe;
        \Closure::bind(function () use ($m, $e): void {
            $this->marketEngine = $m;
            $this->earningsEngine = $e;
        }, $tracker, StockTracker::class)();

        return $probe;
    }

    private static function copyInto(object $src, object $dst, string $scope): void
    {
        $vals = \Closure::bind(fn () => get_object_vars($this), $src, $scope)();
        \Closure::bind(function () use ($vals): void {
            foreach ($vals as $k => $v) {
                $this->$k = $v;
            }
        }, $dst, $scope)();
    }

    private function transient(string $tk): float
    {
        return \Closure::bind(fn () => $this->transientImpact[$tk] ?? 0.0, $this->tracker, StockTracker::class)();
    }

    public function onEngine(MarketPricingContext $ctx, array $r): void
    {
        $this->cur = null;
        $stock = null;
        while ($this->queue !== []) {
            $cand = array_shift($this->queue);
            if (abs((float) $cand->getPrice() - $ctx->currentPrice) <= 1e-9 * max(1.0, $ctx->currentPrice)) {
                $stock = $cand;
                break;
            }
            ++$this->unmatched;
        }
        if ($stock === null) {
            return;
        }
        $tk = $stock->getTicker();
        $this->coeOf[$tk] = (float) ($ctx->liveCostOfEquity ?? 0.0);
        $p0 = $ctx->currentPrice;
        $p1 = max(0.01, (float) $r['price']);
        $dt = $ctx->dt;
        $beta = $ctx->beta;
        $sigma = $ctx->longTermVolatility;
        $b = VarianceBudget::forStep($ctx);

        $jI = $r['shock'] !== null ? log(1.0 + $r['shock'] / 100.0) : 0.0;
        $jS = 0.0;
        if ($ctx->marketJumpMultiplier > 0.0 && $ctx->marketJumpMultiplier !== 1.0) {
            $jS = max(FinancialConstants::MIN_JUMP_LOG_RETURN, min(FinancialConstants::MAX_JUMP_LOG_RETURN, log($ctx->marketJumpMultiplier) * $beta));
        }
        $ma = log(1.0 + $ctx->maShock);
        $pol = log((float) $r['policy_repricing']);
        $rem = log($p1 / $p0) - $jI - $jS - $ma - $pol;

        $maxLog = log(1.0 + MarketEngine::MAX_DAILY_PRICE_CIRCUIT_BREAKER) * sqrt($dt * FinancialConstants::TRADING_DAYS_PER_YEAR);
        $brk = $pull = $mkt = $sec = $idio = 0.0;
        if (abs($rem) >= $maxLog - 1e-9 || $p1 <= 0.01) {
            $brk = $rem;
        } else {
            $w = max(0.0, min(1.0, exp(-(float) $r['dynamic_reversion'] * $dt)));
            $lfv = log(max(0.01, (float) $r['perceived_fair_value']));
            $lD = log($p0) + $rem;
            $lG = $w > 0.0 ? ($lD - (1.0 - $w) * $lfv) / $w : $lD;
            $pull = $lD - $lG;
            $diff = $lG - log($p0);
            $mkt = $beta * $b->diffusionMarketVol * $ctx->marketZ * sqrt($dt);
            $sec = $b->idiosyncraticVolatility() * sqrt(MarketEngine::SECTOR_FACTOR_VARIANCE_SHARE) * $ctx->sectorZ * sqrt($dt);
            $idio = $diff - $mkt - $sec;
        }
        $betw = isset($this->lastEnd[$tk]) && $this->lastEnd[$tk] > 0.0 ? log($p0 / $this->lastEnd[$tk]) : 0.0;
        $fv = max(0.01, (float) $r['perceived_fair_value']);
        $dfv = isset($this->lastFv[$tk]) ? log($fv / $this->lastFv[$tk]) : 0.0;
        $this->lastFv[$tk] = $fv;

        // The fund's slice as applyOrderFlow computes it: same price, float and stored vol (set after the impact).
        $fundTrade = $this->macro?->sovereignFundTrade ?? 0.0;
        $boardFloat = $this->macro?->boardFloatCap ?? 0.0;
        $fundShares = ($fundTrade !== 0.0 && $boardFloat > 0.0 && $p0 > 0.0) ? $fundTrade * (IndexCommittee::floatAdjustedCap($stock) / $boardFloat) / $p0 : 0.0;
        $fundImp = $fundShares !== 0.0 ? $this->liq->peakImpact($stock, $fundShares) : 0.0;
        $fundPerm = FinancialConstants::PERMANENT_IMPACT_SHARE * max(-StockTracker::MAX_TICK_IMPACT_LOG_RETURN, min(StockTracker::MAX_TICK_IMPACT_LOG_RETURN, $fundImp));

        // --- budget inputs, uncapped where the engine caps them ---
        $lambda = $ctx->lambda;
        $vi = VarianceBudget::longTermIdiosyncraticVariance($sigma, $beta);
        $cap = 0.25 * $vi;
        $jp = VarianceBudget::calibratedJumpParameters($sigma, $beta, $lambda, $ctx->jumpVol);
        $ownJ = $lambda > 0.0 ? $lambda * StochasticProcesses::kouTruncatedSecondMoment(0.40, $jp['eta_up'], $jp['eta_down'], FinancialConstants::MAX_JUMP_LOG_RETURN, abs(FinancialConstants::MIN_JUMP_LOG_RETURN)) : 0.0;
        $imp = max(0.0, $ctx->orderFlowVariance);
        $ann = max(0.0, $ctx->announcementVariance);
        $this->cur = [
            'tk' => $tk, 'p0' => $p0, 'p1' => $p1, 'trans' => $this->transient($tk), 'fund' => $fundPerm,
            'c' => [$mkt, $sec, $idio, $pull, $brk, $jI, $jS, $ma, $pol, 0.0, 0.0, 0.0, 0.0, $betw, $dfv],
            'a' => [
                'n' => 1.0, 'sigma' => $sigma, 'beta' => $beta, 'vi' => $vi, 'cap' => $cap,
                'scale' => VarianceBudget::commonIdiosyncraticVarianceScale($ctx->marketVol, $ctx->marketVarianceTrend),
                'sysAnchor' => ($beta * MacroConstants::MACRO_VOL_BASE_ANCHOR) ** 2, 'sysVar' => $b->systematicVar,
                'sysDiff' => ($beta * $b->diffusionMarketVol) ** 2, 'sysJ' => $b->systemicJumpVariance,
                'ownJ' => $ownJ, 'ownJc' => min($ownJ, $cap), 'imp' => $imp, 'impc' => min($imp, $cap),
                'ann' => $ann, 'annc' => min($ann, $cap), 'fundV' => max(0.0, $ctx->fundImpactVariance),
                'theta' => $b->theta, 'idioVar' => $b->idiosyncraticVar, 'mvol' => $ctx->marketVol,
                'lambda' => $lambda, 'kappa' => $b->kappa, 'brkB' => $brk !== 0.0 ? 1.0 : 0.0, 'floorB' => $vi <= 0.2 * $sigma * $sigma + 1e-12 ? 1.0 : 0.0,
            ],
        ];
    }

    public function onWarnEnter(Stock $s): void
    {
        if ($this->cur === null || $this->cur['tk'] !== $s->getTicker()) {
            $this->cur = null;

            return;
        }
        $this->pImp = (float) $s->getPrice();
    }

    public function onWarnExit(Stock $s): void
    {
        $this->pWarn = (float) $s->getPrice();
    }

    public function onCalcExit(Stock $s, ?array $events): void
    {
        if ($this->cur === null || $this->pImp === null || $this->pWarn === null || $this->cur['tk'] !== $s->getTicker()) {
            $this->cur = null;

            return;
        }
        $tk = $s->getTicker();
        $div = 0.0;
        foreach ($events ?? [] as $ev) {
            $div += (float) ($ev['dividend_per_share'] ?? 0.0);
        }
        $c = $this->cur['c'];
        $impTot = log($this->pImp / $this->cur['p1']);
        $c[10] = $this->cur['fund'];
        $c[9] = $impTot - $c[10];
        $c[11] = log($this->pWarn / $this->pImp);
        $c[12] = log(((float) $s->getPrice() + $div) / $this->pWarn);
        if (!isset($this->meta[$tk])) {
            $this->meta[$tk] = ['sector' => $s->getSector(), 'industry' => $s->getIndustry(), 'sigma' => (float) $s->getVolatility()];
        }
        if ($this->sink !== null) {
            ($this->sink)($tk, $c, $this->lastFv[$tk] ?? 0.0, $this->coeOf[$tk] ?? null);
        }
        if ($this->tick > $this->skip) {
            foreach ($this->cur['a'] as $k => $v) {
                $this->audit[$tk][$k] = ($this->audit[$tk][$k] ?? 0.0) + $v;
            }
            $this->accumulate($tk, $c);
        }
        $this->cur = null;
        $this->pImp = $this->pWarn = null;
    }

    private function accumulate(string $tk, array $c): void
    {
        $t = $this->tick - $this->skip - 1;
        $d = count($c);
        foreach ($this->horizons as $h) {
            $p = $this->part[$h][$tk] ?? null;
            if ($p === null || $p['t'] !== $t - 1 || $t % $h === 0) {
                $p = ['k' => 0, 't' => $t - 1, 'v' => array_fill(0, $d, 0.0), 'start' => $t];
            }
            for ($i = 0; $i < $d; ++$i) {
                $p['v'][$i] += $c[$i];
            }
            ++$p['k'];
            $p['t'] = $t;
            if ($p['k'] === $h && $p['start'] % $h === 0) {
                $w = $this->win[$h][$tk] ?? ['n' => 0, 'S' => array_fill(0, $d, 0.0), 'M' => array_fill(0, $d * $d, 0.0)];
                ++$w['n'];
                for ($i = 0; $i < $d; ++$i) {
                    $w['S'][$i] += $p['v'][$i];
                    for ($j = 0; $j < $d; ++$j) {
                        $w['M'][$i * $d + $j] += $p['v'][$i] * $p['v'][$j];
                    }
                }
                $this->win[$h][$tk] = $w;
                $p = null;
            }
            if ($p === null) {
                unset($this->part[$h][$tk]);
            } else {
                $this->part[$h][$tk] = $p;
            }
        }
    }

    /** End of tick: the post-split price the next tick starts from. */
    public function endTick(array $stocks): void
    {
        foreach ($stocks as $tk => $s) {
            $this->lastEnd[$tk] = (float) $s->getPrice();
        }
    }

    public function result(): array
    {
        return ['unmatched' => $this->unmatched, 'names' => self::NAMES, 'meta' => $this->meta, 'audit' => $this->audit, 'win' => $this->win];
    }
}

class ProbeMarketEngine extends MarketEngine
{
    public ?PriceVolProbe $probe = null;

    public function calculateNextPrice(MarketPricingContext $ctx): array
    {
        $r = parent::calculateNextPrice($ctx);
        $this->probe?->onEngine($ctx, $r);

        return $r;
    }
}

class ProbeEarningsEngine extends EarningsEngine
{
    public ?PriceVolProbe $probe = null;

    public function evaluatePreAnnouncement(Stock $stock, MacroStateDTO $macroState, int $tickCount, int $ticksPerYear): array
    {
        $this->probe?->onWarnEnter($stock);
        $r = parent::evaluatePreAnnouncement($stock, $macroState, $tickCount, $ticksPerYear);
        $this->probe?->onWarnExit($stock);

        return $r;
    }

    public function calculate(Stock $stock, MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {
        $r = parent::calculate($stock, $macroState, $tickCount, $ticksPerYear);
        $this->probe?->onCalcExit($stock, $r);

        return $r;
    }
}
