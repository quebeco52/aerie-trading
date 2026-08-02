<?php

namespace App\Service\Math;

/**
 * Defines core economic rules and limits for the simulation, making it easier to balance 
 * the simulation's boom/bust cycles without digging through mathematical physics files.
 */
class FinancialConstants
{
    // --- Earnings & Volatility Tuning ---
    public const SURPRISE_Z_SCORE_THRESHOLD = 1.5;
    public const BORING_Z_SCORE_THRESHOLD = 0.5;
    public const VOLATILITY_SHOCK_FACTOR = 0.2;
    public const VOLATILITY_COOLING_FACTOR = 0.25;
    public const MAX_VOLATILITY_MULTIPLIER = 3.0;
    public const MIN_OPERATING_BASE_CASH = 10000000.0;

    // --- Leverage Effect (Black, 1976) ---
    // Negative earnings surprises spike volatility harder than positive ones
    public const NEGATIVE_SURPRISE_VOL_MULTIPLIER = 1.4;

    // --- Jump Diffusion (Fundamental vs Price) ---
    /** Scale factor for fundamental jump intensity relative to price jumps. */
    public const FUNDAMENTAL_JUMP_INTENSITY_SCALE = 0.25;
    /** Scale factor for fundamental jump mean size relative to price jumps. */
    public const FUNDAMENTAL_JUMP_MEAN_SCALE = 0.50;
    /** Scale factor for fundamental jump volatility relative to price jumps. */
    public const FUNDAMENTAL_JUMP_VOL_SCALE = 0.50;

    // --- SVJJ & Jump Diffusion Limits ---
    /** Maximum individual upside jump cap (~+300% or log(4.0)) to prevent runaway price spikes. */
    public const MAX_JUMP_LOG_RETURN = 1.38;
    /** Minimum individual downside jump floor (~-90% or log(0.10)) to prevent fractional penny wipeouts. */
    public const MIN_JUMP_LOG_RETURN = -2.30;

    // --- EPS Smoothing ---
    public const EPS_TTM_SMOOTHING_OLD_WEIGHT = 0.60;
    public const EPS_TTM_SMOOTHING_NEW_WEIGHT = 0.40;

    // --- Price Gap Dampening ---
    public const PRICE_GAP_DAMPENING = 0.20;
    public const MAX_PRICE_GAP = 0.25;

    // --- Cash Hoarding & Balances ---
    public const TARGET_OPERATING_CASH_RATIO = 0.05;
    public const MIN_OPERATING_CASH_RATIO = 0.03;
    public const BASE_CASH_YIELD_TARGET_RATIO = 0.05;

    public const HOARDER_THRESHOLD_RATIO = 0.25;
    public const MEGA_HOARDER_THRESHOLD_RATIO = 0.40;

    // --- Gordon Growth & Perpetual Valuation Bounds ---
    /** Absolute minimum hurdle rate (~4% COE) to prevent Gordon Growth divergence under extreme distress. */
    public const MIN_COST_OF_EQUITY = 0.04;
    /** Absolute perpetual growth floor (-5%) for contracting or liquidation-stage firms. */
    public const MIN_PERPETUAL_GROWTH_RATE = -0.05;
    /** Absolute perpetual growth ceiling (6%) to prevent exceeding long-term nominal GDP growth. */
    public const MAX_PERPETUAL_GROWTH_RATE = 0.06;

    // --- Valuations ---
    public const BASELINE_MARKET_PE = 15.0;
    public const NEGATIVE_EPS_FALLBACK_PE = 35.0; // Legacy fallback, transitioning to P/S
    public const DEFAULT_PERPETUAL_GROWTH_RATE = 0.02;
    public const MIN_INTRINSIC_PE = 4.0;
    public const MAX_INTRINSIC_PE = 35.0;
    public const DCF_FALLBACK_MULTIPLIER = 60.0;
    public const MAX_DCF_MULTIPLIER = 33.33;
    public const DEFAULT_DDM_GROWTH_RATE = 0.01;
    public const MIN_PS_FALLBACK_MULT = 0.2;
    public const MAX_PS_FALLBACK_MULT = 5.0;
    public const MIN_INTRINSIC_PB = 0.40;
    public const MAX_INTRINSIC_PB = 10.0;
    public const MAX_REVERSION_FORCE_CAP = 15.0;
    public const ESTAR_ARBITRAGE_ELASTICITY = 2.0;
    public const FUNDING_LIQUIDITY_STRESS_FACTOR = 2.0;

    // --- Brokerage & Lending ---
    public const MARGIN_LOAN_SPREAD = 0.03;
    public const BROKERAGE_MAX_EQUITY_TURNOVER = 15.0; // Brokerages have massive top-line turnover relative to pure equity

    // --- Institutional & Market Architecture ---
    public const CUSTODY_CLEARING_SPREAD = 0.0015; // 15 bps spread kept by clearinghouses on margin pools
    public const EMERGENCY_DEBT_SPREAD_PENALTY = 0.02; // +200 bps penalty rate for emergency liquidity borrowing
    public const MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER = 0.40; // 40% single-quarter stock price limit up/down
    public const YIELD_CURVE_INVERSION_SENSITIVITY = 15.0; // Standardized duration sensitivity for NIM compression

    // --- Market Saturation & Bureaucratic Bloat (Diseconomies of Scale) ---
    public const BASELINE_SECTOR_TAM = 2000000000000.0;
    public const COURNOT_DEMAND_ELASTICITY = 1.25;
    public const DISECONOMY_OPTIMAL_SHARE_THRESHOLD = 0.50;
    public const DISECONOMY_FRICTION_COEFF = 0.20;
    public const CAPITAL_MARGINAL_ELASTICITY = 0.50;
    public const SYSTEMIC_MOAT_FACTORS = [
        'titan'    => 0.30,
        'systemic' => 0.75,
        'base'     => 0.90,
        'default'  => 1.00,
    ];

    // --- Business Model / Strategy Baseline Constants ---
    
    // Operating Physics
    public const TTM_SMOOTHING_NEW_WEIGHT = 0.25;
    public const TTM_SMOOTHING_OLD_WEIGHT = 0.75;
    public const REVERSION_COMPETITIVE_EROSION_ALPHA = 0.50;
    public const REVERSION_DISTRESS_PERSISTENCE = 0.60;
    public const REVERSION_DISTRESS_GAMMA = 1.00;

    // Margin Clamping
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;
    
    // Capital Allocation
    public const BUYBACK_SPEND_NORMAL_RATIO = 0.10;
    public const BUYBACK_SPEND_MEGA_HOARDER_RATIO = 0.30;
    public const ORGANIC_CAPEX_DEBT_RATIO = 0.75;

    // Debt Physics
    public const DEBT_EXPANSION_BASE_PROB = 0.40;
    public const DEBT_EXPANSION_PROB_MULT = 0.50;
    public const DEBT_EXPANSION_BASE_AGGR = 0.05;
    public const DEBT_EXPANSION_AGGR_MULT = 0.35;
    public const WACC_ARBITRAGE_BUFFER = 0.01;
    public const FINANCIAL_UNDERLEVERAGED_RATIO = 0.80;
    public const CORPORATE_UNDERLEVERAGED_RATIO = 0.75;
    public const REQUIRED_ICR_SAFETY_MULT = 1.50;
    public const MIN_ABSOLUTE_ICR_BUFFER = 2.00;

    // Valuation Weights
    public const FAIR_VALUE_EARNINGS_WEIGHT = 0.90;
    public const FAIR_VALUE_BOOK_WEIGHT = 0.10;
    public const FAIR_VALUE_DDM_WEIGHT = 0.15;
}
