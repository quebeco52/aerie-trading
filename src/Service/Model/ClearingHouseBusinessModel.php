<?php

declare(strict_types=1);

namespace App\Service\Model;

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
class ClearingHouseBusinessModel extends AbstractBusinessModel
{
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
    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
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

        $taxRate = $macroState->corporateTaxRate;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // 1. Target Net Income and EBT required to achieve ROE
        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        // 2. Non-operating corporate treasury interest and debt expense
        $policyRate = $macroState->policyRateEma;
        $ownCashIncome = $this->calculateInterestIncome($stock, $macroState, $mathUtility);

        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $yield5y = $macroState->yield5yEma;
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

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $dataZ    = $mathUtility->generateStandardNormal(); // Separate Z-score for sticky data subscriptions

        $params = $this->resolveModelParameters($stock, [
            'clearing_fee_weight' => 0.55,
            'custody_float_weight' => 0.20,
            'data_subscription_weight' => 0.25,
        ]);
        $clearingWeight = $params['clearing_fee_weight'];
        $custodyWeight  = $params['custody_float_weight'];
        $dataWeight     = $params['data_subscription_weight'];

        // The Volatility Bonus (Transaction Volume):
        // Clearinghouses thrive on sheer volume. Market panics = massive liquidations = massive fees.
        $vixEma = $macroState->marketVolatilityEma;
        $volatilityBonus = max(0.0, ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_REVENUE_SCALAR);

        // NEW: Interest Rate Volatility Bonus. If the yield curve is violently steepening or inverting, IRS clearing volumes spike.
        $yieldCurveSlope = abs($macroState->yield10yEma - $macroState->yield2yEma);
        $ratesVolBonus = $yieldCurveSlope > 0.005 ? ($yieldCurveSlope - 0.005) * 2.0 : 0.0;

        $totalMacroBonus = $volatilityBonus + $ratesVolBonus;

        // 1. Clearing Revenue (Highly cyclical, gets the Vol and Rates bonus)
        $clearingRevenue = $expectedRevenue * $clearingWeight * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $totalMacroBonus);
        // 2. Custody Revenue (Tied somewhat to volume/Z-score but no macro bonus)
        $custodyRevenue  = $expectedRevenue * $custodyWeight * (1.0 + ($revenueZ * ($baselineVol * 0.3)));
        // 3. NEW: Data & Analytics Revenue (Highly sticky SaaS revenue, immune to trading panics)
        $dataRevenue     = $expectedRevenue * $dataWeight * (1.0 + ($dataZ * ($baselineVol * 0.05)));

        $actualRevenue   = max(0.0, $clearingRevenue + $custodyRevenue + $dataRevenue);

        // The CCP Default Waterfall (Catastrophic Tail Risk)
        $defaultZ = $mathUtility->generateStandardNormal();

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

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            // Systemic defaults are largely opaque until they occur; analysts see only macro stress signals.
            observableShockZ: 0.0,
            eventType: $eventType,
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Systemic defaults partially rumored before earnings (~10% visibility).
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 0.10, errorStdDev: 0.05);
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
        // Non-operating interest income is earned on surplus corporate cash ($ownCash).
        // Additionally, the clearinghouse earns a 15 bps base custody spread plus a dynamic retention
        // share of prevailing short-term yields on member initial margin deposits ($marginPool).
        $cash = (float) $stock->getCorporateTreasury();
        $marginPool = (float) $stock->getCustomerDeposits();
        $ownCash = max(0.0, $cash - $marginPool);

        $cashYield = $this->calculateCashYield($macroState);
        $ownCashYield = $ownCash * $cashYield;

        // NEW: The ZIRP Trap. If policy rates are near zero (< 1%), the CCP waives yield retention
        // to prevent member cash drag. If rates are high, they capture their full 15% share.
        $policyRate = $macroState->policyRateEma;
        $retentionMultiplier = min(1.0, max(0.0, ($policyRate - 0.01) / 0.03));
        $dynamicRetentionShare = self::MARGIN_POOL_YIELD_RETENTION_SHARE * $retentionMultiplier;

        $effectiveCustodySpread = self::MARGIN_POOL_CUSTODY_SPREAD + ($cashYield * $dynamicRetentionShare);
        $marginPoolYield = $marginPool * $effectiveCustodySpread;

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

        // 2. Procyclical Asymmetric Margin Calls
        // If VIX is high, CCPs issue aggressive, rapid margin calls (causing massive deposit inflows).
        // If VIX drops, they release margin collateral very slowly to remain cautious.
        $vixEma = $macroState->marketVolatilityEma;
        $vixDelta = $vixEma - self::VIX_BASELINE_THRESHOLD;
        if ($vixDelta > 0) {
            $volatilityShiftQuarterly = $vixDelta * self::VIX_POOL_GROWTH_SCALAR;
        } else {
            $volatilityShiftQuarterly = ($vixDelta * self::VIX_POOL_GROWTH_SCALAR) / 4.0;
        }

        $baseGrowth = $systemicGrowthQuarterly + $volatilityShiftQuarterly;
        $noise = $mathUtility->generateStandardNormal() * self::POOL_GROWTH_NOISE_STD;

        // Allow higher upward clamps for panic margin calls, but restrict downward clamps
        $growthRate = max(-self::MAX_POOL_CHANGE_CLAMP, min(self::MAX_POOL_CHANGE_CLAMP * 1.5, $baseGrowth + $noise));

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
            // A clearinghouse holds segregated member margin liabilities and statutory liquidity buffer.
            // It should never be flagged as a corporate cash hoarder for buyback or aggressive deleveraging sweeps.
            'is_hoarder'      => false,
            'is_mega_hoarder' => false,
        ];
    }
}
