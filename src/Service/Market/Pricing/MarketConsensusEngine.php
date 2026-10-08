<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

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
    /** Walk-down bias fraction applied to consensus so beat rate aligns with empirical ~70% (Richardson et al. 2004). */
    public const ANALYST_WALKDOWN_BIAS = 0.015;

    /**
     * Generates the analyst consensus estimate for a given quarter.
     *
     * Formula:
     *   analystError        = N(0,1) * coverage.errorStdDev
     *   dynamicVisibility   = clamp(baseVisibility + analystError, minVisibility, 1.0)
     *   analystExpectedRev  = BayesianUpdate(priorAnchor, freshEstimate) * (1 - walkdownBias)
     *   expectedCostRatio   = blend(expectedVariableMargin, realized cost ratio) by ANALYST_COST_BASE_VISIBILITY
     *   analystExpectedVarC = analystExpectedRev * volumeShare * expectedCostRatio
     *
     * @param ActualFinancialsDTO    $actuals                What the company actually produced this quarter.
     * @param SectorCoverageProfile  $coverage               Analyst coverage parameters for this sector.
     * @param float                  $expectedRevenue        Structural expected revenue before shocks.
     * @param MathUtility            $mathUtility            PRNG for analyst estimation noise.
     * @param \App\Entity\Stock      $stock                  The stock entity for anchor history.
     * @param float                  $marketVolatility       Prevailing market volatility (VIX proxy).
     * @param float|null             $expectedVariableMargin Ex-ante variable cost ratio before the sector physics move it.
     * @param float                  $seasonalRatio          Seasonality adjustment ratio (Factor_t / Factor_{t-1}).
     * @param float                  $priorExpectedRevenue   Structural expected revenue the anchor was formed against; 0 when unknown.
     */
    public function generateConsensus(
        ActualFinancialsDTO $actuals,
        SectorCoverageProfile $coverage,
        float $expectedRevenue,
        MathUtility $mathUtility,
        \App\Entity\Stock $stock,
        float $marketVolatility = 0.15,
        ?float $expectedVariableMargin = null,
        float $seasonalRatio = 1.0,
        float $priorExpectedRevenue = 0.0
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

        // Roll prior analyst revenue estimate forward with growth in structural capacity.
        $anchor = (float) $stock->getLastAnalystRevenue();
        if ($anchor > 0.0 && $priorExpectedRevenue > 0.0 && $expectedRevenue > 0.0) {
            $rollForward = max(
                1.0 / FinancialConstants::ANALYST_ANCHOR_MAX_ROLL_FORWARD,
                min(FinancialConstants::ANALYST_ANCHOR_MAX_ROLL_FORWARD, $expectedRevenue / $priorExpectedRevenue)
            );
            $priorEstimate = $anchor * $rollForward;
        } elseif ($anchor > 0.0) {
            $priorEstimate = $anchor * $seasonalRatio;
        } else {
            $priorEstimate = $discountedExpectedRevenue;
        }
        
        $analystExpectedRevenue = $mathUtility->calculateBayesianAnalystUpdate(
            $priorEstimate,
            $priorVariance,
            $freshEstimate,
            $signalVariance
        );

        // Store pre-walkdown posterior as anchor so walkdown shading does not compound across quarters.
        $stock->setLastAnalystRevenue((string) $analystExpectedRevenue);

        $analystExpectedRevenue *= (1.0 - self::ANALYST_WALKDOWN_BIAS);

        // Analyst cost ratio expectation: anchor on last reported ratio and incorporate visible systematic cost shifts.
        $priorCostRatio = $stock->getLastReportedCostRatio();
        $anchorCostRatio = $priorCostRatio !== null
            ? (float) $priorCostRatio
            : ($expectedVariableMargin ?? $actuals->clampedMargin);

        $expectedCostRatio = $anchorCostRatio
            + (FinancialConstants::ANALYST_COST_BASE_VISIBILITY * ($actuals->clampedMargin - $anchorCostRatio));
        $stock->setLastReportedCostRatio((string) $actuals->clampedMargin);

        // Price is not produced, so the cost ratio bites on the volume part of revenue only — exactly as the
        // physics applies it. Charging the ratio against the whole estimate instead left every firm with a
        // price-driven revenue stream looking permanently cheaper to run than the analysts assumed.
        $volumeShare = $actuals->actualRevenue > 0.0
            ? max(0.0, min(1.0, ($actuals->actualRevenue - $actuals->priceRevenue) / $actuals->actualRevenue))
            : 1.0;
        $analystExpectedVariableCosts = $analystExpectedRevenue * $volumeShare * $expectedCostRatio;

        return new ConsensusDTO(
            analystExpectedRevenue: $analystExpectedRevenue,
            analystExpectedVariableCosts: $analystExpectedVariableCosts,
            dynamicVisibility: $dynamicVisibility,
        );
    }
}
