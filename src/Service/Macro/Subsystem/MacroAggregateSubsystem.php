<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Math\MathUtility;

/**
 * Models the core macroeconomic aggregate feedback loop:
 * Total Factor Productivity (TFP), Solow-Swan potential and nominal GDP,
 * Laubach-Williams dynamic natural rate (r*), Kaldor non-linear output gap cycle,
 * Hybrid New Keynesian Phillips Curve (NKPC) inflation, TIPS breakeven expectations,
 * and continuous exponential moving average (EMA) smoothing filters.
 */
class MacroAggregateSubsystem
{
    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /** Demand drift per unit of purchases above baseline, fitted on the engine's own response to a purchases shock (var/harness/govt_irf.sh) to Ramey & Zubairy's (2018) cumulative multipliers, 2y 0.54-0.76 and 4y 0.78-0.84 (Blanchard-Perotti and news shocks): 0.79 and 0.64 here, Taylor offset included. */
    public const KALDOR_GOVT_SPENDING_MULTIPLIER = 0.45;
    /** The rate the demand equation settles a sustained drive at, its automatic stabiliser less its momentum: a drive of c a year holds the gap at c over this, so an effect on the level of spending enters the drift times it. */
    public const DEMAND_OWN_PULL = self::KALDOR_AUTOMATIC_STABILISER - self::KALDOR_MOMENTUM;
    /** Marginal propensity to consume out of a dollar of housing wealth, a year (Mian, Rao & Sufi 2013: 5-7 cents; the low end). */
    public const HOUSING_WEALTH_MPC = 0.05;
    /** Household real estate over GDP, 1985-2019 mean (Fed Z.1 via FRED, HNOREMQ027S over GDP: 1.40). */
    public const HOUSING_WEALTH_TO_GDP = 1.40;
    /** Demand drift per unit of housing wealth above the level households are used to: the MPC on the stock's size, at the pull that turns a level of spending into a drift. */
    public const KALDOR_WEALTH_EFFECT_ELASTICITY = self::DEMAND_OWN_PULL * self::HOUSING_WEALTH_MPC * self::HOUSING_WEALTH_TO_GDP;
    /** Marginal propensity to consume out of a dollar of stock market wealth, a year (Chodorow-Reich, Nenov & Simsek 2021: 3.2 cents). */
    public const EQUITY_WEALTH_MPC = 0.032;
    /** Share of the board District households own themselves, directly and through funds (the holdings the MPC is measured on): a financial centre whose listed giants earn abroad, the UK's 20.4% (ONS, Ownership of UK quoted shares 2020: individuals 12.0%, unit trusts 7.4%, investment trusts 1.0%; the rest of the world holds 56.3%). */
    public const DISTRICT_HOUSEHOLD_EQUITY_SHARE = 0.204;
    /** Demand drift per unit of equity wealth above the level households are used to: the MPC on the part of the board households own, which is sized against District GDP already, so domestic demand's share is undone. Foreign holders spend their gains abroad. */
    public const KALDOR_EQUITY_WEALTH_ELASTICITY = self::DEMAND_OWN_PULL * self::EQUITY_WEALTH_MPC * self::DISTRICT_HOUSEHOLD_EQUITY_SHARE * SovereignFundSubsystem::MARKET_CAP_TO_GDP / self::DOMESTIC_GAP_WEIGHT;
    /** Years over which a valuation level stops being news and becomes the household's normal (Carroll et al. slow adjustment). */
    public const EQUITY_WEALTH_TREND_HORIZON_YEARS = 3.0;
    /** The same horizon for houses, longer because housing wealth is revalued by sales that are years apart (Carroll, Otsuka & Slacalek 2011); a house price cycle runs about twice this, so the cycle still reads as deviation. */
    public const RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS = 5.0;
    /** Horizon of the real exchange rate's own normal, well beyond the ~12y swing the UIP differential and the sovereign risk discount drive: a first-order lag this long follows under a tenth of that swing, so PPP deviations (Rogoff 1996: 3-5y half-life) survive intact while a standing level does not. */
    public const EXCHANGE_RATE_TREND_HORIZON_YEARS = 20.0;

    // --- Distributed Lag Transmission Constants ---
    /** Headline inflation per unit farm-price shock (~13% food CPI weight at ~15% pass-through), symmetric in both directions. */
    public const AGRI_COST_PUSH_TRANSMISSION = 0.020;

    // --- Demand Transmission Lags ---
    /** The output gap as it reaches order books, one first-order lag of output_gap_ema per delay a business model can declare (DEMAND_LAG_YEARS): field => mean lag in years. */
    public const DEMAND_TRANSMISSION_LAGS = [
        'outputGapLag3m' => 0.25,
        'outputGapLag6m' => 0.50,
        'outputGapLag9m' => 0.75,
        'outputGapLag12m' => 1.00,
        'outputGapLag15m' => 1.25,
        'outputGapLag18m' => 1.50,
    ];

    // --- Natural Rate of Interest (Holston, Laubach & Williams 2017) ---
    /** r* per point of trend growth, HLW's c: 1.113 for the US (2023 vintage, 1961-2026 sample). */
    public const NATURAL_RATE_GROWTH_LOADING = 1.113;
    /** Speed of adjustment (kappa) of natural real rate toward fundamental equilibrium. */
    public const NATURAL_RATE_ADJUSTMENT_SPEED = 1.0;

    // --- Open Economy: Trade Volumes and Import Prices (IMF WEO October 2015, Ch. 3, Table 3.1: 60 economies, 1980-2014) ---
    /** Exports over GDP, the UK's 30% (World Bank NE.EXP.GNFS.ZS, 2010-19 average): a financial centre that sells its finance and its arms abroad, at a third of the world's size. */
    public const DISTRICT_EXPORT_SHARE = 0.30;
    /** Imports over GDP: exports less the structural trade balance. */
    public const DISTRICT_IMPORT_SHARE = self::DISTRICT_EXPORT_SHARE - MacroEngine::TRADE_BALANCE_BASELINE;
    /** The seeded Aerospace & Defense makers' first-year revenue (GRIP, PTAR), all of it sold to allied governments (full-market harness). */
    public const DISTRICT_DEFENSE_EXPORTS_USD = 170.0e9;
    /** Arms exports over GDP, part of the exports above (1.4%). */
    public const DISTRICT_DEFENSE_EXPORT_SHARE = self::DISTRICT_DEFENSE_EXPORTS_USD / MacroEngine::DISTRICT_GDP_USD;
    /** Mean lag (years) from an allied arms order to its delivery as exports: the makers' cost-plus backlog burns 15% a quarter (DefenseContractorBusinessModel). */
    public const DEFENSE_DELIVERY_LAG_YEARS = 1.0 / (4.0 * 0.15);
    /** Export volume per unit of trading-partner demand (2.3). */
    public const EXPORT_DEMAND_ELASTICITY = 2.3;
    /** Import volume per unit of domestic demand (1.4). */
    public const IMPORT_DEMAND_ELASTICITY = 1.4;
    /** Long-term pass-through of the real exchange rate to export prices in foreign currency (0.552, PPI-based). */
    public const EXPORT_PRICE_PASS_THROUGH = 0.552;
    /** Long-term price elasticity of export volumes (0.321). */
    public const EXPORT_PRICE_ELASTICITY = 0.321;
    /** Long-term pass-through of the real exchange rate to import prices in domestic currency (0.605). */
    public const IMPORT_PRICE_PASS_THROUGH = 0.605;
    /** Long-term price elasticity of import volumes (0.298). */
    public const IMPORT_PRICE_ELASTICITY = 0.298;
    /** Time constant (years) of the net-export response to the real exchange rate: at the district's shares the table's one-year effects give 87% of the long-term one. */
    public const TRADE_VOLUME_ADJUSTMENT_YEARS = 0.49;
    /** Time constant (years) of import-price pass-through: 0.580 one year out against 0.605 long-term, 96%. */
    public const IMPORT_PRICE_ADJUSTMENT_YEARS = 0.31;
    /** Share of an imported consumer good's retail price that is local distribution, which the exchange rate does not move (Burstein, Neves & Rebelo 2003: ~40% in the US). */
    public const IMPORT_DISTRIBUTION_SHARE = 0.40;
    /** Goods in the District's imports, the part a tariff is levied on: the UK's 75.6% (World Bank TM.VAL.MRCH.CD.WT over NE.IMP.GNFS.CD, 2010-2019 mean); services cross no customs border. */
    public const GOODS_SHARE_OF_IMPORTS = 0.756;
    /** Goods in the District's exports, the part partners can tariff: the UK's 56.5% (World Bank TX.VAL.MRCH.CD.WT over NE.EXP.GNFS.CD, 2010-2019 mean). */
    public const GOODS_SHARE_OF_EXPORTS = 0.565;

    // --- Financial Centre Output (GDP by industry; ESA 2010 §14.14 and Kornfeld 2021: service volumes as deflated balances) ---
    /** Finance and insurance value added over GDP: the District was founded as a financial centre, and its finance is over 30% of it. */
    public const DISTRICT_FINANCE_SHARE = 0.30;
    /** US finance and insurance value added over GDP, 2005-2019 average (BEA via FRED VAPGDPFI, 7.18%): the share the US-fitted demand equation already carries. */
    public const US_FINANCE_SHARE = 0.072;
    /** Credit intermediation's share of the District's finance (banks, credit services, mortgage finance), by the seeded roster's book equity; its volume follows deflated loan balances. */
    public const FINANCE_CREDIT_SHARE = 0.391;
    /** Market-based finance's share (funds, brokerage, exchanges, clearing, investment banking), the same basis; its volume follows the deflated value of the assets it manages. The rest, insurance and holding companies, moves with the domestic economy. */
    public const FINANCE_MARKET_SHARE = 0.250;
    /** Weight of the finance cycle in the gap: the District's finance share less the US share the fitted equation carries on the District's smaller rest of the economy (0.246). */
    public const FINANCE_GAP_WEIGHT = self::DISTRICT_FINANCE_SHARE - ((1.0 - self::DISTRICT_FINANCE_SHARE) * self::US_FINANCE_SHARE / (1.0 - self::US_FINANCE_SHARE));
    /** Domestic demand the District's imports carry abroad beyond the leak the fitted (US) equation already has: imports move 1.4 times domestic demand (IMF WEO 2015) on the District's import share less the US's (0.231). */
    public const EXCESS_IMPORT_LEAKAGE = self::IMPORT_DEMAND_ELASTICITY * (self::DISTRICT_IMPORT_SHARE - self::US_IMPORT_SHARE);
    /** Weight of domestic demand in the gap: everything but the market-driven finance, net of the excess imports it draws in (0.648). */
    public const DOMESTIC_GAP_WEIGHT = (1.0 - (self::FINANCE_GAP_WEIGHT * (self::FINANCE_CREDIT_SHARE + self::FINANCE_MARKET_SHARE))) * (1.0 - self::EXCESS_IMPORT_LEAKAGE);
    /** Steady-state Kalman level gain of the one-sided HP filter at Hodrick & Prescott's quarterly lambda of 1600, the trend managed market value is measured against: it follows a steady trend without the lag a moving average carries (var/harness/hp_kalman.py). */
    public const FINANCE_MARKET_TREND_LEVEL_GAIN = 0.200556;
    /** Steady-state Kalman slope gain of the same filter. */
    public const FINANCE_MARKET_TREND_SLOPE_GAIN = 0.02235291;

    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /** Linear self-reinforcement of demand; at 0.12 zero was an unstable point and the gap swept through it on a clockwork limit cycle, at 0.06 it rests inside ±1% 44% of quarters (Frisch-Slutsky shock-driven cycle) while keeping the left skew. */
    public const KALDOR_MOMENTUM = 0.06;
    /** Cubic capacity ceiling on the UPSIDE only (Friedman 1993 plucking; Dupraz, Nakamura & Steinsson 2019): output is plucked below a ceiling it cannot run above, and a slump has no floor of its own. */
    public const KALDOR_CAPACITY = 600.0;
    /** Demand per unit of a RESTRICTIVE transmitted real-rate stance: tightening binds collateral constraints (Guerrieri & Iacoviello 2017); fitted at 1.6 with the accommodative slope, the premium legs and the rule by indirect inference (var/harness/asym_an.py: ACF, sd, rule, timing, US rate path, sign-split premium projection, Barnichon-Matthes 2018), less the wealth and trade routes that fit absorbed and the engine now models (FITTED_DRAG_NOW_EXPLICIT). */
    public const KALDOR_MONETARY_DRAG_RESTRICTIVE = 1.6 - self::FITTED_DRAG_NOW_EXPLICIT;
    /** Demand per unit of an ACCOMMODATIVE stance, about half the restrictive slope: easing pushes on a string (Tenreyro & Thwaites 2016; Barnichon & Matthes 2018 put the expansionary peak at a third of the contractionary); the same fit, 0.9, less the same routes. */
    public const KALDOR_MONETARY_DRAG_ACCOMMODATIVE = 0.9 - self::FITTED_DRAG_NOW_EXPLICIT;

