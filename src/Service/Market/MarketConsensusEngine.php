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
    // --- Analyst Bias & Noise ---
    /** Default analyst error noise σ used when no profile is provided. */
    public const DEFAULT_ERROR_STD_DEV = 0.06;
    /** Default base visibility fraction when no profile is provided. */
    public const DEFAULT_BASE_VISIBILITY = 0.20;
    /** Walk-down bias fraction applied to consensus so beat rate aligns with empirical ~70% (Richardson et al. 2004). */
    public const ANALYST_WALKDOWN_BIAS = 0.015;

    /**
     * Generates the analyst consensus estimate for a given quarter.
     *
     * Formula:
     *   analystError        = N(0,1) * coverage.errorStdDev
     *   dynamicVisibility   = clamp(baseVisibility + analystError, minVisibility, 1.0)
     *   analystExpectedRev  = BayesianUpdate(priorAnchor, freshEstimate) * (1 - walkdownBias)
     *   analystExpectedVarC = analystExpectedRev * expectedVariableMargin
     *
     * @param ActualFinancialsDTO    $actuals                What the company actually produced this quarter.
     * @param SectorCoverageProfile  $coverage               Analyst coverage parameters for this sector.
     * @param float                  $expectedRevenue        Structural expected revenue before shocks.
     * @param MathUtility            $mathUtility            PRNG for analyst estimation noise.
     * @param \App\Entity\Stock      $stock                  The stock entity for anchor history.
     * @param float                  $marketVolatility       Prevailing market volatility (VIX proxy).
     * @param float|null             $expectedVariableMargin Ex-ante variable margin before physical shocks.
     * @param float                  $seasonalRatio          Seasonality adjustment ratio (Factor_t / Factor_{t-1}).
     */
    public function generateConsensus(
        ActualFinancialsDTO $actuals,
        SectorCoverageProfile $coverage,
        float $expectedRevenue,
        MathUtility $mathUtility,
        \App\Entity\Stock $stock,
        float $marketVolatility = 0.15,
        ?float $expectedVariableMargin = null,
        float $seasonalRatio = 1.0
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

        // Sloan (1996) Accruals Quality Anomaly: High non-cash accruals decay future growth expectations
        $accrualsDiscount = max(0.0, (float) ($stock->getAccrualsRatio() ?? 0.0) * \App\Service\Math\FinancialConstants::ACCRUALS_DECAY_EPS_GROWTH_SENSITIVITY);
        $discountedExpectedRevenue = max(1.0, $expectedRevenue * (1.0 - min(0.25, $accrualsDiscount)));

        $freshEstimate = $discountedExpectedRevenue * (1.0 + $actuals->observableShockZ * $dynamicVisibility);

        // Bayesian Updating: Analysts blend structural baseline capacity / anchored prior with noisy channel signals (fresh estimate)
        $priorVariance = \App\Service\Math\FinancialConstants::BAYESIAN_BASE_PRIOR_VARIANCE 
            + ($marketVolatility * \App\Service\Math\FinancialConstants::BAYESIAN_VIX_SCALING_FACTOR);
            
        $signalVariance = max(0.0001, pow($coverage->errorStdDev, 2));

        $anchor = (float) $stock->getLastAnalystRevenue();
        $priorEstimate = $anchor > 0.0
            ? $anchor * $seasonalRatio
            : $discountedExpectedRevenue;
        
        $analystExpectedRevenue = $mathUtility->calculateBayesianAnalystUpdate(
            $priorEstimate,
            $priorVariance,
            $freshEstimate,
            $signalVariance
        );

        $analystExpectedRevenue *= (1.0 - self::ANALYST_WALKDOWN_BIAS);

        $stock->setLastAnalystRevenue((string) $analystExpectedRevenue);

        $marginForCosts = $expectedVariableMargin !== null ? $expectedVariableMargin : $actuals->clampedMargin;
        $analystExpectedVariableCosts = $analystExpectedRevenue * $marginForCosts;

        return new ConsensusDTO(
            analystExpectedRevenue: $analystExpectedRevenue,
            analystExpectedVariableCosts: $analystExpectedVariableCosts,
            dynamicVisibility: $dynamicVisibility,
            estimateDispersion: $coverage->errorStdDev,
        );
    }
}
