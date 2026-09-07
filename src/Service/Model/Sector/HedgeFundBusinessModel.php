<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\ModelParam;
use App\DTO\DebtHealthDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Quantitative Hedge Funds & Tactical Asset Managers.
 *
 * Financial Physics:
 * - 3-Stream Revenue Architecture: Sticky Management Fees, Leveraged Directional Bets, Quantitative Alpha Engine.
 * - Implied AUM Physics: AUM is derived strictly from baseline management fee capacity ($expectedRevenue * mgmtWeight / 2%).
 * - "2 and 20" Payoff: Incentive fees operate as a call option on excess alpha over hurdle rate.
 * - Almgren-Chriss / Kyle Liquidity Physics: Forced fire-sale liquidations during prime margin calls incur quadratic cost friction on capital, translated to margin decay.
 * - Multi-Stream Carry Allocation: Performance fees are cleanly attributed across directional and quant streams.
 */
class HedgeFundBusinessModel extends AssetManagementBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for hedge funds is extremely low due to black-box opacity. */
    public const BASE_COVERAGE_VISIBILITY = 0.15;
    /** Base coverage estimation error is high due to unpredictable alpha generation. */
    public const BASE_COVERAGE_ERROR = 0.15;
    /** Analyst visibility into directional long/short books (via 13F filings). */
    public const DIR_ANALYST_VISIBILITY = 0.40;
    /** Analyst visibility into quantitative statistical arbitrage (completely opaque). */
    public const QUANT_ANALYST_VISIBILITY = 0.05;

    // --- Stream Default Weights ---
    /** Baseline fraction of revenue derived from sticky recurring AUM management fees. */
    public const DEFAULT_MGMT_FEE_WEIGHT = 0.30;
    /** Baseline fraction of revenue derived from leveraged directional long/short bets. */
    public const DEFAULT_DIRECTIONAL_BETS_WEIGHT = 0.40;
    /** Baseline fraction of revenue derived from quantitative statistical arbitrage and market making. */
    public const DEFAULT_QUANT_ALPHA_WEIGHT = 0.30;

    // --- Stream Volatility & AR(1) Persistence ---
    /** AR(1) persistence coefficient for recurring management fees. */
    public const MGMT_PERSISTENCE = 0.50;
    /** AR(1) persistence coefficient for tactical directional bets. */
    public const DIR_PERSISTENCE = 0.15;
    /** AR(1) persistence coefficient for quantitative statistical arbitrage models. */
    public const QUANT_PERSISTENCE = 0.20;

    /** Low volatility scalar for sticky baseline management fees. */
    public const MGMT_BASE_VOLATILITY_SCALAR = 0.50;
    /** High volatility scalar for leveraged directional long/short positions. */
    public const DIRECTIONAL_BASE_VOLATILITY_SCALAR = 2.50;
    /** Moderate-high volatility scalar for black-box quantitative alpha models. */
    public const QUANT_ALPHA_VOLATILITY_SCALAR = 1.80;

    // --- The "2 and 20" Fee Physics ---
    /** Baseline annual management fee rate (~2% of AUM) used to infer implied AUM capacity. */
    public const BASE_MANAGEMENT_FEE_RATE = 0.02;
    /** Carried interest / performance incentive fee rate (~20% on excess alpha). */
    public const INCENTIVE_FEE_RATE = 0.20;
    /** Alpha Z-score hurdle threshold above which incentive fees crystallize. */
    public const PERFORMANCE_FEE_HURDLE_Z = 1.25;
    /** Fraction of excess performance fee / carry revenue paid out into portfolio manager and quant bonus pools. */
    public const PERF_BONUS_POOL_PAYOUT = 0.45;

    // --- Quantitative Alpha & VIX Physics ---
    /** Baseline VIX threshold (~18%) above which market fragmentation expands quantitative alpha spreads. */
    public const VIX_ALPHA_BASELINE = 0.18;
    /** Multiplier scaling quantitative alpha revenue bonuses with elevated market volatility. */
    public const VIX_ALPHA_SCALAR = 1.50;
    /** Drag scalar on quant alpha revenue when market volatility is compressed below baseline. */
    public const VIX_CALM_DRAG_SCALAR = 1.25;

    // --- Directional Bets & Leverage Physics ---
    /** Multiplier applied to gross leverage (Debt/Equity) to amplify directional trading returns. */
    public const LEVERAGE_AMPLIFIER_SCALAR = 0.50;
    /** Sensitivity of directional bets to macroeconomic output gap expansion or contraction. */
    public const DIRECTIONAL_MACRO_SCALAR = 1.50;
    /** Weight assigned to directional Z-score when computing composite fund alpha for performance fees. */
    public const COMPOSITE_ALPHA_DIR_WEIGHT = 0.60;
    /** Weight assigned to quant Z-score when computing composite fund alpha for performance fees. */
    public const COMPOSITE_ALPHA_QUANT_WEIGHT = 0.40;
    /** Macro demand shift scalar for macroeconomic output gap. */
    public const MACRO_DEMAND_SCALAR = 1.0;
    /** Sensitivity of AUM management fee base to macroeconomic output gap. */
    public const AUM_MARKET_BETA_SCALAR = 1.0;
    /** Sensitivity of hedge fund AUM allocations and prime brokerage liquidity to M2 money supply growth. */
    public const M2_HEDGE_FUND_LIQUIDITY_SENSITIVITY = 0.35;

    // --- Redemption & Capital Flight Physics ---
    /** Z-score threshold for composite alpha below which institutional investors trigger redemptions. */
    public const REDEMPTION_SHOCK_Z_FLOOR = -1.00;
    /** Penalty scalar applied to the AUM base per z-unit below the redemption shock threshold. */
    public const REDEMPTION_SHOCK_SCALAR = 0.10;
    /** Maximum fraction of AUM that can be redeemed in a single quarter to prevent mathematical collapse. */
    public const MAX_REDEMPTION_DRAG = 0.30;

    // --- Kyle / Almgren-Chriss Liquidity Friction & Margin Calls ---
    /** Macro credit spread threshold (~400bps) above which prime brokers issue margin calls. */
    public const MARGIN_CALL_SPREAD_THRESHOLD = 0.040;
    /** Multiplier scaling spread blowout to determine prime broker liquidation haircut. */
    public const PRIME_BROKER_HAIRCUT_MULT = 5.0;
    /** Temporary market impact coefficient (lambda) for Almgren-Chriss quadratic slippage. */
    public const LIQUIDITY_FRICTION_LAMBDA = 0.80;

    // --- Prime Broker Debt Gating ---
    /** Baseline normal macro credit spread (~200bps) for prime brokerage borrowing. */
    public const BASELINE_PRIME_CREDIT_SPREAD = 0.020;
    /** Scalar multiplying credit spread blowout to determine prime debt gating capacity. */
    public const DEBT_GATE_SPREAD_SCALAR = 10.0;
    /** Multiple compression threshold triggering a freeze on new prime leverage expansion. */
    public const DEBT_GATE_DRAG_THRESHOLD = 0.30;
    /** Capacity reduction scalar applied when debt markets are gated during credit freezes. */
    public const DEBT_GATE_FREEZE_SCALAR = 0.25;
    /** Critical credit spread blowout drag threshold triggering total prime leverage freeze. */
    public const DEBT_GATE_CRITICAL_FREEZE_THRESHOLD = 1.00;

    // --- Structural Efficiency & Revenue Variance ---
    /** Multiplier applied to baseline stock volatility for top-line hedge fund revenue variance. */
    public const REVENUE_VARIANCE_SCALAR = 0.18;
    /** Minimum structural operating cost-to-revenue ratio reflecting quant compute, feeds, and talent. */
    public const MIN_EFFICIENCY_RATIO = 0.35;
    /** Upper clamp for realized variable margin during forced liquidation slippage. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 0.85;

    // --- Corporate Treasury & Cash Yield ---
    /** Allocation percentage of hedge fund excess cash deployed into safe sovereign debt tranches. */
    public const PORTFOLIO_BOND_ALLOCATION = 0.80;
    /** Allocation percentage of hedge fund excess cash deployed into fund co-investments and alpha pools. */
    public const PORTFOLIO_EQUITY_ALLOCATION = 0.20;
    /** VIX threshold above which market chaos begins to impact co-investment treasury returns. */
    public const SEED_VIX_THRESHOLD = 0.30;
    /** Sensitivity of hedge fund treasury co-investments to extreme VIX spikes above threshold. */
    public const SEED_VIX_SENSITIVITY = 0.15;

    // --- Debt Expansion Probabilities ---
    /** Base probability that the hedge fund taps prime brokerage debt markets when spreads are neutral. */
    public const DEBT_EXPANSION_BASE_PROB = 0.75;
    /** Spread multiplier effect on the probability of tapping prime leverage markets. */
    public const DEBT_EXPANSION_PROB_MULT = 0.25;
    /** Base aggressiveness fraction for new prime debt tranches. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.12;
    /** Spread multiplier effect on the aggressiveness of prime debt expansion. */
    public const DEBT_EXPANSION_AGGR_MULT = 0.25;

    // --- Event Lore Thresholds ---
    /** VIX threshold triggering Quantitative Alpha Surge event lore. */
    public const LORE_VIX_SURGE_THRESHOLD = 0.30;
    /** Quant stream Z-score threshold triggering Quantitative Alpha Surge event lore. */
    public const LORE_QUANT_ALPHA_SURGE_Z = 1.20;
    /** Composite alpha Z-score threshold triggering Performance Fee Crystallization event lore. */
    public const LORE_PERFORMANCE_CRYSTALLIZATION_Z = 1.60;
    /** Directional stream Z-score contraction threshold triggering Directional Blowup event lore. */
    public const LORE_DIRECTIONAL_BLOWUP_Z = -2.00;
    /** Leverage ratio threshold triggering Margin Call event lore under wide credit spreads. */
    public const LORE_MARGIN_CALL_LEVERAGE_THRESHOLD = 1.80;

        public function getWholesaleLeverageLimit(): float { return 3.0; }
    public function getReversionSpeed(): float { return 0.2; }
    public function getMoatSpread(): float { return 0.008; }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        return new SectorCoverageProfile(
            baseVisibility: self::BASE_COVERAGE_VISIBILITY,
            errorStdDev: self::BASE_COVERAGE_ERROR,
            minVisibility: 0.10,
            eventBaseVisibility: 0.60,
            eventMinVisibility: 0.30
        );
    }

    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }

        $saturationPenalty = CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
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

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 3.00;

        $effectiveEquity = max(1.0, $equity);
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
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $minOperatingEbit = $earningAssets * self::MIN_OPERATING_EBIT_YIELD;
        $targetEbit = max($minOperatingEbit, $targetEbit);

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_TURNOVER_CAP);

        $impliedTurnover = $targetRevenue / max(1.0, $earningAssets);

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic'    => ($impliedTurnover * $stableMargin) * (1.0 - $taxRate),
        ];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        // Nullify generic demand shift to handle multi-stream AUM market beta, VIX alpha expansion,
        // and macro directional shifts discretely per stream in calculateSectorPhysics.
        return [
            'macro_demand_shift'       => 0.0,
            'pricing_power_multiplier' => 1.0,
        ];
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::HfManagementFeeWeight->value   => self::DEFAULT_MGMT_FEE_WEIGHT,
            ModelParam::HfDirectionalBetsWeight->value => self::DEFAULT_DIRECTIONAL_BETS_WEIGHT,
            ModelParam::HfQuantAlphaWeight->value      => self::DEFAULT_QUANT_ALPHA_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'management_fees'  => $params[ModelParam::HfManagementFeeWeight],
            'directional_bets' => $params[ModelParam::HfDirectionalBetsWeight],
            'quant_alpha'      => $params[ModelParam::HfQuantAlphaWeight],
        ]);

        $mgmtWeight  = $activeWeights['management_fees'];
        $dirWeight   = $activeWeights['directional_bets'];
        $quantWeight = $activeWeights['quant_alpha'];

        $mgmtZ  = $streams->generateZ('management_fees', self::MGMT_PERSISTENCE);
        $dirZ   = $streams->generateZ('directional_bets', self::DIR_PERSISTENCE);
        $quantZ = $streams->generateZ('quant_alpha', self::QUANT_PERSISTENCE);

        $outputGap      = $macroState->outputGapEma;
        $vixEma         = $macroState->marketVolatilityEma;
        $creditSpread   = $macroState->macroCreditSpreadEma;
        $beta           = (float) $stock->getBeta();
        $equity         = (float) $stock->getTotalEquity();
        $wholesaleDebt  = (float) $stock->getWholesaleDebt();
        $actualLeverage = $equity > 0 ? ($wholesaleDebt / $equity) : 0.0;

        // --- 1. Sticky Management Fee Revenue (Cyclical AUM Market Beta & Redemption Drag) ---
        $compositeAlphaZ = ($dirZ * self::COMPOSITE_ALPHA_DIR_WEIGHT) + ($quantZ * self::COMPOSITE_ALPHA_QUANT_WEIGHT);
        $redemptionDrag = 0.0;
        if ($compositeAlphaZ < self::REDEMPTION_SHOCK_Z_FLOOR) {
            $rawDrag = abs($compositeAlphaZ - self::REDEMPTION_SHOCK_Z_FLOOR) * self::REDEMPTION_SHOCK_SCALAR;
            $redemptionDrag = min(self::MAX_REDEMPTION_DRAG, $rawDrag);
        }

        $aumMarketBeta = $outputGap * abs($beta) * self::AUM_MARKET_BETA_SCALAR;
        $m2Shift = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, sensitivity: self::M2_HEDGE_FUND_LIQUIDITY_SENSITIVITY);
        $mgmtExpectedRevenue = $expectedRevenue * $mgmtWeight * (1.0 - $redemptionDrag);

        $mgmtRevenue = max(0.0, $mgmtExpectedRevenue
            * (1.0 + ($mgmtZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::MGMT_BASE_VOLATILITY_SCALAR)) + $aumMarketBeta + $m2Shift));

        // --- 2. Leveraged Directional Bets & Performance Fees (Asymmetric Alpha Call Option) ---
        $leverageMultiplier = 1.0 + ($actualLeverage * self::LEVERAGE_AMPLIFIER_SCALAR);
        $macroDirectionalShift = $outputGap * $beta * self::DIRECTIONAL_MACRO_SCALAR;

        // In hedge fund physics ("2 and 20"):
        // Above hurdle (Z > 1.25), performance incentive fees scale dynamically with excess alpha and leverage.
        // Below hurdle (Z < 1.25), no performance fees are earned; directional trading revenues scale with baseline volatility.
        $dirBaseDrift = $dirZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::DIRECTIONAL_BASE_VOLATILITY_SCALAR * $leverageMultiplier;
        $dirIncentiveBonus = max(0.0, ($dirZ - self::PERFORMANCE_FEE_HURDLE_Z) * self::INCENTIVE_FEE_RATE * 3.0 * $leverageMultiplier);

        $dirRevenue = max(0.0, $expectedRevenue * $dirWeight
            * (1.0 + $dirBaseDrift + $dirIncentiveBonus + $macroDirectionalShift));

        // --- 3. Quantitative Alpha Engine (Volatility Spread Expansion & Market Making) ---
        $vixAlphaMultiplier = 1.0 + max(0.0, ($vixEma - self::VIX_ALPHA_BASELINE) * self::VIX_ALPHA_SCALAR);
        $vixCalmDrag        = min(0.0, ($vixEma - self::VIX_ALPHA_BASELINE) * self::VIX_CALM_DRAG_SCALAR);

        $quantBaseDrift = $quantZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::QUANT_ALPHA_VOLATILITY_SCALAR;
        $quantIncentiveBonus = max(0.0, ($quantZ - self::PERFORMANCE_FEE_HURDLE_Z) * self::INCENTIVE_FEE_RATE * 2.0);

        $quantRevenue = max(0.0, ($expectedRevenue * $quantWeight
            * (1.0 + $quantBaseDrift + $quantIncentiveBonus + $vixCalmDrag)
            * $vixAlphaMultiplier));

        $streamRevenues = [
            'management_fees'  => $mgmtRevenue,
            'directional_bets' => $dirRevenue,
            'quant_alpha'      => $quantRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- 4. Compensation Pool Flex & Variable Cost Scaling ---
        // Performance fee crystallization expands portfolio manager & quant incentive bonus pools.
        $dirBaseline = $expectedRevenue * $dirWeight;
        $quantBaseline = $expectedRevenue * $quantWeight;
        $perfExcess = max(0.0, $dirRevenue - $dirBaseline) + max(0.0, $quantRevenue - $quantBaseline);
        $bonusPoolExpense = $perfExcess * self::PERF_BONUS_POOL_PAYOUT;
        $effectiveVariableCosts = ($actualRevenue * $realizedVariableMargin) + $bonusPoolExpense;
        $effectiveVariableMargin = $actualRevenue > 0 ? ($effectiveVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // --- 5. Quadratic Liquidation Friction ---
        $marginCallPenalty = 0.0;
        if ($creditSpread > self::MARGIN_CALL_SPREAD_THRESHOLD) {
            $spreadDelta = $creditSpread - self::MARGIN_CALL_SPREAD_THRESHOLD;
            $liquidationFraction = min(1.0, $actualLeverage * $spreadDelta * self::PRIME_BROKER_HAIRCUT_MULT);

            // Almgren-Chriss slippage friction: 0.5 * lambda * Q^2
            $marginCallPenalty = 0.5 * self::LIQUIDITY_FRICTION_LAMBDA * ($liquidationFraction ** 2);
        }

        $rawMargin = $effectiveVariableMargin + $marginCallPenalty;
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = $this->clampMargin($rawMargin, $minVariableMargin, self::MAX_VARIABLE_MARGIN_CLAMP);

        // --- 6. Shock Event Detection ---
        $eventType = null;

        if ($creditSpread > self::MARGIN_CALL_SPREAD_THRESHOLD && $actualLeverage > self::LORE_MARGIN_CALL_LEVERAGE_THRESHOLD && $dirZ < -1.0) {
            $eventType = ShockEvent::HF_MARGIN_CALL;
        } elseif ($vixEma > self::LORE_VIX_SURGE_THRESHOLD && $quantZ > self::LORE_QUANT_ALPHA_SURGE_Z) {
            $eventType = ShockEvent::HF_QUANT_ALPHA_SURGE;
        } elseif ($compositeAlphaZ > self::LORE_PERFORMANCE_CRYSTALLIZATION_Z) {
            $eventType = ShockEvent::HF_PERFORMANCE_FEE_CRYSTALLIZATION;
        } elseif ($dirZ < self::LORE_DIRECTIONAL_BLOWUP_Z && $actualLeverage > 2.0) {
            $eventType = ShockEvent::HF_DIRECTIONAL_BLOWUP;
        }

        $primaryShockZ = $streams->resolveDominantShockZ([$dirZ, $quantZ, $mgmtZ]);

        // Analyst Observable Shock (Hedge funds are highly opaque)
        $observableShockZ = (($dirZ * $dirWeight * self::DIR_ANALYST_VISIBILITY)
            + ($quantZ * $quantWeight * self::QUANT_ANALYST_VISIBILITY))
            * $baselineVol * self::REVENUE_VARIANCE_SCALAR;

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

    public function calculateCashYield(MacroStateDTO $macroState): float
    {
        $yield10y = $macroState->yield10yEma;
        $outputGap = $macroState->outputGapEma;

        $bondReturn = $yield10y;
        $equityReturn = self::BASE_EQUITY_RETURN + ($outputGap * self::EQUITY_RETURN_GAP_MULT * 1.5);

        return max(0.0, (self::PORTFOLIO_BOND_ALLOCATION * $bondReturn) + (self::PORTFOLIO_EQUITY_ALLOCATION * $equityReturn));
    }

    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $operatingBase = $this->getOperatingBase($stock);
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);

        $baseYield = $this->calculateCashYield($macroState);

        // Hedge funds hedge risk: VIX drag applies only at extreme volatility levels
        $vixEma = $macroState->marketVolatilityEma;
        $vixDrag = max(0.0, ($vixEma - self::SEED_VIX_THRESHOLD) * self::SEED_VIX_SENSITIVITY);

        $effectiveYield = $baseYield - (self::PORTFOLIO_EQUITY_ALLOCATION * $vixDrag);

        return $excessCash * max(0.0, $effectiveYield);
    }

    public function calculateDebtExpansionCapacity(
        float $equity,
        float $totalDebt,
        float $wholesaleDebt,
        DebtHealthDTO $health,
        float $newBorrowingRate,
        float $ebit,
        float $depreciation
    ): float {
        $baseCapacity = parent::calculateDebtExpansionCapacity($equity, $totalDebt, $wholesaleDebt, $health, $newBorrowingRate, $ebit, $depreciation);

        $firmCreditSpread = $health->rawMetrics->dynamicSpread ?? self::BASELINE_PRIME_CREDIT_SPREAD;
        $spreadFreezeDrag = max(0.0, ($firmCreditSpread - self::BASELINE_PRIME_CREDIT_SPREAD) * self::DEBT_GATE_SPREAD_SCALAR);

        if ($spreadFreezeDrag >= self::DEBT_GATE_CRITICAL_FREEZE_THRESHOLD) {
            return 0.0;
        }

        if ($spreadFreezeDrag > self::DEBT_GATE_DRAG_THRESHOLD) {
            $baseCapacity *= self::DEBT_GATE_FREEZE_SCALAR;
        }

        return $baseCapacity;
    }

    public function getDebtExpansionAggressiveness(
        float $spreadMultiplier,
        float $totalDebt = 0.0,
        float $customerDeposits = 0.0,
        float $targetOperatingCash = 0.0,
        float $currentTreasury = 0.0
    ): array {
        return [
            'probability'    => self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT),
            'aggressiveness' => self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier),
        ];
    }

    public function isUnderLeveraged(
        float $currentDebtRatio,
        float $targetDebtTolerance,
        float $interestCoverage,
        float $minIcr,
        float $costOfEquity,
        float $effectiveCostOfDebt
    ): bool {
        $limit = $targetDebtTolerance > 0.0 ? $targetDebtTolerance : $this->getWholesaleLeverageLimit();
        return $currentDebtRatio < ($limit * 0.50);
    }

    public function supportsUnderleveragedDebtExpansion(): bool
    {
        return false;
    }

    public function getAcquisitionType(string $defaultType): string
    {
        return 'HOSTILE TAKEOVER';
    }
}
