<?php

declare(strict_types=1);

namespace App\Service\Macro\Recorder;

use App\Service\Macro\MacroState;

/**
 * Records what set inflation and the policy rate over a window, beside the output gap probe's account of the gap.
 *
 * The gap, inflation and the policy rate are the three legs of the loop, and a calibration that can attribute only
 * one of them has to rebuild the other two offline from their inputs. Inflation and the target rate are LEVELS
 * rather than drifts, so the account here is a time average: each term is integrated as value times dt and divided
 * by the window's length, which makes the terms sum exactly to the window's average of the quantity they build.
 *
 * The same window also carries the averages of the series the US data are published as quarterly averages of (the
 * CBO gap, core PCE, the funds rate, the GZ premium), so a comparison against those data does not measure the last
 * tick of a quarter against a quarter's mean; the shares of time the policy rate spent under each constraint; and a
 * log of the jumps each stochastic process drew.
 *
 * Off unless the ticker turns it on, like App\Service\Macro\Recorder\OutputGapProbe, and rolled on the same quarter
 * boundary: the open window goes over Redis to the admin view, the closed one into its quarter's macro_report row.
 */
final class MacroDiagnosticsProbe
{
    // --- Wire ---

    /** Redis key the ticker publishes the OPEN window under; the admin view runs in another process and reads it there. */
    public const REDIS_KEY = 'macro_diagnostics';

    // --- Averaged Series ---

    /** State fields averaged over the window: the series whose data counterparts are quarterly averages, and the stance behind them. */
    public const AVERAGED_FIELDS = [
        'outputGap',
        'inflation',
        'supercoreInflation',
        'coreGoodsInflation',
        'tipsBreakeven',
        'policyRate',
        'targetRate',
        'yield2y',
        'yield10y',
        'excessBondPremium',
        'macroCreditSpread',
        'highYieldCreditSpread',
        'unemploymentRate',
        'marketVolatility',
        'creditToGdpGap',
        'monetaryStanceTransmitted',
    ];

    private bool $enabled = false;

    private bool $windowOpen = false;

    /** @var array<string, float> Inflation term => integral over the window. */
    private array $inflationTerms = [];

    private float $inflationIntegral = 0.0;

    private float $inflationYears = 0.0;

    /** @var array<string, float> Target-rate term => integral over the window. */
    private array $policyTerms = [];

    private float $targetIntegral = 0.0;

    private float $targetYears = 0.0;

    private float $rateIntegral = 0.0;

    private float $rateYears = 0.0;

    /** @var array<string, float> Constraint => years it held over the window. */
    private array $constraintYears = [];

    /** @var array<string, float> Field => integral over the window. */
    private array $averages = [];

    private float $averageYears = 0.0;

    private int $ticks = 0;

    /** @var array<string, array{count: int, sum: float, maxAbs: float}> Process => jumps drawn in the window. */
    private array $events = [];

    /** @var array<string, float> Shock => sum of its innovations over the window. */
    private array $shocks = [];

    /** @var array<string, mixed>|null The last completed window, so the panel reads something early in a new one. */
    private ?array $previous = null;

