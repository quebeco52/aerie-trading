<?php

namespace App\Service\Macro;

use App\DTO\GovernmentPolicyDTO;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use Psr\Log\LoggerInterface;

class MacroEngine
{
    public const REDIS_MACRO_STATE = 'macroeconomic_state';

    // --- Central Bank & Structural Constraints ---
    /** The Federal Reserve's long-term annual inflation target. */
    public const TARGET_INFLATION = 0.02;

    // --- Sector Demand Factor ---
    /** Time constant (years) of the per-sector demand factor read by every firm's earnings physics: an industry upswing or slump persists for about a year rather than resetting each tick. */
    public const SECTOR_DEMAND_PERSISTENCE_YEARS = 1.0;
    /** The baseline natural real rate of interest (r*) representing neutral monetary policy. */
    public const BASE_NATURAL_RATE = 0.015;
    /** Structural lower bound floor for natural real rate. */
    public const MIN_NATURAL_RATE = 0.005;
    /** Structural upper bound ceiling for natural real rate. */
    public const MAX_NATURAL_RATE = 0.035;
    /** The baseline corporate tax rate for standard physical companies. */
    public const BASE_CORPORATE_TAX_RATE = 0.21;
    /** The baseline historical equity risk premium expected over risk-free assets. */
    public const BASE_EQUITY_RISK_PREMIUM = 0.045;
    /** The discount to the policy rate representing the yield on corporate treasury cash. */
    public const CASH_YIELD_SPREAD = 0.0025;

    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /** The rate at which business investment (output gap) accumulates into the physical capital stock. */
    public const CAPITAL_ACCUMULATION_RATE = 0.25;
    /** The rate at which physical capital depreciates, organically clearing overhangs and creating pent-up demand. */
    public const CAPITAL_DECAY_RATE = 0.30;
    /** Asymptotic lower bound floor on physical capital stock contraction / overhang. */
    public const CAPITAL_OVERHANG_MIN = -0.15;
    /** Asymptotic upper bound ceiling on physical capital stock excess capacity / overhang. */
    public const CAPITAL_OVERHANG_MAX = 0.15;
    /** Sensitivity scaling diffusion volatility of output gap and inflation during severe cyclical stress. */
    public const STRESS_MULTIPLIER_GAP_SENSITIVITY = 10.0;

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
    /** Headline inflation per unit energy shock (~7% CPI weight at ~35% retail pass-through): +60% energy adds ~1.5pp. */
    public const ENERGY_COST_PUSH_TRANSMISSION = 0.025;

    // --- Physical Catastrophes (Klugman, Panjer & Willmot compound Poisson; Noy 2009) ---
    /** Relative catastrophe frequency by calendar quarter [Q1..Q4], summing to 4.0: Q3 carries the Atlantic wind season, Q1 the winter freeze and storm peak. Read by the loss process and by the insurers' seasonal threshold. */
    public const CATASTROPHE_SEASONALITY = [0.80, 0.70, 1.90, 0.60];
    /** Stationary standard deviation of the loss index (~1.0 on a mean of 1.0, from lambda E[S^2] / 2 decay with the Pareto tail truncated); the insurers standardise the burden by it. */
    public const CATASTROPHE_LOSS_INDEX_SD = 1.0;

    // --- Natural Gas (Pilipovic 1998; Ramberg & Parsons 2012) ---
    /** Baseline natural gas index (neutral gas-to-oil relationship, the oil index times a unit ratio). */
    public const NATURAL_GAS_BASELINE = 100.0;

    // --- Wholesale Power (Lucia & Schwartz 2002) ---
    /** Baseline wholesale power index: the power price with gas at its baseline and the heat-rate factor at its mean. */
    public const WHOLESALE_POWER_BASELINE = 100.0;

    // --- Theory of Storage & Commodity Buffer Stocks (Working 1949, Litzenberger-Rabinowitz 1995) ---
    /** Baseline physical commodity inventory index (neutral buffer stock). */
    public const COMMODITY_INVENTORY_BASELINE = 100.0;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /** Long-run equilibrium baseline volatility during neutral economic conditions. */
    public const MACRO_VOL_BASE_ANCHOR            = 0.15;

    // --- SVJJ Stochastic Volatility & Contemporaneous Jumps (Duffie, Pan, & Singleton 2000) ---
    /** Mean variance jump (mu_v): a downside jump lifts vol from the 15% anchor to ~19%. Sized so the jumps fund about a fifth of long-run variance, leaving the diffusion an anchor of its own well clear of the 8% floor; at 0.05 they funded 73% of it and the diffusive anchor sat BELOW that floor. */
    public const SVJJ_MU_V = 0.015;

    // --- Taylor Rule & The Evans Rule (Forward Guidance) ---
    /** Inflation panic threshold above which central bank accelerates hiking to Volcker speed. */
    public const CB_INFLATION_PANIC_THRESHOLD = 0.035;
    /** Policy rate that counts as at the floor, the top of the 0-0.25% target range the FOMC held from 2008 to 2015 and in 2020-22. */
    public const ZLB_PROXIMITY_THRESHOLD = 0.0025;

    // --- Central Bank Effective Lower Bound & Shadow Rates ---
    /** Effective lower bound on the policy rate: with the target range at 0-0.25% the US effective funds rate sat near 0.1% (0.05-0.22%, 2009-15 and 2020-22). */
    public const EFFECTIVE_LOWER_BOUND = 0.001;
    /** Structural upper bound ceiling for nominal monetary policy target rate. */
    public const POLICY_RATE_CEILING = 0.20;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /** Baseline ten-year term premium (Adrian-Crump-Moench 2013: ~115bps average over 1990-2019). Scaled down by duration for shorter tenors; the two-year note carries under a third of it. */
    public const NS_BASE_TERM_PREMIUM = 0.0115;
    /** Duration over which the term premium saturates: a two-year note carries under 30% of the ten-year premium. Past ten years only the structural regime keeps rising (a thirty-year bond carries half again as much of it); transitory shocks, the inflation risk premium and the cyclical terms land on the long end one-for-one with the ten-year, since the 10s30s spread is stable through a taper tantrum. */
    public const TERM_PREMIUM_DURATION_HORIZON_YEARS = 10.0;
    /** Curvature decay: Diebold-Li (2006) 0.0609 per month, so the forward-guidance hump sits at 2.5 years and moves the 2Y against the 10Y. */
    public const SVENSSON_LAMBDA_1 = 0.73;
    /** Bliss (1997) slope decay: how fast the market expects the policy rate to return to neutral (half-life ~2.3 years, one cycle). Loads the 2Y 0.75 and the 10Y 0.32 on the policy gap, the empirical betas; Diebold-Li's 0.73 would give the 10Y only 0.14. */
    public const SVENSSON_SLOPE_LAMBDA = 0.30;
    /** Secondary Svensson decay parameter governing the long-term hump. */
    public const SVENSSON_LAMBDA_2 = 0.15;

