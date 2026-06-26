<?php

namespace App\Service\Archetype;

interface ArchetypeInterface
{
    /**
     * Modifies the target operating cash balance for the company.
     */
    public function modifyTargetOperatingCash(float $targetCash): float;

    /**
     * Modifies the maximum debt tolerance limit before triggering a deleveraging sweep.
     */
    public function modifyDebtToleranceLimit(float $limit): float;

    /**
     * Modifies the probability that the company will invest in organic CapEx.
     */
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float;

    /**
     * Modifies the penalty applied when a market becomes saturated.
     */
    public function modifySaturationPenalty(float $penalty): float;

    /**
     * Modifies the target payout ratio for dividends.
     */
    public function modifyTargetPayoutRatio(float $targetPayout): float;

    /**
     * Determines if the CEO will refuse to cut the dividend despite being in distress.
     */
    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt): bool;

    /**
     * Modifies the aggressiveness of share buyback execution.
     */
    public function modifyBuybackAggression(float $aggression): float;

    /**
     * Modifies the aggressiveness of M&A execution.
     */
    public function modifyAcquisitionAggression(float $baseAggression): float;

    /**
     * Modifies the structural fixed cost ratio (bloat vs lean).
     */
    public function modifyFixedCostRatio(float $fixedCostRatio): float;

    /**
     * Modifies the variable margin target (theta) for the CIR process.
     */
    public function modifyVariableMarginTheta(float $theta): float;

    /**
     * Modifies the baseline idiosyncratic volatility (revenue shocks).
     */
    public function modifyIdiosyncraticVol(float $vol): float;

    /**
     * Modifies the baseline credit spread (bond market trust/penalty).
     */
    public function modifyCreditSpread(float $spread): float;

    /**
     * Modifies the minimum and maximum boundaries of the M&A synergy multiplier roll.
     * @return array{min: float, max: float}
     */
    public function modifyMAndASynergyRange(float $min, float $max): array;
}
