<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Handles corporate and retail credit spreads, interbank liquidity (TED spread),
 * government spending appropriations, and countercyclical corporate tax policy.
 */
class CreditFiscalSubsystem
{
    // --- INTERBANK LIQUIDITY SPREAD (CIR PROCESS & JUMPS) ---
    /** Cap on the interbank spread (500 bps): the TED spread's all-time high was 457 bps on 10 Oct 2008. */
    public const INTERBANK_MAX_SPREAD = 0.05;
    /** Interbank spread mean per unit of positive excess bond premium: 0.32 (se 0.11), TED on the GZ premium 1986-2022, so 2008's 340 bps premium pulls TED ~110 bps above base. */
    public const INTERBANK_PREMIUM_COUPLING = 0.32;
    /** Mean log-size of a panic jump: median 2.7x (1.65x to 4.5x at one sigma), so a 2008-scale 5x freeze is the tail, not the norm. */
    public const INTERBANK_JUMP_MEAN = 1.00;
    /** Sigma of the jump log-size; at 0.50 the two-sigma low is exactly 1.0x, so a panic jump never shrinks the spread. */
    public const INTERBANK_JUMP_VOL = 0.50;

    // --- Corporate Tax Revenue (BEA NIPA) ---
    /** Corporate profits before tax over GDP, 1985-2019 mean (BEA NIPA via FRED, A053RC1Q027SBEA over GDP: 9.67%): the base a change in the corporate rate is levied on, so a point of rate is a tenth of a point of GDP in revenue. */
    public const CORPORATE_PROFITS_TO_GDP = 0.0967;

    // --- Carbon Revenue (EIA; BEA) ---
    /** Power-sector CO2 per dollar of GDP, in tonnes: 1,425 million tonnes in 2023 (EIA) over $27.36 trillion of GDP (BEA): the base a carbon price on power is levied on, held at today's emissions. */
    public const POWER_SECTOR_CO2_PER_GDP_DOLLAR = 1425.0e6 / 27.36e12;

    // --- Sovereign Debt Dynamics (Greenwood-Vayanos 2014) ---
    /** Structural primary fiscal deficit as a fraction of GDP with no sovereign fund; with one, the structural deficit is the fund's draw, spent. */
    public const SOVEREIGN_STRUCTURAL_DEFICIT = 0.020;
    /** Bohn (1998, 2008) fiscal reaction: primary surplus response per unit of debt above the neutral threshold (~0.10, the upper end of advanced-economy estimates), which stabilizes debt near 90% against a 2% structural deficit, and just above the 70% threshold once a sovereign fund's draw pays that deficit. */
    public const BOHN_FISCAL_REACTION_SENSITIVITY = 0.10;
    /** Runaway guard on gross debt, about Japan's postwar peak (~2.6x GDP, IMF WEO 2020); the Bohn reaction holds debt far below it. */
    public const SOVEREIGN_DEBT_CEILING = 2.50;

    // --- Sovereign Risk Premium (Laubach 2009) ---
    /** Long yield per unit of debt-to-GDP above the risk threshold: 3-4 bps per percentage point (Laubach 2009; Engen & Hubbard 2004), so 0.035 per unit. Laubach's deficit coefficient is for PROJECTED structural deficits; the engine's primary deficit is cyclical, so it is published but not priced. */
    public const LAUBACH_DEBT_YIELD_SENSITIVITY = 0.035;
    /** Time constant (years) over which the market reprices the fiscal position: a projection revises over budget rounds, not ticks. */
    public const SOVEREIGN_RISK_REPRICING_YEARS = 0.5;
    /** Cap on the sovereign risk spread (600 bps): the level at which an advanced sovereign lost market access in 2011. */
    public const MAX_SOVEREIGN_RISK_SPREAD = 0.06;
    /** Share of the sovereign spread that passes into the corporate IG base (Durbin & Ng 2005 sovereign ceiling; Almeida et al. 2017 find about half). */
    public const SOVEREIGN_CEILING_PASSTHROUGH = 0.50;

    // --- Barro Tax-Smoothing & Automatic Fiscal Stabilizers (Barro 1979) ---
    /** Tax-rate response to the output gap, fitted with the speed it adjusts at (MacroEngine::FISCAL_ADJUSTMENT_SPEED) by indirect inference on the US primary deficit's Auerbach (2002) reaction, 1960-2019: automatic -0.29, discretionary -0.038 per pp of lagged gap a quarter, persistence -0.047; the engine's regression lands within half a standard error of all three. */
    public const FISCAL_STABILIZER_SENSITIVITY = 0.35;
    /** Mean lag, in years, of the tax a trailing year's earnings carry behind the tax charged: half the year, as a first-order lag (the convention of MacroAggregateSubsystem::DEMAND_TRANSMISSION_LAGS). */
    public const TRAILING_EARNINGS_MEAN_LAG_YEARS = 0.5;
    /** Statutory corporate tax rate floor during deep economic recessions. */
    public const MIN_CORPORATE_TAX_RATE = 0.12;
    /** Statutory corporate tax rate ceiling during overheating economic booms. */
    public const MAX_CORPORATE_TAX_RATE = 0.30;

    // --- GOVERNMENT SPENDING & FISCAL APPROPRIATIONS ---
    /** Reversion speed of log real civilian purchases / potential (half-life 2.3y): US federal non-defence plus state and local, 1985-2019 AR(1) on a trend, corrected for NIPA quarterly averaging (Working 1960); the District fields no army, so its purchases are civilian (var/harness/gov_fit.py). */
    public const GOVT_SPENDING_MEAN_REVERSION = 0.298;
    /** Diffusion of log civilian purchases / potential, the same fit; jumps are not significant (LR 2.1), so the process is Gaussian. */
    public const GOVT_SPENDING_VOLATILITY = 0.0148;

    // --- VASICEK ASRF RETAIL DEFAULT RATE (fitted on US household delinquency 1991-2019, var/harness/retail_default_fit) ---
    /** Basel II/III consumer asset correlation factor for retail exposures. */
    public const RETAIL_ASRF_RHO = 0.12;
    /** Household credit Z at NAIRU, base spreads and trend house prices: -0.06 (se 0.08), the intercept of the fit below; the US channels average -0.17 on top, which with it puts mean delinquency at the 2.5% the PD is struck at. */
    public const RETAIL_CREDIT_INTERCEPT = -0.06;
    /** Household credit Z per unit of unemployment over NAIRU: 18.3 (se 2.9), the Vasicek-inverted 0.7 mortgage / 0.3 consumer 30-day delinquency rate (FRED DRSFRMACBS, DRCLACBS) on the engine's own channels; R2 0.84. */
    public const RETAIL_UNEMPLOYMENT_SENSITIVITY = 18.3;
    /** Household credit Z per unit of excess borrowing spread (IG over its base plus TED over its base): 17.7 (se 4.6), the same fit. */
    public const RETAIL_CREDIT_SPREAD_SENSITIVITY = 17.7;
    /** Household credit Z per unit of log house price over its 5-year trend: 2.65 (se 0.61), the same fit; negative equity is the mortgage default trigger (Foote, Gerardi & Willen 2008). Loss severity reads house prices separately, through the lenders' collateral LGD. */
    public const RETAIL_HOUSE_PRICE_SENSITIVITY = 2.65;
    /** Mean reversion (per year) of the household credit factor the channels leave unexplained: 0.37 (se 0.28), the fit's residual AR(1) corrected for the rate's quarterly EMA. */
    public const RETAIL_CREDIT_FACTOR_KAPPA = 0.37;
    /** Stationary standard deviation of that factor in Z units: 0.23 (se 0.085), the same residual. */
    public const RETAIL_CREDIT_FACTOR_SD = 0.23;

