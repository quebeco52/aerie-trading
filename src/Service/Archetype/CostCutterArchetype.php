<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class CostCutterArchetype extends AbstractArchetype
{
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float
    {
        // Refuses to over-invest in CapEx
        return $prob * 0.50;
    }

    public function modifyAcquisitionAggression(float $baseAggression): float
    {
        // Avoids expensive M&A
        return $baseAggression * 0.20;
    }

    public function modifyTargetOperatingCash(float $targetCash): float
    {
        // Maintains a strong cash reserve
        return $targetCash * 1.50;
    }

    public function modifyBuybackAggression(float $aggression): float
    {
        // Returns surplus capital efficiently via repurchases
        return min(1.0, $aggression * 1.25);
    }

    public function modifyDebtToleranceLimit(float $limit, float $effectiveCostOfDebt): float
    {
        $adjusted = parent::modifyDebtToleranceLimit($limit, $effectiveCostOfDebt);
        return max(0.10, $adjusted * 0.85);
    }
}
