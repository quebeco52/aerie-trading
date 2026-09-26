<?php

declare(strict_types=1);

namespace App\Service\Macro\Recorder;

/**
 * What a closed quarter's macro_report row carries beside the state vector: the two probes' accounts and the run
 * that produced it.
 */
final readonly class QuarterRecord
{
    /**
     * @param array<string, mixed>|null $gapChannels       OutputGapProbe's closed window, or null if none closed.
     * @param array<string, mixed>|null $diagnostics       MacroDiagnosticsProbe's closed window, or null if none closed.
     * @param string|null               $configFingerprint MacroConfigFingerprint of the constants that ran the quarter.
     * @param int|null                  $ticksPerYear      Simulation ticks per year the quarter ran at.
     */
    public function __construct(
        public ?array $gapChannels = null,
        public ?array $diagnostics = null,
        public ?string $configFingerprint = null,
        public ?int $ticksPerYear = null,
    ) {}
}
