<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Immutable Data Transfer Object representing the Wall Street analyst consensus estimate.
 * Produced exclusively by MarketConsensusEngine from ActualFinancialsDTO + SectorCoverageProfile.
 */
readonly class ConsensusDTO
{
    /**
     * @param float $analystExpectedRevenue       Published consensus revenue, walked down to a beatable figure.
     * @param float $analystExpectedVariableCosts Consensus variable cost estimate, on the unshaded revenue.
     * @param float $dynamicVisibility            The realized visibility fraction used this quarter (for
     *                                            debugging/telemetry).
     * @param float $analystUnbiasedRevenue       The estimate before the walk-down, which the earnings estimate is
     *                                            built on.
     */
    public function __construct(
        public float $analystExpectedRevenue,
        public float $analystExpectedVariableCosts,
        public float $dynamicVisibility,
        public float $analystUnbiasedRevenue = 0.0,
    ) {}
}
