<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;

/**
 * Models asset pricing, commercial and residential real estate, equity volatility,
 * foreign exchange rates, and behavioral animal spirits.
 */
class AssetMarketSubsystem
{
    // --- JORGENSON USER COST RESIDENTIAL REAL ESTATE ---
    /** Structural property tax, insurance, and maintenance depreciation rate. */
    public const RESIDENTIAL_DEPRECIATION_TAX_RATE = 0.025;
    /**
     * Equilibrium user cost of housing: neutral 10Y (r* + target + base premium) + mortgage spread + carry costs -
     * target inflation.
     */
    public const RESIDENTIAL_NEUTRAL_USER_COST = MacroConstants::NEUTRAL_MORTGAGE_RATE + self::RESIDENTIAL_DEPRECIATION_TAX_RATE - MacroConstants::TARGET_INFLATION;

    // --- Immigration and Housing (Saiz 2007) ---
    /**
     * Rise in rents and house values per unit of population added by immigration (Saiz 2007: an inflow of 1% of a
     * city's population raises rents and values about 1%); the District's land ends at the sounds.
     */
    public const IMMIGRATION_HOUSING_ELASTICITY = 1.0;

    // --- Foreign Buyers of Homes (British Columbia's 2016 Foreign Buyers Tax) ---
    /**
     * Fall in foreign buyers' share of home purchases per unit of tax, in logs: BC's 15% tax took it from 9.5% of
     * transactions in the six weeks before to 1.7% in the four months after (Pavlov, Somerville & Wetzel), ln(9.5 /
     * 1.7) / 0.15.
     */
    public const FOREIGN_BUYER_TAX_DEMAND_SEMI_ELASTICITY = 11.47;
    /**
     * Log of house values foreign demand holds up, all of which a tax that prices every foreign buyer out takes off:
     * the 15% tax left prices 6% lower where foreign buyers bought (Pavlov, Somerville & Wetzel; West & Botsch 2020
     * find 2.4% within five months), ln(1 / 0.94) over the 82% of foreign demand it removed. The District is a
     * financial centre, so its whole market is such a neighbourhood.
     */
    public const FOREIGN_BUYER_HOUSING_VALUE_SHARE = 0.0754;
    /**
     * Foreign buyers' share of home purchases with no tax: 9.5% of transactions in the six weeks before British
     * Columbia's 2016 tax (Pavlov, Somerville & Wetzel).
     */
    public const FOREIGN_BUYER_PURCHASE_SHARE = 0.095;
    /**
     * Homes sold a year as a share of GDP, the base the tax on foreign buyers is levied on: British Columbia's 20% tax
     * raised C$35-40 million a year in 2018-20 (budget 2018 projections) on about C$295 billion of GDP, over the 20%
     * and the foreign share it left (9.5% e^(-11.47 x 0.2)), 6.6%.
     */
    public const HOME_SALES_TO_GDP = 0.066;

    // --- Green Belts (Hilber & Vermeulen 2016, EJ: 353 English planning authorities, 1974-2008) ---
    /**
     * Mean refusal rate of major residential schemes (ten or more homes) across English planning authorities, 1979-2008
     * (Table 1).
     */
    public const PLANNING_REFUSAL_RATE_MEAN = 0.254;
    /** Standard deviation of that refusal rate across authorities (Table 1). */
    public const PLANNING_REFUSAL_RATE_SD = 0.087;
    /**
     * Standard deviations from the mean authority to the 10th or 90th percentile, a normal's (the refusal rate's
     * skewness is 0.33): the founding planning regime stands at the 10th, the strictest green belt at the 90th.
     */
    public const PLANNING_PERCENTILE_Z = 1.2816;
    /**
     * Rise in the house price-earnings elasticity per standard deviation of refusal rate while house prices rise (Table
     * 3, booms: 0.267, se 0.055).
     */
    public const GREEN_BELT_BOOM_ELASTICITY_PER_SD = 0.267;
    /**
     * The same rise while house prices fall (Table 3, busts: 0.152, se 0.061): a durable stock cannot shrink, so supply
     * binds less on the way down (Glaeser & Gyourko 2005).
     */
    public const GREEN_BELT_BUST_ELASTICITY_PER_SD = 0.152;

    // --- Habit Equity Risk Premium ---
    /** Campbell-Cochrane (1999) habit formation risk aversion sensitivity to output gap deviations. */
    public const HABIT_RISK_AVERSION_COEFF = 9.0;
    /** Structural floor: equities must logically yield more than risk-free T-bills. */
    public const MIN_EQUITY_RISK_PREMIUM = 0.02;
    /**
     * Campbell & Shiller (1988) log-linearisation constant, annual: rho = 1 / (1 + mean dividend yield), ~0.96 on the
     * long US sample.
     */
    public const CAMPBELL_SHILLER_RHO = 0.96;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /**
     * Log volatility per unit of output gap contraction (1.1, se 2.1): ln VIX on the gap AND the excess bond premium,
     * 1990-2026. Alone the gap takes ~4 by standing in for the omitted premium; 2010's VIX averaged 22 with the gap
     * still -3.5%.
     */
    public const MACRO_VOL_OUTPUT_GAP_SENSITIVITY = 1.1;
    /**
     * Log volatility per unit of excess bond premium (31.6, se 3.6), the same regression: 2008's 340 bps premium lifts
     * the anchor ~2.9x. VIX is uncertainty plus the price of risk (Bekaert & Hoerova 2014), and the premium is that
     * price.
     */
    public const MACRO_VOL_PREMIUM_SENSITIVITY    = 31.6;
    /** Sensitivity of exponential baseline volatility to yield curve slope (flattening/inversion increases vol). */
    public const MACRO_VOL_SLOPE_SENSITIVITY      = 8.0;
    /**
     * Sensitivity of the baseline volatility anchor to the log policy-uncertainty index: a doubling lifts the anchor
     * ~11% (Pastor & Veronesi 2013 political uncertainty premium; the BBD index co-moves with the VIX). Enters the
     * anchor, so it is budgeted against the jump compensation like the other drivers.
     */
    public const MACRO_VOL_EPU_SENSITIVITY        = 0.15;
    /** Lower clamp for baseline volatility during extreme Goldilocks expansions. */
    public const MACRO_VOL_MIN_BASELINE           = 0.10;
    /** Upper clamp for macro-driven baseline volatility to prevent infinite variance explosion. */
    public const MACRO_VOL_MAX_BASELINE           = 0.45;
    /** Mean-reversion speed (kappa) of the continuous macroeconomic variance process. */
    public const MACRO_VOL_KAPPA                  = 2.0;
    /**
     * Failsafe floor on realized equity volatility (8%), below the calmest stretch of the cycle. It catches a
     * numerically degenerate variance draw and nothing else: if the process rests on it the parameters above are wrong,
     * because a clamp that binds is setting the level this class claims to anchor.
     */
    public const MACRO_VOL_FLOOR                  = 0.08;
    /** Failsafe ceiling on realized equity volatility (80%), above the 2008 VIX peak. */
    public const MACRO_VOL_CEILING                = 0.80;
    /**
     * Volatility of volatility (sigma) in the macroeconomic variance diffusion. Kept under sqrt(2 * kappa * theta)
     * measured on the JUMP-ADJUSTED anchor (~0.26), so the Feller condition holds and the variance stays strictly
     * positive. Above it the stationary density piles onto the 8% clamp, and the clamp -- not this anchor -- sets the
     * realized level.
     */
    public const MACRO_VOL_SIGMA                  = 0.15;

    // --- Leverage Effect (Heston 1993) ---
    /**
     * Correlation of the variance innovation with the market return, the Heston rho: -0.77 on bias-corrected
     * high-frequency S&P 500 data (Ait-Sahalia, Fan & Li 2013).
     */
    public const MACRO_VOL_LEVERAGE_RHO = -0.77;

    // --- Consumer Sentiment (Michigan index on FRED, monthly 1978-2019: UMCSENT on UNRATE - NROU, CPI yoy, UNRATE yoy)
    // ---
    /** Index points per unit of unemployment above NAIRU: -4.56 a point (Newey-West t -5.7). */
    public const SENTIMENT_SLACK_LOADING = -456.0;
    /**
     * Index points per unit of year-on-year CPI inflation above target: -2.27 a point (t -6.5). Real gasoline against
     * its trend adds nothing once CPI is in (t -0.8), nor does the VIX (t -1.4).
     */
    public const SENTIMENT_INFLATION_LOADING = -227.0;
    /** Index points per unit of rise in unemployment over the year: -3.06 a point (t -2.6). */
    public const SENTIMENT_UNEMPLOYMENT_CHANGE_LOADING = -306.0;
    /** Mean reversion of the mood the fundamentals leave unexplained: the residual's 0.859 monthly autocorrelation. */
    public const SENTIMENT_MOOD_MEAN_REVERSION = 1.83;
    /** Stationary standard deviation of that mood, in index points (the fit's residual, 7.6; R^2 0.64). */
    public const SENTIMENT_MOOD_SD = 7.56;
    /** Lowest the index can read, 53 points under its trend level. */
    public const SENTIMENT_FLOOR = 46.9;
    /** Highest the index can read, 27 points over its trend level. */
    public const SENTIMENT_CEILING = 126.9;

