<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Math\MathUtility;

/**
 * Models asset pricing, commercial and residential real estate, equity volatility,
 * foreign exchange rates, and behavioral animal spirits.
 */
class AssetMarketSubsystem
{
    // --- JORGENSON USER COST RESIDENTIAL REAL ESTATE ---
    /** Structural property tax, insurance, and maintenance depreciation rate. */
    public const RESIDENTIAL_DEPRECIATION_TAX_RATE = 0.025;

    // --- JORGENSON USER COST RESIDENTIAL REAL ESTATE ---
    /** Equilibrium user cost of housing: neutral 10Y (r* + target + base premium) + mortgage spread + carry costs - target inflation. */
    public const RESIDENTIAL_NEUTRAL_USER_COST = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM
        + MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD + self::RESIDENTIAL_DEPRECIATION_TAX_RATE - MacroEngine::TARGET_INFLATION;

    // --- Sector Demand Factor ---
    /** Campbell-Cochrane (1999) habit formation risk aversion sensitivity to output gap deviations. */
    public const HABIT_RISK_AVERSION_COEFF = 9.0;
    /** Structural floor: equities must logically yield more than risk-free T-bills. */
    public const MIN_EQUITY_RISK_PREMIUM = 0.02;
    /** Campbell & Shiller (1988) log-linearisation constant, annual: rho = 1 / (1 + mean dividend yield), ~0.96 on the long US sample. */
    public const CAMPBELL_SHILLER_RHO = 0.96;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /** Log volatility per unit of output gap contraction (1.1, se 2.1): ln VIX on the gap AND the excess bond premium, 1990-2026. Alone the gap takes ~4 by standing in for the omitted premium; 2010's VIX averaged 22 with the gap still -3.5%. */
    public const MACRO_VOL_OUTPUT_GAP_SENSITIVITY = 1.1;
    /** Log volatility per unit of excess bond premium (31.6, se 3.6), the same regression: 2008's 340 bps premium lifts the anchor ~2.9x. VIX is uncertainty plus the price of risk (Bekaert & Hoerova 2014), and the premium is that price. */
    public const MACRO_VOL_PREMIUM_SENSITIVITY    = 31.6;
    /** Sensitivity of exponential baseline volatility to yield curve slope (flattening/inversion increases vol). */
    public const MACRO_VOL_SLOPE_SENSITIVITY      = 8.0;
    /** Sensitivity of the baseline volatility anchor to the log policy-uncertainty index: a doubling lifts the anchor ~11% (Pastor & Veronesi 2013 political uncertainty premium; the BBD index co-moves with the VIX). Enters the anchor, so it is budgeted against the jump compensation like the other drivers. */
    public const MACRO_VOL_EPU_SENSITIVITY        = 0.15;
    /** Lower clamp for baseline volatility during extreme Goldilocks expansions. */
    public const MACRO_VOL_MIN_BASELINE           = 0.10;
    /** Upper clamp for macro-driven baseline volatility to prevent infinite variance explosion. */
    public const MACRO_VOL_MAX_BASELINE           = 0.45;
    /** Mean-reversion speed (kappa) of the continuous macroeconomic variance process. */
    public const MACRO_VOL_KAPPA                  = 2.0;
    /** Failsafe floor on realized equity volatility (8%), below the calmest stretch of the cycle. It catches a numerically degenerate variance draw and nothing else: if the process rests on it the parameters above are wrong, because a clamp that binds is setting the level this class claims to anchor. */
    public const MACRO_VOL_FLOOR                  = 0.08;
    /** Failsafe ceiling on realized equity volatility (80%), above the 2008 VIX peak. */
    public const MACRO_VOL_CEILING                = 0.80;
    /** Volatility of volatility (sigma) in the macroeconomic variance diffusion. Kept under sqrt(2 * kappa * theta) measured on the JUMP-ADJUSTED anchor (~0.26), so the Feller condition holds and the variance stays strictly positive. Above it the stationary density piles onto the 8% clamp, and the clamp -- not this anchor -- sets the realized level. */
    public const MACRO_VOL_SIGMA                  = 0.15;

    // --- SVJJ Stochastic Volatility & Contemporaneous Jumps (Duffie, Pan, & Singleton 2000) ---
    /** Annual Poisson arrival intensity of market-wide volatility jump shocks. */
    public const SVJJ_LAMBDA = 0.80;
    /** Probability of an upward market return jump given a Poisson jump event. */
    public const SVJJ_P_UP = 0.35;
    /** Exponential decay rate parameter for positive return jumps (eta+). */
    public const SVJJ_ETA_UP = 10.0;
    /** Exponential decay rate parameter for negative return crashes (eta-). */
    public const SVJJ_ETA_DOWN = 5.0;

    // --- Consumer Sentiment Index & Animal Spirits ---
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

    // --- The Mainland (Rudebusch & Svensson 1999, re-estimated on US data 1985-2019: var/harness/mainland_fit.py) ---
    /** The mainland's statistics and its central bank's decisions arrive once a quarter, the frequency its equations are estimated at. */
    public const MAINLAND_QUARTERS_PER_YEAR = 4;
    /** First autoregressive coefficient of the mainland gap on the CBO gap (1.197, se 0.143); the real-rate term estimates at zero on this sample (-0.006, se 0.037) and is left out. */
    public const MAINLAND_GAP_AR1 = 1.197;
    /** Second autoregressive coefficient of the mainland gap (-0.296, se 0.159). */
    public const MAINLAND_GAP_AR2 = -0.296;
    /** Quarterly innovation of the mainland gap, the regression's residual sd (0.503pp); the stationary sd it gives is 1.37% against the CBO gap's 1.62%. */
    public const MAINLAND_GAP_SIGMA = 0.00503;
    /** Annualized quarterly core PCE inflation on its own four lags (sum 0.885), the Rudebusch-Svensson Phillips curve; its fitted mean is 1.96%, so it is anchored at the 2% target. */
    public const MAINLAND_INFLATION_LAGS = [0.381, 0.217, 0.141, 0.146];
    /** Mainland core inflation per unit of last quarter's gap (0.004, se 0.028): the flat Phillips curve of the era (Hazell, Herreño, Nakamura & Steinsson 2022). */
    public const MAINLAND_INFLATION_GAP_SLOPE = 0.004;
    /** Quarterly innovation of annualized mainland core inflation, the regression's residual sd (0.586pp). */
    public const MAINLAND_INFLATION_SIGMA = 0.00586;
    /** The mainland's (US) GDP, 2023: $27.8T (World Bank NY.GDP.MKTP.CD). */
    public const MAINLAND_GDP_USD = 27.81e12;
    /** Share of the district's cycle that reaches the mainland's demand in the long run: its imports are the mainland's exports, moving 1.4 times its demand (IMF WEO 2015) at their share of its GDP, scaled to the mainland's size (0.20). */
    public const FOREIGN_IMPORT_SPILLOVER = MacroAggregateSubsystem::IMPORT_DEMAND_ELASTICITY * MacroAggregateSubsystem::DISTRICT_IMPORT_SHARE * MacroEngine::DISTRICT_GDP_USD / self::MAINLAND_GDP_USD;
    /** Guard on the mainland gap, beyond the deepest postwar CBO gap; the fitted process does not reach it. */
    public const MAX_FOREIGN_GAP = 0.10;

