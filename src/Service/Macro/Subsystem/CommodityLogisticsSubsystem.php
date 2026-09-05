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
            kappa: MacroEngine::ENERGY_MEAN_REVERSION,
            theta: MacroEngine::ENERGY_BASELINE,
            sigma: MacroEngine::ENERGY_VOLATILITY,
            dt: $dt,
            dW: $dW
        );
        $state->energyBasePrice = max(10.0, min(250.0, $baseProcess));

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: MacroEngine::ENERGY_JUMP_PROBABILITY,
            jumpMean: MacroEngine::ENERGY_JUMP_MEAN,
            jumpVol: MacroEngine::ENERGY_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }

        // Physical inventory buffer evolution
        $demandDraw = $state->outputGapEma * MacroEngine::COMMODITY_INVENTORY_DRAWDOWN_SENSITIVITY * 100.0;
        $shockDraw = ($jumpData['multiplier'] > 1.0) ? (log($jumpData['multiplier']) * 40.0) : 0.0;
        $reversionFlow = MacroEngine::COMMODITY_INVENTORY_REVERSION_SPEED * (MacroEngine::COMMODITY_INVENTORY_BASELINE - $state->energyInventoryIndex);
        $dInventory = ($reversionFlow - $demandDraw - $shockDraw) * $dt;
        $state->energyInventoryIndex = max(MacroEngine::COMMODITY_MIN_BUFFER_STOCK, min(160.0, $state->energyInventoryIndex + $dInventory));

        // Theory of Storage (Working 1949): Non-linear convenience yield backwardation add-on
        $convenienceYield = $this->mathUtility->calculateConvenienceYield(
            inventoryLevel: $state->energyInventoryIndex,
            minBufferStock: MacroEngine::COMMODITY_MIN_BUFFER_STOCK
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
        $shiftedThetaXi = $baselineLog + ($state->outputGapEma * MacroEngine::METALS_OUTPUT_GAP_SENSITIVITY);

        $result = $this->mathUtility->calculateTwoFactorOU(
            chi: $state->metalsChi,
            xi: $state->metalsXi,
            kappaChi: MacroEngine::METALS_SHORT_TERM_KAPPA,
            kappaXi: MacroEngine::METALS_LONG_TERM_KAPPA,
            thetaChi: 0.0,
            thetaXi: $shiftedThetaXi,
            sigChi: MacroEngine::METALS_SHORT_TERM_SIGMA,
            sigXi: MacroEngine::METALS_LONG_TERM_SIGMA,
            rho: MacroEngine::METALS_RHO,
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
            kappaChi: MacroEngine::AGRI_SHORT_TERM_KAPPA,
            kappaXi: MacroEngine::AGRI_LONG_TERM_KAPPA,
            thetaChi: 0.0,
            thetaXi: log(MacroEngine::AGRI_BASELINE),
            sigChi: MacroEngine::AGRI_SHORT_TERM_SIGMA,
            sigXi: MacroEngine::AGRI_LONG_TERM_SIGMA,
            rho: MacroEngine::AGRI_RHO,
            dt: $dt
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: MacroEngine::AGRI_WEATHER_JUMP_PROBABILITY,
            jumpMean: MacroEngine::AGRI_WEATHER_JUMP_MEAN,
            jumpVol: MacroEngine::AGRI_WEATHER_JUMP_VOL,
            dt: $dt
        );

        $chi = $result['chi'];
        if ($jumpData['multiplier'] !== 1.0) {
            $chi += log($jumpData['multiplier']);
        }

        $state->agriChi = $chi;
        $state->agriXi = $result['xi'];

        $timeOfYear = fmod($state->totalTime, 1.0);
        $seasonalMultiplier = 1.0 + (MacroEngine::AGRI_SEASONALITY_AMPLITUDE * sin(2.0 * M_PI * $timeOfYear));

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
        $demandFactor = 1.0 + ($state->outputGapEma * MacroEngine::FREIGHT_DEMAND_GAP_SENSITIVITY) + ($metalsShift * MacroEngine::FREIGHT_DEMAND_METALS_SENSITIVITY);
        $demand = MacroEngine::FREIGHT_BASELINE * max(0.20, $demandFactor);

        $profitabilityRatio = max(0.10, $state->freightRateIndexEma / MacroEngine::FREIGHT_BASELINE);
        $targetSupply = MacroEngine::FREIGHT_BASELINE * pow($profitabilityRatio, MacroEngine::FREIGHT_SUPPLY_ORDER_ELASTICITY);

        $slowEmaWeight = 1.0 - exp(-$dt / MacroEngine::FREIGHT_SUPPLY_LAG_YEARS);
        $state->freightSupplyEma += $slowEmaWeight * ($targetSupply - $state->freightSupplyEma);
        $supply = max(20.0, $state->freightSupplyEma);

        $utilization = $demand / $supply;
        $equilibriumRate = MacroEngine::FREIGHT_BASELINE * pow($utilization, MacroEngine::FREIGHT_CAPACITY_INELASTICITY);

        $dW = $this->mathUtility->generateStandardNormal();
        $newFreight = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->freightRateIndex,
            kappa: MacroEngine::FREIGHT_MEAN_REVERSION,
            theta: $equilibriumRate,
            sigma: MacroEngine::FREIGHT_VOLATILITY,
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
            kappa: MacroEngine::CRACK_SPREAD_KAPPA,
            sigma: MacroEngine::CRACK_SPREAD_SIGMA
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