    /** Starts recording. The ticker turns this on; the web process leaves it off. */
    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Folds one tick of inflation's terms into the window.
     *
     * @param array<string, float> $terms     Term name => its part of this tick's inflation; they sum to $inflation.
     * @param float                $inflation The tick's headline inflation.
     * @param float                $dt        Time increment in years.
     */
    public function recordInflation(array $terms, float $inflation, float $dt): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->windowOpen = true;
        foreach ($terms as $name => $value) {
            $this->inflationTerms[$name] = ($this->inflationTerms[$name] ?? 0.0) + ($value * $dt);
        }
        $this->inflationIntegral += $inflation * $dt;
        $this->inflationYears += $dt;
    }

    /**
     * Folds one tick of the Taylor target's terms into the window.
     *
     * @param array<string, float> $terms  Term name => its part of this tick's target; they sum to $target.
     * @param float                $target The tick's unclamped target (the shadow rate).
     * @param float                $dt     Time increment in years.
     */
    public function recordPolicyTarget(array $terms, float $target, float $dt): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->windowOpen = true;
        foreach ($terms as $name => $value) {
            $this->policyTerms[$name] = ($this->policyTerms[$name] ?? 0.0) + ($value * $dt);
        }
        $this->targetIntegral += $target * $dt;
        $this->targetYears += $dt;
    }

    /**
     * Folds one tick of the policy rate, and which constraints held it, into the window.
     *
     * @param float               $rate        The policy rate the tick set.
     * @param array<string, bool> $constraints Constraint name => whether it bound this tick.
     * @param float               $dt          Time increment in years.
     */
    public function recordPolicyRate(float $rate, array $constraints, float $dt): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->windowOpen = true;
        $this->rateIntegral += $rate * $dt;
        $this->rateYears += $dt;
        foreach ($constraints as $name => $binding) {
            $this->constraintYears[$name] = ($this->constraintYears[$name] ?? 0.0) + ($binding ? $dt : 0.0);
        }
    }

    /**
     * Folds one tick of the averaged series into the window; the engine calls it once per tick, at the end.
     *
     * @param MacroState $state The state as the tick left it.
     * @param float      $dt    Time increment in years.
     */
    public function recordAverages(MacroState $state, float $dt): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->windowOpen = true;
        foreach (self::AVERAGED_FIELDS as $field) {
            $this->averages[$field] = ($this->averages[$field] ?? 0.0) + ($state->$field * $dt);
        }
        $this->averageYears += $dt;
        ++$this->ticks;
    }

    /**
     * Logs one jump a process drew.
     *
     * @param string $process Process name, as the panel and the dump list it.
     * @param float  $size    Signed jump size, in the process's own units.
     */
    public function recordEvent(string $process, float $size): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->windowOpen = true;
        $event = $this->events[$process] ?? ['count' => 0, 'sum' => 0.0, 'maxAbs' => 0.0];
        $this->events[$process] = [
            'count' => $event['count'] + 1,
            'sum' => $event['sum'] + $size,
            'maxAbs' => max($event['maxAbs'], abs($size)),
        ];
    }

    /**
     * Adds one tick's innovation of a continuous shock, which draws every tick and so is summed rather than logged.
     *
     * @param string $name  Shock name.
     * @param float  $value The tick's innovation.
     */
    public function recordShock(string $name, float $value): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->windowOpen = true;
        $this->shocks[$name] = ($this->shocks[$name] ?? 0.0) + $value;
    }

    /** Closes the open window and starts a new one, keeping the closed one for the panel. */
    public function rollWindow(): void
    {
        if (!$this->enabled || !$this->windowOpen) {
            return;
        }

        $this->previous = $this->window();

        $this->inflationTerms = [];
        $this->inflationIntegral = 0.0;
        $this->inflationYears = 0.0;
        $this->policyTerms = [];
        $this->targetIntegral = 0.0;
        $this->targetYears = 0.0;
        $this->rateIntegral = 0.0;
        $this->rateYears = 0.0;
        $this->constraintYears = [];
        $this->averages = [];
        $this->averageYears = 0.0;
        $this->ticks = 0;
        $this->events = [];
        $this->shocks = [];
        $this->windowOpen = false;
    }

    /**
     * The open window and the last closed one, as the payload the admin view renders.
     *
     * @return array{enabled: bool, current: array<string, mixed>|null, previous: array<string, mixed>|null}
     */
    public function snapshot(): array
    {
        return [
            'enabled' => $this->enabled,
            'current' => $this->windowOpen ? $this->window() : null,
            'previous' => $this->previous,
        ];
    }

    /**
     * One window, every section a time average over the years it was recorded for.
     *
     * @return array<string, mixed>
     */
    private function window(): array
    {
        return [
            'years' => max($this->averageYears, $this->inflationYears, $this->targetYears, $this->rateYears),
            'ticks' => $this->ticks,
            'inflation' => $this->inflationYears > 0.0 ? [
                'average' => $this->inflationIntegral / $this->inflationYears,
                'terms' => self::divide($this->inflationTerms, $this->inflationYears),
            ] : null,
            'policy' => $this->targetYears > 0.0 || $this->rateYears > 0.0 ? [
                'averageTarget' => $this->targetYears > 0.0 ? $this->targetIntegral / $this->targetYears : null,
                'averageRate' => $this->rateYears > 0.0 ? $this->rateIntegral / $this->rateYears : null,
                'terms' => $this->targetYears > 0.0 ? self::divide($this->policyTerms, $this->targetYears) : [],
                'constraints' => $this->rateYears > 0.0 ? self::divide($this->constraintYears, $this->rateYears) : [],
            ] : null,
            'averages' => $this->averageYears > 0.0 ? self::divide($this->averages, $this->averageYears) : [],
            'events' => $this->events,
            'shocks' => $this->shocks,
        ];
    }

    /**
     * @param  array<string, float> $integrals Name => integral over the window.
     * @return array<string, float>             Name => time average.
     */
    private static function divide(array $integrals, float $years): array
    {
        return array_map(static fn (float $integral): float => $integral / $years, $integrals);
    }
}
