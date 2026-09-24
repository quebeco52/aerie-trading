<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * Handles commodity price dynamics and global logistics.
 * Implements Schwartz (1997), Schwartz-Smith (2000), and Stopford (2009) shipping econometric models.
 */
class CommodityLogisticsSubsystem
{
    // --- Energy Shock Jump-Diffusion (Schwartz 1997 Commodity Dynamics) ---
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

    // --- Energy Supply Cobweb (Ezekiel 1938; Anderson, Kellogg & Salant 2018) ---
    /** Income elasticity of energy demand to the output gap (~0.5, Hamilton 2009; Caldara, Cavallo & Iacoviello 2019). */
    public const ENERGY_DEMAND_GAP_SENSITIVITY = 0.50;
    /** Long-run supply elasticity of productive capacity to the price level (~0.3: the drilling and investment response, Caldara, Cavallo & Iacoviello 2019). */
    public const ENERGY_SUPPLY_ELASTICITY = 0.30;
    /** Years for capacity to follow price: the drilling, sanctioning and commissioning lag (Anderson, Kellogg & Salant 2018). */
    public const ENERGY_SUPPLY_LAG_YEARS = 3.0;
    /** Price response to the demand-over-capacity ratio: the inverse of the short-run demand and supply elasticities combined (Kilian & Murphy 2014: ~0.26 + 0.1), so a 1% shortfall clears at ~2.5% more. */
    public const ENERGY_CAPACITY_INELASTICITY = 2.5;
    /** Bounds on the equilibrium the spot price reverts to, so the cobweb cannot demand a price no market has cleared at. */
    public const MIN_ENERGY_EQUILIBRIUM = 40.0;
    /** Upper bound on the cobweb's equilibrium price. */
    public const MAX_ENERGY_EQUILIBRIUM = 250.0;

    // --- Physical Catastrophes (compound Poisson, Pareto severity) ---
    /** Notable insured-loss events per year across the district (Swiss Re sigma counts a few dozen a year in a continental economy; scaled to one district). */
    public const CATASTROPHE_ARRIVAL_PER_YEAR = 3.0;
    /** Decay per year of the loss burden index: claims are recognised and paid over a few quarters, so a quarter after a storm most of it has left the index. */
    public const CATASTROPHE_LOSS_DECAY = 2.0;
    /** Pareto tail index of event severity (~1.7): the mean exists, the variance does not, as insured catastrophe losses show. */
    public const CATASTROPHE_SEVERITY_ALPHA = 1.7;
    /** Cap on a single event in average-year units (twenty average years of losses in one storm: the one-in-a-few-centuries event). */
    public const CATASTROPHE_SEVERITY_CAP = 20.0;
    /** Ceiling on the loss index itself. */
    public const MAX_CATASTROPHE_LOSS_INDEX = 50.0;
    /** Single-event insured loss (in average-year units) that makes district news: ~1.5 years of average losses in one storm, roughly a one-in-six-years event on the Pareto tail. */
    public const SYSTEMIC_CATASTROPHE_SEVERITY = 1.5;

    // --- Natural Gas (Pilipovic 1998; Ramberg & Parsons 2012) ---
    /** Mean reversion of the log gas-to-oil ratio: the two are cointegrated but the relationship drifts for a year or so at a time (Ramberg & Parsons 2012). */
    public const GAS_OIL_RATIO_KAPPA = 0.70;
    /** Annual log volatility of the ratio (~0.30 stationary log spread): gas runs about half again as volatile as oil, and the difference is the ratio's own noise. */
    public const GAS_OIL_RATIO_SIGMA = 0.35;
    /** Winter premium on the gas index (Pilipovic 1998 seasonal factor): heating demand lifts the winter price ~12% over the annual mean. */
    public const GAS_SEASONALITY_AMPLITUDE = 0.12;
    /** Arrivals per year of storage or weather squeezes (a February freeze, a filled-storage collapse) that move gas without oil. */
    public const GAS_JUMP_PROBABILITY = 0.15;
    /** Mean log size of a gas squeeze (~28%); compensated in the ratio's target so squeezes do not lift the average. */
    public const GAS_JUMP_MEAN = 0.25;
    /** Log volatility of a gas squeeze. */
    public const GAS_JUMP_VOL = 0.15;

