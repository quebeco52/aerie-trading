<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Asset Managers.
 * 
 * Financial Physics:
 * - Asset light, high margin business model.
 * - Revenue scales off highly sticky, recurring Assets Under Management (AUM) fees.
 * - Evaluated on Return on Equity (ROE).
 */
class AssetManagementBusinessModel extends BaseFinancialBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for asset managers with quarterly public AUM disclosures. */
    public const BASE_COVERAGE_VISIBILITY = 0.45;
    /** Standard forecasting error on asset management performance fees and net inflows. */
    public const BASE_COVERAGE_ERROR = 0.08;
    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.20;

    public function getWholesaleLeverageLimit(): float
    {
        return 0.5;
    }

    public function getReversionSpeed(): float
    {
        return 0.12;
    }

    public function getMoatSpread(): float
    {
        return 0.012;
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.035;
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.75, 'revenue_weight' => 0.25];
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        return new SectorCoverageProfile(
            baseVisibility: self::BASE_COVERAGE_VISIBILITY,
            errorStdDev: self::BASE_COVERAGE_ERROR,
            minVisibility: self::BASE_COVERAGE_MIN_VISIBILITY,
            eventBaseVisibility: 0.80,
            eventMinVisibility: 0.40
        );
    }

    // --- ROE & Target Architecture ---
    /** Weight given to historical baseline ROE when blending with TTM ROE. */
    public const BASELINE_ROE_WEIGHT = 0.50;
    /** Weight given to TTM ROE when blending with historical baseline ROE. */
    public const TTM_ROE_WEIGHT      = 0.50;
    /** Default 5Y Treasury spread over policy rate when yield curve data is absent. */
    public const DEFAULT_5Y_YIELD_PREMIUM = 0.005;
    /** Default maximum financial leverage (Debt/Equity) limit if sector configuration is absent. */
    public const DEFAULT_EQUITY_LIMIT     = 1.00;

    // --- Structural Yield Rails ---
    /** Minimum structural operating EBIT floor as a fraction of operating equity. */
    public const MIN_OPERATING_EBIT_YIELD = 0.05;
    /** Hard ceiling on gross asset turnover to prevent reverse-engineered revenue hyperinflation. */
    public const MAX_TURNOVER_CAP         = 2.00;

    // --- Macro & Shock Physics ---
    /** Macroeconomic demand shift sensitivity to output gap. */
    public const MACRO_DEMAND_SCALAR      = 0.50;
    /** Volatility multiplier for top-line revenue shocks in sticky fee models. */
    public const REVENUE_VARIANCE_SCALAR  = 0.10;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Dual-Stream Fee Architecture ---
    /** Baseline fraction of revenue derived from sticky recurring AUM management fees. */
    public const BASE_FEE_WEIGHT           = 0.80;
    /** Baseline fraction of revenue derived from volatile performance fees / carried interest. */
    public const PERFORMANCE_FEE_WEIGHT    = 0.20;

    // --- AUM Market Beta & Performance Fees ---
    /** Sensitivity of AUM management fee base to macroeconomic output gap (market appreciation/depreciation). */
    public const AUM_MARKET_BETA_SCALAR    = 0.40;
    /** Z-score threshold above which strong fund alpha crystallizes outsized performance fees / carried interest. */
    public const PERFORMANCE_FEE_Z_FLOOR   = 1.50;
    /** Revenue bonus scalar applied per z-unit above the performance fee threshold. */
    public const PERFORMANCE_FEE_SCALAR    = 0.08;
    /** Severe negative z-score threshold indicating net institutional redemptions and fee compression. */
    public const REDEMPTION_SHOCK_Z_FLOOR  = -1.50;
    /** Revenue penalty scalar applied per z-unit below the redemption shock threshold on performance carry. */
    public const REDEMPTION_SHOCK_SCALAR   = 0.06;
    /** Fraction of institutional redemptions that erodes baseline AUM management fee revenue. */
    public const BASE_REDEMPTION_ATTRITION_SCALAR = 0.04;
    /** Fraction of excess performance fee / carry revenue paid out into portfolio manager incentive bonus pools. */
    public const PERF_BONUS_POOL_PAYOUT    = 0.40;

    // --- Structural Efficiency Floor ---
    /** Minimum cost-to-revenue ratio: high operating leverage ensures variable margin does not collapse below structural platform overhead. */
    public const MIN_EFFICIENCY_RATIO      = 0.35;

    // --- Broad Money Supply & AUM Inflows ---
    /** Sensitivity of institutional and retail AUM fund inflows to broad money supply (M2) expansion. */
    public const M2_AUM_INFLOW_SENSITIVITY = 0.35;

    // --- Seed Capital & Co-Investment Volatility ---
    /** Quarterly volatility of the 40% equity seed capital tranche in the treasury co-investment portfolio. */
    public const SEED_EQUITY_VOL           = 0.10;
    /** VIX threshold above which market panic drags seed capital co-investment returns. */
    public const SEED_VIX_THRESHOLD        = 0.25;
    /** Sensitivity of seed equity co-investment drag to elevated VIX above threshold. */
    public const SEED_VIX_SENSITIVITY      = 0.25;

    // --- Event Lore Thresholds ---
    /** Z-score threshold triggering performance fee surge event lore. */
    public const LORE_PERFORMANCE_SURGE_Z  = 1.80;
    /** Z-score threshold triggering institutional fund outflows event lore. */
    public const LORE_FUND_OUTFLOWS_Z      = -1.80;

    // --- AUM Market Beta & Performance Fee Physics ---
    /** Annualization multiplier applied to quarterly net income to derive annualized ROE. */
    public const ROE_ANNUALIZATION_MULT   = 4.00;
    /** Minimum allowable ROE floor to prevent catastrophic negative overflow. */
    public const MIN_ROE_CLAMP            = -0.50;
    /** Maximum allowable ROE ceiling to prevent unrealistic hyperinflation. */
    public const MAX_ROE_CLAMP            = 1.00;
    /** Weight given to current quarter ROE when updating trailing twelve-month ROE EMA. */
    public const ROE_TTM_EMA_WEIGHT       = 0.25;
    /** Weight given to historical trailing twelve-month ROE when updating ROE EMA. */
    public const ROE_TTM_HIST_WEIGHT      = 0.75;

    // --- Liquidity & Cash Reserves ---
    /** Target operating cash reserve ratio applied to corporate operating base. */
    public const TARGET_OPERATING_BUFFER  = 0.10;
    /** Target operating cash reserve ratio applied to outstanding wholesale debt. */
    public const TARGET_DEBT_BUFFER       = 0.05;
    /** Minimum emergency operating cash reserve ratio applied to corporate operating base. */
    public const MIN_OPERATING_BUFFER     = 0.05;
    /** Threshold ratio of excess cash over operating base triggering standard hoarder status. */
    public const HOARDER_THRESHOLD        = 0.30;
    /** Threshold ratio of excess cash over operating base triggering mega-hoarder status. */
    public const MEGA_HOARDER_THRESHOLD   = 0.50;

    // --- Treasury Yield & 60/40 Portfolio ---
    /** Default policy rate fallback when macroeconomic state data is missing. */
    public const DEFAULT_POLICY_RATE_FALLBACK = 0.02;
    /** Default 10Y Treasury spread over policy rate. */
    public const DEFAULT_10Y_SPREAD       = 0.01;
    /** Baseline structural equity market return in neutral macroeconomic conditions. */
    public const BASE_EQUITY_RETURN       = 0.07;
    /** Output gap multiplier scaling equity market returns during booms and busts. */
    public const EQUITY_RETURN_GAP_MULT   = 2.00;
    /** Weight allocated to fixed-income bonds in standard asset manager treasury portfolios. */
    public const TREASURY_BOND_WEIGHT     = 0.60;
    /** Weight allocated to equities in standard asset manager treasury portfolios. */
    public const TREASURY_EQUITY_WEIGHT   = 0.40;

    // --- Debt Issuance & Capital Deployment ---
    /** Baseline probability of initiating debt expansion when spreads are neutral. */
    public const DEBT_EXPANSION_BASE_PROB = 0.50;
    /** Multiplier scaling debt expansion probability with spread attractiveness. */
    public const DEBT_EXPANSION_PROB_MULT = 0.30;
    /** Baseline aggressiveness fraction for new debt issuance. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.05;
    /** Multiplier scaling debt issuance aggressiveness with spread attractiveness. */
    public const DEBT_EXPANSION_AGGR_MULT = 0.10;
    /** Minimum fraction of newly issued debt that must be deployed into organic capex or fund seeding. */
    public const DEBT_CAPEX_DEPLOYMENT    = 0.90;

    /**
     * Asset Managers scale EBIT to cover their target ROE and any operational wholesale debt.
     * They do not use fractional customer deposits or float to generate leverage.
     */
    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $baselineRoe = max($waccBase, $baselineRoe - $saturationPenalty);

        $policyRate = $macroState->policyRateEma;
        $yield5y = $macroState->yield5yEma;
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $taxRate = $macroState->corporateTaxRate;

        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        // --- THE CLEAR BALANCE SHEET MATH ---
        // Asset managers scale EBIT from their active operating equity (AUM/Platform capacity).
        // Excess cash beyond target operating cash is considered idle and stripped from the ROE target.
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;

        $effectiveEquity = max(1.0, $equity);

        // We use ACTUAL deployed leverage (capped at limits) to prevent the "Phantom Debt" exploit, 
        // where low-leverage brokers pocket theoretical interest expense as massive ROE.
        $actualLeverage = $effectiveEquity > 0 ? ($wholesaleDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(0.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalInterestExpense = $optimalDebt * $blendedWholesaleRate;

        $optimalOperatingNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalOperatingNetIncome / (1.0 - $taxRate);

        $operatingBase = $this->getOperatingBase($stock);
        $optimalOperatingCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, $optimalDebt);
        $minOperatingCash = $this->calculateMinOperatingCash($operatingBase, 0.0, $optimalDebt);
        $optimalYieldingCash = max(0.0, $optimalOperatingCash - $minOperatingCash);
        $optimalInterestIncome = $optimalYieldingCash * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);

        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;
        $optimalEarningAssets = $effectiveEquity + $optimalDebt - $optimalOperatingCash;
        $structuralOperatingYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        $earningAssets = max($effectiveEquity, $effectiveEquity + $wholesaleDebt - $treasury);

        $targetEbit = $earningAssets * $structuralOperatingYield;
        // ------------------------------------
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // The Fee Revenue Floor:
        // Asset-light financials don't have massive balance sheets, but they must maintain 
        // structural fee revenue (AUM / Advisory) to survive.
        $minOperatingEbit = $earningAssets * self::MIN_OPERATING_EBIT_YIELD;

        $targetEbit = max($minOperatingEbit, $targetEbit);

        // We derive revenue from target EBIT to hit ROE expectations, 
        // but we MUST cap the turnover. If margins compress due to market saturation, 
        // uncapped reverse-engineering will cause top-line revenue hyperinflation!
        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_TURNOVER_CAP); // Hard cap turnover at 2.0x annually

        $impliedTurnover = $targetRevenue / max(1.0, $earningAssets);

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($impliedTurnover * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        // Nullify generic demand shift to handle AUM market beta discretely per stream in calculateSectorPhysics.
        return [
            'macro_demand_shift' => 0.0,
            'pricing_power_multiplier' => 1.0,
        ];
    }

    /**
     * Idiosyncratic variance incorporates AUM mark-to-market appreciation/depreciation,
     * asymmetric performance fee / carried interest surges during strong fund alpha quarters,
     * institutional redemption shocks during severe market drawdowns, and structural efficiency floors.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned asset management parameters
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BaseFeeWeight->value         => self::BASE_FEE_WEIGHT,
            ModelParam::PerformanceFeeWeight->value  => self::PERFORMANCE_FEE_WEIGHT,
            ModelParam::AumMarketBetaScalar->value  => self::AUM_MARKET_BETA_SCALAR,
            ModelParam::PerformanceFeeZFloor->value => self::PERFORMANCE_FEE_Z_FLOOR,
            ModelParam::PerformanceFeeScalar->value  => self::PERFORMANCE_FEE_SCALAR,
        ]);

        $baseWeight      = $params[ModelParam::BaseFeeWeight];
        $perfWeight      = $params[ModelParam::PerformanceFeeWeight];
        $aumBetaScalar   = $params[ModelParam::AumMarketBetaScalar];
        $perfZFloor      = $params[ModelParam::PerformanceFeeZFloor];
        $perfScalar      = $params[ModelParam::PerformanceFeeScalar];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'base_fee' => $params[ModelParam::BaseFeeWeight],
            'alpha'    => $params[ModelParam::PerformanceFeeWeight],
        ]);

        $baseWeight = $activeWeights['base_fee'];
        $perfWeight = $activeWeights['alpha'];

        // Independent stream Z-scores with AR(1) persistence
        $baseFeeZ = $streams->generateZ('base_fee', 0.45); // Sticky recurring AUM management fees
        $alphaZ   = $streams->generateZ('alpha', 0.15); // Fund alpha / activist execution

        // 1. AUM Mark-to-Market Beta & M2 Liquidity Inflows (Base Management Fee Stream):
        // When equity/credit markets rise or fall, or systemic broad money (M2) expands, base AUM fee revenue expands or contracts.
        $outputGap = $macroState->outputGapEma;
        $aumMarketBeta = $outputGap * abs((float) $stock->getBeta()) * $aumBetaScalar;
        $m2InflowBoost = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, sensitivity: self::M2_AUM_INFLOW_SENSITIVITY);

        // 2. Asymmetric Performance Fees & Institutional Redemptions (Incentive Fee Stream):
        // Strong alpha quarters crystallize outsized performance fees / carried interest.
        // Severe negative alpha triggers institutional client redemptions eroding baseline AUM.
        $alphaFeeBonus = 0.0;
        $baseRedemptionAttrition = 0.0;
        if ($alphaZ > $perfZFloor) {
            $alphaFeeBonus = ($alphaZ - $perfZFloor) * $perfScalar;
        } elseif ($alphaZ < self::REDEMPTION_SHOCK_Z_FLOOR) {
            $redemptionExcess = abs($alphaZ - self::REDEMPTION_SHOCK_Z_FLOOR);
            $alphaFeeBonus = -$redemptionExcess * self::REDEMPTION_SHOCK_SCALAR;
            $baseRedemptionAttrition = $redemptionExcess * self::BASE_REDEMPTION_ATTRITION_SCALAR;
        }

        // Blended dual-stream revenue
        $baseRevenue = max(0.0, $expectedRevenue * $baseWeight
            * (1.0 + ($baseFeeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $aumMarketBeta - $baseRedemptionAttrition + $m2InflowBoost));
        $perfRevenue = max(0.0, $expectedRevenue * $perfWeight
            * (1.0 + ($alphaZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $alphaFeeBonus));
        
        $streamRevenues = [
            'base_fee' => $baseRevenue,
            'alpha'    => $perfRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // 3. Compensation Pool Flex & Structural Efficiency Floor:
        // Performance fee crystallization expands incentive bonus pool expenses.
        $perfRevenueExcess = max(0.0, $perfRevenue - ($expectedRevenue * $perfWeight));
        $bonusPoolExpense = $perfRevenueExcess * self::PERF_BONUS_POOL_PAYOUT;
        $effectiveVariableCosts = ($actualRevenue * $realizedVariableMargin) + $bonusPoolExpense;
        $effectiveVariableMargin = $actualRevenue > 0 ? ($effectiveVariableCosts / $actualRevenue) : $realizedVariableMargin;

        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = $this->clampMargin($effectiveVariableMargin, $minVariableMargin);

        // 4. Dynamic Event Type:
        $eventType = null;
        if ($alphaZ > self::LORE_PERFORMANCE_SURGE_Z) {
            $eventType = ShockEvent::PERFORMANCE_FEE_SURGE;
        } elseif ($alphaZ < self::LORE_FUND_OUTFLOWS_Z) {
            $eventType = ShockEvent::FUND_OUTFLOWS;
        }

        // observableShockZ: AUM market beta component is fully public; alpha/performance fees are ~10% visible
        // We encode the relative deviation from the macro-expected revenue as the observable shock.
        $unanticipatedRevenueDelta = $actualRevenue - ($expectedRevenue * (1.0 + $aumMarketBeta));
        $observableShockZ = $expectedRevenue > 0 ? ($unanticipatedRevenueDelta / $expectedRevenue) : 0.0;
        $primaryShockZ = $streams->resolveDominantShockZ([$alphaZ, $baseFeeZ]);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * Asset Managers invest excess corporate treasury in seed capital co-investment portfolios (60/40).
     * The 40% equity seed tranche experiences quarterly stochastic mark-to-market volatility and VIX tail risk.
     */
    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $operatingBase = $this->getOperatingBase($stock);
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);

        $policyRate = $macroState->policyRateEma;

        // Base deterministic yield (60% bonds + 40% deterministic CAPM equity return)
        $baseYield = $this->calculateCashYield($macroState);

        // Expected seed capital return: VIX market panic drag.
        // We use the deterministic expected return ($seedZ = 0.0) during continuous tick valuation to prevent 
        // high-frequency distress penalty whipsaws in analyzeDebtHealth().
        $vixEma = $macroState->marketVolatilityEma;
        $vixDrag = max(0.0, ($vixEma - self::SEED_VIX_THRESHOLD) * self::SEED_VIX_SENSITIVITY);

        $stochasticEquityAdjustment = -$vixDrag;
        $effectiveYield = $baseYield + (self::TREASURY_EQUITY_WEIGHT * $stochasticEquityAdjustment);

        return $excessCash * $effectiveYield;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::TARGET_OPERATING_BUFFER, $wholesaleDebt * self::TARGET_DEBT_BUFFER);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * self::MIN_OPERATING_BUFFER;
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($operatingBase * self::HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($operatingBase * self::MEGA_HOARDER_THRESHOLD),
        ];
    }

    public function calculateCashYield(\App\DTO\MacroStateDTO $macroState): float
    {
        $policyRate = $macroState->policyRateEma;
        $yield10y = $macroState->yield10yEma;
        $outputGap = $macroState->outputGapEma;

        $bondReturn = $yield10y;
        $equityReturn = self::BASE_EQUITY_RETURN + ($outputGap * self::EQUITY_RETURN_GAP_MULT);

        // Asset Managers invest heavily in their own funds ("eating their own cooking").
        // We use a classic 60/40 portfolio (60% Bonds / 40% Equities) which gives them higher market correlation.
        return max(0.0, (self::TREASURY_BOND_WEIGHT * $bondReturn) + (self::TREASURY_EQUITY_WEIGHT * $equityReturn));
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array
    {
        return ['probability' => self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), 'aggressiveness' => self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier)];
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        // Asset managers and brokerages use capital to seed new funds, acquire advisory firms, and build trading platforms.
        return max($organicSpend, $debtIssued * self::DEBT_CAPEX_DEPLOYMENT);
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Asset managers and brokerages can expand using existing cash hoards before taking on new debt
        return max(0.0, $baseCapacity - $excessCash);
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        return max($revenueFloorValue, $peFairValue);
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Asset managers, hedge funds, and private equity trade on Fee-Related Earnings (FRE) and carry multiples, not physical Book Value.
        return $dividendSupportValue > 0.0
            ? ($earningsValue * 0.80) + ($dividendSupportValue * 0.20)
            : $earningsValue;
    }
}
