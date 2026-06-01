<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Credit Services (Credit Cards, Consumer Finance).
 * 
 * Financial Physics:
 * - Hybrid model relying on interest spreads (like a bank) and transaction volume (like a brokerage).
 * - Revenue directly benefits from inflation because interchange/swipe fees are a percentage of total price.
 * - Highly vulnerable to economic downturns (negative output gap) due to a spike in unsecured loan defaults.
 * - Evaluated on Return on Equity (ROE).
 */
class CreditServicesBusinessModel implements BusinessModelInterface
{
    /**
     * Similar to commercial banks, credit services scale EBIT to cover their
     * required ROE plus their operational interest expenses.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        return (new CommercialBankBusinessModel())->getTargetMetrics($stock, $macroState, $mathUtility);
    }

    /**
     * The defining characteristic of consumer credit: 
     * - Inflation increases nominal purchase volume (more swipe fees).
     * - Economic booms (positive output gap) reduce loan defaults.
     * - Recessions (negative output gap) trigger massive unsecured default write-offs.
     *
     * @param Stock $stock      The credit services stock entity.
     * @param array $macroState The current macroeconomic state.
     * @return float The calculated pricing power modifier.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();

        // They capture inflation directly through interchange percentages
        $inflationCapture = max(0.0, ($inflation - 0.02) * abs($beta));
        
        // Negative output gaps hit them 2x as hard as a normal bank due to unsecured defaults
        $cyclicalImpact = $outputGap < 0 ? ($outputGap * abs($beta) * 2.5) : ($outputGap * abs($beta) * 1.5);

        $macroDrag = $cyclicalImpact + $inflationCapture;
        
        // --- Analyst Consensus Alignment (Yield Curve & APY Pricing) ---
        return $macroDrag - (new CommercialBankBusinessModel())->getAnalystMarginShift($stock, $macroState);
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Higher variance than traditional commercial banks due to fluctuating consumer spending habits
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.40)));
        
        // 1. Dynamic NIM Alignment
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $earningAssets = max($equity, $equity + $totalDebt - $treasury);
        
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        $targetRoa = $baselineRoe / max(1.0, $equityLimit);
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $structuralSpread = (float) $stock->getCreditSpread();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $customerDeposits = (float) $stock->getCustomerDeposits();
        $totalDebt = $wholesaleDebt + $customerDeposits;
        
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        $depositBeta = $mathUtility->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $depositBeta);
        
        $structuralInterestExpense = ($wholesaleDebt * ($policyRate + $structuralSpread)) + ($customerDeposits * $depositRate);
        $yieldCurveSlope = $macroState['ns_slope_ema'] ?? ($macroState['ns_slope'] ?? 0.0);
        $nimModifier = max(0.1, 1.0 + ($yieldCurveSlope * 10.0));
        
        $targetEbt = ($equity * $baselineRoe * $nimModifier) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestIncome = (float) $stock->getCorporateTreasury() * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        $targetEbt = ($earningAssets * $targetRoa * $nimModifier) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestIncome = $treasury * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $structuralInterestExpense - $expectedInterestIncome);
        $requiredVariableCosts = $actualRevenue - $fixedCosts - $requiredEbit;
        $requiredVariableMargin = $requiredVariableCosts / max(1.0, $actualRevenue);
        
        $pricingPower = $this->calculatePricingPowerModifier($stock, $macroState);
        $requiredVariableMargin -= $pricingPower;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        
        $inflationCapture = max(0.0, ($inflation - 0.02) * abs((float) $stock->getBeta()));
        $cyclicalImpact = $outputGap < 0 ? ($outputGap * abs((float) $stock->getBeta()) * 2.5) : ($outputGap * abs((float) $stock->getBeta()) * 1.5);
        $macroDrag = $cyclicalImpact + $inflationCapture;
        $requiredVariableMargin -= $macroDrag;

        // 2. Unsecured Default Shock (Higher severity than Commercial Banks)
        $defaultZ = $mathUtility->generateStandardNormal();
        $lossProvisionShock = $defaultZ < -1.5 ? abs($defaultZ) * 0.20 : ($defaultZ > 1.0 ? -0.03 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $requiredVariableMargin + $lossProvisionShock));
        
        $eventLore = null;
        if ($defaultZ < -2.0) {
            $eventLore = "Took massive provisions for unsecured credit defaults as consumer health deteriorated.";
        } elseif ($defaultZ < -1.5) {
            $eventLore = "Elevated credit card defaults compressed quarterly margins.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        return (new CommercialBankBusinessModel())->calculateInterestIncome($stock, $macroState, $mathUtility);
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        return (new CommercialBankBusinessModel())->updateDynamicRoic($stock, $actualTotalNetIncome, $investedCapital, $ebit, $corporateTaxRate);
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }
}