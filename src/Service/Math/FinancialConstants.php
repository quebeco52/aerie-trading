<?php

namespace App\Service\Math;

/**
 * Defines core economic rules and limits for the simulation, making it easier to balance 
 * the simulation's boom/bust cycles without digging through mathematical physics files.
 */
class FinancialConstants
{
    // --- Earnings & Volatility Tuning ---
    /** Standardized earnings surprise Z-score threshold triggering extreme market reaction. */
    public const SURPRISE_Z_SCORE_THRESHOLD = 1.5;
    /** Z-score threshold below which quarterly earnings are considered inline/inconsequential. */
    public const BORING_Z_SCORE_THRESHOLD = 0.5;
    /** Additive base volatility shock applied during significant earnings surprises. */
    public const VOLATILITY_SHOCK_FACTOR = 0.2;
    /** Decay rate per quarter dissipating elevated idiosyncratic volatility back to baseline. */
    public const VOLATILITY_COOLING_FACTOR = 0.25;
    /** Absolute ceiling capping short-term volatility relative to baseline asset volatility. */
    public const MAX_VOLATILITY_MULTIPLIER = 3.0;
    /** Minimum operating capital floor ($10M) preventing zero-division in asset-light scaling. */
    public const MIN_OPERATING_BASE_CASH = 10000000.0;

    // --- Earnings Response Coefficient (ERC) ---
    /** Baseline earnings response intercept for unexpected earnings impact on market returns. */
    public const ERC_BASE_ALPHA = 0.0;
    /** Sensitivity coefficient dampening ERC as systematic market beta risk increases. */
    public const ERC_BETA_SENSITIVITY = -0.15;
    /** Sensitivity coefficient increasing ERC for high-growth premium equities. */
    public const ERC_GROWTH_SENSITIVITY = 0.25;

    // --- Bayesian Analyst Consensus ---
    /** Widest one-quarter change in the structural base the analyst anchor is rolled forward by (x0.5 to x2.0). A capacity cap binding or a collapse is not a growth rate analysts extrapolate. */
    public const ANALYST_ANCHOR_MAX_ROLL_FORWARD = 2.0;
    /** Stream-state key: the structural expected revenue the last consensus was formed against, so the analyst anchor can be carried forward with the base rather than frozen at last quarter's size. */
    public const STATE_LAST_EXPECTED_REVENUE = 'state:last_expected_revenue';
    /** Baseline prior uncertainty variance in market analyst earnings consensus formation (~0.06^2). */
    public const BAYESIAN_BASE_PRIOR_VARIANCE = 0.0036;
    /** Multiplier scaling analyst consensus prior uncertainty as VIX rises. */
    public const BAYESIAN_VIX_SCALING_FACTOR = 0.02;

    // --- Analyst Estimate Dispersion ---
    /** Calm-market volatility at which the per-sector analyst error standard deviations are calibrated. */
    public const DISPERSION_BASELINE_VOLATILITY = 0.15;
    /** Sensitivity of analyst estimate dispersion to market volatility above its calibration baseline. */
    public const DISPERSION_VIX_SENSITIVITY = 3.0;
    /** Ceiling on dispersion widening, so a volatility spike cannot drive the SUE denominator to infinity. */
    public const DISPERSION_MAX_SCALE = 2.50;

    // --- Leverage Effect (Black, 1976) ---
    /** Asymmetric leverage effect scalar magnifying volatility on negative earnings surprises. */
    public const NEGATIVE_SURPRISE_VOL_MULTIPLIER = 1.4;

    // --- Jump Diffusion (Fundamental vs Price) ---
    /** Scale factor for fundamental jump intensity relative to price jumps. */
    public const FUNDAMENTAL_JUMP_INTENSITY_SCALE = 0.25;
    /** Scale factor for fundamental jump mean size relative to price jumps. */
    public const FUNDAMENTAL_JUMP_MEAN_SCALE = 0.50;
    /** Scale factor for fundamental jump volatility relative to price jumps. */
    public const FUNDAMENTAL_JUMP_VOL_SCALE = 0.50;

    // --- SVJJ & Jump Diffusion Limits ---
    /** Maximum individual upside jump cap (+30% or log(1.30)) keeping market shocks bounded. */
    public const MAX_JUMP_LOG_RETURN = 0.2624;
    /** Minimum individual downside jump floor (-30% or log(0.70)) keeping market shocks bounded. */
    public const MIN_JUMP_LOG_RETURN = -0.3567;

    // --- EPS Smoothing ---

    // --- Price Gap Dampening ---
    /** Liquidity dampener slowing instantaneous price convergence to fundamental fair value. */
    public const PRICE_GAP_DAMPENING = 0.20;
    /** Maximum allowable single-quarter fundamental price gap adjustment. */
    public const MAX_PRICE_GAP = 0.25;

    // --- Cash Hoarding & Balances ---
    /** Target cash balance as a fraction of annual revenue needed for working capital. */
    public const TARGET_OPERATING_CASH_RATIO = 0.05;
    /** Minimum cash buffer floor before triggering liquidity distress protocols. */
    public const MIN_OPERATING_CASH_RATIO = 0.03;
    /** Cash-to-revenue ratio threshold classifying a corporate as a cash hoarder. */
    public const HOARDER_THRESHOLD_RATIO = 0.25;
    /** Cash-to-revenue ratio threshold classifying a firm as an aggressive mega cash hoarder. */
    public const MEGA_HOARDER_THRESHOLD_RATIO = 0.40;

    // --- Dynamic Revenue Mix Drift & Mean Reversion ---
    /** Adaptation speed scalar (alpha) at which quarterly realized revenue mix shifts active baseline weights. */
    public const DEFAULT_MIX_ADAPTATION_RATE = 0.15;
    /** Strategic mean reversion speed (kappa) pulling dynamic weights back toward long-term franchise target. */
    public const DEFAULT_MIX_REVERSION_SPEED = 0.08;
    /** Minimum structural floor for any business unit to prevent complete segment abandonment. */
    public const DEFAULT_MIN_STREAM_WEIGHT_FLOOR = 0.05;
    /** Maximum structural ceiling for any single business unit to prevent total monopoly capture. */
    public const DEFAULT_MAX_STREAM_WEIGHT_CEILING = 0.85;

    // --- Gordon Growth & Perpetual Valuation Bounds ---
    /** Absolute minimum hurdle rate (~4% COE) to prevent Gordon Growth divergence under extreme distress. */
    public const MIN_COST_OF_EQUITY = 0.04;
    /** Absolute perpetual growth floor (-5%) for contracting or liquidation-stage firms. */
    public const MIN_PERPETUAL_GROWTH_RATE = -0.05;
    /** Absolute perpetual growth ceiling (6%) to prevent exceeding long-term nominal GDP growth. */
    public const MAX_PERPETUAL_GROWTH_RATE = 0.06;

    // --- Valuation & Multiples ---
    /** Baseline long-run market equilibrium price-to-earnings multiple. */
    public const BASELINE_MARKET_PE = 15.0;
    /** Baseline long-term stable GDP growth rate for Gordon Growth valuation. */
    public const DEFAULT_PERPETUAL_GROWTH_RATE = 0.02;
    // --- Fundamental Growth Transmission ---
    /** Share of the output gap that reaches a firm's real growth rate, before its beta scales the cyclical exposure. */
    public const CYCLICAL_GROWTH_PASS_THROUGH = 0.50;
    /** Share of inflation that carries into the nominal growth rate used for valuation. */
    public const INFLATION_NOMINAL_GROWTH_PASS_THROUGH = 0.50;
    /** Cap on nominal expected growth, held below any plausible hurdle rate so the Gordon Growth denominator cannot diverge. */
    public const MAX_EXPECTED_GROWTH = 0.05;