    // --- INTERBANK LIQUIDITY SPREAD (CIR PROCESS & JUMPS) ---
    /** Floor on the interbank spread (1 bp) keeping the CIR process strictly positive. */
    public const INTERBANK_MIN_SPREAD = 0.0001;
    /** Poisson intensity of severe interbank credit freeze/panic events. */
    public const INTERBANK_JUMP_PROBABILITY = 0.05;

    // --- Corporate Default Dynamics (Moody's all-rated, Vasicek single factor) ---
    /** Asset correlation of the all-rated default rate: ~0.1 puts the Vasicek median at the post-1983 record's ~1.2% against its 1.6% mean (0.2 gives 0.8%); fits over 1920-2008 reach 0.2 on the 1930s, a tail this macro index already generates itself. */
    public const CORPORATE_DEFAULT_RHO = 0.10;
    /** Sensitivity of corporate credit Z-score to macroeconomic output gap. */
    public const CORPORATE_DEFAULT_GAP_SENSITIVITY = 25.0;
    /** Corporate credit Z per unit of excess HY spread (~5): a 2,000 bps blowout with a -4% gap and 80% SLOOS, held for a year, gives ~8% (1933's all-rated rate); 2009 averaged 5.4%. */
    public const CORPORATE_DEFAULT_SPREAD_SENSITIVITY = 5.0;
    /** Corporate credit Z per unit of SLOOS net tightening (~1): a 35% credit crunch adds ~0.35 to the systemic factor. */
    public const CORPORATE_DEFAULT_SLOOS_SENSITIVITY = 1.0;

    // --- Economic Policy Uncertainty (Baker, Bloom & Davis 2016) ---
    /** Log lift of the index per unit of the election pulse, the full lift on the eve of a vote (Julio & Yook 2012 locate the investment cut in the election year; the BBD index rises a quarter or so into a presidential vote). */
    public const EPU_ELECTION_LIFT = 0.25;
    /** Log lift per unit of recession probability above its unconditional level: the index roughly doubled through 2008-2011 as policy responses were debated. */
    public const EPU_STRESS_LIFT = 1.0;
    /** Unconditional recession probability the stress lift measures from (the probit intercept's ~15%). */
    public const EPU_STRESS_PROBABILITY_FLOOR = 0.15;
    /** Mean reversion of the log index (half-life ~5 months, the ~0.88 monthly autocorrelation of the BBD series). */
    public const EPU_MEAN_REVERSION = 1.5;
    /** Annual log volatility, giving a stationary log spread of ~0.32 around the level the calendar and the cycle set. */
    public const EPU_VOLATILITY = 0.55;
    /** Arrivals per year of unscheduled policy shocks (debt-ceiling standoffs, referendums, trade rulings). */
    public const EPU_JUMP_PROBABILITY = 0.50;
    /** Mean log size of an unscheduled policy shock. */
    public const EPU_JUMP_MEAN = 0.20;
    /** Log volatility of an unscheduled policy shock. */
    public const EPU_JUMP_VOL = 0.10;
    /** Floor of the index: even a quiet mid-term carries a third of average uncertainty. */
    public const MIN_EPU = 30.0;
    /** Ceiling of the index: the BBD US series peaked near four times its mean in 2020. */
    public const MAX_EPU = 400.0;

    // --- Administered Healthcare Prices (CMS market-basket update) ---
    /** Reimbursement update cut per unit of sovereign debt above the risk threshold (90%): the sequester that a fiscal correction imposes on administered prices. */
    public const REIMBURSEMENT_FISCAL_CUT_SENSITIVITY = 0.02;
    /** Floor on the annual update: administered prices are held, not cut, in a deflationary year. */
    public const REIMBURSEMENT_MIN_UPDATE = 0.0;

    // --- Household Credit Cycle (Mian & Sufi 2018; BIS DSR; Basel III CCyB) ---
    /** Share of household debt that is mortgage debt (~70% in the US), priced off the mortgage rate; the rest is consumer credit priced off the policy rate. */
    public const HOUSEHOLD_MORTGAGE_DEBT_SHARE = 0.70;
    /** Spread of consumer credit (cards, auto, personal) over the policy rate. */
    public const CONSUMER_CREDIT_SPREAD = 0.08;
    /** Average remaining maturity of the household debt stock (years) in the BIS debt-service ratio annuity (Drehmann, Illes, Juselius & Santos 2015 use 18). */
    public const DSR_AVERAGE_MATURITY_YEARS = 18.0;
    /** Time constant (years) of the ratio's long-run average: Drehmann & Juselius (2012, 2014) read the DSR as its deviation from a 15-year moving average. */
    public const DSR_TREND_HORIZON_YEARS = 15.0;
    /** Annual credit growth per unit of real house-price lift over its 5-year trend (Mian & Sufi 2011 home-equity channel): 0.337 (se 0.046), US household debt to income 1976-2019 with the lift built as the engine builds it. */
    public const CREDIT_GROWTH_HOUSE_PRICE = 0.337;
    /** Annual reversion of leverage toward its baseline per unit of relative excess: 0.075 (se 0.016), the same regression, whose implied baseline (0.97) is the engine's own. */
    public const CREDIT_MEAN_REVERSION = 0.075;
    /** Annual log volatility of the leverage ratio: the regression's residual, 0.99% a quarter and serially uncorrelated. */
    public const CREDIT_GROWTH_SIGMA = 0.020;
    /** Extra annual credit contraction per unit of debt-service gap above the warning line (Mian & Sufi 2018): 1.75 (se 0.44), the same regression, so two points over the line repay 3.5% of the stock a year. */
    public const DELEVERAGING_SPEED = 1.75;
    /** Horizon (years) of the new-borrowing flow spending answers: the year's borrowing Drehmann, Juselius & Korinek (2018) measure. */
    public const HOUSEHOLD_NEW_BORROWING_HORIZON_YEARS = 1.0;
    /** Bounds on household debt to income. */
    public const MIN_HOUSEHOLD_DEBT_TO_INCOME = 0.40;
    /** Upper bound on household debt to income. */
    public const MAX_HOUSEHOLD_DEBT_TO_INCOME = 2.50;
    /** Steady-state Kalman level gain of the Basel III one-sided HP filter (lambda 400,000 on quarterly data) in its state-space form; a 30-year EMA stood in for it and, on the engine's own paths, correlated 0.17 with it. */
    public const CREDIT_GAP_HP_LEVEL_GAIN = 0.054686;
    /** Steady-state Kalman slope gain of the same filter. */
    public const CREDIT_GAP_HP_SLOPE_GAIN = 0.0015373;
    /** Household debt-to-income gap at which the countercyclical buffer starts to build: Basel III's 2 points of GDP over disposable income / GDP (0.727). */
    public const CCYB_GAP_FLOOR = 0.0275;
    /** Household debt-to-income gap at which the buffer reaches its maximum: Basel III's 10 points of GDP in the same units. */
    public const CCYB_GAP_CEILING = 0.1376;
    /** Maximum countercyclical capital buffer (Basel III: 2.5% of risk-weighted assets). */
    public const MAX_CCYB = 0.025;
    /** Phase-in time (years) of a buffer decision: Basel gives banks twelve months. */
    public const CCYB_PHASE_IN_YEARS = 1.0;

