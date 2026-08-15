<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class CannibalArchetype extends AbstractArchetype
{
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float 
    { 
        return $prob * 0.85; 
    }

    public function modifyTargetPayoutRatio(float $targetPayout): float 
    { 
        return $targetPayout * 0.70; 
    }
    
    public function modifyBuybackAggression(float $aggression): float 
    { 
        return min(1.0, $aggression * 1.50); 
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool 
    { 
        // Cannibal CEOs favor share buybacks over dividends and never resist cutting payouts.
        return false; 
    }
}
