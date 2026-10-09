<?php

namespace App\Service\Math;

/**
 * Parameters shared by more than one class, or by a trait every sector model composes. A parameter only one class
 * reads lives on that class.
 */
class FinancialConstants
{
    // --- Earnings & Volatility Tuning ---
    /** Minimum operating capital floor ($10M) preventing zero-division in asset-light scaling. */
    public const MIN_OPERATING_BASE_CASH = 10000000.0;

    // --- SVJJ & Jump Diffusion Limits ---
    /** Maximum individual upside jump cap (+30% or log(1.30)) keeping market shocks bounded. */
    public const MAX_JUMP_LOG_RETURN = 0.2624;
    /** Minimum individual downside jump floor (-30% or log(0.70)) keeping market shocks bounded. */
    public const MIN_JUMP_LOG_RETURN = -0.3567;

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
    /** Minimum structural floor for any business unit to prevent complete segment abandonment. */
    public const DEFAULT_MIN_STREAM_WEIGHT_FLOOR = 0.05;
    /** Maximum structural ceiling for any single business unit to prevent total monopoly capture. */
    public const DEFAULT_MAX_STREAM_WEIGHT_CEILING = 0.85;

    // --- Gordon Growth & Perpetual Valuation Bounds ---
    /** Floor on the spread between a discount rate and a perpetual growth rate (50bp), so a perpetuity never divides by zero. */
    public const MIN_PERPETUAL_GROWTH_SPREAD = 0.005;

    // --- Secular Demand ---
    /** Years between the BEA benchmark shares a sector's demand drift is measured over, 1997 to 2019. */
    public const SECULAR_SHARE_WINDOW_YEARS = 22.0;
    /** Half-life of a sector's demand drift: 8 years, how much of US industries' 1997-2008 GDP-share drift carried into 2008-2019 (BEA GDP by Industry, non-commodity industries). */
    public const SECULAR_EXCESS_HALF_LIFE_YEARS = 8.0;

    // --- Relative Valuation Shrinkage (Vasicek 1973) ---
    /** Absolute floor on price-to-book valuation multiple. */
    public const MIN_INTRINSIC_PB = 0.40;
    /** Absolute ceiling on price-to-book valuation multiple. */
    public const MAX_INTRINSIC_PB = 10.0;

    // --- Brokerage & Lending ---
    /** Net interest margin earned by brokerages on client margin debit balances. */
    public const MARGIN_LOAN_SPREAD = 0.03;

    // --- Market Saturation & Bureaucratic Bloat ---
    /** Ceiling on a firm's displayed share of its addressable market; no firm serves all of one. */
    public const MAX_ADDRESSABLE_MARKET_SHARE = 0.9999;
    /** Market share threshold (50%) beyond which Penrose bureaucratic bloat accelerates. */
    public const DISECONOMY_OPTIMAL_SHARE_THRESHOLD = 0.50;
    /** Competitive moat dampeners protecting industry titans from market share erosion. */
    public const SYSTEMIC_MOAT_FACTORS = [
        'titan'    => 0.70,
        'systemic' => 0.80,
        'base'     => 0.90,
        'default'  => 1.00,
    ];

    // --- Reporting Calendar ---
    /** Reporting quarters in a year: the factor between a quarterly flow and its annual rate. */
    public const QUARTERS_PER_YEAR = 4.0;

    // --- Operating Physics ---
    /** Weight of current quarter financial performance in trailing twelve month updates. */
    public const TTM_SMOOTHING_NEW_WEIGHT = 0.25;
    /** Floor on a reported annualised return on capital, so one quarter's loss on a thin base cannot read as a -300% return. */
    public const MIN_REPORTED_RETURN = -0.50;
    /** Ceiling on a reported annualised return on capital. */
    public const MAX_REPORTED_RETURN = 1.00;

