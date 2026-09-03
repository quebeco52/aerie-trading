<?php

namespace App\Service\Macro;

use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use Psr\Log\LoggerInterface;


class MacroEngine
{
    public const REDIS_MACRO_STATE = 'macroeconomic_state';

    // --- Central Bank & Structural Constraints ---
    /** The Federal Reserve's long-term annual inflation target. */
    public const TARGET_INFLATION = 0.02;
    /** The baseline natural real rate of interest (r*) representing neutral monetary policy. */
    public const BASE_NATURAL_RATE = 0.015;
    /** Backward compatibility alias for the baseline natural rate. */
    public const NATURAL_RATE = self::BASE_NATURAL_RATE;
    /** Sensitivity of natural rate r* to annual secular TFP productivity growth deviations from drift. */
    public const NATURAL_RATE_TFP_SENSITIVITY = 0.50;
    /** Speed of adjustment (kappa) of natural real rate toward fundamental equilibrium. */
    public const NATURAL_RATE_ADJUSTMENT_SPEED = 1.0;
    /** Structural lower bound floor for natural real rate. */
    public const MIN_NATURAL_RATE = 0.005;
    /** Structural upper bound ceiling for natural real rate. */
    public const MAX_NATURAL_RATE = 0.035;
    /** The baseline corporate tax rate for standard physical companies. */
    public const BASE_CORPORATE_TAX_RATE = 0.21;
    /** The baseline historical equity risk premium expected over risk-free assets. */
    public const BASE_EQUITY_RISK_PREMIUM = 0.045;
    /** Campbell-Cochrane (1999) habit formation risk aversion sensitivity to output gap deviations. */
    public const HABIT_RISK_AVERSION_COEFF = 25.0;
    /** Structural floor: equities must logically yield more than risk-free T-bills. */
    public const MIN_EQUITY_RISK_PREMIUM = 0.02;
    /** The discount to the policy rate representing the yield on corporate treasury cash. */
    public const CASH_YIELD_SPREAD = 0.0025;

    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /** Elasticity of aggregate demand to exchange rate deviations (Marshall-Lerner Net Export Drag). */
    public const KALDOR_FX_ELASTICITY = 0.04;
    /** Linear momentum of aggregate demand feedback loop. */
    public const KALDOR_MOMENTUM = 0.18;
    /** Cubic stabilization factor bounding extreme boom/bust expansions. */
    public const KALDOR_CAPACITY = 400.0;
    /** Sensitivity of aggregate demand to real interest rate deviations from natural rate. */
    public const KALDOR_MONETARY_DRAG = 1.00;
    /** Countercyclical fiscal stimulus multiplier from corporate tax rate cuts. */
    public const KALDOR_FISCAL_MULTIPLIER = 0.50;
    /** Sensitivity of the output gap to physical capital stock overhang (excess capacity drags down growth). */
    public const KALDOR_CAPITAL_DRAG = 0.15;
    /** Elasticity of aggregate demand to household wealth deviations (Modigliani Wealth Effect). */
    public const KALDOR_WEALTH_EFFECT_ELASTICITY = 0.02;
    /** The rate at which business investment (output gap) accumulates into the physical capital stock. */
    public const CAPITAL_ACCUMULATION_RATE = 0.30;
    /** The rate at which physical capital depreciates, organically clearing overhangs and creating pent-up demand. */
    public const CAPITAL_DECAY_RATE = 0.30;
    /** Stochastic diffusion volatility of the macroeconomic output gap. */
    public const OUTPUT_GAP_DIFFUSION_SIGMA = 0.005;

    // --- Okun's Law & Diamond-Mortensen-Pissarides Beveridge Curve ---
    /** Structural Non-Accelerating Inflation Rate of Unemployment (NAIRU) baseline. */
    public const NATURAL_UNEMPLOYMENT = 0.04;
    /** Structural baseline job vacancies rate. */
    public const NATURAL_JOB_VACANCIES = 0.045;
    /** Asymptotic frictional lower bound on unemployment during extreme economic expansions. */
    public const MIN_FRICTIONAL_UNEMPLOYMENT = 0.020;
    /** Structural Beveridge curve equilibrium constant (k = Natural Unemployment * Natural Vacancies). */
    public const BEVERIDGE_CURVE_CONSTANT = 0.0018;
    /** Structural equilibrium labor market tightness (theta* vacancy-to-unemployment ratio). */
    public const NATURAL_LABOR_TIGHTNESS = 1.125;
    /** Sensitivity of wage growth to labor market tightness deviations from equilibrium. */
    public const WAGE_TIGHTNESS_SENSITIVITY = 0.010;
    /** Annual adjustment speed of nominal wage settlements toward market-clearing equilibrium. */
    public const WAGE_ADJUSTMENT_SPEED = 2.0;
    /** Wage-push inflation transmission passing excess wage growth into headline services inflation. */
    public const WAGE_INFLATION_TRANSMISSION = 0.10;
    /** Okun's beta: sensitivity of equilibrium unemployment deviation to the GDP output gap. */
    public const OKUNS_COEFFICIENT = 0.5;
    /** Annual adjustment speed of employment expansion during economic recoveries (search & matching friction). */
    public const OKUNS_HIRING_SPEED = 1.5;
    /** Annual adjustment speed of workforce reduction during economic contractions (rapid labor shedding). */
    public const OKUNS_FIRING_SPEED = 3.0;