    // --- Credit Crisis Hazard (Schularick & Taylor 2012; Jorda, Schularick & Taylor 2013) ---
    /** Logit intercept: -3.77 (se 0.25), crisis starts on the lagged Basel (one-sided HP) household credit gap, JST Macrohistory R6, 17 economies 1950-2020 outside war years and 5-year post-crisis windows; 2.2% a year at trend. */
    public const CREDIT_CRISIS_LOGIT_INTERCEPT = -3.77;
    /** Logit per unit of the household debt-to-INCOME gap: 11.44, the same fit's 15.74 (se 4.90) per point of household credit to GDP times disposable income / GDP (0.727, US 1976-2019). A 5-point gap lifts the hazard to ~3.9% a year, 10 points to ~6.7%. */
    public const CREDIT_CRISIS_LOGIT_GAP = 11.44;
    /** Years after a crisis during which the hazard is off: the bust resets the credit stock, and the panel's crises are decades apart. */
    public const CREDIT_CRISIS_REFRACTORY_YEARS = 5.0;
    /** Demand drag (pp/yr) a crisis books on impact with no boom behind it: a financial recession runs ~1pp a year deeper than a normal one (JST 2013), as the 1984 S&L crisis passed with no recession at all. */
    public const CREDIT_CRISIS_DRAG_BASE = 0.010;
    /** Extra drag per unit of credit gap at the crisis ("credit bites back", JST 2013), fitted with the decay below so the US boom at the end of 2007 (+10pp of debt to income on the engine's Basel filter) costs the gap what 2008 cost the CBO gap beyond a normal recession, -3.2 -3.2 -3.6 -3.4 -2.5 -2.0 -1.2 -0.9 over years 1-8 (var/harness/crisis_irf.sh: -3.7 -4.8 -3.5 -2.5 -1.9 -1.7 -1.4 -1.1); a typical +3pp boom costs half that. At 0.15 a crisis cost almost nothing beyond a normal recession (JRST 2021 replication, var/harness/jrst_an.py). */
    public const CREDIT_CRISIS_DRAG_PER_GAP = 0.765;
    /** Decay of the crisis drag (eight-year time constant, 55% still running at year five), the same fit: the CBO gap was still below -2% five years after Lehman. Depth comes from OUTLASTING a policy loop that responds in four to five quarters; a drag spent inside two years is offset as it arrives. */
    public const CREDIT_CRISIS_DRAG_DECAY = 0.12;
    /** Excess bond premium a crisis books on the day (216 bps): 2008's jump, EBP 1.24% in August to 3.40% in October, the one systemic crisis in the GZ record. The boom's size scales the drag (JST 2013), not this: nothing in the record says how the premium scales past 2008. */
    public const EBP_CRISIS_JUMP = 0.0216;

    // --- Light-Touch Financial Centre (Jorda, Richter, Schularick & Taylor 2021) ---
    /** Crisis logit shift for the District's wholesale-funded banks: loans at 108% of deposits (UK and Swiss banks 1995-2007, JST R6) against the US 87%, at JRST's post-war probit marginal effect of 0.05pp a year per point (se 0.01); the hazard at trend rises from 2.2% to 3.6%. */
    public const DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT = 0.485;
    /** The District's bank equity over total assets at its opening capital requirement: UK and Swiss banks 1995-2007 (JST R6). */
    public const DISTRICT_BANK_CAPITAL_RATIO = 0.049;
    /** US bank equity over total assets 1995-2007 (JST R6), the capital the crisis drag's panel fit stands for. */
    public const US_BANK_CAPITAL_RATIO = 0.076;
    /** Share of a financial recession's loss each point of bank equity over total assets spares: 0.86pp of five-year output against the average 24.55pp loss (Jorda, Richter, Schularick & Taylor 2021, Table 8). */
    public const CRISIS_LOSS_SPARED_PER_CAPITAL_POINT = 0.86 / 24.55;
    /** Risk-weighted assets over total assets, US insured banks reporting them at end-2024 ($14.90T of $23.33T, FDIC Call Reports): turns a point of CET1 requirement into equity over total assets. */
    public const BANK_RWA_DENSITY = 0.639;

    // --- Bank Capital Build-Up (Bridges et al. 2014; Macroeconomic Assessment Group 2010) ---
    /** Years for banks to build to a new capital requirement or buffer, as a distributed lag: least squares on UK banks' capital ratios after a 1pp rise, up 0.41pp a year on and 0.95pp three years on (Bridges et al. 2014, BoE WP 486, Table C), which this puts at 0.48pp and 0.86pp. */
    public const REQUIREMENT_BUILD_YEARS = 1.52;
    /** Secured household lending cut per unit rise in the capital banks must hold: 0.94pp of year-one loan growth per 1pp (Bridges et al. 2014, Table D), most of it in the first quarter and none in later years; cuts in requirements leave it unaffected (s. 6.3). */
    public const SECURED_LENDING_CUT_PER_CAPITAL_RISE = 0.94;
    /** Unsecured household lending cut per unit rise: 0.68pp of year-one loan growth per 1pp, the same table's point estimate (68% interval -1.43 to 0.03). */
    public const UNSECURED_LENDING_CUT_PER_CAPITAL_RISE = 0.68;
    /** How capital not yet built reads to lending standards, as excess bond premium per unit of shortfall: fitted so a 1pp rise phased in over four years leaves output 0.17% below baseline at 18 quarters, the median of the MAG's (2010, Interim Report pp. 21-22) models that read lending standards and let monetary policy respond. */
    public const SLOOS_CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT = 0.87;

    // --- Excess Bond Premium (Gilchrist & Zakrajsek 2012), a displaced lognormal ---
    /** Displacement (141 bps): the premium plus this is lognormal. Profile maximum likelihood on the GZ series, 1973-2026 (95% CI 116-185 bps); in logs the shocks are the same size at every level, in levels they grow 2.8x from low to high. */
    public const EBP_DISPLACEMENT = 0.0141;
    /** Mean of ln(premium + displacement) over 1973-2026 outside the 2008 crisis quarters, so the premium averages ~0 as GZ's does. */
    public const EBP_LOG_MEAN = -4.271;
    /** Mean reversion of the log premium (1/yr), by indirect inference: quarterly averages of the process give its own shock 0.57 / 0.42 / 0.24 left at 3 / 4 / 6 quarters, the record 0.59 / 0.45 / 0.22. */
    public const EBP_MEAN_REVERSION = 1.2;
    /** Diffusion of the log premium (per sqrt year), fitted with the jumps below so quarterly averages give the log-ARX residual sd of 0.142. */
    public const EBP_LOG_VOLATILITY = 0.183;
    /** Merton jumps in the log premium per year: 4.94 by exact compound-Poisson likelihood on the quarterly residuals (LR 13.8 over a Gaussian; jump mean +0.001, so none). */
    public const EBP_JUMP_INTENSITY = 4.94;
    /** Standard deviation of a log-premium jump, from the same fit rescaled for quarterly averaging (0.114 per quarter's residual). */
    public const EBP_JUMP_LOG_VOLATILITY = 0.165;
    /** Log premium per unit FALL in the output gap: 3.71 (se 0.78), the log-ARX; a quarter losing 2pp of gap adds ~11 bps at a neutral premium and ~37 at 2008's. The gap's level has no pull of its own. */
    public const EBP_LOG_GAP_SPEED_SENSITIVITY = 3.71;

