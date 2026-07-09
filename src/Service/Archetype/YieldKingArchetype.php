<?php

namespace App\Service\Archetype;

class YieldKingArchetype extends AbstractArchetype
{
    public function modifyTargetPayoutRatio(float $targetPayout): float 
    { 
        return min(0.90, $targetPayout * 1.20); 
    }
    
    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool 
    { 
        return !$isLiquidityCrisis && !$isRegulatoryDividendHalt;
    }
    
    public function modifyBuybackAggression(float $aggression): float 
    { 
        return $aggression * 0.50; 
    }
}
