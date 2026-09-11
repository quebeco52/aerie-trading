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
    /** Weight applied to historical smoothed EPS when updating trailing twelve month earnings. */
    public const EPS_TTM_SMOOTHING_OLD_WEIGHT = 0.60;
    /** Weight applied to latest quarterly annualized EPS in TTM smoothing. */
    public const EPS_TTM_SMOOTHING_NEW_WEIGHT = 0.40;

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
    /** Baseline interest yield earned on corporate short-term cash reserves. */
    public const BASE_CASH_YIELD_TARGET_RATIO = 0.05;
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
    /** Defensive fallback P/E multiple used when earnings are negative. */
    public const NEGATIVE_EPS_FALLBACK_PE = 35.0;
    /** Baseline long-term stable GDP growth rate for Gordon Growth valuation. */
    public const DEFAULT_PERPETUAL_GROWTH_RATE = 0.02;
    /** Absolute floor on intrinsic fundamental P/E multiple. */
    public const MIN_INTRINSIC_PE = 4.0;
    /** Absolute ceiling on intrinsic fundamental P/E multiple. */
    public const MAX_INTRINSIC_PE = 35.0;

    // --- Relative Valuation Shrinkage (Vasicek 1973) ---
    /** Spread (cost of equity less growth) at which a firm's own Gordon multiple and its sector's carry equal weight. */
    public const INTRINSIC_PE_SHRINKAGE_SPREAD = 0.03;
    /** Maximum fallback capitalization multiple when DCF denominator approaches zero. */
    public const DCF_FALLBACK_MULTIPLIER = 60.0;
    /** Fundamental cap on free cash flow capitalization multiple (~33.3x or 3% FCF yield). */
    public const MAX_DCF_MULTIPLIER = 33.33;
    /** Baseline dividend growth rate for Dividend Discount Model valuations. */
    public const DEFAULT_DDM_GROWTH_RATE = 0.01;
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
    /** Turnover multiple cap on brokerage revenue scaling relative to total equity. */
    public const BROKERAGE_MAX_EQUITY_TURNOVER = 15.0;

    // --- Institutional & Market Architecture ---
    /** Margin spread (15 bps) earned by clearinghouses and custodians on client margin pools. */
    public const CUSTODY_CLEARING_SPREAD = 0.0015;
    /** Penalty credit spread (+200 bps) incurred when issuing emergency liquidity debt. */
    public const EMERGENCY_DEBT_SPREAD_PENALTY = 0.02;
    /** Circuit breaker limiting quarterly stock price movements to +/-40%. */
    public const MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER = 0.40;
    /** Duration sensitivity scalar converting yield curve inversion into NIM compression. */
    public const YIELD_CURVE_INVERSION_SENSITIVITY = 15.0;

    // --- Market Saturation & Bureaucratic Bloat ---
    /** Baseline total addressable market size ($1T) for standard corporate sectors. */
    public const BASELINE_SECTOR_TAM = 1_000_000_000_000.00;
    /** Price elasticity of demand parameter in Cournot market share competition. */
    public const COURNOT_DEMAND_ELASTICITY = 1.25;
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
    /** Price reaction to a warning, as a fraction of the warned shortfall ratio. Warnings are punished on the day they are issued, not on the report. */
    public const PREANNOUNCEMENT_PRICE_REACTION = 0.35;
    /** Ceiling on the single-tick repricing a warning may cause. One disclosure moves less than a whole quarter, so it sits inside the 40% MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER that bounds the report around it. */
    public const MAX_PREANNOUNCEMENT_PRICE_REACTION = 0.25;
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

    // --- Industry Exit & Consolidation ---
    /** Share of a failed rival's addressable market that surviving peers in the same industry recapture; the rest leaks to substitutes or is destroyed. */
    public const MARKET_EXIT_RECAPTURE_FRACTION = 0.70;

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
    /** Regulatory leverage ratio threshold below which a financial institution is underleveraged. */
    public const FINANCIAL_UNDERLEVERAGED_RATIO = 0.80;
    /** Debt-to-equity ratio threshold below which a corporate entity is underleveraged. */
    public const CORPORATE_UNDERLEVERAGED_RATIO = 0.75;
    /** Safety coverage multiplier required above minimum interest coverage ratio. */
    public const REQUIRED_ICR_SAFETY_MULT = 1.50;
    /** Absolute minimum interest coverage ratio buffer required for discretionary debt issuance. */
    public const MIN_ABSOLUTE_ICR_BUFFER = 2.00;

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
    /** Maximum structural capacity and revenue multiplier relative to dynamic Sector TAM (150%). */
    public const MAX_SECTOR_TAM_CAPACITY_RATIO = 1.50;
    /** Maximum structural capacity and revenue multiplier for financial intermediaries relative to dynamic TAM (250%). */
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

    // --- Market Microstructure: Impact (Almgren & Chriss 2005) ---
    /** Square-root law coefficient. At 1.0 the canonical statement holds: trading one full day's volume moves the price by about one daily standard deviation. */
    public const PERMANENT_IMPACT_GAMMA = 1.00;
    /** Temporary impact as a share of the permanent move. The price walks to its new level while the order fills, so the taker's average fill is the midpoint of that walk: exactly one half. */
    public const TEMPORARY_IMPACT_ETA = 0.50;
    /** Largest multiple of average daily volume a single order may consume. Past it the square-root law is extrapolation, and a capped impact would be a free lunch for size. */
    public const MAX_ORDER_ADV_MULTIPLE = 2.00;
    /** Flat half-spread on a broad index ETF. Creation and redemption keep it pinned to the basket, so it quotes tighter than any single constituent. */
    public const ETF_HALF_SPREAD = 0.0001;
    /** Flat half-spread on a sovereign bond, the deepest instrument on the desk. */
    public const BOND_HALF_SPREAD = 0.00005;

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

    // --- Agent Capital & Positioning ---
    /** Total agent capital per name, as a multiple of its average daily volume. Sets how large the simulated institutional book is relative to the market it trades in. */
    public const AGENT_CAPITAL_ADV_MULTIPLE = 3.00;
    /** Share of the gap to its target an agent closes each tick. Real books are worked over days, not fired in one print. */
    public const AGENT_POSITION_ADJUSTMENT_SPEED = 0.04;
    /** Overall dial on agent activity. The single number to turn when handing more of the market's variance from the diffusion to the agents. */
    public const AGENT_FLOW_INTENSITY = 1.00;

    // --- Agent Signals ---
    /** Fundamentalist conviction per unit of log mispricing: fully committed at roughly a 40% discount to fair value. */
    public const AGENT_FUNDAMENTALIST_GAIN = 2.50;
    /** Chartist conviction per unit of accumulated price trend. */
    public const AGENT_MOMENTUM_GAIN = 3.00;
    /** Share of the other agents' net book a market maker stands against. It is the counterparty, so it is short when the market is long. */
    public const AGENT_MAKER_ABSORPTION = 0.35;
    /** Speed a market maker works its inventory back toward flat, independent of what it is absorbing. */
    public const AGENT_MAKER_INVENTORY_DECAY = 0.10;
    /** Sensitivity of index fund flows to financial conditions: money leaves passive vehicles when conditions tighten. */
    public const AGENT_INDEX_FLOW_SENSITIVITY = 0.50;
    /** Baseline share of agent capital that indexes rather than picking. */
    public const AGENT_INDEX_BASE_SHARE = 0.30;
}