    // --- Federal Reserve Senior Loan Officer Opinion Survey (SLOOS) ---
    /** Adjustment speed of lending standards toward their target (1/yr): partial adjustment on the GZ premium, 1990-2026, SLOOS AR 0.71 (se 0.05) a quarter, a six-month half-life. */
    public const SLOOS_KAPPA = 1.36;
    /** Long-run net tightening per unit of excess bond premium: 34.6 from the same partial adjustment (impact 9.9, se 2.2); standards follow the premium better than the whole GZ spread (static R2 0.46 against 0.27). */
    public const SLOOS_PREMIUM_SENSITIVITY = 34.6;
    /** Net tightening per unit of output gap contraction: none. With the premium in, the gap's loading is +1.4 (se 1.5), wrong-signed and insignificant; 2008's standards eased while the gap was still -4%. */
    public const SLOOS_GAP_SENSITIVITY = 0.0;
    /** Stochastic diffusion volatility of commercial bank underwriting standards. */
    public const SLOOS_SIGMA = 0.08;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /** Logs the jumps this subsystem draws. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null
    ) {}

    /**
     * Merton (1974) Structural Distance-to-Default Corporate Credit Spread Model
     * with Jarrow, Lando & Turnbull (1997) Dual-Tranche (IG vs HY) Rating Migration Cliff.
     *
     * Models investment-grade (IG) and speculative high-yield (HY) corporate credit spreads over risk-free
     * Treasuries as Gilchrist & Zakrajsek's (2012) two parts: the default risk of leverage and equity volatility,
     * and the excess bond premium lenders charge on top of it, plus wholesale interbank contagion and the
     * non-linear "fallen angel" rating migration cliff during contractions. None of the inputs reads the spread
     * back, so the spread has no loop of its own to hold it at its cap.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateMacroCreditSpread(MacroState $state): void
    {
        $interbankStress = max(0.0, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);

        // Borensztein, Cowan & Valenzuela (2013) sovereign ceiling transmission to corporate credit spreads.
        $baseIgSpread = MacroEngine::BASE_CREDIT_SPREAD + (self::SOVEREIGN_CEILING_PASSTHROUGH * $state->sovereignRiskSpreadEma);

        $trancheSpreads = $this->mathUtility->calculateDualTrancheCreditSpreads(
            baseIgSpread: $baseIgSpread,
            outputGapEma: $state->outputGapEma,
            marketVolEma: $state->marketVolatilityEma,
            interbankStress: $interbankStress,
            excessBondPremium: $state->excessBondPremium,
            hyBaseMultiplier: MacroEngine::HY_BASE_SPREAD_MULTIPLIER
        );

        // Dual-tranche credit spread boundary clamping (Merton 1974 structural model).
        $state->macroCreditSpread = $trancheSpreads['ig'];
        $state->highYieldCreditSpread = $trancheSpreads['hy'];
    }

    /**
     * Cox-Ingersoll-Ross (CIR 1985) Square-Root Diffusion with Systemic TED Freeze Jumps (Kou 2002).
     *
     * Models wholesale interbank lending liquidity spreads (TED / Libor-OIS spread) using a strictly
     * positive mean-reverting CIR square-root process compounded with volatility-sensitive Poisson panic jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateInterbankLiquiditySpread(MacroState $state, float $dt): void
    {
        $currentSpread = $state->interbankLiquiditySpread ?? MacroEngine::INTERBANK_BASELINE_SPREAD;

        // Brunnermeier (2009) & Gorton-Metrick (2012) wholesale funding risk. It reads the lenders' premium, the
        // factor TED and the corporate spread share, and not the corporate spread itself, which reads TED back.
        $positivePremium = max(0.0, $state->excessBondPremium);
        $premiumCoupledTheta = MacroEngine::INTERBANK_BASELINE_SPREAD + ($positivePremium * self::INTERBANK_PREMIUM_COUPLING);

        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateCIR(
            currentValue: $currentSpread,
            kappa: MacroEngine::INTERBANK_SPREAD_KAPPA,
            theta: $premiumCoupledTheta,
            sigma: MacroEngine::INTERBANK_SPREAD_SIGMA,
            dt: $dt,
            dW: $dW
        );

        $volatilityRatio = max(1.0, $state->marketVolatilityEma / MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $creditRatio = 1.0 + ($positivePremium / MacroEngine::BASE_CREDIT_SPREAD);
        $jumpProbability = min(0.20, self::INTERBANK_JUMP_PROBABILITY * $volatilityRatio * (1.0 + 0.5 * ($creditRatio - 1.0)));

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: $jumpProbability,
            jumpMean: self::INTERBANK_JUMP_MEAN,
            jumpVol: self::INTERBANK_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
            $this->diagnostics?->recordEvent('interbank', (float) $jumpData['exponent']);
        }
        // Gorton & Metrick (2012) wholesale funding liquidity run during banking crisis shock.
        if ($state->lastCreditCrisisAt === $state->totalTime) {
            $jumpAmount = max($jumpAmount, $baseProcess * (exp(self::INTERBANK_JUMP_MEAN) - 1.0));
        }

        $state->interbankLiquiditySpread = max(
            self::INTERBANK_MIN_SPREAD,
            min(self::INTERBANK_MAX_SPREAD, $baseProcess + $jumpAmount)
        );
    }

    /**
     * Vasicek (2002) One-Factor Asymptotic Single Risk Factor (ASRF) Retail Default Model (Basel II/III).
     *
     * Derives the household default probability (PD) from a systematic factor: unemployment (job loss), borrowing
     * spreads (refinancing), house prices against their trend (negative equity, the mortgage default trigger), and a
     * persistent factor those channels leave unexplained, stepped as an exact OU so its effect does not depend on the
     * tick length. The weights are a joint fit to US delinquency; inflation and the debt-service gap took the wrong
     * sign there and are left out (the debt-service gap predicts crises a year or two ahead, Drehmann & Juselius
     * 2014, and acts through the crisis hazard and deleveraging instead).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateRetailDefaultRate(MacroState $state, float $dt): void
    {
        $unemploymentShock = ($state->unemploymentRateEma - $state->nairu) * self::RETAIL_UNEMPLOYMENT_SENSITIVITY;

        $borrowingSpreadStress = max(0.0, $state->macroCreditSpreadEma - MacroEngine::BASE_CREDIT_SPREAD);
        $interbankStress = max(0.0, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        $spreadShock = ($borrowingSpreadStress + $interbankStress) * self::RETAIL_CREDIT_SPREAD_SENSITIVITY;

        $housePriceGap = $state->residentialWealthTrend > 0.0 && $state->residentialPropertyIndexEma > 0.0
            ? log($state->residentialPropertyIndexEma / $state->residentialWealthTrend)
            : 0.0;

        $state->retailCreditFactor = MathUtility::calculateOrnsteinUhlenbeckStep(
            $state->retailCreditFactor,
            self::RETAIL_CREDIT_FACTOR_KAPPA,
            self::RETAIL_CREDIT_FACTOR_SD,
            $dt,
            $this->mathUtility->generateStandardNormal()
        );

        $macroZ = self::RETAIL_CREDIT_INTERCEPT + ($housePriceGap * self::RETAIL_HOUSE_PRICE_SENSITIVITY) - $unemploymentShock - $spreadShock + $state->retailCreditFactor;

        $conditionalPd = $this->mathUtility->calculateVasicekExpectedLoss(
            macroZ: $macroZ,
            pdLra: MacroEngine::RETAIL_DEFAULT_BASELINE,
            rho: self::RETAIL_ASRF_RHO,
            lgd: 1.0
        );

        $state->retailDefaultRate = max(0.005, min(0.20, $conditionalPd));
    }

    /**
     * Government purchases as an exogenous process: a Schwartz (1997) log-OU fitted to US civilian purchases (federal
     * non-defence plus state and local) over potential, 1985-2019 (var/harness/gov_fit.py). The District fields no army,
     * so the wartime surges that fatten the US total are its customers' (AssetMarketSubsystem::calculateAlliedDefenseSpending).
     * Purchases do not lean against the cycle (Auerbach 2002; the fit's lagged-gap term is insignificant), so the
     * countercyclical fiscal leg is the tax rule alone.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateGovernmentSpending(MacroState $state, float $dt): void
    {
        $state->governmentSpendingIndex = max(60.0, min(200.0, $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->governmentSpendingIndex,
            kappa: self::GOVT_SPENDING_MEAN_REVERSION,
            theta: MacroEngine::GOVT_SPENDING_BASELINE,
            sigma: self::GOVT_SPENDING_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        )));
    }

    /**
     * Barro (1979) Tax-Smoothing Hypothesis & Automatic Fiscal Stabilizers.
     *
     * Adjusts the corporate tax rate continuously via an Ornstein-Uhlenbeck institutional process:
     * raises effective tax burden during economic booms to cool demand, and cuts taxes during recessions.
     * The rate it returns to is the neutral rate plus the shift the Diet has legislated.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateDynamicFiscalPolicy(MacroState $state, float $dt): void
    {
        $targetTaxRate = MacroEngine::TARGET_CORPORATE_TAX_RATE + $state->corporateTaxPolicyShift + (self::FISCAL_STABILIZER_SENSITIVITY * $state->outputGapEma);
        $targetTaxRate = max(self::MIN_CORPORATE_TAX_RATE, min(self::MAX_CORPORATE_TAX_RATE, $targetTaxRate));

        $state->corporateTaxRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($targetTaxRate - $state->corporateTaxRate) * $dt;

        // The legislated part of that adjustment on its own, and the laws as the trailing year's earnings carry them,
        // which the market measures what it expects against (App\Service\Market\PolicyCapitalization).
        $state->corporateTaxShiftRealized += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($state->corporateTaxPolicyShift - $state->corporateTaxShiftRealized) * $dt;
        $state->corporateTaxShiftEmbodied += ($state->corporateTaxShiftRealized - $state->corporateTaxShiftEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $state->bankLevyEmbodied += ($state->bankLevyRate - $state->bankLevyEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        // The rules on extraction and the stamp duty reach costs and turnover at once, so their factors are lagged as the levy is.
        $state->extractionCostFactorEmbodied += (MathUtility::calculateExtractionCostFactor($state->extractionStringency) - $state->extractionCostFactorEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $state->stampDutyVolumeFactorEmbodied += (MathUtility::calculateStampDutyVolumeFactor($state->stampDutyRate) - $state->stampDutyVolumeFactorEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
    }

    /**
     * Fund-financed fiscal stabilisation: the budget leans against the cycle and the sovereign fund pays for it.
     *
     * Norway's structural non-oil balance answers the output gap (IMF Norway Selected Issues 2025, Table 5, a Golinelli &
     * Momigliano 2009 reaction function on annual data): each year it rises 0.450 per point of gap and gives back 0.452 of
     * the year before's change. That is the discretionary balance as a whole, so the fund's leg is what the tax leg
     * leaves: spending above the rule draw in a slump, saving into the fund in a boom. The US record has no such leg
     * (purchases do not lean against the cycle, Auerbach 2002); the fund is the fiscal space that lets the District run
     * one (Romer & Romer 2019). Its swings in market value are kept out, as the IMF advises Norway to do.
     *
     *   D = D0 + change,  change = -beta x (gap - its long-run average) - phi x (last year's change) - kappa x D0
     *
     * where D0 is the level the budget year opened at. The gap is read against its own trailing average, as a
     * ministry's trend estimate averages the cycle to zero: read against potential itself, the engine's gap, whose
     * mean is below zero (so is CBO's), would hold D above zero for good and drain the fund to pay for it. The budget is
     * set twice a year, the October budget and the May revision, each on the gap as it then stands (Table 5 pairs a
     * year's change with that year's gap); the revision redoes the year's change from D0 rather than adding to it. The
     * rounds are the whole of the policy lag: a reading smoothed before the round and an appropriation smoothed after it
     * added three quarters of a year between gap and demand, and the rule kept pushing after the gap turned (HP ACF4 of
     * GDP 0.03 against the no-fund engine's 0.21; var/harness/stab_cycle.sh). The kappa term takes the level back to the
     * rule path, since Norway's deviations from its rule are temporary. The fund is never asked for more than its
     * foreign sleeves hold beside the rule draw. With no fund it is zero.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateFundStabilisation(MacroState $state, float $dt): void
    {
        if ($state->sovereignFundDollarsPerGdp <= 0.0) {
            $state->sovereignFundStabilisationToGdp = 0.0;
            return;
        }

        if (MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, MacroEngine::BUDGET_ROUND_PERIOD_YEARS)) {
            // The round that opens a budget year closes the last one: its change is booked and the new year starts from its end.
            if (MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, 1.0)) {
                $state->sovereignFundStabilisationLastChange = $state->sovereignFundStabilisationToGdp - $state->sovereignFundStabilisationYearStart;
                $state->sovereignFundStabilisationYearStart = $state->sovereignFundStabilisationToGdp;
            }

            $change = -(MacroEngine::FUND_STABILISATION_GAP_RESPONSE * ($state->outputGap - $state->sovereignFundGapTrend))
                - (MacroEngine::FUND_STABILISATION_IMPULSE_REVERSAL * $state->sovereignFundStabilisationLastChange)
                - (MacroEngine::FUND_STABILISATION_PERSISTENCE * $state->sovereignFundStabilisationYearStart);
            $affordable = max(0.0, ($state->sovereignFundToGdp * (1.0 - $state->sovereignFundDomesticWeight)) - $state->sovereignFundDrawToGdp);

            $state->sovereignFundStabilisationToGdp = min($affordable, $state->sovereignFundStabilisationYearStart + $change);
        }

        // The average is of the readings before this one, so a round compares today's gap with the past's.
        $state->sovereignFundGapTrend += (1.0 - exp(-$dt / MacroEngine::FUND_STABILISATION_GAP_TREND_YEARS)) * ($state->outputGap - $state->sovereignFundGapTrend);
    }

    /**
     * Sovereign Debt-to-GDP Stock Accumulation (Blanchard 2019, Greenwood-Vayanos 2014).
     *
     * Accumulates sovereign debt-to-GDP ratio from primary deficit flow, net interest expenses,
     * and nominal GDP growth erosion:
     *   d(Debt/GDP) = [ (G - T + S - NIRC)/GDP + (r_10y - g_nominal) * (Debt/GDP) ] * dt
     * where S is the structural deficit and NIRC the sovereign fund's draw on its expected returns, revenue like a tax.
     *
     * The tax rate's cyclical part is the whole budget's automatic response, so T books it on GDP as it was fitted. The
     * shift the Diet legislates is a change in the corporate rate alone and is levied on corporate profits; the tariff is
     * levied on imported goods, which shrink by their price elasticity as the duty raises their price; the carbon price
     * is levied on the power sector's emissions; the bank levy is what the board's banks owe on their balance sheets,
     * in currency, read through the reserve fund's dollars per unit of GDP (none is booked before the fund opens).
     *
     * With a fund the budget spends the draw: S is the draw itself, as Norway's fiscal rule sets the structural non-oil
     * deficit at the fund's expected real return. The draw is sized to the fund and the fund compounds with its markets,
     * so a fixed S would turn a fund that outgrows the economy into a standing surplus with nothing left to retire.
     *
     * Gross debt has a floor, the stock the benchmark curve is kept on. A surplus that would take debt below it buys
     * assets instead: it is paid into the fund (sovereignFundBudgetInflow, credited on the fund's next tick), as
     * Singapore's budget surpluses accrue to its reserves. With no fund there is nothing to buy and the floor holds.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSovereignDebt(MacroState $state, float $dt): void
    {
        $output = $state->nominalGdpIndex * (1.0 + $state->outputGap);
        $taxRevenue = (($state->corporateTaxRate - $state->corporateTaxPolicyShift) * $output)
            + ($state->corporateTaxPolicyShift * self::CORPORATE_PROFITS_TO_GDP * $output)
            + ($state->importTariffRate * MacroAggregateSubsystem::DISTRICT_IMPORT_SHARE * MacroAggregateSubsystem::GOODS_SHARE_OF_IMPORTS
                * ((1.0 + $state->importTariffRate) ** -MacroAggregateSubsystem::IMPORT_PRICE_ELASTICITY) * $output)
            + ($state->carbonPrice * self::POWER_SECTOR_CO2_PER_GDP_DOLLAR * $output)
            + ($state->sovereignFundDollarsPerGdp > 0.0 ? $state->boardBankLevy / $state->sovereignFundDollarsPerGdp : 0.0);
        $govtSpendingFlow = ($state->governmentSpendingIndex / MacroEngine::GOVT_SPENDING_BASELINE)
            * MacroEngine::TARGET_CORPORATE_TAX_RATE * $state->nominalGdpIndex;

        // Bohn (1998) fiscal reaction function: debt stabilization via primary budget surplus.
        $excessDebt = max(0.0, $state->sovereignDebtToGdp - MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD);
        $bohnFiscalAdjustment = self::BOHN_FISCAL_REACTION_SENSITIVITY * $excessDebt * $state->nominalGdpIndex;

        // Singapore's Net Investment Returns Contribution: the sovereign fund's draw is budget revenue (zero with no fund),
        // and with a fund the structural deficit is that draw, spent, together with the stabilisation the fund pays for.
        // The fund's currency bridge is set only at inception.
        $funded = $state->sovereignFundDollarsPerGdp > 0.0;
        $fundPaidToGdp = $state->sovereignFundDrawToGdp + $state->sovereignFundStabilisationToGdp;
        $fundContribution = $fundPaidToGdp * $state->nominalGdpIndex;
        $structuralDeficitToGdp = $funded ? $fundPaidToGdp : self::SOVEREIGN_STRUCTURAL_DEFICIT;

        $primaryDeficit = ($govtSpendingFlow - $taxRevenue) + ($structuralDeficitToGdp * $state->nominalGdpIndex) - $bohnFiscalAdjustment - $fundContribution;
        $state->primaryDeficitToGdp = $primaryDeficit / max(0.1, $state->nominalGdpIndex);
        $interestCost = $state->yield10yEma * $state->sovereignDebtToGdp;

        // Blanchard (2019) sovereign debt accumulation driven by growth-adjusted real rate (r - g).
        $realPotentialGrowth = $state->laborForceGrowthRate + MacroEngine::TFP_DRIFT;
        $nominalGrowthRate = $realPotentialGrowth + $state->outputGap + $state->inflationEma;
        $growthErosion = $nominalGrowthRate * $state->sovereignDebtToGdp;

        $dDebt = ($primaryDeficit / max(0.1, $state->nominalGdpIndex)) + $interestCost - $growthErosion;
        $state->sovereignDebtToGdp += $dDebt * $dt;

        $belowFloor = MacroEngine::SOVEREIGN_DEBT_FLOOR - $state->sovereignDebtToGdp;
        $state->sovereignFundBudgetInflow = ($funded && $belowFloor > 0.0)
            ? $belowFloor * $state->sovereignFundDollarsPerGdp * $state->nominalGdpIndex
            : 0.0;
        $state->sovereignDebtToGdp = max(MacroEngine::SOVEREIGN_DEBT_FLOOR, min(self::SOVEREIGN_DEBT_CEILING, $state->sovereignDebtToGdp));

        // IMF GFSM 2014 net debt: gross debt less the debt instruments the government holds, which here is the
        // sovereign fund's paper sleeve (its equities are not debt instruments). Equal to gross with no fund.
        $state->sovereignNetDebtToGdp = $state->sovereignDebtToGdp - $state->sovereignFundBondsToGdp;
    }

    /**
     * The household credit cycle (Mian & Sufi 2018) with the BIS debt-service ratio and the Basel III buffer.
     *
     * Leverage builds on collateral values and unwinds through amortisation and, past the warning line,
     * deleveraging; rates reach it through house prices and the debt service. The debt-service ratio is the BIS annuity
     * (Drehmann, Illes, Juselius & Santos 2015): the stock times the instalment its effective rate implies
     * over the average remaining maturity, read against its own 15-year average (Drehmann & Juselius 2012),
     * so a cold start and a slow drift in leverage are silent. The credit-to-GDP gap is the stock against a slow one-sided trend
     * (the Basel filter's stand-in), and the countercyclical buffer maps that gap onto the 0-2.5% Basel
     * schedule with a year's phase-in. The ratio reaches the retail default rate and the IS curve; the gap reaches the
     * lenders' order books. The capital banks must hold is the Financial Regulator's requirement plus the buffer: as it
     * rises they cut household lending at once (Bridges et al. 2014), and the capital they have built tracks it with a
     * lag, while the shortfall tightens lending standards (calculateSloosCreditStandards()).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateHouseholdCredit(MacroState $state, float $dt): void
    {
        $mortgageRate = $state->yield10yEma + MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD;
        $revolvingRate = max(0.0, $state->policyRateEma) + self::CONSUMER_CREDIT_SPREAD;
        $effectiveRate = (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * $mortgageRate) + ((1.0 - self::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * $revolvingRate);

        // Against the persistent trend, not the nominal baseline. Mian & Sufi's home-equity channel is a
        // response to prices moving away from trend; read off a fixed 100 it becomes a permanent level
        // signal, and the index's own equilibrium sits at ~94, so the term carried -0.56%/yr of standing
        // deleveraging and the credit stock could never build. Same fix as the housing wealth effect.
        $housePriceLift = $state->residentialWealthTrend > 0.0
            ? ($state->residentialPropertyIndexEma / $state->residentialWealthTrend) - 1.0
            : 0.0;
        $relativeExcess = ($state->householdDebtToIncome - MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE) / MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE;
        $excessDsr = max(0.0, $state->householdDebtServiceGap - MacroEngine::HOUSEHOLD_DSR_STRESS_MARGIN);

        // The terms US household credit supports (1976-2019). Rates, the gap and business-loan standards have no
        // direct pull on it once house prices are in; they act through the collateral and the debt service.
        $growth = (self::CREDIT_GROWTH_HOUSE_PRICE * $housePriceLift)
            - (self::CREDIT_MEAN_REVERSION * $relativeExcess)
            - (self::DELEVERAGING_SPEED * $excessDsr);
        $noise = self::CREDIT_GROWTH_SIGMA * sqrt($dt) * $this->mathUtility->generateStandardNormal();
        // Bridges et al. (2014): banks cut lending to households as the capital they must hold rises, secured and unsecured
        // in the stock's proportions, and do not lend it back when it falls.
        $capitalRequired = $state->bankCapitalRequirement + $state->countercyclicalBufferRate;
        $lendingCut = (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * self::SECURED_LENDING_CUT_PER_CAPITAL_RISE) + ((1.0 - self::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * self::UNSECURED_LENDING_CUT_PER_CAPITAL_RISE);
        $capitalSqueeze = $lendingCut * max(0.0, $capitalRequired - $state->bankCapitalRequiredLast);
        $state->bankCapitalRequiredLast = $capitalRequired;

        $previousLeverage = $state->householdDebtToIncome;
        $state->householdDebtToIncome = max(self::MIN_HOUSEHOLD_DEBT_TO_INCOME, min(self::MAX_HOUSEHOLD_DEBT_TO_INCOME, $state->householdDebtToIncome * exp(($growth * $dt) + $noise - $capitalSqueeze)));
        // Drehmann, Juselius & Korinek (2018) new borrowing, net of what holds leverage level: the year's average of
        // the change in leverage. It averages zero because leverage reverts, so spending needs no compensator for it.
        $state->householdNewBorrowing = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->householdNewBorrowing,
            targetValue: ($state->householdDebtToIncome - $previousLeverage) / $dt,
            dt: $dt,
            lagTimeConstant: self::HOUSEHOLD_NEW_BORROWING_HORIZON_YEARS
        );

        // BIS debt-service ratio annuity calculation (Drehmann, Illes, Juselius & Santos 2015).
        $annuityFactor = $effectiveRate > 0.0
            ? $effectiveRate / (1.0 - ((1.0 + $effectiveRate) ** (-self::DSR_AVERAGE_MATURITY_YEARS)))
            : 1.0 / self::DSR_AVERAGE_MATURITY_YEARS;
        $state->householdDebtServiceRatio = $state->householdDebtToIncome * $annuityFactor;

        // Drehmann & Juselius (2012) debt-service ratio gap relative to structural trend.
        if ($state->householdDebtServiceTrend <= 0.0) {
            $state->householdDebtServiceTrend = $state->householdDebtServiceRatio;
        }
        $state->householdDebtServiceTrend = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->householdDebtServiceTrend,
            targetValue: $state->householdDebtServiceRatio,
            dt: $dt,
            lagTimeConstant: self::DSR_TREND_HORIZON_YEARS
        );
        $state->householdDebtServiceGap = $state->householdDebtServiceRatio - $state->householdDebtServiceTrend;

        // Basel III credit gap: the one-sided HP trend, which is defined on quarterly data, so it steps on the quarter.
        if (floor($state->totalTime * 4.0) > floor(($state->totalTime - $dt) * 4.0)) {
            $trend = $this->mathUtility->calculateOneSidedHpStep(
                trendLevel: $state->creditToGdpTrend,
                trendSlope: $state->creditToGdpTrendSlope,
                observation: $state->householdDebtToIncome,
                levelGain: self::CREDIT_GAP_HP_LEVEL_GAIN,
                slopeGain: self::CREDIT_GAP_HP_SLOPE_GAIN
            );
            $state->creditToGdpTrend = $trend['level'];
            $state->creditToGdpTrendSlope = $trend['slope'];
        }
        $state->creditToGdpGap = $state->householdDebtToIncome - $state->creditToGdpTrend;

        $bufferPosition = max(0.0, min(1.0, ($state->creditToGdpGapEma - self::CCYB_GAP_FLOOR) / (self::CCYB_GAP_CEILING - self::CCYB_GAP_FLOOR)));
        $state->countercyclicalBufferRate = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->countercyclicalBufferRate,
            targetValue: self::MAX_CCYB * $bufferPosition,
            dt: $dt,
            lagTimeConstant: self::CCYB_PHASE_IN_YEARS
        );

        // The capital banks hold follows the requirement and the buffer over the years they take to build to them.
        $state->bankCapitalBuilt = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->bankCapitalBuilt,
            targetValue: $state->bankCapitalRequirement + $state->countercyclicalBufferRate,
            dt: $dt,
            lagTimeConstant: self::REQUIREMENT_BUILD_YEARS
        );
    }

    /**
     * The crisis channel of the credit cycle (Schularick & Taylor 2012; Jorda, Schularick & Taylor 2013).
     *
     * A boom carries state the output gap does not: the debt stock. Its hazard is a logit on the credit gap
     * (the medium-term signal) and the debt-service gap (the near-term trigger, Drehmann & Juselius 2014),
     * evaluated as a Poisson arrival per tick. When a crisis lands it books a deleveraging drag on demand
     * that scales with the boom behind it ("credit bites back") and decays over the following years, and
     * the same tick forces the wholesale funding run in the interbank spread. The hazard is off for a
     * refractory window afterwards: the bust resets the stock the hazard reads.
     *
     * The District is a light-touch financial centre, so both legs depart from the JST panel as Jorda, Richter,
     * Schularick & Taylor (2021) measure: banks funded beyond their deposits raise the hazard, and thin capital
     * deepens the recession a crisis brings (capital does not predict crises, only how hard they land), by as much as
     * the Financial Regulator's requirement leaves it thin (thinCapitalDragScale()).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCreditCrisisHazard(MacroState $state, float $dt): void
    {
        $state->creditCrisisDrag *= exp(-self::CREDIT_CRISIS_DRAG_DECAY * $dt);

        $yearsSinceLast = $state->lastCreditCrisisAt < 0.0 ? INF : $state->totalTime - $state->lastCreditCrisisAt;
        if ($yearsSinceLast < self::CREDIT_CRISIS_REFRACTORY_YEARS) {
            $state->creditCrisisHazard = 0.0;
            return;
        }

        $state->creditCrisisHazard = $this->mathUtility->calculateSchularickTaylorCrisisHazard(
            creditGap: $state->creditToGdpGapEma,
            beta0: self::CREDIT_CRISIS_LOGIT_INTERCEPT + self::DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT,
            betaGap: self::CREDIT_CRISIS_LOGIT_GAP
        );

        if (!$this->mathUtility->checkProbability($state->creditCrisisHazard * $dt)) {
            return;
        }

        $state->lastCreditCrisisAt = $state->totalTime;
        $crisisDrag = self::thinCapitalDragScale($state->bankCapitalRequirement)
            * (self::CREDIT_CRISIS_DRAG_BASE + (self::CREDIT_CRISIS_DRAG_PER_GAP * max(0.0, $state->creditToGdpGapEma)));
        $state->creditCrisisDrag += $crisisDrag;
        $this->diagnostics?->recordEvent('creditCrisis', $crisisDrag);
        // Gilchrist & Zakrajsek (2012): lenders' capital is hit on the day, so the premium they charge jumps with it.
        $state->excessBondPremium += self::EBP_CRISIS_JUMP;
    }

    /**
     * How much harder a financial recession lands on the District's bank capital than on the US's (Jorda, Richter,
     * Schularick & Taylor 2021, Table 8): the District's equity over total assets is its opening 4.9%, moved by each
     * point the CET1 requirement stands from the opening one at the banks' risk-weighted density, and each point short of
     * the US's 7.6% adds the share of the loss a point of capital spares. 1.095 at the opening requirement.
     *
     * @param float $requirement The CET1 requirement in force, as a share of risk-weighted assets.
     */
    public static function thinCapitalDragScale(float $requirement): float
    {
        $capitalRatio = self::DISTRICT_BANK_CAPITAL_RATIO + (($requirement - FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT) * self::BANK_RWA_DENSITY);

        return 1.0 + (self::CRISIS_LOSS_SPARED_PER_CAPITAL_POINT * 100.0 * (self::US_BANK_CAPITAL_RATIO - $capitalRatio));
    }

