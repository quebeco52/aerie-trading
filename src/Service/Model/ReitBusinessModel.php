<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Real Estate Investment Trusts (REITs).
 * 
 * Financial Physics:
 * - Evaluated on Funds From Operations (FFO) rather than standard Net Income.
 * - Zero entity-level corporate tax (pass-through entity).
 * - Highly stable, recurring revenue from long-term leases.
 * - Target yields (Cap Rates) loosely track the 10-year Treasury yield.
 */
class ReitBusinessModel extends StandardCorporateBusinessModel
{
    /**
     * Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield.
     * As rates rise, property values effectively drop, demanding a higher yield.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $investedCapital = $stock->getInvestedCapital();
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());
        
        // Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield plus a risk premium.
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? 0.04);
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
     * REIT revenues are incredibly stable due to multi-year binding leases.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // REIT revenues are incredibly stable due to multi-year binding leases.
        // Volatility impact is sliced to just 5% of standard variance.
        $revenueShock = $revenueZ * ($baselineVol * 0.05);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // The Tenant Default Shock (Vacancy):
        // While leases are sticky, deep recessions cause anchor tenants to break leases or go bankrupt.
        $tenantDefaultZ = $mathUtility->generateStandardNormal();
        $vacancyShock = $tenantDefaultZ < -1.5 ? abs($tenantDefaultZ) * 0.08 : ($tenantDefaultZ > 1.0 ? -0.01 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin + $vacancyShock));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;
        
        $eventLore = null;
        if ($tenantDefaultZ < -2.0) {
            $eventLore = "Suffered a sudden wave of anchor tenant bankruptcies and commercial lease defaults.";
        } elseif ($tenantDefaultZ < -1.5) {
            $eventLore = "Elevated commercial vacancies and unpaid rent impacted quarterly NOI.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => abs($tenantDefaultZ) > abs($revenueZ) ? $tenantDefaultZ : $revenueZ,
            'event_lore' => $eventLore
        ];
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
        
        // Funds From Operations (FFO) / Net Operating Income (NOI):
        // Because real estate appreciates, GAAP depreciation is an accounting fiction.
        // We add it back to EBIT to calculate the true cash yield (Cap Rate) of the properties.
        $noi = $ebit + $absoluteDepreciation;
        
        $truePostTaxReturn = $investedCapital > 0 ? ($noi / $investedCapital) : 0.0;
        
        $oldRoic = (float) $stock->getCurrentRoic();
        $smoothedRoic = $oldRoic === 0.0 ? $truePostTaxReturn : $oldRoic + (($truePostTaxReturn - $oldRoic) * 0.50);
        
        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $smoothedRoic)));
        
        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        // REITs are pass-through entities and legally pay 0% corporate tax at the entity level.
        return 0.0;
    }
}