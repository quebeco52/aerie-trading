<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
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
    /** Demand impulse per unit of public spending above baseline (~20% GDP share x unit multiplier): +10% spending adds ~1pp/yr to the gap drift. */
    public const KALDOR_GOVT_SPENDING_MULTIPLIER = 0.10;
    /** Demand per unit of housing wealth above the level households are used to: Mian, Rao & Sufi (2013) put the MPC out of housing wealth at 5-7c on a stock worth 1.5-2x GDP. */
    public const KALDOR_WEALTH_EFFECT_ELASTICITY = 0.05;
    /** Elasticity to EQUITY wealth; Carroll-Otsuka-Slacalek (2011) put the MPC out of financial wealth at about half that out of housing. */
    public const KALDOR_EQUITY_WEALTH_ELASTICITY = 0.01;
    /** Years over which a valuation level stops being news and becomes the household's normal (Carroll et al. slow adjustment). */
    public const EQUITY_WEALTH_TREND_HORIZON_YEARS = 3.0;
    /** The same horizon for houses, longer because housing wealth is revalued by sales that are years apart (Carroll, Otsuka & Slacalek 2011); a house price cycle runs about twice this, so the cycle still reads as deviation. */
    public const RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS = 5.0;
    /** Horizon of the real exchange rate's own normal, well beyond the ~12y swing the UIP differential and the sovereign risk discount drive: a first-order lag this long follows under a tenth of that swing, so PPP deviations (Rogoff 1996: 3-5y half-life) survive intact while a standing level does not. */
    public const EXCHANGE_RATE_TREND_HORIZON_YEARS = 20.0;

    // --- Distributed Lag Transmission Constants ---
    /** Headline inflation per unit farm-price shock (~13% food CPI weight at ~15% pass-through), symmetric in both directions. */
    public const AGRI_COST_PUSH_TRANSMISSION = 0.020;

    // --- Sector Demand Factor ---
    /** Sensitivity of natural rate r* to annual secular TFP productivity growth deviations from drift. */
    public const NATURAL_RATE_TFP_SENSITIVITY = 0.50;
    /** Laubach-Williams sensitivity of natural rate r* to cyclical output gap investment demand. */
    public const NATURAL_RATE_OUTPUT_GAP_SENSITIVITY = 0.15;
    /** Speed of adjustment (kappa) of natural real rate toward fundamental equilibrium. */
    public const NATURAL_RATE_ADJUSTMENT_SPEED = 1.0;

    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /** Elasticity of aggregate demand to exchange rate deviations (Marshall-Lerner Net Export Drag). */
    public const KALDOR_FX_ELASTICITY = 0.04;
    /** Linear self-reinforcement of demand; at 0.12 zero was an unstable point and the gap swept through it on a clockwork limit cycle, at 0.06 it rests inside ±1% 44% of quarters (Frisch-Slutsky shock-driven cycle) while keeping the left skew. */
    public const KALDOR_MOMENTUM = 0.06;
    /** Cubic capacity ceiling on the UPSIDE only (Friedman 1993 plucking; Dupraz, Nakamura & Steinsson 2019): output is plucked below a ceiling it cannot run above, and a slump has no floor of its own. */
    public const KALDOR_CAPACITY = 600.0;
    /** Demand per unit of a RESTRICTIVE transmitted real-rate stance: tightening binds collateral constraints (Guerrieri & Iacoviello 2017); fitted with the accommodative slope, the premium legs and the rule by indirect inference (var/harness/asym_an.py: ACF, sd, rule, timing, US rate path, sign-split premium projection, Barnichon-Matthes 2018). The fit is flat from 1.3 to 3.5; 1.3 is the strongest whose deterministic ring-down stays clean -- above it a boom swings into a policy-made bust and back. */
    public const KALDOR_MONETARY_DRAG_RESTRICTIVE = 1.3;
    /** Demand per unit of an ACCOMMODATIVE stance, about half the restrictive slope: easing pushes on a string (Tenreyro & Thwaites 2016; Barnichon & Matthes 2018 put the expansionary peak at a third of the contractionary); the same fit. */
    public const KALDOR_MONETARY_DRAG_ACCOMMODATIVE = 0.7;
    /** Time constant of each of the two Pascal stages the real-rate stance passes through before it moves demand (Solow 1960): mean lag 0.8y against Rudebusch-Svensson's year average lagged a quarter (0.6y); the same fit. */
    public const MONETARY_TRANSMISSION_LAG_YEARS = 0.4;
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
    /** Demand per unit of household debt-service gap (Juselius & Drehmann 2015; Drehmann, Juselius & Korinek 2017): new borrowing lifts spending while service is below its average, and the service on the stock takes it back two to three years later; a point of income in extra service costs half a point of demand a year. */
    public const KALDOR_HOUSEHOLD_DEBT_SERVICE = 0.50;
    /** Demand from the foreign bloc's cycle: export volume per unit of foreign output gap (an export share of GDP near a fifth times an income elasticity of trade above one, Obstfeld & Rogoff 1996). */
    public const KALDOR_FOREIGN_DEMAND = 0.08;
    /** Output lost per unit of catastrophe loss burden above an average year (Noy 2009; Hsiang & Jina 2014 give the sign): a year at twice the average burden costs ~0.4pp of output, before the rebuild the construction stream books. */
    public const KALDOR_CATASTROPHE_DRAG = 0.004;
    /** Demand drag per log unit of policy uncertainty ABOVE baseline: a doubling costs ~0.4pp a year, so the 2006-2011 rise integrates to the ~1% output loss Baker, Bloom & Davis attribute to it. One-sided: spikes cost output (Bloom 2009), calm does not stimulate. */
    public const KALDOR_EPU_DRAG = 0.006;

    // --- Aggregate Demand Disturbance (Smets-Wouters 2007) ---
    /** Mean reversion speed of the aggregate demand disturbance: -4*ln(0.86) per year, from the estimated quarterly AR(1) coefficient. */
    public const DEMAND_SHOCK_REVERSION = 0.60;
    /** Innovation volatility of the aggregate demand disturbance, in annualized gap-drift units: one scale, shared with the disaster sizes below, set so the gap's sd and quarterly moves under the fitted loop are the CBO 1985-2019 1.63% and 0.53pp, the era the policy rule is fitted on, beside a ~1.0% TFP supply gap. */
    public const DEMAND_SHOCK_SIGMA = 0.0175;

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
    /** Characteristic half-life in years for food cost-push pass-through into core inflation. */
    public const AGRI_COST_PUSH_LAG_YEARS = 0.75;

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
        private readonly ?OutputGapProbe $gapProbe = null
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
     * Laubach & Williams (2003) Dynamic Natural Rate of Interest (r*).
     *
     * Drifts equilibrium real rate r* tracking secular Total Factor Productivity (TFP)
     * growth deviations from long-term trend, via continuous Ornstein-Uhlenbeck adjustment.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Productivity growth potential output is built on (trend plus absorbed shocks).
     * @param float      $dt            Time increment in years.
     */
    public function calculateNaturalRate(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        // Holston, Laubach & Williams (2017) natural rate tracking secular TFP drift and investment demand.
        $tfpEffect = self::NATURAL_RATE_TFP_SENSITIVITY * ($tfpGrowthRate - MacroEngine::TFP_DRIFT);
        $demandEffect = self::NATURAL_RATE_OUTPUT_GAP_SENSITIVITY * $state->outputGapEma;
        $targetNaturalRate = MacroEngine::BASE_NATURAL_RATE + $tfpEffect + $demandEffect;
        $targetNaturalRate = max(MacroEngine::MIN_NATURAL_RATE, min(MacroEngine::MAX_NATURAL_RATE, $targetNaturalRate));

        $state->naturalRate += self::NATURAL_RATE_ADJUSTMENT_SPEED * ($targetNaturalRate - $state->naturalRate) * $dt;
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
     * Solves continuous macroeconomic aggregate demand dynamics:
     *   dy = [Momentum - CapacityCeiling(y>0) - RealRateDrag + FiscalStimulus + AutomaticStabilisers - CapitalOverhang + WealthEffect - FxDrag - CrisisDeleveraging - LendingStandards + DemandShock] * dt
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
        // The gap is demand plus the supply part a productivity shock opens (absorbProductivityShocks). The demand
        // equation below runs on the demand part only: momentum, stabilisers and drags answer spending, and the
        // supply part already carries its own measured closing path.
        $openingGap = $state->outputGap;
        $y = $openingGap - $state->productivitySupplyGap;
        $supplyGap = $state->tfpOutputStage2 - $state->tfpPotentialAbsorbed;
        $supplyChange = $supplyGap - $state->productivitySupplyGap;
        $state->productivitySupplyGap = $supplyGap;
        // Smets & Wouters (2007) estimate the demand disturbance and the measurement-frequency
        // component as separate innovations; one draw serving both correlates them at unity.
        $demandZ = $this->mathUtility->generateStandardNormal();
        $outZ = $this->mathUtility->generateStandardNormal();

        // Curdia & Woodford (2010) ex-ante real borrowing cost deflated by expected inflation.
        // Strips inflation risk premium from TIPS breakeven on the 5-year leg.
        $expectedInflation5y = $state->tipsBreakeven - $this->inflationRiskPremium($state, MacroEngine::TARGET_INFLATION);
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
        // it bends the cycle without shifting its average, whose cost belongs in potential, not the gap.
        $adversePremiumMean = $this->stationaryAdversePremium();
        $premiumDrag = $this->mathUtility->calculateAsymmetricResponse($state->excessBondPremium, self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_ADVERSE, self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_FAVORABLE)
            - (self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_ADVERSE * $adversePremiumMean)
            - (self::KALDOR_EXCESS_BOND_PREMIUM_DRAG_FAVORABLE * ($this->stationaryPremiumMean() - $adversePremiumMean));

        $momentum = self::KALDOR_MOMENTUM * $y;
        // Kaldor (1940) non-linear asymmetric capacity ceiling constraint.
        $cubicConstraint = $y > 0.0 ? self::KALDOR_CAPACITY * pow($y, 3) : 0.0;
        // Blanchard & Perotti (2002) DISCRETIONARY fiscal impulse: a statutory rate and an appropriated
        // outlay both clear a legislative lag, so this leg reaches demand late by construction.
        $spendingShift = ($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0;
        $discretionaryFiscal = (self::KALDOR_FISCAL_MULTIPLIER * (MacroEngine::TARGET_CORPORATE_TAX_RATE - $state->corporateTaxRate))
            + (self::KALDOR_GOVT_SPENDING_MULTIPLIER * $spendingShift);

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
        // Against the currency's own trend, not the nominal PPP baseline. targetFx carries a rectified
        // safe-haven bid and a one-sided sovereign risk discount, so the index cannot average 100 by
        // construction -- it settles ~97.3 -- and read off a fixed 100 the Mundell-Fleming term became a
        // permanent +0.11 pp/yr of demand instead of a cyclical one. Same fix as the housing wealth effect
        // and the credit equation's SLOOS term.
        $fxShift = $state->exchangeRateTrend > 0.0
            ? ($state->exchangeRateIndexEma / $state->exchangeRateTrend) - 1.0
            : 0.0;
        // Mundell-Fleming net export drag via real exchange rate elasticity and foreign demand.
        $netExportDrag = (self::KALDOR_FX_ELASTICITY * $fxShift) - (self::KALDOR_FOREIGN_DEMAND * $state->foreignOutputGapEma);

        // Bruno & Sachs (1985) and Blanchard & Gali (2007) symmetric energy supply shock drag.
        $energyShock = $state->energyPriceShock != 0.0
            ? $state->energyPriceShock
            : (($state->energyPriceIndexEma > 0.0 ? $state->energyPriceIndexEma : $state->energyPriceIndex) - MacroEngine::ENERGY_BASELINE);
        $energySupplyShift = $energyShock / MacroEngine::ENERGY_BASELINE;
        $energySupplyDrag = $energySupplyShift * self::KALDOR_ENERGY_SUPPLY_DRAG;

        $freightRate = $state->freightRateIndexEma > 0.0 ? $state->freightRateIndexEma : $state->freightRateIndex;
        $freightSupplyShift = ($freightRate - MacroEngine::FREIGHT_BASELINE) / MacroEngine::FREIGHT_BASELINE;
        $freightSupplyDrag = $freightSupplyShift * self::KALDOR_FREIGHT_SUPPLY_DRAG;

        // Drehmann, Juselius & Korinek (2017) household debt service drag on demand.
        $householdDeleveragingDrag = self::KALDOR_HOUSEHOLD_DEBT_SERVICE * $state->householdDebtServiceGap;

        // Hallegatte et al. (2007) physical capital destruction supply drag.
        $catastropheSupplyDrag = self::KALDOR_CATASTROPHE_DRAG * max(0.0, $state->catastropheLossIndexEma - 1.0);

        // Jordà, Schularick & Taylor (2013) post-crisis balance sheet deleveraging drag.
        $crisisDeleveragingDrag = $state->creditCrisisDrag;
        // Bernanke & Blinder (1988) bank lending channel credit standards quantity constraint.
        $lendingStandardsDrag = self::KALDOR_LENDING_STANDARDS_DRAG * $state->sloosTighteningIndexEma;

        // Baker, Bloom & Davis (2016) real options investment deferral under policy uncertainty.
        $policyUncertaintyDrag = self::KALDOR_EPU_DRAG * max(0.0, log(max(1.0, $state->policyUncertaintyIndexEma) / MacroEngine::EPU_BASELINE));

        // Metzler (1941) & Blinder (1982) inventory investment cycle step.
        $state->inventoryStockGap = $this->mathUtility->calculateInventoryCycleStep(
            currentInventoryGap: $state->inventoryStockGap,
            outputGap: $y,
            outputGapEma: $state->outputGapEma,
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
        $state->demandShock += $this->mathUtility->calculateCompensatedKouJump(
            lambda: self::DEMAND_DISASTER_INTENSITY,
            pUp: self::DEMAND_DISASTER_UP_PROBABILITY,
            etaUp: self::DEMAND_DISASTER_UP_RATE,
            etaDown: self::DEMAND_DISASTER_DOWN_RATE,
            cap: self::DEMAND_DISASTER_CAP,
            dt: $dt
        );

        // Every channel signed as it acts on demand, so a drag reads negative wherever it is looked at.
        // This array IS the drift: it is summed below and handed to the probe unchanged, so a channel
        // cannot reach the economy and miss the decomposition that explains it.
        $contributions = [
            'momentum' => $momentum,
            'cubicConstraint' => -$cubicConstraint,
            'monetaryDrag' => -$monetaryDrag,
            'creditFrictionDrag' => -$creditFrictionDrag,
            'premiumDrag' => -$premiumDrag,
            'fiscalStimulus' => $discretionaryFiscal,
            'automaticStabiliser' => $automaticStabiliser,
            'capitalDrag' => -$capitalDrag,
            'inventoryDrag' => -$inventoryDrag,
            'housingWealthEffect' => $housingWealthEffect,
            'equityWealthEffect' => $equityWealthEffect,
            'netExportDrag' => -$netExportDrag,
            'energySupplyDrag' => -$energySupplyDrag,
            'freightSupplyDrag' => -$freightSupplyDrag,
            'policyUncertaintyDrag' => -$policyUncertaintyDrag,
            'catastropheSupplyDrag' => -$catastropheSupplyDrag,
            'householdDeleveragingDrag' => -$householdDeleveragingDrag,
            'crisisDeleveragingDrag' => -$crisisDeleveragingDrag,
            'lendingStandardsDrag' => -$lendingStandardsDrag,
            'demandShock' => $state->demandShock,
            // Basu, Fernald & Kimball (2006): output catches up with a technology gain before potential does.
            'productivitySupply' => $supplyChange / $dt,
        ];

        $drift = array_sum($contributions) * $dt;
        $diffusion = self::OUTPUT_GAP_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $outZ;
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

        // Shapiro (2022) Sector 2: Core goods intermediate supply chain and materials cost pressures.
        $freightShift = ($state->freightRateIndexEma / MacroEngine::FREIGHT_BASELINE) - 1.0;
        $metalsShift = ($state->industrialMetalsIndexEma / MacroEngine::METALS_BASELINE) - 1.0;
        $gscpiFriction = max(-0.01, $state->supplyChainPressureIndexEma * self::CORE_GOODS_GSCPI_SENSITIVITY);
        $goodsSupplyFriction = ($freightShift * self::CORE_GOODS_FREIGHT_SENSITIVITY) + ($metalsShift * self::CORE_GOODS_METALS_SENSITIVITY) + $gscpiFriction;
        $targetCoreGoods = $targetInflation + $anchorSlip + (self::CORE_GOODS_DEMAND_SENSITIVITY * $convexDemandPressure) + $goodsSupplyFriction;

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

        // Shapiro (2022) commodity basket aggregation normalized by expenditure weight.
        $commodityBasketInflation = $targetInflation + $anchorSlip
            + (($state->energyCostPushLag + $state->agriCostPushLag) / self::INFLATION_WEIGHT_COMMODITY);

        // Shapiro (2022) expenditure-weighted headline consumer price aggregation.
        $blendedInflation = (self::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflation)
            + (self::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflation)
            + (self::INFLATION_WEIGHT_COMMODITY * $commodityBasketInflation);

        $newInflation = $blendedInflation + (self::INFLATION_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $infZ);
        return max(-0.02, min(0.25, $newInflation));
    }

    /**
     * Gurkaynak, Sack & Wright (2010) TIPS Breakeven Inflation Expectation Model.
     *
     * Derives market-implied 10Y forward inflation expectations by weighting anchored central bank
     * targets, adaptive core trends, forward Phillips curve capacity, and inflation volatility risk premium.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @param float      $dt              Time increment in years.
     * @return float 10-Year TIPS breakeven inflation expectation.
     */
    public function calculateTipsBreakeven(MacroState $state, float $targetInflation, float $dt): float
    {
        $cyclicalForecast = $this->mathUtility->calculateConvexPhillipsCurve(
            outputGap: $state->outputGapEma,
            maxCapacity: self::PHILLIPS_MAX_CAPACITY,
            kappa: self::PHILLIPS_CONVEX_KAPPA,
            downwardRigidityFactor: self::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR
        );

        $inflationRiskPremium = $this->inflationRiskPremium($state, $targetInflation);

        $fundamentalBreakeven = (self::TIPS_TARGET_WEIGHT * $targetInflation)
            + (self::TIPS_TREND_WEIGHT * $state->inflationEma)
            + (self::TIPS_CYCLICAL_WEIGHT * ($targetInflation + $cyclicalForecast))
            + $inflationRiskPremium;

        return max(-0.01, min(0.15, $fundamentalBreakeven));
    }

    /**
     * Pflueger & Viceira (2011) inflation risk premium: upside inflation uncertainty from realized inflation above
     * target and cost-push supply shocks. Part of the breakeven, not of expected inflation.
     */
    private function inflationRiskPremium(MacroState $state, float $targetInflation): float
    {
        $excessInflation = max(0.0, $state->inflationEma - $targetInflation);
        $costPushStress = $state->energyCostPushLag + $state->agriCostPushLag;

        return ($excessInflation + $costPushStress) * self::TIPS_INFLATION_RISK_PREMIUM_SCALE;
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

        $realPotentialGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + $tfpGrowthRate;
        $currentPotential = $state->potentialGdpIndex > 0.0 ? $state->potentialGdpIndex : 1.0;
        $state->potentialGdpIndex = max(0.10, $currentPotential * exp($realPotentialGrowth * $dt));

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
