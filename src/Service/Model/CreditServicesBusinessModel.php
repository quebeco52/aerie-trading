<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Credit Services (Credit Cards, Consumer Finance).
 * 
 * Financial Physics:
 * - Hybrid model relying on interest spreads (like a bank) and transaction volume (like a brokerage).
 * - Revenue directly benefits from inflation because interchange/swipe fees are a percentage of total price.
 * - Highly vulnerable to economic downturns (negative output gap) due to a spike in unsecured loan defaults.
 * - Evaluated on Return on Equity (ROE).
 */
class CreditServicesBusinessModel extends CommercialBankBusinessModel
{
    // --- Dual-Stream Credit Services Architecture ---
    /** Baseline fraction of revenue derived from revolving consumer lending interest. */
    public const LENDING_REVENUE_WEIGHT  = 0.65;
    /** Baseline fraction of revenue derived from payment network interchange / swipe fees. */
    public const NETWORK_REVENUE_WEIGHT  = 0.35;

    // --- ROE & Target Architecture ---
    /** Weight given to historical baseline ROE when blending with TTM ROE. */
    public const BASELINE_ROE_WEIGHT = 0.70;
    /** Weight given to TTM ROE when blending with historical baseline ROE. */
    public const TTM_ROE_WEIGHT      = 0.30;
    /** Default 5Y Treasury spread over policy rate when yield curve data is absent. */
    public const DEFAULT_5Y_YIELD_PREMIUM = 0.005;
    /** Default maximum financial leverage (Debt/Equity) limit if sector configuration is absent. */
    public const DEFAULT_EQUITY_LIMIT     = 10.00;
    /** Minimum lending EBIT floor as a fraction of core debt liabilities. */
    public const MIN_LENDING_EBIT_YIELD   = 0.05;

    // --- High-Yield Deposit Funding ---
    /** Maximum allowable deposit beta clamp for high-yield savings accounts. */
    public const MAX_DEPOSIT_BETA_CLAMP   = 0.90;
    /** Minimum allowable deposit beta clamp for high-yield savings accounts. */
    public const MIN_DEPOSIT_BETA_CLAMP   = 0.30;
    /** Supplemental deposit beta spread added to attract high-yield savings funding. */
    public const HIGH_YIELD_BETA_SPREAD   = 0.20;
    /** Target operating cash reserve ratio applied to corporate operating base. */
    public const TARGET_OPERATING_BUFFER  = 0.05;

    // --- APR & Gross Yield Ceiling ---
    /** Absolute minimum APR gross yield ceiling floor. */
    public const MIN_APR_YIELD_FLOOR      = 0.25;
    /** Spread over macro policy rate used to determine floating APR gross yield ceiling. */
    public const POLICY_APR_SPREAD        = 0.35;

    // --- Revenue & Default Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in transaction swipe markets. */
    public const REVENUE_VARIANCE_SCALAR   = 0.20;
    /** Macroeconomic default scalar translating negative output gaps into unsecured loan defaults. */
    public const MACRO_DEFAULT_SCALAR      = 0.80;
    /** Severe credit z-score threshold triggering elevated unsecured default provisions. */
    public const CREDIT_STRESS_Z_THRESHOLD = -1.50;
    /** Loss provision multiplier applied to credit stress severity. */
    public const LOSS_PROVISION_SCALAR     = 0.08;
    /** Z-score threshold above which benign credit conditions trigger a reserve release. */
    public const HEALTHY_CREDIT_Z_FLOOR    = 1.00;
    /** Cost reduction per z-unit of benign conditions above the release threshold (replaces flat HEALTHY_CREDIT_BONUS). */
    public const PROVISION_REVERSAL_SCALE  = 0.020;
    /** Maximum quarterly reserve release clamp. Credit cycle is lumpier than bank loans: cap at 5%. */
    public const MAX_PROVISION_REVERSAL    = 0.05;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;

