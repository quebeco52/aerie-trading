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
     * @param float $analystExpectedRevenue       Consensus revenue estimate based on partial shock visibility.
     * @param float $analystExpectedVariableCosts Consensus variable cost estimate.
     * @param float $dynamicVisibility            The realized visibility fraction used this quarter (for debugging/telemetry).
     * @param float $estimateDispersion           Analyst estimate standard deviation (dispersion) used for SUE calculation.
     */
    public function __construct(
        public float $analystExpectedRevenue,
        public float $analystExpectedVariableCosts,
        public float $dynamicVisibility,
        public float $estimateDispersion = 0.06,
    ) {}
}
