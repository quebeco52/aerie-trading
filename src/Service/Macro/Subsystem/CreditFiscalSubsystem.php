<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\DTO\MacroStateDTO;
use App\DTO\PublicAccountsDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Math\CreditRisk;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use App\Service\Model\Sector\UtilityBusinessModel;

/**
 * Handles corporate and retail credit spreads, interbank liquidity (TED spread),
 * government spending appropriations, and countercyclical corporate tax policy.
 */
class CreditFiscalSubsystem
{
    // --- Interbank Liquidity Spread (TED) ---
    /** Cap on the interbank spread (500 bps): the TED spread's all-time high was 457 bps on 10 Oct 2008. */
    public const INTERBANK_MAX_SPREAD = 0.05;
    /** Interbank spread per unit of positive excess bond premium: 0.32, so a 2008-sized 340bp premium puts it ~110bp above base. */
    public const INTERBANK_PREMIUM_COUPLING = 0.32;
    /** Wholesale run a credit crisis forces on the day, on top of the premium's share (150bp): lenders pull funding from every bank at once, so the spread reads as a freeze. */
    public const INTERBANK_CRISIS_RUN = 0.015;
    /** Time constant (years) of the run's decay: funding returns within a year as the Authority lends and the banks are recapitalised. */
    public const INTERBANK_RUN_DECAY_YEARS = 0.4;

    // --- Corporate Tax Revenue (BEA NIPA) ---
    /** Taxable corporate profits over GDP, the base a change in the corporate rate is levied on: 20%, the District being a financial centre, whose profit bases run 14-34% of GDP against the US's 9.7% (corporate tax revenue over the statutory rate, 2015-19: Switzerland 14%, Singapore 19%, Luxembourg 19%, Ireland 22%, Hong Kong 34%; Eurostat gov_10a_taxag, IRAS, IRD), so a point of rate is a fifth of a point of GDP in revenue. */
    public const CORPORATE_PROFITS_TO_GDP = 0.20;
    /** The Monetary Authority's founding remittance, a share of GDP: the currency's backing at the founding rate the debt pays, before any purchases (0.34%; MonetaryPolicySubsystem::authorityNetIncomeToGdp()). */
    public const FOUNDING_CENTRAL_BANK_REMITTANCE = MonetaryPolicySubsystem::CURRENCY_TO_GDP
        * ((self::SOVEREIGN_DEBT_BILL_SHARE * MacroStateDTO::OPENING_POLICY_RATE) + ((1.0 - self::SOVEREIGN_DEBT_BILL_SHARE) * MacroStateDTO::OPENING_YIELD_10Y));
    /** The budget's founding take outside the corporate tax, net of transfers: household and indirect taxes less benefits, 11.3% of GDP, the purchases share less the founding corporate rate on its base and the Authority's remittance. These carry the automatic stabilisers and the structural stance (Girouard & Andre 2005: income taxes, contributions and unemployment benefits), so the rate firms pay moves only with the law. */
    public const FOUNDING_NET_TAX_RATE = MacroEngine::PUBLIC_PURCHASES_TO_GDP - (MacroEngine::BASE_CORPORATE_TAX_RATE * self::CORPORATE_PROFITS_TO_GDP) - self::FOUNDING_CENTRAL_BANK_REMITTANCE;
    /** Net corporate dividends over GDP, the base the tax on dividends is levied on before the taxable share of holders: 5.5%, between the financial centres' net distributions, paid less received, 2015-19 (Switzerland 5.9%, Ireland 5% of GNI*; Eurostat nasa_10_nf_tr D42), against the US's 4.4%. */
    public const NET_DIVIDENDS_TO_GDP = 0.055;

    // --- Carbon Revenue (EIA; BEA) ---
    /** Power-sector CO2 per dollar of GDP, in tonnes: 1,425 million tonnes in 2023 (EIA) over $27.36 trillion of GDP (BEA): the base a carbon price on power is levied on, held at today's emissions. */
    public const POWER_SECTOR_CO2_PER_GDP_DOLLAR = 1425.0e6 / 27.36e12;

    // --- Sovereign Debt Maturity Ladder (fit to US Treasury average interest rate on marketable debt, 2001-2019) ---
    /** Share of the debt that reprices at the bill rate within the year (bills and maturing coupons): 0.28, fit of the Treasury's average rate on total marketable debt to the 3-month bill and 10-year (2001-2019 RMSE 21bp; 2020-2026 out of sample 35bp, against 69bp for the 10-year charged on the whole stock). */
    public const SOVEREIGN_DEBT_BILL_SHARE = 0.28;
    /** Time constant (years) of the coupon stock's rate toward the 10-year as it rolls over, same fit. */
    public const SOVEREIGN_COUPON_REPRICING_YEARS = 2.0;

    // --- Debt Maturity (the Exchequer's; Hamilton & Wu 2012, Greenwood & Vayanos 2014) ---
    /** Average maturity of the debt at the founding, in years: 59.5 months, the midpoint of the Treasury's record over the ladder fit's years (48 in 2008, 71 in 2017), so the bill share and repricing lag above are the founding book's; both scale with 1 / maturity, the share of a steady ladder maturing each year. */
    public const FOUNDING_DEBT_AVERAGE_MATURITY = 59.5 / 12.0;
    /** Ten-year term premium per unit of gross debt to GDP times years of average maturity: 0.29%; the fund's paper is foreign, so all the District's debt is in private hands. Hamilton & Wu (2012, Table 6): retiring December 2006's debt beyond ten years with $400bn of bills would have taken 14bp off the ten-year; at about 17 years' remaining maturity against 0.4, on $13.8T of GDP, 0.48 GDP-years of supply. */
    public const DEBT_MATURITY_TERM_PREMIUM = 0.0029;

    // --- The Budget's Debt Path (each cabinet's debt target and pace: Politics\AerieDiet::DEBT_PLANKS) ---
    /** Gross debt to GDP the budget steers to before a cabinet has set its own: where the District opened. */
    public const FOUNDING_DEBT_TARGET = MacroEngine::INITIAL_DEBT_TO_GDP;
    /** Points of GDP a year the budget moves debt toward its target before a cabinet has set its own pace. */
    public const FOUNDING_DEBT_PACE = 0.005;
    /** Share of a consolidation the founding budget raises by tax, the rest by cutting purchases: two parts in five. */
    public const FOUNDING_CONSOLIDATION_TAX_SHARE = 0.40;

    // --- Sovereign Default Premium (fiscal limit: Bi 2012; Ghosh, Kim, Mendoza, Ostry & Qureshi 2013) ---
    /** Net financial liabilities to GDP at which the District's fiscal limit is as likely crossed as not, in a year: twice GDP. */
    public const FISCAL_LIMIT_NET_LIABILITIES = 2.0;
    /** Spread of that limit (in GDP): where markets place a sovereign's limit is uncertain, so the premium rises smoothly, about 12bp at 90% of GDP and 2% at 135%. */
    public const FISCAL_LIMIT_SD = 0.4;
    /** Share of a sovereign bond lost in a default: about 40%, the average haircut on advanced and emerging restructurings (Cruces & Trebesch 2013). */
    public const SOVEREIGN_LOSS_GIVEN_DEFAULT = 0.4;
    /** Time constant (years) over which the market reprices the fiscal position: a projection revises over budget rounds, not ticks. */
    public const SOVEREIGN_RISK_REPRICING_YEARS = 0.5;
    /** Share of the sovereign spread a corporate bond's YIELD carries over the riskless curve: Durbin & Ng (2005, Table IV) 0.455 (se 0.074), corporate on sovereign spread changes over US Treasuries; Almeida et al. (2017) find about half. */
    public const SOVEREIGN_CEILING_PASSTHROUGH = 0.50;