    // --- Monetary Transmission the Fitted Drag Carried and the Engine Now Models Explicitly ---
    /** US broad stock index response per unit of policy rate (Bernanke & Kuttner 2005: about 1% per 25bp surprise). */
    public const US_STOCK_RESPONSE_TO_RATES = 4.0;
    /** US house price response per unit of policy rate (Jarocinski & Smets 2008: 0.5% per 25bp at ten quarters). */
    public const US_HOUSE_PRICE_RESPONSE_TO_RATES = 2.0;
    /** US household stock wealth over GDP, 1985-2019 mean (Fed Z.1 via FRED: corporate equities HNOCEAQ027S plus mutual fund shares HNOMFAQ027S, 0.78). */
    public const US_STOCK_WEALTH_TO_GDP = 0.78;
    /** US exports over GDP, 2010-19 mean (World Bank NE.EXP.GNFS.ZS, 13%). */
    public const US_EXPORT_SHARE = 0.13;
    /** US imports over GDP, 2010-19 mean (World Bank NE.IMP.GNFS.ZS, 16%). */
    public const US_IMPORT_SHARE = 0.16;
    /** Housing wealth drift the engine carried when the rate slopes were fitted; the fitting harness reported no board, so equity carried none. */
    public const FIT_HOUSING_WEALTH_DRIFT = 0.05;
    /** Currency drift the engine carried when the rate slopes were fitted, per unit of the currency against its trend. */
    public const FIT_EXCHANGE_RATE_DRIFT = 0.04;
    /** Drift per unit of stance the fitted slopes absorbed for routes the engine now models itself: the US wealth route (stocks and houses at their MPCs) and the US dollar's trade route (IMF footnote 21 at US shares, through parity), each less what the engine already carried when fitted (0.35). */
    public const FITTED_DRAG_NOW_EXPLICIT = (self::DEMAND_OWN_PULL * ((self::US_STOCK_RESPONSE_TO_RATES * self::EQUITY_WEALTH_MPC * self::US_STOCK_WEALTH_TO_GDP)
            + (self::US_HOUSE_PRICE_RESPONSE_TO_RATES * self::HOUSING_WEALTH_MPC * self::HOUSING_WEALTH_TO_GDP)))
        - (self::FIT_HOUSING_WEALTH_DRIFT * self::US_HOUSE_PRICE_RESPONSE_TO_RATES)
        + (AssetMarketSubsystem::UIP_SENSITIVITY * ((self::DEMAND_OWN_PULL * ((self::EXPORT_PRICE_PASS_THROUGH * self::EXPORT_PRICE_ELASTICITY * self::US_EXPORT_SHARE)
            + (self::IMPORT_PRICE_PASS_THROUGH * self::IMPORT_PRICE_ELASTICITY * self::US_IMPORT_SHARE))) - self::FIT_EXCHANGE_RATE_DRIFT));
    /** Time constant of each of the two Pascal stages the real-rate stance passes through before it moves demand (Solow 1960): mean lag 0.8y against Rudebusch-Svensson's year average lagged a quarter (0.6y); the same fit. */
    public const MONETARY_TRANSMISSION_LAG_YEARS = 0.3;
    /** Demand per unit of excess credit and interbank spread: the Bernanke-Gertler-Gilchrist (1999) accelerator's price leg only, well under Gilchrist-Zakrajsek's reduced-form 1.5-2.0 because the quantity and deleveraging legs are booked separately. */
    public const KALDOR_CREDIT_FRICTION_DRAG = 0.60;
    /** Demand per unit of an ADVERSE Gilchrist-Zakrajsek (2012) excess bond premium, beyond its 0.58 loading into the IG spread; fitted to the US sign-split projection of the CBO gap on premium innovations (1973-2019 ex-pandemic: 2q -1.59, 4q -1.69 per pp; var/harness/asym_fit.py). */
    public const KALDOR_EXCESS_BOND_PREMIUM_DRAG_ADVERSE = 2.0;
    /** Demand per unit of a FAVORABLE (negative) premium: none, Barnichon, Matthes & Ziegenbein's (2022) estimate -- easy credit does not lift output (IP ~0 to two years, +0.7 insignificant after); it had been feeding booms. */
    public const KALDOR_EXCESS_BOND_PREMIUM_DRAG_FAVORABLE = 0.0;
    /** Bank lending channel (Lown & Morgan 2006; Bassett, Chosak, Driscoll & Zakrajsek 2014): demand per unit of net lending tightening, so the ~80% of 2008 costs ~2pp a year while it lasts, the loss they attribute to the credit-supply cut. */
    public const KALDOR_LENDING_STANDARDS_DRAG = 0.025;
    /** Countercyclical fiscal stimulus multiplier from corporate tax rate cuts. */
    public const KALDOR_FISCAL_MULTIPLIER = 0.50;
    /** The demand gap's own pull back to trend on the CURRENT gap: automatic stabilisers (Blanchard & Perotti 2002) and the permanent-income smoothing of transitory income (Friedman 1957), the level forces no channel here carries; the same fit. At 0.15 the gap had no pull of its own, a rate move integrated into it without limit, and a policy loop acting 3-4 quarters late did all the restoring and rang at 6-7 years. */
    public const KALDOR_AUTOMATIC_STABILISER = 1.5;
    /** Sensitivity of the output gap to physical capital stock overhang: the slow half of the Kaldor-Kalecki phase space, and the pent-up demand that ends a slump once the overhang has gone negative. */
    public const KALDOR_CAPITAL_DRAG = 0.25;
    /** Demand response to a capital SHORTFALL, well under the drag an excess exerts: scrapped capacity does not summon construction while balance sheets are still impaired (Bertola-Caballero 1994 irreversibility). */
    public const KALDOR_CAPITAL_REBOUND_DRAG = 0.08;
    /** Bruno-Sachs (1985) supply-side elasticity of output to energy price shock (Blanchard-Gali 2007). */
    public const KALDOR_ENERGY_SUPPLY_DRAG = 0.012;
    /** Supply-side elasticity of output to excess freight/logistics costs. */
    public const KALDOR_FREIGHT_SUPPLY_DRAG = 0.002;
    /** Demand per unit of household debt-service gap (Juselius & Drehmann 2015; Drehmann, Juselius & Korinek 2018): the service a stock of debt commits to takes spending back years after the borrowing; a point of income in extra service costs half a point of demand a year. */
    public const KALDOR_HOUSEHOLD_DEBT_SERVICE = 0.50;
    /** Demand per unit of new household borrowing, in debt to income a year: borrowed income is spent as it is borrowed. Fitted by running Drehmann, Juselius & Korinek's (2018) local projection on the engine: GDP growth +0.128 the year after a point of new borrowing to GDP (their Table 8 base, +0.126); the US lead of credit on the gap follows (var/harness/djk_an.py). */
    public const KALDOR_HOUSEHOLD_NEW_BORROWING = 1.0;
    /** Output lost per unit of catastrophe loss burden above an average year (Noy 2009; Hsiang & Jina 2014 give the sign): a year at twice the average burden costs ~0.4pp of output, before the rebuild the construction stream books. */
    public const KALDOR_CATASTROPHE_DRAG = 0.004;
    /** Demand drag per log unit of policy uncertainty ABOVE baseline: a doubling costs ~0.4pp a year, so the 2006-2011 rise integrates to the ~1% output loss Baker, Bloom & Davis attribute to it. One-sided: spikes cost output (Bloom 2009), calm does not stimulate. */
    public const KALDOR_EPU_DRAG = 0.006;
    /** Long-run average of the credit-crisis drag on the District's engine, 1.55pp a year (var/harness/fc_run.sh), booked back as its Merton (1976) compensator: crises bend the cycle without shifting its average, as the disasters and the premium are compensated. */
    public const KALDOR_CRISIS_DRAG_COMPENSATOR = 0.0155;

    // --- Aggregate Demand Disturbance (Smets-Wouters 2007) ---
    /** Mean reversion speed of the aggregate demand disturbance: -4*ln(0.86) per year, from the estimated quarterly AR(1) coefficient. */
    public const DEMAND_SHOCK_REVERSION = 0.60;
    /** Innovation volatility of the aggregate demand disturbance, in annualized gap-drift units: one scale, shared with the disaster sizes below, set so the gap's sd and quarterly moves under the fitted loop are the CBO 1985-2019 1.63% and 0.53pp, the era the policy rule is fitted on, beside a ~1.0% TFP supply gap. */
    public const DEMAND_SHOCK_SIGMA = 0.0125;

    // --- Rare Demand Disasters (Barro 2006, Gourio 2012; Kou 2002 jump) ---
    /** Disaster arrivals per year in either direction: the one-sided cause a slump needs, since the Gaussian innovation above is symmetric. */
    public const DEMAND_DISASTER_INTENSITY = 0.20;
    /** Probability a disaster is an upside demand surprise. Barro's disaster set is one-sided; a quarter weight keeps booms possible while leaving the left tail three times heavier. */
    public const DEMAND_DISASTER_UP_PROBABILITY = 0.25;
    /** Exponential rate of the upside jump: mean 3.5pp/yr of demand, three quarters of the downside, so expansions build gradually and slumps arrive whole; sized with DEMAND_SHOCK_SIGMA. */
    public const DEMAND_DISASTER_UP_RATE = 28.6;
    /** Exponential rate of the downside jump: mean 4.7pp/yr of demand, a 10pp/yr shock once per ~57y; sized with DEMAND_SHOCK_SIGMA. */
    public const DEMAND_DISASTER_DOWN_RATE = 21.4;
    /** Cap on one disaster (17.5pp/yr of demand, about four mean down-jumps). Guards the tail of the exponential draw without binding on the calibrated range. */
    public const DEMAND_DISASTER_CAP = 0.175;

    /** Stochastic micro-diffusion volatility of the output gap: realistic quarterly variance without breaking cycle phase. */
    public const OUTPUT_GAP_DIFFUSION_SIGMA = 0.0025;

    // --- Cyclical Output Gap Bounds ---
    /** Deepest slump the cycle may reach; below the worst postwar CBO gap (-8.8%, 2009Q2), so the bound guards runaway feedback rather than shaping the distribution. */
    public const OUTPUT_GAP_FLOOR = -0.12;
    /** Hottest the economy may run: above the postwar CBO peak (+5.6%, 1966Q1), since the one-sided cubic is what holds the upside. */
    public const OUTPUT_GAP_CEILING = 0.10;

    // --- Metzler-Blinder Inventory Investment Cycle (Metzler 1941, Blinder 1982) ---
    /** Demand drag per unit of inventory-to-sales deviation: the coefficient is the inventory share of annual GDP (~15%), so a 16% overhang costs 2.4pp over a year (Blinder & Maccini 1991). */
    public const METZLER_INVENTORY_DRAG = 0.15;
    /** Annual adjustment speed of firm inventory target replenishment. */
    public const INVENTORY_ADJUSTMENT_SPEED = 0.80;
    /** Sensitivity of involuntary inventory accumulation to unexpected output gap deceleration. */
    public const INVENTORY_SURPRISE_SENSITIVITY = 0.60;
    /** Inventory-to-sales response per unit of output gap; 2008-09 anchors ~4.0, held at 2.0 because the cyclical target reads the gap's LEVEL and past ~3.5 that positive feedback traps (see testNoSeedIsTrappedAgainstTheCapacityClamp). */
    public const INVENTORY_CYCLICAL_DEMAND_SENSITIVITY = 2.00;

    // --- Effective Corporate Borrowing Cost Weights ---
    /** Floating-rate share of private borrowing (bank and leveraged loans, revolvers, cards) priced off the policy rate; roughly half in an economy with a bond market and fixed-rate mortgages. */
    public const BORROWING_POLICY_WEIGHT = 0.50;
    /** Fixed-rate share (corporate bonds, mortgages, auto and term loans) priced off the 5-year benchmark; measured 2026-09-19 the mix moves only the cycle period, not its shape. */
    public const BORROWING_YIELD5Y_WEIGHT = 0.50;

