<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Central Counterparty Clearing Houses (CCP).
 * 
 * Financial Physics:
 * - Revenue scales off transaction volume (benefiting from high VIX / Market Panics).
 * - Holds massive "Initial Margin" deposits from members, earning overnight repo rates.
 * - Carries extreme apocalyptic tail risk: if members default simultaneously, the CCP must cover the trades.
 */
class ClearingHouseBusinessModel extends InsuranceBusinessModel
{
    // --- ROE & Target Architecture ---
    /** Weight given to historical baseline ROE when blending with TTM ROE. */
    public const BASELINE_ROE_WEIGHT = 0.70;
    /** Weight given to TTM ROE when blending with historical baseline ROE. */
    public const TTM_ROE_WEIGHT      = 0.30;
    /** Default 5Y Treasury spread over policy rate when yield curve data is absent. */
    public const DEFAULT_5Y_YIELD_PREMIUM = 0.005;
    /** Minimum structural operating EBIT floor as a fraction of equity. */
    public const MIN_EQUITY_EBIT_YIELD    = 0.05;

    // --- VIX & Transaction Volume Bonus ---
    /** Baseline VIX threshold above which volatility expands clearing transaction volume. */
    public const VIX_BASELINE_THRESHOLD = 0.20;
    /** Sensitivity scalar translating excess VIX points into direct top-line clearing fee bonuses. */
    public const VIX_REVENUE_SCALAR     = 0.40;
    /** Extreme VIX threshold triggering record clearing volume event lore. */
    public const VIX_EXTREME_THRESHOLD  = 0.30;

    // --- Catastrophic Tail Risk & Shocks ---
    /** Volatility multiplier for top-line revenue shocks in clearing fee generation. */
    public const REVENUE_VARIANCE_SCALAR = 0.05;
    /** Severe default z-score threshold triggering initial margin default losses. */
    public const CATASTROPHE_Z_THRESHOLD = -2.50;
    /** Loss multiplier applied to default severity when systemic breaches occur. */
    public const CATASTROPHE_LOSS_SCALAR = 0.25;
    /** Healthy credit environment z-score threshold triggering minor margin write-backs. */
    public const HEALTHY_CREDIT_Z_FLOOR  = 1.00;
    /** Minor variable cost reduction during exceptionally healthy credit environments. */
    public const HEALTHY_CREDIT_BONUS    = -0.02;
    /** Extreme default z-score threshold triggering apocalyptic clearinghouse bailout lore. */
    public const LORE_DEFAULT_Z_THRESHOLD = -3.00;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into systemic clearing defaults prior to quarterly earnings. */
    public const ANALYST_BASE_VISIBILITY = 0.10;
    /** Standard deviation of analyst estimation error for catastrophe losses. */
    public const ANALYST_ERROR_STD_DEV   = 0.05;

    // --- Clearinghouse Liquidity Rules ---
    /** Required cash backing fraction for customer margin liabilities. */
    public const LIABILITY_CASH_BACKING  = 1.00;
    /** Target operating cash reserve ratio applied to corporate operating base. */
    public const TARGET_OPERATING_BUFFER = 0.05;
    /** Minimum emergency operating cash reserve ratio applied to corporate operating base. */
    public const MIN_OPERATING_BUFFER    = 0.02;

    // --- Passive Margin Pool Growth ---
    /** Baseline real GDP growth rate in neutral economic conditions. */
    public const BASE_GDP_GROWTH_RATE    = 0.02;
    /** GDP growth acceleration multiplier during economic expansions. */
    public const EXPANSION_GDP_MULT      = 0.50;
    /** GDP contraction multiplier during recessions. */
    public const RECESSION_GDP_MULT      = 0.30;
    /** Sensitivity scalar translating VIX shifts into customer margin pool expansion/contraction. */
    public const VIX_POOL_GROWTH_SCALAR  = 0.50;
    /** Maximum allowable quarterly expansion or contraction of customer margin pools. */
    public const MAX_POOL_CHANGE_CLAMP   = 0.15;
    /** Standard deviation of random noise applied to quarterly margin pool growth. */
    public const POOL_GROWTH_NOISE_STD   = 0.01;
    /** Threshold percentage change in customer deposits required to trigger margin pool lore. */
    public const LORE_POOL_CHANGE_THRESHOLD = 0.01;

