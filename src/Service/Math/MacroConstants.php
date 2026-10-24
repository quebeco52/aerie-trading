<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * The macro economy's shared parameters: the policy targets, natural rates, trend growth, index baselines and base
 * spreads that the engine, its subsystems, the formulas here, the sector models, the state snapshot and the pages all
 * read. A parameter only one of them reads lives with that reader (MacroConstantOwnershipTest).
 */
final class MacroConstants
{
    // --- Central Bank & Structural Constraints ---
    /** The Federal Reserve's long-term annual inflation target. */
    public const TARGET_INFLATION = 0.02;

    // --- Structural Rates ---
    /** The baseline natural real rate of interest (r*) representing neutral monetary policy. */
    public const BASE_NATURAL_RATE = 0.015;
    /**
     * The corporate tax rate firms pay at the founding: Singapore's 17% (since 2010, IRAS), the financial centre the
     * District's tax laws are set against. The budget's size is PUBLIC_PURCHASES_TO_GDP.
     */
    public const BASE_CORPORATE_TAX_RATE = 0.17;
    /** The baseline historical equity risk premium expected over risk-free assets. */
    public const BASE_EQUITY_RISK_PREMIUM = 0.045;
    /** The discount to the policy rate representing the yield on corporate treasury cash. */
    public const CASH_YIELD_SPREAD = 0.0025;

    // --- Okun's Law & Diamond-Mortensen-Pissarides Beveridge Curve ---
    /** Structural Non-Accelerating Inflation Rate of Unemployment (NAIRU) baseline. */
    public const NATURAL_UNEMPLOYMENT = 0.04;
    /** Structural baseline job vacancies rate. */
    public const NATURAL_JOB_VACANCIES = 0.045;
    /** Structural equilibrium labor market tightness (theta* vacancy-to-unemployment ratio). */
    public const NATURAL_LABOR_TIGHTNESS = 1.125;

    // --- Energy Shock Jump-Diffusion (Schwartz 1997 Commodity Dynamics) ---
    /** Baseline index value for energy prices (neutral commodity equilibrium). */
    public const ENERGY_BASELINE = 100.0;
    /**
     * CPI level per unit energy-price deviation (~7% CPI weight at ~35% retail pass-through): +60% energy lifts it
     * ~1.5%.
     */
    public const ENERGY_COST_PUSH_TRANSMISSION = 0.025;

    // --- Wholesale Power and Its Carbon Cost (EIA; EPA; Fabra & Reguant 2014) ---
    /** Wholesale power in $/MWh at the index baseline: the four-hub on-peak mean, 2017-2025. */
    public const REFERENCE_POWER_PRICE = 48.91;
    /** Henry Hub in $/MMBtu at the gas index baseline: its monthly mean over the same years. */
    public const REFERENCE_GAS_PRICE = 3.26;
    /**
     * Heat rate (MMBtu/MWh) the US gas-fired fleet actually burns: its operating average, 2017-24 (EIA Electric Power
     * Annual, Table 8.1).
     */
    public const GAS_FLEET_HEAT_RATE = 7.74;
    /** CO2 burning natural gas emits, in tonnes per MMBtu: 53.06 kg (EPA GHG Emission Factors Hub). */
    public const NATURAL_GAS_CO2_TONNES_PER_MMBTU = 0.05306;
    /**
     * Share of the marginal plant's carbon cost the wholesale price carries: over 80% (Fabra & Reguant 2014, Spain's
     * power market under the EU ETS).
     */
    public const CARBON_POWER_PASS_THROUGH = 0.80;

    // --- Natural Gas (Pilipovic 1998; Ramberg & Parsons 2012) ---
    /** Baseline natural gas index (neutral gas-to-oil relationship, the oil index times a unit ratio). */
    public const NATURAL_GAS_BASELINE = 100.0;

    // --- Wholesale Power (Lucia & Schwartz 2002) ---
    /**
     * Baseline wholesale power index: the power price with gas at its baseline and the heat-rate factor at its mean.
     */
    public const WHOLESALE_POWER_BASELINE = 100.0;

