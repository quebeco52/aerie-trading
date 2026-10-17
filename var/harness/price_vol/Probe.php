<?php

declare(strict_types=1);

use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\StochasticProcesses;

/**
 * Price-channel probe: swaps the tracker's MarketEngine and EarningsEngine for subclasses that record each tick's
 * log return split by channel, plus the engine's variance-budget inputs. No src change; the subclasses copy the
 * originals' state and call the parent.
 *
 * CSV row per (tick, ticker): t,tk,tot,mkt,sec,idio,pull,brk,jI,jS,ma,pol,impP,impT,warn,rep
 * (tot = log((P_after_earnings + dividend) / P_tick_start), the tracker's own tick return; components sum to it.)
 */
final class PriceVolProbe
{
    public int $tick = 0;
    /** @var resource */
    private $fh;
    private ?array $cur = null;
    /** @var array<string, array<string, float>> */
    private array $audit = [];
    /** @var array<string, array<string, mixed>> */
    private array $meta = [];
    private float $capUp;
    private float $capDown;
    private ?float $pImp = null;
    private float $impT = 0.0;
    private ?float $pWarn = null;

    public function __construct(string $csv, private StockTracker $tracker, private int $skipTicks)
    {
        $this->fh = fopen($csv, 'w');
        fwrite($this->fh, "t,tk,tot,mkt,sec,idio,pull,brk,jI,jS,ma,pol,impP,impT,warn,rep\n");
        $this->capUp = FinancialConstants::MAX_JUMP_LOG_RETURN;
        $this->capDown = abs(FinancialConstants::MIN_JUMP_LOG_RETURN);
    }

    public static function install(StockTracker $tracker, string $csv, int $skipTicks): self
    {
        $probe = new self($csv, $tracker, $skipTicks);
        $origM = \Closure::bind(fn () => $this->marketEngine, $tracker, StockTracker::class)();
        $origE = \Closure::bind(fn () => $this->earningsEngine, $tracker, StockTracker::class)();
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

    public function onEngine(MarketPricingContext $ctx, array $r, MarketEngine $eng): void
    {
        $p0 = $ctx->currentPrice;
        $p1 = (float) $r['price'];
        $dt = $ctx->dt;
        $beta = $ctx->beta;
        $sigma = $ctx->longTermVolatility;
        $sqrtDt = sqrt($dt);

        $jI = $r['shock'] !== null ? log(1.0 + $r['shock'] / 100.0) : 0.0;
        $jS = 0.0;
        if ($ctx->marketJumpMultiplier > 0.0 && $ctx->marketJumpMultiplier !== 1.0) {
            $jS = max(FinancialConstants::MIN_JUMP_LOG_RETURN, min(FinancialConstants::MAX_JUMP_LOG_RETURN, log($ctx->marketJumpMultiplier) * $beta));
        }
        $ma = log(1.0 + $ctx->maShock);
        $fs = \Closure::bind(fn ($c) => $this->fundamentalStateFor($c), $eng, MarketEngine::class)($ctx);
        $pol = log((float) $fs['policy_repricing']);
        $total = log(max(0.01, $p1) / $p0);
        $rem = $total - $jI - $jS - $ma - $pol;

        // --- budget inputs, as the engine computes them ---
        $lambda = $ctx->lambda;
        $vi = MarketEngine::longTermIdiosyncraticVariance($sigma, $beta);
        $floor = 0.2 * $sigma * $sigma;
        $scale = MarketEngine::commonIdiosyncraticVarianceScale($ctx->marketVol, $ctx->marketVarianceTrend);
        $jp = MarketEngine::calibratedJumpParameters($sigma, $beta, $lambda, $ctx->jumpVol);
        $ownJ = $lambda > 0.0 ? $lambda * StochasticProcesses::kouTruncatedSecondMoment(0.40, $jp['eta_up'], $jp['eta_down'], $this->capUp, $this->capDown) : 0.0;
        $sysJ = 0.0;
        $ex = abs($beta);
        if ($ex > 0.0) {
            $eu = MacroConstants::SYSTEMIC_JUMP_ETA_UP / $ex;
            $ed = MacroConstants::SYSTEMIC_JUMP_ETA_DOWN / $ex;
            $pu = $beta > 0.0 ? MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP : 1.0 - MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP;
            $sysJ = MacroConstants::SYSTEMIC_JUMP_INTENSITY * StochasticProcesses::kouTruncatedSecondMoment($pu, $beta > 0.0 ? $eu : $ed, $beta > 0.0 ? $ed : $eu, $this->capUp, $this->capDown);
        }
        $cap = 0.25 * $vi;
        $imp = max(0.0, $ctx->orderFlowVariance);
        $ann = max(0.0, $ctx->announcementVariance);
        $smv = $ctx->priorMarketVol ?? $ctx->marketVol;
        $shockSys = ($beta * $smv) ** 2;
        $fund = min(max(0.0, $ctx->fundImpactVariance), $shockSys);
        $dmv = $shockSys > 0.0 ? $smv * sqrt(1.0 - $fund / $shockSys) : $smv;
        $kappa = MarketEngine::varianceReversionSpeed($lambda, $ctx->kappa);
        // The fixed tree (systemicJumpMoments exists) pays the systemic jump in V_I, drops its cap and theta
        // deduction, and floors the stripped state at 0; the baseline caps it at 25% V_I and floors at 0.2 sigma^2.
        $fixed = method_exists(MarketEngine::class, 'systemicJumpMoments');
        $sysJded = $fixed ? 0.0 : min($sysJ, $cap);
        $sysJstrip = $fixed ? $sysJ : min($sysJ, $cap);
        $theta = max(0.0001, ($vi * $scale - $sysJded - min($ownJ, $cap) - min($imp, $cap) - min($ann, $cap)) * (1.0 - MarketEngine::varianceJumpLoad($lambda, $kappa, $dt)));
        $curIdio = max($fixed ? 0.0 : $floor, $ctx->currentVolatility ** 2 - ($beta * $smv) ** 2 - $sysJstrip - min($ownJ, $cap));

        // --- split the diffusion-plus-pull remainder ---
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
            $mkt = $beta * $dmv * $ctx->marketZ * $sqrtDt;
            $sec = sqrt($curIdio) * sqrt(MarketEngine::SECTOR_FACTOR_VARIANCE_SHARE) * $ctx->sectorZ * $sqrtDt;
            $idio = $diff - $mkt - $sec;
        }

        $this->cur = [
            'p0' => $p0, 'p1' => max(0.01, $p1), 'trans' => \Closure::bind(fn () => $this->transientImpact, $this->tracker, StockTracker::class)(),
            'c' => [$mkt, $sec, $idio, $pull, $brk, $jI, $jS, $ma, $pol],
            'a' => [
                'n' => 1.0, 'sigma' => $sigma, 'beta' => $beta, 'vi' => $vi, 'floor' => $vi <= $floor + 1e-15 ? 1.0 : 0.0,
                'scale' => $scale, 'sysJ' => $sysJ, 'ownJ' => $ownJ, 'imp' => $imp, 'ann' => $ann, 'cap' => $cap,
                'sysJcapB' => $sysJ > $cap ? 1.0 : 0.0, 'ownJcapB' => $ownJ > $cap ? 1.0 : 0.0, 'impcapB' => $imp > $cap ? 1.0 : 0.0, 'anncapB' => $ann > $cap ? 1.0 : 0.0,
                'fund' => max(0.0, $ctx->fundImpactVariance), 'shockSys' => $shockSys, 'sysVarAnchor' => ($beta * MacroConstants::MACRO_VOL_BASE_ANCHOR) ** 2,
                'sysJallow' => $sysJstrip, 'sysBlock' => ($beta * MacroConstants::MACRO_VOL_BASE_ANCHOR) ** 2 + ($fixed ? $sysJ : 0.0),
                'theta' => $theta, 'curIdio' => $curIdio, 'mvol' => $ctx->marketVol, 'brkB' => $brk !== 0.0 ? 1.0 : 0.0,
            ],
        ];
    }

