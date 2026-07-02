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
    // Fundamental shocks are less frequent and smaller than price-level panics
    public const FUNDAMENTAL_JUMP_INTENSITY_SCALE = 0.25;
    public const FUNDAMENTAL_JUMP_MEAN_SCALE = 0.50;
    public const FUNDAMENTAL_JUMP_VOL_SCALE = 0.50;

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

    // --- Valuations ---
    public const BASELINE_MARKET_PE = 15.0;
    public const NEGATIVE_EPS_FALLBACK_PE = 35.0; // Legacy fallback, transitioning to P/S

    // --- Brokerage & Lending ---
    public const MARGIN_LOAN_SPREAD = 0.03;
    public const BROKERAGE_MAX_EQUITY_TURNOVER = 15.0; // Brokerages have massive top-line turnover relative to pure equity

    // --- Institutional & Market Architecture ---
    public const CUSTODY_CLEARING_SPREAD = 0.0015; // 15 bps spread kept by clearinghouses on margin pools
    public const EMERGENCY_DEBT_SPREAD_PENALTY = 0.02; // +200 bps penalty rate for emergency liquidity borrowing
    public const MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER = 0.40; // 40% single-quarter stock price limit up/down
    public const YIELD_CURVE_INVERSION_SENSITIVITY = 15.0; // Standardized duration sensitivity for NIM compression
}
