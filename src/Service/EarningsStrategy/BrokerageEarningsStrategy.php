<?php

namespace App\Service\EarningsStrategy;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Brokerages & Capital Markets.
 * 
 * Financial Physics:
 * - Highly leveraged, transaction-driven business model.
 * - Revenue scales off trading volume, investment banking advisory, and margin loans.
 * - Evaluated on Return on Equity (ROE).
 */
class BrokerageEarningsStrategy implements EarningsStrategyInterface
{
    /**
     * Brokerages don't use fractional customer deposits or float. 
     * They scale EBIT directly to cover their wholesale interest expense and target ROE.
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
     * Brokerage revenues (trading, M&A advisory, underwriting) are hyper-sensitive to market conditions.
     * If the broader market is booming (positive output gap), their margins and deal flow explode upward.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        return (($macroState['output_gap_ema'] ?? 0.0) * (float) $stock->getBeta() * 1.5);
    }

    /**
     * Idiosyncratic shock applied to retail trading volume and institutional deal flow.
     * Capital Markets have higher top-line variance compared to sticky Asset Managers.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Brokerage revenues are hyper-sensitive to the VIX (Systemic Market Volatility).
        // High Volatility = Massive trading volume (panic selling or euphoria buying).
        $vix = $macroState['market_volatility'] ?? 0.15;
        
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($vix * 0.50)));
        $actualVariableCosts = $actualRevenue * $realizedVariableMargin;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => $revenueZ
        ];
    }

    /**
     * Brokerages earn standard money-market yields only on excess liquidity that isn't actively deployed.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = $mathUtility->calculateOperatingBase((float) $stock->getTotalRevenue(), $equity);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * 0.05));
        
        return $excessCash * max(0.0, ($macroState['policy_rate_ema'] ?? 0.04) - MacroEngine::CASH_YIELD_SPREAD);
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