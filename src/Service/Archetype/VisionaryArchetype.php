<?php

namespace App\Service\Archetype;

class VisionaryArchetype extends AbstractArchetype
{
    public function modifyFixedCostRatio(float $fixedCostRatio): float
    {
        // Bloated fixed costs due to massive moonshots, R&D, and premium talent
        return min(0.85, $fixedCostRatio * 1.50);
    }
    
    public function modifyIdiosyncraticVol(float $vol): float
    {
        // Extreme idiosyncratic volatility (high risk, high reward, boom or bust cycles)
        return $vol * 2.0;
    }
    
    public function modifyTargetPayoutRatio(float $targetPayout): float
    {
        // Visionaries hate dividends. They believe they can reinvest capital better than anyone else.
        return 0.0;
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool
    {
        // They will cut the dividend the first chance they get
        return false;
    }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float
    {
        // Enforces massive organic CapEx investment
        return min(0.95, $prob * 3.0);
    }
}