    /**
     * Excess bond premium (Gilchrist & Zakrajsek 2012): the part of the corporate spread that default risk does
     * not explain, the price lenders put on bearing credit risk at all.
     *
     * A displaced lognormal: the premium plus EBP_DISPLACEMENT follows a Schwartz (1997) log-OU, stepped with
     * its exact transition, with Merton (1976) jumps in the log. In the record its shocks grow with its level and
     * it never falls far below zero; in logs the shocks are the same size everywhere, so a lognormal gives both.
     * It is driven by the speed the output gap falls (never its level) and by a level jump when a credit crisis
     * strikes. It peaks before the gap's trough and is largely gone within a year while the gap is still there.
     * The spread, equity volatility, the interbank spread and lending standards all load on it, and none of them
     * feeds back into it.
     *
     * @param MacroState $state     Current macroeconomic state.
     * @param float      $gapChange Change in the output gap over this step.
     * @param float      $dt        Time increment in years.
     */
    public function updateExcessBondPremium(MacroState $state, float $gapChange, float $dt): void
    {
        // The Schwartz target carries the Ito correction; handing it this level puts the log mean on EBP_LOG_MEAN.
        $target = exp(self::EBP_LOG_MEAN + ((self::EBP_LOG_VOLATILITY ** 2) / (2.0 * self::EBP_MEAN_REVERSION)));
        $shifted = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->excessBondPremium + self::EBP_DISPLACEMENT,
            kappa: self::EBP_MEAN_REVERSION,
            theta: $target,
            sigma: self::EBP_LOG_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );
        $jump = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::EBP_JUMP_INTENSITY,
            jumpMean: 0.0,
            jumpVol: self::EBP_JUMP_LOG_VOLATILITY,
            dt: $dt
        );

        $shifted *= $jump['multiplier'] * exp(-self::EBP_LOG_GAP_SPEED_SENSITIVITY * $gapChange);
        if ($jump['exponent'] !== null) {
            $this->diagnostics?->recordEvent('excessBondPremium', $jump['exponent']);
        }
        $state->excessBondPremium = $shifted - self::EBP_DISPLACEMENT;
    }

    /**
     * Sovereign risk premium (Laubach 2009): the fiscal position priced into the long end.
     *
     * One-sided on the debt stock above the level at which an advanced sovereign is re-rated. The premium is
     * a level the whole curve carries (it enters the term premium, the corporate IG base through the
     * sovereign ceiling, and the currency), repriced over budget rounds rather than ticks. It stays zero
     * through the 85-90% the fiscal reaction settles at.
     *
     * The stock is NET debt, gross less the sovereign fund's bonds: spreads price net rather than gross debt
     * (Hadzi-Vaskov & Ricci 2016, IMF WP/16/148), which is how Singapore's gross debt of over 150% of GDP
     * carries no premium at all. What the budget and the bond market's duration supply read stays gross:
     * the coupons are paid on gross debt, and the fund holds foreign paper, not the District's.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSovereignRiskSpread(MacroState $state, float $dt): void
    {
        $excessDebt = max(0.0, $state->sovereignNetDebtToGdpEma - MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD);
        $target = min(self::MAX_SOVEREIGN_RISK_SPREAD, self::LAUBACH_DEBT_YIELD_SENSITIVITY * $excessDebt);

        $state->sovereignRiskSpread = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->sovereignRiskSpread,
            targetValue: $target,
            dt: $dt,
            lagTimeConstant: self::SOVEREIGN_RISK_REPRICING_YEARS
        );
    }

    /**
     * Economic policy uncertainty (Baker, Bloom & Davis 2016).
     *
     * A log mean-reverting index whose level is set by two things the record ties it to: the election calendar, since
     * uncertainty about the policy regime builds into a scheduled vote and resolves once a government takes office (Julio
     * & Yook 2012; Bernhard & Leblang 2006), and the cycle, since a downturn brings the policy response itself into
     * question. The calendar arrives as the election pulse the government hands the economy, centred on its long-run
     * mean, so it moves the index through the term and leaves its average where it was. Unscheduled shocks arrive as
     * jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculatePolicyUncertainty(MacroState $state, float $dt): void
    {
        $recessionExcess = max(0.0, $state->recessionProbabilityEma - self::EPU_STRESS_PROBABILITY_FLOOR);
        $jumpLogCompensator = self::EPU_JUMP_PROBABILITY * self::EPU_JUMP_MEAN / self::EPU_MEAN_REVERSION;

        $target = MacroEngine::EPU_BASELINE * exp(
            (self::EPU_ELECTION_LIFT * $state->electionPulse)
                + (self::EPU_STRESS_LIFT * $recessionExcess)
                - $jumpLogCompensator
        );

        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: max(self::MIN_EPU, $state->policyUncertaintyIndex),
            kappa: self::EPU_MEAN_REVERSION,
            theta: $target,
            sigma: self::EPU_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::EPU_JUMP_PROBABILITY,
            jumpMean: self::EPU_JUMP_MEAN,
            jumpVol: self::EPU_JUMP_VOL,
            dt: $dt
        );

        $state->policyUncertaintyIndex = max(self::MIN_EPU, min(self::MAX_EPU, $baseProcess * $jumpData['multiplier']));
        if ($jumpData['exponent'] !== null) {
            $this->diagnostics?->recordEvent('policyUncertainty', $jumpData['exponent']);
        }
    }

    /**
     * Administered healthcare price update (the CMS market-basket rule).
     *
     * Hospital reimbursement is an administered price: reset once a year to the inflation the payer observes
     * at the update, less a statutory productivity offset (ACA s.3401), less a sequester that scales with the
     * sovereign's excess debt. It is a step function, not a diffusion -- between updates the rate is flat
     * whatever inflation does, which is why a hospital's margin is squeezed through an inflation spike and
     * repaired a year later.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateReimbursementRate(MacroState $state, float $dt): void
    {
        $crossedYearEnd = floor($state->totalTime) > floor($state->totalTime - $dt);
        if (!$crossedYearEnd) {
            return;
        }

        $excessDebt = max(0.0, $state->sovereignDebtToGdpEma - MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD);
        $update = $state->inflationEma
            - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET
            - (self::REIMBURSEMENT_FISCAL_CUT_SENSITIVITY * $excessDebt);

        $state->reimbursementRateGrowth = max(self::REIMBURSEMENT_MIN_UPDATE, $update);
        $state->reimbursementRateIndex *= 1.0 + $state->reimbursementRateGrowth;
    }

    /**
     * Federal Reserve Senior Loan Officer Opinion Survey (SLOOS) Credit Standards Index.
     *
     * Evaluates net percentage of commercial banks tightening C&I loan standards from the price lenders put on
     * credit risk, the excess bond premium. Standards follow the premium rather than the whole spread (R2 0.46
     * against 0.27), so they tighten on the shock and ease once it passes, with the gap still deep: net
     * tightening went from 84% in October 2008 to easing by January 2010. A credit crisis reaches them through
     * the premium jump it books, not through the multi-year deleveraging drag. Capital the banks must hold but have not
     * yet built tightens them too, as the Macroeconomic Assessment Group (2010) carried higher capital targets into
     * output through lending standards.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSloosCreditStandards(MacroState $state, float $dt): void
    {
        // Macroeconomic Assessment Group (2010): banks building to a raised requirement or buffer ration credit through
        // their standards until the capital is built; capital already held rations nothing, and a cut eases nothing.
        $capitalShortfall = max(0.0, $state->bankCapitalRequirement + $state->countercyclicalBufferRate - $state->bankCapitalBuilt);
        $capitalTightening = self::SLOOS_CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT * $capitalShortfall;
        $dW = $this->mathUtility->generateStandardNormal();

        $state->sloosTighteningIndex = $this->mathUtility->calculateSloosCreditStandards(
            currentSloos: $state->sloosTighteningIndex,
            outputGap: $state->outputGapEma,
            excessCreditSpread: $state->excessBondPremium + $capitalTightening,
            dt: $dt,
            dW: $dW,
            kappa: self::SLOOS_KAPPA,
            creditSensitivity: self::SLOOS_PREMIUM_SENSITIVITY,
            gapSensitivity: self::SLOOS_GAP_SENSITIVITY,
            sigma: self::SLOOS_SIGMA
        );
    }

    /**
     * Moody's All-Rated Corporate Default Rate Model.
     *
     * Derives the realized all-rated issuer-weighted corporate default rate (CDR) from a structural
     * macroeconomic credit factor combining output gap, high-yield credit spreads and bank lending
     * standards (SLOOS). It is the all-rated series, not the speculative-grade one, because every
     * reader treats it as the economy-wide rate: receivables are provisioned at it and sector models
     * scale off its excess over the long-run average.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCorporateDefaultRate(MacroState $state, float $dt): void
    {
        $baseHySpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        $excessHySpread = max(0.0, $state->highYieldCreditSpread - $baseHySpread);

        $macroZ = ($state->outputGapEma * self::CORPORATE_DEFAULT_GAP_SENSITIVITY)
            - ($excessHySpread * self::CORPORATE_DEFAULT_SPREAD_SENSITIVITY)
            - ($state->sloosTighteningIndexEma * self::CORPORATE_DEFAULT_SLOOS_SENSITIVITY);

        $state->corporateDefaultRate = $this->mathUtility->calculateCorporateDefaultRate(
            macroZ: $macroZ,
            baseDefaultRate: MacroEngine::CORPORATE_DEFAULT_BASELINE,
            rho: self::CORPORATE_DEFAULT_RHO
        );
    }
}
