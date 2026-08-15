<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class ConservativeArchetype extends AbstractArchetype
{
    public function modifyTargetOperatingCash(float $targetCash): float 
    { 
        return $targetCash * 1.5; 
    }
    
    public function modifyDebtToleranceLimit(float $limit, float $effectiveCostOfDebt): float 
    { 
        // Conservatives are highly terrified of debt costs (5.0 multiplier) and then take an extra 20% haircut
        $adjusted = min($limit, max(0.10, $limit * (1.0 - ($effectiveCostOfDebt * 5.0))));
        return max(0.1, $adjusted * 0.80); 
    }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float 
    { 
        return $prob * 0.90; 
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool 
    { 
        // Conservative CEOs value predictable income and resist cuts during moderate, temporary downturns.
        // However, if facing deep structural distress or a liquidity crisis, they pragmatically cut.
        return !$isLiquidityCrisis && !$isRegulatoryDividendHalt && !$isDeepDistress; 
    }
}
