<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Strongly-typed Data Transfer Object representing internal sector physics calculated by child Business Models,
 * before Template Method margin clamping. Contains only physical/operational outputs — no analyst data.
 */
readonly class SectorPhysicsResult
{
    /**
     * @param float                $actualRevenue     Realized revenue after sector-specific shocks.
     * @param float                $rawVariableMargin Raw variable cost ratio before template-method clamping.
     * @param float                $primaryShockZ     Dominant idiosyncratic Z-score for this quarter.
     * @param float                $observableShockZ  The shock component visible to public data (passed to MarketConsensusEngine).
     * @param string|null          $eventType         Named tail-risk event type, or null.
     * @param array<string, mixed> $eventContext      Key-value lore context for NarrativeEngine.
     * @param bool|null            $isPublicEvent     For event-conditional models: true when a binary public event fired.
     * @param array<string, float> $streamZ           Dictionary of individual AR(1) stream Z-scores to persist.
     * @param array<string, float> $streamRevenue     Dictionary of absolute dollar revenue generated per stream.
     */
    public function __construct(
        public float $actualRevenue,
        public float $rawVariableMargin,
        public float $primaryShockZ,
        public float $observableShockZ,
        public ?string $eventType = null,
        public array $eventContext = [],
        public ?bool $isPublicEvent = null,
        public array $streamZ = [],
        public array $streamRevenue = [],
    ) {
    }
}