    // --- Barro Tax-Smoothing & Automatic Fiscal Stabilizers (Barro 1979) ---
    /** Elasticity of net taxes (taxes less transfers) to output: progressive taxes and countercyclical benefits move about twice as much as output (Girouard & Andre 2005). */
    public const NET_TAX_OUTPUT_ELASTICITY = 2.0;
    /** Net tax rate per unit of output gap, the automatic stabilisers: the District's own take times the elasticity's excess over one, so a low-tax state stabilises less (0.113). */
    public const FISCAL_STABILIZER_SENSITIVITY = self::FOUNDING_NET_TAX_RATE * (self::NET_TAX_OUTPUT_ELASTICITY - 1.0);
    /** Mean lag, in years, of the tax a trailing year's earnings carry behind the tax charged: half the year, as a first-order lag (the convention of MacroAggregateSubsystem::DEMAND_TRANSMISSION_LAGS). */
    public const TRAILING_EARNINGS_MEAN_LAG_YEARS = 0.5;

    // --- GOVERNMENT SPENDING & FISCAL APPROPRIATIONS ---
    /** Diffusion of log civilian purchases / potential, the same fit; jumps are not significant (LR 2.1), so the process is Gaussian. */
    public const GOVT_SPENDING_VOLATILITY = 0.0148;
    /** Time constant (years) of purchases' measured trend: a level the Diet holds for a decade is in potential, not the gap, as the tax rate's is (TAX_RATE_TREND_YEARS). */
    public const GOVT_SPENDING_TREND_YEARS = self::TAX_RATE_TREND_YEARS;

    // --- VASICEK ASRF RETAIL DEFAULT RATE (fitted on US household delinquency 1991-2019, var/harness/retail_default_fit) ---
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

    // --- Through-the-Cycle Spread Level ---
    /** Time constant (years) of the IG spread's measured trend: a decade spans a full credit cycle (US IG OAS peaks 1990, 2002, 2009, 2020), so the trend is the spread's through-the-cycle level, not the base it is built from. */
    public const CREDIT_SPREAD_TREND_YEARS = 10.0;

    // --- Structural Tax Level ---
    /** Time constant (years) of the tax rates' measured trends: demand answers a change in the fiscal stance (Blanchard 1990 fiscal impulse), and a stance held for a decade is in potential, not the gap. */
    public const TAX_RATE_TREND_YEARS = 10.0;

    // --- Corporate Default Dynamics (Moody's all-rated, Vasicek single factor) ---
    /** Sensitivity of corporate credit Z-score to macroeconomic output gap. */
    public const CORPORATE_DEFAULT_GAP_SENSITIVITY = 25.0;
    /** Corporate credit Z per unit of excess HY spread (~5): a 2,000 bps blowout with a -4% gap and 80% SLOOS, held for a year, gives ~8% (1933's all-rated rate); 2009 averaged 5.4%. */
    public const CORPORATE_DEFAULT_SPREAD_SENSITIVITY = 5.0;
    /** Corporate credit Z per unit of SLOOS net tightening (~1): a 35% credit crunch adds ~0.35 to the systemic factor. */
    public const CORPORATE_DEFAULT_SLOOS_SENSITIVITY = 1.0;

    // --- Economic Policy Uncertainty (Baker, Bloom & Davis 2016) ---
    /** Log lift of the index per unit of the election pulse, the full lift on the eve of a vote (Julio & Yook 2012 locate the investment cut in the election year; the BBD index rises a quarter or so into a presidential vote). */
    public const EPU_ELECTION_LIFT = 0.25;
    /** Log lift per unit of unemployment above NAIRU: 0.105 a point, quarterly OLS of log USEPUINDXM on UNRATE - NROU, 1985-2019 (r = 0.60). */
    public const EPU_SLACK_LIFT = 10.5;
    /** Mean reversion of the log index (half-life ~5 months, the ~0.88 monthly autocorrelation of the BBD series). */
    public const EPU_MEAN_REVERSION = 1.5;
    /** Annual log volatility: with the jumps, a log spread of ~0.22 around the slack fit (US residual 0.21 on quarterly averages, 1985-2019). */
    public const EPU_VOLATILITY = 0.35;
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

    // --- Administered Healthcare Prices (the health payer's annual update) ---
    /** Floor on the annual indexation: administered prices are held, not cut, in a deflationary year; a budget cut still cuts them. */
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
    /** Mortgage net charge-offs a year at the retail PD's long-run average: 0.43% (FRED CORSFRMACBS, all commercial banks, 1991-2019; peak 2.80%). */
    public const HOUSEHOLD_MORTGAGE_CHARGE_OFF_RATE = 0.0043;
    /** Consumer-loan net charge-offs a year at the same average: 2.61% (FRED CORCACBS, 1991-2019; peak 6.60%). */
    public const HOUSEHOLD_CONSUMER_CHARGE_OFF_RATE = 0.0261;
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
    /** Phase-in time (years) of a buffer rise: Basel gives banks twelve months. A cut takes effect at once. */
    public const CCYB_PHASE_IN_YEARS = 1.0;
    /** Years the buffer stays released after a crisis before a rise is decided: the March 2020 releases, re-raised by Norway in June 2021 and the UK in December 2021 (15 and 21 months). */
    public const CCYB_RELEASE_HOLD_YEARS = 1.5;

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
    /** Decay of the crisis drag (four-year time constant, 29% still running at year five): the lasting loss is potential's (CRISIS_POTENTIAL_SCAR), so the drag is the slump that outlasts a policy loop responding in four to five quarters, not the lost path. */
    public const CREDIT_CRISIS_DRAG_DECAY = 0.25;
    /** Share of a crisis's deleveraging that is households repaying debt, the rest firms and banks pulling back: a quarter, so a 2008-sized crisis repays ~20pp of debt to income, the US fall of 2007-13 (Mian & Sufi 2018). Repaid debt is borrowed again as leverage reverts, so this part costs no output on average. */
    public const CRISIS_HOUSEHOLD_REPAYMENT_SHARE = 0.25;
    /** Potential output a credit crisis destroys for good (3%), scaled by how thin bank capital is: firms that lose their credit lines scrap projects and the long-term unemployed lose skills, so output never returns to its old path (Cerra & Saxena 2008). */
    public const CRISIS_POTENTIAL_SCAR = 0.03;
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

    // --- Mortgage Loan-to-Value Cap (Iacoviello 2005 collateral constraint; Richter, Schularick & Shim 2019) ---
    /** Combined LTV bin edges of US home-purchase loans at origination (FHFA National Mortgage Database aggregate statistics); the top bin ends at 102% (a VA loan at 100% plus its funding fee) and the bottom one starts where the bins' mean is NMDB's 82.2%. */
    public const NEW_PURCHASE_CLTV_EDGES = [0.44, 0.70, 0.80, 0.90, 0.95, 0.97, 1.02];
    /** Share of US home-purchase loans in each bin, NMDB annual national averages 1998-2019: 19.3% to 70%, 23.6% to 80%, 13.5% to 90%, 12.6% to 95%, 14.2% to 97%, 16.9% above. */
    public const NEW_PURCHASE_CLTV_SHARES = [0.1928, 0.2355, 0.1347, 0.1260, 0.1419, 0.1693];
    /** Years over which a tightened cap takes household credit to its new level: a first-order lag through Richter, Schularick & Shim's (2019, Table 11) real household credit response, -1.37 / -4.11 / -5.95 / -5.94% at 1 / 4 / 8 / 12 quarters. */
    public const MORTGAGE_LTV_CUT_YEARS = 1.0;

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
    /** Share of that speed read from the mainland's gap rather than the District's: lenders in a financial centre price the world's credit as much as its own. */
    public const EBP_MAINLAND_GAP_SHARE = 0.5;

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
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
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

