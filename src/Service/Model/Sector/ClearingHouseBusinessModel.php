<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Central Counterparty Clearing Houses (CCP).
 * 
 * Financial Physics:
 * - Revenue scales off transaction volume (benefiting from high VIX / Market Panics).
 * - Holds segregated Initial Margin deposits from members, earning net custody spreads.
 * - Unlike insurers, margin inflows/outflows are pass-through custody movements and do not impact equity.
 * - Carries extreme tail risk governed by a statutory Default Waterfall: routine member defaults are absorbed
 *   by member collateral/guaranty funds ($0 loss to CCP), while systemic defaults pierce Skin-in-the-Game (SITG) capital.
 */
class ClearingHouseBusinessModel extends BaseFinancialBusinessModel
{
        public function getMinIcr(): float { return 1.05; }
    public function getBankruptEquityThreshold(): float { return 0.5; }
    public function getDistressEquityThreshold(): float { return 1.25; }
    public function getWarningEquityThreshold(): float { return 2.5; }
    public function getWholesaleLeverageLimit(): float { return 0.0; }
    public function getDividendCrisisIcr(): float { return 1.05; }
    public function getBuybackMinIcr(): float { return 1.15; }
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.03; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.0; }
    public function getCapExCompletionRate(Stock $stock): float { return 1.0; }

    // --- Fee Revenue Floor ---
    /** Minimum structural EBIT floor as a fraction of equity. Prevents degenerate zero-revenue states. */
    public const MIN_EQUITY_EBIT_YIELD = 0.05;

    /** Net custody interest spread (15 bps) earned on member initial margin deposits. */
    public const MARGIN_POOL_CUSTODY_SPREAD = 0.0015;
    /** Share of prevailing money-market yield retained by clearinghouse on member margin float (15%). */
    public const MARGIN_POOL_YIELD_RETENTION_SHARE = 0.15;

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
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Clearing Pool & Margin Physics ---
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
    public const MAX_RETAINED_BUYBACK_MULT = 0.70;

    // --- Monopoly Valuation Moat ---
    /** Operating margin mean reversion speed: slower speed reflects toll-booth monopoly pricing power. */
    public const MONOPOLY_REVERSION_SPEED = 2.0;
    /**
     * Calculates the effective annual custody spread rate earned on member initial margin deposits.
     * Includes base custody fee (15 bps) + dynamic retention share of short-term policy yields.
     */
    public function calculateEffectiveCustodySpread(\App\DTO\MacroStateDTO $macroState): float
    {
        $cashYield = $this->calculateCashYield($macroState);
        $policyRate = $macroState->policyRateEma;
        $retentionMultiplier = min(1.0, max(0.0, ($policyRate - 0.01) / 0.03));
        $dynamicRetentionShare = self::MARGIN_POOL_YIELD_RETENTION_SHARE * $retentionMultiplier;

        return self::MARGIN_POOL_CUSTODY_SPREAD + ($cashYield * $dynamicRetentionShare);
    }

    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $effectiveEquity = max(1.0, $equity);
        $marginPool = (float) $stock->getCustomerDeposits();

        // Earning assets for a clearinghouse is its own corporate equity base (skin-in-the-game capital).
        // Custody margin pools do NOT count as corporate invested capital, they are pass-through liabilities.
        $earningAssets = $effectiveEquity;

        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * 0.70) + ($ttmRoe * 0.30);
        }

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, $effectiveEquity, $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $baselineRoe = max($waccBase, $baselineRoe - $saturationPenalty);

        $taxRate = $macroState->corporateTaxRate;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // 1. Target Net Income and EBT required to achieve ROE
        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        // 2. Non-operating corporate treasury interest and corporate debt expense
        $policyRate = $macroState->policyRateEma;
        $ownCashIncome = $this->calculateInterestIncome($stock, $macroState, $mathUtility);

        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $yield5y = $macroState->yield5yEma;
        $structuralSpread = (float) $stock->getCreditSpread();
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;
        $optimalInterestExpense = $corporateDebt * $blendedWholesaleRate;

        // 3. Total Operating EBIT required from core clearing, data, and custody operations
        $optimalTotalEbit = $optimalEbt + $optimalInterestExpense - $ownCashIncome;
        $minEbit = $effectiveEquity * self::MIN_EQUITY_EBIT_YIELD;
        $targetTotalEbit = max($minEbit, $optimalTotalEbit);

        // 4. Target Total Operating Revenue (including custody float)
        $targetTotalRevenue = $targetTotalEbit / $stableMargin;
        $grossYield = $targetTotalRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic'    => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        $params = $this->resolveModelParameters($stock, [
            ModelParam::ClearingFeeWeight->value      => 0.50,
            ModelParam::CustodyFloatWeight->value     => 0.30,
            ModelParam::DataSubscriptionWeight->value => 0.20,
        ]);

        $targetWeights = [
            'clearing_fees'  => $params[ModelParam::ClearingFeeWeight],
            'custody_float'  => $params[ModelParam::CustodyFloatWeight],
            'data_licensing' => $params[ModelParam::DataSubscriptionWeight],
        ];

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $clearingWeight = $activeWeights['clearing_fees'];
        $custodyWeight  = $activeWeights['custody_float'];
        $dataWeight     = $activeWeights['data_licensing'];

        $revenueZ = $streams->generateZ('clearing_fees', 0.25);
        $custodyZ = $streams->generateZ('custody_float', 0.20);
        $dataZ    = $streams->generateZ('data_licensing', 0.45); // Separate Z-score for sticky data subscriptions

        // The Volatility Bonus (Transaction Volume):
        // Clearinghouses thrive on sheer volume. Market panics = massive liquidations = massive fees.
        $vixEma = $macroState->marketVolatilityEma;
        $volatilityBonus = max(0.0, ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_REVENUE_SCALAR);

        // Interest Rate Volatility Bonus. If the yield curve is violently steepening or inverting, IRS clearing volumes spike.
        $yieldCurveSlope = abs($macroState->yield10yEma - $macroState->yield2yEma);
        $ratesVolBonus = $yieldCurveSlope > 0.005 ? ($yieldCurveSlope - 0.005) * 2.0 : 0.0;

        $totalMacroBonus = $volatilityBonus + $ratesVolBonus;

        // Interest Rate Shift on Custody Float:
        $policyRateEma = $macroState->policyRateEma;
        $rateShift = ($policyRateEma - 0.02) * 2.0;

        // 1. Clearing Revenue (Highly cyclical, gets the Vol and Rates bonus)
        $clearingRevenue = max(0.0, $expectedRevenue * $clearingWeight * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $totalMacroBonus));
        // 2. Custody Revenue (Tied to policy rates and float)
        $custodyRevenue  = max(0.0, $expectedRevenue * $custodyWeight * (1.0 + ($custodyZ * ($baselineVol * 0.20)) + $rateShift));
        // 3. Data & Analytics Revenue (Highly sticky SaaS revenue, immune to trading panics)
        $dataRevenue     = max(0.0, $expectedRevenue * $dataWeight * (1.0 + ($dataZ * ($baselineVol * 0.05))));

        $streamRevenues = [
            'clearing_fees'  => $clearingRevenue,
            'custody_float'  => $custodyRevenue,
            'data_licensing' => $dataRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // The CCP Default Waterfall (Catastrophic Tail Risk)
        $defaultZ = $streams->generateZ('default', 0.05);

        // Under the Default Waterfall, routine member defaults ($defaultZ >= CATASTROPHE_Z_THRESHOLD) are fully absorbed
        // by the defaulting member's posted Initial Margin and Guaranty Fund contribution ($0 loss to CCP equity).
        // Only a severe systemic failure pierces the waterfall to hit the CCP's Skin-in-the-Game (SITG) capital tranche.
        $catastropheShock = $defaultZ < self::CATASTROPHE_Z_THRESHOLD
            ? abs($defaultZ - self::CATASTROPHE_Z_THRESHOLD) * self::CATASTROPHE_LOSS_SCALAR
            : ($defaultZ > self::HEALTHY_CREDIT_Z_FLOOR ? self::HEALTHY_CREDIT_BONUS : 0.0);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $catastropheShock);

        $eventType = null;
        if ($defaultZ < self::LORE_DEFAULT_Z_THRESHOLD) {
            $eventType = ShockEvent::CLEARING_SYSTEMIC_DEFAULT;
        } elseif ($vixEma > self::VIX_EXTREME_THRESHOLD) {
            $eventType = ShockEvent::VOLATILITY_SURGE;
        }

        $primaryShockZ = $streams->resolveDominantShockZ([$defaultZ, $revenueZ]);
        $observableShockZ = 0.0;

        $result = new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );

        return $result;
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        // Volatility is the primary macro driver for clearinghouses.
        $vixEma = $macroState->marketVolatilityEma;
        $volatilityShift = ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_POOL_GROWTH_SCALAR; // High VIX = Higher Demand for clearing

        return [
            'macro_demand_shift' => $volatilityShift,
            'pricing_power_multiplier' => 1.0,
        ];
    }

    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        // Non-operating interest income is earned ONLY on surplus corporate cash ($ownCash).
        // Margin pool custody spread is an operating revenue stream included in calculateSectorPhysics.
        $cash = (float) $stock->getCorporateTreasury();
        $marginPool = (float) $stock->getCustomerDeposits();
        $ownCash = max(0.0, $cash - $marginPool);

        $cashYield = $this->calculateCashYield($macroState);
        
        return $ownCash * $cashYield;
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

    public function calculateCashYield(\App\DTO\MacroStateDTO $macroState): float
    {
        // Clearinghouses park member margin and operating reserves in overnight Central Bank deposit accounts
        // (IORB / Fed RRP) due to daily margin liquidity requirements, earning the overnight policy rate.
        $policyRate = $macroState->policyRateEma;

        return max(0.0, $policyRate);
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

    public function processPassiveLiabilityGrowth(Stock $stock, \App\DTO\MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits'];
        if ($currentLiabilities <= 0.0) {
            return;
        }

        // 1. Annualized Systemic Growth quarterized
        $inflation = $macroState->inflationEma;
        $outputGap = $macroState->outputGapEma;
        $realGdpGrowth = self::BASE_GDP_GROWTH_RATE + ($outputGap > 0.0 ? $outputGap * self::EXPANSION_GDP_MULT : $outputGap * self::RECESSION_GDP_MULT);
        $systemicGrowthQuarterly = ($inflation + $realGdpGrowth) / 4.0;

        // 2. Cyclical Elasticity (VIX Shifts)
        // High VIX = aggressive margin calls (expansion). Low VIX = collateral release (contraction).
        $vixEma = $macroState->marketVolatilityEma;
        $vixDelta = $vixEma - self::VIX_BASELINE_THRESHOLD;
        $volatilityShiftQuarterly = $vixDelta * self::VIX_POOL_GROWTH_SCALAR;

        // 3. Capacity Constraints (Mean Reversion)
        // A clearinghouse cannot grow its margin pool infinitely without commensurate equity backing.
        $equity = max(1.0, (float) $stock->getTotalEquity());
        $maxCapacity = $equity * 50.0; // statutory capacity limit

        $capacityPressure = 0.0;
        if ($currentLiabilities > $maxCapacity) {
            // Strong downward reversion if exceeding capacity
            $capacityPressure = -0.05 * ($currentLiabilities / $maxCapacity);
        } elseif ($currentLiabilities < $maxCapacity * 0.5) {
            // Gentle upward pull if severely under-utilized
            $capacityPressure = 0.02;
        }

        $baseGrowth = $systemicGrowthQuarterly + $volatilityShiftQuarterly + $capacityPressure;
        $noise = $mathUtility->generateStandardNormal() * self::POOL_GROWTH_NOISE_STD;

        // Clamp quarterly margin pool volatility within realistic bounds (+/- 5%)
        $growthRate = max(-0.05, min(0.05, $baseGrowth + $noise));

        $liabilityChange = $currentLiabilities * $growthRate;

        if (abs($liabilityChange) > 0.0) {
            // Segregated margin accounting: outflows cannot exceed available deposits
            if ($liabilityChange < 0.0 && abs($liabilityChange) > $currentLiabilities) {
                $liabilityChange = -$currentLiabilities;
            }

            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;

            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));

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

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
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
            // A clearinghouse holds segregated member margin liabilities and statutory liquidity buffer.
            // It should never be flagged as a corporate cash hoarder for buyback or aggressive deleveraging sweeps.
            'is_hoarder'      => false,
            'is_mega_hoarder' => false,
        ];
    }
}