    // --- Wholesale Power (Lucia & Schwartz 2002; EIA ICE on-peak hubs vs Henry Hub, monthly 2017-2025) ---
    /** Log change in wholesale power per log change in gas: 0.852 (se 0.081) across four gas-marginal hubs (PJM West, ISO-NE Mass Hub, SP15, Palo Verde). */
    public const POWER_GAS_ELASTICITY = 0.852;
    /** Mean reversion of the log implied heat rate, fitted to its quarterly means (sd 0.183, AR1 0.37) as a time-averaged OU: weather and outages clear within weeks. */
    public const POWER_HEAT_RATE_KAPPA = 7.04;
    /** Annual log volatility of the implied heat rate, from the same quarterly fit. */
    public const POWER_HEAT_RATE_SIGMA = 0.883;
    /** Annual harmonic of the power season (level profile): the summer air-conditioning peak outweighs the winter one. */
    public const POWER_SEASONAL_ANNUAL_AMPLITUDE = 0.157;
    /** Year fraction at which the annual harmonic peaks. */
    public const POWER_SEASONAL_ANNUAL_PEAK = 0.719;
    /** Semi-annual harmonic of the power season: twin winter and summer load peaks around spring and autumn shoulders. */
    public const POWER_SEASONAL_SEMIANNUAL_AMPLITUDE = 0.246;
    /** Year fraction of the first semi-annual peak (late January); the second falls half a year later. */
    public const POWER_SEASONAL_SEMIANNUAL_PEAK = 0.064;
    /** Wholesale power in $/MWh at the index baseline: the four-hub on-peak mean, 2017-2025. */
    public const REFERENCE_POWER_PRICE = 48.91;
    /** Henry Hub in $/MMBtu at the gas index baseline: its monthly mean over the same years. */
    public const REFERENCE_GAS_PRICE = 3.26;
    /** Heat rate (MMBtu/MWh) EIA quotes the spark spread at: a new, efficient combined-cycle plant. */
    public const SPARK_SPREAD_BENCHMARK_HEAT_RATE = 7.0;

    // --- Gold (Barsky, Epstein, Lafont-Mueller & Yoo 2021, Chicago Fed Letter 464) ---
    /** Log change in the real gold price per unit of real ten-year yield: -0.131 per percentage point (annual levels regression, 1971-2019). */
    public const GOLD_REAL_RATE_SEMI_ELASTICITY = -13.1;
    /** Log change in the real gold price per unit of ten-year expected inflation: +0.365 per percentage point, the inflation-hedge motive. */
    public const GOLD_INFLATION_EXPECTATION_SEMI_ELASTICITY = 36.5;
    /** Log change in the real gold price per percentage point of survey respondents expecting bad times over the next five years. */
    public const GOLD_PESSIMISM_SEMI_ELASTICITY = 0.012;
    /** Percentage points of pessimistic respondents per point of consumer sentiment: the survey's 30-to-60% pessimism swing matched to the sentiment index's ~57-point range over the same years (an approximation; both series come from the Michigan survey). */
    public const PESSIMISM_PER_SENTIMENT_POINT = 0.53;
    /** Real ten-year yield the engine rests at (measured mean over a 49-year run), so the index centres on its baseline rather than on a level the engine never visits. */
    public const GOLD_REAL_RATE_ANCHOR = 0.028;
    /** Ten-year expected inflation the engine rests at (measured mean over the same run). */
    public const GOLD_INFLATION_EXPECTATION_ANCHOR = 0.022;
    /** Annual reversion toward the equilibrium: the levels regression's Durbin-Watson of 0.98 is a residual autocorrelation of 1 - DW/2 = 0.51 a year, so kappa = -ln 0.51. */
    public const GOLD_MEAN_REVERSION = 0.67;
    /** Annual log volatility of the gold price: its annualised volatility has stayed within 10-18% on most days since 1971 (World Gold Council). */
    public const GOLD_VOLATILITY = 0.15;
    /** Floor on the gold index. */
    public const MIN_GOLD_INDEX = 20.0;
    /** Ceiling on the gold index. */
    public const MAX_GOLD_INDEX = 500.0;

