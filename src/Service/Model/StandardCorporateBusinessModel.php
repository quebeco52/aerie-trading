<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for normal, non-financial companies.
 * 
 * Financial Physics:
 * - Evaluated on Return on Invested Capital (ROIC).
 * - Subject to supply chain inflation and physical depreciation.
 * - Operating scale is based on physical assets, not financial leverage.
 */
class StandardCorporateBusinessModel extends AbstractBusinessModel
{
    /**
     * Physical businesses evaluate their true structural scale based on Invested Capital 
     * (Total Equity + Debt - Cash), requiring physical assets to turn a profit.
     */
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => max(0.01, (float) $stock->getBaselineRoic())
        ];
    }

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();

        return [
            'macro_demand_shift' => $outputGap * $beta,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(0.5, $beta)),
            'operating_leverage_rate' => FinancialConstants::STANDARD_OPERATING_LEVERAGE,
        ];
    }

    /**
     * Idiosyncratic variance is applied directly to sales volume.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $revenueShock = $revenueZ * ($baselineVol * 0.15);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);

        // Supply Chain Inflation Penalty:
        // Physical companies get squeezed by inflation because raw material and labor costs rise 
        // faster than they can safely raise prices on consumers without destroying demand.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $inflationPenalty = $inflation > 0.03 ? ($inflation - 0.03) * abs((float) $stock->getBeta()) * 1.5 : 0.0;

        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin + $inflationPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'ebit' => $ebit,
            'primary_shock_z' => $revenueZ
        ];
    }


    /**
     * Normal physical companies are evaluated on NOPAT / Invested Capital (ROIC).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        // NOPAT (Net Operating Profit After Tax) strips out interest expense to measure 
        // the pure operating efficiency of the physical business assets.
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * 4.0;

        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * 0.25) + ($oldTtm * 0.75);
        $stock->setRoicTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Standard corporates pay market interest rates on ALL of their debt. 
        // They do not get the benefit of cheap customer deposits like banks do.
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;

        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $wholesaleRate];
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, 0.02);
            // The FCF passed from EarningsEngine is Quarterly. We MUST annualize it!
            $annualFcf = $fcfPerShare * 4.0;
            // Cap the DCF so a temporary lack of CapEx doesn't cause an infinite perpetual valuation.
            $dcfFairValue = min(max(0.01, $annualFcf * $multiplier), $peFairValue * 1.5);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue) * 0.75 : max($revenueFloorValue, $peFairValue);
    }
}
