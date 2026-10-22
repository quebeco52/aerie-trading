<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\DebtExpansionAppetiteDTO;
use App\DTO\DebtHealthDTO;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Quantitative Hedge Funds & Tactical Asset Managers.
 *
 * Financial Physics:
 * - 3-Stream Revenue Architecture: Sticky Management Fees, Leveraged Directional Bets, Quantitative Alpha Engine.
 * - Implied AUM Physics: AUM is derived strictly from baseline management fee capacity ($expectedRevenue * mgmtWeight /
 *   2%).
 * - "2 and 20" Payoff: Incentive fees operate as a call option on excess alpha over hurdle rate.
 * - Almgren-Chriss / Kyle Liquidity Physics: Forced fire-sale liquidations during prime margin calls incur quadratic
 *   cost friction on capital, translated to margin decay.
 * - Multi-Stream Carry Allocation: Performance fees are cleanly attributed across directional and quant streams.
 */
class HedgeFundBusinessModel extends AssetManagementBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Levered directional
     * books.
     */
    public const OPERATING_CYCLICALITY = 1.50;

    // --- Balance Sheet Realism ---
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Investments & Asset Management, 5.08% of revenue, 283
     * firms (Damodaran, Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0508;

    // --- Labor Intensity ---
    /**
     * Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Investment team compensation dominates
     * hedge fund overhead.
     */
    public const FIXED_COST_LABOR_SHARE = 0.70;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for hedge funds is extremely low due to black-box opacity. */
    public const BASE_COVERAGE_VISIBILITY = 0.15;
    /** Base coverage estimation error is high due to unpredictable alpha generation. */
    public const BASE_COVERAGE_ERROR = 0.15;

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
    /** Carried interest / performance incentive fee rate (~20% on excess alpha). */
    public const INCENTIVE_FEE_RATE = 0.20;
    /** Alpha Z-score hurdle threshold above which incentive fees crystallize. */
    public const PERFORMANCE_FEE_HURDLE_Z = 1.25;
    /** Directional incentive revenue per unit of alpha over the hurdle, as a multiple of the fee rate (3x). */
    public const DIRECTIONAL_FEE_GEARING = 3.0;
    /** Quant incentive revenue per unit of alpha over the hurdle, as a multiple of the fee rate (2x). */
    public const QUANT_FEE_GEARING = 2.0;
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
    /** Multiplier applied to the funds' gross leverage to amplify directional trading returns. */
    public const LEVERAGE_AMPLIFIER_SCALAR = 0.50;
    /**
     * Gross leverage of the funds by default: a long/short book runs about twice its investors' capital, borrowed from
     * its prime brokers, not on the manager's own balance sheet.
     */
    public const FUND_GROSS_LEVERAGE = 2.0;
    /** Sensitivity of directional bets to macroeconomic output gap expansion or contraction. */
    public const DIRECTIONAL_MACRO_SCALAR = 1.50;
    /** Weight assigned to directional Z-score when computing composite fund alpha for performance fees. */
    public const COMPOSITE_ALPHA_DIR_WEIGHT = 0.60;
    /** Weight assigned to quant Z-score when computing composite fund alpha for performance fees. */
    public const COMPOSITE_ALPHA_QUANT_WEIGHT = 0.40;
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
    /** Baseline normal macro credit spread for prime brokerage borrowing, the macro through-the-cycle IG spread. */
    public const BASELINE_PRIME_CREDIT_SPREAD = MacroConstants::BASE_CREDIT_SPREAD;
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
    /** Fund gross leverage above which a directional collapse reads as a blow-up rather than a bad quarter. */
    public const LORE_DIRECTIONAL_BLOWUP_LEVERAGE_THRESHOLD = 2.0;

    // --- Analyst Coverage ---
    // Returns leak through investor letters; a drawdown event is reported.
    /** Floor on routine visibility (10%). */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.10;
    /** Visibility of a quarter with an announced event (60%). */
    public const EVENT_BASE_VISIBILITY = 0.60;
    /** Floor on visibility in an announced-event quarter (30%). */
    public const EVENT_MIN_VISIBILITY = 0.30;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.2 a year). */
    public const ROIC_REVERSION_SPEED = 0.2;
    /** Excess return over the required return the moat sustains (0.8pp). */
    public const MOAT_SPREAD = 0.008;

    // --- Credit Thresholds ---
    /** Most wholesale debt the model carries per unit of equity (3x equity). */
    public const WHOLESALE_LEVERAGE_LIMIT = 3.0;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        // Provide analyst-visible macro shift for consensus; neutralized in calculateSectorPhysics before stream
        // physics.
        return [
            'macro_demand_shift'       => $this->resolveAnalystVisibleMacroShift($stock, $macroState),
            'pricing_power_multiplier' => 1.0,
        ];
    }

    /**
     * The root shift already carries the fund's stream-weighted macro activity into capacity utilisation, so
     * the cost base has seen it; the asset manager's client-flow reading would count it a second time.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return 0.0;
    }

    /**
     * Revenue-weighted macro uplift analysts can forecast from published series, with the baseline stream
     * weights: the AUM market beta and broad-money shift on management fees, the output-gap tilt on
     * directional books, and the VIX regime on quant alpha. Everything driven by the fund's own alpha draws
     * (redemption shocks, incentive fees, blowups) stays inside the physics, since nobody outside can see it.
     */
    private function resolveAnalystVisibleMacroShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::HfManagementFeeWeight->value   => self::DEFAULT_MGMT_FEE_WEIGHT,
            ModelParam::HfDirectionalBetsWeight->value => self::DEFAULT_DIRECTIONAL_BETS_WEIGHT,
            ModelParam::HfQuantAlphaWeight->value      => self::DEFAULT_QUANT_ALPHA_WEIGHT,
        ]);

        $outputGap = $macroState->outputGapEma;
        $beta = $this->getOperatingCyclicality($stock);
        $vixEma = $macroState->marketVolatilityEma;

        $managementShift = ($outputGap * abs($beta) * self::AUM_MARKET_BETA_SCALAR)
            + MacroTransmission::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, $macroState->moneySupplyGrowthTrend, sensitivity: self::M2_HEDGE_FUND_LIQUIDITY_SENSITIVITY);
        $directionalShift = $outputGap * $beta * self::DIRECTIONAL_MACRO_SCALAR;
        $quantShift = ((1.0 + max(0.0, ($vixEma - self::VIX_ALPHA_BASELINE) * self::VIX_ALPHA_SCALAR))
            * (1.0 + min(0.0, ($vixEma - self::VIX_ALPHA_BASELINE) * self::VIX_CALM_DRAG_SCALAR))) - 1.0;

        return ($params[ModelParam::HfManagementFeeWeight] * $managementShift)
            + ($params[ModelParam::HfDirectionalBetsWeight] * $directionalShift)
            + ($params[ModelParam::HfQuantAlphaWeight] * $quantShift);
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility,
    ): SectorPhysicsResult {
        // Neutralize analyst macro shift so streams apply idiosyncratic macro terms on the base.
        $structuralRevenue = $expectedRevenue / max(0.25, 1.0 + $this->resolveAnalystVisibleMacroShift($stock, $macroState));

        $params = $this->resolveModelParameters($stock, [
            ModelParam::HfManagementFeeWeight->value   => self::DEFAULT_MGMT_FEE_WEIGHT,
            ModelParam::HfDirectionalBetsWeight->value => self::DEFAULT_DIRECTIONAL_BETS_WEIGHT,
            ModelParam::HfQuantAlphaWeight->value      => self::DEFAULT_QUANT_ALPHA_WEIGHT,
            ModelParam::FundGrossLeverage->value       => self::FUND_GROSS_LEVERAGE,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

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
        $beta           = $this->getOperatingCyclicality($stock);
        // The directional book, its margin calls and its prime brokers' haircuts run on the funds' leverage. Read off
        // the manager's own debt, a manager that paid its borrowing down deleveraged funds it does not finance.
        $fundLeverage   = max(0.0, $params[ModelParam::FundGrossLeverage]);

        // --- 1. Sticky Management Fee Revenue (Cyclical AUM Market Beta & Redemption Drag) ---
        $compositeAlphaZ = ($dirZ * self::COMPOSITE_ALPHA_DIR_WEIGHT) + ($quantZ * self::COMPOSITE_ALPHA_QUANT_WEIGHT);
        $redemptionDrag = 0.0;
        if ($compositeAlphaZ < self::REDEMPTION_SHOCK_Z_FLOOR) {
            $rawDrag = abs($compositeAlphaZ - self::REDEMPTION_SHOCK_Z_FLOOR) * self::REDEMPTION_SHOCK_SCALAR;
            $redemptionDrag = min(self::MAX_REDEMPTION_DRAG, $rawDrag);
        }

        $aumMarketBeta = $outputGap * abs($beta) * self::AUM_MARKET_BETA_SCALAR;
        $m2Shift = MacroTransmission::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, $macroState->moneySupplyGrowthTrend, sensitivity: self::M2_HEDGE_FUND_LIQUIDITY_SENSITIVITY);
        $mgmtExpectedRevenue = $structuralRevenue * $mgmtWeight * (1.0 - $redemptionDrag);

        $mgmtRevenue = max(0.0, $mgmtExpectedRevenue
            * (1.0 + ($mgmtZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::MGMT_BASE_VOLATILITY_SCALAR)) + $aumMarketBeta + $m2Shift));

        // --- 2. Leveraged Directional Bets & Performance Fees (Asymmetric Alpha Call Option) ---
        $leverageMultiplier = 1.0 + ($fundLeverage * self::LEVERAGE_AMPLIFIER_SCALAR);
        $macroDirectionalShift = $outputGap * $beta * self::DIRECTIONAL_MACRO_SCALAR;

        // In hedge fund physics ("2 and 20"): above the hurdle (Z > 1.25), incentive fees scale with excess alpha and
        // leverage; below it none are earned. The fee is a call on alpha, and expected revenue is the average year,
        // fees included, so each quarter books its excess over the call's expectation, E[max(0, Z - hurdle)].
        $averageFeeExcess = Distributions::calculateNormalLowerPartialMoment(-self::PERFORMANCE_FEE_HURDLE_Z);
        $dirAlphaSlope = $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::DIRECTIONAL_BASE_VOLATILITY_SCALAR * $leverageMultiplier;
        $dirFeeSlope = self::INCENTIVE_FEE_RATE * self::DIRECTIONAL_FEE_GEARING * $leverageMultiplier;
        $dirBaseDrift = $dirZ * $dirAlphaSlope;
        $dirIncentiveBonus = (max(0.0, $dirZ - self::PERFORMANCE_FEE_HURDLE_Z) - $averageFeeExcess) * $dirFeeSlope;

        $dirRevenue = max(0.0, $structuralRevenue * $dirWeight
            * (1.0 + $dirBaseDrift + $dirIncentiveBonus + $macroDirectionalShift));

        // --- 3. Quantitative Alpha Engine (Volatility Spread Expansion & Market Making) ---
        $vixAlphaMultiplier = 1.0 + max(0.0, ($vixEma - self::VIX_ALPHA_BASELINE) * self::VIX_ALPHA_SCALAR);
        $vixCalmDrag        = min(0.0, ($vixEma - self::VIX_ALPHA_BASELINE) * self::VIX_CALM_DRAG_SCALAR);

        $quantAlphaSlope = $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::QUANT_ALPHA_VOLATILITY_SCALAR;
        $quantFeeSlope = self::INCENTIVE_FEE_RATE * self::QUANT_FEE_GEARING;
        $quantBaseDrift = $quantZ * $quantAlphaSlope;
        $quantIncentiveBonus = (max(0.0, $quantZ - self::PERFORMANCE_FEE_HURDLE_Z) - $averageFeeExcess) * $quantFeeSlope;

        $quantRevenue = max(0.0, ($structuralRevenue * $quantWeight
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
        // Portfolio managers and quants share each alpha stream's beat and none of its miss, so the bonus pool is a
        // call on it. The planned cost ratio already carries the pool's average quarter at a neutral cycle, so each
        // quarter books its excess over that expectation; a boom's beat on top still lifts the pool.
        $dirBaseline = $structuralRevenue * $dirWeight;
        $quantBaseline = $structuralRevenue * $quantWeight;
        $perfExcess = max(0.0, $dirRevenue - $dirBaseline) + max(0.0, $quantRevenue - $quantBaseline);
        $averagePerfExcess = ($dirBaseline * self::expectedStreamBeat($dirAlphaSlope, $dirFeeSlope))
            + ($quantBaseline * self::expectedStreamBeat($quantAlphaSlope, $quantFeeSlope));
        $bonusPoolExpense = ($perfExcess - $averagePerfExcess) * self::PERF_BONUS_POOL_PAYOUT;
        $effectiveVariableCosts = ($actualRevenue * $realizedVariableMargin) + $bonusPoolExpense;
        $effectiveVariableMargin = $actualRevenue > 0 ? ($effectiveVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // --- 5. Quadratic Liquidation Friction ---
        $marginCallPenalty = 0.0;
        if ($creditSpread > self::MARGIN_CALL_SPREAD_THRESHOLD) {
            $spreadDelta = $creditSpread - self::MARGIN_CALL_SPREAD_THRESHOLD;
            $liquidationFraction = min(1.0, $fundLeverage * $spreadDelta * self::PRIME_BROKER_HAIRCUT_MULT);

            // Almgren-Chriss slippage friction: 0.5 * lambda * Q^2
            $marginCallPenalty = 0.5 * self::LIQUIDITY_FRICTION_LAMBDA * ($liquidationFraction ** 2);
        }

        $rawMargin = $effectiveVariableMargin + $marginCallPenalty;
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = $this->clampMargin($rawMargin, $minVariableMargin, self::MAX_VARIABLE_MARGIN_CLAMP);

        // --- 6. Shock Event Detection ---
        $eventType = null;

        if ($creditSpread > self::MARGIN_CALL_SPREAD_THRESHOLD && $fundLeverage > self::LORE_MARGIN_CALL_LEVERAGE_THRESHOLD && $dirZ < -1.0) {
            $eventType = ShockEvent::HF_MARGIN_CALL;
        } elseif ($vixEma > self::LORE_VIX_SURGE_THRESHOLD && $quantZ > self::LORE_QUANT_ALPHA_SURGE_Z) {
            $eventType = ShockEvent::HF_QUANT_ALPHA_SURGE;
        } elseif ($compositeAlphaZ > self::LORE_PERFORMANCE_CRYSTALLIZATION_Z) {
            $eventType = ShockEvent::HF_PERFORMANCE_FEE_CRYSTALLIZATION;
        } elseif ($dirZ < self::LORE_DIRECTIONAL_BLOWUP_Z && $fundLeverage > self::LORE_DIRECTIONAL_BLOWUP_LEVERAGE_THRESHOLD) {
            $eventType = ShockEvent::HF_DIRECTIONAL_BLOWUP;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * E[max(0, a Z + g (max(0, Z - h) - E[max(0, Z - h)]))] at hurdle h: an alpha stream's mean beat over its
     * baseline at a neutral cycle, as a share of it, its alpha line with the centred fee call on top.
     */
    private static function expectedStreamBeat(float $alphaSlope, float $feeSlope): float
    {
        $averageFeeExcess = Distributions::calculateNormalLowerPartialMoment(-self::PERFORMANCE_FEE_HURDLE_Z);

        return Distributions::normalKinkedPositivePart($alphaSlope, $feeSlope, self::PERFORMANCE_FEE_HURDLE_Z, -$feeSlope * $averageFeeExcess);
    }

    public function calculateCashYield(MacroStateDTO $macroState): float
    {
        $bondReturn = $macroState->yield10yEma;
        $equityReturn = $this->resolveEquitySleeveReturn($macroState, self::EQUITY_RETURN_GAP_MULT * self::LEVERAGED_SLEEVE_GAP_SCALE);

        return (self::PORTFOLIO_BOND_ALLOCATION * $bondReturn) + (self::PORTFOLIO_EQUITY_ALLOCATION * $equityReturn);
    }

    protected function resolveTreasuryEquityWeight(): float
    {
        return self::PORTFOLIO_EQUITY_ALLOCATION;
    }

    protected function resolveTreasuryEquityGapMultiplier(): float
    {
        return self::EQUITY_RETURN_GAP_MULT * self::LEVERAGED_SLEEVE_GAP_SCALE;
    }

    /**
     * A fund borrows from its prime broker against its own portfolio, so its lender holds a secured claim: the senior
     * secured recovery, 62% (CorporateDefaultService; Moody's ultimate recoveries 1987-2021, 61.5%).
     */
    public function getLossGivenDefault(): float
    {
        return 1.0 - \App\Service\Market\Bond\CorporateDefaultService::RECOVERY_SENIOR_SECURED;
    }

    public function calculateDebtExpansionCapacity(
        float $equity,
        float $totalDebt,
        float $wholesaleDebt,
        DebtHealthDTO $health,
        float $newBorrowingRate,
        float $ebit,
        float $depreciation,
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
        float $currentTreasury = 0.0,
    ): DebtExpansionAppetiteDTO {
        return new DebtExpansionAppetiteDTO(probability: self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), aggressiveness: self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier));
    }

    public function getAcquisitionType(string $defaultType): string
    {
        return 'HOSTILE TAKEOVER';
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
            'macro_credit_spread_ema',
            'market_volatility_ema',
            'money_supply_growth_ema',
            'money_supply_growth_trend',
            'output_gap_ema',
            'yield_10y_ema',
        ];
    }
}