    // --- The Fed (Clarida, Gali & Gertler 2000 smoothed rule, 1987Q3-2008Q3: var/harness/mainland_fit.py) ---
    /** Share of last quarter's funds rate the Fed keeps (0.836, se 0.039). */
    public const FED_RULE_SMOOTHING = 0.836;
    /** Long-run response of the funds rate to four-quarter core inflation over target (1.66). */
    public const FED_RULE_INFLATION_RESPONSE = 1.66;
    /** Long-run response of the funds rate to the mainland gap (1.80). */
    public const FED_RULE_GAP_RESPONSE = 1.80;
    /** Quarterly policy shock, the rule's residual sd (0.392pp). */
    public const FED_RULE_SIGMA = 0.00392;
    /** Guard on the funds rate; the fitted rule does not reach it. */
    public const MAX_FOREIGN_POLICY_RATE = 0.20;

    // --- Allied Defence Spending (SIPRI constant-dollar milex over GDP, 1951-2019: var/harness/allied_fit.py) ---
    /** Reversion speed of the log allied defence burden off its trend (0.489/yr, half-life 1.4y), corrected for annual averaging. */
    public const ALLIED_DEFENSE_MEAN_REVERSION = 0.489;
    /** Diffusion of the log allied defence burden, the Merton (1976) MLE on the annual residuals, scaled for the same averaging. */
    public const ALLIED_DEFENSE_VOLATILITY = 0.0932;
    /** Mobilisations a year, the same MLE (LR 31.6 against Gaussian): Korea is the sample's, so about one a generation. */
    public const ALLIED_MOBILISATION_PROBABILITY = 0.029;
    /** Mean log size of a mobilisation, the same MLE: allied spending rises three quarters above trend. */
    public const ALLIED_MOBILISATION_MEAN = 0.5716;
    /** Volatility of the log mobilisation size, the same MLE. */
    public const ALLIED_MOBILISATION_VOL = 0.0542;
    /** Guards on the allied index, a quarter of trend to four times; the fitted process does not reach them. */
    public const MIN_ALLIED_DEFENSE_INDEX = 25.0;
    /** Upper guard on the allied index, beyond two stacked mobilisations. */
    public const MAX_ALLIED_DEFENSE_INDEX = 400.0;

    // --- MUNDELL-FLEMING OPEN ECONOMY (IS-LM-BOP) ---
    /**
     * Baseline exchange rate index (neutral purchasing power parity).
     *
     * The NOMINAL anchor targetFx is built on, not the level the index settles at: the safe-haven bid is
     * rectified and the sovereign risk discount is one-sided, so a run at rest sits near 97.3. A consumer
     * wanting "is the currency strong" reads MacroState::exchangeRateTrend instead — reading this constant
     * for that cost App\Service\Macro\Subsystem\MacroAggregateSubsystem 0.11 pp/yr of standing net-export
     * demand until 2026-09-21.
     */
    public const EXCHANGE_RATE_BASELINE = 100.0;
    /** UIP sensitivity: exchange rate response to domestic-foreign interest rate differential. */
    public const UIP_SENSITIVITY = 3.0;
    /** Mean-reversion speed of exchange rate toward purchasing power parity equilibrium. */
    public const EXCHANGE_RATE_MEAN_REVERSION = 0.60;
    /** Terms-of-trade FX elasticity (Chen-Rogoff 2003, importer sign): a dearer import basket weakens the currency. */
    public const FX_TERMS_OF_TRADE_SENSITIVITY = 0.30;
    /** Safe-haven FX elasticity (Ranaldo-Soderlind 2010): panic bids the reserve currency ~6% at 50% equity vol. */
    public const FX_SAFE_HAVEN_SENSITIVITY = 0.25;
    /** Log depreciation per unit of sovereign risk spread: fiscal risk sells the currency (Alesina & Perotti 1995; Della Corte, Sarno, Schmeling & Wagner 2022 on sovereign risk and currency returns). A 100 bps premium costs ~2%. */
    public const FX_FISCAL_RISK_SENSITIVITY = 2.0;

    // --- Physical Catastrophes ---
    /** Sentiment index points lost per unit of catastrophe burden above an average year (a season of storms is a few points of confidence, not a recession). */
    public const SENTIMENT_CATASTROPHE_MULTIPLIER = 3.0;
    /** Share of the housing stock's fundamental value destroyed per unit of excess catastrophe burden; rebuilt through the housing-starts channel. */
    public const CATASTROPHE_PROPERTY_DAMAGE_SHARE = 0.01;

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

    // --- JORGENSON USER COST RESIDENTIAL REAL ESTATE ---
    /**
     * Baseline residential property index value (neutral home affordability).
     *
     * The NOMINAL anchor the fundamental price is built on, not the level the index settles at: the
     * affordability, damage and credit factors multiplying it average below 1, so a run at rest sits near
     * 94. A consumer wanting "have prices moved" reads MacroState::residentialWealthTrend instead — reading
     * this constant for that cost App\Service\Macro\Subsystem\CreditFiscalSubsystem 0.56%/yr of standing
     * deleveraging until 2026-09-21.
     */
    public const RESIDENTIAL_BASELINE = 100.0;

