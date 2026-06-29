<?php

namespace App\Service\Archetype;

class EmpireBuilderArchetype extends AbstractArchetype
{
    public function modifyDebtToleranceLimit(float $limit, float $effectiveCostOfDebt): float 
    { 
        // Empire builders largely ignore debt costs (1.0 multiplier) and add a huge 30% leverage buffer
        $adjusted = min($limit, max(0.10, $limit * (1.0 - ($effectiveCostOfDebt * 1.0))));
        return $adjusted + 0.3;
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
    
    public function modifyMAndASynergyRange(float $min, float $max): array 
    { 
        // Willing to massively overpay just to get the deal done and build their empire.
        // Shifts synergy range DOWN, ensuring frequent Goodwill write-offs.
        return ['min' => $min - 0.20, 'max' => $max - 0.05]; 
    }
    
    public function modifyFixedCostRatio(float $fixedCostRatio): float 
    { 
        // Bloated corporate structure, too many executives and private jets
        return min(0.85, $fixedCostRatio * 1.20); 
    }
}