    /** Absolute floor on intrinsic fundamental P/E multiple. */
    public const MIN_INTRINSIC_PE = 4.0;
    /** Absolute ceiling on intrinsic fundamental P/E multiple. */
    public const MAX_INTRINSIC_PE = 35.0;

    // --- Relative Valuation Shrinkage (Vasicek 1973) ---
    /** Spread (cost of equity less growth) at which a firm's own Gordon multiple and its sector's carry equal weight. */
    public const INTRINSIC_PE_SHRINKAGE_SPREAD = 0.03;
    /** Fundamental cap on free cash flow capitalization multiple (~33.3x or 3% FCF yield). */
    public const MAX_DCF_MULTIPLIER = 33.33;
    /** Minimum price-to-sales multiple clamp during valuation stress. */
    public const MIN_PS_FALLBACK_MULT = 0.2;
    /** Maximum price-to-sales multiple clamp during valuation expansion. */
    public const MAX_PS_FALLBACK_MULT = 5.0;
    /** Absolute floor on price-to-book valuation multiple. */
    public const MIN_INTRINSIC_PB = 0.40;
    /** Absolute ceiling on price-to-book valuation multiple. */
    public const MAX_INTRINSIC_PB = 10.0;
    /** Maximum mean-reversion drift force pulling price toward fundamental fair value. */
    public const MAX_REVERSION_FORCE_CAP = 15.0;
    /** Smooth transition autoregressive elasticity parameter scaling mispricing arbitrage speed. */
    public const ESTAR_ARBITRAGE_ELASTICITY = 2.0;
    /** Multiplier scaling liquidity drag when systemic interbank funding spreads widen. */
    public const FUNDING_LIQUIDITY_STRESS_FACTOR = 2.0;

    // --- Brokerage & Lending ---
    /** Net interest margin earned by brokerages on client margin debit balances. */
    public const MARGIN_LOAN_SPREAD = 0.03;

    // --- Institutional & Market Architecture ---
    /** Penalty credit spread (+200 bps) incurred when issuing emergency liquidity debt. */
    public const EMERGENCY_DEBT_SPREAD_PENALTY = 0.02;
    /** Circuit breaker limiting the price move of a single quarterly earnings report to +/-40%. */
    public const MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER = 0.40;
    /** Circuit breaker on the continuous diffusion, as the largest move of a single trading DAY; scaled by the square root of the step actually taken. */
    public const MAX_DAILY_PRICE_CIRCUIT_BREAKER = 0.40;
    /** Duration sensitivity scalar converting yield curve inversion into NIM compression. */
    public const YIELD_CURVE_INVERSION_SENSITIVITY = 15.0;

    // --- Market Saturation & Bureaucratic Bloat ---
    /** Baseline total addressable market size ($1T) for standard corporate sectors. */
    public const BASELINE_SECTOR_TAM = 1_000_000_000_000.00;
    /** Ceiling on a firm's displayed share of its addressable market; no firm serves all of one. */
    public const MAX_ADDRESSABLE_MARKET_SHARE = 0.9999;
    /** Market share threshold (50%) beyond which Penrose bureaucratic bloat accelerates. */
    public const DISECONOMY_OPTIMAL_SHARE_THRESHOLD = 0.50;
    /** Penrose bureaucratic friction coefficient penalizing margins at extreme scale. */
    public const DISECONOMY_FRICTION_COEFF = 0.20;
    /** Cobb-Douglas capital output elasticity determining marginal returns on reinvestment. */
    public const CAPITAL_MARGINAL_ELASTICITY = 0.50;
    /** Competitive moat dampeners protecting industry titans from market share erosion. */
    public const SYSTEMIC_MOAT_FACTORS = [
        'titan'    => 0.70,
        'systemic' => 0.80,
        'base'     => 0.90,
        'default'  => 1.00,
    ];

    // --- Operating Physics ---
    /** Weight of current quarter financial performance in trailing twelve month updates. */
    public const TTM_SMOOTHING_NEW_WEIGHT = 0.25;
    /** Weight of historical financial performance in trailing twelve month updates. */
    public const TTM_SMOOTHING_OLD_WEIGHT = 0.75;
    /** Speed of competitive return erosion toward cost of capital for high-ROIC firms. */
    public const REVERSION_COMPETITIVE_EROSION_ALPHA = 0.50;
    /** Autoregressive persistence parameter maintaining margin drag during financial distress. */
    public const REVERSION_DISTRESS_PERSISTENCE = 0.60;
    /** Non-linear distress acceleration exponent penalizing sub-par economic returns. */
    public const REVERSION_DISTRESS_GAMMA = 1.00;

    // --- Margin Bounds ---
    /** Maximum allowable operating gross margin ceiling (150%) to prevent runaway loops. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Minimum operating variable margin floor (1%) ensuring operational viability bounds. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Capital Allocation & Life-Cycle Physics ---
    /** Discount to intrinsic book at which a board starts repurchasing for the accretion itself. Below this the gap is inside the noise a board would act on. */
    public const MIN_ACCRETIVE_REPURCHASE_DISCOUNT = 0.05;
    /** Fraction of excess cash allocated to quarterly share repurchases for normal firms. */
    public const BUYBACK_SPEND_NORMAL_RATIO = 0.10;
    /** Fraction of excess cash allocated to quarterly share repurchases for mega cash hoarders. */
    public const BUYBACK_SPEND_MEGA_HOARDER_RATIO = 0.30;
    /** Proportion of newly issued debt proceeds required to fund organic capital expenditures. */
    public const ORGANIC_CAPEX_DEBT_RATIO = 0.75;
    /** Maximum effective dividend payout ratio (85%) for fully saturated mature cash cows. */
    public const LIFE_CYCLE_MAX_PAYOUT_RATIO = 0.85;
    /** Fraction of excess cash allocated to quarterly buybacks for saturated firms (50%). */
    public const BUYBACK_SPEND_SATURATED_RATIO = 0.50;
    /** Fraction of excess cash allocated to quarterly buybacks for mega-hoarder saturated firms (70%). */
    public const BUYBACK_SPEND_MEGA_SATURATED_RATIO = 0.70;
    /** Maximum market cap percentage (15%) a fully saturated firm can repurchase in a single quarter. */
    public const MAX_REGULATORY_SPEND_SATURATED = 0.15;

    // --- Regulatory Capital Conservation Buffer (Basel III / Solvency II) ---
    /** Leverage overshoot ratio (1.05x) triggering Tier 1 Capital Conservation Buffer restriction (max 60% payout). */
    public const REGULATORY_BUFFER_TIER_1_THRESHOLD = 1.05;
    /** Maximum target payout ratio allowed when operating under Tier 1 capital buffer restrictions. */
    public const REGULATORY_BUFFER_TIER_1_PAYOUT_CAP = 0.60;
    /** Leverage overshoot ratio (1.15x) triggering Tier 2 Capital Conservation Buffer restriction (max 30% payout). */
    public const REGULATORY_BUFFER_TIER_2_THRESHOLD = 1.15;
    /** Maximum target payout ratio allowed when operating under Tier 2 capital buffer restrictions. */
    public const REGULATORY_BUFFER_TIER_2_PAYOUT_CAP = 0.30;
    /** Leverage overshoot ratio (1.25x) triggering severe Tier 3 regulatory dividend prohibition (0% payout). */
    public const REGULATORY_BUFFER_TIER_3_THRESHOLD = 1.25;

