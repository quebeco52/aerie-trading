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
    /** Baseline prior uncertainty variance in market analyst earnings consensus formation. */
    public const BAYESIAN_BASE_PRIOR_VARIANCE = 0.04;
    /** Multiplier scaling analyst consensus prior uncertainty as VIX rises. */
    public const BAYESIAN_VIX_SCALING_FACTOR = 0.50;

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

    // --- Debt Physics ---
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
}