    // --- CECL Forward Provisioning (Credit Spread Channel) ---
    /** Baseline investment-grade credit spread (~200bps). Widening above this triggers proactive reserve builds. */
    public const CECL_BASELINE_CREDIT_SPREAD = 0.020;
    /** Variable cost add-on per unit of spread widening. Unsecured credit is 1.5x more sensitive than collateralized bank loans. */
    public const CECL_SPREAD_SENSITIVITY     = 1.50;

    // --- Structural Efficiency Floor ---
    /** Minimum cost-to-revenue ratio: even at perfect NIM, structural fixed costs (personnel, compliance, tech) prevent margin going below 50%. */
    public const MIN_EFFICIENCY_RATIO        = 0.50;

    // --- NIM Squeeze & Yield Curve Inversion ---
    /** Default 10Y Treasury yield fallback when macroeconomic yield curve data is missing. */
    public const DEFAULT_10Y_YIELD_FALLBACK = 0.04;
    /** Default 2Y Treasury yield fallback when macroeconomic yield curve data is missing. */
    public const DEFAULT_2Y_YIELD_FALLBACK  = 0.03;
    /** Baseline spread buffer before NIM squeeze compression begins. */
    public const NIM_SPREAD_BUFFER          = 0.005;
    /** Linear sensitivity scalar for spread compression when yield curve flattens. */
    public const NIM_LINEAR_SENSITIVITY     = 1.50;
    /** Quadratic coefficient amplifying funding costs during yield curve inversions. */
    public const NIM_QUADRATIC_COEFF        = 0.15;

    // --- Event Lore Thresholds ---
    /** Severe credit z-score threshold indicating massive unsecured credit default provisions. */
    public const LORE_MASSIVE_PROVISION_Z   = -2.00;
    /** Severe credit z-score threshold indicating elevated credit card default margin penalties. */
    public const LORE_ELEVATED_DEFAULT_Z    = -1.50;
    /** Benign z-score threshold triggering reserve release event lore. */
    public const LORE_RESERVE_RELEASE_Z     = 2.00;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into credit card default spikes via macro delinquency data. */
    public const ANALYST_BASE_VISIBILITY    = 0.50;
    /** Standard deviation of analyst estimation error for unsecured loss provisions. */
    public const ANALYST_ERROR_STD_DEV      = 0.10;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        // Swipe fees perfectly capture nominal inflation dynamically.
        // We strip generic pricing power to prevent double-dipping.
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        // Resolve company-specific tuned credit services parameters
        $params = $this->resolveModelParameters($stock, [
            'lending_revenue_weight'  => self::LENDING_REVENUE_WEIGHT,
            'network_revenue_weight'  => self::NETWORK_REVENUE_WEIGHT,
            'cecl_spread_sensitivity' => self::CECL_SPREAD_SENSITIVITY,
        ]);

        $lendingWeight   = $params['lending_revenue_weight'];
        $networkWeight   = $params['network_revenue_weight'];
        $ceclSensitivity = $params['cecl_spread_sensitivity'];

        // Independent stream Z-scores
        $lendingZ = $mathUtility->generateStandardNormal(); // Revolving credit loan origination volume
        $swipeZ   = $mathUtility->generateStandardNormal(); // Payment gateway transaction swipe volume
        $defaultZ = $mathUtility->generateStandardNormal(); // Consumer credit default Z-score

