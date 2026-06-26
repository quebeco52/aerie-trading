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
    
    public function modifyFixedCostRatio(float $fixedCostRatio): float 
    { 
        // Lean, conservative corporate structure
        return $fixedCostRatio * 0.90; 
    }
    
    public function modifyCreditSpread(float $spread): float 
    { 
        // Highly trusted by bond markets
        return max(0.0010, $spread * 0.85); 
    }
}