    // --- Energy Shock Jump-Diffusion (Schwartz 1997 Commodity Dynamics) ---
    /** Baseline index value for energy prices (neutral commodity equilibrium). */
    public const ENERGY_BASELINE = 100.0;
    /** Poisson annual jump arrival intensity for geopolitical and OPEC energy supply shocks. */
    public const ENERGY_JUMP_PROBABILITY = 0.05;
    /** Mean-reversion speed (kappa) of energy prices reverting to long-run baseline. */
    public const ENERGY_MEAN_REVERSION = 0.8;
    /** Schwartz 1-factor log-price volatility (diffusion sigma). */
    public const ENERGY_VOLATILITY = 0.25;
    /** Expected mean log-return magnitude of an energy price spike. */
    public const ENERGY_JUMP_MEAN = 0.20;
    /** Volatility of energy jump shock magnitude. */
    public const ENERGY_JUMP_VOL = 0.10;
    /** Cost-push transmission coefficient passing energy price spikes into headline inflation. */
    public const ENERGY_COST_PUSH_TRANSMISSION = 0.010;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /** Long-run equilibrium baseline volatility during neutral economic conditions. */
    public const MACRO_VOL_BASE_ANCHOR            = 0.15;
    /** Sensitivity of exponential baseline volatility to output gap fluctuations (countercyclical). */
    public const MACRO_VOL_OUTPUT_GAP_SENSITIVITY = 10.0;
    /** Sensitivity of exponential baseline volatility to corporate credit spread deviations from baseline. */
    public const MACRO_VOL_CREDIT_SENSITIVITY     = 10.0;
    /** Sensitivity of exponential baseline volatility to yield curve slope (flattening/inversion increases vol). */
    public const MACRO_VOL_SLOPE_SENSITIVITY      = 8.0;
    /** Lower clamp for baseline volatility during extreme Goldilocks expansions. */
    public const MACRO_VOL_MIN_BASELINE           = 0.10;
    /** Upper clamp for macro-driven baseline volatility to prevent infinite variance explosion. */
    public const MACRO_VOL_MAX_BASELINE           = 0.45;
    /** Mean-reversion speed (kappa) of the continuous macroeconomic variance process. */
    public const MACRO_VOL_KAPPA                  = 2.0;
    /** Volatility of volatility (sigma) in the macroeconomic variance diffusion. */
    public const MACRO_VOL_SIGMA                  = 0.30;

    // --- SVJJ Stochastic Volatility & Contemporaneous Jumps (Duffie, Pan, & Singleton 2000) ---
    /** Annual Poisson arrival intensity of market-wide volatility jump shocks. */
    public const SVJJ_LAMBDA = 0.80;
    /** Probability of an upward market return jump given a Poisson jump event. */
    public const SVJJ_P_UP = 0.35;
    /** Exponential decay rate parameter for positive return jumps (eta+). */
    public const SVJJ_ETA_UP = 10.0;
    /** Exponential decay rate parameter for negative return crashes (eta-). */
    public const SVJJ_ETA_DOWN = 5.0;
    /** Mean exponential jump size added directly to instantaneous variance (mu_v). */
    public const SVJJ_MU_V = 0.05;

    // --- Effective Corporate Borrowing Cost Weights ---
    /** Weight assigned to the short-term policy rate in aggregate corporate borrowing cost. */
    public const BORROWING_POLICY_WEIGHT = 0.70;
    /** Weight assigned to the 5-year benchmark Treasury yield in aggregate corporate borrowing cost. */
    public const BORROWING_YIELD5Y_WEIGHT = 0.30;

    // --- Taylor Rule & The Evans Rule (Forward Guidance) ---
    /** Weight on inflation deviations from the target in the Taylor Rule. */
    public const TAYLOR_INFLATION_WEIGHT = 0.50;
    /** Weight on positive output gap during economic expansions. */
    public const TAYLOR_BOOM_WEIGHT = 0.15;
    /** Non-linear scaling factor amplifying rate cuts during deep recessions. */
    public const TAYLOR_RECESSION_SCALE = 5.0;
    /** Evans Rule forward guidance: Unemployment threshold required before lifting off from ZLB. */
    public const EVANS_RULE_UNEMPLOYMENT = 0.050;
    /** Evans Rule forward guidance: Maximum inflation ceiling tolerated while holding rates at ZLB. */
    public const EVANS_RULE_INFLATION_CAP = 0.025;
    /** Central bank baseline rate hiking smoothing speed per year (Woodford 2003 inertial gradualism). */
    public const CB_HIKE_SMOOTHING_SPEED = 0.80;
    /** Central bank baseline rate cutting smoothing speed per year (rapid crisis easing). */
    public const CB_CUT_SMOOTHING_SPEED = 2.00;
    /** Inflation panic threshold above which central bank accelerates hiking to Volcker speed. */
    public const CB_INFLATION_PANIC_THRESHOLD = 0.035;
    /** Inflation panic reaction multiplier accelerating rate hikes during extreme inflation spikes. */
    public const CB_INFLATION_PANIC_SCALE = 50.0;
    /** Recession panic reaction multiplier accelerating emergency cuts during downturns. */
    public const CB_RECESSION_PANIC_SCALE = 150.0;
    /** Maximum annual rate hike velocity cap during normal economic expansions. */
    public const CB_MAX_NORMAL_HIKE_VELOCITY = 0.020;
    /** Maximum annual rate hike velocity cap during emergency runaway inflation spikes. */
    public const CB_MAX_PANIC_HIKE_VELOCITY = 0.040;
    /** Maximum annual rate hike velocity cap (Volcker-style panic speed cap). */
    public const CB_MAX_HIKE_PANIC_SPEED = 3.0;
    /** Maximum annual rate cut velocity cap during financial crises. */
    public const CB_MAX_CUT_PANIC_SPEED = 10.0;
    /** Maximum annual rate cut velocity cap during economic downturns and crises. */
    public const CB_MAX_CUT_VELOCITY = -0.080;
    /** Policy rate threshold determining proximity to the Zero Lower Bound. */
    public const ZLB_PROXIMITY_THRESHOLD = 0.015;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /** Baseline structural term premium for long-term Treasury yields. */
    public const NS_BASE_TERM_PREMIUM = 0.0070;
    /** Flight-to-safety sensitivity: recessions compress term premium via safe-haven demand (Campbell et al. 2017). */
    public const NS_GAP_TERM_PREMIUM_SCALE = 0.05;
    /** Diebold-Li (2006) curvature sensitivity to central bank target-policy rate gap (forward guidance channel). */
    public const SVENSSON_CURVATURE1_TARGET_SCALE = 1.20;
    /** Cyclical curvature sensitivity to output gap (positive gap leads to steeper belly). */
    public const SVENSSON_CURVATURE1_GAP_SCALE = 0.60;
    /** Primary Nelson-Siegel decay parameter governing the medium-term hump. */
    public const SVENSSON_LAMBDA_1 = 0.65;
    /** Secondary Svensson decay parameter governing the long-term hump. */
    public const SVENSSON_LAMBDA_2 = 0.15;
    /** Sensitivity of secondary curvature (beta3) to quantitative tightening and long-term fiscal deficits. */
    public const SVENSSON_CURVATURE2_FISCAL_SCALE = 0.02;
    /** Sensitivity of beta3 secondary curvature to central bank balance sheet (positive QT steepens, negative QE suppresses). */
    public const SVENSSON_CURVATURE2_BS_SCALE = 0.40;
    /** Wright (2011) IRP: term premium sensitivity to excess inflation expectations above target. */
    public const TERM_PREMIUM_IRP_EXPECTATION_SCALE = 0.40;
    /** Wright (2011) IRP: term premium sensitivity to excess macro volatility above neutral threshold. */
    public const TERM_PREMIUM_IRP_VOLATILITY_SCALE = 0.03;

