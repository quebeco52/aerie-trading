<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Shadow Banks (Mortgage Finance, Non-bank lenders).
 * 
 * Financial Physics:
 * - Operates like a bank but without customer deposits.
 * - Funds its entire loan book via Wholesale Debt (Repo Markets, Commercial Paper).
 * - Highly vulnerable to credit market freezes and yield curve inversions.
 * - Evaluated on Return on Equity (ROE).
 */
class ShadowBankBusinessModel extends CommercialBankBusinessModel
{
    // --- ROE & Target Architecture ---
    /** Weight given to historical baseline ROE when blending with TTM ROE. */
    public const BASELINE_ROE_WEIGHT = 0.70;
    /** Weight given to TTM ROE when blending with historical baseline ROE. */
    public const TTM_ROE_WEIGHT      = 0.30;
    /** Default 5Y Treasury spread over policy rate when yield curve data is absent. */
    public const DEFAULT_5Y_YIELD_PREMIUM = 0.005;
    /** Target operating cash reserve ratio applied to corporate operating base. */
    public const TARGET_OPERATING_BUFFER  = 0.05;
    /** Hard ceiling on gross asset yield to prevent reverse-engineered revenue hyperinflation. */
    public const MAX_GROSS_ASSET_YIELD    = 0.50;

    // --- Revenue & Default Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in non-bank lending markets. */
    public const REVENUE_VARIANCE_SCALAR = 0.20;
    /** Macroeconomic default scalar translating negative output gaps into mortgage default losses. */
    public const MACRO_DEFAULT_SCALAR    = 1.20;
    /** Severe credit z-score threshold triggering elevated loan default provisions. */
    public const CREDIT_STRESS_Z_THRESHOLD = -1.50;
    /** Loss provision multiplier applied to credit stress severity. */
    public const LOSS_PROVISION_SCALAR   = 0.12;
    /** Healthy credit environment z-score threshold triggering minor provision write-backs. */
    public const HEALTHY_CREDIT_Z_FLOOR    = 1.00;
    /** Sensitivity scale for loan provision write-backs during exceptionally healthy credit environments. */
    public const PROVISION_REVERSAL_SCALE  = 0.020;
    /** Sensitivity of forward loan default provisioning to widening macroeconomic credit spreads. */
    public const CECL_FORWARD_SENSITIVITY  = 1.50;
    /** Structural minimum operating cost-to-revenue ratio for non-bank lending operations. */
    public const MIN_EFFICIENCY_RATIO      = 0.45;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- NIM Squeeze & Repo Market Freeze ---
    /** Default 30Y Treasury yield fallback when macroeconomic yield curve data is missing. */
    public const DEFAULT_30Y_YIELD_FALLBACK = 0.045;
    /** Target structural spread floor between 30Y mortgage yields and short-term repo funding. */
    public const TARGET_MORTGAGE_SPREAD     = 0.015;
    /** Linear sensitivity scalar for mild spread compression when yield curve flattens. */
    public const NIM_LINEAR_SENSITIVITY     = 1.00;
    /** Multiplier scaling systemic yield curve inversion sensitivity for shadow bank repo funding. */
    public const NIM_INVERSION_SCALAR       = 1.33;
    /** Quadratic coefficient amplifying repo funding freeze costs during extreme yield curve inversions. */
    public const NIM_QUADRATIC_COEFF        = 0.20;

