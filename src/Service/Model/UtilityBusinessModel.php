<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Pure-Play Regulated Utilities (Water, Electric, Gas).
 * 
 * Financial Physics:
 * - Legal Monopolies: "Rate Base" regulation guarantees ROIC, but caps upside.
 * - Weather-Driven Volume: Demand is highly inelastic to the economy but highly elastic to severe weather (heatwaves/freezes).
 * - Regulatory Lag: Authorized rate hikes lag behind inflation, causing temporary margin compression during high CPI regimes.
 * - The Bond Proxy: Massive debt loads make them highly vulnerable to rising 10Y Treasury yields (refinancing friction).
 * - Tail Risk: Aging infrastructure and climate events lead to catastrophic liability shocks (wildfires, grid failures).
 */
class UtilityBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.05;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.12, 'capex_completion_rate' => 0.125];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.01;
    }
    public function getCapexCyclicality(): float
    {
        return 0.5;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.85, 'revenue_weight' => 0.15];
    }

    // --- Dual-Stream Utility Rate Architecture ---
    /** Baseline fraction of revenue derived from regulated rate-base monopoly tariff distribution. */
    public const REGULATED_BASE_WEIGHT       = 0.85;
    /** Baseline fraction of revenue derived from unregulated merchant power generation and wholesale grid sales. */
    public const UNREGULATED_MERCHANT_WEIGHT = 0.15;

    // --- Regulatory Lag & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to output gap (industrial power usage). */
    public const MACRO_DEMAND_SCALAR       = 0.15;
    /** Pricing power multiplier applied to inflation reflecting delayed rate hike approvals. */
    public const PRICING_POWER_LAG_SCALAR  = 0.25;
    /** Inflation buffer above target inflation before regulatory lag penalties begin compressing margins. */
    public const REGULATORY_LAG_BUFFER     = 0.01;
    /** Variable margin penalty multiplier applied to inflation exceeding the lag threshold. */
    public const REGULATORY_LAG_PENALTY    = 0.80;

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for weather-driven regulated volume (heatwaves/polar vortex). */
    public const WEATHER_VARIANCE_SCALAR   = 0.04;
    /** Volatility multiplier for unregulated wholesale merchant power pricing. */
    public const MERCHANT_VARIANCE_SCALAR  = 0.15;

    // --- Tail Risk & Refinancing Physics ---
    /** Z-score threshold indicating a catastrophic grid failure, pipeline explosion, or wildfire liability. */
    public const GRID_FAILURE_Z_SCORE       = -2.50;
    /** Variable cost penalty applied to fund massive environmental liabilities or emergency grid repairs. */
    public const GRID_FAILURE_PENALTY       = 0.15;
    /** Sensitivity of utility margins to 10Y Treasury yields (debt refinancing friction). */
    public const REFINANCING_WALL_DRAG      = 0.20;
    /** Fallback safe 10Y yield before refinancing drag kicks in. */
    public const DEFAULT_10Y_YIELD_FALLBACK = 0.04;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    public const REGULATED_REVERSION_SPEED    = 2.0;
    public const DEPRECIATION_DECAY_RATE      = 0.020;
    public const MODERNIZATION_GAIN_RATE      = 0.010;
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.02;
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;

    // --- Rate-Base CapEx & Capital Structure Rails ---
    public const UTILITY_CAPEX_BURN_DISCOUNT  = 0.92;
    public const MIN_RECAP_ICR_FLOOR          = 2.5;
    public const WACC_ARBITRAGE_THRESHOLD     = 0.01;
    public const UNDERLEVERAGED_DEBT_RATIO    = 0.70;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Regulated Utilities are virtually immune to economic output gaps (essential service).
        // Only industrial/commercial power load fluctuates slightly with GDP.
        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();
        $physics['macro_demand_shift'] = $outputGap * $beta * self::MACRO_DEMAND_SCALAR;

        // Regulatory Lag: Utilities do get rate hikes to cover inflation, but they lag by 12-24 months.
        $inflation = $macroState->inflationEma;
        $physics['pricing_power_multiplier'] = 1.0 + ($inflation * self::PRICING_POWER_LAG_SCALAR);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::RegulatedBaseWeight->value       => self::REGULATED_BASE_WEIGHT,
            ModelParam::UnregulatedMerchantWeight->value => self::UNREGULATED_MERCHANT_WEIGHT,
        ]);

        $regulatedWeight   = $params[ModelParam::RegulatedBaseWeight];
        $unregulatedWeight = $params[ModelParam::UnregulatedMerchantWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'regulated_weather_load' => $params[ModelParam::RegulatedBaseWeight],
            'unregulated_merchant'   => $params[ModelParam::UnregulatedMerchantWeight],
        ]);

        $regulatedWeight   = $activeWeights['regulated_weather_load'];
        $unregulatedWeight = $activeWeights['unregulated_merchant'];

        // Independent stream Z-scores
        $weatherZ     = $streams->generateZ('regulated_weather_load', 0.05); // Weather is random, low persistence
        $unregulatedZ = $streams->generateZ('unregulated_merchant', 0.20); // Merchant wholesale electricity trading
        $eventZ       = $streams->generateZ('event', 0.10); // Infrastructure tail risks

        // --- Clamped Revenue Streams ---
        // Weather deviations drive regulated volume. High Z = Heatwaves/Freezes (high load). Low Z = Mild weather (low load).
        $regulatedRevenue   = max(0.0, $expectedRevenue * $regulatedWeight * (1.0 + ($weatherZ * ($baselineVol * self::WEATHER_VARIANCE_SCALAR))));
        $unregulatedRevenue = max(0.0, $expectedRevenue * $unregulatedWeight * (1.0 + ($unregulatedZ * ($baselineVol * self::MERCHANT_VARIANCE_SCALAR))));

        $streamRevenues = [
            'regulated_weather_load' => $regulatedRevenue,
            'unregulated_merchant'   => $unregulatedRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Event Tail Risks (Grid Failure) ---
        $eventType = null;
        $disasterPenalty = 0.0;

        if ($eventZ < self::GRID_FAILURE_Z_SCORE) {
            $eventType = ShockEvent::INFRASTRUCTURE_FAILURE ?? 'grid_failure_liability';
            $disasterPenalty = self::GRID_FAILURE_PENALTY;
        }

        // --- Regulatory Lag & Input Costs ---
        $inflation = $macroState->inflationEma;
        $lagThreshold = MacroEngine::TARGET_INFLATION + self::REGULATORY_LAG_BUFFER;

        // Squeeze margin if CPI stays persistently above target + buffer
        $regulatoryLagPenalty = $inflation > $lagThreshold
            ? (($inflation - $lagThreshold) * self::REGULATORY_LAG_PENALTY * $regulatedWeight)
            : 0.0;

        // --- Merchant Spark Spread Crush ---
        // Unregulated merchant power relies on the "spark spread" (wholesale electricity price minus fuel input cost).
        // If the energy index spikes violently, the spark spread collapses.
        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);
        $sparkSpreadCrush = $energyShift * 0.20 * $unregulatedWeight;

        // --- Refinancing Wall Drag (Bond Proxies) ---
        // Utilities hold massive debt. As 10Y yields rise, their refinancing costs organically erode operating margins.
        $yield10y = $macroState->yield10yEma;
        $refinancingDrag = max(0.0, ($yield10y - self::DEFAULT_10Y_YIELD_FALLBACK) * self::REFINANCING_WALL_DRAG);

        // --- Margin Aggregation ---
        // Apply all structurally driven cost penalties directly to the baseline margin
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $regulatoryLagPenalty + $sparkSpreadCrush + $disasterPenalty + $refinancingDrag);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$unregulatedZ, $weatherZ], $eventZ);

        // Regulated weather volume is perfectly visible via meter data, wholesale trading is opaque.
        $observableShockZ = ($weatherZ * $regulatedWeight * self::WEATHER_VARIANCE_SCALAR) +
            ($unregulatedZ * $unregulatedWeight * self::MERCHANT_VARIANCE_SCALAR * 0.2);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function getMarginReversionSpeed(): float
    {
        return self::REGULATED_REVERSION_SPEED;
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

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, self::DCF_TERMINAL_GROWTH_RATE);
            $annualFcf = $fcfPerShare * 4.0;
            $dcfFairValue = min(max(0.01, $annualFcf * $multiplier), $peFairValue * self::MAX_DCF_TO_PE_CAP_MULT);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue * self::UTILITY_CAPEX_BURN_DISCOUNT) : max($revenueFloorValue, $peFairValue);
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        if ($costOfEquity <= ($effectiveCostOfDebt + self::WACC_ARBITRAGE_THRESHOLD)) {
            return false;
        }
        if ($interestCoverage < self::MIN_RECAP_ICR_FLOOR) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * self::UNDERLEVERAGED_DEBT_RATIO);
    }
}
