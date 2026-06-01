<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Asset Managers.
 * 
 * Financial Physics:
 * - Asset light, high margin business model.
 * - Revenue scales off highly sticky, recurring Assets Under Management (AUM) fees.
 * - Evaluated on Return on Equity (ROE).
 */
class AssetManagementBusinessModel implements BusinessModelInterface
{
    /**
     * Asset Managers scale EBIT to cover their target ROE and any operational wholesale debt.
     * They do not use fractional customer deposits or float to generate leverage.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        return [
            'invested_capital' => $equity,
            'baseline_roic' => $baselineRoe * 1.25 // Stable top-line proxy for Asset Managers
        ];
    }

    /**
     * Asset Managers earn fees based on total Assets Under Management (AUM). 
     * When the broader market booms (positive output gap), their AUM inflates, 
     * naturally scaling their revenue and margins without needing to explicitly raise prices.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $macroDrag = ($outputGap * (float) $stock->getBeta() * 1.5);
        
        return $macroDrag - $this->getAnalystMarginShift($stock, $macroState);
    }

    /**
     * Helper method to align Wall Street analysts with the CFO's dynamic margins.
     */
    public function getAnalystMarginShift(Stock $stock, array $macroState): float
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $targetEbt = ($equity * $baselineRoe) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestExpense = (float) $stock->getWholesaleDebt() * ($policyRate + (float) $stock->getCreditSpread());
        $expectedInterestIncome = $this->calculateInterestIncome($stock, $macroState, new MathUtility());
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $expectedInterestExpense - $expectedInterestIncome);
        $structuralEbit = ($baselineRoe * 1.25) * $equity; 
        
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $expectedRevenue = $equity * (($baselineRoe * 1.25) / $stableMargin) * (1.0 + (($macroState['output_gap_ema'] ?? 0.0) * (float)$stock->getBeta() * 1.5));
        
        return $expectedRevenue > 0 ? (($structuralEbit / $expectedRevenue) - ($requiredEbit / $expectedRevenue)) : 0.0;
    }

    /**
     * Idiosyncratic variance is relatively low compared to transactional brokerages.
     * AUM fees are highly recurring and sticky, providing a stable baseline of revenue.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // AUM fees are highly recurring and sticky, making revenue variance significantly lower than trading-heavy brokerages
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.10)));
        
        // Dynamic ROE Margin Alignment
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $targetEbt = ($equity * $baselineRoe) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestExpense = (float) $stock->getWholesaleDebt() * ($policyRate + (float) $stock->getCreditSpread());
        $expectedInterestIncome = $this->calculateInterestIncome($stock, $macroState, $mathUtility);
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $expectedInterestExpense - $expectedInterestIncome);
        $requiredVariableCosts = $actualRevenue - $fixedCosts - $requiredEbit;
        $requiredVariableMargin = $requiredVariableCosts / max(1.0, $actualRevenue);
        
        $pricingPower = $this->calculatePricingPowerModifier($stock, $macroState);
        $requiredVariableMargin -= $pricingPower;
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $requiredVariableMargin));

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => $revenueZ
        ];
    }

    /**
     * Asset Managers earn standard money-market yields only on excess liquidity 
     * that isn't actively deployed or required for daily operations.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = $mathUtility->calculateOperatingBase((float) $stock->getTotalRevenue(), $equity);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * 0.05));
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        return $excessCash * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
    }

    /**
     * Financial companies are evaluated strictly on Return on Equity (ROE), not ROIC.
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) : 0.0;
        
        $oldRoe = (float) $stock->getCurrentRoe();
        $smoothedRoe = $oldRoe === 0.0 ? $truePostTaxReturn : $oldRoe + (($truePostTaxReturn - $oldRoe) * 0.50);
        
        $stock->setCurrentRoe((string) max(-0.50, min(1.0, $smoothedRoe)));
        
        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }
}