    // --- Physical Capacity Limits (Growth Speed Limits) ---
    /** Ceiling on one quarter's organic book growth for a financial mega-hoarder; a funded balance sheet can be put to work fast. */
    public const FIN_MEGA_HOARDER_GROWTH_LIMIT = 0.35;
    /** Ceiling on one quarter's organic book growth for a cash-hoarding financial. */
    public const FIN_HOARDER_GROWTH_LIMIT = 0.20;
    /** Ceiling on one quarter's organic book growth for a normally capitalised financial. */
    public const FIN_STANDARD_GROWTH_LIMIT = 0.12;
    /** Ceiling on one quarter's organic capacity growth for a cash-hoarding operating firm; plant takes time to build. */
    public const STD_HOARDER_GROWTH_LIMIT = 0.15;
    /** Ceiling on one quarter's organic capacity growth for a normally funded operating firm. */
    public const STD_STANDARD_GROWTH_LIMIT = 0.08;

    // --- Input Cost Basket ---
    /** Default shares of the variable cost base bought in tracked input markets for a producing firm; the remainder has no macro index. */
    public const DEFAULT_INPUT_COST_EXPOSURES = ['energy' => 0.05, 'metals' => 0.05, 'agri' => 0.02, 'freight' => 0.03, 'ppi' => 0.35, 'labor' => 0.30];
    /** Default years for the recoverable share of an input move to reach selling prices (Nakamura & Steinsson 2008 price durations). */
    public const DEFAULT_INPUT_PASS_THROUGH_LAG_YEARS = 0.75;
    /** Stream-state key: lagged relative input cost level of the basket (fraction above baseline). */
    public const STATE_INPUT_COST_LEVEL = 'state:input_cost_level';
    /** Stream-state key: lagged share of the input cost level already recovered in selling prices. */
    public const STATE_INPUT_COST_RECOVERY = 'state:input_cost_recovery';
    /** Stream-state key: unrecovered input cost ratio as it stood at the PREVIOUS report, so guidance can warn on the change rather than the standing level. */
    public const STATE_PRIOR_UNRECOVERED_COST = 'state:prior_unrecovered_cost';

    // --- FX Exposure ---
    /** Base level of the trade-weighted exchange rate index, against which a move is measured as a relative deviation. */
    public const FX_INDEX_BASE = 100.0;
    /** Default share of revenue exposed to the exchange rate: a mostly domestic firm meeting a little imported competition. */
    public const DEFAULT_FX_REVENUE_EXPOSURE = 0.05;

    // --- Demand Transmission Lag ---
    /** Default years for a move in the output gap to reach a firm's order book: none, for a business that sells at the moment demand appears. */
    public const DEFAULT_DEMAND_LAG_YEARS = 0.0;

    // --- Reported KPIs in Consensus ---
    /** Share of a disclosed book-to-bill deviation from parity that analysts carry into the next quarter's revenue estimate. Orders convert to revenue, so a disclosed order book is a forecast the market already holds. */
    public const BOOK_TO_BILL_CONSENSUS_SENSITIVITY = 0.35;
    /** Bound on the resulting forward revenue tilt, so a single blowout order quarter cannot run the estimate away. */
    public const MAX_BOOK_TO_BILL_CONSENSUS_TILT = 0.15;

    // --- Analyst Cost-Base Visibility ---
    /** Share of the realized variable cost ratio analysts forecast correctly: input prices are published series (commodity indices, PPI, wage prints) and pass-through terms disclosed, so only firm-specific execution is left unseen. */
    public const ANALYST_COST_BASE_VISIBILITY = 0.75;

    // --- Earnings Pre-Announcements (Kasznik & Lev 1995) ---
    /** Share of a quarter before the scheduled report at which management closes the books far enough to know it will miss. */
    public const PREANNOUNCEMENT_LEAD_RATIO = 0.10;
    /** Known shortfall, as a fraction of structural quarterly earnings, at which management warns rather than let the market find out on the day. */
    public const PREANNOUNCEMENT_WARNING_THRESHOLD = 0.20;
    /** Share of the warned shortfall analysts take out of their estimate, so the report itself lands as a smaller surprise. */
    public const PREANNOUNCEMENT_CONSENSUS_ABSORPTION = 0.80;
    /** Ceiling on the single-tick repricing a warning may cause. Warning-day abnormal returns average high single digits (Kasznik & Lev 1995; Skinner 1994); the old 25% cap was hit routinely and, with fair value unmoved, produced a V that fully reverted before the report. */
    public const MAX_PREANNOUNCEMENT_PRICE_REACTION = 0.10;
    /** Floor on the operating margin that sizes structural earnings for a warning, so a break-even firm is scaled by its revenue rather than by a near-zero print. */
    public const PREANNOUNCEMENT_MIN_MARGIN_SCALE = 0.05;

    // --- Own-Price Demand Response ---
    /** Default own-price elasticity of demand for a producing firm (volume lost per unit of real price increase); mid-range of empirical estimates for differentiated goods. */
    public const DEFAULT_PRICE_ELASTICITY_OF_DEMAND = 0.50;

    // --- Industry Share Dynamics ---
    /** Default share of a firm's idiosyncratic revenue gain that is taken from same-industry peers rather than won from a larger market (Berry-style substitution). */
    public const DEFAULT_INDUSTRY_SUBSTITUTABILITY = 0.50;
    /** Share of a financial institution's idiosyncratic gain taken from peers: deposits, mandates and AUM move between houses, but much of the swing is market volume. */
    public const DEFAULT_FINANCIAL_INDUSTRY_SUBSTITUTABILITY = 0.35;

    // --- Industry Capacity & Cournot Pricing ---
    /** Industry price elasticity of demand: the inverse demand curve P ~ Q^(-1/e) that installed capacity is sold into (Cournot). */
    public const COURNOT_DEMAND_ELASTICITY = 1.25;
    /** Widest capacity-to-demand ratio the industry price responds to; past it the excess is idle plant, not a deeper price cut. */
    public const MAX_INDUSTRY_CAPACITY_RATIO = 2.0;
    /** Tightest capacity-to-demand ratio the industry price responds to; past it demand is rationed rather than bid ever higher. */
    public const MIN_INDUSTRY_CAPACITY_RATIO = 0.5;
    /** Largest fraction by which the industry capacity balance may move a single firm's realized price level, either way. */
    public const MAX_INDUSTRY_PRICE_RESPONSE = 0.30;
    /** Long-run supply elasticity of the competitive fringe (Forchheimer): fringe output ~ P^eta, so it cedes share when dominant firms overbuild and refills a hole when one exits; unit elasticity is the textbook constant-cost long run. */
    public const FRINGE_SUPPLY_ELASTICITY = 1.0;

    // --- Corporate Flow Pacing (SEC Rule 10b-18) ---
    /** Share of a day's average volume a repurchase program (or a placed offering's flowback) may execute per day under the 10b-18 volume condition. */
    public const CORPORATE_FLOW_MAX_ADV_SHARE_PER_DAY = 0.25;

    // --- Balance Sheet Realism ---
    /** Default capitalized operating lease liability (IFRS 16 / ASC 842) as a fraction of annual revenue. */
    public const DEFAULT_LEASE_LIABILITY_INTENSITY = 0.05;
    /** Default stock-based compensation (ASC 718) as a fraction of revenue: non-cash expense, real dilution. */
    public const DEFAULT_STOCK_COMPENSATION_INTENSITY = 0.01;
    /** Goodwill impairment smaller than this fraction of the goodwill balance is immaterial and not booked. */
    public const MIN_GOODWILL_IMPAIRMENT_FRACTION = 0.01;

    // --- Labor Intensity ---
    /** Largest fixed-payroll relief from wage growth running below trend (nominal wages are downward sticky). */
    public const MAX_WAGE_RELIEF = 0.02;
    /** Default labor share of the fixed cost base (salaried staff, SG&A payroll) exposed to the Beveridge wage squeeze. */
    public const DEFAULT_FIXED_COST_LABOR_SHARE = 0.65;