    // --- Theory of Storage & Commodity Buffer Stocks (Working 1949, Litzenberger-Rabinowitz 1995) ---
    /** Baseline physical commodity inventory index (neutral buffer stock). */
    public const COMMODITY_INVENTORY_BASELINE = 100.0;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /** Long-run equilibrium baseline volatility during neutral economic conditions. */
    public const MACRO_VOL_BASE_ANCHOR            = 0.15;

    // --- Taylor Rule and the Policy Floor ---
    /**
     * Policy rate that counts as at the floor, the top of the 0-0.25% target range the FOMC held from 2008 to 2015 and
     * in 2020-22.
     */
    public const ZLB_PROXIMITY_THRESHOLD = 0.0025;

    // --- Central Bank Effective Lower Bound & Shadow Rates ---
    /**
     * Effective lower bound on the policy rate: with the target range at 0-0.25% the US effective funds rate sat near
     * 0.1% (0.05-0.22%, 2009-15 and 2020-22).
     */
    public const EFFECTIVE_LOWER_BOUND = 0.001;
    /** Structural upper bound ceiling for nominal monetary policy target rate. */
    public const POLICY_RATE_CEILING = 0.20;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /**
     * Baseline ten-year term premium (Adrian-Crump-Moench 2013: ~115bps average over 1990-2019). Scaled down by
     * duration for shorter tenors; the two-year note carries under a third of it.
     */
    public const NS_BASE_TERM_PREMIUM = 0.0115;
    /**
     * Duration over which the term premium saturates: a two-year note carries under 30% of the ten-year premium. Past
     * ten years only the structural regime keeps rising (a thirty-year bond carries half again as much of it);
     * transitory shocks, the inflation risk premium and the cyclical terms land on the long end one-for-one with the
     * ten-year, since the 10s30s spread is stable through a taper tantrum.
     */
    public const TERM_PREMIUM_DURATION_HORIZON_YEARS = 10.0;
    /**
     * Curvature decay: Diebold-Li (2006) 0.0609 per month, so the forward-guidance hump sits at 2.5 years and moves the
     * 2Y against the 10Y.
     */
    public const SVENSSON_LAMBDA_1 = 0.73;
    /**
     * Bliss (1997) slope decay: how fast the market expects the policy rate to return to neutral (half-life ~2.3 years,
     * one cycle). Loads the 2Y 0.75 and the 10Y 0.32 on the policy gap, the empirical betas; Diebold-Li's 0.73 would
     * give the 10Y only 0.14.
     */
    public const SVENSSON_SLOPE_LAMBDA = 0.30;
    /** Secondary Svensson decay parameter governing the long-term hump. */
    public const SVENSSON_LAMBDA_2 = 0.15;

    // --- Preferred-Habitat Duration Extraction (Vayanos-Vila 2021) ---
    /** Sensitivity of duration-weighted term premium extraction to central bank balance sheet intensity. */
    public const PREFERRED_HABITAT_DURATION_SENSITIVITY = 1.0;

    // --- Merton Structural Corporate Credit Spreads (Merton 1974) ---
    /** Through-the-cycle investment-grade spread (130 bps), the long-run median of IG OAS. */
    public const BASE_CREDIT_SPREAD = 0.013;

    // --- Dual-Tranche Corporate Credit Spreads ---
    /**
     * Multiple of HY over IG spread (~3.3x): 130 bps IG pairs with ~430 bps HY through the cycle, and the ~6.5% IG peak
     * of December 2008 with its ~21.8% HY peak.
     */
    public const HY_BASE_SPREAD_MULTIPLIER = 3.3;