    // --- Monopoly Valuation Moat ---
    /** Operating margin mean reversion speed: slower speed reflects toll-booth monopoly pricing power. */
    public const MONOPOLY_REVERSION_SPEED = 2.0;

    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // Clearinghouses don't use massive wholesale debt for leverage; their leverage is the margin pool.
        $effectiveEquity = max(1.0, $equity);
        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;

        // 1. Calculate Required EBT to hit Target ROE
        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        // 2. Calculate Net Interest Income from the Margin Pool
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $marginPool = (float) $stock->getCustomerDeposits();
        $corporateDebt = (float) $stock->getWholesaleDebt();

        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? $policyRate);
        $earnedYield = max(0.0, $yield2y - MacroEngine::CASH_YIELD_SPREAD);
        $rebateRate = max(0.001, $earnedYield - FinancialConstants::CUSTODY_CLEARING_SPREAD); // Pass back the yield they actually earn, minus spread

        $optimalInterestIncome = ($marginPool + $effectiveEquity + $corporateDebt) * $earnedYield;

        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + self::DEFAULT_5Y_YIELD_PREMIUM);
        $structuralSpread = (float) $stock->getCreditSpread();
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;
        $corporateInterest = $corporateDebt * $blendedWholesaleRate;
        $marginInterest = $marginPool * $rebateRate;

        $optimalInterestExpense = $corporateInterest + $marginInterest;

        // 3. Determine Required Operating EBIT
        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;

        // Clearinghouses must maintain a baseline transaction volume
        $minEbit = $effectiveEquity * self::MIN_EQUITY_EBIT_YIELD;
        $targetEbit = max($minEbit, $optimalEbit);

        // 4. Reverse-engineer Revenue
        $targetRevenue = $targetEbit / $stableMargin; // Removed the arbitrary 2.0x cap which caused systemic under-earning death spirals

        $impliedTurnover = $targetRevenue / $effectiveEquity;

        return [
            'invested_capital' => $effectiveEquity,
            'baseline_roic' => ($impliedTurnover * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        // The Volatility Bonus (Transaction Volume):
        // Clearinghouses thrive on sheer volume. Market panics = massive liquidations = massive fees.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? self::VIX_BASELINE_THRESHOLD);
        $volatilityBonus = max(0.0, ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_REVENUE_SCALAR);

        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $volatilityBonus);

        // The Default Fund Shock (Catastrophic Tail Risk)
        $defaultZ = $mathUtility->generateStandardNormal();

        // Tail risk: if multiple titans default simultaneously, the clearinghouse eats the loss.
        $catastropheShock = $defaultZ < self::CATASTROPHE_Z_THRESHOLD ? abs($defaultZ) * self::CATASTROPHE_LOSS_SCALAR : ($defaultZ > self::HEALTHY_CREDIT_Z_FLOOR ? self::HEALTHY_CREDIT_BONUS : 0.0);

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $catastropheShock));

        $eventLore = null;
        if ($defaultZ < self::LORE_DEFAULT_Z_THRESHOLD) {
            $eventLore = "A massive systemic default breached the initial margin pool, forcing the clearinghouse to cover billions in toxic settlements.";
        } elseif ($vixEma > self::VIX_EXTREME_THRESHOLD) {
            $eventLore = "Record transaction volume driven by market panic generated massive clearing fees.";
        }

        // Analyst Visibility
        // Volatility is fully public. Systemic clearing defaults are partially rumored before earnings (10% visibility).
        $analystExpectedRevenue = $expectedRevenue * (1.0 + $volatilityBonus);
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $expectedCatastropheShock = $catastropheShock * $dynamicVisibility;
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $expectedCatastropheShock));

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'analyst_expected_revenue' => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z' => abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        // Volatility is the primary macro driver for clearinghouses.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? self::VIX_BASELINE_THRESHOLD);
        $volatilityShift = ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_POOL_GROWTH_SCALAR; // High VIX = Higher Demand for clearing

        return [
            'macro_demand_shift' => $volatilityShift,
            'pricing_power_multiplier' => 1.0,
        ];
    }

    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float
    {
        // Clearinghouses earn interest on their entire liquid treasury, which is primarily composed of the margin pool.
        $cash = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        // They do not take equity risk with the margin pool. They park cash in overnight repo and short-duration bonds.
        $repoYield = $this->calculateCashYield($macroState, $policyRate);

        return $cash * $repoYield;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Corporate debt interest
        $corporateInterest = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);

        // Margin Pool Rebate (Customer Deposits)
        // Clearinghouses MUST pay interest back to clearing members on their initial margin, keeping a small spread.
        $marginPool = (float) $stock->getCustomerDeposits();

        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? $policyRate);
        $earnedYield = max(0.0, $yield2y - MacroEngine::CASH_YIELD_SPREAD);
        $rebateRate = max(0.001, $earnedYield - FinancialConstants::CUSTODY_CLEARING_SPREAD); // Pass back the yield they actually earn, minus spread

        $marginInterest = $marginPool * $rebateRate;

        $totalInterestExpense = $corporateInterest + $marginInterest;
        $wholesaleRate = $corporateDebt > 0 ? ($corporateInterest / $corporateDebt) : $currentMarketFixedRate;

        return [
            'interest_expense' => $totalInterestExpense,
            'wholesale_rate' => $wholesaleRate
        ];
    }

    public function calculateCashYield(array &$macroState, float $policyRate): float
    {
        // Clearinghouses cannot take equity risk, but they do park margin in short-duration 
        // government bonds (up to 2 years) to capture slight duration premiums over overnight rates.
        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? $policyRate);

        return max(0.0, $yield2y - MacroEngine::CASH_YIELD_SPREAD);
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // A Clearing House MUST hold 100% of its margin pool in liquid reserves.
        // It cannot use customer margin deposits to execute M&A or pay dividends!
        return ($currentLiability * self::LIABILITY_CASH_BACKING) + ($operatingBase * self::TARGET_OPERATING_BUFFER);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // The absolute minimum floor before emergency borrowing is triggered.
        return ($currentLiability * self::LIABILITY_CASH_BACKING) + ($operatingBase * self::MIN_OPERATING_BUFFER);
    }

    public function processPassiveLiabilityGrowth(Stock $stock, array &$macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits'];
        if ($currentLiabilities <= 0) return;

        // Nominal Systemic Growth: The baseline market grows over time.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $realGdpGrowth = self::BASE_GDP_GROWTH_RATE + ($outputGap > 0.0 ? $outputGap * self::EXPANSION_GDP_MULT : $outputGap * self::RECESSION_GDP_MULT);
        $systemicGrowthQuarterly = ($inflation + $realGdpGrowth) / 4.0;

        // Volatility Driver: When markets get chaotic, clearinghouses demand higher initial margins.
        // If VIX is above 20%, margins expand. If below, margins contract.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? self::VIX_BASELINE_THRESHOLD);
        $volatilityShift = ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_POOL_GROWTH_SCALAR;

        $baseGrowth = $systemicGrowthQuarterly + $volatilityShift;
        $liabilityChange = $currentLiabilities * max(-self::MAX_POOL_CHANGE_CLAMP, min(self::MAX_POOL_CHANGE_CLAMP, $baseGrowth + ($mathUtility->generateStandardNormal() * self::POOL_GROWTH_NOISE_STD)));

        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;

            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $state['treasury'] = 0.0;
                $state['wholesaleDebt'] += $liquidityShortfall;
                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $state['events'][] = ['description' => "Severe liquidity drain forced emergency borrowing of \${$amtB}B.", 'shock' => -5.0];
            }

            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));

            $changePct = $liabilityChange / $currentLiabilities;
            if ($changePct < -self::LORE_POOL_CHANGE_THRESHOLD) {
                $state['events'][] = ['description' => "Margin pool contracted by \$" . number_format(abs($liabilityChange) / 1_000_000_000, 2) . "B.", 'shock' => -1.0];
            } elseif ($changePct > self::LORE_POOL_CHANGE_THRESHOLD) {
                $state['events'][] = ['description' => "Collected \$" . number_format($liabilityChange / 1_000_000_000, 2) . "B in additional Initial Margin.", 'shock' => 0.5];
            }
        }
    }

    public function getMarginReversionSpeed(): float
    {
        return self::MONOPOLY_REVERSION_SPEED; // Toll-booth monopoly moat resists margin compression
    }
}