    // --- Capital Allocation & Life-Cycle Physics ---
    /** Fraction of excess cash allocated to quarterly share repurchases for normal firms. */
    public const BUYBACK_SPEND_NORMAL_RATIO = 0.10;
    /** Fraction of excess cash allocated to quarterly share repurchases for mega cash hoarders. */
    public const BUYBACK_SPEND_MEGA_HOARDER_RATIO = 0.30;
    /** Proportion of newly issued debt proceeds required to fund organic capital expenditures. */
    public const ORGANIC_CAPEX_DEBT_RATIO = 0.75;

    // --- Dividend Smoothing (Lintner 1956) ---
    /** Share of the gap to the target dividend closed in a year: Fama & Babiak (1968) firm-level mean. */
    public const LINTNER_ANNUAL_ADJUSTMENT_SPEED = 0.32;

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

    // --- Bank Capital Requirement (Basel III) ---
    /** CET1 requirement on the District's banks at Year 1, as a share of risk-weighted assets, countercyclical buffer aside: it leaves Lakebird's opening CET1 of 13.1% about 3.7pp over it, near the 3.4pp median headroom the 17 largest banks of the advanced economies held over theirs at end-2024 (Pillar 3 disclosures). The Financial Regulator sets the requirement in force (MacroStateDTO::bankCapitalRequirement). */
    public const OPENING_BANK_CAPITAL_REQUIREMENT = 0.094;

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
    public const DEFAULT_INPUT_COST_EXPOSURES = ['energy' => 0.05, 'gas' => 0.0, 'metals' => 0.05, 'agri' => 0.02, 'freight' => 0.03, 'ppi' => 0.35, 'labor' => 0.30];
    /** Default years for the recoverable share of an input move to reach selling prices (Nakamura & Steinsson 2008 price durations). */
    public const DEFAULT_INPUT_PASS_THROUGH_LAG_YEARS = 0.75;
    /** Stream-state key: lagged relative input cost level of the basket (fraction above baseline). */
    public const STATE_INPUT_COST_LEVEL = 'state:input_cost_level';
    /** Stream-state key: lagged share of the input cost level already recovered in selling prices. */
    public const STATE_INPUT_COST_RECOVERY = 'state:input_cost_recovery';

    // --- FX Exposure ---
    /** Base level of the trade-weighted exchange rate index, against which a move is measured as a relative deviation. */
    public const FX_INDEX_BASE = 100.0;
    /** Default share of revenue exposed to the exchange rate: a mostly domestic firm meeting a little imported competition. */
    public const DEFAULT_FX_REVENUE_EXPOSURE = 0.05;

    // --- Demand Transmission Lag ---
    /** Default years for a move in the output gap to reach a firm's order book: none, for a business that sells at the moment demand appears. */
    public const DEFAULT_DEMAND_LAG_YEARS = 0.0;

    // --- Analyst Cost-Base Visibility ---
    /** Share of the realized variable cost ratio analysts forecast correctly: input prices are published series (commodity indices, PPI, wage prints) and pass-through terms disclosed, so only firm-specific execution is left unseen. */
    public const ANALYST_COST_BASE_VISIBILITY = 0.75;

    // --- Own-Price Demand Response ---
    /** Default own-price elasticity of demand for a producing firm (volume lost per unit of real price increase); mid-range of empirical estimates for differentiated goods. */
    public const DEFAULT_PRICE_ELASTICITY_OF_DEMAND = 0.50;

    // --- Industry Share Dynamics ---
    /** Default share of a firm's idiosyncratic revenue gain that is taken from same-industry peers rather than won from a larger market (Berry-style substitution). */
    public const DEFAULT_INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Industry Capacity & Cournot Pricing ---
    /** Industry price elasticity of demand: the inverse demand curve P ~ Q^(-1/e) that installed capacity is sold into (Cournot). */
    public const COURNOT_DEMAND_ELASTICITY = 1.25;
    /** Largest fraction by which the industry capacity balance may move a single firm's realized price level, either way. */
    public const MAX_INDUSTRY_PRICE_RESPONSE = 0.30;

