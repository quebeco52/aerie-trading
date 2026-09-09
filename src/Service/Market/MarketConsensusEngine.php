<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\ActualFinancialsDTO;
use App\DTO\ConsensusDTO;
use App\DTO\SectorCoverageProfile;
use App\Service\Math\FinancialConstants;
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
     *   estimateDispersion  = coverage.errorStdDev * volatilityScale
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
        $accrualsDiscount = max(0.0, (float) ($stock->getAccrualsRatio() ?? 0.0) * FinancialConstants::ACCRUALS_DECAY_EPS_GROWTH_SENSITIVITY);
        $discountedExpectedRevenue = max(1.0, $expectedRevenue * (1.0 - min(0.25, $accrualsDiscount)));

        // Reported KPIs feeding consensus: an order-driven firm discloses book-to-bill, and orders above
        // parity are revenue that has already been won and not yet billed. Analysts do not ignore that —
        // they carry it into the forward estimate, which is why a semiconductor or capital-goods forecast
        // moves on the order line before it moves on the revenue line. Without this the backlog conversion
        // the models already run was invisible to consensus and showed up as a standing surprise.
        $bookToBill = $stock->getLastBookToBill();
        $orderBookTilt = $bookToBill !== null && $bookToBill > 0.0
            ? max(
                -FinancialConstants::MAX_BOOK_TO_BILL_CONSENSUS_TILT,
                min(
                    FinancialConstants::MAX_BOOK_TO_BILL_CONSENSUS_TILT,
                    ($bookToBill - 1.0) * FinancialConstants::BOOK_TO_BILL_CONSENSUS_SENSITIVITY
                )
            )
            : 0.0;

        $freshEstimate = $discountedExpectedRevenue * (1.0 + $orderBookTilt) * (1.0 + $actuals->observableShockZ * $dynamicVisibility);

        // Bayesian Updating: Analysts blend structural baseline capacity / anchored prior with noisy channel signals (fresh estimate)
        $priorVariance = FinancialConstants::BAYESIAN_BASE_PRIOR_VARIANCE 
            + ($marketVolatility * FinancialConstants::BAYESIAN_VIX_SCALING_FACTOR);
            
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

        // Analyst disagreement is regime-dependent: forecasts fan out when the macro outlook is volatile and
        // converge when it is calm. Holding dispersion at the sector's calm-market constant made the SUE
        // denominator regime-blind, so an identical percentage miss read as an identical sigma event in a
        // panic as in a quiet quarter. This mirrors the volatility scaling already applied to the Bayesian
        // prior variance above, keeping both halves of the consensus on the same uncertainty measure.
        $volatilityExcess = max(0.0, $marketVolatility - FinancialConstants::DISPERSION_BASELINE_VOLATILITY);
        $dispersionScale = min(
            FinancialConstants::DISPERSION_MAX_SCALE,
            1.0 + ($volatilityExcess * FinancialConstants::DISPERSION_VIX_SENSITIVITY)
        );

        return new ConsensusDTO(
            analystExpectedRevenue: $analystExpectedRevenue,
            analystExpectedVariableCosts: $analystExpectedVariableCosts,
            dynamicVisibility: $dynamicVisibility,
            estimateDispersion: $coverage->errorStdDev * $dispersionScale,
        );
    }
}
