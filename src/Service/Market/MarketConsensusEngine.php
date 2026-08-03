<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\ActualFinancialsDTO;
use App\DTO\ConsensusDTO;
use App\DTO\SectorCoverageProfile;
use App\Service\Math\MathUtility;

/**
 * Generates Wall Street analyst consensus estimates from physical financial outcomes.
 *
 * Sole responsibility: given what actually happened (ActualFinancialsDTO) and how visible that
 * sector is to analysts (SectorCoverageProfile), produce the consensus expectation (ConsensusDTO).
 *
 * This decoupling enables future features — Analyst Upgrades/Downgrades, Whisper Numbers,
 * Guidance Beats — without touching physical Business Model classes.
 */
class MarketConsensusEngine
{
    /** Default analyst error noise σ used when no profile is provided. */
    public const DEFAULT_ERROR_STD_DEV = 0.06;
    /** Default base visibility fraction when no profile is provided. */
    public const DEFAULT_BASE_VISIBILITY = 0.20;

    /**
     * Generates the analyst consensus estimate for a given quarter.
     *
     * Formula:
     *   analystError        = N(0,1) * coverage.errorStdDev
     *   dynamicVisibility   = clamp(baseVisibility + analystError, minVisibility, 1.0)
     *   analystExpectedRev  = expectedRevenue * (1 + observableShockZ * dynamicVisibility)
     *   analystExpectedVarC = analystExpectedRev * clampedMargin
     *
     * For event-conditional models (Biotech): when isPublicEvent is true, the higher
     * eventBaseVisibility / eventMinVisibility pair is used instead of the routine values.
     *
     * @param ActualFinancialsDTO  $actuals         What the company actually produced this quarter.
     * @param SectorCoverageProfile $coverage        Analyst coverage parameters for this sector.
     * @param float                $expectedRevenue Structural expected revenue before shocks.
     * @param MathUtility          $mathUtility     PRNG for analyst estimation noise.
     */
    public function generateConsensus(
        ActualFinancialsDTO $actuals,
        SectorCoverageProfile $coverage,
        float $expectedRevenue,
        MathUtility $mathUtility,
        \App\Entity\Stock $stock,
        float $marketVolatility = 0.15
    ): ConsensusDTO {
        $analystError = $mathUtility->generateStandardNormal() * $coverage->errorStdDev;

        // Event-conditional visibility fork (Biotech-style dual mode)
        if ($actuals->isPublicEvent === true && $coverage->eventBaseVisibility !== null) {
            $baseVisibility = $coverage->eventBaseVisibility;
            $minVisibility  = $coverage->eventMinVisibility ?? 0.0;
        } else {
            $baseVisibility = $coverage->baseVisibility;
            $minVisibility  = $coverage->minVisibility;
        }

        $dynamicVisibility = min(1.0, max($minVisibility, $baseVisibility + $analystError));

        $freshEstimate = $expectedRevenue * (1.0 + $actuals->observableShockZ * $dynamicVisibility);

        // Bayesian Updating: Analysts anchor to prior quarter's consensus based on uncertainty
        $lastEstimate = (float) $stock->getLastAnalystRevenue();
        
        if ($lastEstimate > 0.0) {
            $priorVariance = \App\Service\Math\FinancialConstants::BAYESIAN_BASE_PRIOR_VARIANCE 
                + ($marketVolatility * \App\Service\Math\FinancialConstants::BAYESIAN_VIX_SCALING_FACTOR);
                
            $signalVariance = max(0.01, $coverage->errorStdDev);
            
            $analystExpectedRevenue = $mathUtility->calculateBayesianAnalystUpdate(
                $lastEstimate,
                $priorVariance,
                $freshEstimate,
                $signalVariance
            );
        } else {
            $analystExpectedRevenue = $freshEstimate;
        }

        $stock->setLastAnalystRevenue((string) $analystExpectedRevenue);

        $analystExpectedVariableCosts = $analystExpectedRevenue * $actuals->clampedMargin;

        return new ConsensusDTO(
            analystExpectedRevenue: $analystExpectedRevenue,
            analystExpectedVariableCosts: $analystExpectedVariableCosts,
            dynamicVisibility: $dynamicVisibility,
        );
    }
}
