<?php

declare(strict_types=1);

namespace App\Tests\Support\Model;

/**
 * The same trait stack as {@see BareStandardModel} with every gated constant declared.
 *
 * Each default in the standard traits sits behind `defined('static::CONST')`. Pairing this composer with
 * the bare one lets a test address both sides of a gate: that the fallback is what an undeclared model
 * gets, and that declaring the constant actually reaches the calculation. Values are deliberately far from
 * the fallbacks so a gate that silently stopped being read shows up as a failed assertion rather than as
 * two numbers that happen to agree.
 */
final class ConfiguredStandardModel extends BareStandardModel
{
    // --- Base Model ---
    /** Loading on the firm-wide demand factor; distinct from the bare composer's 0.60. */
    public const FIRM_FACTOR_LOADING = 0.70;
    /** Elasticity of volumes to the macro cycle; distinct from the trait's unit fallback. */
    public const OPERATING_CYCLICALITY = 1.80;
    /** Loading on the sector demand factor; the bare composer sits at zero (one-factor firm model). */
    public const SECTOR_FACTOR_LOADING = 0.30;

    // --- Debt Physics ---
    /** Equity-over-debt spread demanded before levering up; far above the FinancialConstants buffer. */
    public const WACC_ARBITRAGE_THRESHOLD = 0.05;
    /** Coverage floor demanded before levering up; far above the derived safety multiple. */
    public const MIN_RECAP_ICR_FLOOR = 12.0;
    /** Share of the debt tolerance below which the firm counts as under-levered. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.30;

    // --- Valuation ---
    /** Perpetual growth assumed in the DCF terminal value. */
    public const DCF_TERMINAL_GROWTH_RATE = 0.01;
    /** Cap on the DCF relative to the P/E fair value. */
    public const MAX_DCF_TO_PE_CAP_MULT = 3.00;
    /** Discount applied to the earnings multiple when free cash flow is negative. */
    public const NEGATIVE_FCF_VAL_DISCOUNT = 0.40;

    // --- Operating Physics: Coverage ---
    /** Analyst visibility into the quarter before the systemic-importance uplift. */
    public const BASE_COVERAGE_VISIBILITY = 0.55;
    /** Standard deviation of the analyst's own forecast error. */
    public const BASE_COVERAGE_ERROR = 0.02;
    /** Visibility floor the sector never falls below. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.25;

    // --- Operating Physics: Demand and Prices ---
    /** Years for a move in the output gap to reach the order book; the trait falls back to contemporaneous. */
    public const DEMAND_LAG_YEARS = 1.50;
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. */
    public const FX_REVENUE_EXPOSURE = 0.45;
    /** Median pricing-power index for the sector. */
    public const PRICING_POWER_INDEX = 0.90;
    /** Volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.20;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.80;
    /** Macro inflation measure selling prices track; the trait falls back to goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    // --- Operating Physics: Input Basket ---
    /** Shares of the variable cost base bought in each tracked input market. */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.40, 'metals' => 0.20];
    /** Years for spot input moves to reach the cost base. */
    public const INPUT_COST_LAG_YEARS = 1.00;
    /** Years for the recoverable part of an input move to reach selling prices. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 2.00;
    /** Share of the fixed cost base that is payroll. */
    public const FIXED_COST_LABOR_SHARE = 0.20;

    // --- Operating Physics: Balance Sheet and Reporting ---
    /** Capitalised operating leases as a share of the asset base. */
    public const LEASE_LIABILITY_INTENSITY = 0.30;
    /** Stock compensation as a share of revenue. */
    public const STOCK_COMPENSATION_INTENSITY = 0.08;
    /** How hard management leans on accruals to land the quarter on consensus. */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.90;

    // --- Operating Physics: Asset Decay ---
    /** Quarterly margin decay per unit of under-investment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE = 0.10;
    /** Quarterly margin gain scalar per unit of logarithmic over-investment. */
    public const MODERNIZATION_GAIN_RATE = 0.20;
    /** Structural operating margin floor under sustained under-investment. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.05;
    /** Structural operating margin ceiling modernization converges toward. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.40;
}
