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
    
    public function modifyVariableMarginTheta(float $theta): float 
    { 
        // Underinvests in operations, causing structural margins to decay over time
        return $theta * 0.90; 
    }
    
    public function modifyCreditSpread(float $spread): float 
    { 
        // Bond market penalizes financial engineering
        return $spread * 1.10; 
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool 
    { 
        // Cannibal CEOs favor share buybacks over dividends and never resist cutting payouts.
        return false; 
    }
}