    // --- Forward-Looking TIPS Breakeven & Phillips Expectations ---
    /** Weight on anchored central bank target in TIPS breakeven inflation expectation. */
    public const TIPS_TARGET_WEIGHT = 0.40;
    /** Weight on adaptive trend inflation in TIPS breakeven inflation expectation. */
    public const TIPS_TREND_WEIGHT = 0.40;
    /** Weight on forward-looking output gap pressure in TIPS breakeven inflation expectation. */
    public const TIPS_CYCLICAL_WEIGHT = 0.20;
    /** Inflation risk premium sensitivity scaling with macroeconomic volatility. */
    public const TIPS_INFLATION_RISK_PREMIUM_SCALE = 0.05;

    // --- Distributed Lag Transmission Constants ---
    /** Characteristic half-life time constant in years for energy cost-push pass-through into core inflation. */
    public const ENERGY_COST_PUSH_LAG_YEARS = 0.50;

    // --- New Keynesian Phillips Curve Dynamics ---
    /** Adaptive unanchoring weight of inflation expectations to sustained trend deviations. */
    public const INFLATION_ADAPTIVE_EXPECTATIONS_WEIGHT = 0.25;
    /** Phillips curve slope: sensitivity of headline inflation to the output gap. */
    public const PHILLIPS_SLOPE = 0.25;
    /** Speed of inflation expectations mean-reverting toward central bank target (anchored expectations). */
    public const INFLATION_MEAN_REVERSION = 0.75;

    // --- Merton Structural Corporate Credit Spreads (Merton 1974) ---
    /** Sensitivity of corporate credit spreads to wholesale interbank funding stress. */
    public const INTERBANK_CREDIT_CONTAGION_SENSITIVITY = 2.0;
    /** Baseline investment-grade corporate credit spread over risk-free rate. */
    public const BASE_CREDIT_SPREAD = 0.020;
    /** Sensitivity of corporate credit spreads to GDP contraction (leverage & distance-to-default channel). */
    public const MERTON_LEVERAGE_SENSITIVITY = 2.5;
    /** Sensitivity of corporate credit spreads to excess macroeconomic equity volatility. */
    public const MERTON_VOL_SENSITIVITY = 0.15;
    /** Statutory ceiling cap for aggregate corporate credit spread during systemic credit crunches. */
    public const MAX_CREDIT_SPREAD = 0.10;
    /** Macroeconomic volatility threshold above which excess volatility widens corporate credit spreads. */
    public const CREDIT_SPREAD_EXCESS_VOL_THRESHOLD = 0.20;

    // --- Barro Tax-Smoothing & Automatic Fiscal Stabilizers (Barro 1979) ---
    /** Structural baseline statutory corporate tax rate. */
    public const TARGET_CORPORATE_TAX_RATE = 0.21;
    /** Countercyclical statutory tax response sensitivity to output gap deviations. */
    public const FISCAL_STABILIZER_SENSITIVITY = 1.0;
    /** Institutional legislative adjustment speed of corporate tax rate changes. */
    public const FISCAL_ADJUSTMENT_SPEED = 0.20;
    /** Statutory corporate tax rate floor during deep economic recessions. */
    public const MIN_CORPORATE_TAX_RATE = 0.12;
    /** Statutory corporate tax rate ceiling during overheating economic booms. */
    public const MAX_CORPORATE_TAX_RATE = 0.30;