    public function onWarnEnter(Stock $s): void
    {
        if ($this->cur === null) {
            return;
        }
        $tk = $s->getTicker();
        $this->pImp = (float) $s->getPrice();
        $after = \Closure::bind(fn () => $this->transientImpact[$tk] ?? 0.0, $this->tracker, StockTracker::class)();
        $this->impT = $after - ($this->cur['trans'][$tk] ?? 0.0);
    }

    public function onWarnExit(Stock $s): void
    {
        $this->pWarn = (float) $s->getPrice();
    }

    public function onCalcExit(Stock $s, ?array $events): void
    {
        if ($this->cur === null || $this->pImp === null || $this->pWarn === null) {
            $this->cur = null;
            return;
        }
        $tk = $s->getTicker();
        $div = 0.0;
        foreach ($events ?? [] as $ev) {
            $div += (float) ($ev['dividend_per_share'] ?? 0.0);
        }
        $pE = (float) $s->getPrice();
        $c = $this->cur['c'];
        $impTot = log($this->pImp / $this->cur['p1']);
        $impP = $impTot - $this->impT;
        $warn = log($this->pWarn / $this->pImp);
        $rep = log(($pE + $div) / $this->pWarn);
        $tot = log(($pE + $div) / $this->cur['p0']);
        fwrite($this->fh, sprintf("%d,%s,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e,%.5e\n",
            $this->tick, $tk, $tot, $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $c[7], $c[8], $impP, $this->impT, $warn, $rep));
        if (!isset($this->meta[$tk])) {
            $this->meta[$tk] = ['sector' => $s->getSector(), 'industry' => $s->getIndustry(), 'sigma' => (float) $s->getVolatility()];
        }
        if ($this->tick > $this->skipTicks) {
            foreach ($this->cur['a'] as $k => $v) {
                $this->audit[$tk][$k] = ($this->audit[$tk][$k] ?? 0.0) + $v;
            }
        }
        $this->cur = null;
        $this->pImp = $this->pWarn = null;
    }

    public function finish(string $jsonPath): void
    {
        fclose($this->fh);
        file_put_contents($jsonPath, json_encode(['meta' => $this->meta, 'audit' => $this->audit]));
    }
}

class ProbeMarketEngine extends MarketEngine
{
    public ?PriceVolProbe $probe = null;

    public function calculateNextPrice(MarketPricingContext $ctx): array
    {
        $r = parent::calculateNextPrice($ctx);
        $this->probe?->onEngine($ctx, $r, $this);

        return $r;
    }
}

class ProbeEarningsEngine extends EarningsEngine
{
    public ?PriceVolProbe $probe = null;

    public function evaluatePreAnnouncement(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount, int $ticksPerYear): array
    {
        $this->probe?->onWarnEnter($stock);
        $r = parent::evaluatePreAnnouncement($stock, $macroState, $tickCount, $ticksPerYear);
        $this->probe?->onWarnExit($stock);

        return $r;
    }

    public function calculate(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {
        $r = parent::calculate($stock, $macroState, $tickCount, $ticksPerYear);
        $this->probe?->onCalcExit($stock, $r);

        return $r;
    }
}
