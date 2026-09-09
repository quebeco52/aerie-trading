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
     * @param float                $scheduledCapex    Mandatory quarterly CapEx the physics itself commits (spectrum
     *                                                auctions, grid rebuilds, plant turnarounds); it is deducted from
     *                                                FCF and queued as construction-in-progress by the engine.
     * @param array<string, float> $kpis              Reported operating KPIs analysts track beyond revenue and EPS
     *                                                (book_to_bill, backlog_quarters, subscriber_index, churn...).
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
        public float $scheduledCapex = 0.0,
        public array $kpis = [],
        /** Dollar provision for credit losses the physics charged this quarter beyond the through-the-cycle loss already in the cost base (negative for a reserve release). */
        public float $creditLossProvision = 0.0,
        /** Dollar loans and securities that went bad this quarter and are written off against the allowance. */
        public float $netChargeOffs = 0.0,
        /** Dollars of actual revenue that are pure price above the expected level (escalators, Veblen hikes, spot rates on a fixed fleet). Price carries no variable cost, so the template method applies the cost ratio to volume revenue only. */
        public float $priceRevenue = 0.0,
    ) {
    }
}
