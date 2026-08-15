<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class TurnaroundArchetype extends AbstractArchetype
{
    public function modifyDebtToleranceLimit(float $limit, float $effectiveCostOfDebt): float
    {
        // Forces a significant reduction in debt tolerance to deleverage the company
        $adjusted = parent::modifyDebtToleranceLimit($limit, $effectiveCostOfDebt);
        return max(0.10, $adjusted - 0.20);
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool
    {
        // A turnaround CEO will aggressively cut the dividend to preserve cash for survival
        return false;
    }

    public function modifyTargetPayoutRatio(float $targetPayout): float
    {
        return $targetPayout * 0.20;
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

    public function modifyBuybackAggression(float $aggression): float
    {
        // Suspends buybacks to hoard cash and pay down debt
        return $aggression * 0.10;
    }

    public function modifyTargetOperatingCash(float $targetCash): float
    {
        // Builds a cash cushion to survive distress
        return $targetCash * 1.50;
    }
}