    // --- Consumer Sentiment Index & Animal Spirits ---
    /** Baseline consumer sentiment index value (neutral consumer confidence). */
    public const SENTIMENT_BASELINE = 100.0;
    /** Sensitivity of consumer misery index (unemployment and inflation) on sentiment. */
    public const SENTIMENT_MISERY_MULTIPLIER = 200.0;
    /** Sensitivity of financial market volatility on consumer sentiment confidence. */
    public const SENTIMENT_VOLATILITY_MULTIPLIER = 50.0;
    /** Momentum sensitivity of worsening inflation and unemployment shifts on consumer confidence. */
    public const SENTIMENT_MOMENTUM_MULTIPLIER = 250.0;
    /** Sensitivity of interest rate environment on consumer sentiment borrowing costs. */
    public const SENTIMENT_RATE_MULTIPLIER = 250.0;
    /** Sensitivity of consumer sentiment expansion boost during positive GDP output gaps. */
    public const SENTIMENT_EXPANSION_MULTIPLIER = 300.0;
    /** Sensitivity coefficient penalizing consumer sentiment during retail energy price shocks. */
    public const SENTIMENT_ENERGY_PANIC_SCALE = 0.15;
    /** Mean-reversion speed (theta) of psychological animal spirits returning to fundamentals. */
    public const ANIMAL_SPIRITS_MEAN_REVERSION = 2.0;
    /** Stochastic diffusion volatility (sigma) of consumer animal spirits. */
    public const ANIMAL_SPIRITS_VOLATILITY = 2.5;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Proximity threshold to Zero Lower Bound required before activating QE asset purchases. */
    public const QE_ACTIVATION_ZLB_THRESHOLD = 0.60;
    /** Negative output gap threshold below which central bank initiates QE bond purchases. */
    public const QE_ACTIVATION_GAP_THRESHOLD = 0.0;
    /** Maximum yield suppression capacity achieved under full-scale QE. */
    public const QE_MAX_SUPPRESSION = 0.02;
    /** Sensitivity multiplier scaling QE bond purchase intensity with recession depth. */
    public const QE_SEVERITY_MULTIPLIER = 1.0;
    /** Annual ramp speed of central bank balance sheet expansion and contraction. */
    public const BALANCE_SHEET_RAMP_SPEED = 1.0;
    /** Backward compatibility alias for QE ramp speed. */
    public const QE_RAMP_SPEED = self::BALANCE_SHEET_RAMP_SPEED;
    /** Positive output gap threshold above which central bank initiates Quantitative Tightening. */
    public const QT_ACTIVATION_GAP_THRESHOLD = 0.015;
    /** Inflation threshold above which central bank initiates Quantitative Tightening */
    public const QT_ACTIVATION_INFLATION_THRESHOLD = 0.030;
    /** Maximum yield steepening magnitude under full-scale Quantitative Tightening. */
    public const QT_MAX_INTENSITY = 0.005;
    /** Sensitivity multiplier scaling QT bond runoff with economic overheating. */
    public const QT_SEVERITY_MULTIPLIER = 0.40;
    /** Nelson-Siegel level weighting on target inflation vs expected inflation. */
    public const INFLATION_LEVEL_WEIGHT = 0.5;

    // --- MUNDELL-FLEMING OPEN ECONOMY (IS-LM-BOP) ---
    /** G7 average policy rate proxy for Uncovered Interest Parity (UIP) baseline. */
    public const GLOBAL_BASELINE_RATE = 0.025;
    /** Baseline exchange rate index (neutral purchasing power parity). */
    public const EXCHANGE_RATE_BASELINE = 100.0;
    /** UIP sensitivity: exchange rate response to domestic-foreign interest rate differential. */
    public const UIP_SENSITIVITY = 3.0;
    /** Mean-reversion speed of exchange rate toward purchasing power parity equilibrium. */
    public const EXCHANGE_RATE_MEAN_REVERSION = 0.60;
    /** Stochastic volatility of exchange rate fluctuations (FX market noise). */
    public const EXCHANGE_RATE_VOLATILITY = 0.08;

    // --- 2-FACTOR CORRELATED OU INDUSTRIAL COMMODITIES ---
    /** Baseline industrial metals index value (neutral equilibrium). */
    public const METALS_BASELINE = 100.0;
    /** Mean-reversion speed of short-term supply disruptions (strikes, logistics). */
    public const METALS_SHORT_TERM_KAPPA = 1.50;
    /** Mean-reversion speed of long-term industrial metals supercycle equilibrium shifts. */
    public const METALS_LONG_TERM_KAPPA = 0.20;
    /** Volatility of short-term supply disruption shocks. */
    public const METALS_SHORT_TERM_SIGMA = 0.30;
    /** Volatility of long-term supercycle equilibrium shifts. */
    public const METALS_LONG_TERM_SIGMA = 0.08;
    /** Correlation between short-term disruptions and long-term shifts. */
    public const METALS_RHO = -0.30;
    /** Sensitivity of long-term metals equilibrium target to the macroeconomic output gap. */
    public const METALS_OUTPUT_GAP_SENSITIVITY = 0.50;

    // --- GOVERNMENT SPENDING & FISCAL APPROPRIATIONS ---
    /** Baseline government spending index (neutral peacetime budget). */
    public const GOVT_SPENDING_BASELINE = 100.0;
    /** Counter-cyclical fiscal multiplier: spending rises when output gap contracts. */
    public const GOVT_COUNTERCYCLICAL_SENSITIVITY = 80.0;
    /** Mean-reversion speed of government spending toward structural baseline. */
    public const GOVT_SPENDING_MEAN_REVERSION = 0.30;
    /** Stochastic volatility of annual budget appropriation fluctuations. */
    public const GOVT_SPENDING_VOLATILITY = 0.06;
    /** Poisson intensity of major geopolitical events triggering spending surges. */
    public const GEOPOLITICAL_JUMP_PROBABILITY = 0.08;
    /** Mean log-return magnitude of a geopolitical spending surge. */
    public const GEOPOLITICAL_JUMP_MEAN = 0.15;
    /** Volatility of geopolitical jump size. */
    public const GEOPOLITICAL_JUMP_VOL = 0.08;

