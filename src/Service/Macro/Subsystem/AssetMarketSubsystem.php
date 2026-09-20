<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
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
    public const HABIT_RISK_AVERSION_COEFF = 25.0;
    /** Structural floor: equities must logically yield more than risk-free T-bills. */
    public const MIN_EQUITY_RISK_PREMIUM = 0.02;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /** Sensitivity of exponential baseline volatility to output gap fluctuations (countercyclical). */
    public const MACRO_VOL_OUTPUT_GAP_SENSITIVITY = 10.0;
    /** Sensitivity of exponential baseline volatility to corporate credit spread deviations from baseline. */
    public const MACRO_VOL_CREDIT_SENSITIVITY     = 10.0;
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

    // --- Foreign Bloc (two-country Mundell-Fleming, Obstfeld & Rogoff 1996) ---
    /** Mean reversion of the foreign output gap (half-life ~2 years, a cycle of the same length as the district's). */
    public const FOREIGN_GAP_REVERSION = 0.35;
    /** Annual diffusion of the foreign gap: a stationary spread of ~1.4%, the domestic gap's own. */
    public const FOREIGN_GAP_SIGMA = 0.012;
    /** Share of the district's cycle that reaches the foreign bloc's demand: the district's imports are a small part of the world's exports. */
    public const FOREIGN_IMPORT_SPILLOVER = 0.15;
    /** The foreign central bank's Taylor (1993) output coefficient; its inflation is taken as anchored, so the gap is all it reacts to. */
    public const FOREIGN_TAYLOR_GAP_COEFF = 0.50;
    /** Time constant (years) over which the foreign policy rate reaches its rule. */
    public const FOREIGN_POLICY_ADJUSTMENT_YEARS = 0.50;
    /** Bounds on the foreign gap. */
    public const MAX_FOREIGN_GAP = 0.10;
    /** Bounds on the foreign policy rate. */
    public const MAX_FOREIGN_POLICY_RATE = 0.10;

    // --- MUNDELL-FLEMING OPEN ECONOMY (IS-LM-BOP) ---
    /** UIP sensitivity: exchange rate response to domestic-foreign interest rate differential. */
    public const UIP_SENSITIVITY = 3.0;
    /** Mean-reversion speed of exchange rate toward purchasing power parity equilibrium. */
    public const EXCHANGE_RATE_MEAN_REVERSION = 0.60;
    /** Stochastic volatility of exchange rate fluctuations (FX market noise). */
    public const EXCHANGE_RATE_VOLATILITY = 0.08;
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
    /** Fundamental price per unit of net lending tightening (Duca, Muellbauer & Murphy 2011; Favara & Imbs 2015): the ~80% tightening of a crisis takes a fifth off, the post-crisis decline in Reinhart & Rogoff. */
    public const RESIDENTIAL_CREDIT_STANDARDS_ELASTICITY = 0.25;
    /** Stochastic volatility of residential home prices (stationary noise ~5% so the user-cost channel dominates). */
    public const RESIDENTIAL_VOLATILITY = 0.03;
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
    /** Historical standard deviation for currency index deviations in FCI normalization. */
    public const FCI_FX_STD = 10.0;
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
    /** OU smoothing speed of FCI toward fundamental composite value. */
    public const FCI_MEAN_REVERSION = 2.0;

    // --- Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow ---
    /** Mean-reversion speed (kappa) of deal activity toward fundamental valuation capacity. */
    public const DEAL_ACTIVITY_KAPPA = 1.60;
    /** Stochastic volatility of deal activity volume. */
    public const DEAL_ACTIVITY_SIGMA = 0.15;

    // --- Mundell-Fleming Trade Balance & Net Exports ---
    /** Marshall-Lerner elasticity of trade balance to currency exchange rate index deviations from neutral. */
    public const TRADE_BALANCE_FX_ELASTICITY = 0.040;
    /** Absorption elasticity of trade balance to cyclical domestic GDP output gap demand. */
    public const TRADE_BALANCE_GAP_ELASTICITY = 0.080;
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
        private readonly MathUtility $mathUtility
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

        $affordabilityFactor = (self::RESIDENTIAL_NEUTRAL_USER_COST / $userCost) * $demandMultiplier;
        // Hallegatte et al. (2007) physical housing stock destruction and post-disaster replacement.
        $damageFactor = 1.0 - (self::CATASTROPHE_PROPERTY_DAMAGE_SHARE * max(0.0, $state->catastropheLossIndexEma - 1.0));
        $creditConditionsFactor = 1.0 - (self::RESIDENTIAL_CREDIT_STANDARDS_ELASTICITY * $state->sloosTighteningIndexEma);
        $fundamentalPrice = MacroEngine::RESIDENTIAL_BASELINE * max(0.30, min(2.50, $affordabilityFactor * max(0.5, $damageFactor) * max(0.5, $creditConditionsFactor)));

        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->residentialPropertyIndex,
            kappa: self::RESIDENTIAL_MEAN_REVERSION,
            theta: $fundamentalPrice,
            sigma: self::RESIDENTIAL_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->residentialPropertyIndex = max(self::RESIDENTIAL_MIN_INDEX, min(self::RESIDENTIAL_MAX_INDEX, $newIndex));
    }

    /**
     * Campbell-Cochrane (1999) Habit Formation Asset Pricing Model.
     *
     * Derives macroeconomic aggregate equity risk premium as an exponential function of surplus consumption:
     * as the output gap contracts, risk aversion surges, demanding a wider required equity risk premium.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateEquityRiskPremium(MacroState $state): void
    {
        $habitErp = MacroEngine::BASE_EQUITY_RISK_PREMIUM * exp(-self::HABIT_RISK_AVERSION_COEFF * $state->outputGapEma);
        $state->equityRiskPremium = max(self::MIN_EQUITY_RISK_PREMIUM, min(0.12, $habitErp));
    }

    /**
     * Engle, Ghysels & Sohn (2013) Spline-GARCH Macro Link with SVJJ Jump-Diffusion (Bates 1996).
     *
     * Models aggregate equity implied volatility using continuous macroeconomic fundamental scaling
     * (output gap, credit spreads, yield curve slope) driven by a Quadratic Exponential (Broadie-Kaya)
     * variance step and asymmetric Poisson compound jumps (SVJJ).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Implied equity market volatility (clamped between 8% and 80%).
     */
    public function calculateMarketVolatility(MacroState $state, float $dt): float
    {
        $currentMarketVol = $state->marketVolatility;

        $spreadDeviation = max(0.0, $state->macroCreditSpread - MacroEngine::BASE_CREDIT_SPREAD);
        $policyUncertaintyLog = log(max(1.0, $state->policyUncertaintyIndexEma) / MacroEngine::EPU_BASELINE);
        $macroDriver = (-$state->outputGap * self::MACRO_VOL_OUTPUT_GAP_SENSITIVITY)
            + ($spreadDeviation * self::MACRO_VOL_CREDIT_SENSITIVITY)
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
     * Models currency exchange rate index against global trading partners based on domestic-to-foreign
     * interest rate differentials (UIP equilibrium target) with Schwartz (1997) commodity mean reversion.
     *
     * The parity target carries two further real-world loadings, because a rate differential alone leaves the
     * currency deaf to the two events that move it most:
     *   log(FX / FX*) = UIP * (i - i*) - TOT * ImportBasket + Haven * max(0, vol - threshold)
     * A dearer import basket is a terms-of-trade loss for a district that buys its commodities, and a
     * volatility panic bids its currency the way it already bids its bonds. A higher index is a STRONGER
     * currency throughout, which is why net exports and the trade balance both read it negatively.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateExchangeRate(MacroState $state, float $dt): void
    {
        $rateDiff = $state->policyRate - $state->foreignPolicyRate;

        // Harrod-Balassa-Samuelson terms-of-trade import price effect on real exchange rates.
        $energyShift = ($state->energyPriceIndexEma / MacroEngine::ENERGY_BASELINE) - 1.0;
        $metalsShift = ($state->industrialMetalsIndexEma / MacroEngine::METALS_BASELINE) - 1.0;
        $importBillShift = (MacroEngine::PPI_ENERGY_WEIGHT * $energyShift) + (MacroEngine::PPI_METALS_WEIGHT * $metalsShift);
        $termsOfTradeShift = self::FX_TERMS_OF_TRADE_SENSITIVITY * $importBillShift;

        // Caballero & Krishnamurthy (2008) safe-haven currency bid under global volatility flight to safety.
        $panic = max(0.0, $state->marketVolatilityEma - MacroEngine::FLIGHT_TO_SAFETY_VOL_THRESHOLD);
        $safeHavenBid = self::FX_SAFE_HAVEN_SENSITIVITY * $panic;

        // Della Corte et al. (2016) sovereign credit default risk discount on currency valuation.
        $fiscalRiskDiscount = self::FX_FISCAL_RISK_SENSITIVITY * $state->sovereignRiskSpreadEma;

        $targetFx = MacroEngine::EXCHANGE_RATE_BASELINE * exp(
            (self::UIP_SENSITIVITY * $rateDiff) - $termsOfTradeShift + $safeHavenBid - $fiscalRiskDiscount
        );

        $dW = $this->mathUtility->generateStandardNormal();
        $newFx = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->exchangeRateIndex,
            kappa: self::EXCHANGE_RATE_MEAN_REVERSION,
            theta: $targetFx,
            sigma: self::EXCHANGE_RATE_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->exchangeRateIndex = max(60.0, min(160.0, $newFx));
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

        $fundamentalSentiment = MacroEngine::SENTIMENT_BASELINE - $miseryPenalty - $momentumPenalty - $fearPenalty - $ratePenalty - $gasPanic - $disasterPenalty;
        if ($state->outputGap > 0.0) {
            $fundamentalSentiment += ($state->outputGap * self::SENTIMENT_EXPANSION_MULTIPLIER);
        } else {
            $fundamentalSentiment += ($state->outputGap * self::SENTIMENT_CONTRACTION_MULTIPLIER);
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
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateFinancialConditionsIndex(MacroState $state, float $dt): void
    {
        $creditZ = ($state->macroCreditSpreadEma - self::FCI_CREDIT_MEAN) / self::FCI_CREDIT_STD;
        $erpZ = ($state->equityRiskPremium - self::FCI_ERP_MEAN) / self::FCI_ERP_STD;
        $fxZ = ($state->exchangeRateIndexEma - MacroEngine::EXCHANGE_RATE_BASELINE) / self::FCI_FX_STD;
        $slopeZ = - ($state->nsSlopeEma - self::FCI_SLOPE_MEAN) / self::FCI_SLOPE_STD;
        $volZ = ($state->marketVolatilityEma - self::FCI_VOL_MEAN) / self::FCI_VOL_STD;
        $sloosZ = ($state->sloosTighteningIndexEma - self::FCI_SLOOS_MEAN) / self::FCI_SLOOS_STD;

        $fundamentalFci = (self::FCI_CREDIT_SPREAD_WEIGHT * $creditZ)
            + (self::FCI_ERP_WEIGHT * $erpZ)
            + (self::FCI_EXCHANGE_RATE_WEIGHT * $fxZ)
            + (self::FCI_YIELD_SLOPE_WEIGHT * $slopeZ)
            + (self::FCI_VOLATILITY_WEIGHT * $volZ)
            + (self::FCI_SLOOS_WEIGHT * $sloosZ);

        $state->financialConditionsIndex += self::FCI_MEAN_REVERSION
            * ($fundamentalFci - $state->financialConditionsIndex) * $dt;
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
     * The foreign bloc: an output gap of its own, the policy rate its Taylor rule sets on it, and the global
     * demand composite the district's commodities actually clear against.
     *
     * Two-country Mundell-Fleming (Obstfeld & Rogoff 1996 for the structure): the foreign gap is a mean-
     * reverting disturbance whose mean the district's own cycle shifts a little (its imports are the bloc's
     * exports), the foreign central bank follows a Taylor rule on that gap with anchored inflation, and world
     * demand for metals, freight and fuel is the weighted pair. Everything else -- the currency through the
     * rate differential, the trade balance through foreign absorption, exports through the IS curve -- reads
     * these three series.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateForeignEconomy(MacroState $state, float $dt): void
    {
        $meanGap = self::FOREIGN_IMPORT_SPILLOVER * $state->outputGapEma;
        $innovation = self::FOREIGN_GAP_SIGMA * sqrt($dt) * $this->mathUtility->generateStandardNormal();
        $state->foreignOutputGap += (self::FOREIGN_GAP_REVERSION * ($meanGap - $state->foreignOutputGap) * $dt) + $innovation;
        $state->foreignOutputGap = max(-self::MAX_FOREIGN_GAP, min(self::MAX_FOREIGN_GAP, $state->foreignOutputGap));

        $ruleRate = MacroEngine::GLOBAL_BASELINE_RATE + (self::FOREIGN_TAYLOR_GAP_COEFF * $state->foreignOutputGapEma);
        $state->foreignPolicyRate = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->foreignPolicyRate,
            targetValue: max(0.0, min(self::MAX_FOREIGN_POLICY_RATE, $ruleRate)),
            dt: $dt,
            lagTimeConstant: self::FOREIGN_POLICY_ADJUSTMENT_YEARS
        );

        $state->globalDemandGap = (MacroEngine::DOMESTIC_DEMAND_WEIGHT * $state->outputGapEma) + ((1.0 - MacroEngine::DOMESTIC_DEMAND_WEIGHT) * $state->foreignOutputGapEma);
    }

    /**
     * Mundell-Fleming Open Economy Trade Balance & Net Exports to GDP.
     *
     * Models net export balance as a share of GDP (NX/Y) based on Marshall-Lerner real exchange rate
     * deviations and domestic cyclical demand absorption:
     *   NX/Y = Baseline - beta_FX * (FX/100 - 1) - beta_Y * OutputGap
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateTradeBalance(MacroState $state, float $dt): void
    {
        $fxDeviation = ($state->exchangeRateIndex / MacroEngine::EXCHANGE_RATE_BASELINE) - 1.0;
        $cyclicalAbsorption = $state->outputGap;

        // Mundell-Fleming foreign absorption spillover to domestic export demand.
        $targetTradeBalance = MacroEngine::TRADE_BALANCE_BASELINE
            - (self::TRADE_BALANCE_FX_ELASTICITY * $fxDeviation)
            - (self::TRADE_BALANCE_GAP_ELASTICITY * $cyclicalAbsorption)
            + (self::TRADE_BALANCE_GAP_ELASTICITY * $state->foreignOutputGap);

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
        $residentialPriceRatio = $state->residentialPropertyIndex / MacroEngine::RESIDENTIAL_BASELINE;
        $metalsCostRatio = $state->industrialMetalsIndex / MacroEngine::METALS_BASELINE;
        $laborCostRatio = 1.0 + $state->wageGrowth;
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
