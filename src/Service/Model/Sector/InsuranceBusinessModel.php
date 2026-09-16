<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\StreamContext;
use App\DTO\InterestExpenseDTO;
use App\DTO\DebtExpansionAppetiteDTO;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Insurance companies.
 * 
 * Financial Physics:
 * - Revenue (Premiums) is highly sticky and predictable.
 * - Variance comes from Catastrophes (Claims/Underwriting losses).
 * - Structural profits come from "The Float" (investing premium cash before it's paid out).
 * - Evaluated on Return on Equity (ROE) rather than ROIC.
 */
class InsuranceBusinessModel extends BaseFinancialBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Premium volume is sticky through the cycle. */
    public const OPERATING_CYCLICALITY = 0.70;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Underwriting, claims and distribution payroll is roughly half of an insurer's overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.50;

    // --- Demand Transmission Lag ---
    /** Years for a move in the output gap to reach the order book. Policies are annual: exposure only reprices as the book comes up for renewal. */
    public const DEMAND_LAG_YEARS = 0.50;

    // --- Reporting Incentives ---
    /** Propensity to steer reported earnings toward consensus with accruals. Loss reserves are an actuarial estimate management sets itself: strengthening or releasing them moves the headline without touching cash. */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.60;

        public function getMoatSpread(): float { return 0.01; }

    // --- The Kenney Rule & Capacity Limits ---
    /** Standard Premium-to-Surplus capacity ratio required to maintain strong credit ratings. */
    public const KENNEY_CAPACITY_RATIO    = 1.50;
    /** Implied runoff equity fraction of customer deposit float allowed for insolvent insurers. */
    public const IMPLIED_RUNOFF_EQUITY    = 0.10;
    /** Default maximum financial leverage (Debt/Equity) limit if sector configuration is absent. */
    public const DEFAULT_EQUITY_LIMIT     = 10.00;
    /** Baseline logistic inflection point (80% of equity limit) where capacity tightens for pure corporate debt. */
    public const CAPACITY_INFLECTION_BASE = 0.80;
    /** Inflection shift (10%) moving the regulatory midpoint to 80% for volatile policyholder float. */
    public const CAPACITY_INFLECTION_FLOAT_SHIFT = 0.10;
    /** Steepness (12.0) of NAIC statutory capital constraints and rating agency capacity clamping around real sector limits. */
    public const CAPACITY_LOGISTIC_STEEPNESS = 12.0;
    /** Minimum fraction of prior revenue retained during hard market pricing (post-catastrophe capacity support). */
    public const HARD_MARKET_REVENUE_FLOOR = 0.85;

    // --- Solvency & Regulatory Capital Thresholds ---
    /** Minimum capital ratio (Equity / Assets) before regulatory balance sheet insolvency (Equity <= 0). */
    public const BANKRUPT_EQUITY_THRESHOLD = 0.0;
    /** Statutory capital ratio threshold below which insurer enters regulatory capital distress. */
    public const DISTRESS_EQUITY_THRESHOLD = 2.0;
    /** Statutory capital ratio threshold for regulatory grey/early warning watch. */
    public const WARNING_EQUITY_THRESHOLD  = 4.0;

    // --- ROIC & ROE Target Architecture ---
    /** Weight given to historical baseline ROIC when blending with TTM ROE. */
    public const BASELINE_ROIC_WEIGHT     = 0.50;
    /** Weight given to TTM ROE when blending with historical baseline ROIC. */
    public const TTM_ROIC_WEIGHT          = 0.50;
    /** Weight given to TTM ROE when scaling reversion speed kappa in financial models. */
    public const TTM_ROE_WEIGHT           = 0.50;
    /** Minimum structural through-the-cycle ROE floor for TTM valuation to prevent catastrophe whipsaw. */
    public const MIN_STRUCTURAL_ROE_FLOOR = 0.03;

    // --- Underwriting & Catastrophe Shock Physics ---
    /** Macroeconomic demand shift sensitivity to output gap. */
    public const MACRO_DEMAND_SCALAR      = 0.25;
    /** Underwriting operating margin mean reversion speed (quarters). Insurance policies renew annually with rapid competitive repricing. */
    public const INSURANCE_REVERSION_SPEED = 8.0;
    /** Volatility multiplier for top-line premium revenue shocks in sticky insurance markets. */
    public const REVENUE_VARIANCE_SCALAR  = 0.05;
    /** Baseline fraction of variable underwriting expenses attributed to operating and policy acquisition expenses (Expense Ratio share). */
    public const BASE_EXPENSE_RATIO_SHARE = 0.35;
    /** Catastrophe claim z-score threshold triggering severe underwriting combined ratio penalties. */
    public const CATASTROPHE_Z_THRESHOLD  = -1.50;
    /** Underwriting loss multiplier applied to catastrophe claim severity. */
    public const CATASTROPHE_LOSS_SCALAR  = 0.15;
    /** Benign underwriting environment z-score threshold triggering minor margin bonuses. */
    public const BENIGN_CLAIM_Z_FLOOR     = 1.50;

    // --- Underwriting Cycle (Winter 1994 / Gron 1994 capacity constraint) ---
    /** Regime key for the hard market: the multi-year stretch of rate increases and tightened terms that follows a capital shock. */
    public const REGIME_HARD_MARKET = 'hard_market';
    /** Surplus shortfall against the Kenney target at which capacity withdraws and the market turns hard. */
    public const HARD_MARKET_ONSET_SURPLUS_DEFICIT = 0.10;
    /** Quarterly probability the hard market breaks as capital returns and price competition resumes (~10 quarter expected duration). */
    public const HARD_MARKET_EXIT_HAZARD = 0.10;
    /** Peak premium rate uplift in the opening quarters of a hard market, before returning capacity erodes it. */
    public const HARD_MARKET_PRICING_UPLIFT = 0.25;
    /** Quarters over which the rate uplift decays as capital rebuilds, even while the regime itself persists. */
    public const HARD_MARKET_UPLIFT_DECAY_QUARTERS = 8.0;

    // --- Catastrophe Seasonality ---
    /** Relative catastrophe frequency by calendar quarter [Q1..Q4], summing to 4.0: Q3 carries the Atlantic wind season, Q1 the winter freeze and storm peak. */
    public const CATASTROPHE_SEASONALITY = [0.80, 0.70, 1.90, 0.60];
    /** Minor variable cost reduction during exceptionally benign underwriting environments. */
    public const BENIGN_CLAIM_BONUS       = -0.08;
    /** Cummins & Danzon (1997) soft-market underwriting combined ratio compression sensitivity to high float yields. */
    public const SOFT_MARKET_CYCLE_BETA   = 1.50;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Event Lore Thresholds ---
    /** Severe claim z-score threshold indicating major systemic disaster and catastrophic claim losses. */
    public const LORE_SYSTEMIC_DISASTER_Z = -2.00;
    /** Severe claim z-score threshold indicating elevated claim payouts and underwriting margin pressure. */
    public const LORE_ELEVATED_CLAIMS_Z   = -1.50;

    // --- Reinsurance & Attachment Physics ---
    /** Catastrophe z-score threshold where Excess of Loss (XOL) reinsurance treaties attach (`Z < -2.50`). */
    public const REINSURANCE_ATTACHMENT_Z = -2.50;
    /** Maximum net underwriting loss shock absorbed by primary insurer after reinsurance recovery cap. */
    public const MAX_REINSURED_LOSS_SHOCK = 0.375;
    /** Quarterly surcharge rate per unit of reinsurance recovery amortized during hard market renewals. */
    public const REINSURANCE_HARD_MARKET_RATE = 0.04;

    // --- Loss Reserve & Investment Portfolio Physics ---
    /** Target operating cash reserve ratio applied to corporate operating base. */
    public const TARGET_OPERATING_BUFFER  = 0.05;
    /** Target operating cash reserve ratio applied to customer deposit float. */
    public const TARGET_FLOAT_BUFFER      = 1.00;
    /** Minimum emergency operating cash reserve ratio applied to corporate operating base. */
    public const MIN_OPERATING_BUFFER     = 0.03;
    /** Minimum emergency operating cash reserve ratio applied to customer deposit float. */
    public const MIN_FLOAT_BUFFER         = 0.15;
    /** Threshold ratio of excess cash over total debt triggering hoarder status. */
    public const HOARDER_THRESHOLD        = 0.20;
    /** Threshold ratio of excess cash over total debt triggering mega-hoarder status. */
    public const MEGA_HOARDER_THRESHOLD   = 0.50;

    // --- Buybacks & Capital Deployment ---
    /** Fraction of excess cash allocated to buybacks for mega-hoarder insurers. */
    public const MEGA_BUYBACK_CASH_SHARE  = 0.30;
    /** Fraction of excess cash allocated to buybacks for standard insurers. */
    public const STANDARD_BUYBACK_SHARE   = 0.15;
    /** Maximum buyback spend multiplier relative to quarterly retained earnings. */
    public const MAX_RETAINED_BUYBACK_MULT = 0.40;
    /** Infinite interest coverage fallback for insurance companies without operating debt. */
    public const INFINITE_ICR_FALLBACK    = 999.0;
    /** Minimum fraction of newly issued debt that must be deployed into organic capex or platform growth. */
    public const DEBT_CAPEX_DEPLOYMENT    = 0.80;
    /** Baseline probability of initiating debt expansion when spreads are neutral. */
    public const DEBT_EXPANSION_BASE_PROB = 0.40;
    /** Multiplier scaling debt expansion probability with spread attractiveness. */
    public const DEBT_EXPANSION_PROB_MULT = 0.30;
    /** Baseline aggressiveness fraction for new debt issuance. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.02;
    /** Multiplier scaling debt issuance aggressiveness with spread attractiveness. */
    public const DEBT_EXPANSION_AGGR_MULT = 0.08;

    // --- Float Portfolio Yield (15/75/10 Allocation) ---
    /** Default policy rate fallback when macroeconomic state data is missing. */
    public const DEFAULT_POLICY_RATE_FALLBACK = 0.02;
    /** Default 10Y Treasury spread over policy rate. */
    public const DEFAULT_10Y_SPREAD       = 0.01;
    /** Default Equity Risk Premium (ERP) fallback when macroeconomic data is absent. */
    public const DEFAULT_ERP_FALLBACK     = 0.045;
    /** Equity market return sensitivity to macroeconomic output gaps. */
    public const EQUITY_RETURN_GAP_MULT   = 1.50;
    /** Weight allocated to short-term T-Bills and liquid cash in float portfolios. */
    public const FLOAT_LIQUIDITY_WEIGHT   = 0.15;
    /** Weight allocated to core long-duration fixed income bonds in float portfolios. */
    public const FLOAT_BOND_WEIGHT        = 0.75;
    /** Weight allocated to public growth equities in float portfolios. */
    public const FLOAT_EQUITY_WEIGHT      = 0.10;
    /** Quarterly volatility of the float equity tranche (annual equity vol ~20% ÷ sqrt(4) = ~10%). */
    public const EQUITY_PORTFOLIO_VOL     = 0.10;
    /** VIX threshold above which catastrophe-correlated equity portfolio losses begin. Market panic = equity crashes. */
    public const CATASTROPHE_VIX_THRESHOLD = 0.25;
    /** Sensitivity of equity portfolio drag to VIX above threshold. A VIX of 0.85 (COVID) causes ~18% tranche drag. */
    public const CATASTROPHE_EQUITY_CORRELATION = 0.30;

    // --- Valuation & Fair Value Weights ---
    /** Weight given to book value in profitable quarters (50% Book / 35% Earnings / 15% DDM). */
    public const FAIR_VALUE_BOOK_POS_EPS        = 0.50;
    /** Weight given to book value in catastrophe loss quarters (100% Book Value anchor). */
    public const FAIR_VALUE_BOOK_NEG_EPS        = 1.00;
    /** Weight given to Dividend Discount Model yield support when blending insurance fair value. */
    public const FAIR_VALUE_DDM_WEIGHT          = 0.15;
    /** Franchise floor multiplier applied to revenue floor value for sticky premium & float franchise. */
    public const PREMIUM_FRANCHISE_FLOOR_MULT   = 0.70;

    // --- Passive Liability Growth & Float Expansion ---
    /** Default annual inflation rate fallback for systemic float growth calculations. */
    public const DEFAULT_INFLATION_FALLBACK = 0.02;
    /** Baseline structural annual economic growth addition for insurance float expansion. */
    public const BASE_ECONOMIC_GROWTH_ADD = 0.02;
    /** Output gap multiplier scaling systemic float growth during economic expansions. */
    public const EXPANSION_GAP_MULT       = 0.50;
    /** Output gap multiplier scaling systemic float contraction during economic recessions. Insurance float is sticky. */
    public const RECESSION_GAP_MULT       = 0.50;
    /** Quarterly conversion divisor for annual systemic float growth rates. */
    public const QUARTERLY_GROWTH_DIVISOR = 4.00;
    /** Minimum stock beta clamp applied to liability float growth sensitivity. */
    public const MIN_BETA_GROWTH_CLAMP    = 0.75;
    /** Maximum stock beta clamp applied to liability float growth sensitivity. */
    public const MAX_BETA_GROWTH_CLAMP    = 1.25;
    /** Maximum allowable quarterly liability float change clamp. */
    public const MAX_FLOAT_CHANGE_CLAMP   = 0.15;
    /** Minimum allowable quarterly liability float change clamp. */
    public const MIN_FLOAT_CHANGE_CLAMP   = -0.15;
    /** Standard deviation of random noise applied to quarterly liability float growth. */
    public const FLOAT_GROWTH_NOISE_STD   = 0.005;
    /** Event shock penalty applied when catastrophe claim payouts cause cash insolvency. */
    public const EVENT_SHOCK_INSOLVENCY   = -5.00;
    /** Threshold fraction of policy roll-offs triggering negative underwriting lore (2.5% quarterly drop). */
    public const LORE_ROLLOFF_THRESHOLD   = -0.025;
    /** Threshold fraction of new premium capture triggering positive underwriting lore (2.5% quarterly gain). */
    public const LORE_CAPTURE_THRESHOLD   = 0.025;
    /** Event shock penalty applied during significant quarterly policy roll-offs. */
    public const EVENT_SHOCK_ROLLOFF      = -1.00;
    /** Event shock bonus applied during significant quarterly new premium capture. */
    public const EVENT_SHOCK_CAPTURE      = 0.50;

    // --- Balance Sheet Capacity & Leverage Decay ---
    /** Baseline capacity modifier ceiling when wholesale debt leverage is near zero. */
    public const CAPACITY_MODIFIER_CEILING    = 2.50;
    /** Minimum allowable capacity modifier floor during severe wholesale debt distress. */
    public const CAPACITY_MODIFIER_FLOOR      = 0.01;
    /** Base exponential decay rate applied to wholesale leverage utilization. */
    public const CAPACITY_BASE_DECAY_RATE     = 0.50;
    /** Multiplier scaling capacity decay sensitivity to wholesale debt utilization. */
    public const CAPACITY_UTIL_DECAY_MULT     = 1.50;

    /**
     * Reverse engineers the required operating metrics based on Balance Sheet Capacity.
     * Insurance revenue (Premiums) is strictly constrained by Surplus Equity (The Kenney Rule).
     *
     * @param Stock       $stock       The insurance stock entity being evaluated.
     * @param MacroStateDTO $macroState  The current macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility for engine operations.
     * @return array{invested_capital: float, baseline_roic: float} Target operating metrics.
     */
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // --- THE CLEAR BALANCE SHEET MATH ---
        // 1. Capacity Constraint: Revenue must NEVER be reverse-engineered from target EBIT.
        // It must be mathematically clamped to the firm's physical capital to prevent hyperinflation.

        // Standard Premium-to-Surplus ratio is 1.5x (maintains strong credit ratings).
        $capacityRatio = self::KENNEY_CAPACITY_RATIO;
        // Prevent zombie state: Regulators allow insolvent insurers to operate in runoff using a fraction of their float as implied equity
        $impliedRunoffEquity = (float) $stock->getCustomerDeposits() * self::IMPLIED_RUNOFF_EQUITY;
        $operatingEquity = max(max(1.0, $impliedRunoffEquity), $equity);

        $taxRate = $macroState->corporateTaxRate;

        // 2. Structural Revenue is anchored strictly to their capacity limit and required policy reserves.
        $targetRevenue = $operatingEquity * $capacityRatio;
        // Hard Market Revenue Floor: post-catastrophe pricing power prevents revenue from collapsing
        // proportionally with surplus. Industry-wide capacity depletion supports premium rates.
        $priorRevenue = (float) $stock->getTotalRevenue();
        $targetSurplusForPriorRevenue = $priorRevenue / $capacityRatio;
        $surplusAdequacy = $targetSurplusForPriorRevenue > 0.0 ? min(1.0, $operatingEquity / $targetSurplusForPriorRevenue) : 1.0;
        $targetRevenue = max($targetRevenue, $priorRevenue * self::HARD_MARKET_REVENUE_FLOOR * $surplusAdequacy);

        // 3. The engine requires Baseline ROIC, which implies a specific Asset Turnover.
        // Turnover = Revenue / Invested Capital
        $impliedTurnover = $targetRevenue / $operatingEquity;
        $capacityRoic = ($impliedTurnover * $stableMargin) * (1.0 - $taxRate);
        $baselineRoic = $capacityRoic;

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            // Apply structural floor locally when deriving baseline capacity return so catastrophe losses do not collapse required underwriting turnover
            $structuralRoe = max(self::MIN_STRUCTURAL_ROE_FLOOR, $ttmRoe);
            // The blend may only ever LOWER the premium book, never raise it. What comes back from here is
            // divided by the after-tax underwriting margin to recover a premium turnover, and TTM ROE is a
            // return on EQUITY that the policyholder float has already levered several times over: blending
            // it in unclamped writes premium against investment income and defeats the Kenney capacity
            // constraint struck above. Measured on the reinsurance book, an insurer earning its float yield
            // on 3x leverage wrote at 1.9x surplus against a 1.5x cap.
            $baselineRoic = min($capacityRoic, ($capacityRoic * self::BASELINE_ROIC_WEIGHT) + ($structuralRoe * self::TTM_ROIC_WEIGHT));
        }

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        // The cost of capital is a floor under the RETURN, never under the BOOK. Capacity is a balance-sheet
        // fact: an underwriter whose margin cannot earn its hurdle on the premium its surplus supports does
        // not answer by writing more of it. Left uncapped this floor re-opened the constraint the blend
        // above respects — at a 4% underwriting margin the implied turnover came back as 2.1x surplus, and
        // at 2% as 4.1x, purely because the floor was being divided by a thinner margin downstream.
        $baselineRoic = min($capacityRoic, max($waccBase, $baselineRoic - $saturationPenalty));

        return [
            'invested_capital' => $operatingEquity,
            'baseline_roic'    => $baselineRoic
        ];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $this->resolveLaggedOutputGap($stock, $macroState);
        $beta = $this->getOperatingCyclicality($stock);

        // Cash-flow underwriting (Cummins & Danzon 1997): when float yields are high insurers discount
        // premium to gather investable money, so a high policy rate softens rates on its own.
        $policyRate = $macroState->policyRateEma;
        $softMarketRateDiscount = max(0.0, ($policyRate - self::DEFAULT_POLICY_RATE_FALLBACK) * self::SOFT_MARKET_CYCLE_BETA);

        // The capacity cycle sits on top of it and dominates. The regime clock is advanced by this model's
        // own physics (see calculateSectorPhysics) and read back here a quarter later, which is right:
        // rates reset at renewal, after the loss that withdrew the capacity.
        $pricingPower = 1.0 + $this->resolveHardMarketUplift($stock) - min(0.50, $softMarketRateDiscount);

        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR, // Highly immune to macro demand
            'pricing_power_multiplier' => $pricingPower,
        ];
    }

    /**
     * Premium rate uplift from an active hard market, decaying over the quarters the regime has run.
     *
     * Read from the persisted regime clock rather than a stream context, because pricing is resolved
     * before this quarter's physics: what an insurer can charge at renewal is set by the capital position
     * the market was left in, not by a loss that has not happened yet.
     */
    private function resolveHardMarketUplift(Stock $stock): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $quarters = (int) round((float) ($momentum[StreamContext::REGIME_STATE_PREFIX . self::REGIME_HARD_MARKET] ?? 0.0));

        if ($quarters <= 0) {
            return 0.0;
        }

        $decay = max(0.0, 1.0 - (($quarters - 1) / self::HARD_MARKET_UPLIFT_DECAY_QUARTERS));

        return self::HARD_MARKET_PRICING_UPLIFT * $decay;
    }

    /**
     * Models the "Catastrophe Physics". Premium top-line revenue barely moves,
     * but cost margins can explode due to unpredictable massive claim payouts (Hurricanes, Mass Torts).
     *
     * @param Stock       $stock                  The insurance stock entity.
     * @param float       $expectedRevenue        The baseline expected revenue.
     * @param float       $realizedVariableMargin The expected variable cost margin.
     * @param float       $fixedCosts             The absolute fixed costs of operations.
     * @param float       $baselineVol            The stock's historical volatility.
     * @param MacroStateDTO $macroState             The current macroeconomic state.
     * @param MathUtility $mathUtility            Mathematical utility for Z-score generation.
     * @return SectorPhysicsResult
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned underwriting parameters
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CatastropheZThreshold->value => self::CATASTROPHE_Z_THRESHOLD,
            ModelParam::CatastropheLossScalar->value => self::CATASTROPHE_LOSS_SCALAR,
        ]);

        $catThreshold = $params[ModelParam::CatastropheZThreshold];
        $catScalar    = $params[ModelParam::CatastropheLossScalar];

        // 1. Premium Revenue Shock
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // Premium volume loads on the firm and sector demand factors; claims are exogenous and near i.i.d.
        $revenueZ = $streams->generateZ('revenue', 0.25);
        $claimZ   = $streams->generateExogenousZ('claim', 0.05);

        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // 2. Separation of Loss Ratio vs. Expense Ratio (The Combined Ratio)
        // Baseline decomposition: Total variable cost margin is composed of Loss Ratio (claims) + Expense Ratio (acquisition/admin).
        $baselineExpenseRatio = $realizedVariableMargin * self::BASE_EXPENSE_RATIO_SHARE;
        $baselineLossRatio    = $realizedVariableMargin * (1.0 - self::BASE_EXPENSE_RATIO_SHARE);

        // A. Expense Ratio Dynamics:
        // Policy acquisition and administrative overhead costs are sticky relative to expected baseline revenue.
        // If top-line revenue fluctuates, the realized expense ratio scales inversely with written premiums.
        $expenseScale = $expectedRevenue > 0.0 ? ($expectedRevenue / max(1.0, $actualRevenue)) : 1.0;
        $realizedExpenseRatio = $baselineExpenseRatio * $expenseScale;

        // B. Loss Ratio Dynamics (Catastrophes/Underwriting Cycle):
        // Catastrophe Risk Beta: high-catastrophe insurers earn higher premium margins in benign years.
        // Combines both Frequency ($catThreshold) and Severity ($catScalar) to price expected tail risk.
        $frequencyBeta = self::CATASTROPHE_Z_THRESHOLD / min(-0.1, $catThreshold);
        $severityBeta  = $catScalar / self::CATASTROPHE_LOSS_SCALAR;
        $catRiskBeta   = $frequencyBeta * $severityBeta;
        $benignBonus   = self::BENIGN_CLAIM_BONUS * $catRiskBeta;
        // Incorporate Property Valuation Inflation on claim costs
        $creShift = ($macroState->commercialPropertyIndexEma - 100.0) / 100.0;
        $resShift = ($macroState->residentialPropertyIndexEma - 100.0) / 100.0;
        $propertyClaimInflation = max(0.0, ($creShift * 0.50) + ($resShift * 0.50)) * 0.05; // Modest drag on variable margin when property replacement values surge

        // Catastrophes are seasonal but premiums are not: a hurricane season does not sell more policies,
        // it makes a loss more likely against premiums already written. The season therefore moves the
        // frequency threshold, never revenue — the same z-draw clears a shallower bar in Q3 than in Q4.
        // The firm's structural exposure ($frequencyBeta above) stays on the unseasonalized threshold,
        // because how exposed a book is does not change with the calendar.
        $seasonalCatFrequency = self::CATASTROPHE_SEASONALITY[$macroState->calendarQuarter()] ?? 1.0;
        $seasonalCatThreshold = $catThreshold / max(0.10, $seasonalCatFrequency);

        $lossRatioShock = ($claimZ < $seasonalCatThreshold
            ? abs($claimZ) * $catScalar
            : ($claimZ > self::BENIGN_CLAIM_Z_FLOOR ? $benignBonus : 0.0)) + $propertyClaimInflation;

        // 3. Cummins & Danzon (1997) Soft-Market Underwriting Offset:
        // When interest rates and float yields boom above baseline, price competition intensifies across the industry
        // as insurers discount premium rates to capture market share and gather float (The Soft Underwriting Cycle).
        // (This effect is fully captured upstream in getMacroPhysics via pricing_power_multiplier to ensure accurate market expectations)

        // 4. Cummins & Danzon (1997) Hard-Market Capital Recovery:
        // When an insurer's capital surplus drops below its Kenney target (Equity < Target Surplus),
        // the insurer enters a Hard Market—raising premium rates and tightening underwriting criteria
        // to rebuild surplus capital.
        $equity = (float) $stock->getTotalEquity();
        $targetSurplus = $expectedRevenue / self::KENNEY_CAPACITY_RATIO;
        $surplusDeficitRatio = $targetSurplus > 0.0 ? max(0.0, ($targetSurplus - $equity) / $targetSurplus) : 0.0;
        // Strengthened Hard-Market pricing power scaled by company catastrophe exposure ($catRiskBeta):
        // Reinsurers absorbing higher frequency ($catThreshold) & severity ($catScalar) gain stronger post-disaster pricing power.
        $hardMarketRecoveryDiscount = min(0.30, $surplusDeficitRatio * 0.35 * $catRiskBeta);

        // The underwriting cycle is a CAPACITY cycle, not a rate cycle. A catastrophe destroys surplus,
        // capacity withdraws from the market, rates harden for years, and the returning capital that the
        // hard market attracts is what eventually softens them again (Winter 1994, Gron 1994). Modelling
        // it as a persistent regime rather than a function of this quarter's surplus is the point: rates
        // stay hard well after the capital is back, which is the discipline lag the cycle is named for.
        $streams->evolveRegime(self::REGIME_HARD_MARKET, 0.0, self::HARD_MARKET_EXIT_HAZARD);
        if ($surplusDeficitRatio >= self::HARD_MARKET_ONSET_SURPLUS_DEFICIT || $claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $streams->startRegime(self::REGIME_HARD_MARKET);
        }

        $reinsuranceSurcharge = 0.0;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $lossRatioShock = min($lossRatioShock, self::MAX_REINSURED_LOSS_SHOCK);
            $reinsuranceSurcharge = self::REINSURANCE_HARD_MARKET_RATE;
        }

        $realizedLossRatio = max(0.0, $baselineLossRatio + $lossRatioShock);
        $combinedRatio = $realizedLossRatio + $realizedExpenseRatio + $reinsuranceSurcharge - $hardMarketRecoveryDiscount;

        $clampedMargin = $this->clampMargin($combinedRatio);

        $eventType = null;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $eventType = ShockEvent::REINSURANCE_ATTACHMENT_BREACH;
        } elseif ($claimZ < self::LORE_SYSTEMIC_DISASTER_Z) {
            $eventType = ShockEvent::CATASTROPHIC_CLAIM_LOSSES;
        } elseif ($claimZ < self::LORE_ELEVATED_CLAIMS_Z) {
            $eventType = ShockEvent::ELEVATED_CLAIM_PAYOUTS;
        }

        // Only trigger a structural volatility shock if the claim variance is an actual catastrophe.
        $structuralClaimShock = abs($claimZ) > abs(self::CATASTROPHE_Z_THRESHOLD) ? $claimZ : 0.0;
        $primaryShockZ = $streams->resolveDominantShockZ([$structuralClaimShock, $revenueZ]);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: 0.0,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'premium_revenue' => $actualRevenue,
            ],
        );
    }

    public function getMarginReversionSpeed(): float
    {
        // 12-month annual policy contracts face rapid competitive repricing at each renewal cycle
        return self::INSURANCE_REVERSION_SPEED;
    }

    /**
     * Insurance companies invest their massive Float in long-duration bonds.
     * The 10% equity tranche introduces stochastic quarterly returns and correlates with
     * catastrophe events via VIX: major disasters cause concurrent market crashes (9\/11, COVID).
     *
     * @param Stock       $stock       The insurance stock entity.
     * @param MacroStateDTO $macroState  The current macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility.
     * @return float The total absolute interest income generated by the portfolio.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        // Resolve company-specific tuned float allocation parameters
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FloatEquityWeight->value => self::FLOAT_EQUITY_WEIGHT,
        ]);

        $floatEquityWeight = $params[ModelParam::FloatEquityWeight];
        $cash              = (float) $stock->getCorporateTreasury();
        $policyRate        = $macroState->policyRateEma;
        $yield10y          = $macroState->yield10yEma;

        // Normalized Base Fixed-Income Yield (Liquidity + Long Bonds scaled to sum to 1.0 - float_equity_weight)
        $fixedIncomeWeight = max(0.0, 1.0 - $floatEquityWeight);
        $totalFixedWeight  = max(0.01, self::FLOAT_LIQUIDITY_WEIGHT + self::FLOAT_BOND_WEIGHT);
        $liquidityShare    = self::FLOAT_LIQUIDITY_WEIGHT / $totalFixedWeight;
        $bondShare         = self::FLOAT_BOND_WEIGHT / $totalFixedWeight;

        $liquidityReturn   = $policyRate - MacroEngine::CASH_YIELD_SPREAD;
        $bondReturn        = $yield10y;
        $baseYield         = $fixedIncomeWeight * (($liquidityShare * $liquidityReturn) + ($bondShare * $bondReturn));

        $outputGap = $macroState->outputGapEma;
        $erp = $macroState->equityRiskPremium;

        // Expected equity tranche return during continuous tick valuation ($equityPortfolioZ = 0.0).
        // This prevents high-frequency distress penalty whipsaws when analyzeDebtHealth() evaluates float income.
        $stochasticEquityReturn = ($policyRate + $erp) + ($outputGap * self::EQUITY_RETURN_GAP_MULT);

        // Catastrophe-Equity Correlation:
        // Major disasters (9/11, COVID, GFC) simultaneously cause high claims AND equity market crashes.
        // VIX is a reliable real-time proxy: panic-level VIX (>25%) reliably accompanies both catastrophes
        // and broad equity drawdowns. This correlation is the channel the model exploits.
        $vixEma = $macroState->marketVolatilityEma;
        $catastropheEquityPenalty = max(0.0, ($vixEma - self::CATASTROPHE_VIX_THRESHOLD) * self::CATASTROPHE_EQUITY_CORRELATION);
        $stochasticEquityReturn -= $catastropheEquityPenalty;

        $floatYield = $baseYield + ($floatEquityWeight * $stochasticEquityReturn);

        $policyholderFloat = (float) $stock->getCustomerDeposits();
        $investableFloat = min($cash, $policyholderFloat);
        $excessCash = max(0.0, $cash - $investableFloat);

        $moneyMarketYield = max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);

        return ($investableFloat * $floatYield) + ($excessCash * $moneyMarketYield);
    }

    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float
    {
        // Structural floor: float investment income provides a through-the-cycle baseline
        // that catastrophe underwriting losses should not erase entirely.
        $equity = (float) $stock->getTotalEquity();
        $roeTtm = (float) $stock->getRoeTtm();
        $shares = max(1.0, (float) $stock->getSharesOutstanding());
        $structuralEps = $equity > 0 ? (($equity * max(0.0, $roeTtm)) / 4.0) / $shares : 0.0;

        // If quarterly EPS is negative and capital surplus is impaired (equity below Kenney target surplus),
        // or if quarterly losses exceed structural float earnings, halt distributions to preserve solvency.
        $targetSurplus = (float) $stock->getTotalRevenue() / self::KENNEY_CAPACITY_RATIO;
        if ($quarterlyEps < 0.0 && ($equity < $targetSurplus || abs($quarterlyEps) > $structuralEps)) {
            return 0.0;
        }

        // Use the higher of actual EPS and structural through-cycle EPS,
        // but NEVER exceed actual EPS when actual is positive (don't overpay)
        return $quarterlyEps > 0 ? $quarterlyEps : max($quarterlyEps, $structuralEps * 0.50);
    }

    /**
     * Calculates the target operating cash required for the insurance business.
     *
     * @param float $operatingBase    The base operating expenses.
     * @param float $currentLiability The current liabilities (customer deposits / float).
     * @param float $wholesaleDebt    The wholesale debt balance.
     * @return float The target operating cash to maintain.
     */
    /** Float above the regulatory surplus buffer is invested, not held. */
    public function deploysFundingIntoEarningAssets(): bool
    {
        return true;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // Target 100% of Customer Deposits to maintain a strict regulatory surplus buffer.
        // This prevents the company from buying back shares until they have a solid safety net against catastrophes.
        return max($operatingBase * self::TARGET_OPERATING_BUFFER, $currentLiability * self::TARGET_FLOAT_BUFFER);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::MIN_OPERATING_BUFFER, $currentLiability * self::MIN_FLOAT_BUFFER);
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($totalDebt * self::HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($totalDebt * self::MEGA_HOARDER_THRESHOLD),
        ];
    }

    /**
     * Winter-Cummins (1994/1997) Logistic Capacity Model.
     * 
     * Replaces exponential decay with sigmoidal statutory surplus elasticity: abundant capacity when surplus 
     * is strong, inflecting smoothly at regulatory midpoints, and collapsing when statutory limits are breached.
     */
    public function calculateFloatCapacityMultiplier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float
    {
        $utilization = $equity > 0.0 ? ($totalDebt / ($equity * $equityLimit)) : 1.0;
        $floatRatio = $totalDebt > 0 ? (($coreLiabilities ?? 0.0) / $totalDebt) : 0.0;

        $inflectionPoint = self::CAPACITY_INFLECTION_BASE - (self::CAPACITY_INFLECTION_FLOAT_SHIFT * $floatRatio);
        $elasticity = self::CAPACITY_LOGISTIC_STEEPNESS;

        $logisticSpread = self::CAPACITY_MODIFIER_CEILING - self::CAPACITY_MODIFIER_FLOOR;
        $capacityModifier = self::CAPACITY_MODIFIER_FLOOR + ($logisticSpread / (1.0 + exp($elasticity * ($utilization - $inflectionPoint))));

        return max(self::CAPACITY_MODIFIER_FLOOR, min(self::CAPACITY_MODIFIER_CEILING, $capacityModifier));
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * self::MEGA_BUYBACK_CASH_SHARE : min($excessCash * self::STANDARD_BUYBACK_SHARE, $retainedEarningsThisQuarter * self::MAX_RETAINED_BUYBACK_MULT);
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): InterestExpenseDTO
    {
        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $interestExpense = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);
        return new InterestExpenseDTO(interestExpense: $interestExpense, wholesaleRate: $corporateDebt > 0 ? ($interestExpense / $corporateDebt) : $currentMarketFixedRate);
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
    {
        if ($interestExpense <= 0.0) {
            return self::INFINITE_ICR_FALLBACK;
        }

        // For an insurer, quarterly underwriting claim payouts (EBIT < 0) do not constitute corporate debt default
        // as long as investment/float yield and capital surplus service wholesale obligations.
        // When underwriting claim payouts turn total EBIT negative, evaluate coverage using depreciation and serviceable income.
        $serviceableIncome = $ebit < 0.0 ? max(0.0, $ebit + $interestExpense) : $ebit;
        return ($serviceableIncome + $depreciation) / max(0.01, $interestExpense);
    }

    /**
     * Returns the base float yield for the 15% liquidity + 75% long-duration bond tranches only.
     * The 10% equity tranche is handled stochastically in calculateInterestIncome().
     *
     * @param MacroStateDTO $macroState Current macroeconomic state.
     * @param float $policyRate Current policy interest rate.
     * @return float Blended base yield from the fixed-income portion of the float portfolio.
     */
    public function calculateCashYield(MacroStateDTO $macroState): float
    {
        $policyRate = $macroState->policyRateEma;
        $yield10y = $macroState->yield10yEma;

        // 1. Liquidity Reserve (15% T-Bills/Cash)
        $liquidityReturn = $policyRate - MacroEngine::CASH_YIELD_SPREAD;

        // 2. Core Fixed Income (75% Long-Duration Bonds)
        $bondReturn = $yield10y;

        // Normalized fixed-income yield (assuming baseline 10% equity tranche)
        $fixedIncomeWeight = max(0.0, 1.0 - self::FLOAT_EQUITY_WEIGHT);
        $totalFixedWeight  = max(0.01, self::FLOAT_LIQUIDITY_WEIGHT + self::FLOAT_BOND_WEIGHT);
        $liquidityShare    = self::FLOAT_LIQUIDITY_WEIGHT / $totalFixedWeight;
        $bondShare         = self::FLOAT_BOND_WEIGHT / $totalFixedWeight;

        return $fixedIncomeWeight * (($liquidityShare * $liquidityReturn) + ($bondShare * $bondReturn));
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO
    {
        return new DebtExpansionAppetiteDTO(probability: self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), aggressiveness: self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier));
    }

    /**
     * Insurance companies do not deploy physical CapEx. Underwriting capacity is governed by Surplus Equity
     * (Kenney Rule) and float yield is earned on Corporate Treasury cash, so organic expansion spend is 0.0.
     */
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return 0.0;
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Insurance companies should fund expansion using their premium float (excess cash) first
        return max(0.0, $baseCapacity - $excessCash);
    }
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        $franchiseFloor = $revenueFloorValue * self::PREMIUM_FRANCHISE_FLOOR_MULT;
        return max($franchiseFloor, $peFairValue);
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        if ($normalizedEps > 0.0) {
            $bookWeight = self::FAIR_VALUE_BOOK_POS_EPS;
            $earningsWeight = 1.0 - $bookWeight;
            $baseConsensus = ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
        } else {
            // Loss quarter: Full 100% anchor to Book Value (do NOT discard value by multiplying by 0.80)
            $baseConsensus = $pbFairValue;
        }

        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - self::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * self::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
    }

    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin): float
    {
        // DuPont Decomposition anchored by Kenney Rule capacity (Premium-to-Surplus ratio = 1.50)
        $actualTurnover = $bookValuePerShare > 0.0 ? ($revenuePerShare / $bookValuePerShare) : self::KENNEY_CAPACITY_RATIO;
        $effectiveTurnover = min(self::KENNEY_CAPACITY_RATIO, max(0.5, $actualTurnover));

        $structuralRoe = $effectiveTurnover * $baselineMargin;

        // Blend through-the-cycle structural capacity with actual TTM ROE
        $blendedRoe = ($structuralRoe * 0.70) + ($roicTtm * 0.30);
        return max(self::MIN_STRUCTURAL_ROE_FLOOR, $blendedRoe);
    }

    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits'];
        if ($currentLiabilities <= 0) return;

        $equity = (float) $stock->getTotalEquity();
        $totalDebt = $state['wholesaleDebt'] + $currentLiabilities;
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?: 'General']['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;

        // Nominal Systemic Growth: The Float grows naturally alongside the M2 Money Supply.
        $systemicGrowthQuarterly = ($macroState->inflationEma + self::BASE_ECONOMIC_GROWTH_ADD + (($macroState->outputGapEma > 0.0 ? $macroState->outputGapEma * self::EXPANSION_GAP_MULT : $macroState->outputGapEma * self::RECESSION_GAP_MULT))) / self::QUARTERLY_GROWTH_DIVISOR;

        // Premium-to-Surplus Capacity constraint (Kenney Rule) throttles growth if they don't have enough equity to back the policies.
        $capacityMultiplier = $this->calculateFloatCapacityMultiplier($totalDebt, $equity, $equityLimit, $currentLiabilities);
        
        // Capacity should only boost positive market capture. It should not accelerate shrinkage during recessions.
        $effectiveSystemicGrowth = $systemicGrowthQuarterly > 0.0 ? $systemicGrowthQuarterly * $capacityMultiplier : $systemicGrowthQuarterly;
        $baseGrowth = $effectiveSystemicGrowth * max(self::MIN_BETA_GROWTH_CLAMP, min(self::MAX_BETA_GROWTH_CLAMP, $this->getOperatingCyclicality($stock)));
        
        $effectiveNoise = ($mathUtility->generateStandardNormal() * self::FLOAT_GROWTH_NOISE_STD) * min(1.0, $capacityMultiplier);
        $liabilityChange = $currentLiabilities * max(self::MIN_FLOAT_CHANGE_CLAMP, min(self::MAX_FLOAT_CHANGE_CLAMP, $baseGrowth + $effectiveNoise));

        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;

            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $state['treasury'] = 0.0;
                $state['wholesaleDebt'] += $liquidityShortfall;
                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $state['events'][] = ['description' => "Catastrophe claim payouts exceeded cash reserves. Forced to borrow \${$amtB}B.", 'shock' => self::EVENT_SHOCK_INSOLVENCY];
            }

            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));
            if (($liabilityChange / $currentLiabilities) < self::LORE_ROLLOFF_THRESHOLD) $state['events'][] = ['description' => "Suffered \$" . number_format(abs($liabilityChange) / 1_000_000_000, 2) . "B in policy roll-offs.", 'shock' => self::EVENT_SHOCK_ROLLOFF];
            elseif (($liabilityChange / $currentLiabilities) > self::LORE_CAPTURE_THRESHOLD) $state['events'][] = ['description' => "Captured \$" . number_format($liabilityChange / 1_000_000_000, 2) . "B in new premium Float.", 'shock' => self::EVENT_SHOCK_CAPTURE];
        }
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // For Insurance companies, Customer Deposits represent policyholder reserves ("The Float").
        // Float scales with underwriting policy volume and claim payout schedules, not discretionary capital
        // structure financing. An insurer should never trigger forced "underleveraged" share buyback spirals
        // simply because its float-to-equity ratio fluctuates.
        return false;
    }

    public function getBankruptEquityThreshold(): float { return self::BANKRUPT_EQUITY_THRESHOLD; }
    public function getDistressEquityThreshold(): float { return self::DISTRESS_EQUITY_THRESHOLD; }
    public function getWarningEquityThreshold(): float { return self::WARNING_EQUITY_THRESHOLD; }

    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury): ?float
    {
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $totalAssets = max(1.0, $equity + $totalDebt);
        $capitalRatio = ($equity / $totalAssets) * 100.0;

        // Statutory NAIC / Solvency II capital distress lockout:
        // When capital ratio falls into regulatory distress (< 2.0%) or surplus is below 50% of Kenney target,
        // regulators mandate an immediate halt on all dividend distributions.
        $targetSurplus = (float) $stock->getTotalRevenue() / self::KENNEY_CAPACITY_RATIO;
        if ($capitalRatio < self::DISTRESS_EQUITY_THRESHOLD || $equity < ($targetSurplus * 0.50)) {
            return 0.0;
        }

        return parent::getRegulatoryDividendCap($stock, $currentTreasury);
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
            'commercial_property_index_ema',
            'inflation_ema',
            'market_volatility_ema',
            'output_gap_ema',
            'policy_rate_ema',
            'residential_property_index_ema',
            'yield_10y_ema',
        ];
    }
}
