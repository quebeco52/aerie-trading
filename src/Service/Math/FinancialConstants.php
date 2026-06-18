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

    // --- Macro Factors ---
    public const TECH_OPERATING_LEVERAGE = 0.25;
    public const STANDARD_OPERATING_LEVERAGE = 0.15;
    public const BANK_OPERATING_LEVERAGE = 0.05;
}