    // --- Preferred-Habitat Duration Extraction (Vayanos-Vila 2021) ---
    /** Sensitivity of duration-weighted term premium extraction to central bank balance sheet intensity. */
    public const PREFERRED_HABITAT_DURATION_SENSITIVITY = 1.0;

    // --- Merton Structural Corporate Credit Spreads (Merton 1974) ---
    /** IG spread widening per unit of interbank stress (~0.6): 2008's +430 bps TED moved IG OAS ~+450 bps. TED reads the excess bond premium, never IG, so the two share a factor without forming a loop. */
    public const INTERBANK_CREDIT_CONTAGION_SENSITIVITY = 0.6;
    /** IG spread per unit of excess bond premium, direct leg (0.58): GZ spreads map to IG OAS at 0.78 (their 2008 peaks over medians), and 0.6 x 0.32 of that already arrives through TED contagion. */
    public const CREDIT_SPREAD_PREMIUM_LOADING = 0.58;
    /** Through-the-cycle investment-grade spread (130 bps), the long-run median of IG OAS. */
    public const BASE_CREDIT_SPREAD = 0.013;
    /** Log-elasticity of the IG spread to the output gap (distance-to-default): the GZ default-risk part (spread less premium) on the gap holding VIX fixed, 1990-2026, -6.2 (se 1.3). The level alone explains none of it (R2 0.01, 1973-2026): in 2010 it was back at its pre-crisis 2.5% with the gap still -4%. */
    public const MERTON_LEVERAGE_SENSITIVITY = 6.2;
    /** IG default-risk widening per unit of equity volatility above the threshold (0.037): the GZ default-risk part on VIX, 0.047 (se 0.009), at the 0.78 GZ-to-IG scale. 45% vol adds ~90 bps; the rest of a crisis spread is the premium. */
    public const MERTON_VOL_SENSITIVITY = 0.037;
    /** Floor on the investment-grade spread (80 bps), the tightest IG OAS of the 2000s cycle. */
    public const MIN_CREDIT_SPREAD = 0.008;
    /** Cap on the investment-grade spread (650 bps): ICE BofA US Corporate OAS peaked near 620 bps in Dec 2008. */
    public const MAX_CREDIT_SPREAD = 0.065;
    /** Equity volatility above which credit charges a vol premium (~20%, the long-run VIX median), so ordinary vol does not widen IG. */
    public const CREDIT_SPREAD_EXCESS_VOL_THRESHOLD = 0.20;

    // --- Dual-Tranche Corporate Credit Spreads ---
    /** Multiple of HY over IG spread (~3.3x): 130 bps IG pairs with ~430 bps HY through the cycle, and the ~6.5% IG peak of December 2008 with its ~21.8% HY peak. */
    public const HY_BASE_SPREAD_MULTIPLIER = 3.3;

    // --- Barro Tax-Smoothing & Automatic Fiscal Stabilizers (Barro 1979) ---
    /** Structural baseline statutory corporate tax rate. */
    public const TARGET_CORPORATE_TAX_RATE = 0.21;
    /** Adjustment speed of the effective tax burden toward its cyclical target (half-life 0.7y), fitted with CreditFiscalSubsystem::FISCAL_STABILIZER_SENSITIVITY; shared, as the market prices how fast a law reaches the rate (App\Service\Market\PolicyCapitalization). */
    public const FISCAL_ADJUSTMENT_SPEED = 1.0;

    // --- Consumer Sentiment Index & Animal Spirits ---
    /** Baseline consumer sentiment index value (neutral consumer confidence). */
    public const SENTIMENT_BASELINE = 100.0;
    /** Where the index actually sits with output at trend. The baseline is the ceiling the one-sided penalties below hang off, never a mean: measured 88.0 over 600 simulated quarters. */
    public const SENTIMENT_TREND_LEVEL = 88.0;
    /** Index points of sentiment per unit of output gap. The fundamental half of confidence, which a reader already pricing the cycle must take back out. */
    public const SENTIMENT_GAP_LOADING = 385.0;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Minimum threshold for balance sheet intervention intensity to be considered active. */
    public const BALANCE_SHEET_ACTIVE_THRESHOLD = 0.0005;

    // --- MUNDELL-FLEMING OPEN ECONOMY (IS-LM-BOP) ---
    /** The mainland Fed's neutral policy rate: its smoothed rule, fitted 1987-2008, rests at 3.43% with core inflation at 2% and the gap closed (var/harness/mainland_fit.py), the district's r* plus target. */
    public const MAINLAND_NEUTRAL_RATE = self::BASE_NATURAL_RATE + self::TARGET_INFLATION;
    /** Weight of the district's own gap in the global demand that prices its commodities: its share of world GDP. */
    public const DOMESTIC_DEMAND_WEIGHT = self::DISTRICT_GDP_USD / self::WORLD_GDP_USD;

    // --- The District in Dollars (World Bank NY.GDP.MKTP.CD, 2023) ---
    /** District GDP at the opening: the world's third economy, between China's $18.3T and Germany's $4.6T. */
    public const DISTRICT_GDP_USD = 12.0e12;
    /** World GDP: $107.35T. */
    public const WORLD_GDP_USD = 107.35e12;
    /** Equity volatility above which flight-to-safety flows begin, for both the FX bid and the term premium. */
    public const FLIGHT_TO_SAFETY_VOL_THRESHOLD = 0.25;

    // --- 2-FACTOR CORRELATED OU INDUSTRIAL COMMODITIES ---
    /** Baseline industrial metals index value (neutral equilibrium). */
    public const METALS_BASELINE = 100.0;

    // --- Gold (Barsky, Epstein, Lafont-Mueller & Yoo 2021) ---
    /** Baseline real gold price index: where gold settles with real rates, inflation expectations and confidence at their resting levels. */
    public const GOLD_BASELINE = 100.0;

    // --- Exchange Rate ---
    /** Stochastic volatility of exchange rate fluctuations (FX market noise); the reserve portfolio carries it in home terms. */
    public const EXCHANGE_RATE_VOLATILITY = 0.08;

    // --- Foreign Equity Market ---
    /** Opening level of the foreign equity index the sovereign reserve portfolio holds. */
    public const FOREIGN_EQUITY_BASELINE = 100.0;

    // --- GOVERNMENT SPENDING & FISCAL APPROPRIATIONS ---
    /** Baseline government spending index (neutral peacetime budget). */
    public const GOVT_SPENDING_BASELINE = 100.0;
    /** Allied defence spending against its trend burden, as an index; the mainland and its allies buy the District's arms. */
    public const ALLIED_DEFENSE_BASELINE = 100.0;
    /** Arms makers' orders per unit of allied defence spending: US defence investment (equipment, structures, R&D) moves 1.54 times total defence purchases (BEA NIPA, annual log changes 1949-2019, se 0.07). */
    public const ALLIED_PROCUREMENT_ELASTICITY = 1.54;