    // --- Size of the Budget ---
    /**
     * Government purchases, consumption and investment, as a share of trend GDP at the founding, and the tax take net
     * of transfers that pays for them (CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE): 15%, a financial centre's,
     * Switzerland's 14.9% 2015-19 (Eurostat gov_10a_main, P3 + P51G), with Singapore's and Hong Kong's consumption at
     * 10.1% (World Bank NE.CON.GOVT.ZS) and the US's purchases 17.5% (BEA NIPA).
     */
    public const PUBLIC_PURCHASES_TO_GDP = 0.15;

    // --- Barro Tax-Smoothing & Automatic Fiscal Stabilizers (Barro 1979) ---
    /**
     * Adjustment speed of the effective tax burden toward its cyclical target (half-life 0.7y), fitted with
     * CreditFiscalSubsystem::FISCAL_STABILIZER_SENSITIVITY; shared, as the market prices how fast a law reaches the
     * rate (App\Service\Market\Pricing\PolicyCapitalization).
     */
    public const FISCAL_ADJUSTMENT_SPEED = 1.0;

    // --- Consumer Sentiment Index & Animal Spirits ---
    /**
     * Where the index sits with unemployment at its natural rate, inflation on target and joblessness flat: 100, the
     * survey's base.
     */
    public const SENTIMENT_TREND_LEVEL = 100.0;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Minimum threshold for balance sheet intervention intensity to be considered active. */
    public const BALANCE_SHEET_ACTIVE_THRESHOLD = 0.0005;

    // --- MUNDELL-FLEMING OPEN ECONOMY (IS-LM-BOP) ---
    /**
     * The mainland Fed's neutral policy rate: its smoothed rule, fitted 1987-2008, rests at 3.43% with core inflation
     * at 2% and the gap closed, the district's r* plus target.
     */
    public const MAINLAND_NEUTRAL_RATE = self::BASE_NATURAL_RATE + self::TARGET_INFLATION;

    // --- The District in Dollars (World Bank NY.GDP.MKTP.CD, 2023) ---
    /** District GDP at the opening: the world's third economy, between China's $18.3T and Germany's $4.6T. */
    public const DISTRICT_GDP_USD = 12.0e12;
    /** Equity volatility above which flight-to-safety flows begin, for both the FX bid and the term premium. */
    public const FLIGHT_TO_SAFETY_VOL_THRESHOLD = 0.25;

    // --- 2-FACTOR CORRELATED OU INDUSTRIAL COMMODITIES ---
    /** Baseline industrial metals index value (neutral equilibrium). */
    public const METALS_BASELINE = 100.0;

    // --- Gold (Barsky, Epstein, Lafont-Mueller & Yoo 2021) ---
    /**
     * Baseline real gold price index: where gold settles with real rates, inflation expectations and confidence at
     * their resting levels.
     */
    public const GOLD_BASELINE = 100.0;

    // --- Exchange Rate ---
    /**
     * Quarterly log-change sd of a small open economy's trade-weighted currency (BIS broad nominal effective rates via
     * FRED, quarter-end months 1994-2019: CHF 2.4%, GBP 2.9%, SEK 2.7%).
     */
    public const EXCHANGE_RATE_QUARTERLY_VOLATILITY = 0.027;

    // --- Foreign Equity Market ---
    /** Opening level of the foreign equity index the sovereign reserve portfolio holds. */
    public const FOREIGN_EQUITY_BASELINE = 100.0;

    // --- GOVERNMENT SPENDING & FISCAL APPROPRIATIONS ---
    /** Baseline government spending index (neutral peacetime budget). */
    public const GOVT_SPENDING_BASELINE = 100.0;
    /**
     * Reversion speed of log real civilian purchases / potential (half-life 2.3y): US federal non-defence plus state
     * and local, 1985-2019 AR(1) on a trend, corrected for NIPA quarterly averaging (Working 1960); the District fields
     * no army, so its purchases are civilian.
     */
    public const GOVT_SPENDING_MEAN_REVERSION = 0.298;
    /**
     * Allied defence spending against its trend burden, as an index; the mainland and its allies buy the District's
     * arms.
     */
    public const ALLIED_DEFENSE_BASELINE = 100.0;
    /**
     * Arms makers' orders per unit of allied defence spending: US defence investment (equipment, structures, R&D) moves
     * 1.54 times total defence purchases (BEA NIPA, annual log changes 1949-2019, se 0.07).
     */
    public const ALLIED_PROCUREMENT_ELASTICITY = 1.54;

