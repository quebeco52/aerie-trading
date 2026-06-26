<?php

namespace App\Service\Archetype;

class TurnaroundArchetype extends AbstractArchetype
{
    public function modifyFixedCostRatio(float $fixedCostRatio): float
    {
        // Massively slashes fixed costs (layoffs, restructuring)
        return min(0.85, $fixedCostRatio * 0.75);
    }

    public function modifyDebtToleranceLimit(float $limit): float
    {
        // Highly averse to debt; aggressive deleveraging sweeps
        return $limit * 0.50;
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt): bool
    {
        // A turnaround CEO will aggressively cut the dividend to preserve cash for survival
        return false;
    }

    public function modifyInvestmentProbability(float $prob, float $trueReturn): float
    {
        // Focuses on saving the core business, not expanding it
        return $prob * 0.50;
    }

    public function modifyAcquisitionAggression(float $baseAggression): float
    {
        // Zero interest in M&A while turning the ship around
        return $baseAggression * 0.10;
    }
}