    // --- Event Lore Thresholds ---
    /** Negative credit z-score threshold indicating toxic mortgage-backed security write-downs. */
    public const LORE_TOXIC_WRITE_DOWN_Z = -2.00;
    /** Negative credit z-score threshold indicating elevated default margin penalties. */
    public const LORE_ELEVATED_DEFAULT_Z = -1.50;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into mortgage default spikes via macro housing data. */
    public const ANALYST_BASE_VISIBILITY = 0.50;
    /** Standard deviation of analyst estimation error for mortgage loss provisions. */
    public const ANALYST_ERROR_STD_DEV   = 0.10;

    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $effectiveEquity = max(1.0, $equity);
        $earningAssets = max($effectiveEquity, $effectiveEquity + $wholesaleDebt - $treasury);
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + self::DEFAULT_5Y_YIELD_PREMIUM);
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;

        // Reverse-engineer the optimal EBIT needed to cover massive wholesale debt
        $optimalNetIncome = $equity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / max(0.01, 1.0 - $taxRate);

        $floatingInterestRate = $policyRate + $structuralSpread;
        $optimalInterestExpense = ($wholesaleDebt * (1.0 - $floatingRatio) * (float) $stock->getHistoricalFixedRate())
            + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);

        $operatingBase = $this->getOperatingBase($stock);
        $excessCash = max(0.0, $treasury - ($operatingBase * self::TARGET_OPERATING_BUFFER));
        $expectedTreasuryIncome = $excessCash * $this->calculateCashYield($macroState);

        $optimalEbit = $optimalEbt + $optimalInterestExpense - $expectedTreasuryIncome;
        $targetEbit = max(0.0, $optimalEbit);

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_GROSS_ASSET_YIELD); // Hard cap gross yield at 50%

        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $params = $this->resolveModelParameters($stock, [
            'mortgage_origination_weight' => 0.60,
            'direct_lending_weight'       => 0.40,
        ]);
        $mortgageWeight  = $params['mortgage_origination_weight'];
        $lendingWeight   = $params['direct_lending_weight'];

        $mortgageRevenue = $expectedRevenue * $mortgageWeight * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $lendingRevenue  = $expectedRevenue * $lendingWeight * (1.0 + ($revenueZ * ($baselineVol * 0.8)));
        $actualRevenue   = max(0.0, $mortgageRevenue + $lendingRevenue);

        $creditZ = $mathUtility->generateStandardNormal();

        // CECL Forward Provisioning & Default Shock:
        // Shadow Banks primarily hold highly leveraged mortgages and direct loans.
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $macroDefaultDrag = $outputGap < 0.0 ? abs($outputGap) * self::MACRO_DEFAULT_SCALAR : 0.0;

        $creditSpread = $macroState['macro_credit_spread_ema'] ?? ($macroState['macro_credit_spread'] ?? 0.02);
        $ceclForwardProvision = $creditSpread * self::CECL_FORWARD_SENSITIVITY;

        $lossProvisionShock = ($creditZ < self::CREDIT_STRESS_Z_THRESHOLD
            ? abs($creditZ) * self::LOSS_PROVISION_SCALAR
            : ($creditZ > self::HEALTHY_CREDIT_Z_FLOOR
                ? -($creditZ - self::HEALTHY_CREDIT_Z_FLOOR) * self::PROVISION_REVERSAL_SCALE
                : 0.0)) + $macroDefaultDrag + $ceclForwardProvision;

        // Shadow Bank NIM Squeeze (high VULNERABILITY):
        $yield30y = $macroState['yield_30y_ema'] ?? ($macroState['yield_30y'] ?? self::DEFAULT_30Y_YIELD_FALLBACK);
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $mortgageSpread = $yield30y - $policyRate;

        if ($mortgageSpread < 0) {
            $nimSqueeze = (self::TARGET_MORTGAGE_SPREAD - $mortgageSpread) * self::NIM_LINEAR_SENSITIVITY + pow(abs($mortgageSpread) * (FinancialConstants::YIELD_CURVE_INVERSION_SENSITIVITY * self::NIM_INVERSION_SCALAR), 2) * self::NIM_QUADRATIC_COEFF;
        } else {
            $nimSqueeze = (self::TARGET_MORTGAGE_SPREAD - $mortgageSpread) * self::NIM_LINEAR_SENSITIVITY;
        }

        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = min(self::MAX_VARIABLE_MARGIN_CLAMP, max($minVariableMargin, $realizedVariableMargin + $lossProvisionShock + $nimSqueeze));
        $actualVariableCosts = $actualRevenue * $clampedMargin;

        $eventLore = null;
        if ($creditZ < self::LORE_TOXIC_WRITE_DOWN_Z) {
            $eventLore = "Took massive write-downs on toxic mortgage-backed securities and loan defaults.";
        } elseif ($creditZ < self::LORE_ELEVATED_DEFAULT_Z) {
            $eventLore = "Elevated mortgage defaults negatively impacted quarterly margins.";
        } elseif ($creditZ > 1.80) {
            $eventLore = "Strong loan performance allowed significant reserve releases under CECL accounting models.";
        }

        // Analyst Visibility
        $analystExpectedRevenue = $expectedRevenue;
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $expectedLossProvision = $lossProvisionShock * $dynamicVisibility;
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max($minVariableMargin, $realizedVariableMargin + $expectedLossProvision + $nimSqueeze));

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z'                 => abs($creditZ) > abs($revenueZ) ? $creditZ : $revenueZ,
            'event_lore'                      => $eventLore
        ];
    }
}