    // --- Household Default Rate ---
    /**
     * The District's household default rate over its own cycle (~2.15%): where the rate and its measured trend open.
     */
    public const RETAIL_DEFAULT_LONG_RUN_MEAN = 0.0215;

    // --- 2-FACTOR CORRELATED OU AGRICULTURAL COMMODITIES & WEATHER JUMPS ---
    /** Baseline agricultural commodity index value (neutral crop harvest). */
    public const AGRI_BASELINE = 100.0;

    // --- COBWEB THEOREM FREIGHT RATE INDEX (BALTIC DRY) ---
    /** Baseline ocean freight index value (balanced fleet capacity and trade volume). */
    public const FREIGHT_BASELINE = 100.0;

    // --- INTERBANK LIQUIDITY SPREAD (CIR PROCESS & JUMPS) ---
    /** Baseline interbank liquidity spread (FRA-OIS / TED Spread proxy) under normal conditions. */
    public const INTERBANK_BASELINE_SPREAD = 0.0015;

    // --- SOLOW-SWAN TOTAL FACTOR PRODUCTIVITY (TFP) ---
    /** Baseline index value for Total Factor Productivity (neutral technology baseline). */
    public const TFP_BASELINE = 100.0;
    /**
     * Trend growth of output per worker: 1.5% a year, a mature financial centre's pace, inside the 0.5-2% advanced
     * economies span.
     */
    public const TFP_DRIFT = 0.015;
    /** Labour-force growth from the resident population: 0.54% a year, a settled population still growing slowly. */
    public const NATURAL_LABOR_FORCE_GROWTH = 0.0054;
    /**
     * Net migration the District's average party admits, a year as a share of population (0.50%); each government's
     * regime moves it from there.
     */
    public const STRUCTURAL_NET_MIGRATION_RATE = 0.0050;
    /** Labour-force growth at the average party's migration regime: natural growth plus its newcomers, 1.04% a year. */
    public const STRUCTURAL_LABOR_GROWTH_RATE = self::NATURAL_LABOR_FORCE_GROWTH + self::STRUCTURAL_NET_MIGRATION_RATE;
    /**
     * Trend real growth of potential output: labour force plus productivity, 2.6% a year (advanced economies ran
     * 1.6-3.0% in 1996-2019), the pace a sector with no secular story grows at.
     */
    public const TREND_REAL_GROWTH = self::TFP_DRIFT + self::STRUCTURAL_LABOR_GROWTH_RATE;

    // --- Sovereign Reserve Fund: Opening Size (GPIF, FY2024) ---
    /**
     * Fund over GDP at inception: GPIF's scale, ¥250T of assets on ¥609T of FY2024 nominal GDP (GPIF annual report
     * FY2024; Cabinet Office). The fund subsystem opens at it and its custodian marks the fund's book against it.
     */
    public const OPENING_FUND_TO_GDP = 0.41;

    // --- Sovereign Debt Dynamics (Greenwood-Vayanos 2014) ---
    /**
     * Sovereign debt-to-GDP at simulation start: where the cabinets' debt plans hold it on average (51-55% over a
     * century), so the game opens near its long-run level.
     */
    public const INITIAL_DEBT_TO_GDP = 0.50;
    /**
     * Gross debt kept outstanding with no borrowing need, for the benchmark curve to price (Singapore and Hong Kong
     * issue for market development through surpluses); below it a surplus buys the fund's paper instead.
     */
    public const SOVEREIGN_DEBT_FLOOR = 0.20;

    // --- The Budget Calendar ---
    /** Budget rounds a year apart by half: the budget and its mid-year revision. */
    public const BUDGET_ROUND_PERIOD_YEARS = 0.5;