    // --- VASICEK ASRF RETAIL DEFAULT RATE ---
    /** Baseline long-run average through-the-cycle retail consumer probability of default. */
    public const RETAIL_DEFAULT_BASELINE = 0.025;

    // --- 2-FACTOR CORRELATED OU AGRICULTURAL COMMODITIES & WEATHER JUMPS ---
    /** Baseline agricultural commodity index value (neutral crop harvest). */
    public const AGRI_BASELINE = 100.0;

    // --- COBWEB THEOREM FREIGHT RATE INDEX (BALTIC DRY) ---
    /** Baseline ocean freight index value (balanced fleet capacity and trade volume). */
    public const FREIGHT_BASELINE = 100.0;

    // --- INTERBANK LIQUIDITY SPREAD (CIR PROCESS & JUMPS) ---
    /** Baseline interbank liquidity spread (FRA-OIS / TED Spread proxy) under normal conditions. */
    public const INTERBANK_BASELINE_SPREAD = 0.0015;
    /** Speed of mean reversion (kappa) for the interbank liquidity spread toward baseline. */
    public const INTERBANK_SPREAD_KAPPA = 2.50;
    /** Volatility (sigma) of the continuous interbank liquidity spread diffusion. */
    public const INTERBANK_SPREAD_SIGMA = 0.02;

    // --- SOLOW-SWAN TOTAL FACTOR PRODUCTIVITY (TFP) ---
    /** Baseline index value for Total Factor Productivity (neutral technology baseline). */
    public const TFP_BASELINE = 100.0;
    /** Secular annual drift rate of continuous technological progress. */
    public const TFP_DRIFT = 0.015;
    /** Structural lower bound floor for annual TFP growth rate. */
    public const MIN_TFP_GROWTH_RATE = -0.030;
    /** Structural baseline demographic and labor force growth rate. */
    public const STRUCTURAL_LABOR_GROWTH_RATE = 0.005;

    // --- Sovereign Debt Dynamics (Greenwood-Vayanos 2014) ---
    /** Sovereign debt-to-GDP at simulation start: where the fiscal rule holds it with output at trend (median 0.93, 32 seeds x 100y); the old 0.60 took 40 years to climb out of. */
    public const INITIAL_DEBT_TO_GDP = 0.93;
    /** Debt-to-GDP baseline level below which no excess fiscal term premium applies. */
    public const SOVEREIGN_DEBT_NEUTRAL_THRESHOLD = 0.70;
    /** Gross debt kept outstanding with no borrowing need, for the benchmark curve to price (Singapore and Hong Kong issue for market development through surpluses); below it a surplus buys the fund's paper instead. */
    public const SOVEREIGN_DEBT_FLOOR = 0.20;
    /** Debt-to-GDP above which the market prices fiscal risk and the Council holds its veto over the budget (Reinhart & Rogoff 2010's 90% line). Deliberately above the 70% the Bohn reaction defends: the engine's own steady state runs 85-90%, and a premium charged for that normal state was measured to lift IG 30 bps and 2s10s 45 bps everywhere. */
    public const SOVEREIGN_RISK_DEBT_THRESHOLD = 0.90;

    // --- Fund-Financed Fiscal Stabilisation (IMF Norway Selected Issues 2025, Table 5) ---
    /** Budget rounds a year apart by half: the National Budget each October and the Revised National Budget each May (Norway's Ministry of Finance). */
    public const BUDGET_ROUND_PERIOD_YEARS = 0.5;

    // --- Reserve Draw (Singapore Net Investment Returns framework; Constitution of Singapore, art. 142(1A)) ---
    /** Share of the reserve fund's expected long-term real return the budget may spend without the fund's consent: half, Singapore's constitutional limit on the Net Investment Returns Contribution. The share the District opened with. */
    public const RESERVE_DRAW_CEILING = 0.50;

    // --- Sahm Rule (Sahm 2019, Hamilton Project) ---
    /** Rise of the three-month average unemployment rate above its low of the twelve months before that signals a recession under way: half a point, which has signalled each US recession since 1970 in its first months. */
    public const SAHM_RECESSION_THRESHOLD = 0.005;
    /** Fund-financed rise in the structural balance per unit of output gap a budget round: fitted by indirect inference so the engine's whole discretionary balance, tax leg included, answers the annual gap at Norway's 0.450 (se 0.194); 0.43 with the round reading the gap as it stands (var/harness/stab_cycle.sh). */
    public const FUND_STABILISATION_GAP_RESPONSE = 0.47;
    /** Share of last year's fund-financed impulse reversed this year: Norway's lagged-change coefficient, -0.452 (se 0.093). */
    public const FUND_STABILISATION_IMPULSE_REVERSAL = 0.452;
    /** Yearly decay of the stabilisation back to the rule path: the Auerbach (2002) persistence the tax leg is fitted to, 4.7% a quarter. */
    public const FUND_STABILISATION_PERSISTENCE = 0.175;
    /** Horizon of the long-run average the budget reads the gap against, so the cycle it answers averages to zero: the 15-year window Drehmann & Juselius (2012) separate a cycle from its trend with, about 2.5 postwar NBER cycles. */
    public const FUND_STABILISATION_GAP_TREND_YEARS = 15.0;

    // --- Policy Levers: Tariffs (Fajgelbaum et al. 2020; Furceri et al. 2018) ---
    /** Average tariff partners put on the District's exports per point of its average tariff on imports: 0.60 in 2018, when US tariffs rose 14.0 points on 12.7% of imports and retaliation 13.1 points on 8.2% of exports (Fajgelbaum, Goldberg, Kennedy & Khandelwal 2020). */
    public const TARIFF_RETALIATION_RATIO = (0.131 * 0.082) / (0.140 * 0.127);
    /** Output lost per unit of average tariff, through productivity: a 3.6-point rise costs 0.4% of output five years on (Furceri, Hannan, Ostry & Rose 2018: 151 countries, 1963-2014; labour productivity -0.9%). Their symmetric baseline; they find cuts help less than rises hurt. */
    public const TARIFF_OUTPUT_LOSS = 0.004 / 0.036;

    // --- Federal Reserve G.17 Industrial Capacity Utilization Index ---
    /** Baseline long-run historical capacity utilization rate (~78.5%). */
    public const CU_BASELINE = 0.785;
    /** Capacity utilization per unit output gap (~1.9): a -6% gap takes CU from 78.5% to ~67%, a +3% gap to ~84%. */
    public const CU_GAP_SENSITIVITY = 1.9;
    /** Sensitivity of capacity utilization to accumulated physical capital stock overhang. */
    public const CU_OVERHANG_SENSITIVITY = 0.40;

    // --- Estrella & Mishkin (1998) Yield Curve Recession Probit ---
    /** Probit intercept parameter anchoring baseline recession probability around 15%. */
    public const RECESSION_PROBIT_BETA_0 = -0.55;
    /** Probit sensitivity to sovereign yield curve slope (10Y minus policy rate). */
    public const RECESSION_PROBIT_BETA_SLOPE = -80.0;
    /** Adds back half the term premium so the probit reads the expectations component (slope minus premium), not premium-driven steepness (Rosenberg & Maurer 2008). */
    public const RECESSION_PROBIT_BETA_TP = 40.0;
    /** Probit sensitivity to financial conditions tightening. */
    public const RECESSION_PROBIT_BETA_FCI = 0.35;