    // --- Ten-Year Breakeven Inflation (Gurkaynak, Sack & Wright 2010) ---
    /** Ten-year breakeven per point of core inflation over target: US T10YIE on core CPI, 2003-2026 monthly, 0.080 (Newey-West se 0.024), holding the headline-core wedge and the GZ excess bond premium. */
    public const BREAKEVEN_CORE_LOADING = 0.080;
    /** Ten-year breakeven per point of headline over core, the energy and food pass-through: the same regression, 0.137 (se 0.029). */
    public const BREAKEVEN_NONCORE_LOADING = 0.137;

    // --- Distributed Lag Transmission Constants ---
    /** Characteristic half-life time constant in years for energy cost-push pass-through into core inflation. */
    public const ENERGY_COST_PUSH_LAG_YEARS = 0.50;
    /** Characteristic half-life in years for food cost-push pass-through into core inflation. */
    public const AGRI_COST_PUSH_LAG_YEARS = 0.75;

    // --- Carbon in the Electricity Bill (BLS CPI relative importance; EIA Electric Power Monthly) ---
    /** Household electricity's weight in the consumer price index: 2.201% (CPI-U relative importance, December 2024). */
    public const CPI_ELECTRICITY_WEIGHT = 0.02201;
    /** What households pay for electricity, in $/MWh: 16.48 cents a kWh, the US residential average in 2024 (EIA). */
    public const RESIDENTIAL_ELECTRICITY_PRICE = 164.8;

    // --- New Keynesian Phillips Curve Dynamics ---
    /** Output gap at which supply bottlenecks bind (Benigno & Eggertsson 2023, the v/u = 1 kink); at 11% the convexity sat outside the ±4% the gap lives in and inflation was flat across the cycle. */
    public const PHILLIPS_MAX_CAPACITY = 0.035;
    /** Convex Phillips slope scale; kappa / PHILLIPS_MAX_CAPACITY is the near-zero slope (~0.11 of inflation per unit gap), held constant when the ceiling moved. */
    public const PHILLIPS_CONVEX_KAPPA = 0.0038;
    /** Downward nominal rigidity factor dampening deflationary pressure during recessions (Bewley 1999). */
    public const PHILLIPS_DOWNWARD_RIGIDITY_FACTOR = 0.50;
    /** Weight of supercore services inflation in headline PCE/CPI basket (Shapiro 2022). */
    public const INFLATION_WEIGHT_SUPERCORE = 0.55;
    /** Weight of core goods inflation in headline basket. */
    public const INFLATION_WEIGHT_GOODS = 0.25;
    /** Weight of energy and agricultural food commodities in headline basket. */
    public const INFLATION_WEIGHT_COMMODITY = 0.20;
    /** Adaptive unanchoring weight of inflation expectations to sustained trend deviations. */
    public const INFLATION_ADAPTIVE_EXPECTATIONS_WEIGHT = 0.25;
    /** Speed of inflation expectations mean-reverting toward central bank target (anchored expectations). */
    public const INFLATION_MEAN_REVERSION = 0.75;

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

    // --- SOLOW-SWAN TOTAL FACTOR PRODUCTIVITY (TFP) ---
    /** Level innovation of TFP per sqrt(year): Fernald's utilization-adjusted quarterly TFP 1947-2026 is a random walk at 1-3 year horizons with this sd (annual kurtosis 2.2, so no jump component). */
    public const TFP_VOLATILITY = 0.0165;
    /** Endogenous R&D knowledge spillover sensitivity to economic expansion and capital utilization. */
    public const TFP_OUTPUT_GAP_SENSITIVITY = 0.05;
    /** Structural upper bound ceiling for annual TFP growth rate. */
    public const MAX_TFP_GROWTH_RATE = 0.050;

    // --- Productivity Shock Absorption (Basu, Fernald & Kimball 2006) ---
    /** Rate at which actual output absorbs a TFP level shock through a second-order Pascal lag (Solow 1960); fitted with the potential speed to the CBO gap's response to Fernald TFP shocks over 20 quarters, holding the supply-driven gap to the ~1.2pp sd that response implies (var/harness/tfp_real.py, tfp_grid_an.py). */
    public const TFP_OUTPUT_ABSORPTION_SPEED = 1.65;
    /** Rate at which potential absorbs the same shock, also through a second-order Pascal lag, as capital and organisation adjust; a slower single lag fit the response only by leaving policy to fight a 2.3pp supply gap, which drove the whole gap's sd from 2.2 to 3.5%. */
    public const TFP_POTENTIAL_ABSORPTION_SPEED = 0.75;

    // --- ISM Manufacturing Purchasing Managers' Index (PMI) ---
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

    // --- Producer Price Index (PPI) Stage-of-Processing ---
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
    /** Horizon of the commodity trends the PPI legs measure a growth rate against; one year is the window BLS publishes commodity PPI changes over. */
    public const COMMODITY_TREND_HORIZON_YEARS = 1.0;
    /** Share of a metals price move reaching factory-gate prices; the fastest of the three. */
    public const PPI_METALS_PASS_THROUGH = 0.50;
    /** Share of an energy price move reaching factory-gate prices, damped by hedging and regulated tariffs. */
    public const PPI_ENERGY_PASS_THROUGH = 0.40;
    /** Share of a farm price move reaching factory-gate prices; the lowest, since processing dominates food producer cost. */
    public const PPI_AGRI_PASS_THROUGH = 0.30;

    // --- Continuous EMA Indicator Smoothing Horizons ---
    /** Standard quarterly macro indicator EMA smoothing horizon. */
    public const STANDARD_EMA_HORIZON_YEARS = 0.25;

    /** The premium's stationary means depend on constants only; computed once. */
    private ?float $adversePremiumMean = null;
    private ?float $premiumMean = null;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /** Records what moved the gap. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?OutputGapProbe $gapProbe = null,
        /** Records what set inflation and which shocks were drawn; null and off on the same terms. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null
    ) {}

    /**
     * Solow-Swan (1956) & Romer (1990) Endogenous Growth.
     *
     * Productivity is a trend path (secular drift plus R&D capital deepening in expansions) times a random-walk
     * level shock: Fernald's utilization-adjusted TFP is a random walk at business-cycle horizons with no fat
     * tails, so a breakthrough is a sequence of ordinary innovations, not a jump. The shock level is kept apart
     * from the trend because output and potential absorb it at their own speeds (absorbProductivityShocks).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Realized annual trend TFP growth rate.
     */
    public function calculateTotalFactorProductivity(MacroState $state, float $dt): float
    {
        $currentTfp = $state->totalFactorProductivityIndex ?? MacroEngine::TFP_BASELINE;

        $endogenousGrowth = $state->outputGapEma * self::TFP_OUTPUT_GAP_SENSITIVITY;
        $trendGrowthRate = MacroEngine::TFP_DRIFT + $endogenousGrowth;
        $clampedTrendGrowthRate = max(MacroEngine::MIN_TFP_GROWTH_RATE, min(self::MAX_TFP_GROWTH_RATE, $trendGrowthRate));

        $innovation = self::TFP_VOLATILITY * sqrt($dt) * $this->mathUtility->generateStandardNormal();
        $state->tfpShockLevel += $innovation;
        $this->diagnostics?->recordShock('tfpInnovation', $innovation);

        $state->totalFactorProductivityIndex = max(1.0, $currentTfp * exp(($clampedTrendGrowthRate * $dt) + $innovation));

        return $clampedTrendGrowthRate;
    }

    /**
     * Basu, Fernald & Kimball (2006): a technology improvement reaches output only with a delay (hours fall on
     * impact, output catches up over two years), and potential absorbs it more slowly still as capital and
     * organisation adjust. Both follow the shock level through second-order Pascal lags (Solow 1960), output
     * the faster; their difference is the supply-side part of the output gap. With monetary policy seeing
     * through it, the whole gap opens positive, peaks near +0.4% at eighteen months and closes within about
     * three and a half years, against the CBO gap's +0.5% at two and a half years and close within four and a
     * half after a Fernald TFP shock.
     *
     * @param MacroState $state     Current macroeconomic state.
     * @param float      $trendRate Trend TFP growth from calculateTotalFactorProductivity().
     * @param float      $dt        Time increment in years.
     * @return float Productivity growth potential output is built on: the trend plus the shock potential absorbed this tick.
     */
    public function absorbProductivityShocks(MacroState $state, float $trendRate, float $dt): float
    {
        $state->tfpOutputStage1 = $this->mathUtility->calculateDistributedLag($state->tfpOutputStage1, $state->tfpShockLevel, $dt, 1.0 / self::TFP_OUTPUT_ABSORPTION_SPEED);
        $state->tfpOutputStage2 = $this->mathUtility->calculateDistributedLag($state->tfpOutputStage2, $state->tfpOutputStage1, $dt, 1.0 / self::TFP_OUTPUT_ABSORPTION_SPEED);

        $priorPotential = $state->tfpPotentialAbsorbed;
        $state->tfpPotentialStage1 = $this->mathUtility->calculateDistributedLag($state->tfpPotentialStage1, $state->tfpShockLevel, $dt, 1.0 / self::TFP_POTENTIAL_ABSORPTION_SPEED);
        $state->tfpPotentialAbsorbed = $this->mathUtility->calculateDistributedLag($priorPotential, $state->tfpPotentialStage1, $dt, 1.0 / self::TFP_POTENTIAL_ABSORPTION_SPEED);

        return $trendRate + (($state->tfpPotentialAbsorbed - $priorPotential) / $dt);
    }

    /**
     * Holston, Laubach & Williams (2017) natural rate of interest: r* = c g + z.
     *
     * r* moves with the TREND growth of potential, productivity's and the labour force's (which the immigration regime
     * sets). A level shock to potential (their sigma_y*, here a productivity
     * shock being absorbed) shifts output, not its trend, and leaves r* alone; the output gap does not enter r* at
     * all, it enters the IS curve r* is the neutral point of. HLW's one-sided estimate does move with the cycle,
     * but that is the filter reading IS residuals as z, not the natural rate. Their z, the slow drift from
     * demographics and safe-asset demand, is not modelled.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $trendGrowthRate Trend productivity growth from calculateTotalFactorProductivity(), before absorbed level shocks.
     * @param float      $dt              Time increment in years.
     */
    public function calculateNaturalRate(MacroState $state, float $trendGrowthRate, float $dt): void
    {
        $trendPotentialGrowthGap = ($trendGrowthRate - MacroEngine::TFP_DRIFT) + ($state->laborForceGrowthRate - MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE);
        $targetNaturalRate = MacroEngine::BASE_NATURAL_RATE + (self::NATURAL_RATE_GROWTH_LOADING * $trendPotentialGrowthGap);
        $targetNaturalRate = max(MacroEngine::MIN_NATURAL_RATE, min(MacroEngine::MAX_NATURAL_RATE, $targetNaturalRate));

        $state->naturalRate += self::NATURAL_RATE_ADJUSTMENT_SPEED * ($targetNaturalRate - $state->naturalRate) * $dt;
    }

    /**
     * Long-run average of the credit-crisis drag, the level its compensator books back. A state holding the drag here
     * reads as average credit: the crisis leg and its compensator net to nothing.
     *
     * @return float Mean crisis drag on demand (pp of gap a year, as a fraction).
     */
    public function stationaryCrisisDrag(): float
    {
        return self::KALDOR_CRISIS_DRAG_COMPENSATOR;
    }

    /**
     * Stationary mean of the adverse excess bond premium, E[max(P, 0)], under the premium's own law.
     *
     * The premium plus its displacement is lognormal: a log-OU (CreditFiscalSubsystem::updateExcessBondPremium) whose
     * stationary log variance is (sigma^2 + lambda sigma_J^2) / 2 kappa once its Merton jumps are counted, so the
     * adverse part is a Black (1976) call on it struck at the displacement. The recession feedback and the crisis jump
     * are left out on purpose: they are amplification, and their average cost is real. A premium held at this level
     * leaves both compensated credit legs neutral.
     *
     * @return float Mean adverse premium (fraction).
     */
    public function stationaryAdversePremium(): float
    {
        if ($this->adversePremiumMean !== null) {
            return $this->adversePremiumMean;
        }
        $logVariance = $this->stationaryPremiumLogVariance();

        return $this->adversePremiumMean = $this->mathUtility->calculateBlackScholesPrice(
            spot: exp(CreditFiscalSubsystem::EBP_LOG_MEAN + ($logVariance / 2.0)),
            strike: CreditFiscalSubsystem::EBP_DISPLACEMENT,
            volatility: sqrt($logVariance),
            riskFreeRate: 0.0,
            dividendYield: 0.0,
            timeToExpiry: 1.0,
            isCall: true
        );
    }

