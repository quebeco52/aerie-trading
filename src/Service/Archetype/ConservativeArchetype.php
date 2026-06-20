<?php

namespace App\Service\Archetype;

class ConservativeArchetype extends AbstractArchetype
{
    public function modifyTargetOperatingCash(float $targetCash): float 
    { 
        return $targetCash * 1.5; 
    }
    
    public function modifyDebtToleranceLimit(float $limit): float 
    { 
        return $limit * 0.80; 
    }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float 
    { 
        return $prob * 0.90; 
    }
}
