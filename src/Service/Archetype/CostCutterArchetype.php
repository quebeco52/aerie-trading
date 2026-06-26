<?php

namespace App\Service\Archetype;

class CostCutterArchetype extends AbstractArchetype
{
    public function modifyFixedCostRatio(float $fixedCostRatio): float
    {
        // Obsessed with operational efficiency
        return min(0.85, $fixedCostRatio * 0.80);
    }

    public function modifyVariableMarginTheta(float $theta): float
    {
        // Better variable cost discipline
        return $theta * 0.90;
    }

    public function modifyInvestmentProbability(float $prob, float $trueReturn): float
    {
        // Refuses to spend on CapEx, preferring to hoard cash
        return $prob * 0.50;
    }

    public function modifyAcquisitionAggression(float $baseAggression): float
    {
        // Hates M&A (too expensive, creates bloat)
        return $baseAggression * 0.20;
    }

    public function modifyTargetOperatingCash(float $targetCash): float
    {
        // Hoards cash aggressively
        return $targetCash * 1.50;
    }
}