    // --- Reserve Draw (Singapore Net Investment Returns framework; Constitution of Singapore, art. 142(1A)) ---
    /**
     * Share of the reserve fund's expected long-term real return the budget may spend without the fund's consent: half,
     * Singapore's constitutional limit on the Net Investment Returns Contribution. The share the District opened with.
     */
    public const RESERVE_DRAW_CEILING = 0.50;

    // --- Policy Levers: Tariffs (Fajgelbaum et al. 2020; Furceri et al. 2018) ---
    /**
     * Average tariff partners put on the District's exports per point of its average tariff on imports: 0.60 in 2018,
     * when US tariffs rose 14.0 points on 12.7% of imports and retaliation 13.1 points on 8.2% of exports (Fajgelbaum,
     * Goldberg, Kennedy & Khandelwal 2020).
     */
    public const TARIFF_RETALIATION_RATIO = (0.131 * 0.082) / (0.140 * 0.127);
    /**
     * Output lost per unit of average tariff, through productivity: a 3.6-point rise costs 0.4% of output five years on
     * (Furceri, Hannan, Ostry & Rose 2018: 151 countries, 1963-2014; labour productivity -0.9%). Their symmetric
     * baseline; they find cuts help less than rises hurt.
     */
    public const TARIFF_OUTPUT_LOSS = 0.004 / 0.036;

    // --- Federal Reserve G.17 Industrial Capacity Utilization Index ---
    /** Baseline long-run historical capacity utilization rate (~78.5%). */
    public const CU_BASELINE = 0.785;
    /** Capacity utilization per unit output gap (~1.9): a -6% gap takes CU from 78.5% to ~67%, a +3% gap to ~84%. */
    public const CU_GAP_SENSITIVITY = 1.9;
    /** Sensitivity of capacity utilization to accumulated physical capital stock overhang. */
    public const CU_OVERHANG_SENSITIVITY = 0.40;

    // --- Estrella & Mishkin (1998) Yield Curve Recession Probit ---
    /**
     * What the 12-month recession forecast averages over the District's cycle (~17%; the US spent 13.8% of months in
     * recession, 1945-2019): where the forecast and its measured trend open.
     */
    public const RECESSION_PROBABILITY_LONG_RUN_MEAN = 0.17;
    /** Probit intercept (-0.92): with it the forecast averages RECESSION_PROBABILITY_LONG_RUN_MEAN over the cycle. */
    public const RECESSION_PROBIT_BETA_0 = -0.92;
    /** Probit sensitivity to sovereign yield curve slope (10Y minus policy rate). */
    public const RECESSION_PROBIT_BETA_SLOPE = -80.0;
    /**
     * Adds back half the term premium so the probit reads the expectations component (slope minus premium), not
     * premium-driven steepness (Rosenberg & Maurer 2008).
     */
    public const RECESSION_PROBIT_BETA_TP = 40.0;
    /** Probit sensitivity to financial conditions tightening. */
    public const RECESSION_PROBIT_BETA_FCI = 0.35;

    // --- Corporate Default Dynamics (Moody's all-rated / Altman) ---
    /**
     * Long-run all-rated corporate PD the Vasicek factor is struck at (Moody's: 1.59% over 1983-2019, 1.56% over
     * 1920-2017; speculative grade alone runs about three times it); the District's channels settle the rate lower.
     */
    public const CORPORATE_DEFAULT_BASELINE = 0.016;
    /**
     * The District's all-rated corporate default rate over its own cycle (~1.43%): where the rate and its measured
     * trend open.
     */
    public const CORPORATE_DEFAULT_LONG_RUN_MEAN = 0.0143;

