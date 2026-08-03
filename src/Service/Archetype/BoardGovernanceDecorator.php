<?php

declare(strict_types=1);

namespace App\Service\Archetype;

class BoardGovernanceDecorator implements ArchetypeInterface
{
    public function __construct(private ArchetypeInterface $baseStrategy) {}

    public function modifyTargetOperatingCash(float $targetCash): float
    {
        return $this->baseStrategy->modifyTargetOperatingCash($targetCash) * 1.5; // Board hoards cash in distress
    }

    public function modifyDebtToleranceLimit(float $limit, float $effectiveCostOfDebt): float
    {
        return $this->baseStrategy->modifyDebtToleranceLimit($limit, $effectiveCostOfDebt) * 0.5; // Board mandates deleveraging
    }

    public function modifyInvestmentProbability(float $prob, float $trueReturn): float
    {
        return $this->baseStrategy->modifyInvestmentProbability($prob, $trueReturn) * 0.1; // Board vetoes capex
    }

    public function modifySaturationPenalty(float $penalty): float
    {
        return $this->baseStrategy->modifySaturationPenalty($penalty);
    }

    public function modifyTargetPayoutRatio(float $targetPayout): float
    {
        return 0.0; // Board slashes dividends to 0
    }

    public function shouldResistDividendCut(bool $isLiquidityCrisis, bool $isRegulatoryDividendHalt, bool $isDeepDistress = false): bool
    {
        return false; // Board forces dividend cut
    }

    public function modifyBuybackAggression(float $aggression): float
    {
        return 0.0; // Board halts buybacks
    }

    public function modifyAcquisitionAggression(float $baseAggression): float
    {
        return 0.0; // Board halts M&A
    }

    public function modifyFixedCostRatio(float $fixedCostRatio): float
    {
        return $this->baseStrategy->modifyFixedCostRatio($fixedCostRatio) * 0.90; // Board mandates 10% cost-cutting (layoffs)
    }

    public function modifyVariableMarginTheta(float $theta): float
    {
        return $this->baseStrategy->modifyVariableMarginTheta($theta);
    }

    public function modifyIdiosyncraticVol(float $vol): float
    {
        return $this->baseStrategy->modifyIdiosyncraticVol($vol) * 0.80; // Board demands conservative operations
    }

    public function modifyCreditSpread(float $spread): float
    {
        return $this->baseStrategy->modifyCreditSpread($spread);
    }

    public function modifyMAndASynergyRange(float $min, float $max): array
    {
        return $this->baseStrategy->modifyMAndASynergyRange($min, $max);
    }
}
