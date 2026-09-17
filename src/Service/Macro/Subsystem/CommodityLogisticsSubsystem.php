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

    // --- 3:2:1 Refining Crack Spread & Distillate Margins (Bourgeon et al. 1998) ---
    /** Mean-reversion speed (kappa) of refining crack margins toward baseline equilibrium. */
    public const CRACK_SPREAD_KAPPA = 1.50;
    /** Stochastic volatility of spot crack margins. */
    public const CRACK_SPREAD_SIGMA = 0.25;

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
        $dW = $this->mathUtility->generateStandardNormal();
        $currentBase = $state->energyBasePrice > 0.0 ? $state->energyBasePrice : $state->energyPriceIndex;
        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $currentBase,
            kappa: self::ENERGY_MEAN_REVERSION,
            theta: MacroEngine::ENERGY_BASELINE,
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

        // Physical inventory buffer evolution
        $demandDraw = $state->outputGapEma * self::COMMODITY_INVENTORY_DRAWDOWN_SENSITIVITY * 100.0;
        $shockDraw = ($jumpData['multiplier'] > 1.0) ? (log($jumpData['multiplier']) * 40.0) : 0.0;
        $reversionFlow = self::COMMODITY_INVENTORY_REVERSION_SPEED * (MacroEngine::COMMODITY_INVENTORY_BASELINE - $state->energyInventoryIndex);
        $dInventory = ($reversionFlow - $demandDraw - $shockDraw) * $dt;
        $state->energyInventoryIndex = max(self::COMMODITY_MIN_BUFFER_STOCK, min(160.0, $state->energyInventoryIndex + $dInventory));

        // Theory of Storage (Working 1949): Non-linear convenience yield backwardation add-on
        $convenienceYield = $this->mathUtility->calculateConvenienceYield(
            inventoryLevel: $state->energyInventoryIndex,
            minBufferStock: self::COMMODITY_MIN_BUFFER_STOCK
        );
        $conveniencePricePremium = MacroEngine::ENERGY_BASELINE * $convenienceYield;

        $state->energyPriceIndex = max(10.0, min(350.0, $baseProcess + $jumpAmount + $conveniencePricePremium));
        $state->energyPriceShock = $state->energyPriceIndex - MacroEngine::ENERGY_BASELINE;
    }

    /**
     * Schwartz-Smith (2000) Two-Factor Commodity Model for Industrial Metals (Copper/Aluminum).
     *
     * Decomposes metals prices into short-term transitory market deviations (chi) and long-term
     * structural equilibrium capacity (xi) dynamically shifted by global macroeconomic GDP demand.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateIndustrialMetalsIndex(MacroState $state, float $dt): void
    {
        $baselineLog = log(MacroEngine::METALS_BASELINE);
        $shiftedThetaXi = $baselineLog + ($state->outputGapEma * self::METALS_OUTPUT_GAP_SENSITIVITY);

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
        $demandFactor = 1.0 + ($state->outputGapEma * self::FREIGHT_DEMAND_GAP_SENSITIVITY) + ($metalsShift * self::FREIGHT_DEMAND_METALS_SENSITIVITY);
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
     * 3:2:1 Refining Crack Spread Model (Bourgeon et al. 1998, U.S. EIA).
     *
     * Evaluates gross refining margin per barrel ($/bbl) for refined products over crude oil feedstocks
     * based on cyclical demand and physical energy inventory tightness.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateRefiningCrackSpread(MacroState $state, float $dt): void
    {
        $dW = $this->mathUtility->generateStandardNormal();
        $currentCrack = $state->refiningCrackSpread > 0.0 ? $state->refiningCrackSpread : MacroEngine::CRACK_SPREAD_BASELINE;

        $state->refiningCrackSpread = $this->mathUtility->calculateRefiningCrackSpreadStep(
            currentCrack: $currentCrack,
            outputGap: $state->outputGapEma,
            energyInventoryIndex: $state->energyInventoryIndexEma,
            dt: $dt,
            dW: $dW,
            baselineCrack: MacroEngine::CRACK_SPREAD_BASELINE,
            kappa: self::CRACK_SPREAD_KAPPA,
            sigma: self::CRACK_SPREAD_SIGMA
        );
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