    // --- NY Fed Global Supply Chain Pressure Index (GSCPI - Benigno et al. 2022) ---
    /** Supply-chain pressure in normal times: the index is in its own standard deviations about zero. */
    public const GSCPI_BASELINE = 0.0;
    /**
     * Neutral reading of the suppliers' delivery-times diffusion index (ISM convention: 50 = no change, above =
     * slower).
     */
    public const SUPPLIER_DELIVERIES_NEUTRAL = 50.0;
    /**
     * Standard deviation of delivery times about neutral, measured on the engine (6.2 points): the supply-chain index
     * standardises by it, and so does a firm that reads delivery times beside freight.
     */
    public const SUPPLY_CHAIN_DELIVERY_SD = 6.2;

    // --- 3:2:1 Refining Crack Spread & Distillate Margins (Bourgeon et al. 1998) ---
    /** Baseline long-run equilibrium refining crack spread in $/bbl. */
    public const CRACK_SPREAD_BASELINE = 22.0;

    // --- Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow ---
    /** Baseline neutral capital markets deal flow index (neutral advisory environment). */
    public const DEAL_ACTIVITY_BASELINE = 100.0;

    // --- ISM Manufacturing Purchasing Managers' Index (PMI) ---
    /** Neutral diffusion baseline for ISM manufacturing PMI (50.0 = neutral growth). */
    public const PMI_BASELINE = 50.0;

    // --- Producer Price Index (PPI) Stage-of-Processing ---
    /** Weight of industrial metals price inflation in intermediate producer prices. */
    public const PPI_METALS_WEIGHT = 0.20;
    /** Weight of primary energy and petroleum price inflation in intermediate producer prices. */
    public const PPI_ENERGY_WEIGHT = 0.25;
    /**
     * Horizon of the commodity trends the PPI legs measure a growth rate against; one year is the window BLS publishes
     * commodity PPI changes over.
     */
    public const COMMODITY_TREND_HORIZON_YEARS = 1.0;

    // --- Mundell-Fleming Trade Balance & Net Exports ---
    /**
     * Structural trade balance over GDP (+4.5%): a financial centre that saves more than it invests at home and sells
     * its services abroad runs a surplus, as Germany's 6% and Japan's 2-3% of the 2010s.
     */
    public const TRADE_BALANCE_BASELINE = 0.045;

    // --- DiPasquale-Wheaton Commercial Real Estate ---
    /**
     * Commercial property index at the engine's neutral (100.0): where the fundamental sits with every cap-rate input
     * at its base, which AssetMarketSubsystem::CRE_NEUTRAL_CAP_RATE is built from.
     */
    public const CRE_BASELINE = 100.0;

    // --- Tobin's Q Housing Investment Dynamics (Poterba 1984, Topel-Rosen 1988) ---
    /** Baseline housing starts activity index (100.0 = neutral construction equilibrium). */
    public const HOUSING_STARTS_BASELINE = 100.0;

    // --- Monetarist M2 Broad Money Supply Dynamics (Friedman-Schwartz, Brunner-Meltzer) ---
    /** Baseline structural annual growth rate of M2 money supply matching nominal potential GDP trend. */
    public const M2_BASE_GROWTH = 0.045;

    // --- Systemic Market Factor ---
    /** Market-wide jump arrivals per year, giving the index the discontinuous crash days a diffusion cannot. */
    public const SYSTEMIC_JUMP_INTENSITY = 4.0;
    /** Probability a market-wide jump is upward; below one half, encoding the downward skew of index returns. */
    public const SYSTEMIC_JUMP_PROBABILITY_UP = 0.35;
    /** Decay rate of upward market jumps; the reciprocal is the mean up-jump log return (~2.5%). */
    public const SYSTEMIC_JUMP_ETA_UP = 40.0;
    /** Decay rate of downward market jumps; the reciprocal is the mean crash log return (~3.6%). */
    public const SYSTEMIC_JUMP_ETA_DOWN = 28.0;

