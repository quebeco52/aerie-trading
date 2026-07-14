<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Immutable value object encoding the Wall Street analyst coverage profile for a given sector.
 * Consumed exclusively by MarketConsensusEngine — never by physical BusinessModel classes.
 */
readonly class SectorCoverageProfile
{
    // --- Standard Coverage Profile ---
    /** Baseline fraction of sector shocks visible to analysts via public data sources. */
    public float $baseVisibility;
    /** Standard deviation of analyst estimation noise (σ). */
    public float $errorStdDev;
    /** Floor clamp applied to dynamic visibility after noise perturbation. */
    public float $minVisibility;

    // --- Event-Conditional Coverage (Biotech-style dual-mode) ---
    /** Analyst visibility when a binary public event has occurred (e.g., FDA approval). Null = no dual mode. */
    public ?float $eventBaseVisibility;
    /** Visibility floor for the event-conditional mode. */
    public ?float $eventMinVisibility;

    public function __construct(
        float $baseVisibility,
        float $errorStdDev,
        float $minVisibility = 0.0,
        ?float $eventBaseVisibility = null,
        ?float $eventMinVisibility = null,
    ) {
        $this->baseVisibility       = $baseVisibility;
        $this->errorStdDev          = $errorStdDev;
        $this->minVisibility        = $minVisibility;
        $this->eventBaseVisibility  = $eventBaseVisibility;
        $this->eventMinVisibility   = $eventMinVisibility;
    }
}
