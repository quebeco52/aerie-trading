<?php

namespace App\Service\Archetype;

abstract class AbstractArchetype implements ArchetypeInterface
{
    public function modifyTargetOperatingCash(float $targetCash): float { return $targetCash; }
    
    public function modifyDebtToleranceLimit(float $limit, float $effectiveCostOfDebt): float 
    { 
        return min($limit, max(0.10, $limit * (1.0 - ($effectiveCostOfDebt * 3.0))));
    }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float { return $prob; }
    
    public function modifySaturationPenalty(float $penalty): float { return $penalty; }
    
    public function modifyTargetPayoutRatio(float $targetPayout): float { return $targetPayout; }
    
    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt): bool { return false; }
    
    public function modifyBuybackAggression(float $aggression): float { return $aggression; }
    
    public function modifyAcquisitionAggression(float $baseAggression): float { return $baseAggression; }
    
    public function modifyFixedCostRatio(float $fixedCostRatio): float { return $fixedCostRatio; }
    
    public function modifyVariableMarginTheta(float $theta): float { return $theta; }
    
    public function modifyIdiosyncraticVol(float $vol): float { return $vol; }
    
    public function modifyCreditSpread(float $spread): float { return $spread; }
    
    public function modifyMAndASynergyRange(float $min, float $max): array { 
        return ['min' => $min, 'max' => $max]; 
    }
}
