<?php

namespace App\Service\Archetype;

class CannibalArchetype extends AbstractArchetype
{
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float 
    { 
        return $prob * 0.85; 
    }
    
    public function modifyBuybackAggression(float $aggression): float 
    { 
        return min(1.0, $aggression * 1.50); 
    }
}
