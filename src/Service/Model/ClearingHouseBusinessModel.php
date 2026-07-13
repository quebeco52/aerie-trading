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
class ClearingHouseBusinessModel extends AbstractBusinessModel
{
    // --- Fee Revenue Floor ---
    /** Minimum structural EBIT floor as a fraction of equity. Prevents degenerate zero-revenue states. */
    public const MIN_EQUITY_EBIT_YIELD = 0.05;

    /** Net custody interest spread (15 bps) earned on member initial margin deposits. */
    public const MARGIN_POOL_CUSTODY_SPREAD = 0.0015;

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

    // --- Buybacks & Capital Deployment ---
    /** Fraction of excess cash allocated to buybacks for mega-hoarder insurers. */
    public const MEGA_BUYBACK_CASH_SHARE  = 0.30;
    /** Fraction of excess cash allocated to buybacks for standard insurers. */
    public const STANDARD_BUYBACK_SHARE   = 0.15;
    /** Maximum buyback spend multiplier relative to quarterly retained earnings. */
    public const MAX_RETAINED_BUYBACK_MULT = 0.90;

    // --- Monopoly Valuation Moat ---
    /** Operating margin mean reversion speed: slower speed reflects toll-booth monopoly pricing power. */
    public const MONOPOLY_REVERSION_SPEED = 2.0;
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $effectiveEquity = max(1.0, $equity);
        $marginPool = (float) $stock->getCustomerDeposits();