        $trancheSpreads = CreditRisk::calculateDualTrancheCreditSpreads(
            baseIgSpread: MacroEngine::BASE_CREDIT_SPREAD,
            outputGapEma: $state->outputGapEma,
            marketVolEma: $state->marketVolatilityEma,
            interbankStress: $interbankStress,
            excessBondPremium: $state->excessBondPremium,
            hyBaseMultiplier: MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
        );

        // Durbin & Ng (2005): a corporate yield carries the sovereign spread at the pass-through
        // share, and the District curve these spreads sit over already carries all of it, so the spread over that curve
        // carries the difference (it narrows as the sovereign widens, as corporates trading through their sovereign do).
        $sovereignLoading = (self::SOVEREIGN_CEILING_PASSTHROUGH - 1.0) * $state->sovereignRiskSpreadEma;
        $state->macroCreditSpread = max(MacroEngine::MIN_CREDIT_SPREAD, $trancheSpreads['ig'] + $sovereignLoading);
        $state->highYieldCreditSpread = max($state->macroCreditSpread, $trancheSpreads['hy'] + $sovereignLoading);
    }

    /**
     * The IG spread's through-the-cycle level, measured: an exponential average over a full credit cycle. The spread's
     * own mean sits wherever its premium, volatility and contagion legs put it, not at the base it is built from, so a
     * reader of its cyclical deviation (the firms' cost of debt, DebtEngine) reads it against this.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateCreditSpreadTrend(MacroState $state, float $dt): void
    {
        $state->macroCreditSpreadTrend += (1.0 - exp(-$dt / self::CREDIT_SPREAD_TREND_YEARS)) * ($state->macroCreditSpread - $state->macroCreditSpreadTrend);
    }

    /**
     * The interbank (TED) spread: the base, the share of the lenders' premium wholesale funding carries (Brunnermeier
     * 2009), and the run a credit crisis forces, decaying from the day it struck (Gorton & Metrick 2012). It reads the
     * premium, not the corporate spread, which reads it back.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateInterbankLiquiditySpread(MacroState $state): void
    {
        $yearsSinceCrisis = $state->lastCreditCrisisAt < 0.0 ? INF : $state->totalTime - $state->lastCreditCrisisAt;
        $state->interbankLiquiditySpread = self::interbankSpreadAt($state->excessBondPremium, $yearsSinceCrisis);
    }

    /**
     * The interbank spread at an excess bond premium and the years since the last credit crisis (INF for none).
     */
    public static function interbankSpreadAt(float $excessBondPremium, float $yearsSinceCrisis): float
    {
        $run = is_finite($yearsSinceCrisis) ? self::INTERBANK_CRISIS_RUN * exp(-$yearsSinceCrisis / self::INTERBANK_RUN_DECAY_YEARS) : 0.0;

        return min(
            self::INTERBANK_MAX_SPREAD,
            MacroEngine::INTERBANK_BASELINE_SPREAD + (self::INTERBANK_PREMIUM_COUPLING * max(0.0, $excessBondPremium)) + $run,
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

        $state->retailCreditFactor = StochasticProcesses::calculateOrnsteinUhlenbeckStep(
            $state->retailCreditFactor,
            self::RETAIL_CREDIT_FACTOR_KAPPA,
            self::RETAIL_CREDIT_FACTOR_SD,
            $dt,
            $this->mathUtility->generateStandardNormal(),
        );

        $macroZ = self::RETAIL_CREDIT_INTERCEPT + ($housePriceGap * self::RETAIL_HOUSE_PRICE_SENSITIVITY) - $unemploymentShock - $spreadShock + $state->retailCreditFactor;

        $conditionalPd = CreditRisk::calculateVasicekExpectedLoss(
            macroZ: $macroZ,
            pdLra: MacroEngine::RETAIL_DEFAULT_BASELINE,
            rho: CreditRisk::RETAIL_ASRF_RHO,
            lgd: 1.0,
        );

        $state->retailDefaultRate = max(0.005, min(0.20, $conditionalPd));
    }

    /**
     * Government purchases: a Schwartz (1997) log-OU fitted to US civilian purchases (federal non-defence plus state and
     * local) over potential, 1985-2019 (var/harness/gov_fit.py), reverting to the share of GDP the Diet's budget sets
     * less the cuts the debt calls for (purchasesTarget()), so an appropriation phases in at the fit's own speed. The District fields no army, so the wartime surges that fatten
     * the US total are its customers' (AssetMarketSubsystem::calculateAlliedDefenseSpending).
     * Purchases do not lean against the cycle (Auerbach 2002; the fit's lagged-gap term is insignificant), so the
     * countercyclical fiscal leg is the tax rule alone.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateGovernmentSpending(MacroState $state, float $dt): void
    {
        $state->governmentSpendingIndex = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: $state->governmentSpendingIndex,
            kappa: MacroEngine::GOVT_SPENDING_MEAN_REVERSION,
            theta: MacroEngine::GOVT_SPENDING_BASELINE * self::purchasesTarget($state->publicSpendingShare, self::requiredPrimaryBalance($state), $state->consolidationTaxShare) / MacroEngine::PUBLIC_PURCHASES_TO_GDP,
            sigma: self::GOVT_SPENDING_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal(),
        );
    }

    /**
     * Purchases against their own decade trend, as a fraction: the discretionary impulse demand and the curve read
     * (Blanchard 1990 fiscal impulse), a level held for a decade having joined potential.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public static function discretionarySpendingShift(MacroState $state): float
    {
        return ($state->governmentSpendingIndexEma / $state->governmentSpendingIndexTrend) - 1.0;
    }

    /**
     * Barro (1979) Tax-Smoothing Hypothesis & Automatic Fiscal Stabilizers.
     *
     * Adjusts the budget's net taxes, the take outside the corporate tax net of transfers, continuously via an
     * Ornstein-Uhlenbeck institutional process: they rise in a boom and fall in a recession, and return to their
     * founding share plus the structural fiscal stance (fiscalStance()), the instrument the budget's surplus is raised
     * with, less what the policy levies raise (PublicAccountsDTO::policyLevies()). The budget targets its balance, as
     * Norway's fiscal rule sets the structural deficit and lets every revenue line inside it (Report No. 29 to the
     * Storting, 2000-2001), so a tariff or a carbon price lowers the other taxes rather than running a surplus nobody
     * set. The corporate rate firms pay follows only the shift the Diet has legislated, at the same speed. Demand reads
     * the discretionary parts against their decade trends: the corporate rate and the stance, never the stabilisers'
     * swing, which reaches it through the gap's own pull (MacroAggregateSubsystem::KALDOR_AUTOMATIC_STABILISER), nor the
     * levies' give-back, which leaves the take as a whole where it was.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateDynamicFiscalPolicy(MacroState $state, float $dt): void
    {
        $stanceTarget = self::FOUNDING_NET_TAX_RATE + self::fiscalStance($state);
        $levies = self::publicAccounts($state, $dt)->policyLevies();
        $state->netTaxRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($stanceTarget - $levies + (self::FISCAL_STABILIZER_SENSITIVITY * $state->outputGapEma) - $state->netTaxRate) * $dt;
        $state->netTaxStanceRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($stanceTarget - $state->netTaxStanceRate) * $dt;
        $state->corporateTaxRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * (MacroEngine::BASE_CORPORATE_TAX_RATE + $state->corporateTaxPolicyShift - $state->corporateTaxRate) * $dt;

        $trendWeight = 1.0 - exp(-$dt / self::TAX_RATE_TREND_YEARS);
        $state->netTaxStanceRateTrend += $trendWeight * ($state->netTaxStanceRate - $state->netTaxStanceRateTrend);
        $state->corporateTaxRateTrend += $trendWeight * ($state->corporateTaxRate - $state->corporateTaxRateTrend);

        // The legislated part of that adjustment on its own, and the laws as the trailing year's earnings carry them,
        // which the market measures what it expects against (App\Service\Market\Pricing\PolicyCapitalization).
        $state->corporateTaxShiftRealized += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($state->corporateTaxPolicyShift - $state->corporateTaxShiftRealized) * $dt;
        $state->corporateTaxShiftEmbodied += ($state->corporateTaxShiftRealized - $state->corporateTaxShiftEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $state->bankLevyEmbodied += ($state->bankLevyRate - $state->bankLevyEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        // The rules on extraction, the stamp duty and the carbon price reach costs, turnover and the power price at once, so
        // their measures are lagged as the levy is.
        $state->extractionCostFactorEmbodied += (FirmEconomics::calculateExtractionCostFactor($state->extractionStringency) - $state->extractionCostFactorEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $state->stampDutyVolumeFactorEmbodied += (MacroTransmission::calculateStampDutyVolumeFactor($state->stampDutyRate) - $state->stampDutyVolumeFactorEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $state->carbonPowerUpliftEmbodied += (CommodityLogisticsSubsystem::carbonPowerPriceUplift($state->carbonPrice) - $state->carbonPowerUpliftEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        // A commission's grant reaches the tariff as rate cases close, which the trailing year then lags.
        $state->utilityTariffShortfallEmbodied += (UtilityBusinessModel::steadyTariffShortfall($state->utilityRateCaseGrantShare) - $state->utilityTariffShortfallEmbodied)
            * $dt / ((1.0 / UtilityBusinessModel::RATE_CASE_DECISION_RATE) + self::TRAILING_EARNINGS_MEAN_LAG_YEARS);
        // The firms the budget buys from read purchases through their smoothed level, which the trailing year then lags.
        $state->governmentSpendingShiftEmbodied += ((($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0) - $state->governmentSpendingShiftEmbodied) * $dt / self::TRAILING_EARNINGS_MEAN_LAG_YEARS;
    }

    /**
     * The structural part of the tax take over the purchases share, as a share of GDP: the primary balance the budget's
     * debt path calls for (requiredPrimaryBalance()), less the part the Diet finds by cutting purchases, or of any
     * loosening it spends on them (purchasesTarget()).
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public static function fiscalStance(MacroState $state): float
    {
        $required = self::requiredPrimaryBalance($state);

        return $required - ($state->publicSpendingShare - self::purchasesTarget($state->publicSpendingShare, $required, $state->consolidationTaxShare));
    }

    /**
     * The primary balance the budget plans for the year, a share of GDP: what holds gross debt to GDP where it stands
     * at the rate the debt pays against trend nominal growth, plus the year's step toward the cabinet's debt target, at
     * most its pace. A deficit is planned the same way when debt is under target. The budget plans on trend growth, so
     * the automatic stabilisers run around the plan rather than inside it.
     */
    public static function requiredPrimaryBalance(MacroState|MacroStateDTO $state): float
    {
        return self::requiredPrimaryBalanceAt($state, $state->debtTarget, $state->debtPace);
    }

    /** The primary balance a cabinet with this debt target and pace would plan for from today's debt (requiredPrimaryBalance()). */
    public static function requiredPrimaryBalanceAt(MacroState|MacroStateDTO $state, float $debtTarget, float $debtPace): float
    {
        $trendNominalGrowth = MacroEngine::TARGET_INFLATION + MacroEngine::TFP_DRIFT + $state->laborForceGrowthRate;
        $holdsDebt = (self::sovereignEffectiveRate($state) - $trendNominalGrowth) * $state->sovereignDebtToGdp;
        $pace = max(0.0, $debtPace);

        return $holdsDebt + max(-$pace, min($pace, $state->sovereignDebtToGdp - $debtTarget));
    }

    /**
     * The part of a consolidation the budget finds by cutting purchases rather than raising tax, a share of GDP: the
     * planned surplus at the cabinet's tax share. A planned deficit loosens the budget the other way round, so this turns
     * negative: a Diet that repairs debt by tax spends the room, one that repairs it by cuts gives it back as tax cuts.
     */
    public static function consolidationSpendingCut(float $requiredBalance, float $taxShare): float
    {
        return ((1.0 - $taxShare) * max(0.0, $requiredBalance)) + ($taxShare * min(0.0, $requiredBalance));
    }

    /**
     * The purchases share of GDP the purchases process reverts to: the Diet's appropriation, less the cuts the debt path
     * calls for. No more can be cut than is appropriated; past that the tax rise carries the rest (fiscalStance()).
     */
    public static function purchasesTarget(float $publicSpendingShare, float $requiredBalance, float $taxShare): float
    {
        return $publicSpendingShare - min($publicSpendingShare, self::consolidationSpendingCut($requiredBalance, $taxShare));
    }

    /**
     * What the tax on dividends raises over the founding rate's take, a share of GDP: the taxable holders' share of the
     * dividends paid, those dividends falling as the rate rises (Valuation::dividendTaxPayoutScale()). The founding
     * rate's take is already in the revenue the tax ratio was calibrated on.
     */
    public static function dividendTaxRevenueShift(float $dividendTaxRate): float
    {
        $taxedDividends = Valuation::TAXABLE_EQUITY_SHARE * self::NET_DIVIDENDS_TO_GDP;

        return (($dividendTaxRate * Valuation::dividendTaxPayoutScale($dividendTaxRate)) - Valuation::FOUNDING_DIVIDEND_TAX_RATE) * $taxedDividends;
    }

    /**
     * Sovereign Debt-to-GDP Stock Accumulation (Blanchard 2019, Greenwood-Vayanos 2014).
     *
     * Accumulates sovereign debt-to-GDP ratio from primary deficit flow, net interest expenses,
     * and nominal GDP growth erosion:
     *   d(Debt/GDP) = [ (G - T)/GDP + (r_eff - g_nominal) * (Debt/GDP) ] * dt
     * where G is purchases plus the outlays the sovereign fund pays for, and T is taxes plus the fund's draw on its
     * expected returns (NIRC), revenue like a tax, and r_eff is the rate the debt pays (sovereignEffectiveRate()).
     * Every term is a flow: the debt path's planned balance is in the tax rate (fiscalStance()) and in purchases
     * (purchasesTarget()), which demand reads, not a ledger beside it.
     *
     * The lines are the Exchequer's accounts (publicAccounts()), which the pages read too. Net taxes carry the whole
     * budget's cyclical and structural response, so T books them on GDP as fitted. The corporate rate, which moves only
     * with the shift the Diet legislates, is levied on corporate profits; the tariff on imported goods, which shrink by
     * their price elasticity as the duty raises their price; the carbon price on the power sector's emissions; the bank
     * levy is what the board's banks owe on their balance sheets; the tax on dividends, as far as it departs from the
     * founding rate, on what taxable holders are paid (dividendTaxRevenueShift()); the tax on foreign buyers on the homes
     * they still buy (AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp()).
     *
     * With a fund the budget spends the draw, revenue and spending alike, so the draw leaves the planned balance where
     * the debt path set it however large the fund grows.
     *
     * Gross debt has a floor, the stock the benchmark curve is kept on. A surplus that would take debt below it buys
     * assets instead: it is paid into the fund (sovereignFundBudgetInflow, credited on the fund's next tick), as
     * Singapore's budget surpluses accrue to its reserves. With no fund the surplus stays with the Exchequer as net assets.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSovereignDebt(MacroState $state, float $dt): void
    {
        // The debt pays what it was issued at, not today's 10-year: the coupon stock reprices as it rolls over, the
        // bills at once (Treasury average rate on marketable debt, fitted above).
        // The book moves toward the Exchequer's maturity only as it rolls over: a steady ladder turns over once per average
        // maturity, as the US extension from 48 months to the high 60s took eight years.
        $state->sovereignDebtAverageMaturity += (1.0 - exp(-$dt / $state->sovereignDebtAverageMaturity)) * ($state->sovereignDebtMaturityTarget - $state->sovereignDebtAverageMaturity);
        $state->sovereignCouponRate += (1.0 - exp(-$dt / self::couponRepricingYears($state))) * ($state->yield10y - $state->sovereignCouponRate);

        $accounts = self::publicAccounts($state, $dt);
        $funded = $state->sovereignFundDollarsPerGdp > 0.0;
        $state->primaryDeficitToGdp = $accounts->primaryDeficit();
        $state->sovereignDebtToGdp += $accounts->debtChange($dt);
        // What the Authority earned and did not pay over goes to, or comes off, the losses it carries; a currency stock too.
        $state->centralBankDeferredAssetToGdp -= ((MonetaryPolicySubsystem::authorityNetIncomeToGdp($state) - $accounts->centralBankRemittance) * $dt)
            + ($state->nominalGdpGrowth * $state->centralBankDeferredAssetToGdp * $dt);

        $belowFloor = MacroEngine::SOVEREIGN_DEBT_FLOOR - $state->sovereignDebtToGdp;
        $state->sovereignFundBudgetInflow = ($funded && $belowFloor > 0.0)
            ? $belowFloor * $state->sovereignFundDollarsPerGdp * $state->nominalGdpIndex
            : 0.0;
        // The floor is where the fund sweeps the surplus; with no fund the surplus stays with the Exchequer as net assets.
        if ($funded) {
            $state->sovereignDebtToGdp = max(MacroEngine::SOVEREIGN_DEBT_FLOOR, $state->sovereignDebtToGdp);
        }

        // IMF GFSM 2014 net debt: gross debt less the debt instruments the government holds, which here is the
        // sovereign fund's paper sleeve (its equities are not debt instruments). Equal to gross with no fund.
        $state->sovereignNetDebtToGdp = $state->sovereignDebtToGdp - $state->sovereignFundBondsToGdp;
        // Net financial liabilities (OECD; minus the accounts' net financial worth): gross debt less the fund whole,
        // equities with its bonds.
        $state->sovereignNetFinancialLiabilitiesToGdp = $state->sovereignDebtToGdp - $state->sovereignFundToGdp;
    }

    /**
     * The Exchequer's accounts at this step: revenue by source, spending by use, the debt service and the one-off
     * adjustments the debt moves by (calculateSovereignDebt() applies them), and the stocks it is measured against.
     *
     * Taxes are levied on the GDP produced, which already carries the gap; purchases are appropriated on trend, so their
     * share of GDP rises in a slump (the denominator half of the automatic stabilisers, Girouard & Andre 2005). Net taxes
     * on GDP and the corporate rate on its profits base found PUBLIC_PURCHASES_TO_GDP of GDP between them. Singapore's Net
     * Investment Returns Contribution: the fund's draw is revenue (zero with no fund), and spent. The Monetary Authority
     * pays over its net interest income (centralBankRemittance()).
     *
     * A line is struck on the board's own books where the listed firms are the whole of its base: the bank levy on the
     * deposit banks' balance sheets, and the stamp duty on the exchange's
     * trades, which is paid to the fund rather than here. Where the board is only a sample of the base, the line is a
     * share of GDP: net taxes, the corporate rate on the economy's profits, tariffs on imports, the carbon price on the
     * power sector's emissions, the dividend tax on what all taxable holders are paid, and the tax on foreign buyers on
     * the homes they buy. The fund's contribution and the remittance are the state's own institutions' accounts.
     *
     * @param MacroState|MacroStateDTO $state The macro state, live or as published.
     * @param float                    $dt    Time increment in years, for this step's one-off adjustments.
     */
    public static function publicAccounts(MacroState|MacroStateDTO $state, float $dt): PublicAccountsDTO
    {
        $output = max(0.1, $state->nominalGdpIndex);
        $funded = $state->sovereignFundDollarsPerGdp > 0.0;
        $fundContribution = $funded ? $state->sovereignFundDrawToGdp : 0.0;

        return new PublicAccountsDTO(
            netTaxes: $state->netTaxRate,
            corporateTax: $state->corporateTaxRate * self::CORPORATE_PROFITS_TO_GDP,
            tariffs: MacroAggregateSubsystem::tariffRevenueToGdp($state->importTariffRate),
            carbonPrice: $state->carbonPrice * self::POWER_SECTOR_CO2_PER_GDP_DOLLAR,
            // The board's banks' bill in currency, read through the fund's currency bridge (none before the fund opens).
            bankLevy: $funded ? $state->boardBankLevy / $state->sovereignFundDollarsPerGdp / $output : 0.0,
            dividendTax: self::dividendTaxRevenueShift($state->dividendTaxRate),
            foreignBuyerTax: AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp($state->foreignBuyerTaxRate),
            fundContribution: $fundContribution,
            centralBankRemittance: self::centralBankRemittance(MonetaryPolicySubsystem::authorityNetIncomeToGdp($state), $state->centralBankDeferredAssetToGdp, $dt),
            purchases: ($state->governmentSpendingIndex / MacroEngine::GOVT_SPENDING_BASELINE) * MacroEngine::PUBLIC_PURCHASES_TO_GDP / (1.0 + $state->outputGap),
            fundFinancedSpending: $fundContribution,
            interest: self::sovereignEffectiveRate($state) * $state->sovereignDebtToGdp,
            // Blanchard (2019): g is the growth of the nominal GDP the ratio is struck on, so a slump lowers it and the
            // recovery hands it back.
            growthErosion: $state->nominalGdpGrowth * $state->sovereignDebtToGdp,
            grossDebt: $state->sovereignDebtToGdp,
            fundBonds: $state->sovereignFundBondsToGdp,
            fundAssets: $state->sovereignFundToGdp,
        );
    }

    /**
     * The Authority's profit paid to the Exchequer, a share of GDP a year. A loss is not charged to the budget: it is
     * carried as a deferred asset and the profits that follow pay it down before any is paid over, as the Federal
     * Reserve has booked its losses since 2011 (St. Louis Fed, On the Economy, November 2023). On a page ($dt zero), a
     * carried loss means nothing is being paid.
     */
    public static function centralBankRemittance(float $income, float $deferredAsset, float $dt): float
    {
        if ($deferredAsset <= 0.0) {
            return max(0.0, $income);
        }

        return $dt > 0.0 ? max(0.0, $income - ($deferredAsset / $dt)) : 0.0;
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
        $mortgageRate = FixedIncome::mortgageRate($state->policyRateEma);
        $revolvingRate = max(0.0, $state->policyRateEma) + self::CONSUMER_CREDIT_SPREAD;
        $effectiveRate = (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * $mortgageRate) + ((1.0 - self::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * $revolvingRate);

        // Against the persistent trend, not the nominal baseline. Mian & Sufi's home-equity channel is a
        // response to prices moving away from trend; read off a fixed 100 it becomes a permanent level
        // signal, and the index's own equilibrium sits at ~94, so the term carried -0.56%/yr of standing
        // deleveraging and the credit stock could never build. Same fix as the housing wealth effect.
        $housePriceLift = $state->residentialWealthTrend > 0.0
            ? ($state->residentialPropertyIndexEma / $state->residentialWealthTrend) - 1.0
            : 0.0;
        // Iacoviello (2005): a loan-to-value cap the borrowers press against lowers the leverage they hold for good. A
        // tighter cap is built into the stock at Richter, Schularick & Shim's (2019) pace; a looser one lifts the
        // constraint at once and borrowing rises only as households choose to lever up, through the reversion below
        // toward the baseline the cap now allows.
        $leverageBaseline = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE * exp(-$state->ltvCutBuilt);
        $ltvTarget = self::mortgageLtvCreditCut($state->mortgageLtvCap);
        $ltvBuilt = $ltvTarget > $state->ltvCutBuilt
            ? TimeSeries::calculateDistributedLag($state->ltvCutBuilt, $ltvTarget, $dt, self::MORTGAGE_LTV_CUT_YEARS)
            : $ltvTarget;
        $ltvSqueeze = max(0.0, $ltvBuilt - $state->ltvCutBuilt);
        $state->ltvCutBuilt = $ltvBuilt;
        $relativeExcess = ($state->householdDebtToIncome - $leverageBaseline) / $leverageBaseline;
        $excessDsr = max(0.0, $state->householdDebtServiceGap - MacroEngine::HOUSEHOLD_DSR_STRESS_MARGIN);

        // The terms US household credit supports (1976-2019). Rates, the gap and business-loan standards have no
        // direct pull on it once house prices are in; they act through the collateral and the debt service. What is
        // charged off leaves the stock; the fit's net series carries the charge-off at the default rate's long-run
        // average, so only the excess over it is booked.
        // Jorda, Schularick & Taylor (2013): the household part of a crisis's deleveraging is debt repaid, so the stock
        // falls as spending does and the spending comes back as leverage rebuilds (calculateCreditCrisisHazard()).
        $crisisRepayment = self::crisisHouseholdRepayment($state->creditCrisisDrag) / $state->householdDebtToIncome;
        $growth = (self::CREDIT_GROWTH_HOUSE_PRICE * $housePriceLift)
            - (self::CREDIT_MEAN_REVERSION * $relativeExcess)
            - (self::DELEVERAGING_SPEED * $excessDsr)
            - $crisisRepayment
            - ($this->householdChargeOffRate($state->retailDefaultRate) - $this->householdChargeOffRate($state->retailDefaultRateTrend));
        $noise = self::CREDIT_GROWTH_SIGMA * sqrt($dt) * $this->mathUtility->generateStandardNormal();
        // Bridges et al. (2014): banks cut lending to households as the capital they must hold rises, secured and unsecured
        // in the stock's proportions, and do not lend it back when it falls.
        $capitalRequired = $state->bankCapitalRequirement + $state->countercyclicalBufferRate;
        $lendingCut = (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * self::SECURED_LENDING_CUT_PER_CAPITAL_RISE) + ((1.0 - self::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * self::UNSECURED_LENDING_CUT_PER_CAPITAL_RISE);
        $capitalSqueeze = $lendingCut * max(0.0, $capitalRequired - $state->bankCapitalRequiredLast);
        $state->bankCapitalRequiredLast = $capitalRequired;

        $previousLeverage = $state->householdDebtToIncome;
        $state->householdDebtToIncome = max(self::MIN_HOUSEHOLD_DEBT_TO_INCOME, min(self::MAX_HOUSEHOLD_DEBT_TO_INCOME, $state->householdDebtToIncome * exp(($growth * $dt) + $noise - $capitalSqueeze - $ltvSqueeze)));
        // Drehmann, Juselius & Korinek (2018) new borrowing, net of what holds leverage level: the year's average of
        // the change in leverage. It averages zero because leverage reverts, so spending needs no compensator for it.
        $state->householdNewBorrowing = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->householdNewBorrowing,
            targetValue: ($state->householdDebtToIncome - $previousLeverage) / $dt,
            dt: $dt,
            lagTimeConstant: self::HOUSEHOLD_NEW_BORROWING_HORIZON_YEARS,
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
        $state->householdDebtServiceTrend = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->householdDebtServiceTrend,
            targetValue: $state->householdDebtServiceRatio,
            dt: $dt,
            lagTimeConstant: self::DSR_TREND_HORIZON_YEARS,
        );
        $state->householdDebtServiceGap = $state->householdDebtServiceRatio - $state->householdDebtServiceTrend;

        // Basel III credit gap: the one-sided HP trend, which is defined on quarterly data, so it steps on the quarter.
        if (floor($state->totalTime * 4.0) > floor(($state->totalTime - $dt) * 4.0)) {
            $trend = TimeSeries::calculateOneSidedHpStep(
                trendLevel: $state->creditToGdpTrend,
                trendSlope: $state->creditToGdpTrendSlope,
                observation: $state->householdDebtToIncome,
                levelGain: self::CREDIT_GAP_HP_LEVEL_GAIN,
                slopeGain: self::CREDIT_GAP_HP_SLOPE_GAIN,
            );
            $state->creditToGdpTrend = $trend['level'];
            $state->creditToGdpTrendSlope = $trend['slope'];
        }
        $state->creditToGdpGap = $state->householdDebtToIncome - $state->creditToGdpTrend;

        // BCBS (2010) CCyB guidance: a rise is announced up to twelve months ahead and phases in, a cut takes effect at
        // once, and in a crisis or a declared recession the buffer is released in full so banks lend out of it rather
        // than shrink, as the buffer-setting regulators did in March 2020. A regulator with a positive neutral rate holds
        // that much while credit is not running ahead, and releases it in a downturn too (BCBS 2024).
        $bufferPosition = max(0.0, min(1.0, ($state->creditToGdpGapEma - self::CCYB_GAP_FLOOR) / (self::CCYB_GAP_CEILING - self::CCYB_GAP_FLOOR)));
        $released = ($state->lastCreditCrisisAt >= 0.0 && $state->totalTime - $state->lastCreditCrisisAt < self::CCYB_RELEASE_HOLD_YEARS)
            || MacroEngine::recessionDeclared($state);
        $bufferTarget = $released ? 0.0 : max($state->neutralCountercyclicalBuffer, self::MAX_CCYB * $bufferPosition);
        $state->countercyclicalBufferRate = $bufferTarget <= $state->countercyclicalBufferRate
            ? $bufferTarget
            : TimeSeries::calculateDistributedLag(
                currentLaggedValue: $state->countercyclicalBufferRate,
                targetValue: $bufferTarget,
                dt: $dt,
                lagTimeConstant: self::CCYB_PHASE_IN_YEARS,
            );

        // The capital banks hold follows the requirement and the buffer over the years they take to build to them.
        $state->bankCapitalBuilt = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->bankCapitalBuilt,
            targetValue: $state->bankCapitalRequirement + $state->countercyclicalBufferRate,
            dt: $dt,
            lagTimeConstant: self::REQUIREMENT_BUILD_YEARS,
        );
    }

    /**
     * How far a mortgage loan-to-value cap holds household debt below where it would stand without one, in logs, once the
     * stock has taken it in. Iacoviello's (2005) collateral constraint over the loan-to-value of US home-purchase loans
     * (FHFA NMDB): borrowers above the cap borrow at it, so new mortgage lending falls by the part of the distribution
     * above the cap over its mean, and household debt by that times the mortgage share. A cap above every loan, or
     * none (null), holds nothing.
     *
     * @param float|null $cap The loan-to-value cap in force, as a share of the property's value; null for none.
     */
    public static function mortgageLtvCreditCut(?float $cap): float
    {
        if ($cap === null) {
            return 0.0;
        }

        $excess = Distributions::histogramUpperPartialMoment(self::NEW_PURCHASE_CLTV_EDGES, self::NEW_PURCHASE_CLTV_SHARES, $cap);
        $mean = Distributions::histogramUpperPartialMoment(self::NEW_PURCHASE_CLTV_EDGES, self::NEW_PURCHASE_CLTV_SHARES, 0.0);

        return -log(1.0 - (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * $excess / $mean));
    }

    /**
     * The average interest rate the sovereign debt pays: the bill share at the policy rate, the coupon stock at the rate
     * it was issued at as it has rolled over.
     *
     * @param MacroState|MacroStateDTO $state The macro state, live or as published.
     */
    public static function sovereignEffectiveRate(MacroState|MacroStateDTO $state): float
    {
        $billShare = self::billShare($state->sovereignDebtAverageMaturity);

        return ($billShare * $state->policyRate) + ((1.0 - $billShare) * $state->sovereignCouponRate);
    }

    /** Share of the debt repricing at the bill rate within the year at an average maturity: the fitted share, scaled by the founding maturity over this one. */
    public static function billShare(float $averageMaturity): float
    {
        return min(1.0, self::SOVEREIGN_DEBT_BILL_SHARE * self::FOUNDING_DEBT_AVERAGE_MATURITY / max(0.25, $averageMaturity));
    }

    /** Time constant (years) of the coupon stock's rate toward the 10-year at the book's average maturity, scaled from the fitted one. */
    public static function couponRepricingYears(MacroState $state): float
    {
        return self::SOVEREIGN_COUPON_REPRICING_YEARS * $state->sovereignDebtAverageMaturity / self::FOUNDING_DEBT_AVERAGE_MATURITY;
    }

    /** The ten-year term premium the book's maturity adds against the founding book (FixedIncome::calculateMaturitySupplyTermPremium()). */
    public static function maturitySupplyTermPremium(float $grossDebtToGdp, float $averageMaturity): float
    {
        return FixedIncome::calculateMaturitySupplyTermPremium($grossDebtToGdp, $averageMaturity, self::FOUNDING_DEBT_AVERAGE_MATURITY, self::DEBT_MATURITY_TERM_PREMIUM);
    }

    /**
     * The ten-year term premium the stock of debt adds against the opening stock at the founding maturity
     * (FixedIncome::calculateDebtSupplyTermPremium()): the fund's paper is foreign, so all of it is in private hands, and
     * a government that carries more debt raises every long rate the curve sets, whatever its default risk.
     */
    public static function debtSupplyTermPremium(float $grossDebtToGdp): float
    {
        return FixedIncome::calculateDebtSupplyTermPremium($grossDebtToGdp, MacroEngine::INITIAL_DEBT_TO_GDP, self::FOUNDING_DEBT_AVERAGE_MATURITY, self::DEBT_MATURITY_TERM_PREMIUM);
    }

    /**
     * The duration taken out of private hands, in ten-year term premium: the central bank's holdings less the extra the
     * Exchequer's book puts in against the opening one, its maturity and its size, all through the same preferred-habitat
     * channel (Vayanos & Vila 2021).
     */
    public static function durationExtraction(float $balanceSheetIntensity, float $grossDebtToGdp, float $averageMaturity): float
    {
        return $balanceSheetIntensity - self::maturitySupplyTermPremium($grossDebtToGdp, $averageMaturity) - self::debtSupplyTermPremium($grossDebtToGdp);
    }

    /**
     * Household net charge-offs a year as a share of the debt stock: the mortgage and consumer books' long-run rates
     * moved by the systematic factor the retail default rate implies (Vasicek 2002), in the stock's proportions.
     *
     * @param float $retailDefaultRate The household default rate in force.
     */
    public function householdChargeOffRate(float $retailDefaultRate): float
    {
        $householdZ = CreditRisk::calculateVasicekSystematicFactor($retailDefaultRate, MacroEngine::RETAIL_DEFAULT_BASELINE, CreditRisk::RETAIL_ASRF_RHO);

        return (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * CreditRisk::calculateVasicekExpectedLoss($householdZ, self::HOUSEHOLD_MORTGAGE_CHARGE_OFF_RATE, CreditRisk::RETAIL_ASRF_RHO, 1.0))
            + ((1.0 - self::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * CreditRisk::calculateVasicekExpectedLoss($householdZ, self::HOUSEHOLD_CONSUMER_CHARGE_OFF_RATE, CreditRisk::RETAIL_ASRF_RHO, 1.0));
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

        $state->creditCrisisHazard = CreditRisk::calculateSchularickTaylorCrisisHazard(
            creditGap: $state->creditToGdpGapEma,
            beta0: self::CREDIT_CRISIS_LOGIT_INTERCEPT + self::DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT,
            betaGap: self::CREDIT_CRISIS_LOGIT_GAP,
        );

        if (!$this->mathUtility->checkProbability($state->creditCrisisHazard * $dt)) {
            return;
        }

        $state->lastCreditCrisisAt = $state->totalTime;
        // The buffer is released on the day (see calculateHouseholdCredit()), not a tick later.
        $state->countercyclicalBufferRate = 0.0;
        $crisisDrag = self::thinCapitalDragScale($state->bankCapitalRequirement)
            * (self::CREDIT_CRISIS_DRAG_BASE + (self::CREDIT_CRISIS_DRAG_PER_GAP * max(0.0, $state->creditToGdpGapEma)));
        $state->creditCrisisDrag += $crisisDrag;
        $this->diagnostics?->recordEvent('creditCrisis', $crisisDrag);
        // Cerra & Saxena (2008): the output a crisis destroys does not come back; potential takes the scar as the slump
        // lasts (MacroAggregateSubsystem::absorbProductivityShocks()).
        $state->crisisScarPending -= self::CRISIS_POTENTIAL_SCAR * self::thinCapitalDragScale($state->bankCapitalRequirement);
        // Gilchrist & Zakrajsek (2012): lenders' capital is hit on the day, so the premium they charge jumps with it.
        $state->excessBondPremium += self::EBP_CRISIS_JUMP;
    }

    /** Household debt a crisis's deleveraging repays a year, in debt to income: the households' share of the drag, at the one-for-one rate spending answers new borrowing. */
    public static function crisisHouseholdRepayment(float $creditCrisisDrag): float
    {
        return self::CRISIS_HOUSEHOLD_REPAYMENT_SHARE * $creditCrisisDrag / MacroAggregateSubsystem::KALDOR_HOUSEHOLD_NEW_BORROWING;
    }

    /** The part of a crisis's deleveraging that drags demand directly: firms and banks pulling back, the households' repayment reaching demand through their borrowing instead. */
    public static function crisisDirectDrag(float $creditCrisisDrag): float
    {
        return (1.0 - self::CRISIS_HOUSEHOLD_REPAYMENT_SHARE) * $creditCrisisDrag;
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
     * It is driven by the speed the output gap falls, half the District's and half the mainland's (never their
     * levels), and by a level jump when a credit crisis strikes. It peaks before the gap's trough and is largely gone within a year while the gap is still there.
     * The spread, equity volatility, the interbank spread and lending standards all load on it, and none of them
     * feeds back into it.
     *
     * @param MacroState $state             Current macroeconomic state.
     * @param float      $gapChange         Change in the output gap over this step.
     * @param float      $dt                Time increment in years.
     * @param float      $mainlandGapChange Change in the mainland's output gap over this step.
     */
    public function updateExcessBondPremium(MacroState $state, float $gapChange, float $dt, float $mainlandGapChange = 0.0): void
    {
        // The Schwartz target carries the Ito correction; handing it this level puts the log mean on EBP_LOG_MEAN.
        $target = exp(self::EBP_LOG_MEAN + ((self::EBP_LOG_VOLATILITY ** 2) / (2.0 * self::EBP_MEAN_REVERSION)));
        $shifted = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: $state->excessBondPremium + self::EBP_DISPLACEMENT,
            kappa: self::EBP_MEAN_REVERSION,
            theta: $target,
            sigma: self::EBP_LOG_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal(),
        );
        $jump = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::EBP_JUMP_INTENSITY,
            jumpMean: 0.0,
            jumpVol: self::EBP_JUMP_LOG_VOLATILITY,
            dt: $dt,
        );

        $worldGapChange = ((1.0 - self::EBP_MAINLAND_GAP_SHARE) * $gapChange) + (self::EBP_MAINLAND_GAP_SHARE * $mainlandGapChange);
        $shifted *= $jump['multiplier'] * exp(-self::EBP_LOG_GAP_SPEED_SENSITIVITY * $worldGapChange);
        if ($jump['exponent'] !== null) {
            $this->diagnostics?->recordEvent('excessBondPremium', $jump['exponent']);
        }
        $state->excessBondPremium = $shifted - self::EBP_DISPLACEMENT;
    }

    /**
     * Sovereign default premium: the expected loss on the District's bonds, the chance net liabilities pass the fiscal
     * limit within a year times the loss in a default (CreditRisk::sovereignDefaultPremium()). Near nothing at the
     * debt the District opened with, it rises steeply as debt climbs, the market's discipline on a cabinet that borrows.
     * The premium is a level the whole curve carries (it enters the term premium, the corporate IG base through the
     * sovereign ceiling, and the currency), repriced over budget rounds rather than ticks.
     *
     * The stock is net financial liabilities, gross debt less the sovereign fund whole:
     * spreads price net rather than gross debt (Hadzi-Vaskov & Ricci 2016, IMF WP/16/148), and the whole public
     * balance sheet rather than its debt alone (IMF Fiscal Monitor, October 2018, ch. 2), which is how Singapore's
     * gross debt of over 150% of GDP carries no premium at all. What the budget and the bond market's duration
     * supply read stays gross: the coupons are paid on gross debt, and the fund holds foreign paper, not the District's.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSovereignRiskSpread(MacroState $state, float $dt): void
    {
        $target = CreditRisk::sovereignDefaultPremium(
            $state->sovereignNetFinancialLiabilitiesToGdpEma,
            self::FISCAL_LIMIT_NET_LIABILITIES,
            self::FISCAL_LIMIT_SD,
            self::SOVEREIGN_LOSS_GIVEN_DEFAULT,
        );

        $state->sovereignRiskSpread = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->sovereignRiskSpread,
            targetValue: $target,
            dt: $dt,
            lagTimeConstant: self::SOVEREIGN_RISK_REPRICING_YEARS,
        );
    }

    /**
     * Economic policy uncertainty (Baker, Bloom & Davis 2016).
     *
     * A log mean-reverting index whose level is set by two things the record ties it to: the election calendar, since
     * uncertainty about the policy regime builds into a scheduled vote and resolves once a government takes office (Julio
     * & Yook 2012; Bernhard & Leblang 2006), and the slack the economy is running, since a downturn brings the policy
     * response itself into question (BBD's index is countercyclical, moving with unemployment above its natural rate).
     * The calendar arrives as the election pulse the government hands the economy, centred on its long-run mean, so it
     * moves the index through the term and leaves its average where it was. Unscheduled shocks arrive as jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculatePolicyUncertainty(MacroState $state, float $dt): void
    {
        $jumpLogCompensator = self::EPU_JUMP_PROBABILITY * self::EPU_JUMP_MEAN / self::EPU_MEAN_REVERSION;

        $target = MacroEngine::EPU_BASELINE * exp(
            (self::EPU_ELECTION_LIFT * $state->electionPulse)
                + (self::EPU_SLACK_LIFT * ($state->unemploymentRate - $state->nairu))
                - $jumpLogCompensator,
        );

        // Every reader takes log(EPU / baseline), so it is the log the process centres on the target.
        $baseProcess = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: max(self::MIN_EPU, $state->policyUncertaintyIndex),
            kappa: self::EPU_MEAN_REVERSION,
            theta: StochasticProcesses::schwartzThetaForLogMean($target, self::EPU_MEAN_REVERSION, self::EPU_VOLATILITY),
            sigma: self::EPU_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal(),
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::EPU_JUMP_PROBABILITY,
            jumpMean: self::EPU_JUMP_MEAN,
            jumpVol: self::EPU_JUMP_VOL,
            dt: $dt,
        );

        $state->policyUncertaintyIndex = max(self::MIN_EPU, min(self::MAX_EPU, $baseProcess * $jumpData['multiplier']));
        if ($jumpData['exponent'] !== null) {
            $this->diagnostics?->recordEvent('policyUncertainty', $jumpData['exponent']);
        }
    }

    /**
     * Administered healthcare price update: the District's health payer resets its prices once a year to the inflation
     * it observes, less a fixed productivity offset, and moves them by the change in the share of purchases the budget
     * is cutting. A cut lowers the price level once and holds it there while it lasts; when the budget restores the
     * money, the prices come back. A step function, not a diffusion: between updates the rate is flat whatever inflation
     * does, so a hospital's margin is squeezed through an inflation spike and repaired a year later.
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

        $austerity = max(0.0, self::consolidationSpendingCut(self::requiredPrimaryBalance($state), $state->consolidationTaxShare)) / MacroEngine::PUBLIC_PURCHASES_TO_GDP;
        $indexation = max(self::REIMBURSEMENT_MIN_UPDATE, $state->inflationEma - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET);

        // The level is the indexed path times what the budget leaves of it, so a cut and its restoration cancel exactly.
        $state->reimbursementRateGrowth = ((1.0 + $indexation) * (1.0 - $austerity) / (1.0 - $state->reimbursementAusterityShare)) - 1.0;
        $state->reimbursementAusterityShare = $austerity;
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
        // their standards until the capital is built, at the premium the shortfall is worth to the demand drag;
        // capital already held rations nothing, and a cut eases nothing.
        $capitalTightening = MacroAggregateSubsystem::CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT * self::capitalShortfall($state);
        $dW = $this->mathUtility->generateStandardNormal();

        $state->sloosTighteningIndex = CreditRisk::calculateSloosCreditStandards(
            currentSloos: $state->sloosTighteningIndex,
            outputGap: $state->outputGapEma,
            excessCreditSpread: $state->excessBondPremium + $capitalTightening,
            dt: $dt,
            dW: $dW,
            kappa: self::SLOOS_KAPPA,
            creditSensitivity: self::SLOOS_PREMIUM_SENSITIVITY,
            gapSensitivity: self::SLOOS_GAP_SENSITIVITY,
            sigma: self::SLOOS_SIGMA,
        );
    }

    /** Capital the banks must hold, requirement and buffer, but have not yet built: none once built, and a cut leaves none. */
    public static function capitalShortfall(MacroState $state): float
    {
        return max(0.0, $state->bankCapitalRequirement + $state->countercyclicalBufferRate - $state->bankCapitalBuilt);
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

        $state->corporateDefaultRate = CreditRisk::calculateCorporateDefaultRate(
            macroZ: $macroZ,
            baseDefaultRate: MacroEngine::CORPORATE_DEFAULT_BASELINE,
            rho: CreditRisk::CORPORATE_DEFAULT_RHO,
        );
    }
}
