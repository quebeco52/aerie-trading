<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class DealmakerArchetype extends AbstractArchetype
{
    public function modifyAcquisitionAggression(float $baseAggression): float
    {
        // Massive M&A volume and frequency
        return $baseAggression * 2.5;
    }
    
    public function modifyDebtToleranceLimit(float $limit, float $effectiveCostOfDebt): float
    {
        // Extremely comfortable with high leverage (LBOs). Inherits base elasticity but adds a buffer.
        $adjusted = parent::modifyDebtToleranceLimit($limit, $effectiveCostOfDebt);
        return $adjusted + 0.15;
    }
    
    public function modifyMAndASynergyRange(float $min, float $max): array
    {
        // Willing to take huge risks on LBOs. Widens the variance of the synergy multiplier.
        return ['min' => $min - 0.10, 'max' => $max + 0.10];
    }
}