    // --- Balance Sheet Realism ---
    /** Default capitalized operating lease liability (IFRS 16 / ASC 842) as a fraction of annual revenue. */
    public const DEFAULT_LEASE_LIABILITY_INTENSITY = 0.05;
    /** Default stock-based compensation (ASC 718) as a fraction of revenue, non-cash expense and real dilution: 1.57% for the US total market, 5,994 firms (Damodaran, Employee data by industry, January 2026). */
    public const DEFAULT_STOCK_COMPENSATION_INTENSITY = 0.0157;

    // --- Labor Intensity ---
    /** Payroll share of the cost base for a model with neither its own FIXED_COST_LABOR_SHARE nor a measured input basket. */
    public const DEFAULT_FIXED_COST_LABOR_SHARE = 0.65;

    // --- Debt Physics ---
    /** Fraction of fixed-rate debt that matures and reprices at market each quarter (5-year average tenor). */
    public const DEFAULT_QUARTERLY_DEBT_ROLLOVER = 0.05;
    /** Target leverage as a share of the debt tolerance for a model without its own; below it the firm is under-levered. */
    public const CORPORATE_UNDERLEVERAGED_RATIO = 0.75;
    /** Safety coverage multiplier required above minimum interest coverage ratio. */
    public const REQUIRED_ICR_SAFETY_MULT = 1.50;
    /** Absolute minimum interest coverage ratio buffer required for discretionary debt issuance. */
    public const MIN_ABSOLUTE_ICR_BUFFER = 2.00;
    /** Dampen double-counting of historical debt when re-levering Beta through the Hamada equation. */
    public const HAMADA_DAMPENING_FACTOR = 0.25;

    // --- Payment Default & Cure Period ---
    /** Consecutive quarters a missed principal payment may stand before the firm is a defaulted issuer rather than a late one (standard 30-day indenture grace, rounded to the reporting period). */
    public const PAYMENT_DEFAULT_GRACE_QUARTERS = 1;

    // --- Valuation Consensus Weights ---
    /** Consensus weight given to book value/liquidation fair value in valuation blending. */
    public const FAIR_VALUE_BOOK_WEIGHT = 0.10;

    // --- Asymmetric Cost Stickiness (Anderson, Banker, & Janakiraman 2003) ---
    /** Elasticity of operational variable expenses to revenue growth (beta 1). */
    public const STICKY_COST_BETA_EXPANSION = 0.85;
    /** Downward stickiness penalty reducing expense contraction during revenue declines (beta 2 < 0). */
    public const STICKY_COST_BETA_CONTRACTION_PENALTY = -0.40;
    /** Floor on the committed cost base: property, insurance, minimum maintenance and core staffing survive any restructuring short of liquidation. */
    public const MIN_COMMITTED_COST_SCALE = 0.50;

    // --- Earnings Management (Burgstahler & Dichev 1997) ---
    /** Default propensity to manage reported earnings toward consensus (0 = never, 1 = closes every gap it can reach). */
    public const DEFAULT_EARNINGS_MANAGEMENT_PROPENSITY = 0.50;

    // --- Working Capital Ledger ---
    /** Share of a positive working capital cycle carried as receivables; the rest is inventory (Compustat medians). */
    public const WORKING_CAPITAL_RECEIVABLE_SHARE = 0.55;
    /** Payables carried as a fraction of the gross receivable-plus-inventory cycle, the standard trade-credit offset. */
    public const WORKING_CAPITAL_PAYABLE_SHARE = 0.35;
    /** Days in the accounting year used to convert day counts into balances. */
    public const DAYS_PER_YEAR = 365.0;

    // --- Inventory & Receivable Impairment ---
    /** Loss given default on a trade receivable: unsecured, but with real recovery in liquidation. */
    public const TRADE_RECEIVABLE_LGD = 0.60;

    // --- Fixed Asset Ledger (PP&E) ---
    /** Accumulated depreciation as a share of gross PP&E at seed; the median US non-financial runs a half-aged plant. */
    public const SEED_ASSET_AGE_RATIO = 0.50;

    // --- Investment Securities & AOCI (ASC 320 / Basel III) ---
    /** Share of a financial's securities book carried as held-to-maturity: disclosed at amortized cost, never marked through equity. */
    public const DEFAULT_HTM_BOOK_SHARE = 0.40;