    // --- Debt Physics ---
    /** Fallback weighted average cost of capital used as the investment hurdle when no live debt health exists. */
    public const DEFAULT_WACC_FALLBACK = 0.08;
    /** Fraction of fixed-rate debt that matures and reprices at market each quarter (5-year average tenor). */
    public const DEFAULT_QUARTERLY_DEBT_ROLLOVER = 0.05;
    /** Base quarterly probability of evaluating balance sheet debt expansion. */
    public const DEBT_EXPANSION_BASE_PROB = 0.40;
    /** Sensitivity scaling debt issuance probability when ROIC exceeds WACC. */
    public const DEBT_EXPANSION_PROB_MULT = 0.50;
    /** Baseline percentage of borrowing capacity utilized during debt expansion. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.05;
    /** Aggressiveness multiplier scaling debt issuance with economic spread (ROIC - WACC). */
    public const DEBT_EXPANSION_AGGR_MULT = 0.35;
    /** Minimum hurdle spread (100 bps) required between ROIC and WACC before issuing debt. */
    public const WACC_ARBITRAGE_BUFFER = 0.01;
    /** Debt-to-equity ratio threshold below which a corporate entity is underleveraged. */
    public const CORPORATE_UNDERLEVERAGED_RATIO = 0.75;
    /** Safety coverage multiplier required above minimum interest coverage ratio. */
    public const REQUIRED_ICR_SAFETY_MULT = 1.50;
    /** Absolute minimum interest coverage ratio buffer required for discretionary debt issuance. */
    public const MIN_ABSOLUTE_ICR_BUFFER = 2.00;
    /** Dampen double-counting of historical debt when re-levering Beta through the Hamada equation. */
    public const HAMADA_DAMPENING_FACTOR = 0.25;

    // --- Valuation Consensus Weights ---
    /** Consensus weight given to earnings/DCF intrinsic fair value in valuation blending. */
    public const FAIR_VALUE_EARNINGS_WEIGHT = 0.90;
    /** Consensus weight given to book value/liquidation fair value in valuation blending. */
    public const FAIR_VALUE_BOOK_WEIGHT = 0.10;
    /** Weight given to Dividend Discount Model fair value when dividend support is active. */
    public const FAIR_VALUE_DDM_WEIGHT = 0.15;

    // --- Corporate Taxation ---
    /** Max % of taxable income that can be shielded by NOLs (e.g. 80% post-TCJA). */
    public const NOL_MAX_SHIELD_RATIO = 0.80;

    // --- Asymmetric Cost Stickiness (Anderson, Banker, & Janakiraman 2003) ---
    /** Elasticity of operational variable expenses to revenue growth (beta 1). */
    public const STICKY_COST_BETA_EXPANSION = 0.85;
    /** Downward stickiness penalty reducing expense contraction during revenue declines (beta 2 < 0). */
    public const STICKY_COST_BETA_CONTRACTION_PENALTY = -0.40;

    // --- Dynamic Cash Conversion Cycle & Working Capital (CCC) ---
    /** Sensitivity of Days Sales Outstanding (DSO) to corporate credit spread widening. */
    public const CCC_DSO_CREDIT_SPREAD_SENSITIVITY = 250.0;
    /** Sensitivity of Days Inventory Outstanding (DIO) to stranded capacity / inventory overhang. */
    public const CCC_DIO_CAPACITY_SENSITIVITY = 20.0;
    /** Sensitivity of Days Payable Outstanding (DPO) contraction to interbank funding liquidity stress. */
    public const CCC_DPO_LIQUIDITY_SENSITIVITY = 500.0;
    /** Minimum working capital intensity floor for positive working capital models. */
    public const MIN_POSITIVE_NWC_INTENSITY = 0.01;
    /** Maximum working capital intensity ceiling for positive working capital models. */
    public const MAX_POSITIVE_NWC_INTENSITY = 1.00;
    /** Minimum working capital intensity floor for negative working capital float models. */
    public const MIN_NEGATIVE_NWC_INTENSITY = -0.50;
    /** Maximum working capital intensity ceiling for negative working capital float models. */
    public const MAX_NEGATIVE_NWC_INTENSITY = -0.001;

    // --- Accruals Quality & Sloan Anomaly (Sloan 1996) ---
    /** Valuation multiple discount scalar penalizing stocks with high non-cash accounting accruals. */
    public const ACCRUALS_ANOMALY_PE_PENALTY_SCALE = 8.0;
    /** Analyst EPS growth forecast mean-reversion discount for low-quality non-cash earnings. */
    public const ACCRUALS_DECAY_EPS_GROWTH_SENSITIVITY = 0.50;

    // --- Earnings Management (Burgstahler & Dichev 1997) ---
    /** Largest shortfall against consensus, as a fraction of the consensus figure, that management will close with accruals; a wider miss is taken rather than papered over. */
    public const EARNINGS_MANAGEMENT_MAX_GAP = 0.05;
    /** Cap on the accumulated managed-accrual balance as a fraction of total assets: past this the reversal is too large to keep hiding. */
    public const EARNINGS_MANAGEMENT_MAX_BANK_RATIO = 0.02;
    /** Quarterly fraction of the borrowed balance that unwinds back into reported earnings (Dechow & Dichev 2002 accrual reversal). */
    public const EARNINGS_MANAGEMENT_REVERSAL_RATE = 0.25;
    /** Cushion above consensus a managed quarter aims for, so it prints as a small beat rather than an implausibly exact match. */
    public const EARNINGS_MANAGEMENT_BEAT_CUSHION = 0.002;
    /** Default propensity to manage reported earnings toward consensus (0 = never, 1 = closes every gap it can reach). */
    public const DEFAULT_EARNINGS_MANAGEMENT_PROPENSITY = 0.50;

    // --- CapEx & Construction in Progress (CIP) ---
    /** Maximum fraction of physical/invested capital that can be deferred as unplaced Construction in Progress (25%). */
    public const MAX_CIP_CAPITAL_DEDUCTION_RATIO = 0.25;
    /** Maximum CIP balance relative to invested capital allowed before new growth CapEx deployment is paused (25%). */
    public const MAX_CIP_EXPANSION_THRESHOLD_RATIO = 0.25;

    // --- Deferred Taxes (ASC 740) ---
    /** Declining-balance rate multiple for tax depreciation: the 200% method of the MACRS general depreciation system. */
    public const TAX_DEPRECIATION_ACCELERATION = 2.0;

    // --- Working Capital Ledger ---
    /** Share of a positive working capital cycle carried as receivables; the rest is inventory (Compustat medians). */
    public const WORKING_CAPITAL_RECEIVABLE_SHARE = 0.55;
    /** Payables carried as a fraction of the gross receivable-plus-inventory cycle, the standard trade-credit offset. */
    public const WORKING_CAPITAL_PAYABLE_SHARE = 0.35;
    /** Days in the accounting year used to convert day counts into balances. */
    public const DAYS_PER_YEAR = 365.0;

    // --- Inventory & Receivable Impairment ---
    /** Capacity utilization below which unsold inventory starts failing the lower-of-cost-or-net-realizable-value test (ASC 330). Calibrated to this engine's utilization scale, which centres on 1.0 rather than the ~80% of the published manufacturing series. */
    public const INVENTORY_NRV_UTILIZATION_TRIGGER = 0.95;
    /** Fraction of inventory written off at total demand collapse; scaled by how far utilization has fallen. */
    public const INVENTORY_NRV_LOSS_RATE = 0.25;
    /** Loss given default on a trade receivable: unsecured, but with real recovery in liquidation. */
    public const TRADE_RECEIVABLE_LGD = 0.60;
    /** Maximum share of the existing allowance that can be released in one quarter, so a recovery cannot be booked as instant profit. */
    public const MAX_ALLOWANCE_RELEASE_RATIO = 0.25;