    /** Fundamental price per point of unemployment above NAIRU (foreclosure and affordability drag), fitted with the income elasticity below and the dynamics further down: at 5.0 and 2.0 a -5% gap took a fifth off the fundamental, household credit followed through the collateral term and new borrowing returned it to demand one for one, and at the lower bound slumps sustained themselves (var/harness/boom_score.py: clamp traps in 3% of 35-year windows, 5-year slumps below -4% in 7%, against none in the US record). */
    public const RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY = 2.5;
    /** Elasticity of the fundamental price to the output gap, a transitory income shock; the same fit. */
    public const RESIDENTIAL_INCOME_ELASTICITY = 1.0;
    /** Lower bound multiplier on residential spatial labor demand during deep labor distress. */
    public const RESIDENTIAL_MIN_LABOR_FACTOR = 0.30;
    /** Upper bound multiplier on residential spatial labor demand during peak labor market expansions. */
    public const RESIDENTIAL_MAX_LABOR_FACTOR = 1.80;
    /** Elasticity of the fundamental house price to the user cost: Glaeser, Gottlieb & Gyourko (2010) measure about 7-8% per 100bp fall in real rates, half the ~17% a unit elasticity gives at the 6.85% neutral user cost, so collapsing long rates at the floor no longer inflate prices in a slump. */
    public const RESIDENTIAL_USER_COST_ELASTICITY = 0.5;
    /** Yearly reversion of real house prices toward their fundamental, fitted with the momentum and volatility below to US real house prices (FHFA / CPI, 1977-2019): annual growth sd 3.86%, autocorrelation 0.71 / 0.33 / 0.00 at one to three years, worst year -9.0%; the engine gives 3.65%, 0.67 / 0.26 / -0.06 and -10.1% at its 0.5th percentile (var/harness/boom_score.py). */
    public const RESIDENTIAL_MEAN_REVERSION = 0.25;
    /** Fundamental price per unit of net lending tightening (Duca, Muellbauer & Murphy 2011; Favara & Imbs 2015): the ~80% tightening of a crisis takes a fifth off, the post-crisis decline in Reinhart & Rogoff. */
    public const RESIDENTIAL_CREDIT_STANDARDS_ELASTICITY = 0.25;
    /** House price response per unit of credit-to-GDP gap (Favara & Imbs 2015): the return leg of the collateral channel, without which Mian & Sufi's home-equity term is a one-way street. */
    public const RESIDENTIAL_CREDIT_SUPPLY_ELASTICITY = 0.25;
    /** Volatility of real house prices around the path the fundamental and momentum set, the same fit. */
    public const RESIDENTIAL_VOLATILITY = 0.018;
    /** Share of the past year's real growth carried into this year's (Case & Shiller 1989; Capozza, Hendershott, Mack & Mayer 2002 serial correlation), the same fit; household credit follows house prices, so it also carries the persistence of new borrowing toward the US's (0.45 against 0.58, from 0.23). */
    public const RESIDENTIAL_PRICE_MOMENTUM = 0.85;
    /** Horizon (years) of the growth buyers extrapolate: the annual change the serial-correlation regressions are run on. */
    public const RESIDENTIAL_MOMENTUM_HORIZON_YEARS = 1.0;
    /** Structural lower floor for the residential property index value. */
    public const RESIDENTIAL_MIN_INDEX = 30.0;
    /** Structural upper ceiling for the residential property index value. */
    public const RESIDENTIAL_MAX_INDEX = 300.0;

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
    public const FCI_CREDIT_MEAN = MacroEngine::BASE_CREDIT_SPREAD;
    /** Historical standard deviation for investment-grade credit spreads in FCI normalization. */
    public const FCI_CREDIT_STD = 0.008;
    /** Neutral equity risk premium for FCI normalization, tied to the model's habit-formation baseline. */
    public const FCI_ERP_MEAN = MacroEngine::BASE_EQUITY_RISK_PREMIUM;
    /** Historical standard deviation for equity risk premium in FCI normalization. */
    public const FCI_ERP_STD = 0.015;
    /** Standard deviation of the log currency gap to its trend in FCI normalization: ~10%, the real broad dollar's swing about its mean. */
    public const FCI_FX_STD = 0.10;
    /** Neutral 10Y-minus-policy slope for FCI normalization: with policy at neutral the slope is the base ten-year premium. */
    public const FCI_SLOPE_MEAN = MacroEngine::NS_BASE_TERM_PREMIUM;
    /** Historical standard deviation for yield curve slope in FCI normalization. */
    public const FCI_SLOPE_STD = 0.012;
    /** Neutral equity volatility for FCI normalization, tied to the model's calm-market vol anchor. */
    public const FCI_VOL_MEAN = MacroEngine::MACRO_VOL_BASE_ANCHOR;
    /** Historical standard deviation for equity market volatility in FCI normalization. */
    public const FCI_VOL_STD = 0.06;
    /** Historical mean benchmark for SLOOS net tightening index in FCI normalization. */
    public const FCI_SLOOS_MEAN = 0.0;
    /** Historical standard deviation for SLOOS net tightening index in FCI normalization. */
    public const FCI_SLOOS_STD = 0.20;

    // --- Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow ---
    /** Mean-reversion speed (kappa) of deal activity toward fundamental valuation capacity. */
    public const DEAL_ACTIVITY_KAPPA = 1.60;
    /** Stochastic volatility of deal activity volume. */
    public const DEAL_ACTIVITY_SIGMA = 0.15;

    // --- Mundell-Fleming Trade Balance & Net Exports ---
    /** Lower bound floor for trade balance deficit as a percentage of GDP (-8.0%). */
    public const MIN_TRADE_BALANCE = -0.080;
    /** Upper bound ceiling for trade balance surplus as a percentage of GDP (+3.0%). */
    public const MAX_TRADE_BALANCE = 0.030;

