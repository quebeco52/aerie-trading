<?php

namespace App\Service\Archetype;

class DealmakerArchetype extends AbstractArchetype
{
    public function modifyAcquisitionAggression(float $baseAggression): float
    {
        // Massive M&A volume and frequency
        return $baseAggression * 2.5;
    }
    
    public function modifyDebtToleranceLimit(float $limit): float
    {
        // Extremely comfortable with high leverage (LBOs)
        return $limit * 1.5;
    }
    
    public function modifyCreditSpread(float $spread): float
    {
        // Bond market demands a premium due to risky leverage profiles
        return $spread * 1.25;
    }
    
    public function modifyIdiosyncraticVol(float $vol): float
    {
        // More volatile due to constant corporate restructuring and dealmaking
        return $vol * 1.30;
    }
    
    public function modifyMAndASynergyRange(float $min, float $max): array
    {
        // Willing to take huge risks on LBOs. Widens the variance of the synergy multiplier.
        return ['min' => $min - 0.10, 'max' => $max + 0.10];
    }
}
