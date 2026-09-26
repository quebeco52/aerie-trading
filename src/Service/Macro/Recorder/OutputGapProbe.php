<?php

declare(strict_types=1);

namespace App\Service\Macro\Recorder;

/**
 * Accumulates the output gap's drift channels, so a move in the gap can be attributed rather than guessed at.
 *
 * App\Service\Macro\Subsystem\MacroAggregateSubsystem::calculateOutputGap sums its demand and supply channels
 * into one drift and returns a single number. Watching that number says the economy turned; it
 * never says which channel turned it, and the channels routinely cancel — a recovery running at +2.4pp/yr
 * of monetary stimulus against -1.5pp/yr of private brakes reads on the dashboard as a quiet +0.9.
 *
 * WHAT IS ACCUMULATED IS CONTRIBUTION, NOT LEVEL. One tick at the production dt of 1/3600 of a year moves
 * the gap by a rounding error and the diffusion draw swamps every drift term inside it, so an instantaneous
 * reading is a jitter display. Each channel is integrated over the window instead — rate times dt, summed —
 * which gives the percentage points of gap the channel actually delivered, the quantity a drift
 * decomposition of a recession leg is measured in.
 *
 * The window's arithmetic closes: contributions + diffusion + clamp + external + unexplained is exactly the
 * change in the gap across the window. The two residuals watch different leaks. `unexplained` compares a tick's
 * move with the terms it was handed, so it stays at rounding while the subsystem sums the same array it reports,
 * and flags a term added to the move and not to that array. `external` compares each tick's opening gap with the
 * last tick's closing one, so it catches anything that writes the gap between ticks, which no per-tick check can.
 *
 * Off unless something turns it on, because only the ticker writes it and only the admin view reads it —
 * and those are two processes, so the reading is carried out of this object rather than read off it.
 *
 * Only the window currently OPEN goes over Redis. A window this object closes is handed to
 * App\Service\Macro\Recorder\MacroSnapshotRecorder and lands in the quarter's own macro_report row, beside
 * the state vector it explains. The closed history used to be a second copy in a capped Redis list, which
 * made the panel disagree with the table after any restart — Redis has no volume here — and gave the same
 * series two owners.
 */
final class OutputGapProbe
{
    // --- Wire ---

    /** Redis key the ticker publishes the OPEN window under; the admin view runs in another process and reads it there. */
    public const REDIS_KEY = 'macro_gap_debug';

    private bool $enabled = false;

    private bool $windowOpen = false;

    /**
     * Channel name => percentage of gap contributed since the window opened, as a fraction.
     *
     * Keys are the drift's own local variable names, so a line on the panel is greppable in the subsystem
     * that produced it.
     *
     * @var array<string, float>
     */
    private array $contributions = [];

    /** Diffusion drawn since the window opened, as a gap increment. */
    private float $diffusion = 0.0;

    /** Gap the ±bound took off the drift since the window opened; non-zero only against the clamp. */
    private float $clamp = 0.0;

    /** Gap movement no recorded channel accounts for; zero unless the drift and the decomposition have drifted apart. */
    private float $unexplained = 0.0;

    /** Gap moved between ticks, by something other than the drift equation; zero while it is the gap's only writer. */
    private float $external = 0.0;

    /** Gap the last recorded tick closed at, carried across windows so consecutive windows chain. */
    private ?float $lastClosingGap = null;

    /** Gap the window opened at. */
    private float $openingGap = 0.0;

    /** Gap as of the last tick folded in. */
    private float $closingGap = 0.0;

    /** Simulated years the window covers. */
    private float $years = 0.0;

    /** Ticks folded into the window. */
    private int $ticks = 0;

    /**
     * The last completed window, so the panel reads something in the first ticks of a new one.
     *
     * @var array<string, mixed>|null
     */
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
     * Folds one tick's drift into the open window.
     *
     * The pre-clamp gap is taken rather than derived, so the clamp is measured as the bound's actual effect
     * instead of becoming the bucket every accounting error falls into.
     *
     * @param array<string, float> $terms       Channel name => signed contribution to drift, in gap per year.
     * @param float                $diffusion   The tick's diffusion draw, already a gap increment.
     * @param float                $openingGap  Gap the tick started at.
     * @param float                $preClampGap Gap the drift and diffusion produced, before the bounds.
     * @param float                $closingGap  Gap the tick ended at, after the bounds.
     * @param float                $dt          Time increment in years.
     */
    public function record(
        array $terms,
        float $diffusion,
        float $openingGap,
        float $preClampGap,
        float $closingGap,
        float $dt
    ): void {
        if (!$this->enabled) {
            return;
        }

        if (!$this->windowOpen) {
            // A window opens where the last one closed, so a move between them is booked rather than lost.
            $this->openingGap = $this->lastClosingGap ?? $openingGap;
            $this->windowOpen = true;
        }

        if ($this->lastClosingGap !== null) {
            $this->external += $openingGap - $this->lastClosingGap;
        }
        $this->lastClosingGap = $closingGap;

        $drift = 0.0;
        foreach ($terms as $name => $rate) {
            $contribution = $rate * $dt;
            $this->contributions[$name] = ($this->contributions[$name] ?? 0.0) + $contribution;
            $drift += $contribution;
        }

        $this->diffusion += $diffusion;
        $this->clamp += $closingGap - $preClampGap;
        $this->unexplained += ($preClampGap - $openingGap - $diffusion) - $drift;
        $this->closingGap = $closingGap;
        $this->years += $dt;
        ++$this->ticks;
    }

    /**
     * Closes the open window and starts a new one, keeping the closed one for the panel.
     *
     * The ticker rolls on the simulation quarter it already records a macro snapshot on, so a reading is
     * "the quarter so far" against "last quarter" rather than an arbitrary slice.
     */
    public function rollWindow(): void
    {
        if (!$this->enabled || !$this->windowOpen) {
            return;
        }

        $this->previous = $this->window();

        $this->contributions = [];
        $this->diffusion = 0.0;
        $this->clamp = 0.0;
        $this->unexplained = 0.0;
        $this->external = 0.0;
        $this->years = 0.0;
        $this->ticks = 0;
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
     * One window's decomposition, every figure a fraction of potential output.
     *
     * @return array<string, mixed>
     */
    private function window(): array
    {
        return [
            'contributions' => $this->contributions,
            'drift' => array_sum($this->contributions),
            'diffusion' => $this->diffusion,
            'clamp' => $this->clamp,
            'unexplained' => $this->unexplained,
            'external' => $this->external,
            'opening_gap' => $this->openingGap,
            'closing_gap' => $this->closingGap,
            'change' => $this->closingGap - $this->openingGap,
            'years' => $this->years,
            'ticks' => $this->ticks,
        ];
    }
}