    // --- The Mainland (Rudebusch & Svensson 1999, re-estimated on US data 1985-2019: var/harness/mainland_fit.py) ---
    /**
     * The mainland's statistics and its central bank's decisions arrive once a quarter, the frequency its equations are
     * estimated at.
     */
    public const MAINLAND_QUARTERS_PER_YEAR = 4;
    /**
     * First autoregressive coefficient of the mainland gap on the CBO gap (1.235, se 0.136); the real-rate term
     * estimates at zero away from the lower bound (-0.006, se 0.037) and is left out, so the zero-bound years stay in
     * the sample.
     */
    public const MAINLAND_GAP_AR1 = 1.235;
    /** Second autoregressive coefficient of the mainland gap (-0.300, se 0.140). */
    public const MAINLAND_GAP_AR2 = -0.300;
    /**
     * Quarterly Gaussian innovation of the mainland gap, the regression's residual sd without 2008Q4 (0.452pp). With
     * the crashes below, the whole innovation is the regression's 0.490pp, and the stationary sd stays 1.65% against
     * the CBO gap's 1.62%.
     */
    public const MAINLAND_GAP_SIGMA = 0.00452;
    /**
     * Shared crashes a year, one in 40 years. 2008Q4 is the one in the 35-year sample; at the size below, crashes add
     * the variance the residuals lose without it (0.036pp^2 a quarter).
     */
    public const MAINLAND_CRASH_INTENSITY = 0.025;
    /** Mean gap lost in a crash quarter: 2008Q4's residual, 2.3pp, five sd of the Gaussian part. */
    public const MAINLAND_CRASH_MEAN = 0.023;
    /** Log sd of a crash's size: one crash in six is milder than 1.6pp, one in six deeper than 3.0pp. */
    public const MAINLAND_CRASH_LOG_SD = 0.30;
    /**
     * Annualized quarterly core PCE inflation on its own four lags (sum 0.885), the Rudebusch-Svensson Phillips curve;
     * its fitted mean is 1.96%, so it is anchored at the 2% target.
     */
    public const MAINLAND_INFLATION_LAGS = [0.381, 0.217, 0.141, 0.146];
    /**
     * Mainland core inflation per unit of last quarter's gap (0.004, se 0.028): the flat Phillips curve of the era
     * (Hazell, Herreño, Nakamura & Steinsson 2022).
     */
    public const MAINLAND_INFLATION_GAP_SLOPE = 0.004;
    /** Quarterly innovation of annualized mainland core inflation, the regression's residual sd (0.586pp). */
    public const MAINLAND_INFLATION_SIGMA = 0.00586;
    /** World GDP, 2023: $107.35T (World Bank NY.GDP.MKTP.CD). */
    public const WORLD_GDP_USD = 107.35e12;
    /** The mainland's (US) GDP, 2023: $27.8T (World Bank NY.GDP.MKTP.CD). */
    public const MAINLAND_GDP_USD = 27.81e12;
    /** The mainland's weight in the world demand that prices commodities: its share of world GDP. */
    public const MAINLAND_DEMAND_WEIGHT = self::MAINLAND_GDP_USD / self::WORLD_GDP_USD;
    /**
     * Share of the district's domestic demand cycle that reaches the mainland's demand in the long run: its imports for
     * home use are the mainland's exports, its main trading partner's, moving 1.4 times its demand (IMF WEO 2015) at
     * their share of its GDP, scaled to the mainland's size.
     */
    public const FOREIGN_IMPORT_SPILLOVER = MacroAggregateSubsystem::IMPORT_DEMAND_ELASTICITY * MacroAggregateSubsystem::DISTRICT_DOMESTIC_IMPORT_SHARE * MacroConstants::DISTRICT_GDP_USD / self::MAINLAND_GDP_USD;
    /** Guard on the mainland gap, beyond the deepest postwar CBO gap; the fitted process does not reach it. */
    public const MAX_FOREIGN_GAP = 0.10;
    /**
     * Stationary sd of the mainland gap its AR(2) implies on its whole innovation (mainlandGapInnovationSd()):
     * sigma * sqrt((1 - a2) / ((1 + a2) * ((1 - a2)^2 - a1^2))).
     */
    public const MAINLAND_GAP_STATIONARY_SD = 0.01645;

    // --- The Rest of the World (G6 industrial production gap, 1985-2019: FRED OECD MEI, own calculation) ---
    /**
     * Stationary sd of the world gap beyond the mainland: HP gap of G6 industrial production 2.81%, at the US ratio of
     * GDP to IP gap (0.40) and CBO to HP gap, 1.8% in CBO-gap units.
     */
    public const WORLD_GAP_SD = 0.018;
    /**
     * Correlation of the world gap with the mainland's: G6 IP gap against the CBO US gap, 0.51 (0.54 with the US a
     * quarter ahead).
     */
    public const WORLD_MAINLAND_CORRELATION = 0.51;
    /** Quarterly persistence of the world gap's own part: the G6 IP gap's AR(1), 0.85. */
    public const WORLD_GAP_AR1 = 0.85;
    /** World gap per unit of the mainland's: the correlation times the sd ratio, a one-factor loading. */
    public const WORLD_MAINLAND_LOADING = self::WORLD_MAINLAND_CORRELATION * self::WORLD_GAP_SD / self::MAINLAND_GAP_STATIONARY_SD;
    /**
     * Quarterly innovation of the world gap's own part: its stationary sd, WORLD_GAP_SD * sqrt(1 - correlation^2),
     * times sqrt(1 - AR1^2).
     */
    public const WORLD_GAP_SIGMA = 0.00816;
    /** Guard on the world gap, as on the mainland's; the fitted process does not reach it. */
    public const MAX_WORLD_GAP = 0.10;

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
    /**
     * Reversion speed of the log allied defence burden off its trend (0.489/yr, half-life 1.4y), corrected for annual
     * averaging.
     */
    public const ALLIED_DEFENSE_MEAN_REVERSION = 0.489;
    /**
     * Diffusion of the log allied defence burden, the Merton (1976) MLE on the annual residuals, scaled for the same
     * averaging.
     */
    public const ALLIED_DEFENSE_VOLATILITY = 0.0932;
    /**
     * Mobilisations a year, the same MLE (LR 31.6 against Gaussian): Korea is the sample's, so about one a generation.
     */
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
     * wanting "is the currency strong" reads MacroState::exchangeRateTrend instead.
     */
    public const EXCHANGE_RATE_BASELINE = 100.0;
    /** UIP sensitivity: exchange rate response to domestic-foreign interest rate differential. */
    public const UIP_SENSITIVITY = 3.0;
    /** Mean-reversion speed of exchange rate toward purchasing power parity equilibrium. */
    public const EXCHANGE_RATE_MEAN_REVERSION = 0.60;
    /**
     * Quarterly log-change sd the fundamental itself carries (rate differential, terms of trade, haven bid, fiscal
     * discount), measured on the full board at ~2.0%: the deviation supplies only the rest of the currency's variance.
     */
    public const EXCHANGE_RATE_FUNDAMENTAL_QUARTERLY_SD = 0.020;
    /** Horizon, in years, the currency's volatility is measured over: a quarter, as the BIS series are sampled. */
    public const EXCHANGE_RATE_VOLATILITY_HORIZON_YEARS = 0.25;
    /** Terms-of-trade FX elasticity (Chen-Rogoff 2003, importer sign): a dearer import basket weakens the currency. */
    public const FX_TERMS_OF_TRADE_SENSITIVITY = 0.30;
    /** Safe-haven FX elasticity (Ranaldo-Soderlind 2010): panic bids the reserve currency ~6% at 50% equity vol. */
    public const FX_SAFE_HAVEN_SENSITIVITY = 0.25;
    /**
     * Log depreciation per unit of sovereign risk spread: fiscal risk sells the currency (Alesina & Perotti 1995; Della
     * Corte, Sarno, Schmeling & Wagner 2022 on sovereign risk and currency returns). A 100 bps premium costs ~2%.
     */
    public const FX_FISCAL_RISK_SENSITIVITY = 2.0;
    /** Weight of the district's own gap in the global demand that prices its commodities: its share of world GDP. */
    public const DOMESTIC_DEMAND_WEIGHT = MacroConstants::DISTRICT_GDP_USD / self::WORLD_GDP_USD;

    // --- Physical Catastrophes ---
    /** Share of the housing stock's fundamental value destroyed per unit of excess catastrophe burden. */
    public const CATASTROPHE_PROPERTY_DAMAGE_SHARE = 0.01;

    // --- DIPASQUALE-WHEATON COMMERCIAL REAL ESTATE (2-QUADRANT) ---
    /** Sensitivity of occupancy/rent demand factor to excess unemployment (DiPasquale-Wheaton spatial market). */
    public const CRE_OCCUPANCY_UNEMPLOYMENT_SENSITIVITY = 3.0;
    /** Structural risk premium spread above 10Y yield for CRE cap rate derivation. */
    public const CRE_CAP_RATE_RISK_PREMIUM = 0.02;
    /**
     * Cap rate at the engine's own equilibrium, net of expected rent inflation: the neutral real 10Y (r* plus the base
     * term premium), the base IG spread and the premium, built from the constants the cap rate reads as
     * RESIDENTIAL_NEUTRAL_USER_COST is (5.95%, the low end of the US NCREIF/ACLI 1990-2019 range of ~6-8%).
     */
    public const CRE_NEUTRAL_CAP_RATE = MacroConstants::BASE_NATURAL_RATE + MacroConstants::NS_BASE_TERM_PREMIUM + MacroConstants::BASE_CREDIT_SPREAD + self::CRE_CAP_RATE_RISK_PREMIUM;
    /** Mean-reversion speed of commercial property values toward fundamental equilibrium. */
    public const CRE_MEAN_REVERSION = 0.25;
    /**
     * Stochastic volatility of commercial property valuations (stationary noise ~7% so cap rates, not noise, drive the
     * cycle).
     */
    public const CRE_VOLATILITY = 0.05;
    /** Minimum cap rate floor to prevent division instability in extreme rate environments. */
    public const CRE_MIN_CAP_RATE = 0.03;
    /**
     * Elasticity of real commercial rents to the output gap (DiPasquale-Wheaton rent equation): a -4% gap takes 2% off
     * rents.
     */
    public const CRE_RENT_OUTPUT_GAP_ELASTICITY = 0.50;
    /**
     * Guards on the commercial property index, below a 2009-style 70% fall and above a 2.5x boom; the fitted process
     * does not reach them.
     */
    public const CRE_MIN_INDEX = 30.0;
    /** Upper guard on the commercial property index. */
    public const CRE_MAX_INDEX = 250.0;