    // --- Fixed Asset Ledger (PP&E) ---
    /** Accumulated depreciation as a share of gross PP&E at seed; the median US non-financial runs a half-aged plant. */
    public const SEED_ASSET_AGE_RATIO = 0.50;
    /** Viability floor on net PP&E as a share of invested capital. Kept minimal on purpose: a distributor's or staffing firm's capital genuinely IS its working capital, and a larger floor would invent plant the balance sheet cannot fund. */
    public const MIN_PPE_SHARE_OF_CAPITAL = 0.02;
    /** Floor on the cash share of the structural cost base once depreciation is carved out as its own expense line. */
    public const MIN_CASH_COST_SHARE = 0.40;

    // --- Earning Asset Ledger (Financials) ---
    /** Haircut taken when earning assets are sold in a hurry to meet withdrawals or a maturity: securities marked below par, loans sold at a discount. */
    public const EARNING_ASSET_FIRE_SALE_HAIRCUT = 0.05;
    /** Largest share of the earning-asset book that can be sold in one quarter; the rest is illiquid loans nobody bids for on the day. */
    public const MAX_QUARTERLY_ASSET_LIQUIDATION_RATIO = 0.25;
    /** Share of the gap between the credit-loss allowance and its lifetime target closed each quarter, in either direction, so a build or release is a path and not a cliff. */
    public const CREDIT_ALLOWANCE_CONVERGENCE_RATIO = 0.25;

    // --- Equity Issuance & TAM Scaling Limits ---
    /** Maximum fraction of market capitalization that can be raised in a distressed emergency equity offering (25%). */
    public const MAX_EMERGENCY_EQUITY_RAISE_RATIO = 0.25;
    /** Ceiling on structural revenue capacity, as a multiple of the firm's revenue at a full addressable share (150%). */
    public const MAX_SECTOR_TAM_CAPACITY_RATIO = 1.50;
    /** The same ceiling for financial intermediaries, whose share is read on equity while revenue comes off the leveraged book (250%). */
    public const MAX_FINANCIAL_SECTOR_TAM_CAPACITY_RATIO = 2.50;
    // --- Sovereign Bond Desk ---
    /** Face value of a single sovereign bond, redeemed at maturity and the base every coupon is struck against. */
    public const BOND_FACE_VALUE = 1000.0;
    /** Coupon payments per year. Sovereign convention is semi-annual. */
    public const BOND_COUPON_FREQUENCY = 2;
    /** Auctions per year: each one rotates a fresh on-the-run issue into every tenor and retires the previous one to off-the-run. */
    public const BOND_AUCTIONS_PER_YEAR = 4;
    /** Coupons are struck in eighths of a percent, the auction convention, so the issue prices near par rather than exactly at it. */
    public const BOND_COUPON_RATE_INCREMENT = 0.00125;
    /** Original maturities offered at auction, in years; the same benchmark points the macro engine publishes. */
    public const BOND_AUCTION_TENORS = [2.0, 5.0, 10.0, 30.0];
    /** Floor on a struck coupon. A zero-coupon issue is legitimate at the lower bound; a negative one is not. */
    public const BOND_MIN_COUPON_RATE = 0.0;
    /** Face amount issued per tenor per auction, in currency units. Sets the size of the tradable float. */
    public const BOND_ISSUE_SIZE = 5.0e9;
    /** Ceiling on years-to-maturity treated as outstanding; past it the issue is redeemed and stops trading. */
    public const BOND_MATURITY_EPSILON = 1.0e-6;
    /** Tenors the curve is sampled at for display. Dense at the front, where the curve actually bends. */
    public const BOND_CURVE_SAMPLE_TENORS = [0.25, 0.5, 1.0, 2.0, 3.0, 5.0, 7.0, 10.0, 15.0, 20.0, 30.0];
    // --- Market Microstructure: Volume & Liquidity ---
    /** Trading days a simulated year is divided into when expressing average daily volume. */
    public const TRADING_DAYS_PER_YEAR = 252.0;
    /** Baseline annual share turnover as a fraction of the public float; the median large cap turns over a little more than its float each year. */
    public const BASELINE_ANNUAL_TURNOVER = 1.20;
    /** Floor on average daily volume in shares. sqrt(Q/ADV) diverges as ADV approaches zero, so a dead name must be illiquid rather than untradable. */
    public const MIN_ADV_SHARES = 1000.0;
    /** Shares in a typical print, used to turn daily volume into the trade count the spread relation needs. */
    public const TYPICAL_TRADE_SIZE_SHARES = 200.0;
    /** Elasticity of traded volume to volatility (Karpoff 1987): both are driven by the same information arrivals, so a volatile tape is a busy one. */
    public const VOLUME_VOLATILITY_ELASTICITY = 0.70;
    /** Lower bound on the volatility-driven activity multiplier applied to structural ADV. */
    public const MIN_ADV_ACTIVITY_MULTIPLIER = 0.40;
    /** Upper bound on that multiplier, so a crash does not manufacture unlimited liquidity. */
    public const MAX_ADV_ACTIVITY_MULTIPLIER = 3.00;
    /** Lognormal dispersion of realized volume around its conditional mean (Clark 1973 mixture-of-distributions). */
    public const VOLUME_LOGNORMAL_SIGMA = 0.45;
    /** Reference single-name annual volatility that BASELINE_ANNUAL_TURNOVER is quoted against. */
    public const TURNOVER_REFERENCE_VOLATILITY = 0.18;
    /** Cross-sectional elasticity of turnover to volatility: volatile names change hands more often than quiet ones. */
    public const TURNOVER_VOLATILITY_ELASTICITY = 0.60;
    /** Bounds on the structural turnover ratio, keeping even the quietest utility and the wildest speculative name inside a plausible range. */
    public const MIN_ANNUAL_TURNOVER = 0.35;
    public const MAX_ANNUAL_TURNOVER = 4.00;

    // --- Market Microstructure: Spread (Wyart, Bouchaud, Kockelkoren, Potters & Vettorazzo 2008) ---
    /** Coefficient c in S = c * sigma_daily / sqrt(N), the observed relation between spread, volatility and trade count. Near unity in real order-driven markets. */
    public const SPREAD_VOLATILITY_COEFFICIENT = 1.00;
    /** Floor on the quoted half-spread as a fraction of price (0.5bp): crossing a mega-cap is cheap, never free. */
    public const MIN_HALF_SPREAD = 0.00005;
    /** Ceiling on the quoted half-spread (2%), so even a distressed name stays tradable at a price. */
    public const MAX_HALF_SPREAD = 0.02;

    // --- Market Microstructure: Impact (Almgren, Thum, Hauptmann & Li 2005) ---
    /** Linear permanent impact coefficient. At 1.0 trading one full day's volume moves the price by one daily standard deviation; linear so the mark is additive across ticks and independent of the tick rate (Huberman & Stanzl 2004). */
    public const PERMANENT_IMPACT_GAMMA = 1.00;
    /** Temporary impact as a share of the permanent move. The price walks to its new level while the order fills, so the taker's average fill is the midpoint of that walk: exactly one half. */
    public const TEMPORARY_IMPACT_ETA = 0.50;
    /** Largest multiple of average daily volume a single order may consume. Past it the impact law is extrapolation, and a capped impact would be a free lunch for size. */
    public const MAX_ORDER_ADV_MULTIPLE = 2.00;
    /** Ceiling on the price move one tick's net order flow may leave behind, as a log return; the impact law is a per-order measurement and a tick's aggregate is not bounded by the per-order size cap. */
    public const MAX_TICK_IMPACT_LOG_RETURN = 0.2624;
    /** Flat half-spread on a broad index ETF. Creation and redemption keep it pinned to the basket, so it quotes tighter than any single constituent. */
    public const ETF_HALF_SPREAD = 0.0001;
    /** Flat half-spread on a sovereign bond, the deepest instrument on the desk. */
    public const BOND_HALF_SPREAD = 0.00005;
    /** Half-spread on a CORPORATE issue. Wider than the sovereign by an order of magnitude and then some: a company's bonds trade in a fraction of the size, against a fraction of the buyers, and most of them sit in portfolios that never sell. Quoting them at the sovereign's depth would make credit risk free to get into and out of, which is the opposite of what makes it risky. */
    public const CORPORATE_BOND_HALF_SPREAD = 0.0015;


