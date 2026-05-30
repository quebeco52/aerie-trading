<?php

namespace App\Service\EarningsStrategy;

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
class AssetManagementEarningsStrategy implements EarningsStrategyInterface
{
    /**
     * Asset Managers scale EBIT to cover their target ROE and any operational wholesale debt.
     * They do not use fractional customer deposits or float to generate leverage.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $targetEbt = ($equity * max(0.01, (float) $stock->getBaselineRoe())) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestExpense = (float) $stock->getWholesaleDebt() * ($policyRate + (float) $stock->getCreditSpread());
        $requiredEbit = max(0.01 * $equity, $targetEbt + $expectedInterestExpense);

        return [
            'invested_capital' => $equity,
            'baseline_roic' => $equity > 0 ? ($requiredEbit / $equity) : 0.01
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
        
        return ($outputGap * (float) $stock->getBeta() * 1.5);
    }

    /**
     * Idiosyncratic variance is relatively low compared to transactional brokerages.
     * AUM fees are highly recurring and sticky, providing a stable baseline of revenue.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // AUM fees are highly recurring and sticky, making revenue variance significantly lower than trading-heavy brokerages
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.10)));
        $actualVariableCosts = $actualRevenue * $realizedVariableMargin;

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
}