    // --- JORGENSON USER COST RESIDENTIAL REAL ESTATE ---
    /**
     * Baseline residential property index value (neutral home affordability).
     *
     * The NOMINAL anchor the fundamental price is built on, not the level the index settles at: the
     * affordability, damage and credit factors multiplying it average below 1, so a run at rest sits near
     * 98. A consumer wanting "have prices moved" reads MacroState::residentialWealthTrend instead.
     */
    public const RESIDENTIAL_BASELINE = 100.0;

    /**
     * Fundamental price per point of unemployment above NAIRU (foreclosure and affordability drag), fitted with the
     * income elasticity below and the dynamics further down, low enough that household credit following the fall
     * through the collateral term does not sustain a slump at the lower bound.
     */
    public const RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY = 2.5;
    /** Elasticity of the fundamental price to the output gap, a transitory income shock; the same fit. */
    public const RESIDENTIAL_INCOME_ELASTICITY = 1.0;
    /** Lower bound multiplier on residential spatial labor demand during deep labor distress. */
    public const RESIDENTIAL_MIN_LABOR_FACTOR = 0.30;
    /** Upper bound multiplier on residential spatial labor demand during peak labor market expansions. */
    public const RESIDENTIAL_MAX_LABOR_FACTOR = 1.80;
    /**
     * Elasticity of the fundamental house price to the user cost: Glaeser, Gottlieb & Gyourko (2010) measure about 7-8%
     * per 100bp fall in real rates, half the ~17% a unit elasticity gives at the 6.85% neutral user cost, so collapsing
     * long rates at the floor do not inflate prices in a slump.
     */
    public const RESIDENTIAL_USER_COST_ELASTICITY = 0.5;
    /**
     * Yearly reversion of real house prices toward their fundamental, fitted with the momentum and volatility below to
     * US real house prices (FHFA / CPI, 1977-2019): annual growth sd 3.86%, autocorrelation 0.71 / 0.33 / 0.00 at one
     * to three years, worst year -9.0%.
     */
    public const RESIDENTIAL_MEAN_REVERSION = 0.25;
    /**
     * Fundamental price per unit of net lending tightening (Duca, Muellbauer & Murphy 2011; Favara & Imbs 2015): the
     * ~80% tightening of a crisis takes a fifth off, the post-crisis decline in Reinhart & Rogoff.
     */
    public const RESIDENTIAL_CREDIT_STANDARDS_ELASTICITY = 0.25;
    /**
     * House price response per unit of credit-to-GDP gap (Favara & Imbs 2015): the return leg of the collateral
     * channel, without which Mian & Sufi's home-equity term is a one-way street.
     */
    public const RESIDENTIAL_CREDIT_SUPPLY_ELASTICITY = 0.25;
    /** Volatility of real house prices around the path the fundamental and momentum set, the same fit. */
    public const RESIDENTIAL_VOLATILITY = 0.018;
    /**
     * Share of the past year's real growth carried into this year's (Case & Shiller 1989; Capozza, Hendershott, Mack &
     * Mayer 2002 serial correlation), the same fit; household credit follows house prices, so it also carries the
     * persistence of new borrowing toward the US's.
     */
    public const RESIDENTIAL_PRICE_MOMENTUM = 0.85;
    /**
     * Horizon (years) of the growth buyers extrapolate: the annual change the serial-correlation regressions are run
     * on.
     */
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
    public const FCI_CREDIT_MEAN = MacroConstants::BASE_CREDIT_SPREAD;
    /** Historical standard deviation for investment-grade credit spreads in FCI normalization. */
    public const FCI_CREDIT_STD = 0.008;
    /** Neutral equity risk premium for FCI normalization, tied to the model's habit-formation baseline. */
    public const FCI_ERP_MEAN = MacroConstants::BASE_EQUITY_RISK_PREMIUM;
    /** Historical standard deviation for equity risk premium in FCI normalization. */
    public const FCI_ERP_STD = 0.015;
    /**
     * Standard deviation of the log currency gap to its trend in FCI normalization: ~10%, the real broad dollar's swing
     * about its mean.
     */
    public const FCI_FX_STD = 0.10;
    /**
     * Neutral 10Y-minus-policy slope for FCI normalization: with policy at neutral the slope is the base ten-year
     * premium.
     */
    public const FCI_SLOPE_MEAN = MacroConstants::NS_BASE_TERM_PREMIUM;
    /** Historical standard deviation for yield curve slope in FCI normalization. */
    public const FCI_SLOPE_STD = 0.012;
    /** Neutral equity volatility for FCI normalization, tied to the model's calm-market vol anchor. */
    public const FCI_VOL_MEAN = MacroConstants::MACRO_VOL_BASE_ANCHOR;
    /** Historical standard deviation for equity market volatility in FCI normalization. */
    public const FCI_VOL_STD = 0.06;
    /** Historical mean benchmark for SLOOS net tightening index in FCI normalization. */
    public const FCI_SLOOS_MEAN = 0.0;
    /** Historical standard deviation for SLOOS net tightening index in FCI normalization. */
    public const FCI_SLOOS_STD = 0.20;
    /**
     * Years the composite is centred over: a decade, as published indices standardise each indicator on its sample mean
     * (Brave & Butters 2011), so conditions that last become the norm and a credit event still reads about +3.
     */
    public const FCI_TREND_YEARS = 10.0;

    // --- Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow ---
    /** Mean-reversion speed (kappa) of deal activity toward fundamental valuation capacity. */
    public const DEAL_ACTIVITY_KAPPA = 1.60;
    /** Stochastic volatility of deal activity volume. */
    public const DEAL_ACTIVITY_SIGMA = 0.15;
    /**
     * Time constant (years) of deal flow's measured trend: a decade spans a full deal cycle, as it does the credit
     * cycle the IG spread's trend averages over.
     */
    public const DEAL_ACTIVITY_TREND_YEARS = CreditFiscalSubsystem::CREDIT_SPREAD_TREND_YEARS;

    // --- Mundell-Fleming Trade Balance & Net Exports ---
    /** Lower bound of the trade balance over GDP: 5.5 points under its structural level. */
    public const MIN_TRADE_BALANCE = MacroConstants::TRADE_BALANCE_BASELINE - 0.055;
    /** Upper bound of the trade balance over GDP: 5.5 points over its structural level. */
    public const MAX_TRADE_BALANCE = MacroConstants::TRADE_BALANCE_BASELINE + 0.055;
    /**
     * Time constant (years) of the trade balance's adjustment to its target: half a year, the J-curve's two-quarter
     * delivery lag on contracts already signed (Magee 1973).
     */
    public const TRADE_BALANCE_ADJUSTMENT_YEARS = 0.5;

    // --- Tobin's Q Housing Investment Dynamics (Poterba 1984, Topel-Rosen 1988) ---
    /**
     * Materials in the replacement cost of a new home: intermediate inputs over inputs plus compensation in
     * single-family construction (BEA 2017 detail Use table, 233411: 0.557).
     */
    public const REPLACEMENT_COST_MATERIALS_SHARE = 0.557;
    /**
     * Metals in it, the part the metals index prices: $19.2bn of primary and fabricated metals in 2019 US new
     * residential construction (NAHB, from the BEA detail tables) over $333bn put in place (Census); other materials
     * move with prices.
     */
    public const REPLACEMENT_COST_METALS_SHARE = 0.058;
    /** Sensitivity of housing starts to Tobin's q ratio (home price valuation vs replacement cost). */
    public const HOUSING_STARTS_Q_SENSITIVITY = 50.0;
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

    // --- Household Formation (Paciorek 2013) ---
    /**
     * Households lost per unit of unemployment gap, in logs: headship -0.36 pts per point at 18-30, -0.08 above; a
     * fifth of adults under 31 at 0.50 households each, so 1pp of gap is 0.28% of households.
     */
    public const HEADSHIP_UNEMPLOYMENT_SEMI_ELASTICITY = 0.28;
    /** Homes started a year over the stock: ~1%, the US 2000-2019 average (UK ~0.7%, Australia ~2%). */
    public const HOUSING_STARTS_TO_STOCK = 0.010;