    // --- Tobin's Q Housing Investment Dynamics (Poterba 1984, Topel-Rosen 1988) ---
    /** Sensitivity of housing starts to Tobin's q ratio (home price valuation vs replacement cost). */
    public const HOUSING_STARTS_Q_SENSITIVITY = 50.0;
    /** Sensitivity of residential housing starts to user cost of housing capital and mortgage rates. */
    public const HOUSING_STARTS_USER_COST_SENSITIVITY = 300.0;
    /** Sensitivity of housing construction orders to bank mortgage lending standards tightening (SLOOS). */
    public const HOUSING_STARTS_SLOOS_SENSITIVITY = 25.0;
    /** Sensitivity of housing starts to aggregate private sector credit-to-GDP gap availability. */
    public const HOUSING_STARTS_CREDIT_GAP_SENSITIVITY = 30.0;
    /** Mean-reversion speed (kappa) of housing construction volume toward equilibrium capacity. */
    public const HOUSING_STARTS_KAPPA = 1.5;
    /** Stochastic diffusion volatility of new housing starts. */
    public const HOUSING_STARTS_SIGMA = 0.08;
    /** Structural lower floor for the housing starts index. */
    public const MIN_HOUSING_STARTS = 40.0;
    /** Structural upper ceiling for the housing starts index. */
    public const MAX_HOUSING_STARTS = 220.0;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /** Records what set the house-price fundamental and consumer sentiment. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null
    ) {}

    /**
     * DiPasquale-Wheaton (1996) Two-Quadrant Commercial Real Estate (CRE) Econometric Model.
     *
     * Couples the spatial tenant market (unemployment occupancy contraction) with the capital asset
     * market (cap rate = 10Y yield + credit spread + CRE risk premium) with physical market adjustment lag.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCommercialPropertyIndex(MacroState $state, float $dt): void
    {
        $excessUnemployment = $state->unemploymentRateEma - $state->nairu;
        $occupancyFactor = 1.0 - ($excessUnemployment * self::CRE_OCCUPANCY_UNEMPLOYMENT_SENSITIVITY);
        $occupancyFactor = max(0.30, min(1.80, $occupancyFactor));

        // DiPasquale-Wheaton (1996) commercial real estate rent adjustment with inflation and demand.
        $rentGrowthFactor = 1.0 + (($state->inflationEma - MacroEngine::TARGET_INFLATION) * self::CRE_RENT_GROWTH_ELASTICITY)
            + ($state->outputGapEma * 0.50);
        $rentGrowthFactor = max(0.50, min(2.0, $rentGrowthFactor));

        $capRate = max(self::CRE_MIN_CAP_RATE, $state->yield10yEma + $state->macroCreditSpreadEma + self::CRE_CAP_RATE_RISK_PREMIUM);
        $fundamentalValue = self::CRE_BASELINE * $occupancyFactor * $rentGrowthFactor * (self::CRE_NEUTRAL_CAP_RATE / $capRate);

        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->commercialPropertyIndex,
            kappa: self::CRE_MEAN_REVERSION,
            theta: $fundamentalValue,
            sigma: self::CRE_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->commercialPropertyIndex = max(30.0, min(250.0, $newIndex));
    }

    /**
     * Jorgenson (1963) User Cost of Capital & Spatial Housing Affordability Equilibrium Model.
     *
     * Calculates fundamental home prices from user cost of housing capital (mortgage rate + taxes - expected inflation)
     * and household real disposable income affordability, with sticky physical mean reversion. Credit conditions
     * enter the fundamental as in Duca, Muellbauer & Murphy (2011): the price a buyer can pay is the price a lender
     * will finance, so net tightening in bank standards lowers it and loosening lifts it.
     *
     * @param MacroState $state             Current macroeconomic state.
     * @param float      $expectedInflation Expected inflation (MonetaryPolicySubsystem::calculateExpectedInflation), the same measure the Taylor rule and IS curve use.
     * @param float      $dt                Time increment in years.
     */
    public function calculateResidentialPropertyIndex(MacroState $state, float $expectedInflation, float $dt): void
    {
        $userCost = $this->housingUserCost($state, $expectedInflation);

        $excessUnemployment = $state->unemploymentRateEma - $state->nairu;
        $laborFactor = 1.0 - ($excessUnemployment * self::RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY);
        $incomeFactor = 1.0 + ($state->outputGapEma * self::RESIDENTIAL_INCOME_ELASTICITY);
        $demandMultiplier = max(self::RESIDENTIAL_MIN_LABOR_FACTOR, min(self::RESIDENTIAL_MAX_LABOR_FACTOR, $laborFactor * $incomeFactor));

        $affordabilityFactor = ((self::RESIDENTIAL_NEUTRAL_USER_COST / $userCost) ** self::RESIDENTIAL_USER_COST_ELASTICITY) * $demandMultiplier;
        // Hallegatte et al. (2007) physical housing stock destruction and post-disaster replacement.
        $damageFactor = 1.0 - (self::CATASTROPHE_PROPERTY_DAMAGE_SHARE * max(0.0, $state->catastropheLossIndexEma - 1.0));
        $creditConditionsFactor = 1.0 - (self::RESIDENTIAL_CREDIT_STANDARDS_ELASTICITY * $state->sloosTighteningIndexEma);
        // Favara & Imbs (2015) credit supply as housing demand: the quantity outstanding, not just the
        // standards it is lent on. One tick stale, since the EMA block runs later in the tick.
        $creditSupplyFactor = $state->creditToGdpTrend > 0.0
            ? 1.0 + (self::RESIDENTIAL_CREDIT_SUPPLY_ELASTICITY * ($state->creditToGdpGapEma / $state->creditToGdpTrend))
            : 1.0;
        $fundamentalPrice = self::RESIDENTIAL_BASELINE * max(0.30, min(2.50, $affordabilityFactor * max(0.5, $damageFactor) * max(0.5, $creditConditionsFactor) * max(0.5, $creditSupplyFactor)));

        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->residentialPropertyIndex,
            kappa: self::RESIDENTIAL_MEAN_REVERSION,
            theta: $fundamentalPrice,
            sigma: self::RESIDENTIAL_VOLATILITY,
            dt: $dt,
            dW: $dW
        );
        // Capozza, Hendershott, Mack & Mayer (2002): real house prices carry serial correlation on top of their
        // reversion to fundamental, so a boom keeps rising after its cause has passed and overshoots.
        $newIndex *= exp(self::RESIDENTIAL_PRICE_MOMENTUM * $state->residentialPriceMomentum * $dt);