    /**
     * Stationary mean of the excess bond premium under the same law: the lognormal mean less the displacement.
     *
     * @return float Mean premium (fraction).
     */
    private function stationaryPremiumMean(): float
    {
        return $this->premiumMean ??= exp(CreditFiscalSubsystem::EBP_LOG_MEAN + ($this->stationaryPremiumLogVariance() / 2.0)) - CreditFiscalSubsystem::EBP_DISPLACEMENT;
    }

    /**
     * Stationary variance of the log displaced premium: diffusion plus Merton jumps over twice the reversion speed.
     *
     * @return float Log variance.
     */
    private function stationaryPremiumLogVariance(): float
    {
        return ((CreditFiscalSubsystem::EBP_LOG_VOLATILITY ** 2) + (CreditFiscalSubsystem::EBP_JUMP_INTENSITY * (CreditFiscalSubsystem::EBP_JUMP_LOG_VOLATILITY ** 2)))
            / (2.0 * CreditFiscalSubsystem::EBP_MEAN_REVERSION);
    }

    /**
     * Kaldor (1940) Non-Linear Business Cycle with Modigliani Wealth Effect & Marshall-Lerner FX Drag.
     *
     * Solves continuous domestic demand dynamics, weighted by its share of GDP, to which the productivity supply part, net
     * exports and the market-driven finance output are added as levels:
     *   dy = [Momentum - CapacityCeiling(y>0) - RealRateDrag + FiscalStimulus + AutomaticStabilisers - CapitalOverhang + WealthEffect + NewBorrowing - DebtService - CrisisDeleveraging - LendingStandards + DemandShock] * dt
     *
     * @param MacroState $state             Current macroeconomic state.
     * @param float      $yield5y           5-Year Treasury yield benchmark for business borrowing.
     * @param float      $naturalRate       Dynamic natural real rate of interest (r*).
     * @param float      $expectedInflation Short-horizon expected inflation, the Taylor rule's own measure (MonetaryPolicySubsystem::calculateExpectedInflation).
     * @param float      $dt                Time increment in years.
     * @param float      $stressMultiplier  Non-linear crisis volatility multiplier.
     * @return float Updated cyclical output gap bounded between -12% and +10%.
     */
    public function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $expectedInflation, float $dt, float $stressMultiplier): float
    {
        // The gap is GDP by industry: domestic demand on its share of the economy, plus, as levels each on its own
        // measured path, the supply part a productivity shock opens (absorbProductivityShocks), net exports, and the
        // market-driven finance output. The demand equation below runs on domestic demand only: momentum,
        // stabilisers and drags answer spending.
        $openingGap = $state->outputGap;
        $y = self::domesticDemandGap($state);
        $supplyGap = $state->tfpOutputStage2 - $state->tfpPotentialAbsorbed;
        $supplyChange = $supplyGap - $state->productivitySupplyGap;
        $state->productivitySupplyGap = $supplyGap;
        $netExportGap = $this->updateNetExportGap($state, $dt);
        $tradeChange = $netExportGap - $state->netExportGap;
        $state->netExportGap = $netExportGap;
        $financeGap = self::financeOutputGapAt(
            self::creditBalanceGap($state->creditToGdpGap, $state->creditToGdpTrend),
            self::marketBalanceGap($state->equityWealthRatio, $state->financeMarketTrend)
        );
        $financeChange = $financeGap - $state->financeOutputGap;
        $state->financeOutputGap = $financeGap;
        // Smets & Wouters (2007) estimate the demand disturbance and the measurement-frequency
        // component as separate innovations; one draw serving both correlates them at unity.
        $demandZ = $this->mathUtility->generateStandardNormal();
        $outZ = $this->mathUtility->generateStandardNormal();

        // Curdia & Woodford (2010) ex-ante real borrowing cost, the 5-year leg deflated by the market's breakeven.
        $expectedInflation5y = $state->tipsBreakeven;
        $realRate = (self::BORROWING_POLICY_WEIGHT * ($state->policyRate - $expectedInflation))
            + (self::BORROWING_YIELD5Y_WEIGHT * ($yield5y - $expectedInflation5y));

        $neutral5yDurationScale = MathUtility::calculateTermPremiumDurationScale(5.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        // Bernanke (2006) neutral borrowing benchmark incorporating structural term premium.
        $neutral5yYield = $naturalRate + MacroEngine::TARGET_INFLATION + (MacroEngine::NS_BASE_TERM_PREMIUM * $neutral5yDurationScale);

        $neutralBorrowingPolicy = (self::BORROWING_POLICY_WEIGHT * ($naturalRate + MacroEngine::TARGET_INFLATION))
            + (self::BORROWING_YIELD5Y_WEIGHT * $neutral5yYield);
        // Woodford (2003) neutral real rate with expectations anchored at inflation target.
        $neutralRealRate = $neutralBorrowingPolicy - MacroEngine::TARGET_INFLATION;

        // Curdia & Woodford (2010) risk-free real stance, reaching spending through a Pascal lag (Solow 1960;
        // Rudebusch & Svensson 1999 lag the real rate a year): investment is planned, ordered and built.
        $state->monetaryStanceStage1 = $this->mathUtility->calculateDistributedLag($state->monetaryStanceStage1, $realRate - $neutralRealRate, $dt, self::MONETARY_TRANSMISSION_LAG_YEARS);
        $state->monetaryStanceTransmitted = $this->mathUtility->calculateDistributedLag($state->monetaryStanceTransmitted, $state->monetaryStanceStage1, $dt, self::MONETARY_TRANSMISSION_LAG_YEARS);
        $monetaryDrag = $this->mathUtility->calculateAsymmetricResponse($state->monetaryStanceTransmitted, self::KALDOR_MONETARY_DRAG_RESTRICTIVE, self::KALDOR_MONETARY_DRAG_ACCOMMODATIVE);

        // Bernanke, Gertler & Gilchrist (1999) financial accelerator wholesale credit frictions.
        $excessCreditSpread = max(-MacroEngine::BASE_CREDIT_SPREAD * 0.5, $state->macroCreditSpreadEma - MacroEngine::BASE_CREDIT_SPREAD);
        $excessInterbankSpread = max(-MacroEngine::INTERBANK_BASELINE_SPREAD * 0.5, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        $creditFrictionDrag = self::KALDOR_CREDIT_FRICTION_DRAG * ($excessCreditSpread + $excessInterbankSpread);

        // Gilchrist & Zakrajsek (2012): the premium is the price of credit supply, and it moves spending beyond the spread it loads into.
        // The premium bites on its adverse side only, so each side is compensated by its stationary mean (Merton 1976):
        // it bends the cycle without shifting its average, whose cost belongs in potential, not the gap. The
        // compensator is its own channel, so a calm quarter does not read as credit stimulus.
        $adversePremiumMean = $this->stationaryAdversePremium();
        $premiumDrag = $this->mathUtility->calculateAsymmetricResponse($state->excessBondPremium, self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_ADVERSE, self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_FAVORABLE);
        $premiumCompensator = (self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_ADVERSE * $adversePremiumMean)
            + (self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_FAVORABLE * ($this->stationaryPremiumMean() - $adversePremiumMean));

        $momentum = self::KALDOR_MOMENTUM * $y;
        // Kaldor (1940) non-linear asymmetric capacity ceiling constraint.
        $cubicConstraint = $y > 0.0 ? self::KALDOR_CAPACITY * pow($y, 3) : 0.0;
        // Blanchard & Perotti (2002) DISCRETIONARY fiscal impulse: a statutory rate and an appropriated
        // outlay both clear a legislative lag, so this leg reaches demand late by construction.
        $spendingShift = ($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0;
        $discretionaryFiscal = (self::KALDOR_FISCAL_MULTIPLIER * (MacroEngine::TARGET_CORPORATE_TAX_RATE - $state->corporateTaxRate))
            + (self::KALDOR_GOVT_SPENDING_MULTIPLIER * $spendingShift);
        // The sovereign fund's stabilisation reaches demand through the purchases channel, on purchases that are the target
        // tax take of GDP. Its legislative lag is the budget round that sets it, so it flows at the rate the round sets. So
        // does the part of the rule draw above the founding share, the spending a budget drawing more of the fund's return
        // adds to the baseline the founding draw is in.
        $drawAboveFounding = $state->sovereignFundDrawShare > 0.0
            ? $state->sovereignFundDrawToGdp * ($state->sovereignFundDrawShare - MacroEngine::RESERVE_DRAW_CEILING) / $state->sovereignFundDrawShare
            : 0.0;
        $fundStabilisation = self::KALDOR_GOVT_SPENDING_MULTIPLIER * ($state->sovereignFundStabilisationToGdp + $drawAboveFounding) / MacroEngine::TARGET_CORPORATE_TAX_RATE;

        // Blanchard & Perotti (2002) AUTOMATIC stabilisers and Friedman (1957) income smoothing, on the
        // contemporaneous gap: nobody decides them, so they carry no lag and act as a spring rather than an anti-damper.
        $automaticStabiliser = -self::KALDOR_AUTOMATIC_STABILISER * $y;

        // Bertola & Caballero (1994) asymmetric capital overhang drag reflecting investment irreversibility.
        $capitalDrag = $this->mathUtility->calculateAsymmetricResponse($state->capitalStockOverhang, self::KALDOR_CAPITAL_DRAG, self::KALDOR_CAPITAL_REBOUND_DRAG);

        // Case, Quigley & Shiller (2005) housing wealth effect relative to persistent trend.
        $housingWealthEffect = $state->residentialWealthTrend > 0.0
            ? max(-0.60, min(0.60, ($state->residentialPropertyIndexEma / $state->residentialWealthTrend) - 1.0)) * self::KALDOR_WEALTH_EFFECT_ELASTICITY
            : 0.0;

        // Lettau & Ludvigson (2001) equity wealth effect from equity-to-GDP valuation trend deviations.
        $equityWealthRatio = $this->equityWealthRatio($state);
        $equityWealthEffect = ($equityWealthRatio > 0.0 && $state->equityWealthTrend > 0.0)
            ? max(-0.60, min(0.60, ($equityWealthRatio / $state->equityWealthTrend) - 1.0)) * self::KALDOR_EQUITY_WEALTH_ELASTICITY
            : 0.0;
        // Bruno & Sachs (1985) and Blanchard & Gali (2007) symmetric energy supply shock drag.
        $energyShock = $state->energyPriceShock != 0.0
            ? $state->energyPriceShock
            : (($state->energyPriceIndexEma > 0.0 ? $state->energyPriceIndexEma : $state->energyPriceIndex) - MacroEngine::ENERGY_BASELINE);
        $energySupplyShift = $energyShock / MacroEngine::ENERGY_BASELINE;
        $energySupplyDrag = $energySupplyShift * self::KALDOR_ENERGY_SUPPLY_DRAG;

        $freightRate = $state->freightRateIndexEma > 0.0 ? $state->freightRateIndexEma : $state->freightRateIndex;
        $freightSupplyShift = ($freightRate - MacroEngine::FREIGHT_BASELINE) / MacroEngine::FREIGHT_BASELINE;
        $freightSupplyDrag = $freightSupplyShift * self::KALDOR_FREIGHT_SUPPLY_DRAG;

        // Drehmann, Juselius & Korinek (2018): new borrowing lifts spending now, and the debt service it commits to drags
        // it later. Service peaks about four years after the borrowing, which is the credit boom's reversal.
        $householdNewBorrowing = self::KALDOR_HOUSEHOLD_NEW_BORROWING * $state->householdNewBorrowing;
        $householdDeleveragingDrag = self::KALDOR_HOUSEHOLD_DEBT_SERVICE * $state->householdDebtServiceGap;

        // Hallegatte et al. (2007) physical capital destruction supply drag.
        $catastropheSupplyDrag = self::KALDOR_CATASTROPHE_DRAG * max(0.0, $state->catastropheLossIndexEma - 1.0);

        // Jordà, Schularick & Taylor (2013) post-crisis balance sheet deleveraging drag.
        $crisisDeleveragingDrag = $state->creditCrisisDrag;
        // Bernanke & Blinder (1988) bank lending channel credit standards quantity constraint.
        $lendingStandardsDrag = self::KALDOR_LENDING_STANDARDS_DRAG * $state->sloosTighteningIndexEma;

        // Baker, Bloom & Davis (2016) real options investment deferral under policy uncertainty.
        $policyUncertaintyDrag = self::KALDOR_EPU_DRAG * max(0.0, log(max(1.0, $state->policyUncertaintyIndexEma) / MacroEngine::EPU_BASELINE));

        // Metzler (1941) & Blinder (1982) inventory investment cycle step, on domestic demand against its own average:
        // like with like, so the level parts riding beside the demand equation set off no surprise.
        $state->inventoryStockGap = $this->mathUtility->calculateInventoryCycleStep(
            currentInventoryGap: $state->inventoryStockGap,
            outputGap: $y,
            outputGapEma: $state->domesticDemandGapEma,
            speed: self::INVENTORY_ADJUSTMENT_SPEED,
            surpriseSens: self::INVENTORY_SURPRISE_SENSITIVITY,
            dt: $dt,
            cyclicalSens: self::INVENTORY_CYCLICAL_DEMAND_SENSITIVITY
        );
        $inventoryDrag = self::METZLER_INVENTORY_DRAG * $state->inventoryStockGap;

        // Smets & Wouters (2007) persistent AR(1) aggregate demand disturbance.
        $state->demandShock += (-self::DEMAND_SHOCK_REVERSION * $state->demandShock * $dt)
            + (self::DEMAND_SHOCK_SIGMA * $stressMultiplier * sqrt($dt) * $demandZ);

        // Barro (2006) rare demand disaster: the one-sided cause a slump needs, since the Gaussian
        // innovation above is symmetric and too small to dig one. Compensated, so it bends the shape
        // without shifting the mean.
        $disasterIncrement = $this->mathUtility->calculateCompensatedKouJump(
            lambda: self::DEMAND_DISASTER_INTENSITY,
            pUp: self::DEMAND_DISASTER_UP_PROBABILITY,
            etaUp: self::DEMAND_DISASTER_UP_RATE,
            etaDown: self::DEMAND_DISASTER_DOWN_RATE,
            cap: self::DEMAND_DISASTER_CAP,
            dt: $dt
        );
        $state->demandShock += $disasterIncrement;

        // The disasters' share of that level and their compensator's, each reverting as the whole does: the level is
        // linear, so noise, disasters and compensation are three processes summing to it exactly, and each can be
        // attributed without changing it.
        $disasterCompensator = $this->mathUtility->calculateKouCompensator(
            lambda: self::DEMAND_DISASTER_INTENSITY,
            pUp: self::DEMAND_DISASTER_UP_PROBABILITY,
            etaUp: self::DEMAND_DISASTER_UP_RATE,
            etaDown: self::DEMAND_DISASTER_DOWN_RATE,
            cap: self::DEMAND_DISASTER_CAP,
            dt: $dt
        );
        $disasterJump = $disasterIncrement + $disasterCompensator;
        $state->demandDisasterShock += (-self::DEMAND_SHOCK_REVERSION * $state->demandDisasterShock * $dt) + $disasterJump;
        $state->demandDisasterCompensation += (-self::DEMAND_SHOCK_REVERSION * $state->demandDisasterCompensation * $dt) + $disasterCompensator;
        if ($disasterJump !== 0.0) {
            $this->diagnostics?->recordEvent('demandDisaster', $disasterJump);
        }

        // Every channel signed as it acts on demand, so a drag reads negative wherever it is looked at. Domestic
        // demand's channels reach GDP on its share; the level parts arrive as their change. This array IS the
        // drift: it is summed below and handed to the probe unchanged, so a channel cannot reach the economy and miss
        // the decomposition that explains it.
        $domesticDemand = [
            'momentum' => $momentum,
            'cubicConstraint' => -$cubicConstraint,
            'monetaryDrag' => -$monetaryDrag,
            'creditFrictionDrag' => -$creditFrictionDrag,
            'premiumDrag' => -$premiumDrag,
            'premiumCompensator' => $premiumCompensator,
            'fiscalStimulus' => $discretionaryFiscal,
            'fundStabilisation' => $fundStabilisation,
            'automaticStabiliser' => $automaticStabiliser,
            'capitalDrag' => -$capitalDrag,
            'inventoryDrag' => -$inventoryDrag,
            'housingWealthEffect' => $housingWealthEffect,
            'equityWealthEffect' => $equityWealthEffect,
            'energySupplyDrag' => -$energySupplyDrag,
            'freightSupplyDrag' => -$freightSupplyDrag,
            'policyUncertaintyDrag' => -$policyUncertaintyDrag,
            'catastropheSupplyDrag' => -$catastropheSupplyDrag,
            'householdNewBorrowing' => $householdNewBorrowing,
            'householdDeleveragingDrag' => -$householdDeleveragingDrag,
            'crisisDeleveragingDrag' => -$crisisDeleveragingDrag,
            'crisisCompensator' => self::KALDOR_CRISIS_DRAG_COMPENSATOR,
            'lendingStandardsDrag' => -$lendingStandardsDrag,
            'demandShock' => $state->demandShock - $state->demandDisasterShock + $state->demandDisasterCompensation,
            'demandDisaster' => $state->demandDisasterShock,
            'disasterCompensator' => -$state->demandDisasterCompensation,
        ];
        $contributions = array_map(static fn (float $rate): float => self::DOMESTIC_GAP_WEIGHT * $rate, $domesticDemand) + [
            // Basu, Fernald & Kimball (2006): output catches up with a technology gain before potential does.
            'productivitySupply' => $supplyChange / $dt,
            // Obstfeld & Rogoff (1996): the mainland's demand and the real exchange rate move what the district sells abroad.
            'netExports' => $tradeChange / $dt,
            // ESA 2010 deflated balances: the finance the District lives on produces as its loans and managed assets do.
            'financeOutput' => $financeChange / $dt,
        ];

        $drift = array_sum($contributions) * $dt;
        $diffusion = self::DOMESTIC_GAP_WEIGHT * self::OUTPUT_GAP_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $outZ;
        $preClampGap = $openingGap + $drift + $diffusion;
        $newGap = max(self::OUTPUT_GAP_FLOOR, min(self::OUTPUT_GAP_CEILING, $preClampGap));

        $this->gapProbe?->record(
            terms: $contributions,
            diffusion: $diffusion,
            openingGap: $openingGap,
            preClampGap: $preClampGap,
            closingGap: $newGap,
            dt: $dt
        );

        return $newGap;
    }

    /**
     * Net exports away from the structural trade balance, as a share of GDP (IMF WEO October 2015, Ch. 3).
     *
     * Exports follow the mainland's demand. The currency against its own trend moves both volumes through the prices
     * it passes into, the price response building over about a year toward its long-term size.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Net export gap (fraction of GDP).
     */
    private function updateNetExportGap(MacroState $state, float $dt): float
    {
        $state->realExchangeRateTradeLag = $this->mathUtility->calculateDistributedLag(
            $state->realExchangeRateTradeLag,
            self::realExchangeRateGap($state->exchangeRateIndexEma, $state->exchangeRateTrend),
            $dt,
            self::TRADE_VOLUME_ADJUSTMENT_YEARS
        );
        $state->alliedDefenseDeliveryLag = $this->mathUtility->calculateDistributedLag(
            $state->alliedDefenseDeliveryLag,
            self::alliedDefenseGap($state->alliedDefenseSpendingIndexEma),
            $dt,
            self::DEFENSE_DELIVERY_LAG_YEARS
        );
        // A duty reaches volumes as a price does, over the same year.
        $state->tariffTradeLag = $this->mathUtility->calculateDistributedLag($state->tariffTradeLag, log(1.0 + $state->importTariffRate), $dt, self::TRADE_VOLUME_ADJUSTMENT_YEARS);

        return self::netExportGapAt($state->foreignOutputGapEma, $state->realExchangeRateTradeLag, $state->alliedDefenseDeliveryLag, $state->tariffTradeLag);
    }

    /**
     * The domestic-demand part of the gap, at domestic demand's own scale: the gap less the level parts that ride
     * beside the demand equation (supply, net exports, market-driven finance), over domestic demand's weight.
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Domestic demand gap (fraction).
     */
    public static function domesticDemandGap(MacroState $state): float
    {
        return self::domesticDemandGapAt($state->outputGap, $state->productivitySupplyGap, $state->netExportGap, $state->financeOutputGap);
    }

    /**
     * The domestic-demand part of a gap made of the given level parts, at domestic demand's own scale.
     *
     * @param float $outputGap        The whole output gap (fraction).
     * @param float $supplyGap        The productivity supply gap riding beside demand.
     * @param float $netExportGap     Net exports away from the structural balance (share of GDP).
     * @param float $financeOutputGap The market-driven finance output (share of GDP).
     * @return float Domestic demand gap (fraction).
     */
    public static function domesticDemandGapAt(float $outputGap, float $supplyGap, float $netExportGap, float $financeOutputGap): float
    {
        return ($outputGap - $supplyGap - $netExportGap - $financeOutputGap) / self::DOMESTIC_GAP_WEIGHT;
    }

    /**
     * The field that carries the output gap at a given demand lag. The macro publishes the gap at a fixed set of lags
     * rather than at whatever each business model asks for, so a model declaring any other lag is a configuration error.
     *
     * @param float $lagYears Mean demand lag in years, as a business model declares it.
     * @return string MacroState property name.
     * @throws \InvalidArgumentException When the lag is not one DEMAND_TRANSMISSION_LAGS publishes.
     */
    public static function demandTransmissionLagField(float $lagYears): string
    {
        $field = array_search($lagYears, self::DEMAND_TRANSMISSION_LAGS, true);
        if (!is_string($field)) {
            throw new \InvalidArgumentException(sprintf('The macro publishes no output gap lag of %s years; add one to DEMAND_TRANSMISSION_LAGS.', $lagYears));
        }

        return $field;
    }

    /**
     * Allied defence spending against its trend, in logs: the orders the District's arms makers take.
     *
     * @param float $alliedDefenseSpendingIndexEma The allied defence index, smoothed over a quarter.
     * @return float Log allied defence gap; positive is a build-up.
     */
    public static function alliedDefenseGap(float $alliedDefenseSpendingIndexEma): float
    {
        return $alliedDefenseSpendingIndexEma > 0.0 ? log($alliedDefenseSpendingIndexEma / MacroEngine::ALLIED_DEFENSE_BASELINE) : 0.0;
    }

    /**
     * The currency against its own trend, in logs: the competitiveness trade volumes answer. The trend, not the index's
     * nominal baseline, is the reference, since the currency settles away from 100 by construction.
     *
     * @param float $exchangeRateIndexEma The currency index, smoothed over a quarter.
     * @param float $exchangeRateTrend    The currency's own long-run trend.
     * @return float Log real exchange rate gap; positive is an appreciation.
     */
    public static function realExchangeRateGap(float $exchangeRateIndexEma, float $exchangeRateTrend): float
    {
        return ($exchangeRateTrend > 0.0 && $exchangeRateIndexEma > 0.0) ? log($exchangeRateIndexEma / $exchangeRateTrend) : 0.0;
    }

    /**
     * Net exports at a mainland gap, a (lagged) real exchange rate gap and the allied defence spending delivered, as a
     * share of GDP.
     *
     * Civilian export volume moves 2.3 times the mainland's demand; arms exports move with the allied procurement their
     * makers sell into, at its elasticity to defence spending. A real appreciation raises export prices abroad by the
     * export pass-through and loses their elasticity's worth of volume, and lowers import prices at home by the import
     * pass-through and gains that elasticity's worth of imports, each weighted by its share of GDP (the chapter's
     * footnote 21, which gives 1.5% of GDP per 10% at the sample's 42% and 41% shares).
     *
     * A tariff is a price with full pass-through (Amiti, Redding & Weinstein 2019): imported goods lose their price
     * elasticity's worth of volume on the whole duty. Partners answer on the District's goods exports at the 2018
     * retaliation ratio (Fajgelbaum et al. 2020), which lose theirs; allied governments do not tariff the arms they buy.
     *
     * @param float $mainlandGap         Mainland output gap (fraction).
     * @param float $realExchangeRateGap Log real exchange rate against its trend.
     * @param float $alliedDefenseGap    Log allied defence spending against its trend, as deliveries have reached it.
     * @param float $tariffDuty          Log of one plus the District's average tariff, as it has reached volumes.
     * @return float Net export gap (fraction of GDP).
     */
    public static function netExportGapAt(float $mainlandGap, float $realExchangeRateGap, float $alliedDefenseGap, float $tariffDuty = 0.0): float
    {
        $priceResponse = (self::EXPORT_PRICE_PASS_THROUGH * self::EXPORT_PRICE_ELASTICITY * self::DISTRICT_EXPORT_SHARE)
            + (self::IMPORT_PRICE_PASS_THROUGH * self::IMPORT_PRICE_ELASTICITY * self::DISTRICT_IMPORT_SHARE);
        $retaliatoryDuty = log(1.0 + (MacroEngine::TARIFF_RETALIATION_RATIO * (exp($tariffDuty) - 1.0)));
        $retaliatedExportShare = (self::DISTRICT_EXPORT_SHARE * self::GOODS_SHARE_OF_EXPORTS) - self::DISTRICT_DEFENSE_EXPORT_SHARE;

        return ((self::DISTRICT_EXPORT_SHARE - self::DISTRICT_DEFENSE_EXPORT_SHARE) * self::EXPORT_DEMAND_ELASTICITY * $mainlandGap)
            + (self::DISTRICT_DEFENSE_EXPORT_SHARE * MacroEngine::ALLIED_PROCUREMENT_ELASTICITY * $alliedDefenseGap)
            - ($priceResponse * $realExchangeRateGap)
            + (self::IMPORT_PRICE_ELASTICITY * self::DISTRICT_IMPORT_SHARE * self::GOODS_SHARE_OF_IMPORTS * $tariffDuty)
            - (self::EXPORT_PRICE_ELASTICITY * $retaliatedExportShare * $retaliatoryDuty);
    }

    /**
     * The market-driven part of the District's finance output, as a share of GDP. Service volumes follow balances
     * deflated by a general price index (ESA 2010 §14.14; Kornfeld 2021): banks produce as their loans do and fund
     * managers, brokers and exchanges as the value of the assets they manage and trade does, each against its trend.
     *
     * @param float $creditBalanceGap Log credit balances against their trend.
     * @param float $marketBalanceGap Log market value of managed assets against its trend.
     * @return float Finance output gap (fraction of GDP).
     */
    public static function financeOutputGapAt(float $creditBalanceGap, float $marketBalanceGap): float
    {
        return self::FINANCE_GAP_WEIGHT * ((self::FINANCE_CREDIT_SHARE * $creditBalanceGap) + (self::FINANCE_MARKET_SHARE * $marketBalanceGap));
    }

    /**
     * Credit balances against their Basel trend, in logs: the credit-to-GDP ratio over its one-sided HP trend.
     *
     * @param float $creditToGdpGap   Credit-to-GDP less its trend.
     * @param float $creditToGdpTrend The trend.
     * @return float Log credit balance gap.
     */
    public static function creditBalanceGap(float $creditToGdpGap, float $creditToGdpTrend): float
    {
        return $creditToGdpTrend > 0.0 ? log(max(0.01, 1.0 + ($creditToGdpGap / $creditToGdpTrend))) : 0.0;
    }

    /**
     * The market value the District's funds and brokers hold against its trend, in logs: the board's capitalisation
     * over GDP against its one-sided HP trend, so a steady rise in what they manage is potential output, not a boom.
     * Zero before a market is reported.
     *
     * @param float $equityWealthRatio  Board capitalisation over nominal GDP.
     * @param float $financeMarketTrend The trend of that ratio.
     * @return float Log market balance gap.
     */
    public static function marketBalanceGap(float $equityWealthRatio, float $financeMarketTrend): float
    {
        return ($equityWealthRatio > 0.0 && $financeMarketTrend > 0.0) ? log($equityWealthRatio / $financeMarketTrend) : 0.0;
    }

    /**
     * The log import price level, relative to domestic prices, that the currency and the tariff settle it at: the
     * long-term pass-through times the index against its baseline, plus the whole duty, which passes into duty-inclusive
     * prices in full (Amiti, Redding & Weinstein 2019; Fajgelbaum et al. 2020). Only its change is ever read, so the
     * baseline sets no level of its own.
     *
     * @param float $exchangeRateIndexEma The currency index, smoothed over a quarter.
     * @param float $importTariffRate     The average tariff on imports.
     * @return float Log relative import price level.
     */
    public static function importPriceLevelTarget(float $exchangeRateIndexEma, float $importTariffRate = 0.0): float
    {
        $index = $exchangeRateIndexEma > 0.0 ? $exchangeRateIndexEma : AssetMarketSubsystem::EXCHANGE_RATE_BASELINE;

        return (-self::IMPORT_PRICE_PASS_THROUGH * log($index / AssetMarketSubsystem::EXCHANGE_RATE_BASELINE)) + log(1.0 + $importTariffRate);
    }

    /**
     * Hybrid New Keynesian Phillips Curve with Benigno & Eggertsson (2023) Convexity
     * and Shapiro (2022) Sectoral Disaggregation (Supercore Services vs Core Goods vs Commodity).
     *
     * Models non-linear capacity-constrained inflation where output gaps approaching capacity
     * accelerate inflation non-linearly, while downward nominal wage/price rigidity flattens the curve during recessions.
     * Decomposes inflation into wage-push supercore services, freight/materials core goods, and energy/food pass-through.
     *
     * @param MacroState $state            Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @param float      $stressMultiplier Non-linear crisis volatility multiplier.
     * @param float      $dt               Time increment in years.
     * @return float Updated headline inflation rate.
     */
    public function calculateInflation(MacroState $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        $infZ = $this->mathUtility->generateStandardNormal();

        // Mankiw, Reis & Wolfers (2004) adaptive inflation expectations unanchoring.
        $anchorSlip = ($state->inflationEma - $targetInflation) * self::INFLATION_ADAPTIVE_EXPECTATIONS_WEIGHT;

        // Benigno & Eggertsson (2023) non-linear convex demand-pull Phillips curve.
        $convexDemandPressure = $this->mathUtility->calculateConvexPhillipsCurve(
            outputGap: $state->outputGap,
            maxCapacity: self::PHILLIPS_MAX_CAPACITY,
            kappa: self::PHILLIPS_CONVEX_KAPPA,
            downwardRigidityFactor: self::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR
        );

        // Shapiro (2022) Sector 1: Supercore services labor wage-push inflation channel.
        $wageGap = $state->wageGrowth - (MacroEngine::TFP_DRIFT + $targetInflation);
        $wageCostPush = $wageGap * self::SUPERCORE_WAGE_TRANSMISSION;
        $targetSupercore = $targetInflation + $anchorSlip + $convexDemandPressure + $wageCostPush;

        // Import prices (IMF WEO October 2015, Ch. 3): the real exchange rate passes into import prices relative to
        // domestic ones within about a year. The peninsula makes no goods, so every good, fuel and food in the basket is
        // imported, and all of its retail price but the local distribution margin reprices.
        $priorImportPriceLevel = $state->importPriceLevel;
        $state->importPriceLevel = $this->mathUtility->calculateDistributedLag($priorImportPriceLevel, self::importPriceLevelTarget($state->exchangeRateIndexEma, $state->importTariffRate), $dt, self::IMPORT_PRICE_ADJUSTMENT_YEARS);
        $importPriceInflation = (1.0 - self::IMPORT_DISTRIBUTION_SHARE) * ($state->importPriceLevel - $priorImportPriceLevel) / $dt;

        // Shapiro (2022) Sector 2: Core goods intermediate supply chain and materials cost pressures.
        $freightShift = ($state->freightRateIndexEma / MacroEngine::FREIGHT_BASELINE) - 1.0;
        $metalsShift = ($state->industrialMetalsIndexEma / MacroEngine::METALS_BASELINE) - 1.0;
        $gscpiFriction = max(-0.01, $state->supplyChainPressureIndexEma * self::CORE_GOODS_GSCPI_SENSITIVITY);
        $goodsSupplyFriction = ($freightShift * self::CORE_GOODS_FREIGHT_SENSITIVITY) + ($metalsShift * self::CORE_GOODS_METALS_SENSITIVITY) + $gscpiFriction;
        $targetCoreGoods = $targetInflation + $anchorSlip + (self::CORE_GOODS_DEMAND_SENSITIVITY * $convexDemandPressure) + $goodsSupplyFriction + $importPriceInflation;

        // Calvo (1983) sticky price dynamics via continuous AR(1) state adjustment.
        $reversionWeight = 1.0 - exp(-self::INFLATION_MEAN_REVERSION * $dt);
        $state->supercoreInflation += $reversionWeight * ($targetSupercore - $state->supercoreInflation);
        $state->supercoreInflation = max(-0.01, min(0.20, $state->supercoreInflation));

        $state->coreGoodsInflation += $reversionWeight * ($targetCoreGoods - $state->coreGoodsInflation);
        $state->coreGoodsInflation = max(-0.02, min(0.20, $state->coreGoodsInflation));

        // Shapiro (2022) Sector 3: Distributed lag energy cost-push pass-through.
        $rawEnergyCostPush = ($state->energyPriceShock / MacroEngine::ENERGY_BASELINE) * MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $state->energyCostPushLag = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->energyCostPushLag,
            targetValue: $rawEnergyCostPush,
            dt: $dt,
            lagTimeConstant: self::ENERGY_COST_PUSH_LAG_YEARS
        );

        // Gelos & Ustyugova (2017) distributed lag agricultural pass-through to food CPI.
        $rawAgriCostPush = (($state->agriculturalCommodityIndex / MacroEngine::AGRI_BASELINE) - 1.0) * self::AGRI_COST_PUSH_TRANSMISSION;
        $state->agriCostPushLag = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->agriCostPushLag,
            targetValue: $rawAgriCostPush,
            dt: $dt,
            lagTimeConstant: self::AGRI_COST_PUSH_LAG_YEARS
        );

        // A carbon price reaches the electricity bill as the wholesale price it adds, at the energy lag: a step in the
        // price level, its electricity weight's worth, not a lasting rate.
        $priorCarbonLevel = $state->electricityCarbonPriceLevel;
        $state->electricityCarbonPriceLevel = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $priorCarbonLevel,
            targetValue: log(1.0 + (CommodityLogisticsSubsystem::carbonPowerPriceAdder($state->carbonPrice) / self::RESIDENTIAL_ELECTRICITY_PRICE)),
            dt: $dt,
            lagTimeConstant: self::ENERGY_COST_PUSH_LAG_YEARS
        );
        $carbonCostPush = self::CPI_ELECTRICITY_WEIGHT * ($state->electricityCarbonPriceLevel - $priorCarbonLevel) / $dt;

        // Shapiro (2022) commodity basket aggregation normalized by expenditure weight.
        $commodityBasketInflation = $targetInflation + $anchorSlip
            + (($state->energyCostPushLag + $state->agriCostPushLag + $carbonCostPush) / self::INFLATION_WEIGHT_COMMODITY)
            + $importPriceInflation;

        // Shapiro (2022) expenditure-weighted headline consumer price aggregation.
        $blendedInflation = (self::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflation)
            + (self::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflation)
            + (self::INFLATION_WEIGHT_COMMODITY * $commodityBasketInflation);

        $noise = self::INFLATION_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $infZ;
        $newInflation = $blendedInflation + $noise;
        $boundedInflation = max(-0.02, min(0.25, $newInflation));

        // The blend is linear with weights summing to one, so headline splits exactly into what each channel put in
        // it, with the gap between a sector's rate and the rate it is moving toward booked as the sticky-price lag.
        if ($this->diagnostics?->isEnabled()) {
            $weightSum = self::INFLATION_WEIGHT_SUPERCORE + self::INFLATION_WEIGHT_GOODS + self::INFLATION_WEIGHT_COMMODITY;
            $this->diagnostics->recordInflation([
                'target' => $targetInflation * $weightSum,
                'expectationsSlip' => $anchorSlip * $weightSum,
                'demandPull' => $convexDemandPressure * (self::INFLATION_WEIGHT_SUPERCORE + (self::CORE_GOODS_DEMAND_SENSITIVITY * self::INFLATION_WEIGHT_GOODS)),
                'wagePush' => $wageCostPush * self::INFLATION_WEIGHT_SUPERCORE,
                'goodsSupply' => $goodsSupplyFriction * self::INFLATION_WEIGHT_GOODS,
                'energyPassThrough' => $state->energyCostPushLag,
                'foodPassThrough' => $state->agriCostPushLag,
                'carbonPassThrough' => $carbonCostPush,
                'importPrices' => $importPriceInflation * (self::INFLATION_WEIGHT_GOODS + self::INFLATION_WEIGHT_COMMODITY),
                'stickyPriceLag' => (self::INFLATION_WEIGHT_SUPERCORE * ($state->supercoreInflation - $targetSupercore))
                    + (self::INFLATION_WEIGHT_GOODS * ($state->coreGoodsInflation - $targetCoreGoods)),
                'noise' => $noise,
                'clamp' => $boundedInflation - $newInflation,
            ], $boundedInflation, $dt);
        }

        return $boundedInflation;
    }

    /**
     * Gurkaynak, Sack & Wright (2010) ten-year TIPS breakeven: expected inflation over the bond's life plus its risk
     * premium, less the TIPS liquidity premium, fitted as one reduced form on the inflation the market sees.
     *
     * The US ten-year moves 0.08 per point of core inflation over target and 0.14 per point of headline over core, so
     * an energy spike that adds a point to headline adds a seventh of a point here: long expectations are anchored,
     * and a commodity shock is priced as passing. The output gap adds nothing once inflation is held (CBO gap 0.02,
     * se 0.02). The fit also holds the excess bond premium, whose liquidity squeeze on TIPS (-0.31 per point, 2008
     * and March 2020) is not modelled here. Fit: var/harness/breakeven_fit.py.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @return float Ten-year breakeven inflation.
     */
    public function calculateTipsBreakeven(MacroState $state, float $targetInflation): float
    {
        // Headline runs over core by the lagged energy and food pass-through the commodity basket adds.
        $headlineOverCore = $state->energyCostPushLag + $state->agriCostPushLag;

        return $targetInflation
            + (self::BREAKEVEN_CORE_LOADING * (self::coreInflationEma($state) - $targetInflation))
            + (self::BREAKEVEN_NONCORE_LOADING * $headlineOverCore);
    }

    /**
     * Core inflation, less food and energy: supercore services and core goods at their basket weights, renormalised.
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Smoothed core inflation rate.
     */
    public static function coreInflationEma(MacroState $state): float
    {
        return ((self::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflationEma) + (self::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflationEma))
            / (self::INFLATION_WEIGHT_SUPERCORE + self::INFLATION_WEIGHT_GOODS);
    }

    /**
     * Solow-Swan (1956) Potential Output Capacity & Price Deflator Accumulation.
     *
     * Expands constant-dollar real potential GDP capacity via demographic growth and TFP,
     * accumulates the GDP price deflator via headline inflation, and computes nominal GDP.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $dt            Time increment in years.
     * @param float|null $tfpGrowthRate Optional pre-computed TFP growth rate.
     */
    public function calculatePotentialAndNominalGdp(MacroState $state, float $dt, ?float $tfpGrowthRate = null): void
    {
        if ($tfpGrowthRate === null) {
            $tfpGrowthRate = $this->calculateTotalFactorProductivity($state, $dt);
        }

        $realPotentialGrowth = $state->laborForceGrowthRate + $tfpGrowthRate;
        $currentPotential = $state->potentialGdpIndex > 0.0 ? $state->potentialGdpIndex : 1.0;
        $state->potentialGdpIndex = max(0.10, $currentPotential * exp($realPotentialGrowth * $dt));
        // The population an immigration regime has added over the structural path (log), which bids for housing.
        $state->immigrationPopulationShift += ($state->laborForceGrowthRate - MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE) * $dt;

        $currentDeflator = $state->gdpDeflator > 0.0 ? $state->gdpDeflator : 1.0;
        $state->gdpDeflator = max(0.01, $currentDeflator * exp($state->inflation * $dt));

        $state->nominalGdpIndex = max(0.10, $state->potentialGdpIndex * (1.0 + $state->outputGap) * $state->gdpDeflator);
    }

    /**
     * Continuous Exponential Moving Average (EMA) Filter for Macroeconomic State Variables.
     *
     * Updates exponential distributed lags representing institutional memory and smoothed
     * trend expectations across rates, yields, spreads, indices, and real activity.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        $emaWeight = 1.0 - exp(-$dt / self::STANDARD_EMA_HORIZON_YEARS);
        $commodityTrendWeight = 1.0 - exp(-$dt / self::COMMODITY_TREND_HORIZON_YEARS);

        $state->outputGapEma += $emaWeight * ($state->outputGap - $state->outputGapEma);
        foreach (self::DEMAND_TRANSMISSION_LAGS as $field => $lagYears) {
            $state->$field = $this->mathUtility->calculateDistributedLag($state->$field, $state->outputGapEma, $dt, $lagYears);
        }
        $state->domesticDemandGapEma += $emaWeight * (self::domesticDemandGap($state) - $state->domesticDemandGapEma);
        $state->policyRateEma += $emaWeight * ($state->policyRate - $state->policyRateEma);
        $state->inflationEma += $emaWeight * ($state->inflation - $state->inflationEma);
        $state->tipsBreakevenEma += $emaWeight * ($state->tipsBreakeven - $state->tipsBreakevenEma);
        $state->nsSlopeEma += $emaWeight * ($state->nsSlope - $state->nsSlopeEma);
        // Lucas (1978) asset pricing: initialises equity market cap EMA on first reported observation.
        if ($state->equityMarketCap > 0.0) {
            $state->equityMarketCapEma = $state->equityMarketCapEma > 0.0
                ? $state->equityMarketCapEma + ($emaWeight * ($state->equityMarketCap - $state->equityMarketCapEma))
                : $state->equityMarketCap;
        }

        // Lettau & Ludvigson (2001) cay model: multi-year trend EMA for equity wealth effect.
        $state->equityWealthRatio = $this->equityWealthRatio($state);
        if ($state->equityWealthRatio > 0.0) {
            $trendWeight = 1.0 - exp(-$dt / self::EQUITY_WEALTH_TREND_HORIZON_YEARS);
            $state->equityWealthTrend = $state->equityWealthTrend > 0.0
                ? $state->equityWealthTrend + ($trendWeight * ($state->equityWealthRatio - $state->equityWealthTrend))
                : $state->equityWealthRatio;

            // Potential finance output: the one-sided HP trend of managed market value, defined on quarterly data, so it
            // steps on the quarter.
            if ($state->financeMarketTrend <= 0.0) {
                $state->financeMarketTrend = $state->equityWealthRatio;
                $state->financeMarketTrendSlope = 0.0;
            } elseif (floor($state->totalTime * 4.0) > floor(($state->totalTime - $dt) * 4.0)) {
                $trend = $this->mathUtility->calculateOneSidedHpStep(
                    trendLevel: log($state->financeMarketTrend),
                    trendSlope: $state->financeMarketTrendSlope,
                    observation: log($state->equityWealthRatio),
                    levelGain: self::FINANCE_MARKET_TREND_LEVEL_GAIN,
                    slopeGain: self::FINANCE_MARKET_TREND_SLOPE_GAIN
                );
                $state->financeMarketTrend = exp($trend['level']);
                $state->financeMarketTrendSlope = $trend['slope'];
            }
        }

        $state->naturalRateEma += $emaWeight * ($state->naturalRate - $state->naturalRateEma);
        $state->jobVacanciesRateEma += $emaWeight * ($state->jobVacanciesRate - $state->jobVacanciesRateEma);
        $state->laborTightnessEma += $emaWeight * ($state->laborTightness - $state->laborTightnessEma);
        $state->wageGrowthEma += $emaWeight * ($state->wageGrowth - $state->wageGrowthEma);

        $state->yield2yEma += $emaWeight * ($state->yield2y - $state->yield2yEma);
        $state->yield5yEma += $emaWeight * ($state->yield5y - $state->yield5yEma);
        $state->yield10yEma += $emaWeight * ($state->yield10y - $state->yield10yEma);
        $state->yield30yEma += $emaWeight * ($state->yield30y - $state->yield30yEma);

        $state->termPremium10yEma += $emaWeight * ($state->termPremium10y - $state->termPremium10yEma);
        $state->riskNeutral10yEma += $emaWeight * ($state->riskNeutral10y - $state->riskNeutral10yEma);

        $state->marketVolatilityEma += $emaWeight * ($state->marketVolatility - $state->marketVolatilityEma);
        $state->macroCreditSpreadEma += $emaWeight * ($state->macroCreditSpread - $state->macroCreditSpreadEma);
        $state->unemploymentRateEma += $emaWeight * ($state->unemploymentRate - $state->unemploymentRateEma);
        $state->energyPriceIndexEma += $emaWeight * ($state->energyPriceIndex - $state->energyPriceIndexEma);
        $state->energyPriceIndexTrend = $state->energyPriceIndexTrend > 0.0
            ? $state->energyPriceIndexTrend + ($commodityTrendWeight * ($state->energyPriceIndex - $state->energyPriceIndexTrend))
            : $state->energyPriceIndex;
        $state->consumerSentimentIndexEma += $emaWeight * ($state->consumerSentimentIndex - $state->consumerSentimentIndexEma);
        $state->exchangeRateIndexEma += $emaWeight * ($state->exchangeRateIndex - $state->exchangeRateIndexEma);
        $exchangeRateTrendWeight = 1.0 - exp(-$dt / self::EXCHANGE_RATE_TREND_HORIZON_YEARS);
        $state->exchangeRateTrend += $exchangeRateTrendWeight * ($state->exchangeRateIndexEma - $state->exchangeRateTrend);
        $state->industrialMetalsIndexEma += $emaWeight * ($state->industrialMetalsIndex - $state->industrialMetalsIndexEma);
        $state->industrialMetalsIndexTrend = $state->industrialMetalsIndexTrend > 0.0
            ? $state->industrialMetalsIndexTrend + ($commodityTrendWeight * ($state->industrialMetalsIndex - $state->industrialMetalsIndexTrend))
            : $state->industrialMetalsIndex;
        $state->governmentSpendingIndexEma += $emaWeight * ($state->governmentSpendingIndex - $state->governmentSpendingIndexEma);
        $state->alliedDefenseSpendingIndexEma += $emaWeight * ($state->alliedDefenseSpendingIndex - $state->alliedDefenseSpendingIndexEma);
        $state->retailDefaultRateEma += $emaWeight * ($state->retailDefaultRate - $state->retailDefaultRateEma);
        $state->agriculturalCommodityIndexEma += $emaWeight * ($state->agriculturalCommodityIndex - $state->agriculturalCommodityIndexEma);
        $state->agriculturalCommodityIndexTrend = $state->agriculturalCommodityIndexTrend > 0.0
            ? $state->agriculturalCommodityIndexTrend + ($commodityTrendWeight * ($state->agriculturalCommodityIndex - $state->agriculturalCommodityIndexTrend))
            : $state->agriculturalCommodityIndex;
        $state->freightRateIndexEma += $emaWeight * ($state->freightRateIndex - $state->freightRateIndexEma);
        $state->interbankLiquiditySpreadEma += $emaWeight * ($state->interbankLiquiditySpread - $state->interbankLiquiditySpreadEma);
        $state->totalFactorProductivityIndexEma += $emaWeight * ($state->totalFactorProductivityIndex - $state->totalFactorProductivityIndexEma);
        $state->capitalStockOverhangEma += $emaWeight * ($state->capitalStockOverhang - $state->capitalStockOverhangEma);
        $state->residentialPropertyIndexEma += $emaWeight * ($state->residentialPropertyIndex - $state->residentialPropertyIndexEma);
        $residentialTrendWeight = 1.0 - exp(-$dt / self::RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS);
        $state->residentialWealthTrend += $residentialTrendWeight * ($state->residentialPropertyIndexEma - $state->residentialWealthTrend);
        $state->commercialPropertyIndexEma += $emaWeight * ($state->commercialPropertyIndex - $state->commercialPropertyIndexEma);
        $state->nairuEma += $emaWeight * ($state->nairu - $state->nairuEma);
        $state->sovereignDebtToGdpEma += $emaWeight * ($state->sovereignDebtToGdp - $state->sovereignDebtToGdpEma);
        $state->sovereignNetDebtToGdpEma += $emaWeight * ($state->sovereignNetDebtToGdp - $state->sovereignNetDebtToGdpEma);
        $state->financialConditionsIndexEma += $emaWeight * ($state->financialConditionsIndex - $state->financialConditionsIndexEma);

        $state->supercoreInflationEma += $emaWeight * ($state->supercoreInflation - $state->supercoreInflationEma);
        $state->coreGoodsInflationEma += $emaWeight * ($state->coreGoodsInflation - $state->coreGoodsInflationEma);
        $state->highYieldCreditSpreadEma += $emaWeight * ($state->highYieldCreditSpread - $state->highYieldCreditSpreadEma);
        $state->inventoryStockGapEma += $emaWeight * ($state->inventoryStockGap - $state->inventoryStockGapEma);
        $state->energyInventoryIndexEma += $emaWeight * ($state->energyInventoryIndex - $state->energyInventoryIndexEma);
        $state->capacityUtilizationRateEma += $emaWeight * ($state->capacityUtilizationRate - $state->capacityUtilizationRateEma);
        $state->recessionProbabilityEma += $emaWeight * ($state->recessionProbability - $state->recessionProbabilityEma);
        $state->corporateDefaultRateEma += $emaWeight * ($state->corporateDefaultRate - $state->corporateDefaultRateEma);
        $state->sloosTighteningIndexEma += $emaWeight * ($state->sloosTighteningIndex - $state->sloosTighteningIndexEma);
        $state->supplyChainPressureIndexEma += $emaWeight * ($state->supplyChainPressureIndex - $state->supplyChainPressureIndexEma);
        $state->refiningCrackSpreadEma += $emaWeight * ($state->refiningCrackSpread - $state->refiningCrackSpreadEma);
        $state->dealActivityIndexEma += $emaWeight * ($state->dealActivityIndex - $state->dealActivityIndexEma);

        $state->manufacturingPmiEma += $emaWeight * ($state->manufacturingPmi - $state->manufacturingPmiEma);
        $state->producerPriceInflationEma += $emaWeight * ($state->producerPriceInflation - $state->producerPriceInflationEma);
        $state->tradeBalanceToGdpEma += $emaWeight * ($state->tradeBalanceToGdp - $state->tradeBalanceToGdpEma);
        $state->housingStartsIndexEma += $emaWeight * ($state->housingStartsIndex - $state->housingStartsIndexEma);
        $state->moneySupplyGrowthEma += $emaWeight * ($state->moneySupplyGrowth - $state->moneySupplyGrowthEma);
        $state->reimbursementRateIndexEma += $emaWeight * ($state->reimbursementRateIndex - $state->reimbursementRateIndexEma);
        $state->policyUncertaintyIndexEma += $emaWeight * ($state->policyUncertaintyIndex - $state->policyUncertaintyIndexEma);
        $state->sovereignRiskSpreadEma += $emaWeight * ($state->sovereignRiskSpread - $state->sovereignRiskSpreadEma);
        $state->systemDepositBetaEma += $emaWeight * ($state->systemDepositBeta - $state->systemDepositBetaEma);
        $state->naturalGasPriceIndexEma += $emaWeight * ($state->naturalGasPriceIndex - $state->naturalGasPriceIndexEma);
        $state->goldPriceIndexEma += $emaWeight * ($state->goldPriceIndex - $state->goldPriceIndexEma);
        $state->wholesalePowerPriceIndexEma += $emaWeight * ($state->wholesalePowerPriceIndex - $state->wholesalePowerPriceIndexEma);
        $state->catastropheLossIndexEma += $emaWeight * ($state->catastropheLossIndex - $state->catastropheLossIndexEma);
        $state->foreignOutputGapEma += $emaWeight * ($state->foreignOutputGap - $state->foreignOutputGapEma);
        $state->householdDebtToIncomeEma += $emaWeight * ($state->householdDebtToIncome - $state->householdDebtToIncomeEma);
        $state->householdDebtServiceRatioEma += $emaWeight * ($state->householdDebtServiceRatio - $state->householdDebtServiceRatioEma);
        $state->creditToGdpGapEma += $emaWeight * ($state->creditToGdpGap - $state->creditToGdpGapEma);
        $state->countercyclicalBufferRateEma += $emaWeight * ($state->countercyclicalBufferRate - $state->countercyclicalBufferRateEma);
        $state->foreignPolicyRateEma += $emaWeight * ($state->foreignPolicyRate - $state->foreignPolicyRateEma);
        $state->globalDemandGapEma += $emaWeight * ($state->globalDemandGap - $state->globalDemandGapEma);
        $state->moneyMarketFundShareEma += $emaWeight * ($state->moneyMarketFundShare - $state->moneyMarketFundShareEma);
    }

    /**
     * The capital half of the 2D Kaldor-Kalecki phase space, whose other half is the output gap above.
     *
     * Stepped BEFORE the gap on each tick, because the gap's own capitalDrag term reads the level this
     * leaves behind; running it after would drag the gap with an overhang one tick out of date.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateCapitalStockOverhang(MacroState $state, float $dt): void
    {
        // Kaldor (1940) capital stock accumulation and physical depreciation dynamics.
        $state->capitalStockOverhang += (($state->outputGap * MacroEngine::CAPITAL_ACCUMULATION_RATE) - (MacroEngine::CAPITAL_DECAY_RATE * $state->capitalStockOverhang)) * $dt;
        $state->capitalStockOverhang = max(MacroEngine::CAPITAL_OVERHANG_MIN, min(MacroEngine::CAPITAL_OVERHANG_MAX, $state->capitalStockOverhang));
    }

    /**
     * Household equity wealth as a share of income: the whole board's capitalisation over nominal GDP, the
     * ratio the MPC-out-of-wealth literature is estimated on.
     *
     * Carries an arbitrary scale, because capitalisation is currency and nominal GDP is an index. That is
     * deliberate and costs nothing: the effect reads this against its own trend, and a constant factor
     * divides straight out of that comparison. Zero means no market has been reported.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    private function equityWealthRatio(MacroState $state): float
    {
        // Piketty & Zucman (2014) equity market capitalization to nominal GDP valuation ratio.
        if ($state->equityMarketCapEma <= 0.0) {
            return 0.0;
        }

        return $state->equityMarketCapEma / max(0.01, $state->nominalGdpIndex);
    }

    /**
     * Federal Reserve G.17 Industrial Capacity Utilization Index.
     *
     * Evaluates real aggregate physical factory, mining, and utility capacity utilization (CU_t)
     * based on macroeconomic output gap demand and capital stock overhang.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateCapacityUtilization(MacroState $state): void
    {
        $state->capacityUtilizationRate = $this->mathUtility->calculateCapacityUtilization(
            outputGap: $state->outputGap,
            capitalStockOverhang: $state->capitalStockOverhang,
            baselineCu: MacroEngine::CU_BASELINE,
            gapSensitivity: MacroEngine::CU_GAP_SENSITIVITY,
            overhangSensitivity: MacroEngine::CU_OVERHANG_SENSITIVITY
        );
    }

    /**
     * ISM / S&P Global Manufacturing Purchasing Managers' Index (PMI).
     *
     * Evaluates the headline diffusion index centered at 50.0. A diffusion index counts the share of firms
     * reporting improvement, so it tracks the rate of change of activity, not its level: ISM maps the headline
     * to annualized real GDP growth at ~0.3pp per index point. Real growth over potential is the annualized
     * output gap momentum, read off the gap's distance from its quarter-horizon EMA; capacity utilization,
     * Metzler inventory restocking demand and SLOOS bank credit standards are secondary level channels:
     *   Target = 50 + beta_g * (d(OutputGap)/dt) + beta_CU * (CU - CU*) + beta_inv * (-InventoryGap) - beta_sloos * SLOOS
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time step in years.
     */
    public function calculateManufacturingPmi(MacroState $state, float $dt): void
    {
        $cuDeviation = $state->capacityUtilizationRate - MacroEngine::CU_BASELINE;
        // Brown (1963) real GDP growth momentum derived from EMA output gap time-derivative.
        $excessGrowth = ($state->outputGap - $state->outputGapEma) / self::STANDARD_EMA_HORIZON_YEARS;
        $inventoryDemand = -$state->inventoryStockGap; // Shortfall stimulates orders
        $sloosStress = max(0.0, $state->sloosTighteningIndexEma);

        $drivers = [
            ['deviation' => $excessGrowth, 'sensitivity' => self::PMI_GROWTH_SENSITIVITY],
            ['deviation' => $cuDeviation, 'sensitivity' => self::PMI_CU_SENSITIVITY],
            ['deviation' => $inventoryDemand, 'sensitivity' => self::PMI_INVENTORY_SENSITIVITY],
            ['deviation' => -$sloosStress, 'sensitivity' => self::PMI_SLOOS_SENSITIVITY],
        ];

        $targetPmi = $this->mathUtility->calculateDiffusionIndex(
            baseline: MacroEngine::PMI_BASELINE,
            drivers: $drivers,
            min: MacroEngine::MIN_PMI,
            max: MacroEngine::MAX_PMI
        );

        // Gillespie (1996) exact Ornstein-Uhlenbeck discretization for PMI diffusion index.
        $dW = $this->mathUtility->generateStandardNormal();
        $decay = exp(-self::PMI_KAPPA * $dt);
        $drift = (1.0 - $decay) * ($targetPmi - $state->manufacturingPmi);
        $diffusion = self::PMI_SIGMA * sqrt((1.0 - ($decay ** 2)) / (2.0 * self::PMI_KAPPA)) * $dW;
        $newPmi = $state->manufacturingPmi + $drift + $diffusion;

        $state->manufacturingPmi = max(MacroEngine::MIN_PMI, min(MacroEngine::MAX_PMI, $newPmi));
    }

    /**
     * Stage-of-Processing Producer Price Index (PPI) Wholesale Inflation Pipeline (Clark 1995).
     *
     * Computes wholesale factory-gate price inflation driven by primary commodity input price shocks
     * (metals, energy, agriculture), ocean freight/logistics bottlenecks (GSCPI), Unit Labor Costs (ULC),
     * and cyclical output gap demand pressure.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Productivity growth potential output is built on (trend plus absorbed shocks).
     * @param float      $dt            Time step in years.
     */
    public function calculateProducerPriceInflation(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $unitLaborCost = $state->wageGrowth - $tfpGrowthRate;

        $weights = [
            'metals' => MacroEngine::PPI_METALS_WEIGHT,
            'energy' => MacroEngine::PPI_ENERGY_WEIGHT,
            'agri' => self::PPI_AGRI_WEIGHT,
            'gscpi' => self::PPI_GSCPI_SENSITIVITY,
            'ulc' => self::PPI_ULC_WEIGHT,
            'demand' => self::PPI_DEMAND_SENSITIVITY,
        ];

        $targetPpi = $this->mathUtility->calculateStageOfProcessingPpi(
            metalsInflation: $this->commodityInflation($state->industrialMetalsIndex, $state->industrialMetalsIndexTrend, self::PPI_METALS_PASS_THROUGH),
            energyInflation: $this->commodityInflation($state->energyPriceIndex, $state->energyPriceIndexTrend, self::PPI_ENERGY_PASS_THROUGH),
            agriInflation: $this->commodityInflation($state->agriculturalCommodityIndex, $state->agriculturalCommodityIndexTrend, self::PPI_AGRI_PASS_THROUGH),
            gscpiZ: $state->supplyChainPressureIndex,
            unitLaborCost: $unitLaborCost,
            outputGap: $state->outputGap,
            weights: $weights,
            min: self::MIN_PPI_INFLATION,
            max: MacroEngine::MAX_PPI_INFLATION
        );

        $dW = $this->mathUtility->generateStandardNormal();
        $diffusion = 0.003 * sqrt($dt) * $dW;
        $state->producerPriceInflation = max(self::MIN_PPI_INFLATION, min(MacroEngine::MAX_PPI_INFLATION, $targetPpi + $diffusion));
    }

    /**
     * Nominal inflation rate of one commodity input, at the share reaching factory-gate prices.
     *
     * The stage-of-processing legs (Clark 1995) take RATES, and each index is a stationary Schwartz-Smith
     * log-price whose long-run inflation is zero. The rate is its deviation from trend over the trend's
     * horizon, exact by Brown (1963) linear exponential smoothing, and needs no baseline constant.
     *
     * @param float $index       Current index level.
     * @param float $trend       The index's own long-run average, or 0.0 before the first observation.
     * @param float $passThrough Share of the move that reaches factory-gate prices.
     * @return float Annual nominal inflation rate contributed by this input.
     */
    private function commodityInflation(float $index, float $trend, float $passThrough): float
    {
        if ($trend <= 0.0) {
            return MacroEngine::TARGET_INFLATION;
        }

        $growthRate = (($index - $trend) / $trend) / self::COMMODITY_TREND_HORIZON_YEARS;

        return ($growthRate * $passThrough) + MacroEngine::TARGET_INFLATION;
    }
}