    // --- Listed Equity Options ---
    /** Shares one contract is written on, the listed convention. Every premium here is quoted PER SHARE and multiplied by this only where cash actually moves. */
    public const OPTION_CONTRACT_MULTIPLIER = 100;
    /** Months to expiry of the expiries listed at any one time: two near months, a quarterly and a two-quarter, which is the front of a standard listed cycle. */
    public const OPTION_EXPIRY_MONTHS = [1, 2, 3, 6];
    /** Strike ladder spacing as a fraction of spot, before it is snapped to a round increment. */
    public const OPTION_STRIKE_SPACING_FRACTION = 0.05;
    /** Widest strike listed either side of spot, as a fraction of it. Wide enough to carry the tails the smile prices, short of the strikes nobody quotes. */
    public const OPTION_STRIKE_LADDER_WIDTH = 0.30;
    /** Round increments a strike ladder may be struck on; the ladder snaps to the smallest one at or above the spacing fraction, which is how a real ladder ends up on whole and half numbers at every price level. */
    public const OPTION_STRIKE_INCREMENTS = [0.50, 1.00, 2.50, 5.00, 10.00, 25.00, 50.00, 100.00, 250.00];
    /** Half-width around spot listed on every increment. A real chain is dense at the money and thins as it goes out, because that is where the strikes anyone asks for are. */
    public const OPTION_STRIKE_DENSE_BAND = 0.10;
    /** Increments between strikes outside the dense band, the ends of the ladder always listed. Three: measured across a $8-$1400 price range at 30% fewer rows for 1.2% of dealer gamma, against 19% fewer for 0.1% at two. */
    public const OPTION_STRIKE_WING_INCREMENT_MULTIPLE = 3;
    /** Average daily volume a name must trade before a class is opened on it; exchanges list options against a float and a trading record, not against every listed company. */
    public const OPTION_LISTING_MIN_ADV = 50000.0;
    /** Price a name must hold to carry a class. Below it the round-increment ladder has no usable strikes and every contract is one tick wide. */
    public const OPTION_LISTING_MIN_PRICE = 5.00;

    // --- Option Market Making ---
    /** Volatility points a desk quotes either side of its mark. An option's spread is a spread in VOLATILITY — the desk is trading variance, not premium — and the premium spread is this times vega. */
    public const OPTION_HALF_SPREAD_VOLATILITY = 0.015;
    /** Floor on the half-spread as a fraction of the premium, so a deep in-the-money contract carrying almost no vega still costs something to cross. */
    public const OPTION_MIN_HALF_SPREAD_FRACTION = 0.005;
    /** Ceiling on the same, because a far out-of-the-money contract's vega spread can otherwise exceed the whole of its premium. */
    public const OPTION_MAX_HALF_SPREAD_FRACTION = 0.25;
    /** Smallest premium a listed contract quotes at: one cent, the minimum increment. A contract worth less than this is quoted here and worth nothing on exercise. */
    public const OPTION_MIN_PREMIUM = 0.01;
    // --- Option Exercise & Settlement ---
    /** Intrinsic value per share at which a contract is exercised by exception at expiry. The clearing house exercises anything in the money by a tick unless the holder says otherwise, so a contract a cent in the money is delivered, not abandoned. */
    public const OPTION_EXERCISE_THRESHOLD = 0.01;

    // --- Short Option Margin (FINRA Rule 4210 / CBOE minimums) ---
    /** Share of the underlying a naked short option is collateralized at, before the out-of-the-money amount is credited back against it. */
    public const SHORT_OPTION_UNDERLYING_REQUIREMENT = 0.20;
    /** Floor on that requirement, struck on the underlying for a call and on the STRIKE for a put, so a far out-of-the-money short is never collateralized at nothing. */
    public const SHORT_OPTION_MINIMUM_REQUIREMENT = 0.10;


    // --- Market Index Membership (Shleifer 1986) ---
    /** Seats in the headline index. Fewer than the listed universe, so membership is a real distinction and joining or leaving it means something; the composite index carries every listed name and has no count. */
    public const INDEX_CONSTITUENT_COUNT = 30;
    /** Banding around the cut, as a fraction of the constituent count. A sitting member is not evicted the first time a marginal name edges past it: real indices band precisely because ranking noise at the boundary would otherwise churn the whole passive book twice a year for nothing. */
    public const INDEX_MEMBERSHIP_BUFFER = 0.20;
    /** Reconstitutions per year. */
    public const INDEX_RECONSTITUTIONS_PER_YEAR = 4;
    /** Level the index opens at on a market with no history. An index base is a convention, not a measurement: what carries meaning is the return from it. */
    public const INDEX_BASE_LEVEL = 100.0;
    /** Seats in the low-volatility index, drawn from the whole listed board. S&P's low-volatility index takes the quietest fifth of its parent; the same fraction of this board is about this many names. */
    public const INDEX_LOW_VOLATILITY_COUNT = 20;
    /** Floor on the trailing volatility an inverse-volatility weighting divides by. A name that has gone quiet enough to divide by nothing would otherwise take the whole fund. */
    public const INDEX_MINIMUM_WEIGHT_VOLATILITY = 0.04;
    /** Window the realized volatility an index ranks and weights on is measured over, as the mean life of its exponential weighting. A year, which is what S&P's low-volatility index uses; ranking on the instantaneous variance state instead turned every volatility spike into a reconstitution. */
    public const INDEX_TRAILING_VOLATILITY_YEARS = 1.0;

    // --- Index Diversification Caps (RIC / UCITS 5-10-40, as applied by the S&P Select Sector indices) ---
    /** Most any one constituent may weigh in a capped index. A sector fund that must stay a regulated investment company cannot let one name run away with it. */
    public const INDEX_MAX_CONSTITUENT_WEIGHT = 0.225;
    /** Weight above which a constituent counts toward the concentration budget below. */
    public const INDEX_CONCENTRATION_THRESHOLD = 0.045;
    /** Most the constituents above that threshold may weigh in combination. */
    public const INDEX_CONCENTRATION_BUDGET = 0.45;

    // --- Passive Assets by Index (share of the indexed book each published index carries) ---
    /** Share of passive money tracking the headline index. Broad cap-weighted benchmarks hold the large majority of indexed assets. */
    public const INDEX_PASSIVE_SHARE_HEADLINE = 0.62;
    /** Share tracking the whole-board composite: total-market funds, the second-largest passive vehicle. */
    public const INDEX_PASSIVE_SHARE_COMPOSITE = 0.30;
    /** Share tracking the low-volatility fund. Smart beta is a low single-digit share of indexed money, and it is spread across the near half of the board that qualifies as quiet. */
    public const INDEX_PASSIVE_SHARE_LOW_VOLATILITY = 0.05;
    /** Share tracking the consumer staples sector fund. Deliberately near the sector's own weight in the market: a narrow fund holding far more indexed money than its sector is worth would leave its handful of names with passive ownership no real constituent carries. */
    public const INDEX_PASSIVE_SHARE_STAPLES = 0.03;
    /** Ceiling on how much passive ownership a single name can carry relative to its weight in the market. A name held by every fund at once is still only so much of anyone's book. */
    public const INDEX_MAX_PASSIVE_OWNERSHIP_MULTIPLE = 4.0;