    // --- Corporate Default Dynamics (Moody's all-rated / Altman) ---
    /** Long-run average ALL-rated issuer-weighted corporate default rate (Moody's: 1.59% over 1983-2019, 1.56% over 1920-2017); speculative grade alone runs about three times it. */
    public const CORPORATE_DEFAULT_BASELINE = 0.016;

    // --- NY Fed Global Supply Chain Pressure Index (GSCPI - Benigno et al. 2022) ---
    /** Neutral baseline index for GSCPI composite (standard deviations). */
    public const GSCPI_BASELINE = 0.0;

    // --- 3:2:1 Refining Crack Spread & Distillate Margins (Bourgeon et al. 1998) ---
    /** Baseline long-run equilibrium refining crack spread in $/bbl. */
    public const CRACK_SPREAD_BASELINE = 22.0;

    // --- Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow ---
    /** Baseline neutral capital markets deal flow index (neutral advisory environment). */
    public const DEAL_ACTIVITY_BASELINE = 100.0;
    /** Log-elasticity of deal flow to one cycle-standard-deviation (150bps) of equity risk premium. */
    public const DEAL_ACTIVITY_ERP_BETA = 0.175;
    /** Log-elasticity of deal flow to one cycle-standard-deviation (500bps) of high-yield OAS. */
    public const DEAL_ACTIVITY_HY_BETA = 0.225;
    /** Log-elasticity of deal flow to one cycle-standard-deviation (6 vol points) of equity volatility. */
    public const DEAL_ACTIVITY_VOL_BETA = 0.10;
    /** Cap on the log deviation of the deal flow target (~1.7x either way): global M&A volume ran 2.2x from the 2007 peak to the 2009 trough, the widest cycle on record, so a 2008 freeze against a 2007 boom stays under 3x. */
    public const DEAL_ACTIVITY_LOG_RANGE = 0.55;

    // --- ISM Manufacturing Purchasing Managers' Index (PMI) ---
    /** Neutral diffusion baseline for ISM manufacturing PMI (50.0 = neutral growth). */
    public const PMI_BASELINE = 50.0;
    /** Asymptotic lower bound floor for manufacturing PMI during severe industrial depressions. */
    public const MIN_PMI = 30.0;
    /** Asymptotic upper bound ceiling for manufacturing PMI during hyper-expansionary booms. */
    public const MAX_PMI = 70.0;

    // --- Producer Price Index (PPI) Stage-of-Processing ---
    /** Weight of industrial metals price inflation in intermediate producer prices. */
    public const PPI_METALS_WEIGHT = 0.20;
    /** Weight of primary energy and petroleum price inflation in intermediate producer prices. */
    public const PPI_ENERGY_WEIGHT = 0.25;
    /** Upper bound ceiling on runaway wholesale producer price inflation. */
    public const MAX_PPI_INFLATION = 0.25;

    // --- Mundell-Fleming Trade Balance & Net Exports ---
    /** Baseline structural trade balance as a percentage of GDP (-2.5% neutral deficit). */
    public const TRADE_BALANCE_BASELINE = -0.025;

    // --- Tobin's Q Housing Investment Dynamics (Poterba 1984, Topel-Rosen 1988) ---
    /** Baseline housing starts activity index (100.0 = neutral construction equilibrium). */
    public const HOUSING_STARTS_BASELINE = 100.0;

    // --- Monetarist M2 Broad Money Supply Dynamics (Friedman-Schwartz, Brunner-Meltzer) ---
    /** Baseline structural annual growth rate of M2 money supply matching nominal potential GDP trend. */
    public const M2_BASE_GROWTH = 0.045;

    // --- Systemic Market Factor ---
    /** Decay time constant (years) of the common equity factor's regime leg, giving a ~20 day trend half-life. */
    public const MARKET_FACTOR_DECAY_TAU_YEARS = 0.08;
    /** Degrees of freedom of the market factor's Student's t shock; 5 is the lowest with a finite fourth moment. */
    public const MARKET_FACTOR_TAIL_DF = 5;
    /** Share of market factor variance carried by the slow regime leg; kept small so beta stays horizon-stable. */
    public const MARKET_FACTOR_REGIME_VARIANCE_SHARE = 0.15;
    /** Market-wide jump arrivals per year, giving the index the discontinuous crash days a diffusion cannot. */
    public const SYSTEMIC_JUMP_INTENSITY = 4.0;
    /** Probability a market-wide jump is upward; below one half, encoding the downward skew of index returns. */
    public const SYSTEMIC_JUMP_PROBABILITY_UP = 0.35;
    /** Decay rate of upward market jumps; the reciprocal is the mean up-jump log return (~2.5%). */
    public const SYSTEMIC_JUMP_ETA_UP = 40.0;
    /** Decay rate of downward market jumps; the reciprocal is the mean crash log return (~3.6%). */
    public const SYSTEMIC_JUMP_ETA_DOWN = 28.0;
    /** Mean of the contemporaneous variance jump accompanying a market-wide price jump. */
    public const SYSTEMIC_JUMP_VARIANCE_MEAN = 0.02;

    // --- Sector Factor ---
    /** Fraction of a stock's non-market variance loaded onto its sector factor, setting within- vs cross-sector correlation. */
    public const SECTOR_FACTOR_VARIANCE_SHARE = 0.20;

    // --- Household Balance Sheet (Mian & Sufi 2018; Drehmann, Illes, Juselius & Santos 2015; Basel III CCyB) ---
    /** Household debt to disposable income at the seeded neutral (unity, the advanced-economy average). */
    public const HOUSEHOLD_DEBT_TO_INCOME_BASELINE = 1.00;
    /** The BIS debt-service ratio at neutral rates and baseline leverage: 0.7 x 6.35% mortgage + 0.3 x 11.5% consumer = 7.9% on an 18-year annuity, 10.6% of income (the US long-run average). */
    public const HOUSEHOLD_DSR_NEUTRAL = 0.106;
    /** Debt-service gap (ratio over its own long-run average) at which households deleverage (Drehmann & Juselius 2012: a DSR ~2pp over its average is the early-warning threshold). */
    public const HOUSEHOLD_DSR_STRESS_MARGIN = 0.02;
    /** Spread of the 30-year mortgage over the ten-year (~170 bps), read by the housing user cost and the household debt service. */
    public const RESIDENTIAL_MORTGAGE_SPREAD = 0.017;

    // --- Deposits Channel (Drechsler, Savov & Schnabl 2017) ---
    /** System-wide deposit beta at the neutral policy rate (~0.20): the share of a rate rise banks pass to depositors, and the level the bank model's competitive advantage is normalised to. */
    public const SYSTEM_DEPOSIT_BETA_BASE = 0.20;
    /** Share of household liquid assets held in money-market funds at the neutral deposit spread (~15%, the US share outside a hiking cycle). */
    public const MMF_SHARE_BASE = 0.15;