        $previousIndex = $state->residentialPropertyIndex;
        $state->residentialPropertyIndex = max(self::RESIDENTIAL_MIN_INDEX, min(self::RESIDENTIAL_MAX_INDEX, $newIndex));
        $state->residentialPriceMomentum = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->residentialPriceMomentum,
            targetValue: log($state->residentialPropertyIndex / $previousIndex) / $dt,
            dt: $dt,
            lagTimeConstant: self::RESIDENTIAL_MOMENTUM_HORIZON_YEARS
        );

        // The fundamental is a product of factors, so its log splits exactly into theirs; the floors and the band
        // land in the clamp term. The index's distance from it is the sticky part the reversion has not closed.
        if ($this->diagnostics?->isEnabled()) {
            $logFundamental = log($fundamentalPrice / self::RESIDENTIAL_BASELINE);
            $terms = [
                'userCost' => self::RESIDENTIAL_USER_COST_ELASTICITY * log(self::RESIDENTIAL_NEUTRAL_USER_COST / $userCost),
                'unemployment' => log(max(1e-9, $laborFactor)),
                'income' => log(max(1e-9, $incomeFactor)),
                'catastropheDamage' => log(max(0.5, $damageFactor)),
                'lendingStandards' => log(max(0.5, $creditConditionsFactor)),
                'creditSupply' => log(max(0.5, $creditSupplyFactor)),
            ];
            $terms['clamp'] = $logFundamental - array_sum($terms);
            $this->diagnostics->recordLevel('households', 'houseFundamental', $terms, $logFundamental, $dt);
            $this->diagnostics->recordValues('households', ['housePriceToFundamental' => log($state->residentialPropertyIndex / $fundamentalPrice)], $dt);
        }
    }

    /**
     * Campbell-Cochrane (1999) Habit Formation Asset Pricing Model.
     *
     * Derives macroeconomic aggregate equity risk premium as an exponential function of surplus consumption:
     * as the output gap contracts, risk aversion surges, demanding a wider required equity risk premium.
     *
     * The foreign bloc's investors price their own cycle the same way, and its equity market re-rates on the premium by
     * the Campbell-Shiller present value: a premium that decays at the cycle's persistence phi moves the log price by
     * -(premium - base) / (1 - rho * phi). The valuation's change is handed on for the reserve fund's foreign equities.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateEquityRiskPremium(MacroState $state): void
    {
        $state->equityRiskPremium = $this->habitEquityRiskPremium($state->outputGapEma);

        $state->foreignEquityRiskPremium = $this->habitEquityRiskPremium($state->foreignOutputGapEma);
        $valuation = -self::foreignValuationDuration() * ($state->foreignEquityRiskPremium - MacroEngine::BASE_EQUITY_RISK_PREMIUM);
        $state->foreignEquityValuationChange = $valuation - $state->foreignEquityValuation;
        $state->foreignEquityValuation = $valuation;
    }

    /**
     * Log price response of the foreign equity market to one unit of premium held at the foreign cycle's persistence.
     *
     * The present value of a premium shock that decays by phi a year, discounted at rho: 1 / (1 - rho * phi), where phi
     * is the mainland gap's own annual persistence, its AR(2) autocorrelation four quarters out.
     */
    public static function foreignValuationDuration(): float
    {
        $annualPersistence = MathUtility::calculateAr2Autocorrelation(self::MAINLAND_GAP_AR1, self::MAINLAND_GAP_AR2, self::MAINLAND_QUARTERS_PER_YEAR);

        return 1.0 / (1.0 - (self::CAMPBELL_SHILLER_RHO * $annualPersistence));
    }

    /** The habit premium at an output gap, floored at a positive premium and capped at the ceiling it has always had. */
    private function habitEquityRiskPremium(float $outputGapEma): float
    {
        $habitErp = MacroEngine::BASE_EQUITY_RISK_PREMIUM * exp(-self::HABIT_RISK_AVERSION_COEFF * $outputGapEma);

        return max(self::MIN_EQUITY_RISK_PREMIUM, min(0.12, $habitErp));
    }

    /**
     * Engle, Ghysels & Sohn (2013) Spline-GARCH Macro Link with SVJJ Jump-Diffusion (Bates 1996).
     *
     * Models aggregate equity implied volatility using continuous macroeconomic fundamental scaling
     * (output gap, the excess bond premium, yield curve slope, policy uncertainty) driven by a Quadratic
     * Exponential (Broadie-Kaya) variance step and asymmetric Poisson compound jumps (SVJJ). It reads the
     * premium the credit spread is built on, not the spread, which reads volatility back.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Implied equity market volatility (clamped between 8% and 80%).
     */
    public function calculateMarketVolatility(MacroState $state, float $dt): float
    {
        $currentMarketVol = $state->marketVolatility;

        $policyUncertaintyLog = log(max(1.0, $state->policyUncertaintyIndexEma) / MacroEngine::EPU_BASELINE);
        $macroDriver = (-$state->outputGap * self::MACRO_VOL_OUTPUT_GAP_SENSITIVITY)
            + ($state->excessBondPremium * self::MACRO_VOL_PREMIUM_SENSITIVITY)
            + (-min(0.0, $state->structuralSlope) * self::MACRO_VOL_SLOPE_SENSITIVITY)
            + ($policyUncertaintyLog * self::MACRO_VOL_EPU_SENSITIVITY);

        $longTermVol = min(
            self::MACRO_VOL_MAX_BASELINE,
            max(self::MACRO_VOL_MIN_BASELINE, MacroEngine::MACRO_VOL_BASE_ANCHOR * exp($macroDriver))
        );

        $currentVar = $currentMarketVol * $currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: self::SVJJ_LAMBDA,
            pUp: self::SVJJ_P_UP,
            etaUp: self::SVJJ_ETA_UP,
            etaDown: self::SVJJ_ETA_DOWN,
            muV: MacroEngine::SVJJ_MU_V,
            dt: $dt
        );

        $adjustedTheta = max(0.0001, $longTermVar - self::jumpVarianceDrag());

        $nextVar = $this->mathUtility->calculateQEVarianceStep($currentVar, $adjustedTheta, self::MACRO_VOL_KAPPA, self::MACRO_VOL_SIGMA, $dt);
        $nextVar += $jumpData['var_jump'];

        return max(self::MACRO_VOL_FLOOR, min(self::MACRO_VOL_CEILING, sqrt($nextVar)));
    }

    /**
     * Long-run variance the SVJJ jumps supply, which the diffusion's anchor must give back.
     *
     * The jumps are additive: for dv = kappa*(theta_adj - v)dt + sigma*sqrt(v)dW + dJ with arrivals at
     * lambda and mean size m, the stationary mean is theta_adj + lambda*m/kappa. So a process that is to
     * revert to MACRO_VOL_BASE_ANCHOR has to be handed an anchor lower by exactly lambda*m/kappa, and the
     * divisor is the mean-reversion speed and nothing else. A bare 3.0 stood here, leaving a third of the
     * jump variance uncompensated: the series reverted to 16.8% against a 15% anchor, and nothing measured
     * it because the MACRO_VOL_FLOOR clamp absorbed the rest.
     *
     * The mean size is the jump's own asymmetry -- a crash spikes variance harder than a rally, at the share
     * MathUtility applies when it draws one -- so the two must be read off the same constant or the
     * compensation silently desynchronizes from the thing it compensates for.
     *
     * Exposed because App\Tests\Financial\MacroVolatilityAnchorTest asserts the round trip against it.
     * A test that recomputed this from the constants would prove only that it agrees with itself.
     *
     * @return float Variance per unit time the jump process contributes in the long run.
     */
    public static function jumpVarianceDrag(): float
    {
        $meanVarianceJump = (self::SVJJ_P_UP * MacroEngine::SVJJ_MU_V * MathUtility::VARIANCE_JUMP_UPSIDE_MEAN_SHARE)
            + ((1.0 - self::SVJJ_P_UP) * MacroEngine::SVJJ_MU_V);

        return (self::SVJJ_LAMBDA * $meanVarianceJump) / self::MACRO_VOL_KAPPA;
    }

    /**
     * The systemic market factor and the market-wide jump: the one driver every equity price shares.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateSystemicMarketFactor(MacroState $state, float $dt): void
    {
        // Two-factor equity market innovation: AR(1) regime persistence plus Student's t heavy tails.
        $marketFactorPhi = exp(-$dt / MacroEngine::MARKET_FACTOR_DECAY_TAU_YEARS);
        $state->marketZLatent = $this->mathUtility->generatePersistentZ($state->marketZLatent, $marketFactorPhi);

        $regimeShock = $state->marketZLatent * $this->mathUtility->calculatePersistenceVarianceScale($marketFactorPhi);
        $tailShock = $this->mathUtility->generateStudentsT(MacroEngine::MARKET_FACTOR_TAIL_DF);

        $state->marketZ = (sqrt(MacroEngine::MARKET_FACTOR_REGIME_VARIANCE_SHARE) * $regimeShock)
            + (sqrt(1.0 - MacroEngine::MARKET_FACTOR_REGIME_VARIANCE_SHARE) * $tailShock);

        // Kou (2002) double-exponential jump diffusion for systemic equity market crashes.
        $systemicJump = $this->mathUtility->calculateSVJJJumps(
            lambda: MacroEngine::SYSTEMIC_JUMP_INTENSITY,
            pUp: MacroEngine::SYSTEMIC_JUMP_PROBABILITY_UP,
            etaUp: MacroEngine::SYSTEMIC_JUMP_ETA_UP,
            etaDown: MacroEngine::SYSTEMIC_JUMP_ETA_DOWN,
            muV: MacroEngine::SYSTEMIC_JUMP_VARIANCE_MEAN,
            dt: $dt
        );
        $state->marketJumpMultiplier = $systemicJump['price_multiplier'];
    }

    /**
     * Mundell-Fleming Open Economy (IS-LM-BOP) & Uncovered Interest Parity (Dornbusch 1976).
     *
     * The currency is an asset price: it moves to its fundamental the moment the fundamental moves, and only its
     * departures from that fundamental, the purchasing-power deviations (Rogoff 1996), decay slowly. The fundamental
     * is the parity level with two further real-world loadings, because a rate differential alone leaves the
     * currency deaf to the two events that move it most:
     *   log(FX* / 100) = UIP * (i - i*) - TOT * ImportBasket + Haven * max(0, vol - threshold) - Fiscal * spread
     * A dearer import basket is a terms-of-trade loss for a district that buys its commodities, and a volatility
     * panic bids its currency the way it already bids its bonds. The index is that fundamental times a Schwartz (1997)
     * deviation reverting to one. A higher index is a STRONGER currency throughout, which is why net exports and the
     * trade balance both read it negatively.
     *
     * The fundamental used to be the level a single slow reversion headed toward, so a rate move reached the
     * currency over a year and trade the year after, landing on demand once the rate channel had already closed the
     * gap and ringing the policy loop past zero.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateExchangeRate(MacroState $state, float $dt): void
    {
        $state->exchangeRateDeviation = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->exchangeRateDeviation,
            kappa: self::EXCHANGE_RATE_MEAN_REVERSION,
            theta: 1.0,
            sigma: MacroEngine::EXCHANGE_RATE_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );

        $state->exchangeRateIndex = max(60.0, min(160.0, self::exchangeRateFundamental($state) * $state->exchangeRateDeviation));
    }

    /**
     * The currency's fundamental level: parity on the rate differential, the terms of trade, the safe-haven bid and
     * the sovereign risk discount.
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Fundamental exchange rate index.
     */
    public static function exchangeRateFundamental(MacroState $state): float
    {
        return self::exchangeRateFundamentalAt(
            $state->policyRate,
            $state->foreignPolicyRate,
            $state->energyPriceIndexEma,
            $state->industrialMetalsIndexEma,
            $state->marketVolatilityEma,
            $state->sovereignRiskSpreadEma
        );
    }

    /**
     * The fundamental on its inputs, for the readers that hold them as values rather than as a state.
     *
     * @param float $policyRate              District policy rate.
     * @param float $foreignPolicyRate       Mainland policy rate.
     * @param float $energyPriceIndexEma     Smoothed energy price index.
     * @param float $industrialMetalsIndexEma Smoothed industrial metals index.
     * @param float $marketVolatilityEma     Smoothed equity volatility.
     * @param float $sovereignRiskSpreadEma  Smoothed sovereign risk spread.
     * @return float Fundamental exchange rate index.
     */
    public static function exchangeRateFundamentalAt(
        float $policyRate,
        float $foreignPolicyRate,
        float $energyPriceIndexEma,
        float $industrialMetalsIndexEma,
        float $marketVolatilityEma,
        float $sovereignRiskSpreadEma
    ): float {
        $rateDiff = $policyRate - $foreignPolicyRate;

        // Harrod-Balassa-Samuelson terms-of-trade import price effect on real exchange rates.
        $energyShift = ($energyPriceIndexEma / MacroEngine::ENERGY_BASELINE) - 1.0;
        $metalsShift = ($industrialMetalsIndexEma / MacroEngine::METALS_BASELINE) - 1.0;
        $importBillShift = (MacroEngine::PPI_ENERGY_WEIGHT * $energyShift) + (MacroEngine::PPI_METALS_WEIGHT * $metalsShift);
        $termsOfTradeShift = self::FX_TERMS_OF_TRADE_SENSITIVITY * $importBillShift;

        // Caballero & Krishnamurthy (2008) safe-haven currency bid under global volatility flight to safety.
        $panic = max(0.0, $marketVolatilityEma - MacroEngine::FLIGHT_TO_SAFETY_VOL_THRESHOLD);
        $safeHavenBid = self::FX_SAFE_HAVEN_SENSITIVITY * $panic;

        // Della Corte et al. (2016) sovereign credit default risk discount on currency valuation.
        $fiscalRiskDiscount = self::FX_FISCAL_RISK_SENSITIVITY * $sovereignRiskSpreadEma;

        return self::EXCHANGE_RATE_BASELINE * exp(
            (self::UIP_SENSITIVITY * $rateDiff) - $termsOfTradeShift + $safeHavenBid - $fiscalRiskDiscount
        );
    }

    /**
     * University of Michigan Sentiment & Okun Misery Index with Animal Spirits OU Diffusion (Akerlof-Shiller 2009).
     *
     * Derives rational consumer confidence from inflation, unemployment, interest rates, and energy costs,
     * compounded with an Ornstein-Uhlenbeck stochastic diffusion simulating psychological animal spirits.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateConsumerSentiment(MacroState $state, float $dt): void
    {
        $excessInflation = max(0.0, $state->inflation - MacroEngine::TARGET_INFLATION);
        $excessUnemployment = max(0.0, $state->unemploymentRate - $state->nairu);
        $miseryPenalty = ($excessInflation + $excessUnemployment) * self::SENTIMENT_MISERY_MULTIPLIER;

        $inflationMomentum = max(0.0, $state->inflation - $state->inflationEma);
        $unemploymentMomentum = max(0.0, $state->unemploymentRate - $state->unemploymentRateEma);
        $momentumPenalty = ($inflationMomentum + $unemploymentMomentum) * self::SENTIMENT_MOMENTUM_MULTIPLIER;

        $excessVolatility = max(0.0, $state->marketVolatility - MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $fearPenalty = $excessVolatility * self::SENTIMENT_VOLATILITY_MULTIPLIER;

        $neutral10yYield = $state->naturalRate + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM;
        $excessYield = max(0.0, $state->yield10y - $neutral10yYield);
        $ratePenalty = $excessYield * self::SENTIMENT_RATE_MULTIPLIER;

        $gasPanic = max(0.0, $state->energyPriceShock) * self::SENTIMENT_ENERGY_PANIC_SCALE;
        $disasterPenalty = max(0.0, $state->catastropheLossIndexEma - 1.0) * self::SENTIMENT_CATASTROPHE_MULTIPLIER;

        $gapTerm = $state->outputGap * ($state->outputGap > 0.0 ? self::SENTIMENT_EXPANSION_MULTIPLIER : self::SENTIMENT_CONTRACTION_MULTIPLIER);
        $fundamentalSentiment = MacroEngine::SENTIMENT_BASELINE - $miseryPenalty - $momentumPenalty - $fearPenalty - $ratePenalty - $gasPanic - $disasterPenalty + $gapTerm;

        if ($this->diagnostics?->isEnabled()) {
            $this->diagnostics->recordLevel('households', 'sentimentFundamental', [
                'baseline' => MacroEngine::SENTIMENT_BASELINE,
                'misery' => -$miseryPenalty,
                'momentum' => -$momentumPenalty,
                'fear' => -$fearPenalty,
                'rates' => -$ratePenalty,
                'energy' => -$gasPanic,
                'catastrophe' => -$disasterPenalty,
                'outputGap' => $gapTerm,
            ], $fundamentalSentiment, $dt);
        }

        $currentSentiment = $state->consumerSentimentIndex ?? MacroEngine::SENTIMENT_BASELINE;
        $dW = $this->mathUtility->generateStandardNormal();

        $drift = self::ANIMAL_SPIRITS_MEAN_REVERSION * ($fundamentalSentiment - $currentSentiment) * $dt;
        $diffusion = self::ANIMAL_SPIRITS_VOLATILITY * sqrt($dt) * $dW;

        $newSentiment = $currentSentiment + $drift + $diffusion;
        $state->consumerSentimentIndex = max(40.0, min(120.0, $newSentiment));
    }

    /**
     * Financial Conditions Index (FCI) Composite Model (Goldman Sachs / Chicago Fed).
     *
     * Constructs a normalized macroeconomic financial conditions index tracking wholesale credit spreads,
     * equity risk premium, real exchange rate deviations, term structure slope, and equity market volatility:
     *   FCI > 0 indicates restrictive financial conditions; FCI < 0 indicates accommodative conditions.
     * The weights are loadings on unit-variance components whose stress moves together, so the plain weighted sum is
     * the composite: a 2008-type credit event reads ~+3 sigma, a mild recession ~+1.4, a boom ~-0.6 (Chicago Fed NFCI ranges).
     * The currency is read against its own trend, since the index settles away from its nominal baseline. Like the NFCI,
     * the index is a same-period reading of its components, which are already quarterly averages, so no lag is added.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateFinancialConditionsIndex(MacroState $state): void
    {
        $creditZ = ($state->macroCreditSpreadEma - self::FCI_CREDIT_MEAN) / self::FCI_CREDIT_STD;
        $erpZ = ($state->equityRiskPremium - self::FCI_ERP_MEAN) / self::FCI_ERP_STD;
        $fxZ = MacroAggregateSubsystem::realExchangeRateGap($state->exchangeRateIndexEma, $state->exchangeRateTrend) / self::FCI_FX_STD;
        $slopeZ = - ($state->nsSlopeEma - self::FCI_SLOPE_MEAN) / self::FCI_SLOPE_STD;
        $volZ = ($state->marketVolatilityEma - self::FCI_VOL_MEAN) / self::FCI_VOL_STD;
        $sloosZ = ($state->sloosTighteningIndexEma - self::FCI_SLOOS_MEAN) / self::FCI_SLOOS_STD;

        $state->financialConditionsIndex = (self::FCI_CREDIT_SPREAD_WEIGHT * $creditZ)
            + (self::FCI_ERP_WEIGHT * $erpZ)
            + (self::FCI_EXCHANGE_RATE_WEIGHT * $fxZ)
            + (self::FCI_YIELD_SLOPE_WEIGHT * $slopeZ)
            + (self::FCI_VOLATILITY_WEIGHT * $volZ)
            + (self::FCI_SLOOS_WEIGHT * $sloosZ);
    }

    /**
     * Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow Model.
     *
     * Evaluates global investment banking advisory, private equity LBO, and IPO volume
     * driven by valuation liquidity (equity risk premium, high-yield spreads, and volatility).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCapitalMarketsDealIndex(MacroState $state, float $dt): void
    {
        $dW = $this->mathUtility->generateStandardNormal();
        $currentDealIndex = $state->dealActivityIndex > 0.0 ? $state->dealActivityIndex : MacroEngine::DEAL_ACTIVITY_BASELINE;

        $state->dealActivityIndex = $this->mathUtility->calculateCapitalMarketsDealIndexStep(
            currentDealIndex: $currentDealIndex,
            equityRiskPremium: $state->equityRiskPremium,
            hyCreditSpread: $state->highYieldCreditSpread,
            marketVolatility: $state->marketVolatility,
            dt: $dt,
            dW: $dW,
            kappa: self::DEAL_ACTIVITY_KAPPA,
            sigma: self::DEAL_ACTIVITY_SIGMA,
            policyUncertaintyIndex: $state->policyUncertaintyIndexEma
        );
    }

    /**
     * The mainland (the United States the district split from): its output gap, its core inflation, the funds rate
     * the Fed sets on them, and the global demand composite the district's commodities clear against.
     *
     * A compact model rather than a second engine: the Rudebusch & Svensson (1999) backward-looking gap and Phillips
     * curve re-estimated on US data 1985-2019, and the Fed's Clarida, Gali & Gertler (2000) smoothed rule fitted over
     * Greenspan and Bernanke. Simulated together they give the CBO gap's persistence, core PCE's level and spread and
     * the funds rate's (var/harness/mainland_fit.py). The equations are quarterly and are stepped at each quarter's
     * turn, which is also how the district sees them: a statistical release and a rate decision. The district's own
     * cycle moves the mainland gap's mean a little (two-country Mundell-Fleming, Obstfeld & Rogoff 1996), and
     * everything else -- the currency through the rate differential, the trade balance and exports through the
     * mainland gap -- reads these series.
     *
     * @param MacroState $state Current macroeconomic state; its clock has already been advanced by dt.
     * @param float      $dt    Time increment in years.
     */
    public function calculateForeignEconomy(MacroState $state, float $dt): void
    {
        $quartersTurned = self::quarterCount($state->totalTime) - self::quarterCount($state->totalTime - $dt);
        for ($quarter = 0; $quarter < $quartersTurned; $quarter++) {
            $this->stepMainlandQuarter($state);
        }

        $state->globalDemandGap = (MacroEngine::DOMESTIC_DEMAND_WEIGHT * $state->outputGapEma) + ((1.0 - MacroEngine::DOMESTIC_DEMAND_WEIGHT) * $state->foreignOutputGapEma);
    }

    /**
     * Allied defence spending, the demand the District's arms makers export into: the mainland's and its allies'
     * military spending over their GDP against its trend, a Schwartz (1997) log-OU with Merton (1976) mobilisation
     * jumps, fitted to SIPRI's constant-dollar series for NATO, Japan, South Korea, Australia, New Zealand and Israel,
     * 1951-2019. The District fields no army of its own, so its wars are its customers'.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateAlliedDefenseSpending(MacroState $state, float $dt): void
    {
        // Merton compensator: the target nets out the jumps' expected drift, lambda * (E[e^J] - 1) / kappa in log.
        $jumpDrift = self::ALLIED_MOBILISATION_PROBABILITY * (exp(self::ALLIED_MOBILISATION_MEAN + ((self::ALLIED_MOBILISATION_VOL ** 2) / 2.0)) - 1.0);
        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->alliedDefenseSpendingIndex,
            kappa: self::ALLIED_DEFENSE_MEAN_REVERSION,
            theta: MacroEngine::ALLIED_DEFENSE_BASELINE * exp(-$jumpDrift / self::ALLIED_DEFENSE_MEAN_REVERSION),
            sigma: self::ALLIED_DEFENSE_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::ALLIED_MOBILISATION_PROBABILITY,
            jumpMean: self::ALLIED_MOBILISATION_MEAN,
            jumpVol: self::ALLIED_MOBILISATION_VOL,
            dt: $dt
        );

        $state->alliedDefenseSpendingIndex = max(self::MIN_ALLIED_DEFENSE_INDEX, min(self::MAX_ALLIED_DEFENSE_INDEX, $baseProcess * $jumpData['multiplier']));
        if ($jumpData['exponent'] !== null) {
            $this->diagnostics?->recordEvent('alliedMobilisation', $jumpData['exponent']);
        }
    }

    /** Quarters completed by a simulated time; the tolerance absorbs the clock's accumulated rounding at a quarter's turn. */
    private static function quarterCount(float $totalTime): int
    {
        return (int) floor(($totalTime * self::MAINLAND_QUARTERS_PER_YEAR) + 1e-9);
    }

    /**
     * One quarter of the mainland: the gap on its two lags around the mean the district's imports set, core inflation
     * on its four lags and last quarter's gap, then the Fed's partial adjustment toward its rule on four-quarter core
     * inflation and the new gap, floored at the effective lower bound.
     */
    private function stepMainlandQuarter(MacroState $state): void
    {
        $spilloverMean = self::FOREIGN_IMPORT_SPILLOVER * $state->outputGapEma;
        $lastGap = $state->foreignOutputGap;
        $gap = ((1.0 - self::MAINLAND_GAP_AR1 - self::MAINLAND_GAP_AR2) * $spilloverMean)
            + (self::MAINLAND_GAP_AR1 * $lastGap)
            + (self::MAINLAND_GAP_AR2 * $state->foreignOutputGapLag)
            + (self::MAINLAND_GAP_SIGMA * $this->mathUtility->generateStandardNormal());

        $lags = [$state->foreignCoreInflation, $state->foreignCoreInflationLag1, $state->foreignCoreInflationLag2, $state->foreignCoreInflationLag3];
        $inflation = (1.0 - array_sum(self::MAINLAND_INFLATION_LAGS)) * MacroEngine::TARGET_INFLATION;
        foreach (self::MAINLAND_INFLATION_LAGS as $j => $coefficient) {
            $inflation += $coefficient * $lags[$j];
        }
        $inflation += (self::MAINLAND_INFLATION_GAP_SLOPE * $lastGap) + (self::MAINLAND_INFLATION_SIGMA * $this->mathUtility->generateStandardNormal());

        $state->foreignOutputGapLag = $lastGap;
        $state->foreignOutputGap = max(-self::MAX_FOREIGN_GAP, min(self::MAX_FOREIGN_GAP, $gap));
        $state->foreignCoreInflationLag3 = $state->foreignCoreInflationLag2;
        $state->foreignCoreInflationLag2 = $state->foreignCoreInflationLag1;
        $state->foreignCoreInflationLag1 = $state->foreignCoreInflation;
        $state->foreignCoreInflation = $inflation;

        // Four quarters of annualized log inflation average to the year-on-year rate the rule is fitted on.
        $yearOnYear = ($state->foreignCoreInflation + $state->foreignCoreInflationLag1 + $state->foreignCoreInflationLag2 + $state->foreignCoreInflationLag3) / 4.0;
        $ruleRate = MacroEngine::MAINLAND_NEUTRAL_RATE
            + (self::FED_RULE_INFLATION_RESPONSE * ($yearOnYear - MacroEngine::TARGET_INFLATION))
            + (self::FED_RULE_GAP_RESPONSE * $state->foreignOutputGap);
        $rate = (self::FED_RULE_SMOOTHING * $state->foreignPolicyRate)
            + ((1.0 - self::FED_RULE_SMOOTHING) * $ruleRate)
            + (self::FED_RULE_SIGMA * $this->mathUtility->generateStandardNormal());
        $state->foreignPolicyRate = max(MacroEngine::EFFECTIVE_LOWER_BOUND, min(self::MAX_FOREIGN_POLICY_RATE, $rate));
    }

    /**
     * Mundell-Fleming Open Economy Trade Balance & Net Exports to GDP.
     *
     * The structural balance, plus the net exports the output gap already carries (the mainland's demand and the real
     * exchange rate: MacroAggregateSubsystem::netExportGapAt), less the imports domestic demand draws in (IMF WEO
     * October 2015, Ch. 3: 1.4% of imports per 1% of domestic demand):
     *   NX/Y = Baseline + NetExportGap - M/Y * 1.4 * (OutputGap - NetExportGap)
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateTradeBalance(MacroState $state, float $dt): void
    {
        $domesticDemandGap = $state->outputGap - $state->netExportGap;
        $targetTradeBalance = MacroEngine::TRADE_BALANCE_BASELINE
            + $state->netExportGap
            - (MacroAggregateSubsystem::DISTRICT_IMPORT_SHARE * MacroAggregateSubsystem::IMPORT_DEMAND_ELASTICITY * $domesticDemandGap);

        $targetTradeBalance = max(self::MIN_TRADE_BALANCE, min(self::MAX_TRADE_BALANCE, $targetTradeBalance));

        $state->tradeBalanceToGdp += 2.0 * ($targetTradeBalance - $state->tradeBalanceToGdp) * $dt;
        $state->tradeBalanceToGdp = max(self::MIN_TRADE_BALANCE, min(self::MAX_TRADE_BALANCE, $state->tradeBalanceToGdp));
    }

    /**
     * Jorgenson (1963) user cost of owning: mortgage rate plus depreciation and tax, less EXPECTED house-price
     * inflation. Realized inflation is not an expectation, so a commodity spike does not make owning cheaper.
     * The 30Y fixed mortgage is priced off the 10Y: prepayment shortens its effective duration to ~7 years.
     */
    private function housingUserCost(MacroState $state, float $expectedInflation): float
    {
        $mortgageRate = $state->yield10yEma + MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD;

        return max(0.015, $mortgageRate + self::RESIDENTIAL_DEPRECIATION_TAX_RATE - $expectedInflation);
    }

    /**
     * Poterba (1984) / Topel-Rosen (1988) Tobin's Q Housing Investment Dynamics.
     *
     * Computes residential construction volume (Housing Starts) driven by the ratio of asset market home prices
     * to physical replacement costs, discounted by mortgage user costs and bank lending standards.
     *
     * @param MacroState $state             Current macroeconomic state.
     * @param float      $expectedInflation Expected inflation, as for calculateResidentialPropertyIndex().
     * @param float      $dt                Time increment in years.
     */
    public function calculateHousingStarts(MacroState $state, float $expectedInflation, float $dt): void
    {
        $residentialPriceRatio = $state->residentialPropertyIndex / self::RESIDENTIAL_BASELINE;
        $metalsCostRatio = $state->industrialMetalsIndex / MacroEngine::METALS_BASELINE;
        // Building labour priced as a LEVEL in the same real terms as the home price index: the real wage against its
        // trend-productivity path. Read as 1 + wage growth, it charged a standing 1.75% premium in a calm economy and
        // saw only the acceleration of pay, never a real wage that stayed high.
        $laborCostRatio = exp($state->realWageGap);
        $replacementCostRatio = (0.50 * $metalsCostRatio) + (0.50 * $laborCostRatio);

        $userCost = $this->housingUserCost($state, $expectedInflation);

        $dW = $this->mathUtility->generateStandardNormal();

        $params = [
            'baseline' => MacroEngine::HOUSING_STARTS_BASELINE,
            'qSens' => self::HOUSING_STARTS_Q_SENSITIVITY,
            'costSens' => self::HOUSING_STARTS_USER_COST_SENSITIVITY,
            'sloosSens' => self::HOUSING_STARTS_SLOOS_SENSITIVITY,
            'creditGapSens' => self::HOUSING_STARTS_CREDIT_GAP_SENSITIVITY,
            'kappa' => self::HOUSING_STARTS_KAPPA,
            'sigma' => self::HOUSING_STARTS_SIGMA,
            'min' => self::MIN_HOUSING_STARTS,
            'max' => self::MAX_HOUSING_STARTS,
        ];

        $state->housingStartsIndex = $this->mathUtility->calculateTobinsQHousingStarts(
            currentStarts: $state->housingStartsIndex,
            residentialPriceRatio: $residentialPriceRatio,
            replacementCostRatio: $replacementCostRatio,
            userCost: $userCost,
            neutralUserCost: self::RESIDENTIAL_NEUTRAL_USER_COST,
            sloosTightening: $state->sloosTighteningIndexEma,
            dt: $dt,
            dW: $dW,
            params: $params,
            creditGap: $state->creditToGdpGapEma
        );
    }
}
