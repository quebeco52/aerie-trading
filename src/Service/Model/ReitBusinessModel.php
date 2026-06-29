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
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $investedCapital = $stock->getInvestedCapital();
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        // Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield plus a risk premium.
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? 0.04);
        $realEstateRiskPremium = $macroState['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;
        $targetCapRate = $yield10y + $realEstateRiskPremium;

        // Baseline DNA change over time, but at a realistic physical rate.
        // Commercial leases (Office, Healthcare) are typically 7 to 10 years long.
        // This means a REIT only turns over about 2.5% of its portfolio per quarter.
        // If they do a bad M&A, they will be punished for YEARS before leases expire and reset to market rates!
        $portfolioTurnoverRate = 0.025;
        $blendedCapRate = ($baselineRoic * (1.0 - $portfolioTurnoverRate)) + ($targetCapRate * $portfolioTurnoverRate);
        $stock->setBaselineRoic((string) max(0.01, $blendedCapRate));

        // Cap Rate represents NOI (Net Operating Income) yield.
        // However, the EarningsEngine targets EBIT (Earnings Before Interest & Taxes).
        // Since EBIT = NOI - Depreciation, we MUST subtract depreciation from the target 
        // to prevent REITs from mathematically double-counting depreciation and printing infinite FFO.
        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? 0.05);

        $targetEbitYield = max(0.01, $blendedCapRate - $depreciationRate);

        $ttmRoic = (float) $stock->getRoicTtm();
        if ($ttmRoic !== 0.0) {
            $targetEbitYield = ($targetEbitYield * 0.70) + ($ttmRoic * 0.30);
        }

        return [
            'invested_capital' => $investedCapital,
            'baseline_roic' => $targetEbitYield
        ];
    }

    /**
     * REIT revenues are incredibly stable due to multi-year binding leases.
     */
    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        // CPI Rent Escalators perfectly capture inflation dynamically.
        // We strip generic pricing power to prevent double-dipping.
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        // REIT revenues are incredibly stable due to multi-year binding leases.
        // Volatility impact is sliced to just 5% of standard variance.
        $revenueShock = $revenueZ * ($baselineVol * 0.05);

        // The Inflation Hedge (CPI Rent Escalators):
        // Commercial real estate leases almost universally contain automatic annual rent increases tied to inflation.
        // Rent Escalators only provide an Earnings Surprise if inflation spikes above the expected baseline.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $excessInflation = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $rentEscalator = $excessInflation * 0.80; // Capture 80% of excess inflation directly into top-line revenue

        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock + $rentEscalator);

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
        $quarterlyDepreciation = $absoluteDepreciation / 4.0;

        // Funds From Operations (FFO) / Net Operating Income (NOI):
        // Because real estate appreciates, GAAP depreciation is an accounting fiction.
        // We add it back to EBIT to calculate the true cash yield (Cap Rate) of the properties.
        $noi = $ebit + $quarterlyDepreciation;

        $truePostTaxReturn = $investedCapital > 0 ? ($noi / $investedCapital) * 4.0 : 0.0;

        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * 0.25) + ($oldTtm * 0.75);
        $stock->setRoicTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        // REITs are pass-through entities and legally pay 0% corporate tax at the entity level.
        return 0.0;
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
    {
        // REITs evaluate their interest coverage on Funds From Operations (FFO) / NOI, not EBIT.
        // Because real estate appreciates, GAAP depreciation is an accounting fiction that artificially 
        // reduces EBIT. Adding it back reveals the true cash flow available to cover debt.
        $ffo = $ebit + $depreciation;
        return $interestExpense > 0 ? ($ffo / $interestExpense) : ($ffo > 0 ? 999.0 : -999.0);
    }

    /**
     * REITs pay out the vast majority of their income as dividends, leaving little retained earnings.
     * To grow their portfolio, they MUST aggressively issue debt to finance new property acquisitions.
     */
    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        return [
            'probability' => 0.80 + ($spreadMultiplier * 0.20), // Constantly hunting for property acquisitions
            'aggressiveness' => 0.15 + (0.35 * $spreadMultiplier) // High leverage tolerance for commercial real estate
        ];
    }

    /**
     * REITs trade heavily on their Net Asset Value (NAV) / Book Value.
     */
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float
    {
        return ($earningsValue * 0.60) + ($pbFairValue * 0.40);
    }
}