    // --- Theory of Storage & Commodity Buffer Stocks (Working 1949, Litzenberger-Rabinowitz 1995) ---
    /** Critical minimum physical buffer stock floor before extreme convenience yield spike. */
    public const COMMODITY_MIN_BUFFER_STOCK = 50.0;
    /** Annual mean-reversion speed of physical inventories toward structural baseline. */
    public const COMMODITY_INVENTORY_REVERSION_SPEED = 0.50;
    /** Sensitivity of inventory drawdown to economic output gap and geopolitical supply shocks. */
    public const COMMODITY_INVENTORY_DRAWDOWN_SENSITIVITY = 1.20;

    // --- 2-FACTOR CORRELATED OU INDUSTRIAL COMMODITIES ---
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

    // --- 2-FACTOR CORRELATED OU AGRICULTURAL COMMODITIES & WEATHER JUMPS ---
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

    // --- 3:2:1 Refining Crack Spread (EIA Gulf Coast gasoline & ULSD vs WTI, monthly 2010-2024) ---
    /** Annual mean reversion of the de-seasonalised log crack: its monthly autocorrelation of 0.889 is kappa = -12 ln 0.889 (half-life ~6 months). */
    public const CRACK_SPREAD_KAPPA = 1.41;
    /** Annual log volatility of the crack: the de-seasonalised log dispersion of 0.444 times sqrt(2 kappa). */
    public const CRACK_SPREAD_SIGMA = 0.75;
    /** Seasonal swing of the crack around its annual mean: the first harmonic of the monthly profile (driving season against the autumn trough). */
    public const CRACK_SEASONAL_AMPLITUDE = 0.146;
    /** Year fraction at which the seasonal crack peaks (early June). */
    public const CRACK_SEASONAL_PEAK = 0.46;

    public function __construct(
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * Schwartz (1997) One-Factor Mean-Reverting Commodity Model with Poisson Supply Jumps
     * and Working (1949) / Litzenberger & Rabinowitz (1995) Theory of Storage Convenience Yield.
     *
     * Simulates global retail energy commodity prices (crude oil & refined products benchmark)
     * using mean-reverting log-prices driven by Ornstein-Uhlenbeck drift, geopolitical supply disruption jumps,
     * and non-linear convenience yield backwardation spikes when physical inventory buffer stocks deplete.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateEnergyShock(MacroState $state, float $dt): void
    {
        // Ezekiel (1938) cobweb dynamics: capacity chases lagged prices while spot market clears demand.
        $priceSeen = $state->energyPriceIndexEma > 0.0 ? $state->energyPriceIndexEma : MacroEngine::ENERGY_BASELINE;
        $targetSupply = MacroEngine::ENERGY_BASELINE * (($priceSeen / MacroEngine::ENERGY_BASELINE) ** self::ENERGY_SUPPLY_ELASTICITY);
        $supplyWeight = 1.0 - exp(-$dt / self::ENERGY_SUPPLY_LAG_YEARS);
        $state->energySupplyEma += $supplyWeight * ($targetSupply - $state->energySupplyEma);

        $equilibriumPrice = self::resolveEnergyEquilibriumPrice($state->globalDemandGapEma, $state->energySupplyEma);

        $dW = $this->mathUtility->generateStandardNormal();
        $currentBase = $state->energyBasePrice > 0.0 ? $state->energyBasePrice : $state->energyPriceIndex;
        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $currentBase,
            kappa: self::ENERGY_MEAN_REVERSION,
            theta: $equilibriumPrice,
            sigma: self::ENERGY_VOLATILITY,
            dt: $dt,
            dW: $dW
        );
        $state->energyBasePrice = max(10.0, min(250.0, $baseProcess));

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::ENERGY_JUMP_PROBABILITY,
            jumpMean: self::ENERGY_JUMP_MEAN,
            jumpVol: self::ENERGY_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }

        // Litzenberger & Rabinowitz (1995) physical commodity inventory buffer stock evolution.
        $demandDraw = $state->globalDemandGapEma * self::COMMODITY_INVENTORY_DRAWDOWN_SENSITIVITY * 100.0;
        $shockDraw = ($jumpData['multiplier'] > 1.0) ? (log($jumpData['multiplier']) * 40.0) : 0.0;
        $reversionFlow = self::COMMODITY_INVENTORY_REVERSION_SPEED * (MacroEngine::COMMODITY_INVENTORY_BASELINE - $state->energyInventoryIndex);
        $dInventory = ($reversionFlow - $demandDraw - $shockDraw) * $dt;
        $state->energyInventoryIndex = max(self::COMMODITY_MIN_BUFFER_STOCK, min(160.0, $state->energyInventoryIndex + $dInventory));

        // Working (1949) theory of storage non-linear convenience yield backwardation.
        $convenienceYield = $this->mathUtility->calculateConvenienceYield(
            inventoryLevel: $state->energyInventoryIndex,
            minBufferStock: self::COMMODITY_MIN_BUFFER_STOCK
        );
        $conveniencePricePremium = MacroEngine::ENERGY_BASELINE * $convenienceYield;

        $state->energyPriceIndex = max(10.0, min(350.0, $baseProcess + $jumpAmount + $conveniencePricePremium));
        $state->energyPriceShock = $state->energyPriceIndex - MacroEngine::ENERGY_BASELINE;
    }

    /**
     * The price the energy spot process reverts to: global demand over lagging productive capacity, cleared at
     * the combined short-run inelasticity (Ezekiel 1938 cobweb; Kilian & Murphy 2014). It is the theta of the
     * Schwartz (1997) base process, so a producer striking forwards off the same curve reads it from here.
     */
    public static function resolveEnergyEquilibriumPrice(float $globalDemandGapEma, float $energySupplyEma): float
    {
        $demandIndex = MacroEngine::ENERGY_BASELINE * (1.0 + ($globalDemandGapEma * self::ENERGY_DEMAND_GAP_SENSITIVITY));
        $utilization = max(0.20, $demandIndex) / max(20.0, $energySupplyEma);

        return max(self::MIN_ENERGY_EQUILIBRIUM, min(self::MAX_ENERGY_EQUILIBRIUM, MacroEngine::ENERGY_BASELINE * ($utilization ** self::ENERGY_CAPACITY_INELASTICITY)));
    }

    /**
     * Physical catastrophe losses as a compound Poisson process with Pareto severity (Klugman, Panjer &
     * Willmot, Loss Models), seasonal in arrival.
     *
     * The index is the district's recent insured loss burden in units of an average year: every event adds
     * its severity, and the burden decays as claims are settled, so the stationary mean is
     * lambda x mean severity / decay, which the severity scale is set to make exactly one. One district-wide
     * draw is what correlates every insurer's claims, the builders' rebuild orders and the property damage
     * that a per-firm claim draw could never produce. A single event large enough to make the news records
     * its tick for the district event pulse.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCatastropheLosses(MacroState $state, float $dt): void
    {
        $quarter = (((int) floor($state->totalTime * 4.0)) % 4 + 4) % 4;
        $seasonalFrequency = MacroEngine::CATASTROPHE_SEASONALITY[$quarter] ?? 1.0;
        $eventCount = $this->mathUtility->generatePoissonCount(self::CATASTROPHE_ARRIVAL_PER_YEAR * $seasonalFrequency * $dt);

        // Pickands (1975) extreme value theory Pareto severity calibration for catastrophe loss index.
        $meanSeverity = self::CATASTROPHE_LOSS_DECAY / self::CATASTROPHE_ARRIVAL_PER_YEAR;
        $severityScale = $meanSeverity * (self::CATASTROPHE_SEVERITY_ALPHA - 1.0) / self::CATASTROPHE_SEVERITY_ALPHA;

        $state->catastropheLossIndex *= exp(-self::CATASTROPHE_LOSS_DECAY * $dt);

        $largestEvent = 0.0;
        for ($i = 0; $i < $eventCount; $i++) {
            $severity = $this->mathUtility->generateParetoSeverity($severityScale, self::CATASTROPHE_SEVERITY_ALPHA, self::CATASTROPHE_SEVERITY_CAP);
            $state->catastropheLossIndex += $severity;
            $largestEvent = max($largestEvent, $severity);
        }

        if ($largestEvent >= self::SYSTEMIC_CATASTROPHE_SEVERITY) {
            $state->lastCatastropheAt = $state->totalTime;
            $state->lastCatastropheSeverity = $largestEvent;
        }

        $state->catastropheLossIndex = min(self::MAX_CATASTROPHE_LOSS_INDEX, $state->catastropheLossIndex);
    }

    /**
     * Natural gas as oil times a stationary ratio (Ramberg & Parsons 2012), with Pilipovic's seasonal factor.
     *
     * Gas and oil are cointegrated: the level follows oil, so every oil jump reaches gas through the product,
     * while the ratio wanders on its own with more noise than oil has and squeezes of its own. The ratio is a
     * log mean-reverting state around unity, the winter premium is a deterministic annual cycle peaking at
     * the turn of the year, and the ratio's target is set so the index averages the oil index, not its median.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateNaturalGasIndex(MacroState $state, float $dt): void
    {
        // Schwartz (1997) one-factor log-price mean-reversion drift with jump compensator.
        $ratioTarget = exp(
            ((self::GAS_OIL_RATIO_SIGMA ** 2) / (4.0 * self::GAS_OIL_RATIO_KAPPA))
                - (self::GAS_JUMP_PROBABILITY * self::GAS_JUMP_MEAN / self::GAS_OIL_RATIO_KAPPA)
        );
        $ratio = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: exp($state->gasOilRatioLog),
            kappa: self::GAS_OIL_RATIO_KAPPA,
            theta: $ratioTarget,
            sigma: self::GAS_OIL_RATIO_SIGMA,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::GAS_JUMP_PROBABILITY,
            jumpMean: self::GAS_JUMP_MEAN,
            jumpVol: self::GAS_JUMP_VOL,
            dt: $dt
        );

        $state->gasOilRatioLog = max(-2.0, min(2.0, log($ratio * $jumpData['multiplier'])));

        $timeOfYear = fmod($state->totalTime, 1.0);
        $seasonalMultiplier = 1.0 + (self::GAS_SEASONALITY_AMPLITUDE * cos(2.0 * M_PI * $timeOfYear));

        $spot = ($state->energyPriceIndex / MacroEngine::ENERGY_BASELINE) * MacroEngine::NATURAL_GAS_BASELINE * exp($state->gasOilRatioLog) * $seasonalMultiplier;
        $state->naturalGasPriceIndex = max(10.0, min(500.0, $spot));
    }

    /**
     * Wholesale power as gas passed through at the market heat rate (Lucia & Schwartz 2002, one-factor log spot).
     *
     * Gas sets the clearing price in most hours, so power moves with it at the log elasticity the hubs show. The
     * implied heat rate on top (which plant is marginal, weather, outages) is a fast log-OU around its mean times a
     * deterministic twin-peak load season. Its target is compensated by exp(sigma^2 / 4 kappa), so the index
     * averages its baseline rather than settling at its median.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateWholesalePowerIndex(MacroState $state, float $dt): void
    {
        $heatRate = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: exp($state->powerHeatRateLog),
            kappa: self::POWER_HEAT_RATE_KAPPA,
            theta: exp((self::POWER_HEAT_RATE_SIGMA ** 2) / (4.0 * self::POWER_HEAT_RATE_KAPPA)),
            sigma: self::POWER_HEAT_RATE_SIGMA,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );
        $state->powerHeatRateLog = max(-3.0, min(3.0, log($heatRate)));

        $gasRelative = max(0.01, $state->naturalGasPriceIndex / MacroEngine::NATURAL_GAS_BASELINE);
        $spot = MacroEngine::WHOLESALE_POWER_BASELINE * ($gasRelative ** self::POWER_GAS_ELASTICITY)
            * exp($state->powerHeatRateLog) * self::resolvePowerSeasonalFactor($state->totalTime);
        $state->wholesalePowerPriceIndex = max(5.0, min(800.0, $spot));
    }

    /** The load-season multiplier on wholesale power at a point in simulated time (years); it averages one over the year. */
    public static function resolvePowerSeasonalFactor(float $totalTime): float
    {
        $timeOfYear = fmod($totalTime, 1.0);

        return 1.0
            + (self::POWER_SEASONAL_ANNUAL_AMPLITUDE * cos(2.0 * M_PI * ($timeOfYear - self::POWER_SEASONAL_ANNUAL_PEAK)))
            + (self::POWER_SEASONAL_SEMIANNUAL_AMPLITUDE * cos(4.0 * M_PI * ($timeOfYear - self::POWER_SEASONAL_SEMIANNUAL_PEAK)));
    }

    /**
     * The real gold price the market clears at given long real rates, inflation expectations and how bad the
     * public thinks the next five years will be (Barsky, Epstein, Lafont-Mueller & Yoo 2021, annual levels).
     *
     * Gold pays nothing, so a higher real yield raises what holding it forgoes; it is bought against inflation
     * and against bad times. The world-GDP trend in their regression is carried here by the firms' capital,
     * and its cyclical part is insignificant in their quarterly news regression, so it is left out.
     */
    public static function resolveGoldEquilibriumPrice(float $realTenYearYield, float $expectedInflation, float $consumerSentiment): float
    {
        $pessimism = -self::PESSIMISM_PER_SENTIMENT_POINT * ($consumerSentiment - MacroEngine::SENTIMENT_TREND_LEVEL);
        $logDeviation = (self::GOLD_REAL_RATE_SEMI_ELASTICITY * ($realTenYearYield - self::GOLD_REAL_RATE_ANCHOR))
            + (self::GOLD_INFLATION_EXPECTATION_SEMI_ELASTICITY * ($expectedInflation - self::GOLD_INFLATION_EXPECTATION_ANCHOR))
            + (self::GOLD_PESSIMISM_SEMI_ELASTICITY * $pessimism);

        return MacroEngine::GOLD_BASELINE * exp($logDeviation);
    }

    /**
     * Gold as a log mean-reverting price around its fundamental equilibrium, stepped with the exact Schwartz
     * (1997) transition. Deviations carry half their size into the next year, as the residuals of the levels
     * regression do, which is what lets news about rates and confidence reach the price over quarters.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateGoldPriceIndex(MacroState $state, float $dt): void
    {
        $equilibrium = self::resolveGoldEquilibriumPrice(
            $state->yield10yEma - $state->tipsBreakevenEma,
            $state->tipsBreakevenEma,
            $state->consumerSentimentIndexEma
        );

        $gold = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->goldPriceIndex > 0.0 ? $state->goldPriceIndex : MacroEngine::GOLD_BASELINE,
            kappa: self::GOLD_MEAN_REVERSION,
            theta: $equilibrium,
            sigma: self::GOLD_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );

        $state->goldPriceIndex = max(self::MIN_GOLD_INDEX, min(self::MAX_GOLD_INDEX, $gold));
    }

    /**
     * Schwartz-Smith (2000) Two-Factor Commodity Model for Industrial Metals (Copper/Aluminum).
     *
     * Decomposes metals prices into short-term transitory market deviations (chi) and long-term
     * structural equilibrium capacity (xi) dynamically shifted by GLOBAL demand: the composite of the
     * district's gap and the foreign bloc's, since a district this size does not set the copper price.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateIndustrialMetalsIndex(MacroState $state, float $dt): void
    {
        $baselineLog = log(MacroEngine::METALS_BASELINE);
        $shiftedThetaXi = $baselineLog + ($state->globalDemandGapEma * self::METALS_OUTPUT_GAP_SENSITIVITY);

        $result = $this->mathUtility->calculateTwoFactorOU(
            chi: $state->metalsChi,
            xi: $state->metalsXi,
            kappaChi: self::METALS_SHORT_TERM_KAPPA,
            kappaXi: self::METALS_LONG_TERM_KAPPA,
            thetaChi: 0.0,
            thetaXi: $shiftedThetaXi,
            sigChi: self::METALS_SHORT_TERM_SIGMA,
            sigXi: self::METALS_LONG_TERM_SIGMA,
            rho: self::METALS_RHO,
            dt: $dt
        );

        $state->metalsChi = $result['chi'];
        $state->metalsXi = $result['xi'];
        $state->industrialMetalsIndex = max(20.0, min(400.0, $result['spot']));
    }

    /**
     * Schwartz-Smith (2000) Two-Factor Commodity Model with Harvest Seasonality & Weather Jumps.
     *
     * Evaluates agricultural spot prices via correlated two-factor Ornstein-Uhlenbeck processes,
     * deterministic annual harvest sine-wave seasonality, and Poisson weather shock jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateAgriculturalCommodityIndex(MacroState $state, float $dt): void
    {
        $result = $this->mathUtility->calculateTwoFactorOU(
            chi: $state->agriChi,
            xi: $state->agriXi,
            kappaChi: self::AGRI_SHORT_TERM_KAPPA,
            kappaXi: self::AGRI_LONG_TERM_KAPPA,
            thetaChi: 0.0,
            thetaXi: log(MacroEngine::AGRI_BASELINE),
            sigChi: self::AGRI_SHORT_TERM_SIGMA,
            sigXi: self::AGRI_LONG_TERM_SIGMA,
            rho: self::AGRI_RHO,
            dt: $dt
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::AGRI_WEATHER_JUMP_PROBABILITY,
            jumpMean: self::AGRI_WEATHER_JUMP_MEAN,
            jumpVol: self::AGRI_WEATHER_JUMP_VOL,
            dt: $dt
        );

        $chi = $result['chi'];
        if ($jumpData['multiplier'] !== 1.0) {
            $chi += log($jumpData['multiplier']);
        }

        $state->agriChi = $chi;
        $state->agriXi = $result['xi'];

        $timeOfYear = fmod($state->totalTime, 1.0);
        $seasonalMultiplier = 1.0 + (self::AGRI_SEASONALITY_AMPLITUDE * sin(2.0 * M_PI * $timeOfYear));

        $spot = exp($state->agriChi + $state->agriXi) * $seasonalMultiplier;
        $state->agriculturalCommodityIndex = max(20.0, min(400.0, $spot));
    }

    /**
     * Stopford (2009) Maritime Shipping Model & Ezekiel (1938) Cobweb Supply Lag Theorem.
     *
     * Models ocean dry-bulk/container freight charter rates by matching global trade demand against
     * sticky vessel fleet supply subject to a multi-year shipyard delivery lag and capacity inelasticity.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateFreightRateIndex(MacroState $state, float $dt): void
    {
        $metalsShift = ($state->industrialMetalsIndexEma - MacroEngine::METALS_BASELINE) / 100.0;
        $demandFactor = 1.0 + ($state->globalDemandGapEma * self::FREIGHT_DEMAND_GAP_SENSITIVITY) + ($metalsShift * self::FREIGHT_DEMAND_METALS_SENSITIVITY);
        $demand = MacroEngine::FREIGHT_BASELINE * max(0.20, $demandFactor);

        $profitabilityRatio = max(0.10, $state->freightRateIndexEma / MacroEngine::FREIGHT_BASELINE);
        $targetSupply = MacroEngine::FREIGHT_BASELINE * pow($profitabilityRatio, self::FREIGHT_SUPPLY_ORDER_ELASTICITY);

        $slowEmaWeight = 1.0 - exp(-$dt / self::FREIGHT_SUPPLY_LAG_YEARS);
        $state->freightSupplyEma += $slowEmaWeight * ($targetSupply - $state->freightSupplyEma);
        $supply = max(20.0, $state->freightSupplyEma);

        $utilization = $demand / $supply;
        $equilibriumRate = MacroEngine::FREIGHT_BASELINE * pow($utilization, self::FREIGHT_CAPACITY_INELASTICITY);

        $dW = $this->mathUtility->generateStandardNormal();
        $newFreight = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->freightRateIndex,
            kappa: self::FREIGHT_MEAN_REVERSION,
            theta: $equilibriumRate,
            sigma: self::FREIGHT_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->freightRateIndex = max(20.0, min(500.0, $newFreight));
    }

    /**
     * 3:2:1 refining crack spread: a de-seasonalised log mean-reverting margin times the driving-season cycle,
     * the same split of stochastic and deterministic seasonal parts the gas index uses (Pilipovic 1998).
     *
     * The cycle multiplies the price rather than its target: a margin that reverts with a six-month half-life
     * would otherwise carry barely a fifth of a yearly swing. The stored spread carries the season, so the
     * previous step's factor is divided back out before the stochastic part is stepped.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateRefiningCrackSpread(MacroState $state, float $dt): void
    {
        $currentCrack = $state->refiningCrackSpread > 0.0 ? $state->refiningCrackSpread : MacroEngine::CRACK_SPREAD_BASELINE;
        $baseCrack = $currentCrack / self::resolveCrackSeasonalFactor($state->totalTime - $dt);

        $nextBase = $this->mathUtility->calculateRefiningCrackSpreadStep(
            currentCrack: $baseCrack,
            outputGap: $state->globalDemandGapEma,
            energyInventoryIndex: $state->energyInventoryIndexEma,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal(),
            baselineCrack: MacroEngine::CRACK_SPREAD_BASELINE,
            kappa: self::CRACK_SPREAD_KAPPA,
            sigma: self::CRACK_SPREAD_SIGMA
        );

        $state->refiningCrackSpread = max(4.0, min(80.0, $nextBase * self::resolveCrackSeasonalFactor($state->totalTime)));
    }

    /** The driving-season multiplier on the crack at a point in simulated time (years). */
    public static function resolveCrackSeasonalFactor(float $totalTime): float
    {
        return 1.0 + (self::CRACK_SEASONAL_AMPLITUDE * cos(2.0 * M_PI * (fmod($totalTime, 1.0) - self::CRACK_SEASONAL_PEAK)));
    }

    /**
     * NY Fed Global Supply Chain Pressure Index (GSCPI) (Benigno et al. 2022).
     *
     * Synthesizes cross-border freight rates, inventory stock gaps, and raw material frictions
     * into a standardized Z-score composite.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateSupplyChainPressureIndex(MacroState $state): void
    {
        $state->supplyChainPressureIndex = $this->mathUtility->calculateGscpiComposite(
            freightRateIndex: $state->freightRateIndexEma,
            inventoryStockGap: $state->inventoryStockGapEma,
            industrialMetalsIndex: $state->industrialMetalsIndexEma,
            freightBase: MacroEngine::FREIGHT_BASELINE,
            metalsBase: MacroEngine::METALS_BASELINE
        );
    }
}