    // --- DIPASQUALE-WHEATON COMMERCIAL REAL ESTATE (2-QUADRANT) ---
    /** Baseline commercial property index (neutral valuation). */
    public const CRE_BASELINE = 100.0;
    /** Sensitivity of occupancy/rent demand factor to excess unemployment (DiPasquale-Wheaton spatial market). */
    public const CRE_OCCUPANCY_UNEMPLOYMENT_SENSITIVITY = 3.0;
    /** Structural risk premium spread above 10Y yield for CRE cap rate derivation. */
    public const CRE_CAP_RATE_RISK_PREMIUM = 0.02;
    /** Pre-calibrated neutral cap rate at macro equilibrium. */
    public const CRE_NEUTRAL_CAP_RATE = 0.0904;
    /** Mean-reversion speed of commercial property values toward fundamental equilibrium. */
    public const CRE_MEAN_REVERSION = 0.25;
    /** Stochastic volatility of commercial property valuations. */
    public const CRE_VOLATILITY = 0.10;
    /** Minimum cap rate floor to prevent division instability in extreme rate environments. */
    public const CRE_MIN_CAP_RATE = 0.03;

    // --- VASICEK ASRF RETAIL DEFAULT RATE ---
    /** Baseline long-run average through-the-cycle retail consumer probability of default. */
    public const RETAIL_DEFAULT_BASELINE = 0.025;
    /** Basel II/III consumer asset correlation factor for retail exposures. */
    public const RETAIL_ASRF_RHO = 0.12;
    /** Sensitivity of consumer macro credit Z-score to unemployment rate deviations from natural rate. */
    public const RETAIL_UNEMPLOYMENT_SENSITIVITY = 40.0;
    /** Sensitivity of consumer macro credit Z-score to inflation deviations from target. */
    public const RETAIL_INFLATION_SENSITIVITY = 25.0;
    /** Stochastic volatility of idiosyncratic consumer credit shocks. */
    public const RETAIL_CREDIT_VOLATILITY = 0.35;

    // --- 2-FACTOR CORRELATED OU AGRICULTURAL COMMODITIES & WEATHER JUMPS ---
    /** Baseline agricultural commodity index value (neutral crop harvest). */
    public const AGRI_BASELINE = 100.0;
    /** Mean-reversion speed of short-term agricultural supply disruptions (frost, harvest delays). */
    public const AGRI_SHORT_TERM_KAPPA = 1.80;
    /** Mean-reversion speed of long-term agricultural equilibrium supercycles toward baseline. */
    public const AGRI_LONG_TERM_KAPPA = 0.25;
    /** Volatility of short-term agricultural supply shocks. */
    public const AGRI_SHORT_TERM_SIGMA = 0.25;
    /** Volatility of long-term agricultural equilibrium shifts. */
    public const AGRI_LONG_TERM_SIGMA = 0.06;
    /** Correlation between short-term disruptions and long-term agricultural shifts. */
    public const AGRI_RHO = -0.20;
    /** Amplitude of annual seasonal harvest cycle price oscillation (percentage of index). */
    public const AGRI_SEASONALITY_AMPLITUDE = 0.06;
    /** Poisson intensity of major climate/weather shocks such as droughts or El Niño events. */
    public const AGRI_WEATHER_JUMP_PROBABILITY = 0.10;
    /** Mean log-return price jump magnitude resulting from an extreme weather shock. */
    public const AGRI_WEATHER_JUMP_MEAN = 0.18;
    /** Volatility of climate jump shock magnitude. */
    public const AGRI_WEATHER_JUMP_VOL = 0.08;

    // --- COBWEB THEOREM FREIGHT RATE INDEX (BALTIC DRY) ---
    /** Baseline ocean freight index value (balanced fleet capacity and trade volume). */
    public const FREIGHT_BASELINE = 100.0;
    /** Elasticity of instantaneous shipping demand to macroeconomic output gap. */
    public const FREIGHT_DEMAND_GAP_SENSITIVITY = 3.5;
    /** Sensitivity of bulk shipping demand to industrial metals production and raw material flows. */
    public const FREIGHT_DEMAND_METALS_SENSITIVITY = 0.30;
    /** Elasticity of desired fleet capacity orders to prevailing freight charter profitability. */
    public const FREIGHT_SUPPLY_ORDER_ELASTICITY = 0.80;
    /** Time constant in years for multi-year shipyard shipbuilding capacity adjustments. */
    public const FREIGHT_SUPPLY_LAG_YEARS = 3.0;
    /** Inelasticity exponent amplifying freight spot rates when capacity utilization exceeds 1.0. */
    public const FREIGHT_CAPACITY_INELASTICITY = 2.0;
    /** Mean-reversion speed of spot charter rates toward capacity-clearing equilibrium. */
    public const FREIGHT_MEAN_REVERSION = 1.50;
    /** Stochastic volatility of spot charter market fluctuations. */
    public const FREIGHT_VOLATILITY = 0.25;

    // --- JORGENSON USER COST RESIDENTIAL REAL ESTATE ---
    /** Baseline residential property index value (neutral home affordability). */
    public const RESIDENTIAL_BASELINE = 100.0;
    /** Structural mortgage spread above 30Y Treasury yield for prime residential mortgages. */
    public const RESIDENTIAL_MORTGAGE_SPREAD = 0.018;
    /** Structural property tax, insurance, and maintenance depreciation rate. */
    public const RESIDENTIAL_DEPRECIATION_TAX_RATE = 0.025;
    /** Baseline equilibrium user cost of housing capital. */
    public const RESIDENTIAL_NEUTRAL_USER_COST = 0.0778;
    /** Sensitivity of housing demand to unemployment rate shocks (foreclosure and affordability drag). */
    public const RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY = 5.0;
    /** Elasticity of residential housing purchasing power to real macroeconomic income and GDP growth. */
    public const RESIDENTIAL_INCOME_ELASTICITY = 2.0;
    /** Lower bound multiplier on residential spatial labor demand during deep labor distress. */
    public const RESIDENTIAL_MIN_LABOR_FACTOR = 0.30;
    /** Upper bound multiplier on residential spatial labor demand during peak labor market expansions. */
    public const RESIDENTIAL_MAX_LABOR_FACTOR = 1.80;
    /** Mean-reversion speed of residential property valuations toward fundamental user-cost equilibrium. */
    public const RESIDENTIAL_MEAN_REVERSION = 0.15;
    /** Stochastic volatility of residential home prices. */
    public const RESIDENTIAL_VOLATILITY = 0.06;
    /** Structural lower floor for the residential property index value. */
    public const RESIDENTIAL_MIN_INDEX = 30.0;
    /** Structural upper ceiling for the residential property index value. */
    public const RESIDENTIAL_MAX_INDEX = 300.0;