        // Inflation Bonus (Interchange Swipe Fees):
        // Swipe fees (Visa/MC network) are a percentage of transaction value — higher prices = higher revenue.
        $inflation = $macroState['inflation_ema'] ?? ($macroState['inflation'] ?? MacroEngine::TARGET_INFLATION);
        $inflationBonus = ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta());

        // Blended dual-stream revenue (Lending vs. Payment Network Interchange)
        $lendingRevenue = $expectedRevenue * $lendingWeight
            * (1.0 + ($lendingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $networkRevenue = $expectedRevenue * $networkWeight
            * (1.0 + ($swipeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $inflationBonus);
        $actualRevenue = max(0.0, $lendingRevenue + $networkRevenue);

        // Unsecured Default Shock:
        // Credit card debt is unsecured. Consumers default on cards long before mortgages during recessions.
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $macroDefaultDrag = $outputGap < 0.0 ? abs($outputGap) * self::MACRO_DEFAULT_SCALAR : 0.0;

        if ($defaultZ < self::CREDIT_STRESS_Z_THRESHOLD) {
            $provisionShock = abs($defaultZ) * self::LOSS_PROVISION_SCALAR;
        } elseif ($defaultZ > self::HEALTHY_CREDIT_Z_FLOOR) {
            // Scaled reserve release: replaces flat HEALTHY_CREDIT_BONUS.
            $provisionShock = -min(self::MAX_PROVISION_REVERSAL, ($defaultZ - self::HEALTHY_CREDIT_Z_FLOOR) * self::PROVISION_REVERSAL_SCALE);
        } else {
            $provisionShock = 0.0;
        }
        $lossProvisionShock = $provisionShock + $macroDefaultDrag;

        // CECL Forward Provisioning (Credit Spread Channel):
        // Unsecured credit companies are far more sensitive to spread widening than banks.
        // Subprime Spread Beta: lenders taking on higher credit spread risk earn a higher spread
        // yield margin in benign credit environments, eliminating low-sensitivity free-money exploits.
        $creditSpread = $macroState['macro_credit_spread_ema'] ?? ($macroState['macro_credit_spread'] ?? self::CECL_BASELINE_CREDIT_SPREAD);
        $spreadBeta = $ceclSensitivity / self::CECL_SPREAD_SENSITIVITY;
        $spreadGap = $creditSpread - self::CECL_BASELINE_CREDIT_SPREAD;
        $ceclDrag = $spreadGap > 0.0
            ? $spreadGap * $ceclSensitivity
            : max(-0.03, $spreadGap * ($spreadBeta - 1.0));

        // Net Interest Margin (NIM) Squeeze (1.5x more sensitive than banks due to wholesale funding dependency)
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? self::DEFAULT_10Y_YIELD_FALLBACK);
        $yield2y  = $macroState['yield_2y_ema']  ?? ($macroState['yield_2y']  ?? self::DEFAULT_2Y_YIELD_FALLBACK);
        $bankSpread = $yield10y - $yield2y;

        if ($bankSpread < 0) {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread)
                + pow(abs($bankSpread) * FinancialConstants::YIELD_CURVE_INVERSION_SENSITIVITY, 2) * self::NIM_QUADRATIC_COEFF;
        } else {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread) * self::NIM_LINEAR_SENSITIVITY;
        }

        // Structural efficiency floor: Total Operating Costs (Fixed + Variable) / Revenue >= MIN_EFFICIENCY_RATIO.
        // Crucially, unsecured default provisions, CECL reserve builds, and NIM squeeze apply proportionally
        // to the Revolving Lending share ($lendingWeight), leaving Payment Network Swipe Interchange completely insulated.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $lendingCostAddon = ($lossProvisionShock + $nimSqueeze + $ceclDrag) * $lendingWeight;
        $rawMargin = $realizedVariableMargin + $lendingCostAddon;
        $clampedMargin = min(self::MAX_VARIABLE_MARGIN_CLAMP, max($minVariableMargin, $rawMargin));
        $actualVariableCosts = $actualRevenue * $clampedMargin;

        $eventLore = null;
        if ($defaultZ < self::LORE_MASSIVE_PROVISION_Z) {
            $eventLore = "Took massive provisions for unsecured credit defaults as consumer health deteriorated.";
        } elseif ($defaultZ < self::LORE_ELEVATED_DEFAULT_Z) {
            $eventLore = "Elevated credit card defaults compressed quarterly margins.";
        } elseif ($defaultZ > self::LORE_RESERVE_RELEASE_Z) {
            $eventLore = "Released loan loss reserves on the back of pristine credit performance, boosting quarterly earnings.";
        }

        // Analyst Visibility:
        // Credit card delinquency rates are publicly reported monthly — analysts see ~50% of the provision shock.
        // Revenue is fully visible (inflation and swipe volume data are public).
        $analystExpectedRevenue = $expectedRevenue * (1.0 + $inflationBonus);
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $expectedLossProvision = $lossProvisionShock * $dynamicVisibility;
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_EFFICIENCY_RATIO, $realizedVariableMargin + $expectedLossProvision));

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z'                 => abs($defaultZ) > abs($lendingZ) ? $defaultZ : $lendingZ,
            'event_lore'                      => $eventLore,
        ];
    }

    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $effectiveEquity = max(1.0, $equity);
        $earningAssets = max($effectiveEquity, $effectiveEquity + $totalDebt - $treasury);
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }

        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + self::DEFAULT_5Y_YIELD_PREMIUM);
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;

        $customerDeposits = (float) $stock->getCustomerDeposits();
        $depositRatio = $totalDebt > 0 ? ($customerDeposits / $totalDebt) : 0.0;

        // Credit services require highly competitive APYs on their high-yield savings accounts
        $depositBeta = min(self::MAX_DEPOSIT_BETA_CLAMP, max(self::MIN_DEPOSIT_BETA_CLAMP, $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits) + self::HIGH_YIELD_BETA_SPREAD));
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        $actualLeverage = $effectiveEquity > 0 ? ($totalDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(1.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalWholesaleDebt = $optimalDebt * (1.0 - $depositRatio);
        $optimalDeposits = $optimalDebt * $depositRatio;
        $optimalInterestExpense = ($optimalWholesaleDebt * $blendedWholesaleRate) + ($optimalDeposits * $depositRate);

        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        $optimalEbit = $optimalEbt + $optimalInterestExpense;
        $structuralAssetYield = $optimalEbit / max(1.0, $effectiveEquity + $optimalDebt);

        $targetEbit = $earningAssets * $structuralAssetYield;

        $coreLiabilities = $totalDebt;
        $minLendingEbit = $coreLiabilities * self::MIN_LENDING_EBIT_YIELD; // Floor is higher than banks due to high-yield credit card loans

        $targetEbit = max($minLendingEbit, $targetEbit);

        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $maxApr = max(self::MIN_APR_YIELD_FLOOR, ($macroState['policy_rate_ema'] ?? 0.04) + self::POLICY_APR_SPREAD);
        $targetRevenue = min($unboundedRevenue, $earningAssets * $maxApr); // Floating gross yield ceiling based on macro policy rate

        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float
    {
        // Credit services generate their interest income from their unsecured loan book.
        $earningAssets = max(1.0, (float) $stock->getTotalEquity() + (float) $stock->getTotalDebt() - (float) $stock->getCorporateTreasury());

        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        // However, this is largely captured in Revenue (Gross Yield). 
        // We only return the supplemental interest from excess treasury cash to avoid double-counting.
        $operatingBase = $this->getOperatingBase($stock);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * self::TARGET_OPERATING_BUFFER));

        return $excessCash * $this->calculateCashYield($macroState);
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $customerDeposits = (float) $stock->getCustomerDeposits(); // High yield savings sweeps
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        $wholesaleInterest = ($wholesaleDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $wholesaleDebt > 0 ? ($wholesaleInterest / $wholesaleDebt) : $currentMarketFixedRate;

        // Credit services must offer highly competitive APYs on their high-yield savings accounts to attract funding
        $depositBeta = min(self::MAX_DEPOSIT_BETA_CLAMP, max(self::MIN_DEPOSIT_BETA_CLAMP, $this->calculateDepositBeta($debt, $totalEquity, $equityLimit, $customerDeposits) + self::HIGH_YIELD_BETA_SPREAD));
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $depositInterest = $customerDeposits * $depositRate;

        return [
            'interest_expense' => $wholesaleInterest + $depositInterest,
            'wholesale_rate' => $wholesaleRate
        ];
    }
}

