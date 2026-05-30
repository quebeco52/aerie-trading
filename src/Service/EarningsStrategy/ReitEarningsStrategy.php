<?php

namespace App\Service\EarningsStrategy;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Real Estate Investment Trusts (REITs).
 * 
 * Financial Physics:
 * - Evaluated on Funds From Operations (FFO) rather than standard Net Income.
 * - Zero entity-level corporate tax (pass-through entity).
 * - Highly stable, recurring revenue from long-term leases.
 * - Target yields (Cap Rates) loosely track the 10-year Treasury yield.
 */
class ReitEarningsStrategy implements EarningsStrategyInterface
{
    /**
     * Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield.
     * As rates rise, property values effectively drop, demanding a higher yield.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $investedCapital = $stock->getInvestedCapital();
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());
        
        $yield10y = $macroState['yield_10y'] ?? 0.04;
        $realEstateRiskPremium = 0.035; // Target a 350 bps spread over the risk-free rate
        $targetCapRate = $yield10y + $realEstateRiskPremium;
        
        // Leases are multi-year, so the structural ROIC moves very slowly towards the target cap rate
        $blendedCapRate = ($baselineRoic * 0.85) + ($targetCapRate * 0.15);
        
        $stock->setBaselineRoic((string) max(0.01, $blendedCapRate));
        
        return [
            'invested_capital' => $investedCapital,
            'baseline_roic' => max(0.01, $blendedCapRate)
        ];
    }

    /**
     * REITs are highly insulated against inflation.
     * Their long-term leases typically have built-in contractual inflation escalators.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        
        // Strong ability to capture and pass on inflation
        $inflationCapture = $inflation * 0.80; 
        
        // Occupancy rates drop slightly in deep recessions, mildly hurting pricing power
        $occupancyHit = $outputGap < 0.0 ? ($outputGap * 0.5) : ($outputGap * 0.1);
        
        return $inflationCapture + $occupancyHit;
    }

    /**
     * REIT revenues are incredibly stable due to multi-year binding leases.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Volatility impact is sliced to just 5% of standard variance.
        $revenueShock = $revenueZ * ($baselineVol * 0.05);
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
     * Wall Street evaluates REITs on FFO (Funds From Operations), not GAAP Net Income.
     * FFO = Net Income + Depreciation (since real estate generally appreciates, depreciation is an accounting fiction).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? 0.05);
        
        $absoluteDepreciation = $investedCapital * $depreciationRate;
        
        // For Real Estate, the equivalent of ROIC is the Cap Rate (Net Operating Income / Property Value).
        // NOI is essentially EBITDA, since REITs pay no corporate tax.
        $noi = $ebit + $absoluteDepreciation;
        
        $truePostTaxReturn = $investedCapital > 0 ? ($noi / $investedCapital) : 0.0;
        
        $oldRoic = (float) $stock->getCurrentRoic();
        $smoothedRoic = $oldRoic === 0.0 ? $truePostTaxReturn : $oldRoic + (($truePostTaxReturn - $oldRoic) * 0.50);
        
        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $smoothedRoic)));
        
        return $truePostTaxReturn;
    }
}