    // --- INTERBANK LIQUIDITY SPREAD (CIR PROCESS & JUMPS) ---
    /** Baseline interbank liquidity spread (FRA-OIS / TED Spread proxy) under normal conditions. */
    public const INTERBANK_BASELINE_SPREAD = 0.0015;
    /** Speed of mean reversion (kappa) for the interbank liquidity spread toward baseline. */
    public const INTERBANK_SPREAD_KAPPA = 2.50;
    /** Volatility (sigma) of the continuous interbank liquidity spread diffusion. */
    public const INTERBANK_SPREAD_SIGMA = 0.02;
    /** Poisson intensity of severe interbank credit freeze/panic events. */
    public const INTERBANK_JUMP_PROBABILITY = 0.05;
    /** Mean log-return magnitude of an interbank liquidity panic jump. */
    public const INTERBANK_JUMP_MEAN = 1.60;
    /** Volatility of the interbank panic jump magnitude. */
    public const INTERBANK_JUMP_VOL = 0.50;

    // --- SOLOW-SWAN TOTAL FACTOR PRODUCTIVITY (TFP) ---
    /** Baseline index value for Total Factor Productivity (neutral technology baseline). */
    public const TFP_BASELINE = 100.0;
    /** Secular annual drift rate of continuous technological progress. */
    public const TFP_DRIFT = 0.015;
    /** Annual volatility (sigma) of technological innovation and diffusion shocks. */
    public const TFP_VOLATILITY = 0.022;
    /** Endogenous R&D knowledge spillover sensitivity to economic expansion and capital utilization. */
    public const TFP_OUTPUT_GAP_SENSITIVITY = 0.05;
    /** Poisson arrival intensity (lambda) of major breakthrough innovation jump shocks per year. */
    public const TFP_JUMP_PROBABILITY = 0.03;
    /** Mean log-scale magnitude of a major technological breakthrough jump. */
    public const TFP_JUMP_MEAN = 0.010;
    /** Volatility of breakthrough technological jump shocks. */
    public const TFP_JUMP_VOL = 0.005;
    /** Structural lower bound floor for annual TFP growth rate. */
    public const MIN_TFP_GROWTH_RATE = -0.030;
    /** Structural upper bound ceiling for annual TFP growth rate. */
    public const MAX_TFP_GROWTH_RATE = 0.050;
    /** Structural baseline demographic and labor force growth rate. */
    public const STRUCTURAL_LABOR_GROWTH_RATE = 0.005;

    // --- Continuous EMA Indicator Smoothing Horizons ---
    /** Standard quarterly macro indicator EMA smoothing horizon. */
    public const STANDARD_EMA_HORIZON_YEARS = 0.25;

    private ?MacroSnapshotRecorder $snapshotRecorder = null;
    private ?MonetaryPolicySubsystem $monetarySubsystem = null;
    private ?LaborMarketSubsystem $laborSubsystem = null;
    private ?MacroAggregateSubsystem $aggregateSubsystem = null;
    private ?CommodityLogisticsSubsystem $commoditySubsystem = null;
    private ?AssetMarketSubsystem $assetSubsystem = null;
    private ?CreditFiscalSubsystem $creditFiscalSubsystem = null;

    public function __construct(
        private MathUtility $mathUtility,
        private LoggerInterface $logger,
        private \Redis $redis,
        ?MacroSnapshotRecorder $snapshotRecorder = null,
        ?MonetaryPolicySubsystem $monetarySubsystem = null,
        ?LaborMarketSubsystem $laborSubsystem = null,
        ?MacroAggregateSubsystem $aggregateSubsystem = null,
        ?CommodityLogisticsSubsystem $commoditySubsystem = null,
        ?AssetMarketSubsystem $assetSubsystem = null,
        ?CreditFiscalSubsystem $creditFiscalSubsystem = null,
    ) {
        if ($snapshotRecorder !== null) {
            $this->snapshotRecorder = $snapshotRecorder;
        }
        if ($monetarySubsystem !== null) {
            $this->monetarySubsystem = $monetarySubsystem;
        }
        if ($laborSubsystem !== null) {
            $this->laborSubsystem = $laborSubsystem;
        }
        if ($aggregateSubsystem !== null) {
            $this->aggregateSubsystem = $aggregateSubsystem;
        }
        if ($commoditySubsystem !== null) {
            $this->commoditySubsystem = $commoditySubsystem;
        }
        if ($assetSubsystem !== null) {
            $this->assetSubsystem = $assetSubsystem;
        }
        if ($creditFiscalSubsystem !== null) {
            $this->creditFiscalSubsystem = $creditFiscalSubsystem;
        }
    }

    private function getSnapshotRecorder(): MacroSnapshotRecorder
    {
        return $this->snapshotRecorder ??= new MacroSnapshotRecorder();
    }

    private function getMonetarySubsystem(): MonetaryPolicySubsystem
    {
        return $this->monetarySubsystem ??= new MonetaryPolicySubsystem($this->mathUtility);
    }

    private function getLaborSubsystem(): LaborMarketSubsystem
    {
        return $this->laborSubsystem ??= new LaborMarketSubsystem();
    }

