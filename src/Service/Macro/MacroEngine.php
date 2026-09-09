<?php

namespace App\Service\Macro;

use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Event\ShockEvent;
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
    /** Sensitivity of natural rate r* to annual secular TFP productivity growth deviations from drift. */
    public const NATURAL_RATE_TFP_SENSITIVITY = 0.50;
    /** Laubach-Williams sensitivity of natural rate r* to cyclical output gap investment demand. */
    public const NATURAL_RATE_OUTPUT_GAP_SENSITIVITY = 0.15;
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
    public const KALDOR_MOMENTUM = 0.12;
    /** Cubic stabilization factor bounding extreme boom/bust expansions. */
    public const KALDOR_CAPACITY = 500.0;
    /** Sensitivity of aggregate demand to real interest rate deviations from natural rate. */
    public const KALDOR_MONETARY_DRAG = 1.30;
    /** Sensitivity of aggregate demand to wholesale credit spread and interbank liquidity friction (Bernanke-Gertler 1999). */
    public const KALDOR_CREDIT_FRICTION_DRAG = 0.25;
    /** Countercyclical fiscal stimulus multiplier from corporate tax rate cuts. */
    public const KALDOR_FISCAL_MULTIPLIER = 0.50;
    /** Demand impulse per unit of public spending above baseline (~20% GDP share x unit multiplier): +10% spending adds ~1pp/yr to the gap drift. */
    public const KALDOR_GOVT_SPENDING_MULTIPLIER = 0.10;
    /** Sensitivity of the output gap to physical capital stock overhang (excess capacity drags down growth). */
    public const KALDOR_CAPITAL_DRAG = 0.15;
    /** Elasticity of aggregate demand to household wealth deviations (Modigliani Wealth Effect). */
    public const KALDOR_WEALTH_EFFECT_ELASTICITY = 0.02;
    /** Bruno-Sachs (1985) supply-side elasticity of output to energy price shock (Blanchard-Gali 2007). */
    public const KALDOR_ENERGY_SUPPLY_DRAG = 0.004;
    /** Supply-side elasticity of output to excess freight/logistics costs. */
    public const KALDOR_FREIGHT_SUPPLY_DRAG = 0.002;
    /** The rate at which business investment (output gap) accumulates into the physical capital stock. */
    public const CAPITAL_ACCUMULATION_RATE = 0.25;
    /** The rate at which physical capital depreciates, organically clearing overhangs and creating pent-up demand. */
    public const CAPITAL_DECAY_RATE = 0.30;
    /** Asymptotic lower bound floor on physical capital stock contraction / overhang. */
    public const CAPITAL_OVERHANG_MIN = -0.15;
    /** Asymptotic upper bound ceiling on physical capital stock excess capacity / overhang. */
    public const CAPITAL_OVERHANG_MAX = 0.15;
    /** Stochastic diffusion volatility of the macroeconomic output gap. */
    public const OUTPUT_GAP_DIFFUSION_SIGMA = 0.010;
    /** Sensitivity scaling diffusion volatility of output gap and inflation during severe cyclical stress. */
    public const STRESS_MULTIPLIER_GAP_SENSITIVITY = 10.0;

    // --- Metzler-Blinder Inventory Investment Cycle (Metzler 1941, Blinder 1982) ---
    /** Sensitivity of output gap drift to involuntary inventory liquidation and restocking. */
    public const METZLER_INVENTORY_DRAG = 0.08;
    /** Annual adjustment speed of firm inventory target replenishment. */
    public const INVENTORY_ADJUSTMENT_SPEED = 0.80;
    /** Sensitivity of involuntary inventory accumulation to unexpected output gap deceleration. */
    public const INVENTORY_SURPRISE_SENSITIVITY = 0.60;
    /** Cyclical target inventory sensitivity to real output gap demand. */
    public const INVENTORY_CYCLICAL_DEMAND_SENSITIVITY = 0.80;

    // --- Okun's Law & Diamond-Mortensen-Pissarides Beveridge Curve ---
    /** Structural Non-Accelerating Inflation Rate of Unemployment (NAIRU) baseline. */
    public const NATURAL_UNEMPLOYMENT = 0.04;
    /** Structural baseline job vacancies rate. */
    public const NATURAL_JOB_VACANCIES = 0.045;
    /** Asymptotic frictional lower bound on unemployment (3.2%): search friction keeps even a red-hot economy above ~3%. */
    public const MIN_FRICTIONAL_UNEMPLOYMENT = 0.032;
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
    /** Annual OU speed of NAIRU scarring drift toward sustained excess unemployment (Blanchard & Summers 1986). */
    public const NAIRU_HYSTERESIS_SPEED = 0.10;
    /** Excess unemployment above NAIRU required before structural scarring activates. */
    public const NAIRU_HYSTERESIS_THRESHOLD = 0.005;
    /** Structural floor for NAIRU (frictional minimum). */
    public const MIN_NAIRU = 0.025;
    /** Structural ceiling for NAIRU (maximum structural deterioration). */
    public const MAX_NAIRU = 0.08;
    /** Downward wage adjustment speed as fraction of upward speed (nominal rigidity, Bewley 1999). */
    public const WAGE_DOWNWARD_RIGIDITY_FACTOR = 0.30;

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
    /** Headline inflation per unit energy shock (~7% CPI weight at ~35% retail pass-through): +60% energy adds ~1.5pp. */
    public const ENERGY_COST_PUSH_TRANSMISSION = 0.025;

    // --- Theory of Storage & Commodity Buffer Stocks (Working 1949, Litzenberger-Rabinowitz 1995) ---
    /** Baseline physical commodity inventory index (neutral buffer stock). */
    public const COMMODITY_INVENTORY_BASELINE = 100.0;
    /** Critical minimum physical buffer stock floor before extreme convenience yield spike. */
    public const COMMODITY_MIN_BUFFER_STOCK = 50.0;
    /** Annual mean-reversion speed of physical inventories toward structural baseline. */
    public const COMMODITY_INVENTORY_REVERSION_SPEED = 0.50;
    /** Sensitivity of inventory drawdown to economic output gap and geopolitical supply shocks. */
    public const COMMODITY_INVENTORY_DRAWDOWN_SENSITIVITY = 1.20;

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
    /** Canonical Taylor (1993) weight on the output gap in the Taylor Rule. */
    public const TAYLOR_OUTPUT_GAP_WEIGHT = 0.50;
    /** Non-linear scaling factor amplifying rate cuts during deep recessions. */
    public const TAYLOR_RECESSION_SCALE = 35.0;
    /** Bernanke (2015) blend: weight on realized core inflation (EMA) in the Taylor Rule inflation measure. */
    public const TAYLOR_INFLATION_CORE_WEIGHT = 0.70;
    /** Bernanke (2015) blend: weight on forward inflation expectations (TIPS breakeven) in the Taylor Rule inflation measure. */
    public const TAYLOR_INFLATION_ANCHOR_WEIGHT = 0.30;
    /** Bernanke (2006) / Rudebusch-Sack-Swanson (2007) long-rate offset: the central bank leans against the part of the ten-year it does not set (term premium away from baseline, the market's drifted view of neutral). FRB/US puts a 100bps term premium move at roughly 50bps of policy; without it a high-premium era is a decade-long slump. */
    public const TAYLOR_LONG_RATE_OFFSET = 0.50;
    /** Evans Rule forward guidance: Unemployment threshold required before lifting off from ZLB. */
    public const EVANS_RULE_UNEMPLOYMENT = 0.050;
    /** Evans Rule forward guidance: Maximum inflation ceiling tolerated while holding rates at ZLB. */
    public const EVANS_RULE_INFLATION_CAP = 0.025;
    /** Central bank baseline rate hiking smoothing speed per year (Woodford 2003 inertial gradualism). */
    public const CB_HIKE_SMOOTHING_SPEED = 0.80;
    /** Central bank baseline rate cutting smoothing speed per year (rapid crisis easing). */
    public const CB_CUT_SMOOTHING_SPEED = 1.20;
    /** Inflation panic threshold above which central bank accelerates hiking to Volcker speed. */
    public const CB_INFLATION_PANIC_THRESHOLD = 0.035;
    /** Inflation panic reaction multiplier accelerating rate hikes during extreme inflation spikes. */
    public const CB_INFLATION_PANIC_SCALE = 50.0;
    /** Recession panic reaction multiplier accelerating emergency cuts during downturns. */
    public const CB_RECESSION_PANIC_SCALE = 20.0;
    /** Maximum annual rate hike velocity cap during normal economic expansions (8 × 25bps meetings). */
    public const CB_MAX_NORMAL_HIKE_VELOCITY = 0.025;
    /** Maximum annual rate hike velocity cap during emergency runaway inflation spikes (525bps in 15 months annualized). */
    public const CB_MAX_PANIC_HIKE_VELOCITY = 0.060;
    /** Maximum annual rate hike velocity cap (Volcker-style panic speed cap). */
    public const CB_MAX_HIKE_PANIC_SPEED = 3.0;
    /** Maximum annual rate cut velocity cap during financial crises. */
    public const CB_MAX_CUT_PANIC_SPEED = 10.0;
    /** Maximum annual rate cut velocity cap during economic downturns and crises. */
    public const CB_MAX_CUT_VELOCITY = -0.080;
    /** Policy rate threshold determining proximity to the Zero Lower Bound. */
    public const ZLB_PROXIMITY_THRESHOLD = 0.015;

    // --- Flexible Average Inflation Targeting (FAIT - Powell 2020) ---
    /** FAIT rolling memory persistence speed per year for cumulative price level shortfall. */
    public const FAIT_MEMORY_SPEED = 0.50;
    /** Central bank reaction sensitivity to cumulative inflation shortfall/overshoot. */
    public const FAIT_MAKEUP_COEFFICIENT = 0.25;
    /** Maximum policy rate target offset allowed from FAIT cumulative memory. */
    public const FAIT_MAX_TARGET_OFFSET = 0.015;

    // --- Central Bank Effective Lower Bound & Shadow Rates ---
    /** Wu-Xia (2016) Effective Lower Bound on nominal policy rates (ECB deposit facility floor). */
    public const EFFECTIVE_LOWER_BOUND = -0.005;
    /** Structural upper bound ceiling for nominal monetary policy target rate. */
    public const POLICY_RATE_CEILING = 0.20;
    /** Shadow-rate accommodation per unit of QE yield suppression: full-scale QE (100bps) reads as a -3% shadow rate, the Wu-Xia trough of 2014. */
    public const WU_XIA_QE_SHADOW_SENSITIVITY = 3.0;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /** Baseline ten-year term premium (Adrian-Crump-Moench 2013: ~115bps average over 1990-2019). Scaled down by duration for shorter tenors; the two-year note carries under a third of it. */
    public const NS_BASE_TERM_PREMIUM = 0.0115;
    /** Duration over which the term premium saturates: a two-year note carries under 30% of the ten-year premium. Past ten years only the structural regime keeps rising (a thirty-year bond carries half again as much of it); transitory shocks, the inflation risk premium and the cyclical terms land on the long end one-for-one with the ten-year, since the 10s30s spread is stable through a taper tantrum. */
    public const TERM_PREMIUM_DURATION_HORIZON_YEARS = 10.0;
    /** Weight on the central bank target in the ten-year inflation expectation that anchors the curve's long end. Well-anchored expectations (surveys barely move) are what let the policy rate swing against a steady long end and invert the curve; a level that tracked the current breakeven would follow the short end up and never invert. */
    public const LONG_RUN_INFLATION_ANCHOR_WEIGHT = 0.75;
    /** Kozicki-Tinsley (2001) shifting endpoint: weight on the market's adaptive long-run policy rate in the curve's anchor, against the model-consistent r* plus expected inflation. A decade at 5% lifts the anchor so the curve flattens like 1995-1999 instead of staying inverted; a decade at the floor drags the ten-year toward the 2% of 2012-2016. */
    public const KOZICKI_TINSLEY_ENDPOINT_WEIGHT = 0.50;
    /** Speed at which the perceived long-run policy rate learns from the realized rate (half-life ~5 years): slow enough that a two-year hiking cycle still inverts the curve, fast enough that a decade-long era reprices the long end, the slow adaptive expectations of Kozicki-Tinsley. */
    public const KOZICKI_TINSLEY_ADAPTATION_SPEED = 0.14;
    /** Flight-to-safety sensitivity: recessions compress term premium via safe-haven demand (Campbell et al. 2017). */
    public const NS_GAP_TERM_PREMIUM_SCALE = 0.05;
    /** Diebold-Li (2006) curvature sensitivity to central bank target-policy rate gap (forward guidance channel). */
    public const SVENSSON_CURVATURE1_TARGET_SCALE = 0.85;
    /** Cyclical curvature sensitivity to output gap (positive gap leads to steeper belly). */
    public const SVENSSON_CURVATURE1_GAP_SCALE = 0.15;
    /** Curvature decay: Diebold-Li (2006) 0.0609 per month, so the forward-guidance hump sits at 2.5 years and moves the 2Y against the 10Y. */
    public const SVENSSON_LAMBDA_1 = 0.73;
    /** Bliss (1997) slope decay: how fast the market expects the policy rate to return to neutral (half-life ~2.3 years, one cycle). Loads the 2Y 0.75 and the 10Y 0.32 on the policy gap, the empirical betas; Diebold-Li's 0.73 would give the 10Y only 0.14. */
    public const SVENSSON_SLOPE_LAMBDA = 0.30;
    /** Secondary Svensson decay parameter governing the long-term hump. */
    public const SVENSSON_LAMBDA_2 = 0.15;
    /** Sensitivity of secondary curvature (beta3) to quantitative tightening and long-term fiscal deficits. */
    public const SVENSSON_CURVATURE2_FISCAL_SCALE = 0.02;
    /** Sensitivity of beta3 secondary curvature to central bank balance sheet (positive QT steepens, negative QE suppresses). */
    public const SVENSSON_CURVATURE2_BS_SCALE = 0.40;
    /** Wright (2011) IRP: term premium sensitivity to excess inflation expectations above target. Kept modest: the 2022 episode showed breakevens near 3% adding little premium once expectations are anchored. */
    public const TERM_PREMIUM_IRP_EXPECTATION_SCALE = 0.20;
    /** Safe-haven flight to safety: financial market panic compresses sovereign term premium (Campbell et al. 2020). */
    public const FLIGHT_TO_SAFETY_SENSITIVITY = 0.015;
    /** Restrictive stance term premium compression (ACM 2013): the premium is squeezed as the stance tightens, matching the near-zero ACM premium of 2023. With the Bliss slope decay the inversion itself comes from expected cuts, so this only needs to trim, not erase. */
    public const TERM_PREMIUM_TIGHTENING_COMPRESSION = 0.45;
    /** Annual attenuation speed at which tightening compression fades over a long restrictive phase (half-life ~2.8 years, so a normal two-year peak keeps most of it), as the market accepts higher-for-longer and demands the full premium again. Keyed to the restrictive-stance clock, not the sign of the slope, so a flat curve cannot keep resetting it. */
    public const TERM_PREMIUM_COMPRESSION_DECAY_RATE = 0.25;
    /** Speed at which the restrictive-stance clock unwinds once policy is back at or below neutral (half-life ~4 months). */
    public const RESTRICTIVE_DURATION_UNWIND_RATE = 2.0;
    /** Floor on the ten-year term premium (-75bps): ACM ran between -50 and -100bps from 2016 to 2021, so flight to safety and QE may push the long end below the expected policy path. */
    public const MIN_TERM_PREMIUM_10Y = -0.0075;

    // --- Term Premium Dynamics (ACM 2013 persistence, Campbell-Pflueger-Viceira 2020 regimes) ---
    /** Mean reversion of transitory term premium shocks (half-life ~8 months): ACM show the premium is persistent but not permanent. */
    public const TERM_PREMIUM_SHOCK_KAPPA = 1.0;
    /** Annual volatility of transitory term premium shocks: a stationary spread of ~50bps and ~35bps quarterly moves (ACM 2013 quarterly changes run 30-35bps), so a taper tantrum is a two-sigma quarter. */
    public const TERM_PREMIUM_SHOCK_SIGMA = 0.0070;
    /** Cap on the transitory shock (200bps either way), the largest ACM swing on record. */
    public const TERM_PREMIUM_SHOCK_CAP = 0.02;
    /** Mean reversion of the structural term premium regime (half-life ~8 years): eras such as the 1990s at 2% and the 2010s near zero, set by the bond-stock correlation. */
    public const TERM_PREMIUM_REGIME_KAPPA = 0.087;
    /** Annual volatility of the structural regime: a stationary spread of ~50bps around the baseline. */
    public const TERM_PREMIUM_REGIME_SIGMA = 0.0021;
    /** Floor of the structural term premium regime (the 2010s era). */
    public const MIN_TERM_PREMIUM_REGIME = 0.0;
    /** Ceiling of the structural term premium regime (the early 1990s era). */
    public const MAX_TERM_PREMIUM_REGIME = 0.025;

    // --- Preferred-Habitat Duration Extraction (Vayanos-Vila 2021) ---
    /** Sensitivity of duration-weighted term premium extraction to central bank balance sheet intensity. */
    public const PREFERRED_HABITAT_DURATION_SENSITIVITY = 1.0;

    // --- Forward-Looking TIPS Breakeven & Phillips Expectations ---
    /** Weight on anchored central bank target in TIPS breakeven inflation expectation. */
    public const TIPS_TARGET_WEIGHT = 0.40;
    /** Weight on adaptive trend inflation in TIPS breakeven inflation expectation. */
    public const TIPS_TREND_WEIGHT = 0.40;
    /** Weight on forward-looking output gap pressure in TIPS breakeven inflation expectation. */
    public const TIPS_CYCLICAL_WEIGHT = 0.20;
    /** Pflueger & Viceira (2011) inflation risk premium sensitivity to excess inflation and supply shocks. */
    public const TIPS_INFLATION_RISK_PREMIUM_SCALE = 0.25;

    // --- Distributed Lag Transmission Constants ---
    /** Characteristic half-life time constant in years for energy cost-push pass-through into core inflation. */
    public const ENERGY_COST_PUSH_LAG_YEARS = 0.50;
    /** Headline inflation per unit farm-price shock (~13% food CPI weight at ~15% pass-through), symmetric in both directions. */
    public const AGRI_COST_PUSH_TRANSMISSION = 0.020;
    /** Characteristic half-life in years for food cost-push pass-through into core inflation. */
    public const AGRI_COST_PUSH_LAG_YEARS = 0.75;

    // --- New Keynesian Phillips Curve Dynamics ---
    /** Adaptive unanchoring weight of inflation expectations to sustained trend deviations. */
    public const INFLATION_ADAPTIVE_EXPECTATIONS_WEIGHT = 0.25;
    /** Phillips curve slope: sensitivity of headline inflation to the output gap. */
    public const PHILLIPS_SLOPE = 0.25;
    /** Speed of inflation expectations mean-reverting toward central bank target (anchored expectations). */
    public const INFLATION_MEAN_REVERSION = 0.75;
    /** Maximum asymptotic output gap capacity ceiling where supply bottlenecks bind (Benigno & Eggertsson 2023). */
    public const PHILLIPS_MAX_CAPACITY = 0.08;
    /** Base slope sensitivity of convex Phillips curve to output gap capacity. */
    public const PHILLIPS_CONVEX_KAPPA = 0.020;
    /** Downward nominal rigidity factor dampening deflationary pressure during recessions (Bewley 1999). */
    public const PHILLIPS_DOWNWARD_RIGIDITY_FACTOR = 0.35;
    /** Weight of supercore services inflation in headline PCE/CPI basket (Shapiro 2022). */
    public const INFLATION_WEIGHT_SUPERCORE = 0.55;
    /** Weight of core goods inflation in headline basket. */
    public const INFLATION_WEIGHT_GOODS = 0.25;
    /** Weight of energy and agricultural food commodities in headline basket. */
    public const INFLATION_WEIGHT_COMMODITY = 0.20;

    // --- Sectoral Inflation Sensitivities (Shapiro 2022) ---
    /** Wage-push transmission factor passing excess wage growth into supercore services inflation. */
    public const SUPERCORE_WAGE_TRANSMISSION = 0.25;
    /** Demand sensitivity factor scaling aggregate output gap pressure into core goods prices. */
    public const CORE_GOODS_DEMAND_SENSITIVITY = 0.80;
    /** Pass-through elasticity of ocean freight logistics bottlenecks into core goods inflation. */
    public const CORE_GOODS_FREIGHT_SENSITIVITY = 0.015;
    /** Pass-through elasticity of industrial metals supply friction into core goods inflation. */
    public const CORE_GOODS_METALS_SENSITIVITY = 0.008;
    /** Pass-through elasticity of global supply chain pressure index (GSCPI Z-score) into core goods inflation. */
    public const CORE_GOODS_GSCPI_SENSITIVITY = 0.003;
    /** Stochastic diffusion volatility (sigma) of headline inflation fluctuations. */
    public const INFLATION_DIFFUSION_SIGMA = 0.002;

    // --- Merton Structural Corporate Credit Spreads (Merton 1974) ---
    /** IG spread widening per unit of interbank stress (~0.6). TED and IG share a common factor, and with the reverse coupling below the loop gain stays under 0.25 so calm markets do not self-excite. */
    public const INTERBANK_CREDIT_CONTAGION_SENSITIVITY = 0.6;
    /** Through-the-cycle investment-grade spread (130 bps), the long-run median of IG OAS. */
    public const BASE_CREDIT_SPREAD = 0.013;
    /** Log-elasticity of the IG spread to the output gap (distance-to-default): a -3% gap widens IG ~1.35x, a +3% gap tightens it to ~0.74x. */
    public const MERTON_LEVERAGE_SENSITIVITY = 10.0;
    /** IG spread widening per unit of equity volatility above the threshold (vol as a fraction): 30% vol adds ~180 bps, 45% vol ~360 bps. */
    public const MERTON_VOL_SENSITIVITY = 0.12;
    /** Floor on the investment-grade spread (80 bps), the tightest IG OAS of the 2000s cycle. */
    public const MIN_CREDIT_SPREAD = 0.008;
    /** Cap on the investment-grade spread (650 bps): ICE BofA US Corporate OAS peaked near 620 bps in Dec 2008. */
    public const MAX_CREDIT_SPREAD = 0.065;
    /** Equity volatility above which credit charges a vol premium (~20%, the long-run VIX median), so ordinary vol does not widen IG. */
    public const CREDIT_SPREAD_EXCESS_VOL_THRESHOLD = 0.20;

    // --- Dual-Tranche Corporate Credit Spreads & Rating Migration (Jarrow-Lando-Turnbull 1997) ---
    /** Baseline multiple of HY over IG spread (~3.3x): 130 bps IG pairs with ~430 bps HY through the cycle. */
    public const HY_BASE_SPREAD_MULTIPLIER = 3.3;
    /** Log-sensitivity of the HY/IG ratio to contraction depth: a -4% gap lifts the ratio ~1.27x, matching the ~4x HY/IG seen at 2008-type troughs. */
    public const FALLEN_ANGEL_CLIFF_SENSITIVITY = 6.0;
    /** Floor multiple of HY over IG spread; HY never trades inside 1.5x IG even at the tightest point of the cycle. */
    public const HY_MIN_SPREAD_MULTIPLIER = 1.5;
    /** Statutory ceiling cap for aggregate high-yield corporate credit spread. */
    public const MAX_HY_CREDIT_SPREAD = 0.25;

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
    public const SENTIMENT_MISERY_MULTIPLIER = 500.0;
    /** Sensitivity of financial market volatility on consumer sentiment confidence. */
    public const SENTIMENT_VOLATILITY_MULTIPLIER = 80.0;
    /** Momentum sensitivity of worsening inflation and unemployment shifts on consumer confidence. */
    public const SENTIMENT_MOMENTUM_MULTIPLIER = 300.0;
    /** Sensitivity of interest rate environment on consumer sentiment borrowing costs. */
    public const SENTIMENT_RATE_MULTIPLIER = 300.0;
    /** Sensitivity of consumer sentiment expansion boost during positive GDP output gaps. */
    public const SENTIMENT_EXPANSION_MULTIPLIER = 400.0;
    /** Sensitivity penalty on consumer sentiment during GDP output contractions. */
    public const SENTIMENT_CONTRACTION_MULTIPLIER = 500.0;
    /** Sensitivity coefficient penalizing consumer sentiment during retail energy price shocks. */
    public const SENTIMENT_ENERGY_PANIC_SCALE = 0.25;
    /** Mean-reversion speed (theta) of psychological animal spirits returning to fundamentals. */
    public const ANIMAL_SPIRITS_MEAN_REVERSION = 2.0;
    /** Stochastic diffusion volatility (sigma) of consumer animal spirits. */
    public const ANIMAL_SPIRITS_VOLATILITY = 2.5;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Policy rate threshold below which QE bond purchases can be initiated during recessions. */
    public const QE_ACTIVATION_RATE_THRESHOLD = 0.025;
    /** Proximity threshold to Zero Lower Bound required before activating QE asset purchases. */
    public const QE_ACTIVATION_ZLB_THRESHOLD = 0.60;
    /** Negative output gap threshold below which central bank initiates QE bond purchases. */
    public const QE_ACTIVATION_GAP_THRESHOLD = -0.005;
    /** Ten-year yield suppression under full-scale QE (~100bps): Gagnon et al. (2011) and Bonis-Ihrig-Wei (2017) put the whole QE1-QE3 stock near 100bps at its 2013 peak. */
    public const QE_MAX_SUPPRESSION = 0.01;
    /** QE dose per unit of negative output gap: full-scale purchases need a ~-2.5% gap with the policy rate at the floor, not a mild slowdown. */
    public const QE_SEVERITY_MULTIPLIER = 0.40;
    /** Annual ramp speed of central bank balance sheet expansion and contraction. */
    public const BALANCE_SHEET_RAMP_SPEED = 1.0;
    /** Minimum reinvestment hold period (years) after QE ends before QT runoff can begin (Bernanke 2020). */
    public const BALANCE_SHEET_REINVESTMENT_HOLD_YEARS = 1.5;
    /** Minimum threshold for balance sheet intervention intensity to be considered active. */
    public const BALANCE_SHEET_ACTIVE_THRESHOLD = 0.0005;
    /** Positive output gap threshold above which central bank initiates Quantitative Tightening. */
    public const QT_ACTIVATION_GAP_THRESHOLD = 0.010;
    /** Inflation threshold above which central bank initiates Quantitative Tightening */
    public const QT_ACTIVATION_INFLATION_THRESHOLD = 0.022;
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
    /** Counter-cyclical appropriation response: a -3% output gap lifts the spending index ~6 points (discretionary stimulus plus stabilizers). */
    public const GOVT_COUNTERCYCLICAL_SENSITIVITY = 200.0;
    /** Mean-reversion speed of government spending toward structural baseline. */
    public const GOVT_SPENDING_MEAN_REVERSION = 0.30;
    /** Stochastic volatility of annual budget appropriations, kept below the countercyclical swing so the cycle drives spending. */
    public const GOVT_SPENDING_VOLATILITY = 0.03;
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
    /** Elasticity of commercial net operating income (NOI) to general price inflation and GDP demand. */
    public const CRE_RENT_GROWTH_ELASTICITY = 0.80;
    /** Mean-reversion speed of commercial property values toward fundamental equilibrium. */
    public const CRE_MEAN_REVERSION = 0.25;
    /** Stochastic volatility of commercial property valuations (stationary noise ~7% so cap rates, not noise, drive the cycle). */
    public const CRE_VOLATILITY = 0.05;
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
    /** Sensitivity of consumer macro credit Z-score to debt service and corporate borrowing spread stress. */
    public const RETAIL_DEBT_SERVICE_SENSITIVITY = 15.0;
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
    /** Prime 30Y fixed mortgage spread over the 10Y sovereign (~170 bps): prepayment duration prices mortgages off the ten-year, not the thirty. */
    public const RESIDENTIAL_MORTGAGE_SPREAD = 0.017;
    /** Structural property tax, insurance, and maintenance depreciation rate. */
    public const RESIDENTIAL_DEPRECIATION_TAX_RATE = 0.025;
    /** Equilibrium user cost of housing: neutral 10Y (r* + target + base premium) + mortgage spread + carry costs - target inflation. */
    public const RESIDENTIAL_NEUTRAL_USER_COST = self::BASE_NATURAL_RATE + self::TARGET_INFLATION + self::NS_BASE_TERM_PREMIUM
        + self::RESIDENTIAL_MORTGAGE_SPREAD + self::RESIDENTIAL_DEPRECIATION_TAX_RATE - self::TARGET_INFLATION;
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
    /** Stochastic volatility of residential home prices (stationary noise ~5% so the user-cost channel dominates). */
    public const RESIDENTIAL_VOLATILITY = 0.03;
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
    /** Floor on the interbank spread (1 bp) keeping the CIR process strictly positive. */
    public const INTERBANK_MIN_SPREAD = 0.0001;
    /** Cap on the interbank spread (500 bps): the TED spread's all-time high was 457 bps on 10 Oct 2008. */
    public const INTERBANK_MAX_SPREAD = 0.05;
    /** Share of excess IG spread that lifts the interbank spread's mean (~0.4): a 470 bps IG blowout pulls TED toward ~200 bps, a mild recession toward ~90. */
    public const INTERBANK_CREDIT_COUPLING = 0.40;
    /** Poisson intensity of severe interbank credit freeze/panic events. */
    public const INTERBANK_JUMP_PROBABILITY = 0.05;
    /** Mean log-size of a panic jump: median 2.7x (1.65x to 4.5x at one sigma), so a 2008-scale 5x freeze is the tail, not the norm. */
    public const INTERBANK_JUMP_MEAN = 1.00;
    /** Sigma of the jump log-size; at 0.50 the two-sigma low is exactly 1.0x, so a panic jump never shrinks the spread. */
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

    // --- Sovereign Debt Dynamics (Greenwood-Vayanos 2014) ---
    /** Initial sovereign debt-to-GDP ratio at simulation start (Maastricht 60% benchmark). */
    public const INITIAL_DEBT_TO_GDP = 0.60;
    /** Baseline structural primary fiscal deficit as a fraction of GDP. */
    public const SOVEREIGN_STRUCTURAL_DEFICIT = 0.020;
    /** Long-end term premium sensitivity per unit excess debt/GDP above neutral threshold. */
    public const SOVEREIGN_DEBT_YIELD_SENSITIVITY = 0.01;
    /** Debt-to-GDP baseline level below which no excess fiscal term premium applies. */
    public const SOVEREIGN_DEBT_NEUTRAL_THRESHOLD = 0.70;
    /** Bohn (1998, 2008) fiscal reaction: primary surplus response per unit of debt above the neutral threshold (~0.10, the upper end of advanced-economy estimates), which stabilizes debt near 90% against a 2% structural deficit. */
    public const BOHN_FISCAL_REACTION_SENSITIVITY = 0.10;

    // --- Financial Conditions Index (Goldman Sachs / Chicago Fed) ---
    /** Weight on corporate credit spread deviation in FCI composite. */
    public const FCI_CREDIT_SPREAD_WEIGHT = 0.25;
    /** Weight on equity risk premium deviation in FCI composite. */
    public const FCI_ERP_WEIGHT = 0.20;
    /** Weight on currency appreciation/depreciation in FCI composite. */
    public const FCI_EXCHANGE_RATE_WEIGHT = 0.15;
    /** Weight on yield curve slope inversion in FCI composite. */
    public const FCI_YIELD_SLOPE_WEIGHT = 0.15;
    /** Weight on excess market volatility in FCI composite. */
    public const FCI_VOLATILITY_WEIGHT = 0.15;
    /** Weight on bank lending standards (SLOOS net tightening) in the composite FCI. */
    public const FCI_SLOOS_WEIGHT = 0.10;
    /** Neutral IG credit spread for FCI normalization, tied to the model's own through-the-cycle spread. */
    public const FCI_CREDIT_MEAN = self::BASE_CREDIT_SPREAD;
    /** Historical standard deviation for investment-grade credit spreads in FCI normalization. */
    public const FCI_CREDIT_STD = 0.008;
    /** Neutral equity risk premium for FCI normalization, tied to the model's habit-formation baseline. */
    public const FCI_ERP_MEAN = self::BASE_EQUITY_RISK_PREMIUM;
    /** Historical standard deviation for equity risk premium in FCI normalization. */
    public const FCI_ERP_STD = 0.015;
    /** Historical standard deviation for currency index deviations in FCI normalization. */
    public const FCI_FX_STD = 10.0;
    /** Neutral 10Y-minus-policy slope for FCI normalization: with policy at neutral the slope is the base ten-year premium. */
    public const FCI_SLOPE_MEAN = self::NS_BASE_TERM_PREMIUM;
    /** Historical standard deviation for yield curve slope in FCI normalization. */
    public const FCI_SLOPE_STD = 0.012;
    /** Neutral equity volatility for FCI normalization, tied to the model's calm-market vol anchor. */
    public const FCI_VOL_MEAN = self::MACRO_VOL_BASE_ANCHOR;
    /** Historical standard deviation for equity market volatility in FCI normalization. */
    public const FCI_VOL_STD = 0.06;
    /** Historical mean benchmark for SLOOS net tightening index in FCI normalization. */
    public const FCI_SLOOS_MEAN = 0.0;
    /** Historical standard deviation for SLOOS net tightening index in FCI normalization. */
    public const FCI_SLOOS_STD = 0.20;
    /** OU smoothing speed of FCI toward fundamental composite value. */
    public const FCI_MEAN_REVERSION = 2.0;

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

    // --- Speculative-Grade Corporate Default Dynamics (Moody's / Altman) ---
    /** Long-run average through-the-cycle speculative corporate probability of default (~1.8%). */
    public const CORPORATE_DEFAULT_BASELINE = 0.018;
    /** Basel II/III corporate asset correlation factor for speculative exposures. */
    public const CORPORATE_DEFAULT_RHO = 0.20;
    /** Sensitivity of corporate credit Z-score to macroeconomic output gap. */
    public const CORPORATE_DEFAULT_GAP_SENSITIVITY = 25.0;
    /** Corporate credit Z per unit of excess HY spread (~5): a 2,000 bps blowout with a -4% gap and 80% SLOOS yields a ~13% default rate. */
    public const CORPORATE_DEFAULT_SPREAD_SENSITIVITY = 5.0;
    /** Corporate credit Z per unit of SLOOS net tightening (~1): a 35% credit crunch adds ~0.35 to the systemic factor. */
    public const CORPORATE_DEFAULT_SLOOS_SENSITIVITY = 1.0;

    // --- Federal Reserve Senior Loan Officer Opinion Survey (SLOOS) ---
    /** Mean-reversion speed (kappa) of bank lending standards toward fundamental target. */
    public const SLOOS_KAPPA = 1.80;
    /** Sensitivity of net tightening percentage to wholesale corporate credit spread widening. */
    public const SLOOS_CREDIT_SENSITIVITY = 15.0;
    /** Sensitivity of net tightening percentage to output gap contraction. */
    public const SLOOS_GAP_SENSITIVITY = 3.0;
    /** Stochastic diffusion volatility of commercial bank underwriting standards. */
    public const SLOOS_SIGMA = 0.08;

    // --- NY Fed Global Supply Chain Pressure Index (GSCPI - Benigno et al. 2022) ---
    /** Neutral baseline index for GSCPI composite (standard deviations). */
    public const GSCPI_BASELINE = 0.0;

    // --- 3:2:1 Refining Crack Spread & Distillate Margins (Bourgeon et al. 1998) ---
    /** Baseline long-run equilibrium refining crack spread in $/bbl. */
    public const CRACK_SPREAD_BASELINE = 22.0;
    /** Mean-reversion speed (kappa) of refining crack margins toward baseline equilibrium. */
    public const CRACK_SPREAD_KAPPA = 1.50;
    /** Stochastic volatility of spot crack margins. */
    public const CRACK_SPREAD_SIGMA = 0.25;

    // --- Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow ---
    /** Baseline neutral capital markets deal flow index (neutral advisory environment). */
    public const DEAL_ACTIVITY_BASELINE = 100.0;
    /** Mean-reversion speed (kappa) of deal activity toward fundamental valuation capacity. */
    public const DEAL_ACTIVITY_KAPPA = 1.60;
    /** Stochastic volatility of deal activity volume. */
    public const DEAL_ACTIVITY_SIGMA = 0.15;
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
    /** PMI points per unit CU deviation (~0.5 per pp): the level of capacity strain feeds supplier deliveries and prices, a secondary channel next to growth. */
    public const PMI_CU_SENSITIVITY = 50.0;
    /** PMI points per unit of annualized real growth over potential (~3.3 per pp): ISM's published mapping of the headline index to annualized real GDP growth, ~0.3pp of growth per index point. A diffusion index measures change, so a V-shaped recovery reads in the high 50s while the level of activity is still below trend. */
    public const PMI_GROWTH_SENSITIVITY = 330.0;
    /** Sensitivity of manufacturing PMI to Metzler inventory restocking demand (shortfall stimulates new orders). */
    public const PMI_INVENTORY_SENSITIVITY = 20.0;
    /** Sensitivity of manufacturing PMI to commercial banking credit standards tightening (SLOOS). */
    public const PMI_SLOOS_SENSITIVITY = 12.0;
    /** Mean-reversion speed (kappa) of manufacturing PMI toward fundamental business conditions (half-life ~6 weeks): a monthly survey reprices within a month or two, so the index is coincident with quarterly growth; at a four-month half-life it trailed growth by two quarters. */
    public const PMI_KAPPA = 6.0;
    /** Stochastic diffusion volatility of manufacturing PMI survey sentiment. */
    public const PMI_SIGMA = 1.2;
    /** Asymptotic lower bound floor for manufacturing PMI during severe industrial depressions. */
    public const MIN_PMI = 30.0;
    /** Asymptotic upper bound ceiling for manufacturing PMI during hyper-expansionary booms. */
    public const MAX_PMI = 70.0;

    // --- Producer Price Index (PPI) Stage-of-Processing ---
    /** Weight of industrial metals price inflation in intermediate producer prices. */
    public const PPI_METALS_WEIGHT = 0.20;
    /** Weight of primary energy and petroleum price inflation in intermediate producer prices. */
    public const PPI_ENERGY_WEIGHT = 0.25;
    /** Weight of agricultural raw food commodities in intermediate producer prices. */
    public const PPI_AGRI_WEIGHT = 0.15;
    /** Pass-through elasticity of global supply chain pressure (GSCPI Z-score) into wholesale PPI. */
    public const PPI_GSCPI_SENSITIVITY = 0.006;
    /** Weight of Unit Labor Cost (ULC = Wage Growth - TFP Growth) in wholesale producer processing costs. */
    public const PPI_ULC_WEIGHT = 0.25;
    /** Sensitivity of wholesale producer price margins to cyclical GDP output gap demand. */
    public const PPI_DEMAND_SENSITIVITY = 0.35;
    /** Lower bound floor on annual producer price deflation. */
    public const MIN_PPI_INFLATION = -0.06;
    /** Upper bound ceiling on runaway wholesale producer price inflation. */
    public const MAX_PPI_INFLATION = 0.25;

    // --- Mundell-Fleming Trade Balance & Net Exports ---
    /** Baseline structural trade balance as a percentage of GDP (-2.5% neutral deficit). */
    public const TRADE_BALANCE_BASELINE = -0.025;
    /** Marshall-Lerner elasticity of trade balance to currency exchange rate index deviations from neutral. */
    public const TRADE_BALANCE_FX_ELASTICITY = 0.040;
    /** Absorption elasticity of trade balance to cyclical domestic GDP output gap demand. */
    public const TRADE_BALANCE_GAP_ELASTICITY = 0.080;
    /** Lower bound floor for trade balance deficit as a percentage of GDP (-8.0%). */
    public const MIN_TRADE_BALANCE = -0.080;
    /** Upper bound ceiling for trade balance surplus as a percentage of GDP (+3.0%). */
    public const MAX_TRADE_BALANCE = 0.030;

    // --- Tobin's Q Housing Investment Dynamics (Poterba 1984, Topel-Rosen 1988) ---
    /** Baseline housing starts activity index (100.0 = neutral construction equilibrium). */
    public const HOUSING_STARTS_BASELINE = 100.0;
    /** Sensitivity of housing starts to Tobin's q ratio (home price valuation vs replacement cost). */
    public const HOUSING_STARTS_Q_SENSITIVITY = 50.0;
    /** Sensitivity of residential housing starts to user cost of housing capital and mortgage rates. */
    public const HOUSING_STARTS_USER_COST_SENSITIVITY = 300.0;
    /** Sensitivity of housing construction orders to bank mortgage lending standards tightening (SLOOS). */
    public const HOUSING_STARTS_SLOOS_SENSITIVITY = 25.0;
    /** Mean-reversion speed (kappa) of housing construction volume toward equilibrium capacity. */
    public const HOUSING_STARTS_KAPPA = 1.5;
    /** Stochastic diffusion volatility of new housing starts. */
    public const HOUSING_STARTS_SIGMA = 0.08;
    /** Structural lower floor for the housing starts index. */
    public const MIN_HOUSING_STARTS = 40.0;
    /** Structural upper ceiling for the housing starts index. */
    public const MAX_HOUSING_STARTS = 220.0;

    // --- Monetarist M2 Broad Money Supply Dynamics (Friedman-Schwartz, Brunner-Meltzer) ---
    /** Baseline structural annual growth rate of M2 money supply matching nominal potential GDP trend. */
    public const M2_BASE_GROWTH = 0.045;
    /** Sensitivity of broad M2 money growth to central bank QE/QT balance sheet operations. */
    public const M2_QE_SENSITIVITY = 2.40;
    /** Sensitivity of commercial bank money creation multiplier to lending standards tightening (SLOOS). */
    public const M2_SLOOS_SENSITIVITY = 0.06;
    /** Cyclical credit demand sensitivity scaling M2 money growth with the output gap. */
    public const M2_GAP_SENSITIVITY = 0.25;
    /** Mean-reversion speed (kappa) of broad money supply growth toward fundamental trajectory. */
    public const M2_KAPPA = 1.80;
    /** Stochastic diffusion volatility of annual M2 money supply growth. */
    public const M2_SIGMA = 0.005;
    /** Lower bound floor for annual M2 money supply growth (-2.0% broad contraction). */
    public const MIN_M2_GROWTH = -0.020;
    /** Upper bound ceiling for annual M2 money supply expansion (+25.0% wartime/crisis expansion). */
    public const MAX_M2_GROWTH = 0.250;

    // --- Continuous EMA Indicator Smoothing Horizons ---
    /** Standard quarterly macro indicator EMA smoothing horizon. */
    public const STANDARD_EMA_HORIZON_YEARS = 0.25;

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

    // --- District-Wide Systemic Event Triggers ---
    /** Minimum simulated years between district-wide events, so a sustained crisis reports once rather than every tick. */
    public const SYSTEMIC_EVENT_COOLDOWN_YEARS = 0.25;
    /** Interbank spread (100 bps) marking a genuine wholesale funding freeze rather than routine tightness. */
    public const SYSTEMIC_LIQUIDITY_FREEZE_SPREAD = 0.0100;
    /** High-yield spread (1000 bps) at which speculative-grade primary issuance effectively shuts. */
    public const SYSTEMIC_CREDIT_SEIZURE_SPREAD = 0.1000;
    /** Recession probability above which the downturn is formally declared. */
    public const SYSTEMIC_RECESSION_DECLARE_PROBABILITY = 0.50;
    /** Output gap that must accompany the probability trigger, confirming output is genuinely contracting. */
    public const SYSTEMIC_RECESSION_DECLARE_GAP = -0.010;
    /** Sustained inversion duration (years) that historically precedes a downturn and trips the curve alarm. */
    public const SYSTEMIC_INVERSION_ALARM_YEARS = 0.75;
    /** Balance sheet expansion intensity marking an intervention large enough to read as a policy backstop. */
    public const SYSTEMIC_INTERVENTION_QE_INTENSITY = 0.005;
    /** Equity risk premium above which capital is being deployed into genuinely distressed valuations. */
    public const SYSTEMIC_DEPLOYMENT_ERP_THRESHOLD = 0.070;

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
    ) {
    }

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
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     */
    public function updateMacroState(float $dt): \App\DTO\MacroStateDTO
    {
        $state = $this->loadState();

        // Advance physical simulation time in years
        $state->totalTime += $dt;

        // 1. Evaluate Total Factor Productivity (TFP) & Secular Drift
        $tfpTrendGrowthRate = $this->aggregateSubsystem->calculateTotalFactorProductivity($state, $dt);

        // 2. Laubach-Williams (2003) Dynamic Natural Rate of Interest (r*)
        $this->aggregateSubsystem->calculateNaturalRate($state, $tfpTrendGrowthRate, $dt);

        // 3. Labor Market: Okun's Law & Diamond-Mortensen-Pissarides Beveridge Curve
        $this->laborSubsystem->calculateUnemployment($state, $dt);
        $this->laborSubsystem->calculateLaborMarketAndWages($state, $tfpTrendGrowthRate, $dt);

        // 4. Inflation Expectations (TIPS Breakeven)
        $state->tipsBreakeven = $this->aggregateSubsystem->calculateTipsBreakeven($state, self::TARGET_INFLATION, $dt);

        // 5. Central Bank Monetary Policy Target & Rate Setting
        $state->targetRate = $this->monetarySubsystem->calculateTargetRate($state, self::TARGET_INFLATION, $state->naturalRate, $dt);
        $clampedTarget = max(self::EFFECTIVE_LOWER_BOUND, min(self::POLICY_RATE_CEILING, $state->targetRate));
        $state->policyRate = $this->monetarySubsystem->updatePolicyRate($state, $clampedTarget, $dt);

        // 6. Central Bank Balance Sheet Operations (QE / QT)
        $balanceSheetData = $this->monetarySubsystem->calculateBalanceSheetOperations($state, $dt);
        $state->balanceSheetIntensity = $balanceSheetData['new_balance_sheet_intensity'];
        $state->balanceSheetHoldTimer = $balanceSheetData['new_hold_timer'];
        $state->qeIntensity = $balanceSheetData['new_qe_intensity'];
        $state->qeActive = $state->qeIntensity > self::BALANCE_SHEET_ACTIVE_THRESHOLD;
        $state->qtIntensity = $balanceSheetData['new_qt_intensity'];
        $state->qtActive = $state->qtIntensity > self::BALANCE_SHEET_ACTIVE_THRESHOLD;

        // 7. Market Expectations: term premium dynamics, the shifting long-run endpoint and the restrictive-stance clock
        $this->monetarySubsystem->updateTermPremiumDynamics($state, $dt);
        $this->monetarySubsystem->updateMarketExpectations($state, $dt);

        // 8. Sovereign Yield Curve & Term Structure Decomposition
        $yieldData = $this->monetarySubsystem->calculateYieldCurve($state, self::TARGET_INFLATION, $state->naturalRate);

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

        // Systemic Market Factor: the single common driver behind every equity price, built from two
        // components because one process cannot supply both properties markets actually exhibit.
        //
        // An i.i.d. normal draw -- the previous behaviour -- gave the factor no memory at all, so a
        // market-wide selloff could not mathematically survive into the next tick and no drawdown or rally
        // ever lasted longer than one. The regime leg fixes that with an AR(1) memory whose persistence is a
        // decay constant in YEARS, keeping regime length invariant to SIM_TICKS_PER_YEAR.
        //
        // Fat tails cannot ride on that leg: at this persistence the AR(1) averages on the order of a hundred
        // innovations, and the central limit theorem erases their kurtosis entirely (a t(4) innovation with
        // kurtosis ~11 emerges from the recursion at ~2.8, thinner than a normal). So the crash component is
        // carried by an independent i.i.d. Student's t shock, where the kurtosis survives.
        //
        // Both legs are scaled so integrated annual variance is exactly preserved: the regime leg is divided
        // by its own autocorrelation inflation (otherwise persistence alone would multiply realised market
        // volatility roughly twenty-five fold), and the two are then combined in variance shares summing to
        // one. Persistence and tails therefore change the SHAPE of the market's path, never its magnitude.
        $marketFactorPhi = exp(-$dt / self::MARKET_FACTOR_DECAY_TAU_YEARS);
        $state->marketZLatent = $this->mathUtility->generatePersistentZ($state->marketZLatent, $marketFactorPhi);

        $regimeShock = $state->marketZLatent * $this->mathUtility->calculatePersistenceVarianceScale($marketFactorPhi);
        $tailShock = $this->mathUtility->generateStudentsT(self::MARKET_FACTOR_TAIL_DF);

        $state->marketZ = (sqrt(self::MARKET_FACTOR_REGIME_VARIANCE_SHARE) * $regimeShock)
            + (sqrt(1.0 - self::MARKET_FACTOR_REGIME_VARIANCE_SHARE) * $tailShock);

        // Market-Wide Jump: the discontinuous component of the common factor.
        // A fat-tailed diffusion shock alone cannot produce a crash DAY. At this tick rate a day is a sum of
        // many shocks, and the central limit theorem flattens their kurtosis back toward normal long before a
        // player sees it -- swapping the market factor's normal draw for a Student's t moves index daily
        // kurtosis only from about 2.97 to 3.19. Genuine index tail risk has to arrive as a jump that is large
        // in a single tick and therefore survives aggregation, which is exactly how per-stock tail risk is
        // already modelled by the SVJJ process. The same Kou double-exponential is reused here, skewed
        // downward, so the whole district can gap at once rather than only individual firms.
        $systemicJump = $this->mathUtility->calculateSVJJJumps(
            lambda: self::SYSTEMIC_JUMP_INTENSITY,
            pUp: self::SYSTEMIC_JUMP_PROBABILITY_UP,
            etaUp: self::SYSTEMIC_JUMP_ETA_UP,
            etaDown: self::SYSTEMIC_JUMP_ETA_DOWN,
            muV: self::SYSTEMIC_JUMP_VARIANCE_MEAN,
            dt: $dt
        );
        $state->marketJumpMultiplier = $systemicJump['price_multiplier'];

        // 2D Kaldor Phase Space: Capital Stock tracking
        // Booms build excess capacity (+k); Recessions cause physical depreciation and pent-up demand (-k).
        $state->capitalStockOverhang += (($state->outputGap * self::CAPITAL_ACCUMULATION_RATE) - (self::CAPITAL_DECAY_RATE * $state->capitalStockOverhang)) * $dt;
        $state->capitalStockOverhang = max(self::CAPITAL_OVERHANG_MIN, min(self::CAPITAL_OVERHANG_MAX, $state->capitalStockOverhang));

        $stressMultiplier = 1.0 + (abs($state->outputGap) * self::STRESS_MULTIPLIER_GAP_SENSITIVITY);
        $state->outputGap = $this->aggregateSubsystem->calculateOutputGap($state, $state->yield5y, $state->naturalRate, $dt, $stressMultiplier);

        $this->aggregateSubsystem->calculateCapacityUtilization($state);
        $this->commoditySubsystem->calculateEnergyShock($state, $dt);
        $this->commoditySubsystem->calculateRefiningCrackSpread($state, $dt);
        $this->assetSubsystem->calculateExchangeRate($state, $dt);
        $this->assetSubsystem->calculateTradeBalance($state, $dt);
        $this->commoditySubsystem->calculateIndustrialMetalsIndex($state, $dt);
        $this->creditFiscalSubsystem->calculateGovernmentSpending($state, $dt);
        $this->assetSubsystem->calculateCommercialPropertyIndex($state, $dt);
        $this->creditFiscalSubsystem->calculateRetailDefaultRate($state, $dt);
        $this->commoditySubsystem->calculateAgriculturalCommodityIndex($state, $dt);
        $this->commoditySubsystem->calculateFreightRateIndex($state, $dt);
        $this->commoditySubsystem->calculateSupplyChainPressureIndex($state);
        $this->assetSubsystem->calculateResidentialPropertyIndex($state, $dt);
        $this->assetSubsystem->calculateHousingStarts($state, $dt);

        $stressMultiplier = 1.0 + (abs($state->outputGap) * self::STRESS_MULTIPLIER_GAP_SENSITIVITY);
        $state->inflation = $this->aggregateSubsystem->calculateInflation($state, self::TARGET_INFLATION, $stressMultiplier, $dt);
        $this->aggregateSubsystem->calculateProducerPriceInflation($state, $tfpTrendGrowthRate, $dt);
        $state->marketVolatility = $this->assetSubsystem->calculateMarketVolatility($state, $dt);

        $this->aggregateSubsystem->calculateManufacturingPmi($state, $dt);
        $this->monetarySubsystem->calculateMoneySupplyGrowth($state, $dt, $tfpTrendGrowthRate);

        $this->aggregateSubsystem->updateExponentialMovingAverages($state, $dt);

        $this->creditFiscalSubsystem->calculateMacroCreditSpread($state);
        $this->creditFiscalSubsystem->calculateInterbankLiquiditySpread($state, $dt);
        $this->creditFiscalSubsystem->calculateSloosCreditStandards($state, $dt);
        $this->creditFiscalSubsystem->calculateCorporateDefaultRate($state, $dt);

        $this->aggregateSubsystem->calculatePotentialAndNominalGdp($state, $dt, $tfpTrendGrowthRate);
        $this->creditFiscalSubsystem->calculateDynamicFiscalPolicy($state, $dt);
        $this->creditFiscalSubsystem->calculateSovereignDebt($state, $dt);
        $this->assetSubsystem->calculateEquityRiskPremium($state);
        $this->assetSubsystem->calculateFinancialConditionsIndex($state, $dt);
        $this->assetSubsystem->calculateConsumerSentiment($state, $dt);
        $this->monetarySubsystem->calculateRecessionProbability($state);
        $this->assetSubsystem->calculateCapitalMarketsDealIndex($state, $dt);

        $this->updateSectorFactors($state);
        $this->evaluateSystemicEvent($state, $dt);

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
    private function updateSectorFactors(MacroState $state): void
    {
        foreach (array_keys(\App\Data\Sectors::MACRO_SECTORS) as $sector) {
            $state->sectorZ[$sector] = $this->mathUtility->generateStandardNormal();
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
        // The event is a pulse, not a latch: clear last tick's value before deciding this tick's.
        $state->eventType = null;

        if ($state->eventCooldownTimer > 0.0) {
            $state->eventCooldownTimer = max(0.0, $state->eventCooldownTimer - $dt);
            return;
        }

        $eventType = match (true) {
            $state->interbankLiquiditySpread >= self::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD
                => ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE,

            $state->highYieldCreditSpread >= self::SYSTEMIC_CREDIT_SEIZURE_SPREAD
                => ShockEvent::CREDIT_MARKET_SEIZURE,

            // The backstop only reads as a backstop if it arrives while conditions are actually stressed.
            $state->qeIntensity >= self::SYSTEMIC_INTERVENTION_QE_INTENSITY && $state->outputGapEma < 0.0
                => ShockEvent::TITAN_INTERVENTION,

            $state->recessionProbability >= self::SYSTEMIC_RECESSION_DECLARE_PROBABILITY
                && $state->outputGap <= self::SYSTEMIC_RECESSION_DECLARE_GAP
                => ShockEvent::RECESSION_DECLARED,

            $state->inversionDuration >= self::SYSTEMIC_INVERSION_ALARM_YEARS
                => ShockEvent::YIELD_CURVE_INVERSION_ALARM,

            // Deep value with the cycle already turning: capital steps in as the gap closes from below.
            $state->equityRiskPremium >= self::SYSTEMIC_DEPLOYMENT_ERP_THRESHOLD
                && $state->outputGapEma < 0.0
                && $state->outputGap > $state->outputGapEma
                => ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT,

            default => null,
        };

        if ($eventType !== null) {
            $state->eventType = $eventType;
            $state->eventCooldownTimer = self::SYSTEMIC_EVENT_COOLDOWN_YEARS;
        }
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
        $this->snapshotRecorder->recordSnapshot($macroState, $conn);
    }
}