        // Earning assets represent physical capital deployed into clearing operations and margin pool custody
        $earningAssets = max($effectiveEquity, $effectiveEquity + $marginPool);

        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * 0.70) + ($ttmRoe * 0.30);
        }

        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // 1. Target Net Income and EBT required to achieve ROE
        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        // 2. Non-operating corporate treasury interest and debt expense
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $ownCashIncome = $this->calculateInterestIncome($stock, $macroState, $mathUtility);

        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);
        $structuralSpread = (float) $stock->getCreditSpread();
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;
        $optimalInterestExpense = $corporateDebt * $blendedWholesaleRate;

        // 3. Operating EBIT required from core clearing and custody operations
        $optimalEbit = $optimalEbt + $optimalInterestExpense - $ownCashIncome;
        $minEbit = $effectiveEquity * self::MIN_EQUITY_EBIT_YIELD;
        $targetEbit = max($minEbit, $optimalEbit);

        // 4. Derive total structural operating revenue (clearing fees + custody spread)
        $targetRevenue = $targetEbit / $stableMargin;
        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic'    => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $params = $this->resolveModelParameters($stock, [
            'clearing_fee_weight' => 0.65,
            'custody_data_weight' => 0.35,
        ]);
        $clearingWeight = $params['clearing_fee_weight'];
        $custodyWeight  = $params['custody_data_weight'];

        // The Volatility Bonus (Transaction Volume):
        // Clearinghouses thrive on sheer volume. Market panics = massive liquidations = massive fees.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? self::VIX_BASELINE_THRESHOLD);
        $volatilityBonus = max(0.0, ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_REVENUE_SCALAR);

        $clearingRevenue = $expectedRevenue * $clearingWeight * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $volatilityBonus);
        $custodyRevenue  = $expectedRevenue * $custodyWeight * (1.0 + ($revenueZ * ($baselineVol * 0.3)));
        $actualRevenue   = max(0.0, $clearingRevenue + $custodyRevenue);

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
        // Non-operating interest income is earned on surplus corporate cash ($ownCash).
        // Additionally, the clearinghouse earns a reliable 15 bps custody net spread on member initial margin deposits ($marginPool).
        $cash = (float) $stock->getCorporateTreasury();
        $marginPool = (float) $stock->getCustomerDeposits();
        $ownCash = max(0.0, $cash - $marginPool);

        $ownCashYield = $ownCash * $this->calculateCashYield($macroState);
        $marginPoolYield = $marginPool * self::MARGIN_POOL_CUSTODY_SPREAD;

        return $ownCashYield + $marginPoolYield;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Corporate debt interest
        $corporateInterest = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $corporateDebt > 0 ? ($corporateInterest / $corporateDebt) : $currentMarketFixedRate;

        // Note: Margin pool custody rebates are pass-through distributions netted against custody yield in calculateInterestIncome.
        // Returning only corporate debt interest ensures ICR and solvency metrics measure true corporate debt servicing capacity.
        return [
            'interest_expense' => $corporateInterest,
            'wholesale_rate' => $wholesaleRate
        ];
    }

    public function calculateCashYield(array &$macroState): float
    {
        // Clearinghouses cannot take equity risk, but they do park margin in short-duration 
        // government bonds (up to 2 years) to capture slight duration premiums over overnight rates.
        $policyRate = $macroState['policy_rate_ema'] ?? ($macroState['policy_rate'] ?? 0.04);
        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? $policyRate);

        return max(0.0, $yield2y - MacroEngine::CASH_YIELD_SPREAD);
    }

    /**
     * Clearinghouses do not deploy physical CapEx. Clearing capacity is governed by Clearing Equity
     * and margin pool collateral is held in Corporate Treasury reserves, so organic capex spend is 0.0.
     */
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return 0.0;
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
        if ($currentLiabilities <= 0.0) {
            return;
        }

        // 1. Annualized Systemic Growth quarterized
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $realGdpGrowth = self::BASE_GDP_GROWTH_RATE + ($outputGap > 0.0 ? $outputGap * self::EXPANSION_GDP_MULT : $outputGap * self::RECESSION_GDP_MULT);
        $systemicGrowthQuarterly = ($inflation + $realGdpGrowth) / 4.0;

        // 2. Volatility Elasticity (Quarterized so calm markets don't cause annual double-digit drain)
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? self::VIX_BASELINE_THRESHOLD);
        $volatilityShiftQuarterly = (($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_POOL_GROWTH_SCALAR) / 4.0;

        $baseGrowth = $systemicGrowthQuarterly + $volatilityShiftQuarterly;
        $noise = $mathUtility->generateStandardNormal() * self::POOL_GROWTH_NOISE_STD;
        $growthRate = max(-self::MAX_POOL_CHANGE_CLAMP, min(self::MAX_POOL_CHANGE_CLAMP, $baseGrowth + $noise));

        $liabilityChange = $currentLiabilities * $growthRate;

        if (abs($liabilityChange) > 0.0) {
            // Segregated margin accounting: outflows cannot exceed available deposits
            if ($liabilityChange < 0.0 && abs($liabilityChange) > $currentLiabilities) {
                $liabilityChange = -$currentLiabilities;
            }

            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;

            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));

            // Only generate lore for significant margin pool movements (>5% quarterly shift)
            $changePct = $liabilityChange / $currentLiabilities;
            if ($changePct < -self::LORE_POOL_CHANGE_THRESHOLD) {
                $amtB = number_format(abs($liabilityChange) / 1_000_000_000, 2);
                $state['events'][] = [
                    'description' => "Initial margin pool contracted by \${$amtB}B amid declining market volatility.",
                    'shock' => -0.5
                ];
            } elseif ($changePct > self::LORE_POOL_CHANGE_THRESHOLD) {
                $amtB = number_format($liabilityChange / 1_000_000_000, 2);
                $state['events'][] = [
                    'description' => "Collected \${$amtB}B in additional Initial Margin deposits due to elevated market volatility.",
                    'shock' => 0.5
                ];
            }
        }
    }

    public function getMarginReversionSpeed(): float
    {
        return self::MONOPOLY_REVERSION_SPEED; // Toll-booth monopoly moat resists margin compression
    }

    public function isUnderLeveraged(bool $isFinancial, float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // For a Central Counterparty Clearing House (CCP), Customer Deposits represent member initial margin collateral.
        // These deposits scale exogenously with clearing member trading volume and open interest rather than discretionary
        // balance sheet recapitalization. A clearinghouse should never trigger "underleveraged" buyback/debt spirals
        // just because its customer margin pool ratio fluctuates.
        return false;
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        return max($revenueFloorValue, $peFairValue);
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * self::MEGA_BUYBACK_CASH_SHARE : min($excessCash * self::STANDARD_BUYBACK_SHARE, $retainedEarningsThisQuarter * self::MAX_RETAINED_BUYBACK_MULT);
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            // A clearinghouse holds massive member margin liabilities ($totalDebt). Comparing excess cash against operatingBase
            // falsely triggers hoarder buyback flags. We evaluate hoarding status relative to total liabilities.
            'is_hoarder'      => $excessCash > ($totalDebt * 0.25),
            'is_mega_hoarder' => $excessCash > ($totalDebt * 0.40),
        ];
    }
}