    // --- Household Balance Sheet (Mian & Sufi 2018; Drehmann, Illes, Juselius & Santos 2015; Basel III CCyB) ---
    /** Household debt to disposable income at the seeded neutral (unity, the advanced-economy average). */
    public const HOUSEHOLD_DEBT_TO_INCOME_BASELINE = 1.00;
    /**
     * Household debt to disposable income at the opening (0.85): where crises' repayments and the re-borrowing toward
     * the baseline hold it over a century (0.82-0.93 by decade), so the game opens near its long-run level.
     */
    public const OPENING_HOUSEHOLD_DEBT_TO_INCOME = 0.85;
    /**
     * The BIS debt-service ratio at neutral rates and baseline leverage: 0.7 x 5.5% mortgage + 0.3 x 11.5% consumer =
     * 7.3% on an 18-year annuity, 10.2% of income.
     */
    public const HOUSEHOLD_DSR_NEUTRAL = 0.1016;
    /** The debt-service ratio at neutral rates and the opening leverage (8.6% of income). */
    public const OPENING_HOUSEHOLD_DSR = self::HOUSEHOLD_DSR_NEUTRAL * self::OPENING_HOUSEHOLD_DEBT_TO_INCOME / self::HOUSEHOLD_DEBT_TO_INCOME_BASELINE;
    /**
     * Debt-service gap (ratio over its own long-run average) at which households deleverage (Drehmann & Juselius 2012:
     * a DSR ~2pp over its average is the early-warning threshold).
     */
    public const HOUSEHOLD_DSR_STRESS_MARGIN = 0.02;
    /**
     * Spread of the District's floating-rate mortgage over the policy rate (200 bps), read by the housing user cost and
     * the household debt service: the District's homes are bought on variable loans, as in the UK, Australia and Spain.
     */
    public const RESIDENTIAL_MORTGAGE_SPREAD = 0.020;
    /**
     * The mortgage rate at the engine's neutral: the neutral policy rate, r* and the inflation target, plus the
     * mortgage spread (5.5%).
     */
    public const NEUTRAL_MORTGAGE_RATE = self::BASE_NATURAL_RATE + self::TARGET_INFLATION + self::RESIDENTIAL_MORTGAGE_SPREAD;

    // --- Deposits Channel (Drechsler, Savov & Schnabl 2017) ---
    /**
     * System deposit rate per unit of policy rate: 0.46, US banks 1986-2013 (aggregate deposit spread beta 0.54;
     * Drechsler, Savov & Schnabl 2017). The spread banks keep scales with the rate.
     */
    public const SYSTEM_DEPOSIT_BETA_BASE = 0.46;
    /**
     * Share of household liquid assets held in money-market funds at the neutral deposit spread (~15%, the US share
     * outside a hiking cycle).
     */
    public const MMF_SHARE_BASE = 0.15;

    // --- Economic Policy Uncertainty (Baker, Bloom & Davis 2016) ---
    /** Neutral level of the policy-uncertainty index (the BBD index is normalised to a mean of 100). */
    public const EPU_BASELINE = 100.0;

    // --- Administered Healthcare Prices (CMS market-basket update) ---
    /**
     * Productivity offset subtracted from the annual reimbursement update (ACA s.3401 multifactor-productivity
     * adjustment, ~0.6pp a year). Read by the fiscal subsystem and by the state's opening value.
     */
    public const REIMBURSEMENT_PRODUCTIVITY_OFFSET = 0.006;

    // --- District-Wide Systemic Event Triggers ---
    /** Interbank spread (100 bps) marking a genuine wholesale funding freeze rather than routine tightness. */
    public const SYSTEMIC_LIQUIDITY_FREEZE_SPREAD = 0.0100;
    /** High-yield spread (1000 bps) at which speculative-grade primary issuance effectively shuts. */
    public const SYSTEMIC_CREDIT_SEIZURE_SPREAD = 0.1000;
    /**
     * Sovereign risk spread (150 bps) at which the fiscal position is formally re-rated: the level at which an advanced
     * sovereign loses its top rating.
     */
    public const SYSTEMIC_SOVEREIGN_STRESS_SPREAD = 0.015;
    /** Sustained inversion duration (years) that historically precedes a downturn and trips the curve alarm. */
    public const SYSTEMIC_INVERSION_ALARM_YEARS = 0.75;
}