    private function getAggregateSubsystem(): MacroAggregateSubsystem
    {
        return $this->aggregateSubsystem ??= new MacroAggregateSubsystem($this->mathUtility);
    }

    private function getCommoditySubsystem(): CommodityLogisticsSubsystem
    {
        return $this->commoditySubsystem ??= new CommodityLogisticsSubsystem($this->mathUtility);
    }

    private function getAssetSubsystem(): AssetMarketSubsystem
    {
        return $this->assetSubsystem ??= new AssetMarketSubsystem($this->mathUtility);
    }

    private function getCreditFiscalSubsystem(): CreditFiscalSubsystem
    {
        return $this->creditFiscalSubsystem ??= new CreditFiscalSubsystem($this->mathUtility);
    }

    public function getLiveState(): MacroState
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        return $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();
    }

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     */
    public function updateMacroState(float $dt): \App\DTO\MacroStateDTO
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        $state = $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();

        // Advance physical simulation time in years
        $state->totalTime += $dt;

        // 1. Evaluate Total Factor Productivity (TFP) & Secular Drift
        $tfpTrendGrowthRate = $this->calculateTotalFactorProductivity($state, $dt);

        // 2. Laubach-Williams (2003) Dynamic Natural Rate of Interest (r*)
        $this->calculateNaturalRate($state, $tfpTrendGrowthRate, $dt);

        // 3. Labor Market: Okun's Law & Diamond-Mortensen-Pissarides Beveridge Curve
        $this->calculateUnemployment($state, $dt);
        $this->calculateLaborMarketAndWages($state, $tfpTrendGrowthRate, $dt);

        // 4. Inflation Expectations (TIPS Breakeven)
        $state->tipsBreakeven = $this->calculateTipsBreakeven($state, self::TARGET_INFLATION, $dt);

        // 5. Central Bank Monetary Policy & Yield Curve
        $state->targetRate = $this->calculateTargetRate($state, self::TARGET_INFLATION, $state->naturalRate);
        $state->policyRate = $this->updatePolicyRate($state, $state->targetRate, $dt);

        $yieldData = $this->calculateYieldCurveAndQE($state, self::TARGET_INFLATION, $state->naturalRate, $dt);

        $state->balanceSheetIntensity = $yieldData['new_balance_sheet_intensity'];
        $state->qeIntensity = $yieldData['new_qe_intensity'];
        $state->qeActive = $state->qeIntensity > 0.0005;
        $state->qtIntensity = $yieldData['new_qt_intensity'];
        $state->qtActive = $state->qtIntensity > 0.0005;

        $state->yield2y = $yieldData['yield_2y'];
        $state->yield5y = $yieldData['yield_5y'];
        $state->yield10y = $yieldData['yield_10y'];
        $state->yield30y = $yieldData['yield_30y'];
        $state->nsLevel = $yieldData['level'];
        $state->nsCurvature = $yieldData['curvature'];
        $state->nsCurvature2 = $yieldData['curvature2'];

        $state->termPremium10y = $yieldData['term_premium_10y'];
        $state->riskNeutral10y = $yieldData['risk_neutral_10y'];

        $state->nsSlope = $state->yield10y - $state->policyRate;
        $state->structuralSlope = $yieldData['structural_10y'] - $state->policyRate;

        if ($state->structuralSlope < 0.0) {
            $state->inversionDuration += $dt;
        } else {
            $state->inversionDuration = 0.0;
        }

        $state->marketZ = $this->mathUtility->generateStandardNormal();

        // 2D Kaldor Phase Space: Capital Stock tracking
        // Booms build excess capacity (+k); Recessions cause physical depreciation and pent-up demand (-k).
        $state->capitalStockOverhang += (($state->outputGap * self::CAPITAL_ACCUMULATION_RATE) - (self::CAPITAL_DECAY_RATE * $state->capitalStockOverhang)) * $dt;
        $state->capitalStockOverhang = max(-0.15, min(0.15, $state->capitalStockOverhang));

        $stressMultiplier = 1.0 + (abs($state->outputGap) * 10.0);
        $state->outputGap = $this->calculateOutputGap($state, $state->yield5y, $state->naturalRate, $dt, $stressMultiplier);

        $this->calculateEnergyShock($state, $dt);
        $this->calculateExchangeRate($state, $dt);
        $this->calculateIndustrialMetalsIndex($state, $dt);
        $this->calculateGovernmentSpending($state, $dt);
        $this->calculateCommercialPropertyIndex($state, $dt);
        $this->calculateRetailDefaultRate($state, $dt);
        $this->calculateAgriculturalCommodityIndex($state, $dt);
        $this->calculateFreightRateIndex($state, $dt);
        $this->calculateResidentialPropertyIndex($state, $dt);

        $state->inflation = $this->calculateInflation($state, self::TARGET_INFLATION, $stressMultiplier, $dt);
        $state->marketVolatility = $this->calculateMarketVolatility($state, $dt);

        $this->updateExponentialMovingAverages($state, $dt);
        $this->calculateMacroCreditSpread($state);
        $this->calculateInterbankLiquiditySpread($state, $dt);

        $this->calculatePotentialAndNominalGdp($state, $dt, $tfpTrendGrowthRate);
        $this->calculateDynamicFiscalPolicy($state, $dt);
        $this->calculateEquityRiskPremium($state);
        $this->calculateConsumerSentiment($state, $dt);

        $payload = $state->toArray();
        $this->redis->set(self::REDIS_MACRO_STATE, json_encode($payload));
        return \App\DTO\MacroStateDTO::fromMacroState($state);
    }

    /**
     * Persists an immutable historical econometric snapshot to the database.
     *
     * Records all macroeconomic time series across interest rates, yield curves,
     * inflation, labor market dynamics, credit spreads, commodities, real estate,
     * and national accounts into the macro_report table.
     *
     * @param \App\DTO\MacroStateDTO   $macroState State snapshot to record.
     * @param \Doctrine\DBAL\Connection $conn       Database connection.
     */
    public function recordMacroSnapshot(\App\DTO\MacroStateDTO $macroState, \Doctrine\DBAL\Connection $conn): void
    {
        $this->getSnapshotRecorder()->recordSnapshot($macroState, $conn);
    }

    private function calculateTargetRate(MacroState $state, float $targetInflation, float $naturalRate): float
    {
        return $this->getMonetarySubsystem()->calculateTargetRate($state, $targetInflation, $naturalRate);
    }

    private function updatePolicyRate(MacroState $state, float $targetRate, float $dt): float
    {
        return $this->getMonetarySubsystem()->updatePolicyRate($state, $targetRate, $dt);
    }

    private function calculateYieldCurveAndQE(MacroState $state, float $targetInflation, float $naturalRate, float $dt): array
    {
        return $this->getMonetarySubsystem()->calculateYieldCurveAndQE($state, $targetInflation, $naturalRate, $dt);
    }

    private function calculateSvenssonTenor(float $t, float $level, float $nsBeta1, float $nsBeta2, float $nsBeta3, MacroState $state): float
    {
        return $this->getMonetarySubsystem()->calculateSvenssonTenor($t, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
    }

    private function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $dt, float $stressMultiplier): float
    {
        return $this->getAggregateSubsystem()->calculateOutputGap($state, $yield5y, $naturalRate, $dt, $stressMultiplier);
    }

    private function calculateNaturalRate(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $this->getAggregateSubsystem()->calculateNaturalRate($state, $tfpGrowthRate, $dt);
    }

    private function calculateLaborMarketAndWages(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $this->getLaborSubsystem()->calculateLaborMarketAndWages($state, $tfpGrowthRate, $dt);
    }

    private function calculateInflation(MacroState $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        return $this->getAggregateSubsystem()->calculateInflation($state, $targetInflation, $stressMultiplier, $dt);
    }

    private function calculateMarketVolatility(MacroState $state, float $dt): float
    {
        return $this->getAssetSubsystem()->calculateMarketVolatility($state, $dt);
    }

    private function calculateTotalFactorProductivity(MacroState $state, float $dt): float
    {
        return $this->getAggregateSubsystem()->calculateTotalFactorProductivity($state, $dt);
    }

    private function calculatePotentialAndNominalGdp(MacroState $state, float $dt, ?float $tfpGrowthRate = null): void
    {
        $this->getAggregateSubsystem()->calculatePotentialAndNominalGdp($state, $dt, $tfpGrowthRate);
    }

    private function calculateTipsBreakeven(MacroState $state, float $targetInflation, float $dt): float
    {
        return $this->getAggregateSubsystem()->calculateTipsBreakeven($state, $targetInflation, $dt);
    }

    private function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        $this->getAggregateSubsystem()->updateExponentialMovingAverages($state, $dt);
    }

    private function calculateDynamicFiscalPolicy(MacroState $state, float $dt): void
    {
        $this->getCreditFiscalSubsystem()->calculateDynamicFiscalPolicy($state, $dt);
    }

    private function calculateEquityRiskPremium(MacroState $state): void
    {
        $this->getAssetSubsystem()->calculateEquityRiskPremium($state);
    }

    private function calculateMacroCreditSpread(MacroState $state): void
    {
        $this->getCreditFiscalSubsystem()->calculateMacroCreditSpread($state);
    }

    private function calculateInterbankLiquiditySpread(MacroState $state, float $dt): void
    {
        $this->getCreditFiscalSubsystem()->calculateInterbankLiquiditySpread($state, $dt);
    }

    private function calculateUnemployment(MacroState $state, float $dt): void
    {
        $this->getLaborSubsystem()->calculateUnemployment($state, $dt);
    }

    private function calculateEnergyShock(MacroState $state, float $dt): void
    {
        $this->getCommoditySubsystem()->calculateEnergyShock($state, $dt);
    }

    private function calculateConsumerSentiment(MacroState $state, float $dt): void
    {
        $this->getAssetSubsystem()->calculateConsumerSentiment($state, $dt);
    }

    private function calculateExchangeRate(MacroState $state, float $dt): void
    {
        $this->getAssetSubsystem()->calculateExchangeRate($state, $dt);
    }

    private function calculateIndustrialMetalsIndex(MacroState $state, float $dt): void
    {
        $this->getCommoditySubsystem()->calculateIndustrialMetalsIndex($state, $dt);
    }

    private function calculateGovernmentSpending(MacroState $state, float $dt): void
    {
        $this->getCreditFiscalSubsystem()->calculateGovernmentSpending($state, $dt);
    }

    private function calculateCommercialPropertyIndex(MacroState $state, float $dt): void
    {
        $this->getAssetSubsystem()->calculateCommercialPropertyIndex($state, $dt);
    }

    private function calculateRetailDefaultRate(MacroState $state, float $dt): void
    {
        $this->getCreditFiscalSubsystem()->calculateRetailDefaultRate($state, $dt);
    }

    private function calculateAgriculturalCommodityIndex(MacroState $state, float $dt): void
    {
        $this->getCommoditySubsystem()->calculateAgriculturalCommodityIndex($state, $dt);
    }

    private function calculateFreightRateIndex(MacroState $state, float $dt): void
    {
        $this->getCommoditySubsystem()->calculateFreightRateIndex($state, $dt);
    }

    private function calculateResidentialPropertyIndex(MacroState $state, float $dt): void
    {
        $this->getAssetSubsystem()->calculateResidentialPropertyIndex($state, $dt);
    }
}
