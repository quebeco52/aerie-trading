<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

/**
 * Wall time per phase of the tick, for the lag warning and the steady-state report.
 *
 * A phase is EVERYTHING SINCE THE LAST LAP, so a missing lap never loses time: it charges it to whatever is
 * lapped next, under that name. Add a lap whenever a phase grows a second job.
 *
 * The report window SUMS rather than samples, because most of the tick is not on every tick: options sweep on
 * their own interval, history rows and the reload on theirs. A window total divided by its ticks is what each
 * subsystem costs per tick, cadence included, which is the number to optimise against.
 */
final class TickProfiler
{
    // --- Diagnostics ---
    /** Phases named in a lag warning, most expensive first. */
    public const LAG_PHASES_SHOWN = 4;

    /** Ticks a steady-state phase report covers: long enough to average the option sweep, at a pass every few dozen ticks, over several passes. */
    public const PHASE_REPORT_INTERVAL_TICKS = 600;

    /** @var array<string, float> Milliseconds per phase of the tick in progress. */
    private array $phases = [];
    private int|float $phaseStart;

    /** @var array<string, float> */
    private array $windowPhases = [];
    private int $windowTicks = 0;
    private int $windowOverruns = 0;
    private float $windowMaxMs = 0.0;
    private float $windowTotalMs = 0.0;

    public function __construct()
    {
        $this->phaseStart = hrtime(true);
    }

    public function startTick(): void
    {
        $this->phases = [];
        $this->phaseStart = hrtime(true);
    }

    /** Charges the time since the last lap to $name. */
    public function lap(string $name): void
    {
        $now = hrtime(true);
        $this->phases[$name] = ($this->phases[$name] ?? 0.0) + (($now - $this->phaseStart) / 1e6);
        $this->phaseStart = $now;
    }

    /** Records a phase timed elsewhere, such as a stage inside the option sweep. */
    public function record(string $name, float $ms): void
    {
        $this->phases[$name] = $ms;
    }

    /** The most expensive phases of the tick just run, as "name ms" pairs. */
    public function breakdown(): string
    {
        $phases = $this->phases;
        arsort($phases);
        $shown = array_slice($phases, 0, self::LAG_PHASES_SHOWN, true);

        return implode(', ', array_map(
            static fn (string $name, float $ms): string => sprintf('%s %.1f', $name, $ms),
            array_keys($shown),
            $shown
        ));
    }

    /**
     * Adds the tick just run to the window, and closes the window once it is full.
     *
     * @return list<string> The window report when it closes; empty otherwise.
     */
    public function closeTick(float $executionMs, bool $overran, int $tickCount, float $budgetMs): array
    {
        $this->windowTicks++;
        $this->windowTotalMs += $executionMs;
        $this->windowMaxMs = max($this->windowMaxMs, $executionMs);
        $this->windowOverruns += $overran ? 1 : 0;

        foreach ($this->phases as $name => $ms) {
            $this->windowPhases[$name] = ($this->windowPhases[$name] ?? 0.0) + $ms;
        }

        if ($this->windowTicks < self::PHASE_REPORT_INTERVAL_TICKS) {
            return [];
        }

        arsort($this->windowPhases);
        $windowTicks = $this->windowTicks;
        $perTick = implode(', ', array_map(
            static fn (string $name, float $ms): string => sprintf('%s %.2f', $name, $ms / $windowTicks),
            array_keys($this->windowPhases),
            $this->windowPhases
        ));

        $report = [
            sprintf(
                '<info>⏱  Tick %d | %d ticks: mean %.1fms of %.1fms budget, max %.1fms, %d over (%.0f%%)</info>',
                $tickCount,
                $windowTicks,
                $this->windowTotalMs / $windowTicks,
                $budgetMs,
                $this->windowMaxMs,
                $this->windowOverruns,
                100.0 * $this->windowOverruns / $windowTicks
            ),
            '<info>   ms/tick: ' . $perTick . '</info>',
        ];

        $this->windowPhases = [];
        $this->windowTicks = 0;
        $this->windowOverruns = 0;
        $this->windowMaxMs = 0.0;
        $this->windowTotalMs = 0.0;

        return $report;
    }
}
