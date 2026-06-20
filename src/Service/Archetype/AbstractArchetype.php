<?php

namespace App\Service\Archetype;

abstract class AbstractArchetype implements ArchetypeInterface
{
    public function modifyTargetOperatingCash(float $targetCash): float { return $targetCash; }
    
    public function modifyDebtToleranceLimit(float $limit): float { return $limit; }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float { return $prob; }
    
    public function modifySaturationPenalty(float $penalty): float { return $penalty; }
    
    public function modifyTargetPayoutRatio(float $targetPayout): float { return $targetPayout; }
    
    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt): bool { return false; }
    
    public function modifyBuybackAggression(float $aggression): float { return $aggression; }
    
    public function modifyAcquisitionAggression(float $baseAggression): float { return $baseAggression; }
}