    // --- Index Fund Accounting ---
    /** Distributions a fund pays per year. Quarterly, matching both the constituents' own dividend cycle and the reconstitution calendar. */
    public const FUND_DISTRIBUTIONS_PER_YEAR = 4;
    /** Smallest distribution worth paying, per share. Below this the income stays accrued into the next quarter rather than writing a ledger row per holder that rounds to nothing. */
    public const FUND_MINIMUM_DISTRIBUTION = 0.005;
    /** Smallest rebalance worth charging for, as a fraction of the fund. Below this the trade is the rounding on a weight that barely moved, and charging it would write a cost row every quarter for nothing. */
    public const FUND_MINIMUM_REBALANCE_TURNOVER = 0.0005;

    // --- Corporate Bond Issuance ---
    /** Share of a firm's wholesale debt that is funded in the PUBLIC bond market rather than by banks. The listed issues are a tranche of the debt the balance sheet already carries, never additional borrowing. */
    public const CORPORATE_PUBLIC_DEBT_SHARE = 0.50;
    /** Issues a firm keeps outstanding at once. Sets the steady-state size of the corporate ladder directly: a firm holding this many issues holds this many, whatever the tenors are. */
    public const CORPORATE_LADDER_ISSUES = 3;
    /** Original maturities a firm issues at, cycled so a ladder ends up spread across the curve instead of stacked on one point. */
    public const CORPORATE_ISSUE_TENORS = [3.0, 5.0, 7.0, 10.0];
    /** Smallest face a single issue may be brought at. A gap smaller than this waits rather than bringing a deal nobody would underwrite. */
    public const CORPORATE_MIN_ISSUE_FACE = 5.0e7;
    /** Wholesale debt a firm must carry before the public market is worth tapping. DERIVED, not chosen: it is exactly the debt at which a full ladder of minimum-size issues fits inside the public tranche. Set independently, the two rules disagree — a firm passes the debt gate, then every deal it tries to bring prices below the minimum size and it silently never issues at all. */
    public const CORPORATE_MIN_PUBLIC_DEBT = (self::CORPORATE_LADDER_ISSUES * self::CORPORATE_MIN_ISSUE_FACE) / self::CORPORATE_PUBLIC_DEBT_SHARE;
    /** Reconciliations of the public tranche per year. A firm comes to market when it has room, not continuously. */
    public const CORPORATE_ISSUANCE_PER_YEAR = 4;

    // --- Corporate Credit: Recovery Given Default (Altman, Brady, Resti & Sironi 2005) ---
    /** Recovery on a senior SECURED claim in an average default year, as a share of face; collateral is what puts this claim ahead of the rest. */
    public const RECOVERY_SENIOR_SECURED = 0.62;
    /** Recovery on a senior UNSECURED claim, the ordinary public corporate bond. */
    public const RECOVERY_SENIOR_UNSECURED = 0.48;
    /** Recovery on a SUBORDINATED claim, which is paid only once everything above it is whole. */
    public const RECOVERY_SUBORDINATED = 0.28;
    /** Aggregate corporate default rate the base recoveries above are quoted at; the long-run average year. */
    public const RECOVERY_BASELINE_DEFAULT_RATE = 0.018;
    /** Fall in recovery per unit of log excess in the aggregate default rate. Recovery and default are NEGATIVELY correlated: defaults cluster in bad years, distressed assets are sold into a market with no buyers, and the same claim is worth less precisely when more of them are being settled. Ignoring it prices the tail of a credit portfolio far too kindly. */
    public const RECOVERY_DEFAULT_RATE_ELASTICITY = 0.12;
    /** Bounds on recovery. Nothing recovers everything once it has defaulted, and even a wiped-out claim usually salvages something. */
    public const MIN_RECOVERY_RATE = 0.05;
    public const MAX_RECOVERY_RATE = 0.90;

    // --- Corporate Credit: Spread Composition (Longstaff, Mithal & Neis 2005) ---
    /** Non-default component of a corporate spread: what a buyer charges for holding a claim they cannot sell as readily as a sovereign. Measured to be a material minority of an investment-grade spread, so a bond priced on default risk alone quotes through the market. */
    public const CORPORATE_ILLIQUIDITY_SPREAD = 0.0040;
    /** Ceiling on the credit spread a listed issue may be discounted at, matching the cap the Merton spread itself carries. */
    public const MAX_CORPORATE_SPREAD = 1.00;

    // --- Public Option Demand (Bollen & Whaley 2004 net buying pressure) ---
    /** The public's net long position across a name's whole chain, in contracts, as a multiple of its average daily volume converted to contract-equivalents. The public is a persistent NET BUYER of options, which is the whole reason a dealer is structurally short them. */
    public const OPTION_PUBLIC_OPEN_INTEREST_ADV_MULTIPLE = 0.50;
    /** Absolute delta the public's demand is centred on. Open interest concentrates out of the money rather than at it: the buyer is paying for convexity, not for the underlying. */
    public const OPTION_PUBLIC_TARGET_DELTA = 0.30;
    /** Width of that concentration, in delta. Wide enough that the whole listed ladder carries some interest, narrow enough that the wings do not dominate it. */
    public const OPTION_PUBLIC_DELTA_DISPERSION = 0.18;
    /** Share of single-name public demand that goes to calls in calm conditions. Bollen & Whaley find net buying pressure in INDIVIDUAL equity options is call-driven — the lottery preference of Bali, Cakici & Whitelaw (2011) — where in index options it is puts. */
    public const OPTION_PUBLIC_CALL_SHARE = 0.60;
    /** Shift of that share toward puts per unit of market volatility above its baseline: hedging demand displaces lottery demand as the market becomes frightening, which is what steepens a skew in a selloff. */
    public const OPTION_PUBLIC_FEAR_PUT_SENSITIVITY = 1.50;
    /** Decay of demand with time to expiry, per year. Listed open interest is concentrated in the front months; the back months are quoted more than they are held. */
    public const OPTION_PUBLIC_EXPIRY_DECAY = 2.00;
    /** Years for public open interest to close 63% of the gap to its target. Positions are opened and rolled over weeks; a book that rebuilt itself every tick would be a flow, not a position. */
    public const OPTION_PUBLIC_DEMAND_HORIZON_YEARS = 0.08;

    // --- Dealer Gamma Hedging (Barbon & Buraschi 2020; Baltussen, Da, Lammers & Radeva 2021) ---
    /** Share of the desk's delta exposure that actually reaches the market as a hedge. A desk nets customer flow against itself first and only hedges the residual, so the whole of its book never trades. */
    public const DEALER_HEDGE_RATIO = 0.80;
    /** Ceiling on one tick's hedging flow as a multiple of the name's average daily volume. A short-gamma desk chasing a gap would otherwise demand more liquidity in one tick than the name trades in a day, and the impact law is extrapolation past that point. */
    public const MAX_DEALER_HEDGE_ADV_MULTIPLE = 0.25;

    /** Markup from the variance a desk expects to the variance it quotes (Carr & Wu 2009). A desk that quotes its own forecast loses money on average, which is why implied runs above subsequent realized. Held modest because the premium on SINGLE-NAME options is a fraction of the index premium (Bakshi, Kapadia & Madan 2003). */
    public const OPTION_VARIANCE_RISK_PREMIUM = 1.05;