    // --- Systemic Market Factor ---
    /** Decay time constant (years) of the common equity factor's regime leg, giving a ~20 day trend half-life. */
    public const MARKET_FACTOR_DECAY_TAU_YEARS = 0.08;
    /** Degrees of freedom of the market factor's Student's t shock; 5 is the lowest with a finite fourth moment. */
    public const MARKET_FACTOR_TAIL_DF = 5;
    /** Share of market factor variance carried by the slow regime leg; kept small so beta stays horizon-stable. */
    public const MARKET_FACTOR_REGIME_VARIANCE_SHARE = 0.15;
    /**
     * Mean variance jump on a market-wide crash (Duffie, Pan & Singleton 2000 SVCJ): a crash lifts vol from the 15%
     * anchor to ~16%; at four arrivals a year the jumps fund about a fifth of the long-run variance, leaving the
     * diffusion an anchor well clear of the 8% floor.
     */
    public const SYSTEMIC_JUMP_VARIANCE_MEAN = 0.003;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /**
         * Records what set the house-price fundamental and consumer sentiment. Null in a test or a headless harness,
         * off everywhere the ticker is not.
         */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
    ) {}

    /**
     * DiPasquale-Wheaton (1996) Two-Quadrant Commercial Real Estate (CRE) Econometric Model.
     *
     * Couples the spatial tenant market (unemployment occupancy contraction) with the capital asset
     * market (cap rate = 10Y yield - expected inflation + credit spread + CRE risk premium) with physical market
     * adjustment lag. Rents keep pace with expected inflation, so the Gordon cap rate nets it out of the nominal yield.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCommercialPropertyIndex(MacroState $state, float $dt): void
    {
        // Okun's law bounds excess unemployment to about +-8pp and the output gap is clamped at -12%/+10%, so both
        // factors stay within ~0.75-1.25 and 0.94-1.05 without a clamp of their own.
        $excessUnemployment = $state->unemploymentRateEma - $state->nairu;
        $occupancyFactor = 1.0 - ($excessUnemployment * self::CRE_OCCUPANCY_UNEMPLOYMENT_SENSITIVITY);

        // DiPasquale-Wheaton (1996) commercial real estate real rent level with demand.
        $rentFactor = 1.0 + ($state->outputGapEma * self::CRE_RENT_OUTPUT_GAP_ELASTICITY);

        // The space market: what a lease signed today earns per occupied square foot. Landlords' rent rolls read this,
        // not the asset price, which capitalises rent and occupancy at the cap rate.
        $state->commercialMarketRent = $rentFactor;

        $capRate = max(self::CRE_MIN_CAP_RATE, $state->yield10yEma - $state->tipsBreakevenEma + $state->macroCreditSpreadEma + self::CRE_CAP_RATE_RISK_PREMIUM);
        $fundamentalValue = MacroConstants::CRE_BASELINE * $occupancyFactor * $rentFactor * (self::CRE_NEUTRAL_CAP_RATE / $capRate);

        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: $state->commercialPropertyIndex,
            kappa: self::CRE_MEAN_REVERSION,
            theta: StochasticProcesses::schwartzThetaForMean($fundamentalValue, self::CRE_MEAN_REVERSION, self::CRE_VOLATILITY),
            sigma: self::CRE_VOLATILITY,
            dt: $dt,
            dW: $dW,
        );

        $state->commercialPropertyIndex = max(self::CRE_MIN_INDEX, min(self::CRE_MAX_INDEX, $newIndex));
    }

    /**
     * Jorgenson (1963) User Cost of Capital & Spatial Housing Affordability Equilibrium Model.
     *
     * Calculates fundamental home prices from user cost of housing capital (mortgage rate + taxes - expected inflation)
     * and household real disposable income affordability, with sticky physical mean reversion. Credit conditions enter
     * the fundamental as in Duca, Muellbauer & Murphy (2011): the price a buyer can pay is the price a lender will
     * finance, so net tightening in bank standards lowers it and loosening lifts it. The population an immigration
     * regime adds over the structural path bids for the same land, one for one (Saiz 2007); a tax on foreign buyers
     * takes the part of values their demand holds up (foreignBuyerPriceFactor()). A green belt makes prices answer
     * income and population more strongly, as stricter planning does across English authorities (Hilber & Vermeulen
     * 2016); the index has no trend in real income, so a green belt deepens its booms and slumps and the price of
     * immigration rather than adding a drift of its own.
     *
     * @param MacroState $state             Current macroeconomic state.
     * @param float      $expectedInflation Expected inflation (MonetaryPolicySubsystem::calculateExpectedInflation),
     *                                      the same measure the Taylor rule and IS curve use.
     * @param float      $dt                Time increment in years.
     */
    public function calculateResidentialPropertyIndex(MacroState $state, float $expectedInflation, float $dt): void
    {
        $userCost = $this->housingUserCost($state, $expectedInflation);

        $excessUnemployment = $state->unemploymentRateEma - $state->nairu;
        $laborFactor = 1.0 - ($excessUnemployment * self::RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY);
        $incomeLift = self::greenBeltElasticityLift($state->greenBeltStringency, $state->outputGapEma);
        $incomeFactor = 1.0 + ($state->outputGapEma * (self::RESIDENTIAL_INCOME_ELASTICITY + $incomeLift));
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
        $immigrationLift = self::greenBeltElasticityLift($state->greenBeltStringency, $state->immigrationPopulationShift);
        $immigrationFactor = exp((self::IMMIGRATION_HOUSING_ELASTICITY + $immigrationLift) * $state->immigrationPopulationShift);
        $foreignBuyerFactor = self::foreignBuyerPriceFactor($state->foreignBuyerTaxRate);
        $fundamentalPrice = self::RESIDENTIAL_BASELINE * max(0.30, min(2.50, $affordabilityFactor * max(0.5, $damageFactor) * max(0.5, $creditConditionsFactor) * max(0.5, $creditSupplyFactor) * $immigrationFactor * $foreignBuyerFactor));

        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: $state->residentialPropertyIndex,
            kappa: self::RESIDENTIAL_MEAN_REVERSION,
            theta: StochasticProcesses::schwartzThetaForMean($fundamentalPrice, self::RESIDENTIAL_MEAN_REVERSION, self::RESIDENTIAL_VOLATILITY),
            sigma: self::RESIDENTIAL_VOLATILITY,
            dt: $dt,
            dW: $dW,
        );
        // Capozza, Hendershott, Mack & Mayer (2002): real house prices carry serial correlation on top of their
        // reversion to fundamental, so a boom keeps rising after its cause has passed and overshoots.
        $newIndex *= exp(self::RESIDENTIAL_PRICE_MOMENTUM * $state->residentialPriceMomentum * $dt);

        $previousIndex = $state->residentialPropertyIndex;
        $state->residentialPropertyIndex = max(self::RESIDENTIAL_MIN_INDEX, min(self::RESIDENTIAL_MAX_INDEX, $newIndex));
        $state->residentialPriceMomentum = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->residentialPriceMomentum,
            targetValue: log($state->residentialPropertyIndex / $previousIndex) / $dt,
            dt: $dt,
            lagTimeConstant: self::RESIDENTIAL_MOMENTUM_HORIZON_YEARS,
        );

        // The fundamental is a product of factors, so its log splits exactly into theirs; the floors and the band
        // land in the clamp term. The index's distance from it is the sticky part the reversion has not closed.
        if ($this->diagnostics?->isEnabled()) {
            $logFundamental = log($fundamentalPrice / self::RESIDENTIAL_BASELINE);
            $terms = [
                'userCost' => self::RESIDENTIAL_USER_COST_ELASTICITY * log(self::RESIDENTIAL_NEUTRAL_USER_COST / $userCost),
                'unemployment' => log(max(1e-9, $laborFactor)),
                'income' => log(max(1e-9, 1.0 + ($state->outputGapEma * self::RESIDENTIAL_INCOME_ELASTICITY))),
                'catastropheDamage' => log(max(0.5, $damageFactor)),
                'lendingStandards' => log(max(0.5, $creditConditionsFactor)),
                'creditSupply' => log(max(0.5, $creditSupplyFactor)),
                'immigration' => self::IMMIGRATION_HOUSING_ELASTICITY * $state->immigrationPopulationShift,
                'foreignBuyers' => log($foreignBuyerFactor),
                'greenBelt' => log(max(1e-9, $incomeFactor) / max(1e-9, 1.0 + ($state->outputGapEma * self::RESIDENTIAL_INCOME_ELASTICITY)))
                    + ($immigrationLift * $state->immigrationPopulationShift),
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
        $valuation = -self::foreignValuationDuration() * ($state->foreignEquityRiskPremium - MacroConstants::BASE_EQUITY_RISK_PREMIUM);
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
        $annualPersistence = TimeSeries::calculateAr2Autocorrelation(self::MAINLAND_GAP_AR1, self::MAINLAND_GAP_AR2, self::MAINLAND_QUARTERS_PER_YEAR);

        return 1.0 / (1.0 - (self::CAMPBELL_SHILLER_RHO * $annualPersistence));
    }

    /**
     * The habit premium at an output gap, floored at a positive premium and capped at the ceiling it has always had.
     */
    private function habitEquityRiskPremium(float $outputGapEma): float
    {
        $habitErp = MacroConstants::BASE_EQUITY_RISK_PREMIUM * exp(-self::HABIT_RISK_AVERSION_COEFF * $outputGapEma);

        return max(self::MIN_EQUITY_RISK_PREMIUM, min(0.12, $habitErp));
    }

    /**
     * Engle, Ghysels & Sohn (2013) Spline-GARCH Macro Link with SVCJ Contemporaneous Jumps (Duffie, Pan & Singleton
     * 2000).
     *
     * Models aggregate equity implied volatility using continuous macroeconomic fundamental scaling
     * (output gap, the excess bond premium, yield curve slope, policy uncertainty) driven by an Andersen (2008)
     * QE variance step. Its innovation loads on the market return at the Heston rho, and the variance jumps
     * land with the market-wide price jumps (SVCJ: Duffie, Pan & Singleton 2000; Eraker, Johannes & Polson 2003).
     * It reads the premium the credit spread is built on, not the spread, which reads volatility back.
     *
     * @param MacroState $state            Current macroeconomic state.
     * @param float      $dt               Time increment in years.
     * @param float      $varianceJump     Variance jump the market-wide price jump drew this tick
     *                                     (updateSystemicMarketFactor()).
     * @param float|null $returnInnovation This tick's i.i.d. market return innovation, unit variance; null leaves the
     *                                     variance uncorrelated.
     * @return float Implied equity market volatility (clamped between 8% and 80%).
     */
    public function calculateMarketVolatility(MacroState $state, float $dt, float $varianceJump = 0.0, ?float $returnInnovation = null): float
    {
        $currentMarketVol = $state->marketVolatility;

        $policyUncertaintyLog = log(max(1.0, $state->policyUncertaintyIndexEma) / MacroConstants::EPU_BASELINE);
        $macroDriver = (-$state->outputGap * self::MACRO_VOL_OUTPUT_GAP_SENSITIVITY)
            + ($state->excessBondPremium * self::MACRO_VOL_PREMIUM_SENSITIVITY)
            + (-min(0.0, $state->structuralSlope) * self::MACRO_VOL_SLOPE_SENSITIVITY)
            + ($policyUncertaintyLog * self::MACRO_VOL_EPU_SENSITIVITY);

        $longTermVol = min(
            self::MACRO_VOL_MAX_BASELINE,
            max(self::MACRO_VOL_MIN_BASELINE, MacroConstants::MACRO_VOL_BASE_ANCHOR * exp($macroDriver)),
        );

        $currentVar = $currentMarketVol * $currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        $adjustedTheta = max(0.0001, $longTermVar - self::jumpVarianceDrag());

        $varianceShock = null;
        if ($returnInnovation !== null) {
            $loading = self::leverageLoading();
            $varianceShock = ($loading * $returnInnovation) + (sqrt(1.0 - ($loading * $loading)) * $this->mathUtility->generateStandardNormal());
        }

        $nextVar = $this->mathUtility->calculateQEVarianceStep($currentVar, $adjustedTheta, self::MACRO_VOL_KAPPA, self::MACRO_VOL_SIGMA, $dt, $varianceShock);
        $nextVar += $varianceJump;

        return max(self::MACRO_VOL_FLOOR, min(self::MACRO_VOL_CEILING, sqrt($nextVar)));
    }

    /**
     * Loading of the variance shock on the i.i.d. leg of the market innovation that puts its correlation with the whole
     * return at MACRO_VOL_LEVERAGE_RHO: that leg carries 1 - MARKET_FACTOR_REGIME_VARIANCE_SHARE of the return
     * variance.
     */
    public static function leverageLoading(): float
    {
        return max(-1.0, min(1.0, self::MACRO_VOL_LEVERAGE_RHO / sqrt(1.0 - self::MARKET_FACTOR_REGIME_VARIANCE_SHARE)));
    }

    /**
     * Long-run variance the market-wide jumps supply, which the diffusion's anchor must give back.
     *
     * The jumps are additive: for dv = kappa*(theta_adj - v)dt + sigma*sqrt(v)dW + dJ with arrivals at
     * lambda and mean size m, the stationary mean is theta_adj + lambda*m/kappa. So a process that is to
     * revert to MACRO_VOL_BASE_ANCHOR has to be handed an anchor lower by exactly lambda*m/kappa, and the
     * divisor is the mean-reversion speed and nothing else.
     *
     * The mean size is the jump's own asymmetry -- a crash spikes variance harder than a rally, at the share
     * MathUtility applies when it draws one -- so the two must be read off the same constant or the
     * compensation silently desynchronizes from the thing it compensates for.
     *
     * Public so App\Tests\Financial\MacroVolatilityAnchorTest can assert the round trip against it.
     *
     * @return float Variance per unit time the jump process contributes in the long run.
     */
    public static function jumpVarianceDrag(): float
    {
        $meanVarianceJump = StochasticProcesses::meanVarianceJump(
            MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP,
            self::SYSTEMIC_JUMP_VARIANCE_MEAN,
        );

        return (MacroConstants::SYSTEMIC_JUMP_INTENSITY * $meanVarianceJump) / self::MACRO_VOL_KAPPA;
    }

    /**
     * The systemic market factor and the market-wide jump: the one driver every equity price shares.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return array{returnInnovation: float, varianceJump: float} The return's i.i.d. innovation and the jump's
     *                                                             variance jump, for calculateMarketVolatility().
     */
    public function updateSystemicMarketFactor(MacroState $state, float $dt): array
    {
        // Two-factor equity market innovation: AR(1) regime persistence plus Student's t heavy tails.
        $marketFactorPhi = exp(-$dt / self::MARKET_FACTOR_DECAY_TAU_YEARS);
        $state->marketZLatent = $this->mathUtility->generatePersistentZ($state->marketZLatent, $marketFactorPhi);

        $regimeShock = $state->marketZLatent * StochasticProcesses::calculatePersistenceVarianceScale($marketFactorPhi);
        $tailShock = $this->mathUtility->generateStudentsT(self::MARKET_FACTOR_TAIL_DF);

        $state->marketZ = (sqrt(self::MARKET_FACTOR_REGIME_VARIANCE_SHARE) * $regimeShock)
            + (sqrt(1.0 - self::MARKET_FACTOR_REGIME_VARIANCE_SHARE) * $tailShock);

        // Kou (2002) double-exponential jump diffusion for systemic equity market crashes.
        $systemicJump = $this->mathUtility->calculateSVJJJumps(
            lambda: MacroConstants::SYSTEMIC_JUMP_INTENSITY,
            pUp: MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP,
            etaUp: MacroConstants::SYSTEMIC_JUMP_ETA_UP,
            etaDown: MacroConstants::SYSTEMIC_JUMP_ETA_DOWN,
            muV: self::SYSTEMIC_JUMP_VARIANCE_MEAN,
            dt: $dt,
        );
        $state->marketJumpMultiplier = $systemicJump['price_multiplier'];

        return ['returnInnovation' => $tailShock, 'varianceJump' => $systemicJump['var_jump']];
    }

    /**
     * Mundell-Fleming Open Economy (IS-LM-BOP) & Uncovered Interest Parity (Dornbusch 1976).
     *
     * The currency is an asset price: it moves to its fundamental the moment the fundamental moves, and only its
     * departures from that fundamental, the purchasing-power deviations (Rogoff 1996), decay slowly. The fundamental
     * is the parity level with two further real-world loadings, because a rate differential alone leaves the
     * currency deaf to the two events that move it most:
     *   log(FX* / 100) = UIP * ((i - pi_e) - (i* - pi*_e)) - TOT * ImportBasket + Haven * max(0, vol - threshold) - Fiscal * spread
     * The differential is in real rates (Frankel 1979): a rate that only keeps pace with expected inflation does not
     * strengthen the currency.
     * A dearer import basket is a terms-of-trade loss for a district that buys its commodities, and a volatility
     * panic bids its currency the way it already bids its bonds. The index is that fundamental times a Schwartz (1997)
     * deviation reverting to one. A higher index is a STRONGER currency throughout, which is why net exports and the
     * trade balance both read it negatively.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateExchangeRate(MacroState $state, float $dt): void
    {
        $sigma = self::exchangeRateDeviationVolatility();
        $state->exchangeRateDeviation = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: $state->exchangeRateDeviation,
            kappa: self::EXCHANGE_RATE_MEAN_REVERSION,
            theta: StochasticProcesses::schwartzThetaForMean(1.0, self::EXCHANGE_RATE_MEAN_REVERSION, $sigma),
            sigma: $sigma,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal(),
        );

        $state->exchangeRateIndex = max(60.0, min(160.0, self::exchangeRateFundamental($state) * $state->exchangeRateDeviation));
    }

    /**
     * The deviation's diffusion: the variance of a small open economy's quarterly currency move left once the
     * fundamental's own share is taken out, as the log-OU's instantaneous sigma. The two move independently, so the
     * currency's quarterly variance is their sum.
     */
    public static function exchangeRateDeviationVolatility(): float
    {
        $residual = sqrt(max(0.0, (MacroConstants::EXCHANGE_RATE_QUARTERLY_VOLATILITY ** 2) - (self::EXCHANGE_RATE_FUNDAMENTAL_QUARTERLY_SD ** 2)));

        return StochasticProcesses::ouSigmaForIncrementSd($residual, self::EXCHANGE_RATE_MEAN_REVERSION, self::EXCHANGE_RATE_VOLATILITY_HORIZON_YEARS);
    }

    /**
     * The currency's fundamental level: parity on the rate differential, the terms of trade, the safe-haven bid and
     * the sovereign risk discount. Parity reads the short rate the market expects, not the decision rounded at a
     * meeting, so a step everyone saw coming does not move the currency on the day (as the curve's short end).
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Fundamental exchange rate index.
     */
    public static function exchangeRateFundamental(MacroState $state): float
    {
        return self::exchangeRateFundamentalAt(
            MonetaryPolicySubsystem::marketShortRate($state),
            $state->tipsBreakeven,
            self::mainlandMarketRate($state),
            self::mainlandBreakeven(self::mainlandMarketCoreYearOnYear($state)),
            $state->energyPriceIndexEma,
            $state->industrialMetalsIndexEma,
            $state->marketVolatilityEma,
            $state->sovereignRiskSpreadEma,
        );
    }

    /**
     * The fundamental on its inputs, for the readers that hold them as values rather than as a state.
     *
     * @param float $policyRate              District short rate, as the market expects it.
     * @param float $expectedInflation       District expected inflation (ten-year breakeven).
     * @param float $foreignPolicyRate       Mainland policy rate.
     * @param float $foreignExpectedInflation Mainland expected inflation (ten-year breakeven).
     * @param float $energyPriceIndexEma     Smoothed energy price index.
     * @param float $industrialMetalsIndexEma Smoothed industrial metals index.
     * @param float $marketVolatilityEma     Smoothed equity volatility.
     * @param float $sovereignRiskSpreadEma  Smoothed sovereign risk spread.
     * @return float Fundamental exchange rate index.
     */
    public static function exchangeRateFundamentalAt(
        float $policyRate,
        float $expectedInflation,
        float $foreignPolicyRate,
        float $foreignExpectedInflation,
        float $energyPriceIndexEma,
        float $industrialMetalsIndexEma,
        float $marketVolatilityEma,
        float $sovereignRiskSpreadEma,
    ): float {
        $rateDiff = ($policyRate - $expectedInflation) - ($foreignPolicyRate - $foreignExpectedInflation);

        // Harrod-Balassa-Samuelson terms-of-trade import price effect on real exchange rates.
        $energyShift = ($energyPriceIndexEma / MacroConstants::ENERGY_BASELINE) - 1.0;
        $metalsShift = ($industrialMetalsIndexEma / MacroConstants::METALS_BASELINE) - 1.0;
        $importBillShift = (MacroConstants::PPI_ENERGY_WEIGHT * $energyShift) + (MacroConstants::PPI_METALS_WEIGHT * $metalsShift);
        $termsOfTradeShift = self::FX_TERMS_OF_TRADE_SENSITIVITY * $importBillShift;

        // Caballero & Krishnamurthy (2008) safe-haven currency bid under global volatility flight to safety.
        $panic = max(0.0, $marketVolatilityEma - MacroConstants::FLIGHT_TO_SAFETY_VOL_THRESHOLD);
        $safeHavenBid = self::FX_SAFE_HAVEN_SENSITIVITY * $panic;

        // Della Corte et al. (2016) sovereign credit default risk discount on currency valuation.
        $fiscalRiskDiscount = self::FX_FISCAL_RISK_SENSITIVITY * $sovereignRiskSpreadEma;

        return self::EXCHANGE_RATE_BASELINE * exp(
            (self::UIP_SENSITIVITY * $rateDiff) - $termsOfTradeShift + $safeHavenBid - $fiscalRiskDiscount,
        );
    }

    /**
     * The mainland's year-on-year core inflation: its four quarters of annualized log inflation averaged.
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Mainland core inflation, year on year.
     */
    public static function mainlandCoreYearOnYear(MacroState $state): float
    {
        return ($state->foreignCoreInflation + $state->foreignCoreInflationLag1 + $state->foreignCoreInflationLag2 + $state->foreignCoreInflationLag3) / 4.0;
    }

    /**
     * The mainland's ten-year breakeven: the mainland is the US process, so it is the US fit the District's breakeven
     * uses (Gurkaynak, Sack & Wright 2010) on its core inflation; it carries no headline pass-through term.
     *
     * @param float $coreYearOnYear Mainland core inflation, year on year.
     * @return float Mainland ten-year breakeven inflation.
     */
    public static function mainlandBreakeven(float $coreYearOnYear): float
    {
        return MacroConstants::TARGET_INFLATION + (MacroAggregateSubsystem::BREAKEVEN_CORE_LOADING * ($coreYearOnYear - MacroConstants::TARGET_INFLATION));
    }

    /**
     * Consumer sentiment as the Michigan index answers it: a level set by what households see -- unemployment above its
     * natural rate, the year's CPI inflation and whether joblessness is rising -- plus a persistent mood the
     * fundamentals do not explain (Barsky & Sims 2012 find it carries news; Akerlof & Shiller 2009 call it animal
     * spirits).
     *
     * Coefficients are a Newey-West regression of UMCSENT on FRED data, monthly 1978-2019, and the mood is that fit's
     * residual as an Ornstein-Uhlenbeck process. Every term is two-sided, so with unemployment at NAIRU, inflation on
     * target and joblessness flat the index sits at SENTIMENT_TREND_LEVEL.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateConsumerSentiment(MacroState $state, float $dt): void
    {
        $inflationYearOnYear = MacroAggregateSubsystem::consumerPriceInflationYearOnYear($state->consumerPriceMonthly) ?? $state->inflationEma;
        $unemploymentChange = LaborMarketSubsystem::unemploymentChangeYearOnYear($state->unemploymentMonthly) ?? 0.0;

        $slack = self::SENTIMENT_SLACK_LOADING * ($state->unemploymentRate - $state->nairu);
        $inflation = self::SENTIMENT_INFLATION_LOADING * ($inflationYearOnYear - MacroConstants::TARGET_INFLATION);
        $joblessness = self::SENTIMENT_UNEMPLOYMENT_CHANGE_LOADING * $unemploymentChange;
        $fundamentalSentiment = MacroConstants::SENTIMENT_TREND_LEVEL + $slack + $inflation + $joblessness;

        if ($this->diagnostics?->isEnabled()) {
            $this->diagnostics->recordLevel('households', 'sentimentFundamental', [
                'trend' => MacroConstants::SENTIMENT_TREND_LEVEL,
                'slack' => $slack,
                'inflation' => $inflation,
                'unemploymentChange' => $joblessness,
            ], $fundamentalSentiment, $dt);
        }

        $state->consumerSentimentMood = StochasticProcesses::calculateOrnsteinUhlenbeckStep(
            current: $state->consumerSentimentMood,
            kappa: self::SENTIMENT_MOOD_MEAN_REVERSION,
            stationarySd: self::SENTIMENT_MOOD_SD,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal(),
        );
        $state->consumerSentimentIndex = max(self::SENTIMENT_FLOOR, min(self::SENTIMENT_CEILING, $fundamentalSentiment + $state->consumerSentimentMood));
    }

    /**
     * Financial Conditions Index (FCI) Composite Model (Goldman Sachs / Chicago Fed).
     *
     * Constructs a normalized macroeconomic financial conditions index tracking wholesale credit spreads,
     * equity risk premium, real exchange rate deviations, term structure slope, and equity market volatility:
     *   FCI > 0 indicates restrictive financial conditions; FCI < 0 indicates accommodative conditions.
     * The weights are loadings on unit-variance components whose stress moves together, so the plain weighted sum is
     * the composite: a 2008-type credit event reads ~+3 sigma, a mild recession ~+1.4, a boom ~-0.6 (Chicago Fed NFCI
     * ranges). The currency is read against its own trend, since the index settles away from its nominal baseline. Like
     * the NFCI, the index is a same-period reading of its components, which are already quarterly averages, so no lag
     * is added. The other components are scored against the constants they are built on, and they do not all settle
     * there, so the composite is published net of its own decade-long average, as the NFCI is centred on its sample's.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateFinancialConditionsIndex(MacroState $state, float $dt): void
    {
        $creditZ = ($state->macroCreditSpreadEma - self::FCI_CREDIT_MEAN) / self::FCI_CREDIT_STD;
        $erpZ = ($state->equityRiskPremium - self::FCI_ERP_MEAN) / self::FCI_ERP_STD;
        $fxZ = MacroAggregateSubsystem::realExchangeRateGap($state->exchangeRateIndexEma, $state->exchangeRateTrend) / self::FCI_FX_STD;
        $slopeZ = - ($state->nsSlopeEma - self::FCI_SLOPE_MEAN) / self::FCI_SLOPE_STD;
        $volZ = ($state->marketVolatilityEma - self::FCI_VOL_MEAN) / self::FCI_VOL_STD;
        $sloosZ = ($state->sloosTighteningIndexEma - self::FCI_SLOOS_MEAN) / self::FCI_SLOOS_STD;

        $composite = (self::FCI_CREDIT_SPREAD_WEIGHT * $creditZ)
            + (self::FCI_ERP_WEIGHT * $erpZ)
            + (self::FCI_EXCHANGE_RATE_WEIGHT * $fxZ)
            + (self::FCI_YIELD_SLOPE_WEIGHT * $slopeZ)
            + (self::FCI_VOLATILITY_WEIGHT * $volZ)
            + (self::FCI_SLOOS_WEIGHT * $sloosZ);
        $state->financialConditionsTrend += (1.0 - exp(-$dt / self::FCI_TREND_YEARS)) * ($composite - $state->financialConditionsTrend);
        $state->financialConditionsIndex = $composite - $state->financialConditionsTrend;
    }

    /**
     * Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow Model.
     *
     * Evaluates global investment banking advisory, private equity LBO, and IPO volume
     * driven by valuation liquidity: the world's equity risk premium (the deals the District's banks advise on are
     * mostly the mainland's), and the District's high-yield spreads and volatility.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCapitalMarketsDealIndex(MacroState $state, float $dt): void
    {
        $dW = $this->mathUtility->generateStandardNormal();
        $currentDealIndex = $state->dealActivityIndex > 0.0 ? $state->dealActivityIndex : MacroConstants::DEAL_ACTIVITY_BASELINE;

        $state->dealActivityIndex = MacroTransmission::calculateCapitalMarketsDealIndexStep(
            currentDealIndex: $currentDealIndex,
            equityRiskPremium: $state->foreignEquityRiskPremium,
            hyCreditSpread: $state->highYieldCreditSpread,
            marketVolatility: $state->marketVolatility,
            dt: $dt,
            dW: $dW,
            kappa: self::DEAL_ACTIVITY_KAPPA,
            sigma: self::DEAL_ACTIVITY_SIGMA,
            policyUncertaintyIndex: $state->policyUncertaintyIndexEma,
        );
        $state->dealActivityIndexTrend += (1.0 - exp(-$dt / self::DEAL_ACTIVITY_TREND_YEARS)) * ($state->dealActivityIndex - $state->dealActivityIndexTrend);
    }

    /**
     * The mainland (the United States the district split from): its output gap, its core inflation, the funds rate
     * the Fed sets on them, and the global demand composite the district's commodities clear against.
     *
     * A compact model rather than a second engine: the Rudebusch & Svensson (1999) backward-looking gap and Phillips
     * curve re-estimated on US data 1985-2019, and the Fed's Clarida, Gali & Gertler (2000) smoothed rule fitted over
     * Greenspan and Bernanke. Simulated together they give the CBO gap's persistence, core PCE's level and spread and
     * the funds rate's. The equations are quarterly and are stepped at each quarter's
     * turn, which is also how the district sees them: a statistical release and a rate decision. The district's own
     * spending moves the mainland gap's mean a little (two-country Mundell-Fleming, Obstfeld & Rogoff 1996), and
     * everything else -- the currency through the rate differential, the trade balance and exports through the
     * mainland gap, and the district's output through the regional factor in it -- reads these series. The gap's
     * shocks are Gaussian but for a rare crash, the region's shared slump.
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

        // World demand weighs each economy at its share of world GDP: the District, the mainland, and the rest. The
        // gaps go in as they stand; globalDemandGapEma is the one smoothing stage, so commodities answer within a
        // quarter.
        $state->globalDemandGap = (self::DOMESTIC_DEMAND_WEIGHT * $state->outputGap)
            + (self::MAINLAND_DEMAND_WEIGHT * $state->foreignOutputGap)
            + ((1.0 - self::DOMESTIC_DEMAND_WEIGHT - self::MAINLAND_DEMAND_WEIGHT) * $state->worldActivityGap);
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
        // Its reader takes log(index / baseline) (MacroAggregateSubsystem::alliedDefenseGap()), so the log centres on
        // the baseline: the mobilisations' log drift lambda mu / kappa is netted out (Merton 1976), then Schwartz's
        // alpha.
        $jumpLogShift = self::ALLIED_MOBILISATION_PROBABILITY * self::ALLIED_MOBILISATION_MEAN / self::ALLIED_DEFENSE_MEAN_REVERSION;
        $baseProcess = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: $state->alliedDefenseSpendingIndex,
            kappa: self::ALLIED_DEFENSE_MEAN_REVERSION,
            theta: StochasticProcesses::schwartzThetaForLogMean(MacroConstants::ALLIED_DEFENSE_BASELINE * exp(-$jumpLogShift), self::ALLIED_DEFENSE_MEAN_REVERSION, self::ALLIED_DEFENSE_VOLATILITY),
            sigma: self::ALLIED_DEFENSE_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal(),
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::ALLIED_MOBILISATION_PROBABILITY,
            jumpMean: self::ALLIED_MOBILISATION_MEAN,
            jumpVol: self::ALLIED_MOBILISATION_VOL,
            dt: $dt,
        );

        $state->alliedDefenseSpendingIndex = max(self::MIN_ALLIED_DEFENSE_INDEX, min(self::MAX_ALLIED_DEFENSE_INDEX, $baseProcess * $jumpData['multiplier']));
        if ($jumpData['exponent'] !== null) {
            $this->diagnostics?->recordEvent('alliedMobilisation', $jumpData['exponent']);
        }
    }

    /**
     * Quarters completed by a simulated time; the tolerance absorbs the clock's accumulated rounding at a quarter's
     * turn.
     */
    private static function quarterCount(float $totalTime): int
    {
        return (int) floor(($totalTime * self::MAINLAND_QUARTERS_PER_YEAR) + 1e-9);
    }

    /**
     * One quarter of the mainland: the gap on its two lags around the mean the district's imports set, core inflation
     * on its four lags and last quarter's gap, then the Fed's partial adjustment toward its rule on four-quarter core
     * inflation and the new gap, floored at the effective lower bound. The rest of the world's quarter follows.
     */
    private function stepMainlandQuarter(MacroState $state): void
    {
        $gapDraw = $this->mathUtility->generateStandardNormal();
        $inflationShock = self::MAINLAND_INFLATION_SIGMA * $this->mathUtility->generateStandardNormal();
        $rateShock = self::FED_RULE_SIGMA * $this->mathUtility->generateStandardNormal();
        $gapShock = (self::MAINLAND_GAP_SIGMA * $gapDraw) + $this->mainlandCrash();
        $next = self::mainlandNextQuarter($state, $gapShock, $inflationShock, $rateShock);

        $state->foreignOutputGapLag = $state->foreignOutputGap;
        $state->foreignOutputGap = $next['gap'];

        $state->foreignCoreInflationLag3 = $state->foreignCoreInflationLag2;
        $state->foreignCoreInflationLag2 = $state->foreignCoreInflationLag1;
        $state->foreignCoreInflationLag1 = $state->foreignCoreInflation;
        $state->foreignCoreInflation = $next['inflation'];

        $state->foreignPolicyRate = $next['rate'];

        // The rest of the world: a one-factor model, the mainland's cycle at its loading plus an AR(1) of its own.
        $state->worldActivityIdiosyncratic = (self::WORLD_GAP_AR1 * $state->worldActivityIdiosyncratic)
            + (self::WORLD_GAP_SIGMA * $this->mathUtility->generateStandardNormal());
        $state->worldActivityGap = max(-self::MAX_WORLD_GAP, min(self::MAX_WORLD_GAP, (self::WORLD_MAINLAND_LOADING * $state->foreignOutputGap) + $state->worldActivityIdiosyncratic));
    }

    /**
     * The quarter's crash: the region's rare shared slump (Barro 2006), a Merton (1976) jump down in the gap with a
     * lognormal size around 2008Q4's, less its expected loss so the gap's mean is unmoved. The arrival and the size are
     * drawn every quarter, so no later draw depends on whether one struck.
     */
    private function mainlandCrash(): float
    {
        $probability = self::mainlandCrashProbability();
        $strikes = $this->mathUtility->checkProbability($probability);
        $size = $this->mathUtility->calculateLogNormalSynergy(log(self::MAINLAND_CRASH_MEAN) - ((self::MAINLAND_CRASH_LOG_SD ** 2) / 2.0), self::MAINLAND_CRASH_LOG_SD);
        if ($strikes) {
            $this->diagnostics?->recordEvent('mainlandCrash', -$size);
        }

        return ($strikes ? -$size : 0.0) + ($probability * self::MAINLAND_CRASH_MEAN);
    }

    /** Chance a crash strikes the mainland in a given quarter. */
    private static function mainlandCrashProbability(): float
    {
        return self::MAINLAND_CRASH_INTENSITY / self::MAINLAND_QUARTERS_PER_YEAR;
    }

    /**
     * Sd of the mainland gap's whole quarterly innovation: the Gaussian part and the compensated crash, an arrival
     * times a lognormal size (variance p E[X^2] - p^2 E[X]^2). It is the regression's residual sd, 0.490pp.
     */
    public static function mainlandGapInnovationSd(): float
    {
        $probability = self::mainlandCrashProbability();
        $crashVariance = ($probability * (self::MAINLAND_CRASH_MEAN ** 2) * exp(self::MAINLAND_CRASH_LOG_SD ** 2))
            - (($probability * self::MAINLAND_CRASH_MEAN) ** 2);

        return sqrt((self::MAINLAND_GAP_SIGMA ** 2) + $crashVariance);
    }

    /**
     * The mainland's next quarter on given shocks: the gap, core inflation and the policy rate the step above sets.
     * Zero shocks give the quarter the market expects.
     *
     * @return array{gap: float, inflation: float, rate: float}
     */
    private static function mainlandNextQuarter(MacroState $state, float $gapShock, float $inflationShock, float $rateShock): array
    {
        // The fitted cycle already holds its trading partners' answer to it, the district's among them, so the mean
        // reads the district's own spending: its domestic demand, not the part of its gap that is the mainland's cycle
        // come back through exports and the regional factor.
        $spilloverMean = self::FOREIGN_IMPORT_SPILLOVER * $state->domesticDemandGapEma;
        $lastGap = $state->foreignOutputGap;
        $gap = ((1.0 - self::MAINLAND_GAP_AR1 - self::MAINLAND_GAP_AR2) * $spilloverMean)
            + (self::MAINLAND_GAP_AR1 * $lastGap)
            + (self::MAINLAND_GAP_AR2 * $state->foreignOutputGapLag)
            + $gapShock;
        $gap = max(-self::MAX_FOREIGN_GAP, min(self::MAX_FOREIGN_GAP, $gap));

        $lags = [$state->foreignCoreInflation, $state->foreignCoreInflationLag1, $state->foreignCoreInflationLag2, $state->foreignCoreInflationLag3];
        $inflation = (1.0 - array_sum(self::MAINLAND_INFLATION_LAGS)) * MacroConstants::TARGET_INFLATION;
        foreach (self::MAINLAND_INFLATION_LAGS as $j => $coefficient) {
            $inflation += $coefficient * $lags[$j];
        }
        $inflation += (self::MAINLAND_INFLATION_GAP_SLOPE * $lastGap) + $inflationShock;

        // Four quarters of annualized log inflation average to the year-on-year rate the rule is fitted on.
        $yearOnYear = ($inflation + $state->foreignCoreInflation + $state->foreignCoreInflationLag1 + $state->foreignCoreInflationLag2) / 4.0;
        $ruleRate = MacroConstants::MAINLAND_NEUTRAL_RATE
            + (self::FED_RULE_INFLATION_RESPONSE * ($yearOnYear - MacroConstants::TARGET_INFLATION))
            + (self::FED_RULE_GAP_RESPONSE * $gap);
        $rate = (self::FED_RULE_SMOOTHING * $state->foreignPolicyRate)
            + ((1.0 - self::FED_RULE_SMOOTHING) * $ruleRate)
            + $rateShock;

        return [
            'gap' => $gap,
            'inflation' => $inflation,
            'rate' => max(MacroConstants::EFFECTIVE_LOWER_BOUND, min(self::MAX_FOREIGN_POLICY_RATE, $rate)),
        ];
    }

    /** Share of the mainland's current quarter already gone: zero at its turn, approaching one before the next. */
    private static function mainlandQuarterElapsed(float $totalTime): float
    {
        return max(0.0, min(1.0, ($totalTime * self::MAINLAND_QUARTERS_PER_YEAR) - self::quarterCount($totalTime)));
    }

    /**
     * The mainland short rate the market prices at this moment: the last decision moving across the quarter toward the
     * one it expects at the turn, so the expected step is in the currency before it is taken and only the surprise
     * moves it on the day, as marketShortRate() reads the District's.
     */
    public static function mainlandMarketRate(MacroState $state): float
    {
        $expected = self::mainlandNextQuarter($state, 0.0, 0.0, 0.0)['rate'];

        return $state->foreignPolicyRate + (self::mainlandQuarterElapsed($state->totalTime) * ($expected - $state->foreignPolicyRate));
    }

    /**
     * The mainland's year-on-year core inflation as the market reads it between releases: moving toward the print it
     * expects.
     */
    public static function mainlandMarketCoreYearOnYear(MacroState $state): float
    {
        $now = self::mainlandCoreYearOnYear($state);
        $expectedInflation = self::mainlandNextQuarter($state, 0.0, 0.0, 0.0)['inflation'];
        $expected = ($expectedInflation + $state->foreignCoreInflation + $state->foreignCoreInflationLag1 + $state->foreignCoreInflationLag2) / 4.0;

        return $now + (self::mainlandQuarterElapsed($state->totalTime) * ($expected - $now));
    }

    /**
     * Mundell-Fleming Open Economy Trade Balance & Net Exports to GDP.
     *
     * The structural balance, plus the net exports the output gap already carries (the mainland's demand and the real
     * exchange rate: MacroAggregateSubsystem::netExportGapAt), less the imports domestic demand draws in (IMF WEO
     * October 2015, Ch. 3: 1.4% of imports for home use per 1% of domestic demand):
     *   NX/Y = Baseline + NetExportGap - M/Y * 1.4 * (OutputGap - NetExportGap)
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateTradeBalance(MacroState $state, float $dt): void
    {
        $domesticDemandGap = $state->outputGap - $state->netExportGap;
        $targetTradeBalance = MacroConstants::TRADE_BALANCE_BASELINE
            + $state->netExportGap
            - (MacroAggregateSubsystem::DISTRICT_DOMESTIC_IMPORT_SHARE * MacroAggregateSubsystem::IMPORT_DEMAND_ELASTICITY * $domesticDemandGap);

        $targetTradeBalance = max(self::MIN_TRADE_BALANCE, min(self::MAX_TRADE_BALANCE, $targetTradeBalance));

        // Exact exponential smoothing: a convex step toward an in-band target cannot leave the band.
        $adjustmentWeight = 1.0 - exp(-$dt / self::TRADE_BALANCE_ADJUSTMENT_YEARS);
        $state->tradeBalanceToGdp += $adjustmentWeight * ($targetTradeBalance - $state->tradeBalanceToGdp);
    }

    /**
     * Jorgenson (1963) user cost of owning: mortgage rate plus depreciation and tax, less EXPECTED house-price
     * inflation. Realized inflation is not an expectation, so a commodity spike does not make owning cheaper.
     * A buyer on a floating loan prices the home on the rate expected over the years they hold it, not this
     * quarter's (Himmelberg, Mayer & Sinai 2005): the debt service households pay reads the rate in force.
     */
    private function housingUserCost(MacroState $state, float $expectedInflation): float
    {
        $mortgageRate = FixedIncome::expectedMortgageRate($state->riskNeutral10yEma);

        return max(0.015, $mortgageRate + self::RESIDENTIAL_DEPRECIATION_TAX_RATE - $expectedInflation);
    }

    /**
     * What a tax on homes bought from outside the District leaves of the fundamental house price: the foreign demand
     * it prices out, a share falling exponentially in the tax, takes its part of house values with it, so the effect
     * saturates once foreign buyers have left.
     *
     * @param float $taxRate The tax, a share of the price.
     */
    public static function foreignBuyerPriceFactor(float $taxRate): float
    {
        return exp(-self::FOREIGN_BUYER_HOUSING_VALUE_SHARE * (1.0 - exp(-self::FOREIGN_BUYER_TAX_DEMAND_SEMI_ELASTICITY * max(0.0, $taxRate))));
    }

    /**
     * What the tax on foreign buyers raises a year, a share of GDP: the rate on the homes foreign buyers still buy,
     * their share falling exponentially in the tax (foreignBuyerPriceFactor()), so the take peaks near a tenth and all
     * but vanishes at the strictest rates, which work by keeping the buyers out.
     *
     * @param float $taxRate The tax, a share of the price.
     */
    public static function foreignBuyerTaxRevenueToGdp(float $taxRate): float
    {
        $rate = max(0.0, $taxRate);

        return $rate * self::FOREIGN_BUYER_PURCHASE_SHARE * exp(-self::FOREIGN_BUYER_TAX_DEMAND_SEMI_ELASTICITY * $rate) * self::HOME_SALES_TO_GDP;
    }

    /**
     * The refusal rate of major residential schemes a green belt's stringency stands for: the 10th percentile of
     * English planning authorities at the founding regime (0), the 90th at the strictest (1).
     *
     * @param float $stringency The green belt, 0 to 1.
     */
    public static function planningRefusalRate(float $stringency): float
    {
        return self::PLANNING_REFUSAL_RATE_MEAN + (self::PLANNING_REFUSAL_RATE_SD * self::PLANNING_PERCENTILE_Z * ((2.0 * $stringency) - 1.0));
    }

    /**
     * How much a green belt adds to the elasticity of house prices to a demand shifter over the founding regime: the
     * refusal rate's rise in standard deviations times Hilber & Vermeulen's rise per standard deviation, the boom
     * estimate while the shifter pushes prices up and the bust estimate while it pulls them down.
     *
     * @param float $stringency The green belt, 0 to 1.
     * @param float $shifter    The demand shifter the elasticity applies to (the output gap, or the immigrant
     *                          population).
     */
    public static function greenBeltElasticityLift(float $stringency, float $shifter): float
    {
        $perSd = $shifter >= 0.0 ? self::GREEN_BELT_BOOM_ELASTICITY_PER_SD : self::GREEN_BELT_BUST_ELASTICITY_PER_SD;

        return 2.0 * self::PLANNING_PERCENTILE_Z * $stringency * $perSd;
    }

    /**
     * How strongly building answers house prices under a green belt, as a share of how it answers under the founding
     * regime: the price response to demand is the inverse of the supply elasticity (Saiz 2010), so supply's response to
     * the price falls in the proportion the price's response to rising demand grows.
     *
     * @param float $stringency The green belt, 0 to 1.
     */
    public static function greenBeltSupplyResponse(float $stringency): float
    {
        return self::RESIDENTIAL_INCOME_ELASTICITY / (self::RESIDENTIAL_INCOME_ELASTICITY + self::greenBeltElasticityLift($stringency, 1.0));
    }

    /**
     * New households a year as a share of the starts the stock needs, from the unemployment gap's change: young adults
     * move out as jobs come and back in as they go (Paciorek 2013), so a gap that is rising cuts building and one that
     * holds builds nothing extra. The change is read off the drift of the quarter's averages, which lag the rates by
     * their horizon.
     */
    public static function householdFormationStarts(MacroState $state): float
    {
        $gapChange = (($state->unemploymentRate - $state->unemploymentRateEma) - ($state->nairu - $state->nairuEma))
            / MacroAggregateSubsystem::STANDARD_EMA_HORIZON_YEARS;

        return -self::HEADSHIP_UNEMPLOYMENT_SEMI_ELASTICITY * $gapChange / self::HOUSING_STARTS_TO_STOCK;
    }

    /**
     * Poterba (1984) / Topel-Rosen (1988) Tobin's Q Housing Investment Dynamics.
     *
     * Homes are started for the households that form and when they sell above what they cost to build, as lending
     * standards and credit allow. Rates reach building through the house price, which prices the user cost, and
     * through jobs. A green belt makes building answer both less (greenBeltSupplyResponse()).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateHousingStarts(MacroState $state, float $dt): void
    {
        // Both legs of q read the level against the trend it has settled at, not the baseline it is built on.
        $residentialPriceRatio = $state->residentialPropertyIndex / $state->residentialWealthTrend;
        $metalsCostRatio = $state->industrialMetalsIndexTrend > 0.0 ? $state->industrialMetalsIndex / $state->industrialMetalsIndexTrend : 1.0;
        // Building labour priced as a LEVEL in the same real terms as the home price index: the real wage against its
        // trend-productivity path.
        $laborCostRatio = exp($state->realWageGap);
        $replacementCostRatio = (self::REPLACEMENT_COST_METALS_SHARE * $metalsCostRatio)
            + (self::REPLACEMENT_COST_MATERIALS_SHARE - self::REPLACEMENT_COST_METALS_SHARE)
            + ((1.0 - self::REPLACEMENT_COST_MATERIALS_SHARE) * $laborCostRatio);
        $supplyResponse = self::greenBeltSupplyResponse($state->greenBeltStringency);

        $dW = $this->mathUtility->generateStandardNormal();

        $params = [
            'baseline' => MacroConstants::HOUSING_STARTS_BASELINE,
            'qSens' => self::HOUSING_STARTS_Q_SENSITIVITY * $supplyResponse,
            'sloosSens' => self::HOUSING_STARTS_SLOOS_SENSITIVITY,
            'creditGapSens' => self::HOUSING_STARTS_CREDIT_GAP_SENSITIVITY,
            'kappa' => self::HOUSING_STARTS_KAPPA,
            'sigma' => self::HOUSING_STARTS_SIGMA,
            'min' => self::MIN_HOUSING_STARTS,
            'max' => self::MAX_HOUSING_STARTS,
        ];

        $state->housingStartsIndex = MacroTransmission::calculateTobinsQHousingStarts(
            currentStarts: $state->housingStartsIndex,
            residentialPriceRatio: $residentialPriceRatio,
            replacementCostRatio: $replacementCostRatio,
            sloosTightening: $state->sloosTighteningIndexEma,
            dt: $dt,
            dW: $dW,
            params: $params,
            creditGap: $state->creditToGdpGapEma,
            householdFormation: $supplyResponse * self::householdFormationStarts($state),
        );
    }
}
