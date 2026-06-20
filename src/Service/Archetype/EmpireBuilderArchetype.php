<?php

namespace App\Service\Archetype;

class EmpireBuilderArchetype extends AbstractArchetype
{
    public function modifyDebtToleranceLimit(float $limit): float 
    { 
        return $limit * 1.25; 
    }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float 
    { 
        return min(0.95, $prob + 0.25); 
    }
    
    public function modifySaturationPenalty(float $penalty): float 
    { 
        return $penalty * 0.50; 
    }
    
    public function modifyBuybackAggression(float $aggression): float 
    { 
        return $aggression * 0.10; 
    }
    
    public function modifyAcquisitionAggression(float $baseAggression): float 
    { 
        return $baseAggression * 5.0; 
    }
}
