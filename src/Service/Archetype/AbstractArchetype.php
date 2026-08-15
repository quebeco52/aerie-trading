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
    
    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool { return false; }
    
    public function modifyBuybackAggression(float $aggression): float { return $aggression; }
    
    public function modifyAcquisitionAggression(float $baseAggression): float { return $baseAggression; }
    
    public function modifyMAndASynergyRange(float $min, float $max): array { 
        return ['min' => $min, 'max' => $max]; 
    }
}
