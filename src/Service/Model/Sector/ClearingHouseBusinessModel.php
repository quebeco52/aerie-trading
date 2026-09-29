<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\InterestExpenseDTO;

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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Clearing volumes rise in stress; only pool growth follows the cycle. */
    public const OPERATING_CYCLICALITY = 0.80;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. A matching engine is capital, not payroll, but risk, compliance and member-services staff carry the rest of the overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.55;

        public function getMinIcr(): float { return 1.05; }
    public function getBankruptEquityThreshold(): float { return 0.5; }
    public function getDistressEquityThreshold(): float { return 1.25; }
    public function getWarningEquityThreshold(): float { return 2.5; }

    /**
     * Member initial and variation margin. The clearinghouse holds it, invests it and keeps the spread,
     * but it belongs to the members and the default waterfall spends it before ACC's own equity is ever
     * reached, so it cannot answer for ACC's debts. getTargetMetrics, calculateInterestIncome,
     * isUnderLeveraged and evaluateHoardingStatus all already treat the pool this way.
     */
    public function getSegregatedCustodyLiabilities(Stock $stock): float
    {
        return max(0.0, (float) $stock->getCustomerDeposits());
    }
    /**
     * No discretionary corporate borrowing: a CCP funds its general business risk with liquid net assets
     * funded by equity (CPMI-IOSCO PFMI Principle 15). Debt it already carries still refinances.
     */
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
    /** Absolute 2s10s slope at which rates-clearing volumes are normal (~70bps, the neutral curve); a curve steepening or inverting past it brings swap hedging flow. */
    public const RATES_VOL_NEUTRAL_SLOPE = 0.007;
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
    /** Sensitivity of clearinghouse member default stress to elevated macroeconomic corporate default rates. */
    public const MACRO_DEFAULT_STRESS_SCALAR = 0.10;
    /** Healthy credit environment z-score threshold triggering minor margin write-backs. */
    public const HEALTHY_CREDIT_Z_FLOOR  = 1.00;
    /** Minor variable cost reduction during exceptionally healthy credit environments. */
    public const HEALTHY_CREDIT_BONUS    = -0.02;
    /** Extreme default z-score threshold triggering apocalyptic clearinghouse bailout lore. */
    public const LORE_DEFAULT_Z_THRESHOLD = -3.00;

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
    /** Sensitivity of clearing demand to volatility above its baseline (high VIX brings hedging and liquidation flow). */
    public const VIX_POOL_GROWTH_SCALAR  = 0.50;
    /** Elasticity of initial margin to the volatility it is struck on: IM is a VaR over the liquidation period, so at fixed positions it scales one-for-one with sigma (EMIR RTS 153/2013 Arts. 24-26; CFTC 17 CFR 39.13(g)). */
    public const MARGIN_VOLATILITY_ELASTICITY = 1.0;
    /** Persisted smoothed volatility the margin pool was last struck on. */
    public const STATE_MARGIN_VOLATILITY = 'state:margin_volatility';
    /** Persisted log change in the margin rate this quarter, applied to the pool when the treasury rolls it. */
    public const STATE_MARGIN_RATE_CHANGE = 'state:margin_rate_change';
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
        // Clearing capacity is the house's own loss-absorbing capital, its skin in the game; the members'
        // margin pool is a pass-through liability. Goodwill absorbs no member default and is not a liquid
        // net asset (PFMI Principle 15), so the base is tangible equity: the premium paid for a deal buys no
        // clearing capacity and writing it off deletes none.
        $tangibleEquity = max(1.0, $stock->getTangibleEquity());

        $baselineRoe = $this->resolveStructuralTargetRoe($stock, $macroState);

        $taxRate = $macroState->corporateTaxRate;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // Fee revenue follows the capital that backs clearing, not how the house is financed (Modigliani-
        // Miller): operations earn the target return on that capital, and the interest on the house's own
        // debt and cash sits below operating income. Adding the interest bill to the target let every
        // borrowed dollar raise the revenue that paid for it.
        $targetTotalEbit = max(
            $tangibleEquity * self::MIN_EQUITY_EBIT_YIELD,
            ($tangibleEquity * $baselineRoe) / (1.0 - $taxRate)
        );

        $targetTotalRevenue = $targetTotalEbit / $stableMargin;
        $grossYield = $targetTotalRevenue / $tangibleEquity;

        return [
            'invested_capital' => $tangibleEquity,
            'baseline_roic'    => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

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
        $ratesVolBonus = $yieldCurveSlope > self::RATES_VOL_NEUTRAL_SLOPE ? ($yieldCurveSlope - self::RATES_VOL_NEUTRAL_SLOPE) * 2.0 : 0.0;

        $totalMacroBonus = $volatilityBonus + $ratesVolBonus;

        // Initial margin is struck on the smoothed volatility, so the margin rate moves with its CHANGE. The
        // log change is carried to the treasury, which rolls the pool later in the quarter.
        $marginVolatility = max(1e-4, $vixEma);
        $previousMarginVolatility = $streams->getPersistedState(self::STATE_MARGIN_VOLATILITY, $marginVolatility);
        $streams->registerState(self::STATE_MARGIN_VOLATILITY, $marginVolatility);
        $streams->registerState(self::STATE_MARGIN_RATE_CHANGE, self::MARGIN_VOLATILITY_ELASTICITY * log($marginVolatility / max(1e-4, $previousMarginVolatility)));

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
        $defaultZ = $streams->generateExogenousZ('default', 0.05);

        // Under the Default Waterfall, routine member defaults ($defaultZ >= CATASTROPHE_Z_THRESHOLD) are fully absorbed
        // by the defaulting member's posted Initial Margin and Guaranty Fund contribution ($0 loss to CCP equity).
        // Only a severe systemic failure pierces the waterfall to hit the CCP's Skin-in-the-Game (SITG) capital tranche.
        $corporateDefaultShift = MathUtility::excessOverBaseline($macroState->corporateDefaultRateEma, MacroEngine::CORPORATE_DEFAULT_BASELINE);
        $macroMemberStress = $corporateDefaultShift * self::MACRO_DEFAULT_STRESS_SCALAR * 0.05;

        $catastropheShock = ($defaultZ < self::CATASTROPHE_Z_THRESHOLD
            ? abs($defaultZ - self::CATASTROPHE_Z_THRESHOLD) * self::CATASTROPHE_LOSS_SCALAR
            : ($defaultZ > self::HEALTHY_CREDIT_Z_FLOOR ? self::HEALTHY_CREDIT_BONUS : 0.0)) + $macroMemberStress;

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

    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        // Non-operating interest income is earned ONLY on surplus corporate cash ($ownCash).
        // Margin pool custody spread is an operating revenue stream included in calculateSectorPhysics.
        $cash = (float) $stock->getCorporateTreasury();
        $marginPool = (float) $stock->getCustomerDeposits();
        $ownCash = max(0.0, $cash - $marginPool);

        $cashYield = $this->calculateCashYield($macroState);
        
        return $ownCash * $cashYield;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt, ?\App\DTO\MacroStateDTO $macroState = null): InterestExpenseDTO
    {
        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Corporate debt interest
        $corporateInterest = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $corporateDebt > 0 ? ($corporateInterest / $corporateDebt) : $currentMarketFixedRate;

        // Note: Margin pool custody rebates are pass-through distributions netted against custody yield in calculateInterestIncome.
        // Returning only corporate debt interest ensures ICR and solvency metrics measure true corporate debt servicing capacity.
        return new InterestExpenseDTO(interestExpense: $corporateInterest, wholesaleRate: $wholesaleRate);
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

    /** Only what sits above the fully backed margin pool is invested; the pool itself stays liquid. */
    public function deploysFundingIntoEarningAssets(): bool
    {
        return true;
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

        // Initial margin is open positions times a VaR rate. Positions grow with trend nominal income, the trend
        // a bank's deposit base follows; the rate moves with the change in the volatility it is struck on, which
        // sector physics carried here. A volatility LEVEL moves the pool's level, never its growth rate: read as a
        // rate, a quiet market bled the pool away every quarter it stayed quiet.
        $trendGrowthQuarterly = ($macroState->inflationEma + MacroEngine::TFP_DRIFT + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE) * \App\Service\Corporate\EarningsEngine::QUARTERLY_TIME_STEP;
        $marginRateChange = (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_MARGIN_RATE_CHANGE] ?? 0.0);
        $noise = $mathUtility->generateStandardNormal() * self::POOL_GROWTH_NOISE_STD;

        $liabilityChange = $currentLiabilities * (((1.0 + $trendGrowthQuarterly) * exp($marginRateChange + $noise)) - 1.0);

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

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'corporate_default_rate_ema',
            'inflation_ema',
            'market_volatility_ema',
            'policy_rate_ema',
            'yield_10y_ema',
            'yield_2y_ema',
        ];
    }
}