    // --- Sovereign Bond Desk ---
    /** Face value of a single sovereign bond, redeemed at maturity and the base every coupon is struck against. */
    public const BOND_FACE_VALUE = 1000.0;
    /** Coupon payments per year. Sovereign convention is semi-annual. */
    public const BOND_COUPON_FREQUENCY = 2;
    /** Ceiling on years-to-maturity treated as outstanding; past it the issue is redeemed and stops trading. */
    public const BOND_MATURITY_EPSILON = 1.0e-6;
    /** Tenors the curve is sampled at for display. Dense at the front, where the curve actually bends. */
    public const BOND_CURVE_SAMPLE_TENORS = [0.25, 0.5, 1.0, 2.0, 3.0, 5.0, 7.0, 10.0, 15.0, 20.0, 30.0];
    // --- Market Microstructure: Volume & Liquidity ---
    /** Trading days a simulated year is divided into when expressing average daily volume. */
    public const TRADING_DAYS_PER_YEAR = 252.0;

    // --- Stamp Duty (Colliard & Hoffmann 2017) ---
    /** Stamp duty on a transfer of listed shares at the founding, charged to buyer and seller each and paid into the sovereign reserve fund (District rate; Hong Kong charges 0.1% a side, the UK 0.5% on purchases). The Diet sets the rate in force (MacroStateDTO::stampDutyRate). */
    public const STAMP_DUTY_RATE = 0.0005;
    /** Stream-state key: the share of a firm's revenue, less its variable cost, that moves with the District's share turnover, at the founding duty; the market prices a change in the duty against it. */
    public const STATE_STAMP_DUTY_TURNOVER_SHARE = 'state:stamp_duty_turnover_share';

    // --- Market Microstructure: Spread (Wyart, Bouchaud, Kockelkoren, Potters & Vettorazzo 2008) ---
    /** Floor on the quoted half-spread as a fraction of price (0.5bp): crossing a mega-cap is cheap, never free. */
    public const MIN_HALF_SPREAD = 0.00005;

    // --- Market Microstructure: Impact (Almgren, Thum, Hauptmann & Li 2005) ---
    /** Share of the peak move that stays in the price; the rest relaxes away. Metaorder impact settles at ~2/3 of its peak (Farmer, Gerig, Lillo & Waelbroeck 2013; Bershova & Rakhlin 2013). */
    public const PERMANENT_IMPACT_SHARE = 2.0 / 3.0;
    /** Floor on a fund's half-spread. Creation and redemption keep a broad fund close to its basket, so it quotes tighter than any single constituent — but never tighter than this. */
    public const ETF_HALF_SPREAD = 0.0001;

    // --- Listed Equity Options ---
    /** Shares one contract is written on, the listed convention. Every premium here is quoted PER SHARE and multiplied by this only where cash actually moves. */
    public const OPTION_CONTRACT_MULTIPLIER = 100;
    /** Average daily volume a name must trade before a class is opened on it; exchanges list options against a float and a trading record, not against every listed company. */
    public const OPTION_LISTING_MIN_ADV = 50000.0;
    /** Price a name must hold to carry a class. Below it the round-increment ladder has no usable strikes and every contract is one tick wide. */
    public const OPTION_LISTING_MIN_PRICE = 5.00;

    // --- Market Index Membership (Shleifer 1986) ---
    /** Reconstitutions per year. */
    public const INDEX_RECONSTITUTIONS_PER_YEAR = 4;
    /** Level the index opens at on a market with no history. An index base is a convention, not a measurement: what carries meaning is the return from it. */
    public const INDEX_BASE_LEVEL = 100.0;

    // --- Index Diversification Caps (RIC / UCITS 5-10-40, as applied by the S&P Select Sector indices) ---
    /** Weight above which a constituent counts toward the concentration budget below. */
    public const INDEX_CONCENTRATION_THRESHOLD = 0.045;
    /** Most the constituents above that threshold may weigh in combination. */
    public const INDEX_CONCENTRATION_BUDGET = 0.45;

