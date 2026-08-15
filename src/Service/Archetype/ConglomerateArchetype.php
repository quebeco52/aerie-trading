<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class ConglomerateArchetype extends AbstractArchetype
{
    public function modifyMAndASynergyRange(float $min, float $max): array
    {
        // Refuses to overpay. Shifts synergy range UP, guaranteeing strong accretion.
        return ['min' => $min + 0.10, 'max' => $max + 0.20];
    }
    
    public function modifyAcquisitionAggression(float $baseAggression): float
    {
        // Extremely picky. Executes M&A deals 75% less often than peers.
        return $baseAggression * 0.25;
    }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float
    {
        // High hurdle rates for organic investment. Often sits out entirely.
        return $prob * 0.50;
    }
    
    public function modifyTargetOperatingCash(float $targetCash): float
    {
        // Hoards cash aggressively to deploy during market crashes
        return $targetCash * 2.0;
    }
}
