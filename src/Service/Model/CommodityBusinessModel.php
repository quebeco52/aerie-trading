<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Heavy Extractors and Refiners (Oil, Copper, Steel).
 * 
 * Financial Physics:
 * - Ultimate "Price Takers." They have zero ability to set their own prices.
 * - Revenue and margins are violently driven by global supply and demand (The Commodity Supercycle).
 * - INFLATION IS A BLESSING: While standard corporates get crushed by supply chain inflation, 
 *   commodities *are* the supply chain, meaning their margins explode upwards during inflationary spikes.
 */
class CommodityBusinessModel extends StandardCorporateBusinessModel
{
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.30, 'moat_spread' => 0.000, 'nwc_intensity' => 0.15, 'capex_completion_rate' => 0.125];
    }
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.01;
    }
    public function getCapexCyclicality(): float
    {
        return 3.0;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.30, 'revenue_weight' => 0.70];
    }

    // --- Dual-Stream Commodity Architecture ---
    /** Baseline fraction of revenue tied to physical extraction and production volume. */
    public const EXTRACTION_REVENUE_WEIGHT = 0.50;
    /** Baseline fraction of revenue tied to global commodity spot pricing and inflation premium. */
    public const SPOT_PRICE_WEIGHT         = 0.50;
    /** Baseline multiplier scaling spot price sensitivity to macro inflation spikes (0.50 simulates 50% hedging). */
    public const SPOT_PRICE_SENSITIVITY    = 0.50;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating severe geopolitical sanctions throttling extraction. */
    public const GEOPOLITICAL_SANCTIONS_Z_SCORE = -2.20;
    /** Positive z-score threshold indicating a massive geopolitical export ban (spot price spike). */
    public const GEOPOLITICAL_EXPORT_BAN_Z_SCORE = 2.40;
    /** Multiplier applied to spot prices during an export ban. */
    public const EXPORT_BAN_SPOT_MULT = 1.25;

    // --- Continuous Elasticity ---
    /** Variable margin sensitivity to physical extraction economies of scale. */
    public const EXTRACTION_SCALE_ELASTICITY = 0.015;

    // --- Commodity Spot Price & Inflation Physics ---
    /** Volatility multiplier for top-line revenue shocks driven by global commodity spot prices. */
    public const REVENUE_VARIANCE_SCALAR   = 0.25;
    /** Sensitivity scalar scaling excess inflation with stock beta to determine spot price revenue bonus. */
    public const INFLATION_BONUS_SCALAR    = 1.00;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE      = 0.025;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE      = 0.012;
    /** Structural minimum operating margin floor under extreme mining/drilling equipment aging. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.02;
    /** Structural maximum operating margin ceiling for state-of-the-art mining/drilling operations. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // CRITICAL FIX: Commodities are absolute price takers. They have zero traditional pricing power.
        // We set this to 1.0 because their top-line revenue is already dynamically forced up and down 
        // by global spot prices ($inflationBonus) during the Idiosyncratic Shock phase. 
        // Setting this higher would result in massive, compounded double-dipping on inflation.
        $physics['pricing_power_multiplier'] = 1.0;
        
        // Re-implementing Macro Volume Shock (GDP Sensitivity)
        // Commodities are heavily exposed to economic cycles, so multiplier is 1.5
        $physics['macro_demand_shift'] = $macroState->outputGapEma * 1.5 * abs((float) $stock->getBeta());

        return $physics;
    }

    /**
     * Commodity revenues are highly volatile due to wild swings in global spot prices.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'extraction_revenue_weight' => self::EXTRACTION_REVENUE_WEIGHT,
            'spot_price_weight'         => self::SPOT_PRICE_WEIGHT,
            'spot_price_sensitivity'    => self::SPOT_PRICE_SENSITIVITY,
        ]);

        $extractionWeight = $params['extraction_revenue_weight'];
        $spotWeight       = $params['spot_price_weight'];
        $spotSensitivity  = $params['spot_price_sensitivity'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores with AR(1) persistence
        $extractionZ = $mathUtility->generatePersistentZ($momentum['extraction'] ?? 0.0, 0.35); // Physical extraction/refining volume variance
        $spotZ       = $mathUtility->generatePersistentZ($momentum['spot'] ?? 0.0, 0.15); // Global commodity spot price deviations
        $eventZ      = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.10);

        // Tail Risk Events
        $extractionMultiplier = 1.0;
        $spotMultiplier = 1.0;
        $eventType = null;

        if ($eventZ < self::GEOPOLITICAL_SANCTIONS_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_SANCTIONS;
            $extractionMultiplier = 0.75; // Severe extraction volume drop
        } elseif ($eventZ > self::GEOPOLITICAL_EXPORT_BAN_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_EXPORT_BAN;
            $spotMultiplier = self::EXPORT_BAN_SPOT_MULT; // Massive spot price spike
        }

        // Higher top-line variance compared to standard retail/manufacturing
        // (Macro demand shift is already handled by EarningsEngine capacityUtilization)
        $extractionRevenue = $expectedRevenue * $extractionWeight * (1.0 + ($extractionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))) * $extractionMultiplier;

        // The Inflation Exposure:
        // While standard corporates get crushed by supply chain inflation, commodities *are* the supply chain.
        // Their margins explode upwards during inflationary spikes as spot prices rise, and violently contract during deflation.
        $inflation = $macroState->inflationEma;
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;

        $inflationBonus = ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_BONUS_SCALAR * $spotSensitivity;
        
        // Energy shift is already a massive percentage multiplier (e.g. 100 -> 400 is +300%). 
        // We shouldn't multiply it by beta and 2.0, otherwise a 300% spike causes a 1050% revenue spike.
        // We rely on $spotSensitivity (set to 0.50) to simulate a partially hedged production book (locking in futures).
        $energyBonus = $energyShift * self::INFLATION_BONUS_SCALAR * $spotSensitivity; 

        $spotRevenue   = $expectedRevenue * $spotWeight * (1.0 + ($spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $inflationBonus + $energyBonus) * $spotMultiplier;
        $actualRevenue = max(0.0, $extractionRevenue + $spotRevenue);

        // The total realizedVariableMargin is exclusively allocated to physical extraction.
        // Spot price shocks carry 100% gross margin (0% variable cost), serving as pure operating leverage.
        $extractionVariableMargin = $extractionWeight > 0 ? ($realizedVariableMargin / $extractionWeight) : $realizedVariableMargin;
        $actualVariableCosts = $extractionRevenue * $extractionVariableMargin;

        // Continuous Elasticity
        $elasticityShift = -self::EXTRACTION_SCALE_ELASTICITY * $extractionZ * $extractionWeight;

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin + $elasticityShift);

        $primaryShockZ = abs($extractionZ) > abs($spotZ) ? $extractionZ : $spotZ;
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // observableShockZ: extraction volume drift is partially visible (~20%). Spot prices & macro bonuses are 100% public.
        // We divide the public spot component by 0.20 so that when MarketConsensusEngine multiplies observableShockZ 
        // by dynamicVisibility (~0.20), the spot shock passes through to analysts at ~100% visibility.
        $extractionShock = $extractionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $spotShockTotal = ($spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $inflationBonus + $energyBonus;
        $observableShockZ = ($extractionShock * $extractionWeight) + (($spotShockTotal * $spotWeight) / 0.20);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'extraction' => $extractionZ,
                'spot'       => $spotZ,
                'event'      => $eventZ,
            ],
            streamRevenue: [
                'extraction' => $extractionRevenue,
                'spot'       => $spotRevenue,
            ],
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Commodity spot prices and inflation are public. Physical extraction volumes are ~20% visible.
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 0.20, errorStdDev: 0.05);
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.15; // Physical mining inventory holding & bulk refining working capital
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decayRate = self::DEPRECIATION_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