    // --- Index Fund Accounting ---
    /** Distributions a fund pays per year. Quarterly, matching both the constituents' own dividend cycle and the reconstitution calendar. */
    public const FUND_DISTRIBUTIONS_PER_YEAR = 4;

    // --- Corporate Bond Issuance ---
    /** Share of a firm's wholesale debt that is funded in the PUBLIC bond market rather than by banks. The listed issues are a tranche of the debt the balance sheet already carries, never additional borrowing. */
    public const CORPORATE_PUBLIC_DEBT_SHARE = 0.50;
    /** Issues a firm keeps outstanding at once. Sets the steady-state size of the corporate ladder directly: a firm holding this many issues holds this many, whatever the tenors are. */
    public const CORPORATE_LADDER_ISSUES = 3;
    /** Smallest face a single issue may be brought at. A gap smaller than this waits rather than bringing a deal nobody would underwrite. */
    public const CORPORATE_MIN_ISSUE_FACE = 5.0e7;

    // --- Margin Accounts (Regulation T) ---
    /** Equity a new position must be backed by: half of what it is worth, long or short. */
    public const INITIAL_MARGIN_REQUIREMENT = 0.50;
    /** Equity a long position must keep behind it before the account is called. */
    public const MAINTENANCE_MARGIN_LONG = 0.25;
    /** Higher for a short, because a short's loss is unbounded while a long's stops at zero. */
    public const MAINTENANCE_MARGIN_SHORT = 0.30;

    // --- Securities Lending ---
    /** Share of the public float that is actually lendable; the rest sits with holders who do not lend. */
    public const DEFAULT_LENDABLE_SUPPLY_RATIO = 0.65;
    /** Utilization past which lenders begin recalling stock and shorts are bought in. */
    public const BUY_IN_UTILIZATION_THRESHOLD = 0.97;
    // --- Attention-Driven Retail (Barber & Odean 2008, "All That Glitters") ---
    /** Memory of what retail has noticed, in years (one trading day): Barber & Odean sort on the previous day's return, volume and news, so a tick's worth of it is the same day at any tick rate. */
    public const AGENT_RETAIL_ATTENTION_HORIZON_YEARS = 1.0 / 252.0;

    // --- Environmental Regulation (Greenstone, List & Syverson 2012) ---
    /** Productivity polluting plants lose under the strictest air-quality rules: 4.8% of TFP for plants in counties out of attainment, corrected for price rises and for the plants that close (NBER w18392, 1.2 million US plant-years 1972-1993). Manufacturing evidence, the nearest on record for the rules on extraction. */
    public const ENVIRONMENTAL_REGULATION_TFP_LOSS = 0.048;
    /** Stream-state key: the share of a mine's or field's revenue the rules on extraction scale as cost, before them; the market prices a change in the rules against it. */
    public const STATE_EXTRACTION_COST_SHARE = 'state:extraction_cost_share';
    /** Stream-state key: the share of a firm's revenue its earnings before tax gain per unit of power price the carbon price adds (CommodityLogisticsSubsystem::carbonPowerPriceUplift()); the market prices a change in the carbon price against it. */
    public const STATE_CARBON_POWER_EARNINGS_SHARE = 'state:carbon_power_earnings_share';

    // --- ETF Creation, Redemption and the Arbitrage Band (Petajisto 2017; Madhavan 2016) ---
    /** Shares a fund is seeded with, so a creation has a book to be measured against on the first tick. */
    public const ETF_SEED_SHARES_OUTSTANDING = 250_000_000.0;

    // --- Structural Capital Approximation (callers without a balance sheet) ---
    /** Invested capital per unit of revenue (0.5, capital turned about twice a year); the long-standing approximation, of the order of Damodaran's US sales-to-capital tables (not pinned). */
    public const STRUCTURAL_INVESTED_CAPITAL_TO_REVENUE = 0.5;
    /** Invested capital per unit of book equity (1.5, a book debt-to-equity of 0.5); the long-standing approximation, of the order of US non-financial leverage at book (not pinned). */
    public const STRUCTURAL_INVESTED_CAPITAL_TO_BOOK = 1.5;
}
