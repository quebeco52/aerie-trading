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

        return $cyclicalImpact + $inflationCapture;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Higher variance than traditional commercial banks due to fluctuating consumer spending habits
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.40)));
        $actualVariableCosts = $actualRevenue * $realizedVariableMargin;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => $revenueZ
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