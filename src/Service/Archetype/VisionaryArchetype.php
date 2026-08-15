<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class VisionaryArchetype extends AbstractArchetype
{
    public function modifyTargetPayoutRatio(float $targetPayout): float
    {
        // Visionaries hate dividends. They believe they can reinvest capital better than anyone else.
        return 0.0;
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool
    {
        // They will cut the dividend the first chance they get to fund expansion
        return false;
    }
    
    public function modifyInvestmentProbability(float $prob, float $trueReturn): float
    {
        // Enforces massive organic CapEx investment
        return min(0.95, $prob * 3.0);
    }

    public function modifyBuybackAggression(float $aggression): float
    {
        // Prioritizes organic reinvestment over share repurchases
        return $aggression * 0.20;
    }
}