    // --- Economic Policy Uncertainty (Baker, Bloom & Davis 2016) ---
    /** Neutral level of the policy-uncertainty index (the BBD index is normalised to a mean of 100). */
    public const EPU_BASELINE = 100.0;
    /** One cycle-standard-deviation of the log index (~0.35): the unit the deal-flow elasticity is quoted in. */
    public const EPU_CYCLE_LOG_SD = 0.35;
    /** Log-elasticity of deal flow to one standard deviation of policy uncertainty: acquisition activity falls by single-digit percents per sd (Bonaime, Gulen & Ion 2018). */
    public const DEAL_ACTIVITY_EPU_BETA = 0.10;

    // --- Administered Healthcare Prices (CMS market-basket update) ---
    /** Productivity offset subtracted from the annual reimbursement update (ACA s.3401 multifactor-productivity adjustment, ~0.6pp a year). Read by the fiscal subsystem and by the state's opening value. */
    public const REIMBURSEMENT_PRODUCTIVITY_OFFSET = 0.006;


    // --- District-Wide Systemic Event Triggers ---
    /** Minimum simulated years between district-wide events, so a sustained crisis reports once rather than every tick. */
    public const SYSTEMIC_EVENT_COOLDOWN_YEARS = 0.25;
    /** Interbank spread (100 bps) marking a genuine wholesale funding freeze rather than routine tightness. */
    public const SYSTEMIC_LIQUIDITY_FREEZE_SPREAD = 0.0100;
    /** High-yield spread (1000 bps) at which speculative-grade primary issuance effectively shuts. */
    public const SYSTEMIC_CREDIT_SEIZURE_SPREAD = 0.1000;
    /** Sovereign risk spread (150 bps) at which the fiscal position is formally re-rated: the level at which an advanced sovereign loses its top rating. */
    public const SYSTEMIC_SOVEREIGN_STRESS_SPREAD = 0.015;
    /** Recession probability above which the downturn is formally declared. */
    public const SYSTEMIC_RECESSION_DECLARE_PROBABILITY = 0.50;
    /** Output gap that must accompany the probability trigger, confirming output is genuinely contracting. */
    public const SYSTEMIC_RECESSION_DECLARE_GAP = -0.010;
    /** Sustained inversion duration (years) that historically precedes a downturn and trips the curve alarm. */
    public const SYSTEMIC_INVERSION_ALARM_YEARS = 0.75;

    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly \Redis $redis,
        private readonly MacroSnapshotRecorder $snapshotRecorder,
        private readonly MonetaryPolicySubsystem $monetarySubsystem,
        private readonly LaborMarketSubsystem $laborSubsystem,
        private readonly MacroAggregateSubsystem $aggregateSubsystem,
        private readonly CommodityLogisticsSubsystem $commoditySubsystem,
        private readonly AssetMarketSubsystem $assetSubsystem,
        private readonly CreditFiscalSubsystem $creditFiscalSubsystem,
        private readonly ?LoggerInterface $logger = null,
        /** Records the quarter's averages. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
        /** The sovereign reserve fund. Null means the district has none, which is what a caller that builds the engine by hand gets. */
        private readonly ?SovereignFundSubsystem $sovereignFundSubsystem = null,
    ) {}

    private function loadState(): MacroState
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        if (!is_string($rawState) || trim($rawState) === '') {
            return new MacroState();
        }

        try {
            $decoded = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $this->logger?->warning('Corrupt macroeconomic state in Redis: expected array, got ' . gettype($decoded));
                return new MacroState();
            }
            return MacroState::fromArray($decoded);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to decode macroeconomic state from Redis: ' . $e->getMessage());
            return new MacroState();
        }
    }

    private function saveState(MacroState $state): void
    {
        try {
            $payload = $state->toArray();
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $this->redis->set(self::REDIS_MACRO_STATE, $encoded);
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to persist macroeconomic state to Redis: ' . $e->getMessage());
        }
    }

    public function getLiveState(): MacroState
    {
        return $this->loadState();
    }

    /**
     * Sets the macroeconomic clock to an authoritative simulation time.
     *
     * The macro state is cached in Redis and the simulation clock is committed to the database, so a cache
     * that has lost writes comes back describing an economy at the wrong moment. Everything else in the state
     * — the rate, the regime, where the cycle stands — is a level with no record of when it was taken, and
     * there is nothing to reconcile it against; only time itself can be corrected, and it has to be, because
     * the expiry grids and sampling cadences read off it. The ticker does this once at start-up.
     */
    public function alignClock(float $totalTime): void
    {
        $state = $this->loadState();

        if ($state->totalTime === $totalTime) {
            return;
        }

        $this->logger?->warning(sprintf(
            'Macroeconomic clock realigned from %.6f to %.6f simulation years to match the database.',
            $state->totalTime,
            $totalTime
        ));

        $state->totalTime = $totalTime;
        $this->saveState($state);
    }

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     *
     * Everything here runs forward, from the economy to the prices it sets, with one exception: household
     * equity wealth. The board's CAPITALISATION comes back in through $equityMarketCap so that a bull market
     * can spend and a crash can save, which is a loop, and it is closed deliberately and visibly -- the
     * caller hands over what it measured on the PREVIOUS tick, never anything from this one.
     *
     * Capitalisation, not an index level. A level is a tradable instrument's scale: the district's funds
     * split themselves whenever their price runs away, and the index divisor is restated to match, so a
     * level re-bases on a share-count cosmetic. Household wealth does not. Reading the level once cost this
     * engine a 73% phantom crash and years of demand drag behind it.
     *
     * Passing null leaves the last observation standing, and a caller that never reports a market (the
     * simulate command, the headless harness, the unit tests) leaves the capitalisation at zero, which the
     * wealth channel reads as "no market" and contributes exactly nothing for.
     *
     * The sovereign fund reads the board the same way and on the same lag: its float-adjusted capitalisation (a
     * level, which stands when not reported) and the float-weighted price return, dividend cash and net issuance of
     * that tick (flows, which are zero when not reported, so a missing observation never replays the last one).
     *
     * The government is outside the economy too (App\Service\Politics\PoliticsEngine): what it hands over, the levers
     * in force and the election calendar's pull on policy uncertainty, comes in through $policy as of the previous tick,
     * and null leaves both where they stand, so a caller with no politics runs on the opening levers and no calendar.
     *
     * @param float      $dt                Time increment in years.
     * @param float|null $equityMarketCap   Whole-board capitalisation as of the previous tick, or null.
     * @param float|null $boardFloatCap     Whole-board float-adjusted capitalisation as of the previous tick, or null.
     * @param float|null $boardPriceReturn  The previous tick's float-weighted price return of the board, or null.
     * @param float|null $boardDividendCash Dividend cash the board's float was paid on the previous tick, or null.
     * @param float|null $boardNetIssuance  Float the companies' own issuance added on the previous tick (buybacks negative), or null.
     * @param float|null $boardStampDuty    Stamp duty the board's trading paid on the previous tick, or null.
     * @param float|null $strategicStakeCash Cash the District's strategic stakes paid it on the previous tick (dividends, buybacks less issues), or null.
     * @param GovernmentPolicyDTO|null $policy The government's levers and election pulse as of the previous tick, or null.
     * @param float|null $boardBankLevy     Bank levy the board's banks owe a year at the rate in force, as of the previous tick, or null to keep the last.
     */
    public function updateMacroState(
        float $dt,
        ?float $equityMarketCap = null,
        ?float $boardFloatCap = null,
        ?float $boardPriceReturn = null,
        ?float $boardDividendCash = null,
        ?float $boardNetIssuance = null,
        ?float $boardStampDuty = null,
        ?float $strategicStakeCash = null,
        ?GovernmentPolicyDTO $policy = null,
        ?float $boardBankLevy = null,
    ): \App\DTO\MacroStateDTO {
        $state = $this->loadState();

        if ($policy !== null) {
            $this->enactPolicy($state, $policy);
        }

        if ($equityMarketCap !== null && $equityMarketCap > 0.0) {
            $state->equityMarketCap = $equityMarketCap;
        }
        if ($boardFloatCap !== null && $boardFloatCap > 0.0) {
            $state->boardFloatCap = $boardFloatCap;
        }
        $state->boardPriceReturn = $boardPriceReturn ?? 0.0;
        $state->boardDividendCash = $boardDividendCash ?? 0.0;
        $state->boardNetIssuance = $boardNetIssuance ?? 0.0;
        $state->boardStampDuty = $boardStampDuty ?? 0.0;
        $state->strategicStakeCash = $strategicStakeCash ?? 0.0;
        if ($boardBankLevy !== null) {
            $state->boardBankLevy = $boardBankLevy;
        }

        // Advance physical simulation time in years
        $state->totalTime += $dt;

        // 1. Solow-Swan (1956) Total Factor Productivity (TFP) secular drift and endogenous growth.
        $tfpTrendGrowthRate = $this->aggregateSubsystem->calculateTotalFactorProductivity($state, $dt);
        // Basu, Fernald & Kimball (2006): potential absorbs a productivity shock gradually; everything built on
        // productivity growth (wage bargains, unit labour cost, money demand, potential GDP) reads that path.
        $productivityGrowthRate = $this->aggregateSubsystem->absorbProductivityShocks($state, $tfpTrendGrowthRate, $dt);

        // 2. Holston, Laubach & Williams (2017) natural rate of interest (r*), on trend growth only.
        $this->aggregateSubsystem->calculateNaturalRate($state, $tfpTrendGrowthRate, $dt);

        // 3. Okun (1962) & Diamond-Mortensen-Pissarides (1994) labor market dynamics.
        $this->laborSubsystem->calculateUnemployment($state, $dt);
        $this->laborSubsystem->calculateLaborMarketAndWages($state, $productivityGrowthRate, $dt);

        // 4. Gurkaynak, Sack & Wright (2010) TIPS breakeven inflation expectations, anchored where the public expects the
        // Authority to hold inflation: its target, plus whatever pressure it gave ground to has added (Drechsel 2024).
        $this->monetarySubsystem->updateInflationAnchor($state, $dt);
        $inflationAnchor = self::TARGET_INFLATION + $state->inflationAnchorDrift;
        $state->tipsBreakeven = $this->aggregateSubsystem->calculateTipsBreakeven($state, $inflationAnchor);

        // 5. Taylor (1993) monetary policy target and Clarida-Gali-Gertler (2000) rate inertia.
        $state->targetRate = $this->monetarySubsystem->calculateTargetRate($state, self::TARGET_INFLATION, $state->naturalRate, $dt);
        $clampedTarget = max(self::EFFECTIVE_LOWER_BOUND, min(self::POLICY_RATE_CEILING, $state->targetRate));
        $state->policyRate = $this->monetarySubsystem->updatePolicyRate($state, $clampedTarget, $dt);

        // 6. Central bank balance sheet unconventional QE/QT operations (Bernanke & Reinhart 2004).
        $balanceSheetData = $this->monetarySubsystem->calculateBalanceSheetOperations($state, $dt);
        $state->balanceSheetIntensity = $balanceSheetData['new_balance_sheet_intensity'];
        $state->balanceSheetHoldTimer = $balanceSheetData['new_hold_timer'];
        $state->qeIntensity = $balanceSheetData['new_qe_intensity'];
        $qeWasActive = $state->qeActive;
        $state->qeActive = $state->qeIntensity > self::BALANCE_SHEET_ACTIVE_THRESHOLD;
        if ($state->qeActive && !$qeWasActive) {
            $state->lastQeLaunchAt = $state->totalTime;
        }
        $state->qtIntensity = $balanceSheetData['new_qt_intensity'];
        $state->qtActive = $state->qtIntensity > self::BALANCE_SHEET_ACTIVE_THRESHOLD;

        // 7. Adrian, Crump & Moench (2013) term premium and market expectations dynamics.
        $this->monetarySubsystem->updateTermPremiumDynamics($state, $dt);
        $this->monetarySubsystem->updateMarketExpectations($state, $dt);

        // 8. Nelson-Siegel (1987) / Svensson (1994) sovereign yield curve term structure.
        $yieldData = $this->monetarySubsystem->calculateYieldCurve($state, self::TARGET_INFLATION, $state->naturalRate);

        $this->monetarySubsystem->applyYieldCurve($state, $yieldData, $dt);

        $this->assetSubsystem->updateSystemicMarketFactor($state, $dt);
        $this->aggregateSubsystem->updateCapitalStockOverhang($state, $dt);
        // Baker, Bloom & Davis (2016) economic policy uncertainty index simulation.
        $this->creditFiscalSubsystem->calculatePolicyUncertainty($state, $dt);
        // Mundell-Fleming multi-country foreign output gap and global policy transmission.
        $this->assetSubsystem->calculateForeignEconomy($state, $dt);

        $stressMultiplier = 1.0 + (abs($state->outputGap) * self::STRESS_MULTIPLIER_GAP_SENSITIVITY);
        $expectedInflation = $this->monetarySubsystem->calculateExpectedInflation($state, self::TARGET_INFLATION);
        $openingGap = $state->outputGap;
        $state->outputGap = $this->aggregateSubsystem->calculateOutputGap($state, $state->yield5y, $state->naturalRate, $expectedInflation, $dt, $stressMultiplier);

        $this->aggregateSubsystem->calculateCapacityUtilization($state);
        $this->commoditySubsystem->calculateEnergyShock($state, $dt);
        $this->commoditySubsystem->calculateNaturalGasIndex($state, $dt);
        $this->commoditySubsystem->calculateWholesalePowerIndex($state, $dt);
        $this->commoditySubsystem->calculateRefiningCrackSpread($state, $dt);
        $this->assetSubsystem->calculateExchangeRate($state, $dt);
        $this->assetSubsystem->calculateTradeBalance($state, $dt);
        $this->commoditySubsystem->calculateIndustrialMetalsIndex($state, $dt);
        $this->commoditySubsystem->calculateGoldPriceIndex($state, $dt);
        $this->creditFiscalSubsystem->calculateGovernmentSpending($state, $dt);
        $this->assetSubsystem->calculateAlliedDefenseSpending($state, $dt);
        $this->assetSubsystem->calculateCommercialPropertyIndex($state, $dt);
        $this->creditFiscalSubsystem->calculateHouseholdCredit($state, $dt);
        $this->creditFiscalSubsystem->calculateCreditCrisisHazard($state, $dt);
        // Gilchrist & Zakrajsek (2012): the premium moves before volatility and the spreads that load on it.
        $this->creditFiscalSubsystem->updateExcessBondPremium($state, $state->outputGap - $openingGap, $dt);
        $this->creditFiscalSubsystem->calculateRetailDefaultRate($state, $dt);
        $this->commoditySubsystem->calculateAgriculturalCommodityIndex($state, $dt);
        $this->commoditySubsystem->calculateCatastropheLosses($state, $dt);
        $this->commoditySubsystem->calculateFreightRateIndex($state, $dt);
        $this->commoditySubsystem->calculateSupplyChainPressureIndex($state);
        $this->assetSubsystem->calculateResidentialPropertyIndex($state, $expectedInflation, $dt);
        $this->assetSubsystem->calculateHousingStarts($state, $expectedInflation, $dt);

        $stressMultiplier = 1.0 + (abs($state->outputGap) * self::STRESS_MULTIPLIER_GAP_SENSITIVITY);
        $state->inflation = $this->aggregateSubsystem->calculateInflation($state, $inflationAnchor, $stressMultiplier, $dt);
        $this->aggregateSubsystem->calculateProducerPriceInflation($state, $productivityGrowthRate, $dt);
        $state->marketVolatility = $this->assetSubsystem->calculateMarketVolatility($state, $dt);

        $this->aggregateSubsystem->calculateManufacturingPmi($state, $dt);
        $this->monetarySubsystem->calculateMoneySupplyGrowth($state, $dt, $productivityGrowthRate);
        $this->monetarySubsystem->calculateDepositChannel($state, $dt);

        $this->aggregateSubsystem->updateExponentialMovingAverages($state, $dt);

        $this->creditFiscalSubsystem->calculateMacroCreditSpread($state);
        $this->creditFiscalSubsystem->calculateInterbankLiquiditySpread($state, $dt);
        $this->creditFiscalSubsystem->calculateSloosCreditStandards($state, $dt);
        $this->creditFiscalSubsystem->calculateCorporateDefaultRate($state, $dt);

        $this->aggregateSubsystem->calculatePotentialAndNominalGdp($state, $dt, $productivityGrowthRate);
        // Singapore NIR draw and a GPIF-style rebalancing band: struck on this tick's GDP, before the budget reads the draw.
        $this->sovereignFundSubsystem?->update($state, $dt);
        $this->creditFiscalSubsystem->calculateFundStabilisation($state, $dt);
        $this->creditFiscalSubsystem->calculateDynamicFiscalPolicy($state, $dt);
        $this->creditFiscalSubsystem->calculateSovereignDebt($state, $dt);
        $this->creditFiscalSubsystem->calculateSovereignRiskSpread($state, $dt);
        $this->creditFiscalSubsystem->calculateReimbursementRate($state, $dt);
        $this->assetSubsystem->calculateEquityRiskPremium($state);
        $this->assetSubsystem->calculateFinancialConditionsIndex($state);
        $this->assetSubsystem->calculateConsumerSentiment($state, $dt);
        $this->monetarySubsystem->calculateRecessionProbability($state);
        $this->assetSubsystem->calculateCapitalMarketsDealIndex($state, $dt);
        $this->updateSectorFactors($state, $dt);
        $this->evaluateSystemicEvent($state, $dt);
        if ($state->eventType !== null) {
            $this->diagnostics?->recordEvent('systemic.' . $state->eventType, 1.0);
        }

        $this->diagnostics?->recordAverages($state, $dt);

        $this->saveState($state);
        return \App\DTO\MacroStateDTO::fromMacroState($state);
    }

    /**
     * Advances one persistent shock per macro sector, the second common factor behind every equity price.
     *
     * With only the market factor, two banks co-move solely through their betas, so a banking crisis moves
     * their earnings together while their prices diffuse independently between reporting dates.
     *
     * These shocks are deliberately i.i.d. rather than autocorrelated. Autocorrelating them would have to be
     * paid for by scaling each shock down to keep integrated variance intact, which shrinks the factor's
     * per-step contribution to nearly nothing and makes the sector correlation it exists to create invisible
     * at any horizon a player actually looks at. It is also unnecessary: a sector ROTATION lives in the
     * cumulative level, and the level of a random walk wanders and stays displaced for months at a time
     * without any memory in its increments. Autocorrelation would only add predictable momentum, which is
     * the market factor's job and is kept small there for the same horizon-stability reason.
     */
    private function updateSectorFactors(MacroState $state, float $dt): void
    {
        // Ornstein-Uhlenbeck persistent sector demand factor with unit stationary variance (Vasicek 1977).
        $decay = exp(-$dt / self::SECTOR_DEMAND_PERSISTENCE_YEARS);
        $innovationScale = sqrt(max(0.0, 1.0 - ($decay * $decay)));

        foreach (array_keys(\App\Data\Sectors::MACRO_SECTORS) as $sector) {
            $state->sectorZ[$sector] = $this->mathUtility->generateStandardNormal();
            $previous = (float) ($state->sectorDemandZ[$sector] ?? 0.0);
            $state->sectorDemandZ[$sector] = ($decay * $previous) + ($innovationScale * $this->mathUtility->generateStandardNormal());
        }
    }

    /**
     * Selects at most one district-wide systemic event per tick from the macro state just computed.
     *
     * Edge-triggered with a refractory cooldown rather than level-triggered: a crisis satisfies its threshold
     * for many consecutive ticks, so a level trigger would republish the same headline thousands of times per
     * simulated year and bury the news feed. The event is a single-tick pulse -- consumers read it on the tick
     * it fires and it is cleared on the next -- and the cooldown then suppresses further events until the
     * regime has had time to develop.
     *
     * Conditions are evaluated most-severe-first so a funding freeze outranks a recession declaration that
     * would inevitably accompany it.
     */
    private function evaluateSystemicEvent(MacroState $state, float $dt): void
    {
        // Pulse trigger: resets instantaneous systemic event classification before evaluation.
        $state->eventType = null;

        if ($state->eventCooldownTimer > 0.0) {
            $state->eventCooldownTimer = max(0.0, $state->eventCooldownTimer - $dt);
            // Preserves unhandled edge-triggered systemic shocks during refractory cooldown.
            if ($state->lastCatastropheAt !== $state->totalTime
                && $state->lastCreditCrisisAt !== $state->totalTime
                && $state->lastQeLaunchAt !== $state->totalTime
                && $state->lastSovereignRebalanceAt !== $state->totalTime
            ) {
                return;
            }
        }

        $eventType = match (true) {
            // Mian & Sufi (2018) systemic banking crisis triggered by debt overhang default hazard.
            $state->lastCreditCrisisAt === $state->totalTime
            => ShockEvent::BANKING_CRISIS,

            // Launch of an asset-purchase programme: one headline per programme, reported on the tick it starts.
            $state->lastQeLaunchAt === $state->totalTime
            => ShockEvent::TITAN_INTERVENTION,

            // The sovereign fund starts a rebalance: one headline per programme, buying after a fall or trimming after a run.
            $state->lastSovereignRebalanceAt === $state->totalTime && $state->sovereignFundRebalanceBacklog > 0.0
            => ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT,

            $state->lastSovereignRebalanceAt === $state->totalTime && $state->sovereignFundRebalanceBacklog < 0.0
            => ShockEvent::SOVEREIGN_WEALTH_TRIM,

            $state->interbankLiquiditySpread >= self::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD
            => ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE,

            $state->highYieldCreditSpread >= self::SYSTEMIC_CREDIT_SEIZURE_SPREAD
            => ShockEvent::CREDIT_MARKET_SEIZURE,

            $state->sovereignRiskSpread >= self::SYSTEMIC_SOVEREIGN_STRESS_SPREAD
            => ShockEvent::SOVEREIGN_DOWNGRADE,

            self::isRecession($state->recessionProbability, $state->outputGap)
            => ShockEvent::RECESSION_DECLARED,

            // Natural catastrophe physical damage shock event (Hallegatte et al. 2007).
            $state->lastCatastropheAt === $state->totalTime
            => ShockEvent::NATURAL_CATASTROPHE,

            // Drehmann & Juselius (2012) household balance sheet debt-service deleveraging shock.
            $state->householdDebtServiceGap >= self::HOUSEHOLD_DSR_STRESS_MARGIN
                && $state->householdDebtToIncome < $state->householdDebtToIncomeEma
            => ShockEvent::HOUSEHOLD_DELEVERAGING,

            $state->inversionDuration >= self::SYSTEMIC_INVERSION_ALARM_YEARS
            => ShockEvent::YIELD_CURVE_INVERSION_ALARM,

            default => null,
        };

        if ($eventType !== null) {
            $state->eventType = $eventType;
            // District-wide systemic crisis refractory cooldown timer arming.
            $state->eventCooldownTimer = self::SYSTEMIC_EVENT_COOLDOWN_YEARS;
        }
    }

    /**
     * Whether the District is in a recession it has formally declared: the probit's odds of one above even, with output
     * a point below potential to confirm it. The downturn the RECESSION_DECLARED headline names.
     */
    public static function isRecession(float $recessionProbability, float $outputGap): bool
    {
        return $recessionProbability >= self::SYSTEMIC_RECESSION_DECLARE_PROBABILITY && $outputGap <= self::SYSTEMIC_RECESSION_DECLARE_GAP;
    }

    /** Whether unemployment says a recession is under way: the Sahm rule's reading at its threshold or above. */
    public static function inSahmRecession(float $sahmIndicator): bool
    {
        return $sahmIndicator >= self::SAHM_RECESSION_THRESHOLD;
    }

    /**
     * Puts the government's levers in force and takes its election pulse. A tariff's change moves productivity by
     * Furceri et al.'s (2018) output loss, a level potential absorbs over the years that follow.
     */
    private function enactPolicy(MacroState $state, GovernmentPolicyDTO $policy): void
    {
        $productivityLoss = -self::TARIFF_OUTPUT_LOSS * ($policy->importTariffRate - $state->importTariffRate);
        $state->tfpShockLevel += $productivityLoss;
        $state->totalFactorProductivityIndex *= exp($productivityLoss);

        $state->corporateTaxPolicyShift = $policy->corporateTaxPolicyShift;
        $state->importTariffRate = $policy->importTariffRate;
        $state->laborForceGrowthRate = $policy->laborForceGrowthRate;
        $state->mergerReviewLeniency = $policy->mergerReviewLeniency;
        $state->greenBeltStringency = $policy->greenBeltStringency;
        $state->carbonPrice = $policy->carbonPrice;
        $state->extractionStringency = $policy->extractionStringency;
        $state->stampDutyRate = $policy->stampDutyRate;
        $state->bankLevyRate = $policy->bankLevyRate;
        $state->reserveDrawShare = $policy->reserveDrawShare;
        $state->electionPulse = $policy->electionPulse;
        $state->authorityMajority = $policy->authorityMajority ?? 0.0;
        $state->authorityCommitteeSeated = $policy->authorityMajority === null ? 0.0 : 1.0;
        $state->authorityConcession = $policy->authorityConcession ?? 0.0;
        $state->bankCapitalRequirement = $policy->bankCapitalRequirement ?? $state->bankCapitalRequirement;
        $state->sovereignFundMandateEquityShare = $policy->reserveFundEquityShare ?? $state->sovereignFundMandateEquityShare;

        $state->previousSittingLevers = $state->sittingLevers;
        $state->previousSittingPolicyFrom = $state->sittingPolicyFrom;
        $state->previousExpectedLevers = $state->expectedLevers;
        $state->previousExpectedPolicyFrom = $state->expectedPolicyFrom;
        $state->sittingLevers = $policy->sittingLevers ?? [];
        $state->sittingPolicyFrom = $policy->sittingLevers === null ? -1.0 : ($policy->sittingPolicyFrom ?? -1.0);
        $state->expectedLevers = $policy->expectedLevers ?? [];
        $state->expectedPolicyFrom = $policy->expectedLevers === null ? -1.0 : ($policy->expectedPolicyFrom ?? -1.0);
    }

    /**
     * Persists an immutable historical econometric snapshot to the database.
     *
     * Records all macroeconomic time series across interest rates, yield curves,
     * inflation, labor market dynamics, credit spreads, commodities, real estate,
     * and national accounts into the macro_report table.
     *
     * @param \App\DTO\MacroStateDTO                         $macroState State snapshot to record.
     * @param \Doctrine\DBAL\Connection                       $conn       Database connection.
     * @param \App\Service\Macro\Recorder\QuarterRecord|null $quarter    The probes' closed windows and the run's identity.
     */
    public function recordMacroSnapshot(
        \App\DTO\MacroStateDTO $macroState,
        \Doctrine\DBAL\Connection $conn,
        ?\App\Service\Macro\Recorder\QuarterRecord $quarter = null
    ): void {
        $this->snapshotRecorder->recordSnapshot($macroState, $conn, $quarter);
    }
}
