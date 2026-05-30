<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for normal, non-financial companies.
 * 
 * Financial Physics:
 * - Evaluated on Return on Invested Capital (ROIC).
 * - Subject to supply chain inflation and physical depreciation.
 * - Operating scale is based on physical assets, not financial leverage.
 */
class StandardCorporateBusinessModel implements BusinessModelInterface
{
    /**
     * Physical businesses evaluate their true structural scale based on Invested Capital 
     * (Total Equity + Debt - Cash), requiring physical assets to turn a profit.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => max(0.01, (float) $stock->getBaselineRoic())
        ];
    }

    /**
     * Normal physical companies capture pricing power in a boom, but are crushed by supply chain inflation.
     * Supply chain inflation hurts margins regardless of market correlation.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();
        
        $inflationPenalty = $inflation > 0.03 ? ($inflation - 0.03) * abs($beta) * 1.5 : 0.0;
        
        return ($outputGap * $beta * 0.5) - $inflationPenalty;
    }

    /**
     * Idiosyncratic variance is applied directly to sales volume.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $revenueShock = $revenueZ * ($baselineVol * 0.15);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        $actualVariableCosts = $actualRevenue * $realizedVariableMargin;
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }

    /**
     * Normal companies earn standard money-market yields only on excess liquidity 
     * that isn't required to run the day-to-day business.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = $mathUtility->calculateOperatingBase((float) $stock->getTotalRevenue(), $equity);
        
        $excessCash = max(0.0, $cash - ($operatingBase * 0.05));
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        return $excessCash * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
    }

    /**
     * Normal physical companies are evaluated on NOPAT / Invested Capital (ROIC).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;
        $truePostTaxReturn = $investedCapital > 0 ? ($nopatProxy / $investedCapital) : 0.0;
        
        $oldRoic = (float) $stock->getCurrentRoic();
        $smoothedRoic = $oldRoic === 0.0 ? $truePostTaxReturn : $oldRoic + (($truePostTaxReturn - $oldRoic) * 0.50);
        
        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $smoothedRoic)));
        
        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }
}