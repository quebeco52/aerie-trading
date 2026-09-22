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
    /** Horizon of the lending-standards trend. Far longer than the 6-10y credit cycle it must leave intact, short enough to follow a regime change; the trend exists to strip a STANDING level, not a swing. */
    public const SLOOS_TREND_HORIZON_YEARS = 20.0;
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
    /** Sensitivity of aggregate demand to real interest rate deviations from natural rate. */
    public const KALDOR_MONETARY_DRAG = 1.30;
    /** Sensitivity of aggregate demand to wholesale credit spread and interbank liquidity friction (Bernanke-Gertler 1999). */
    public const KALDOR_CREDIT_FRICTION_DRAG = 0.25;
    /** Bank lending channel (Lown & Morgan 2006; Bassett, Chosak, Driscoll & Zakrajsek 2014): demand per unit of net lending tightening, so the ~80% of 2008 costs ~2pp a year while it lasts, the loss they attribute to the credit-supply cut. */
    public const KALDOR_LENDING_STANDARDS_DRAG = 0.025;
    /** Countercyclical fiscal stimulus multiplier from corporate tax rate cuts. */
    public const KALDOR_FISCAL_MULTIPLIER = 0.50;
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
    /** Innovation volatility of the aggregate demand disturbance, in annualized output gap drift units. */
    public const DEMAND_SHOCK_SIGMA = 0.0050;

    /** Stochastic micro-diffusion volatility of the output gap: realistic quarterly variance without breaking cycle phase. */
    public const OUTPUT_GAP_DIFFUSION_SIGMA = 0.0025;

    // --- Cyclical Output Gap Bounds ---
    /** Deepest slump the cycle may reach; below the worst postwar CBO gap (-8.8%, 2009Q2), so the bound guards runaway feedback rather than shaping the distribution. */
    public const OUTPUT_GAP_FLOOR = -0.12;
    /** Hottest the economy may run: above the postwar CBO peak (+5.6%, 1966Q1), since the one-sided cubic is what holds the upside. */
    public const OUTPUT_GAP_CEILING = 0.10;

    // --- Metzler-Blinder Inventory Investment Cycle (Metzler 1941, Blinder 1982) ---
    /** Sensitivity of output gap drift to involuntary inventory liquidation and restocking; inventory swings carry a large share of the peak-to-trough decline in a typical downturn. */
    public const METZLER_INVENTORY_DRAG = 0.10;
    /** Annual adjustment speed of firm inventory target replenishment. */
    public const INVENTORY_ADJUSTMENT_SPEED = 0.80;
    /** Sensitivity of involuntary inventory accumulation to unexpected output gap deceleration. */
    public const INVENTORY_SURPRISE_SENSITIVITY = 0.60;
    /** Cyclical target inventory sensitivity to real output gap demand. */
    public const INVENTORY_CYCLICAL_DEMAND_SENSITIVITY = 0.80;

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
    /** Structural upper bound ceiling for annual TFP growth rate. */
    public const MAX_TFP_GROWTH_RATE = 0.050;

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

    // --- Continuous EMA Indicator Smoothing Horizons ---
    /** Standard quarterly macro indicator EMA smoothing horizon. */
    public const STANDARD_EMA_HORIZON_YEARS = 0.25;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /** Records what moved the gap. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?OutputGapProbe $gapProbe = null
    ) {}

    /**
     * Solow-Swan (1956) & Romer (1990) Endogenous Growth with Merton (1976) Breakthrough Jumps.
     *
     * Simulates technological progress via secular drift, endogenous R&D capital deepening,
     * Brownian diffusion, and Schumpeterian general-purpose breakthrough jumps.
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

        $dW = $this->mathUtility->generateStandardNormal();
        $innovationDiffusion = self::TFP_VOLATILITY * sqrt($dt) * $dW;

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::TFP_JUMP_PROBABILITY,
            jumpMean: self::TFP_JUMP_MEAN,
            jumpVol: self::TFP_JUMP_VOL,
            dt: $dt
        );

        $jumpExponent = (float) ($jumpData['exponent'] ?? 0.0);

        // Merton (1976) jump-diffusion accumulation for technological progress.
        $logIncrement = ($clampedTrendGrowthRate * $dt) + $innovationDiffusion + $jumpExponent;

        $state->totalFactorProductivityIndex = max(1.0, $currentTfp * exp($logIncrement));

        return $clampedTrendGrowthRate;
    }

    /**
     * Laubach & Williams (2003) Dynamic Natural Rate of Interest (r*).
     *
     * Drifts equilibrium real rate r* tracking secular Total Factor Productivity (TFP)
     * growth deviations from long-term trend, via continuous Ornstein-Uhlenbeck adjustment.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Realized annual trend TFP growth rate.
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
     * Kaldor (1940) Non-Linear Business Cycle with Modigliani Wealth Effect & Marshall-Lerner FX Drag.
     *
     * Solves continuous macroeconomic aggregate demand dynamics:
     *   dy = [Momentum - CapacityCeiling(y>0) - RealRateDrag + FiscalStimulus - CapitalOverhang + WealthEffect - FxDrag - CrisisDeleveraging - LendingStandards + DemandShock] * dt
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
        $y = $state->outputGap;
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

        // Curdia & Woodford (2010) pure risk-free real monetary policy transmission stance.
        $monetaryDrag = self::KALDOR_MONETARY_DRAG * ($realRate - $neutralRealRate);

        // Bernanke, Gertler & Gilchrist (1999) financial accelerator wholesale credit frictions.
        $excessCreditSpread = max(-MacroEngine::BASE_CREDIT_SPREAD * 0.5, $state->macroCreditSpreadEma - MacroEngine::BASE_CREDIT_SPREAD);
        $excessInterbankSpread = max(-MacroEngine::INTERBANK_BASELINE_SPREAD * 0.5, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        $creditFrictionDrag = self::KALDOR_CREDIT_FRICTION_DRAG * ($excessCreditSpread + $excessInterbankSpread);

        $momentum = self::KALDOR_MOMENTUM * $y;
        // Kaldor (1940) non-linear asymmetric capacity ceiling constraint.
        $cubicConstraint = $y > 0.0 ? self::KALDOR_CAPACITY * pow($y, 3) : 0.0;
        // Blanchard & Perotti (2002) fiscal impulse via automatic stabilizers and discretionary spending.
        $spendingShift = ($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0;
        $fiscalStimulus = (self::KALDOR_FISCAL_MULTIPLIER * (MacroEngine::TARGET_CORPORATE_TAX_RATE - $state->corporateTaxRate))
            + (self::KALDOR_GOVT_SPENDING_MULTIPLIER * $spendingShift);
        // Bertola & Caballero (1994) asymmetric capital overhang drag reflecting investment irreversibility.
        $capitalDrag = $state->capitalStockOverhang >= 0.0
            ? self::KALDOR_CAPITAL_DRAG * $state->capitalStockOverhang
            : self::KALDOR_CAPITAL_REBOUND_DRAG * $state->capitalStockOverhang;

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


        // Every channel signed as it acts on demand, so a drag reads negative wherever it is looked at.
        // This array IS the drift: it is summed below and handed to the probe unchanged, so a channel
        // cannot reach the economy and miss the decomposition that explains it.
        $contributions = [
            'momentum' => $momentum,
            'cubicConstraint' => -$cubicConstraint,
            'monetaryDrag' => -$monetaryDrag,
            'creditFrictionDrag' => -$creditFrictionDrag,
            'fiscalStimulus' => $fiscalStimulus,
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
        ];

        $drift = array_sum($contributions) * $dt;
        $diffusion = self::OUTPUT_GAP_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $outZ;
        $preClampGap = $y + $drift + $diffusion;
        $newGap = max(self::OUTPUT_GAP_FLOOR, min(self::OUTPUT_GAP_CEILING, $preClampGap));

        $this->gapProbe?->record(
            terms: $contributions,
            diffusion: $diffusion,
            openingGap: $y,
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
        $state->consumerSentimentIndexEma += $emaWeight * ($state->consumerSentimentIndex - $state->consumerSentimentIndexEma);
        $state->exchangeRateIndexEma += $emaWeight * ($state->exchangeRateIndex - $state->exchangeRateIndexEma);
        $exchangeRateTrendWeight = 1.0 - exp(-$dt / self::EXCHANGE_RATE_TREND_HORIZON_YEARS);
        $state->exchangeRateTrend += $exchangeRateTrendWeight * ($state->exchangeRateIndexEma - $state->exchangeRateTrend);
        $state->industrialMetalsIndexEma += $emaWeight * ($state->industrialMetalsIndex - $state->industrialMetalsIndexEma);
        $state->governmentSpendingIndexEma += $emaWeight * ($state->governmentSpendingIndex - $state->governmentSpendingIndexEma);
        $state->retailDefaultRateEma += $emaWeight * ($state->retailDefaultRate - $state->retailDefaultRateEma);
        $state->agriculturalCommodityIndexEma += $emaWeight * ($state->agriculturalCommodityIndex - $state->agriculturalCommodityIndexEma);
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
        $state->cumulativeInflationGapEma += $emaWeight * ($state->cumulativeInflationGap - $state->cumulativeInflationGapEma);
        $state->highYieldCreditSpreadEma += $emaWeight * ($state->highYieldCreditSpread - $state->highYieldCreditSpreadEma);
        $state->inventoryStockGapEma += $emaWeight * ($state->inventoryStockGap - $state->inventoryStockGapEma);
        $state->energyInventoryIndexEma += $emaWeight * ($state->energyInventoryIndex - $state->energyInventoryIndexEma);
        $state->capacityUtilizationRateEma += $emaWeight * ($state->capacityUtilizationRate - $state->capacityUtilizationRateEma);
        $state->recessionProbabilityEma += $emaWeight * ($state->recessionProbability - $state->recessionProbabilityEma);
        $state->corporateDefaultRateEma += $emaWeight * ($state->corporateDefaultRate - $state->corporateDefaultRateEma);
        $state->sloosTighteningIndexEma += $emaWeight * ($state->sloosTighteningIndex - $state->sloosTighteningIndexEma);
        $sloosTrendWeight = 1.0 - exp(-$dt / self::SLOOS_TREND_HORIZON_YEARS);
        $state->sloosTighteningTrend += $sloosTrendWeight * ($state->sloosTighteningIndexEma - $state->sloosTighteningTrend);
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
     * @param float      $tfpGrowthRate Realized annual trend TFP growth rate.
     * @param float      $dt            Time step in years.
     */
    public function calculateProducerPriceInflation(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $metalsShift = ($state->industrialMetalsIndex - MacroEngine::METALS_BASELINE) / MacroEngine::METALS_BASELINE;
        $energyShift = ($state->energyPriceIndex - MacroEngine::ENERGY_BASELINE) / MacroEngine::ENERGY_BASELINE;
        $agriShift = ($state->agriculturalCommodityIndex - MacroEngine::AGRI_BASELINE) / MacroEngine::AGRI_BASELINE;

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
            metalsInflation: ($metalsShift * 0.50) + MacroEngine::TARGET_INFLATION,
            energyInflation: ($energyShift * 0.40) + MacroEngine::TARGET_INFLATION,
            agriInflation: ($agriShift * 0.30) + MacroEngine::TARGET_INFLATION,
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
}