    // --- Market Microstructure: Order Flow Variance Budget ---
    /** Ceiling on the share of long-run variance order flow may reclaim from the diffusion. */
    public const MAX_IMPACT_VARIANCE_DRAG_SHARE = 0.25;
    /** Half-life in years of the realized impact-variance estimate the budget is drawn from. */
    public const IMPACT_VARIANCE_EMA_YEARS = 0.25;
    // --- Margin Accounts (Regulation T) ---
    /** Equity a new position must be backed by: half of what it is worth, long or short. */
    public const INITIAL_MARGIN_REQUIREMENT = 0.50;
    /** Equity a long position must keep behind it before the account is called. */
    public const MAINTENANCE_MARGIN_LONG = 0.25;
    /** Higher for a short, because a short's loss is unbounded while a long's stops at zero. */
    public const MAINTENANCE_MARGIN_SHORT = 0.30;
    /** Extra equity a forced liquidation restores beyond the bare minimum, so the account is not called again on the next tick. */
    public const LIQUIDATION_EQUITY_BUFFER = 0.05;
    /** Ceiling on the fraction of a position that one margin call may liquidate. */
    public const MAX_LIQUIDATION_FRACTION = 1.00;

    // --- Securities Lending ---
    /** Share of the public float that is actually lendable; the rest sits with holders who do not lend. */
    public const DEFAULT_LENDABLE_SUPPLY_RATIO = 0.65;
    /** General collateral borrow fee: what an easy-to-borrow name costs to short, annualized. */
    public const GENERAL_COLLATERAL_BORROW_FEE = 0.0030;
    /** Borrow fee on a name whose lendable supply is fully consumed. Hard-to-borrow specials really do reach these levels. */
    public const MAX_BORROW_FEE = 1.00;
    /** Convexity of the fee curve in utilization. Flat while supply is ample, then steepening sharply as the last of it is taken: general collateral holds past half utilization, and a name only turns special above roughly eighty-five percent. */
    public const BORROW_FEE_CONVEXITY = 8.00;
    /** Utilization past which lenders begin recalling stock and shorts are bought in. */
    public const BUY_IN_UTILIZATION_THRESHOLD = 0.97;
    /** Share of an outstanding short position recalled per buy-in. */
    public const BUY_IN_FRACTION = 0.20;
    // --- Agent Population (Brock & Hommes 1997, 1998 Adaptive Belief System) ---
    /** Intensity of choice: how sharply capital chases whichever belief has been paying. At zero the population never moves; raising it is what tips the market from anchored to trending. */
    public const AGENT_INTENSITY_OF_CHOICE = 3.00;
    /** Memory in the fitness estimate, in years. Capital chases performance over months, not over the last print, and a horizon in time rather than in ticks keeps that true at any tick rate. */
    public const AGENT_FITNESS_HORIZON_YEARS = 0.50;
    /** Floor on any belief's population share, so a strategy that has been wrong for a long time can still come back when conditions turn. */
    public const AGENT_MIN_POPULATION_SHARE = 0.05;
    /** Risk aversion in the mean-variance fitness U = pi - (a/2) sigma^2 z^2 (Brock & Hommes 1998). Standard relative risk aversion; without it raw profit rewards whichever belief simply carries more exposure. */
    public const AGENT_RISK_AVERSION = 2.00;
    /** Share of switching capital that chooses at the STYLE level, on how a belief has paid across the whole market, rather than name by name (Barberis & Shleifer 2003). Zero is a market of unrelated single-name populations; one is a single market-wide population. */
    public const AGENT_STYLE_CROWDING_WEIGHT = 0.50;
    /** Memory of the realized-variance estimate the agents see, in years (~1 month). RiskMetrics-style EWMA of observed returns; vol-control mandates (Harvey et al. 2018) and maker risk desks size on a window of that order. */
    public const AGENT_REALIZED_VOLATILITY_HORIZON_YEARS = 0.083;

    // --- Agent Capital & Positioning ---
    /** Unit of agent capital per name, as a multiple of its STRUCTURAL average daily volume. A fully committed belief or structural holder is sized against it; the competing beliefs share one unit between them and each structural holder carries its own share of one. */
    public const AGENT_CAPITAL_ADV_MULTIPLE = 3.00;
    /** Time an agent takes to work its book 63% of the way to target, in years (~1 trading day; ~95% done in three). In time rather than per tick so the same book is worked the same way at any tick rate. */
    public const AGENT_POSITION_HORIZON_YEARS = 0.004;
    /** Overall scale on the agent books. Multiplies the capital unit, so flow scales with it and the variance the agents supply to the price with its square: the single number to turn when handing more of the market's variance from the diffusion to the agents. Zero winds every book down over the position horizon and leaves no agents. */
    public const AGENT_FLOW_INTENSITY = 1.00;

    // --- Agent Signals ---
    /** Fundamentalist conviction per unit of log mispricing: fully committed at roughly a 40% discount to fair value. */
    public const AGENT_FUNDAMENTALIST_GAIN = 2.50;
    /** Chartist conviction per unit of accumulated price trend. */
    public const AGENT_MOMENTUM_GAIN = 3.00;
    /** Share of the others' flow a market maker takes the other side of in calm conditions (Grossman & Miller 1988 immediacy). The rest reaches the price at once. */
    public const AGENT_MAKER_ABSORPTION = 0.35;
    /** Time a maker takes to work 63% of its inventory back to flat, in years (~1 trading day; Hendershott & Menkveld 2014 find inventories mean-revert on that order). Carrying risk is not what it is paid for. */
    public const AGENT_MAKER_INVENTORY_HORIZON_YEARS = 0.004;
    /** Volatility at which the base absorption applies. Above it, absorption falls with 1/variance (Ho & Stoll 1981: the cost of immediacy is proportional to variance), so makers step back in a stressed market. */
    public const AGENT_MAKER_REFERENCE_VOLATILITY = 0.25;
    /** Fractional change in the passive book per unit of the financial conditions index (a z-score composite): money leaves passive vehicles when conditions tighten. A two-sigma tightening takes 30% of the book, the order of a bad year of equity fund outflows. */
    public const AGENT_INDEX_FLOW_SENSITIVITY = 0.15;
    /** Most the passive book moves from its base in either direction, as a fraction. Passive flows are slow money even in a crisis; a tilt that could empty the book turned an index fund into a macro trader. */
    public const AGENT_INDEX_MAX_FLOW_TILT = 0.30;
    /** Baseline share of agent capital that indexes rather than picking. */
    public const AGENT_INDEX_BASE_SHARE = 0.30;

    // --- Volatility-Targeting Funds (Moreira & Muir 2017; Harvey et al. 2018) ---
    /** Annualized volatility a vol-control book is run to. Exposure scales as target / realized, so a name at this volatility is held at the base share; set at the market's reference name so an ordinary name sits near 1x. */
    public const AGENT_VOL_TARGET_VOLATILITY = 0.25;
    /** Share of agent capital the vol-targeting books hold in a name running at target volatility. */
    public const AGENT_VOL_TARGET_BASE_SHARE = 0.15;
    /** Most a vol-targeting book levers up when realized volatility falls below target. Harvey et al. cap leverage at 2x; without a cap a quiet tape would be bought without limit. */
    public const AGENT_VOL_TARGET_MAX_LEVERAGE = 2.00;

    // --- Relative-Value Funds (Barberis & Shleifer 2003 cross-sectional style) ---
    /** Share of agent capital a market-neutral book can put long or short in one name against the rest of the market. */
    public const AGENT_RELATIVE_VALUE_SHARE = 0.15;
    /** Conviction per unit of log mispricing RELATIVE to the market's average mispricing; on the fundamentalist's scale, fully committed at roughly a 40% gap to the average name. */
    public const AGENT_RELATIVE_VALUE_GAIN = 2.50;
}
