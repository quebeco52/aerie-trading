<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\DTO\ActualFinancialsDTO;
use App\DTO\ConsensusDTO;
use App\DTO\SectorCoverageProfile;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;

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
    /**
     * Walk-down of the published revenue consensus below the unbiased estimate: 1.5%, the average S&P 500 revenue
     * surprise (FactSet Earnings Insight, 10-year average; Richardson, Teoh & Wysocki 2004).
     */
    public const ANALYST_WALKDOWN_BIAS = 0.015;
    /**
     * Walk-down of the published EPS consensus in standard deviations of the firm's own measured surprise: 0.674, so
     * three reports in four beat (the S&P 500's 75% 10-year beat rate, FactSet Earnings Insight).
     */
    public const ANALYST_EPS_WALKDOWN_SURPRISE_SDS = 0.674;
    /** Time constant of the surprise record the walk-down is sized from: 1.5 years, about six reports. */
    public const SURPRISE_RECORD_MEMORY_YEARS = 1.5;
    /** Weight of the typical firm's surprise before a firm has a record of its own, in reports: two. */
    public const SURPRISE_RECORD_PRIOR_REPORTS = 2.0;
    /**
     * The typical firm's quarterly earnings surprise sd as a share of its quarterly revenue: 2.5%, the board's median.
     */
    public const TYPICAL_EARNINGS_SURPRISE_SD = 0.025;

    // --- Bayesian Analyst Consensus ---
    /**
     * Widest one-quarter change in the structural base the analyst anchor is rolled forward by (x0.5 to x2.0). A
     * capacity cap binding or a collapse is not a growth rate analysts extrapolate.
     */
    public const ANALYST_ANCHOR_MAX_ROLL_FORWARD = 2.0;
    /** Baseline prior uncertainty variance in market analyst earnings consensus formation (~0.06^2). */
    public const BAYESIAN_BASE_PRIOR_VARIANCE = 0.0036;
    /** Multiplier scaling analyst consensus prior uncertainty as VIX rises. */
    public const BAYESIAN_VIX_SCALING_FACTOR = 0.02;

    // --- Reported KPIs in Consensus ---
    /**
     * Share of a disclosed book-to-bill deviation from parity that analysts carry into the next quarter's revenue
     * estimate. Orders convert to revenue, so a disclosed order book is a forecast the market already holds.
     */
    public const BOOK_TO_BILL_CONSENSUS_SENSITIVITY = 0.35;
    /** Bound on the resulting forward revenue tilt, so a single blowout order quarter cannot run the estimate away. */
    public const MAX_BOOK_TO_BILL_CONSENSUS_TILT = 0.15;

    /**
     * The dollars the Street shades a firm's EPS estimate by: a fixed number of standard deviations of the surprise
     * the firm has measurably produced, on its quarterly revenue. A steady firm is walked down by little and a lumpy
     * one by a lot, and both beat about as often.
     */
    public static function epsWalkDown(float $quarterlyRevenue, float $surpriseVariance): float
    {
        return self::ANALYST_EPS_WALKDOWN_SURPRISE_SDS * sqrt(max(0.0, $surpriseVariance)) * max(0.0, $quarterlyRevenue);
    }

    /**
     * The surprise variance analysts hold for a firm: the mean of its weighted record, or the typical firm's before it
     * has one.
     *
     * @param float $weightedSquares The record's weighted sum of squared surprises (recordSurprise()).
     * @param float $weight          The record's total weight.
     */
    public static function surpriseVariance(float $weightedSquares, float $weight): float
    {
        return $weight > 0.0 ? $weightedSquares / $weight : self::TYPICAL_EARNINGS_SURPRISE_SD ** 2;
    }

    /**
     * One report's surprise rolled into the record, the old record decayed over the time since the last report. A
     * firm's first report joins the typical firm's surprise, worth SURPRISE_RECORD_PRIOR_REPORTS reports, which then
     * fades with the rest of the record.
     *
     * @return array{0: float, 1: float} The record's weighted sum of squares and its weight.
     */
    public static function recordSurprise(float $weightedSquares, float $weight, float $surprise, float $dt): array
    {
        $decay = exp(-$dt / self::SURPRISE_RECORD_MEMORY_YEARS);
        if ($weight <= 0.0) {
            $weight = self::SURPRISE_RECORD_PRIOR_REPORTS * (1.0 - $decay);
            $weightedSquares = $weight * self::TYPICAL_EARNINGS_SURPRISE_SD ** 2;
        }

        return [($weightedSquares * $decay) + ((1.0 - $decay) * $surprise ** 2), ($weight * $decay) + (1.0 - $decay)];
    }

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
     * @param float|null             $expectedVariableMargin Ex-ante variable cost ratio before the sector physics move
     *                                                       it.
     * @param float                  $seasonalRatio          Seasonality adjustment ratio (Factor_t / Factor_{t-1}).
     * @param float                  $priorExpectedRevenue   Pre-shock expected revenue the anchor was formed against; 0
     *                                                       when unknown.
     * @param float|null             $expectedRevenueBeforeShocks The quarter's expected revenue without the firm's own
     *                                                            demand
     *                                                     shock and jump; null when the caller has none to separate.
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
        float $priorExpectedRevenue = 0.0,
        ?float $expectedRevenueBeforeShocks = null,
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

        // Analysts do not discount accruals (Bradshaw, Richardson & Sloan 2001): the Sloan (1996) anomaly is that
        // the estimate fixates on reported earnings, and the market's fair value penalty is where it is priced.
        // The firm's own demand shock and jump this quarter are news: the estimate starts from the demand the firm
        // was expected to see and hears the shock only at the share public data reveals, as it hears stream noise.
        $baseExpectedRevenue = max(1.0, $expectedRevenueBeforeShocks ?? $expectedRevenue);
        $ownShock = ($expectedRevenue / $baseExpectedRevenue) - 1.0;

        // Reported KPIs feeding consensus: an order-driven firm discloses book-to-bill, and orders above
        // parity are revenue that has already been won and not yet billed. Analysts do not ignore that —
        // they carry it into the forward estimate, which is why a semiconductor or capital-goods forecast
        // moves on the order line before it moves on the revenue line. Without this the backlog conversion
        // the models already run was invisible to consensus and showed up as a standing surprise.
        $bookToBill = $stock->getLastBookToBill();
        $orderBookTilt = $bookToBill !== null && $bookToBill > 0.0
            ? max(
                -self::MAX_BOOK_TO_BILL_CONSENSUS_TILT,
                min(
                    self::MAX_BOOK_TO_BILL_CONSENSUS_TILT,
                    ($bookToBill - 1.0) * self::BOOK_TO_BILL_CONSENSUS_SENSITIVITY,
                ),
            )
            : 0.0;

        // A benchmark price or a disclosed rent roll is read in full, whatever the coverage.
        $freshEstimate = $baseExpectedRevenue * (1.0 + $orderBookTilt)
            * (1.0 + $ownShock * $dynamicVisibility)
            * (1.0 + $actuals->publishedRevenueShift)
            * (1.0 + $actuals->observableShockZ * $dynamicVisibility);

        // Bayesian Updating: Analysts blend structural baseline capacity / anchored prior with noisy channel signals
        // (fresh estimate)
        $priorVariance = self::BAYESIAN_BASE_PRIOR_VARIANCE
            + ($marketVolatility * self::BAYESIAN_VIX_SCALING_FACTOR);

        $signalVariance = max(0.0001, pow($coverage->errorStdDev, 2));

        // Roll prior analyst revenue estimate forward with growth in structural capacity: the pre-shock base on both
        // quarters, so the roll carries no more of this quarter's shock than the fresh signal does.
        $anchor = (float) $stock->getLastAnalystRevenue();
        if ($anchor > 0.0 && $priorExpectedRevenue > 0.0) {
            $rollForward = max(
                1.0 / self::ANALYST_ANCHOR_MAX_ROLL_FORWARD,
                min(self::ANALYST_ANCHOR_MAX_ROLL_FORWARD, $baseExpectedRevenue / $priorExpectedRevenue),
            );
            $priorEstimate = $anchor * $rollForward;
        } elseif ($anchor > 0.0) {
            $priorEstimate = $anchor * $seasonalRatio;
        } else {
            $priorEstimate = $baseExpectedRevenue;
        }

        $analystExpectedRevenue = Valuation::calculateBayesianAnalystUpdate(
            $priorEstimate,
            $priorVariance,
            $freshEstimate,
            $signalVariance,
        );

        // Store pre-walkdown posterior as anchor so walkdown shading does not compound across quarters.
        $stock->setLastAnalystRevenue((string) $analystExpectedRevenue);

        $analystUnbiasedRevenue = $analystExpectedRevenue;
        $analystExpectedRevenue *= (1.0 - self::ANALYST_WALKDOWN_BIAS);

        // Analyst cost ratio expectation: anchor on last reported ratio, rolled to this quarter's known seasonal mix,
        // and incorporate visible systematic cost shifts.
        $priorCostRatio = $stock->getLastReportedCostRatio();
        $anchorCostRatio = $priorCostRatio !== null
            ? (float) $priorCostRatio + $actuals->seasonalCostRatioShift
            : ($expectedVariableMargin ?? $actuals->clampedMargin);

        $expectedCostRatio = $anchorCostRatio
            + (FinancialConstants::ANALYST_COST_BASE_VISIBILITY * ($actuals->clampedMargin - $anchorCostRatio));
        $stock->setLastReportedCostRatio((string) $actuals->clampedMargin);

        // Price is not produced, so the cost ratio bites on the volume part of revenue only — exactly as the
        // physics applies it. Charging the ratio against the whole estimate instead left every firm with a
        // price-driven revenue stream looking permanently cheaper to run than the analysts assumed.
        // A price below the expected level leaves the volume costing more than the revenue it now brings in.
        $volumeShare = $actuals->actualRevenue > 0.0
            ? max(0.0, ($actuals->actualRevenue - $actuals->priceRevenue) / $actuals->actualRevenue)
            : 1.0;
        // On the unshaded revenue: the walk-down is a shading of the published figures (Richardson, Teoh & Wysocki
        // 2004), not a view that the firm will sell less and so spend less.
        $analystExpectedVariableCosts = $analystUnbiasedRevenue * $volumeShare * $expectedCostRatio;

        return new ConsensusDTO(
            analystExpectedRevenue: $analystExpectedRevenue,
            analystExpectedVariableCosts: $analystExpectedVariableCosts,
            dynamicVisibility: $dynamicVisibility,
            analystUnbiasedRevenue: $analystUnbiasedRevenue,
        );
    }
}
