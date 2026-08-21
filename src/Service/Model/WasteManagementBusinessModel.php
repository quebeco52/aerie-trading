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
 * Earnings strategy for Waste Management & Environmental Services.
 * 
 * Financial Physics:
 * - Localized Oligopolies: Extremely high barriers to entry (landfill permitting) grant immense pricing power.
 * - Tri-Stream Model: 
 *      1. Residential Collection: Hyper-sticky, contracted revenue.
 *      2. Commercial/Industrial: Highly sensitive to economic output and construction volume.
 *      3. Recycling & RNG: Highly sensitive to global energy and commodity spot prices.
 * - Inflation Hedge: Municipal contracts bake in automatic CPI rent escalators.
 * - Fuel Surcharge Lag: Spikes in energy prices cause temporary margin compression before fuel surcharges kick in.
 */
class WasteManagementBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50; // High visibility due to steady municipal contracts
    public const BASE_COVERAGE_ERROR = 0.05;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.50, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.75, 'buyback_min_icr' => 2.50, 'reversion_speed' => 0.12, 'moat_spread' => 0.020, 'nwc_intensity' => 0.08, 'capex_completion_rate' => 0.25];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.025;
    }
    public function getCapexCyclicality(): float
    {
        return 1.0;
    } // Constant need for fleet upgrades and landfill cells
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.75, 'revenue_weight' => 0.25];
    }

    // --- Tri-Stream Architecture ---
    /** Baseline fraction of revenue from defensive residential collection & municipal contracts. */
    public const RESIDENTIAL_WEIGHT = 0.55;
    /** Baseline fraction of revenue from cyclical commercial roll-offs and construction debris. */
    public const COMMERCIAL_WEIGHT  = 0.35;
    /** Baseline fraction of revenue from volatile recycled commodities and Renewable Natural Gas (RNG). */
    public const RECYCLING_WEIGHT   = 0.10;

    // --- Physics & Variances ---
    public const RESIDENTIAL_VARIANCE_SCALAR = 0.05; // Extremely sticky
    public const COMMERCIAL_VARIANCE_SCALAR  = 0.25; // Cyclical, tied to business closures/starts
    public const RECYCLING_VARIANCE_SCALAR   = 0.60; // Highly volatile commodity exposure

    // --- Margin Squeeze & Inflation Physics ---
    /** Fraction of excess CPI inflation automatically captured by contract escalators. */
    public const CPI_ESCALATOR_CAPTURE = 0.85;
    /** Margin penalty applied when energy prices spike faster than fuel surcharges can adjust. */
    public const FUEL_SURCHARGE_LAG_PENALTY = 0.08;

    // --- Tail Risk Events ---
    /** Z-score threshold indicating a severe landfill leachate leak or EPA regulatory shutdown. */
    public const ENVIRONMENTAL_DISASTER_Z_SCORE = -2.50;
    /** Variable cost penalty applied to fund massive environmental cleanup liabilities. */
    public const ENVIRONMENTAL_CLEANUP_PENALTY  = 0.12;

    // --- Asset Depreciation & Reinvestment ---
    public const FLEET_AGING_DECAY_RATE       = 0.020;
    public const AUTOMATION_RNG_GAIN_RATE     = 0.012; // Routing automation and RNG capture
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.08;
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift to avoid double-dipping, as macro volume shocks 
        // are handled discretely per-stream (Commercial vs. Residential) below.
        $physics['macro_demand_shift'] = 0.0;

        // Massive pricing power: Waste management companies dictate prices to municipalities.
        $physics['pricing_power_multiplier'] = 1.0 + ($macroState->inflationEma * 0.85);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ResidentialWeight->value => self::RESIDENTIAL_WEIGHT,
            ModelParam::CommercialWeight->value  => self::COMMERCIAL_WEIGHT,
            ModelParam::RecyclingWeight->value   => self::RECYCLING_WEIGHT,
        ]);

        $residentialWeight = $params[ModelParam::ResidentialWeight];
        $commercialWeight  = $params[ModelParam::CommercialWeight];
        $recyclingWeight   = $params[ModelParam::RecyclingWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'residential_collection' => $params[ModelParam::ResidentialWeight],
            'commercial_disposal'    => $params[ModelParam::CommercialWeight],
            'recycling_and_rng'      => $params[ModelParam::RecyclingWeight],
        ]);

        $residentialWeight = $activeWeights['residential_collection'];
        $commercialWeight  = $activeWeights['commercial_disposal'];
        $recyclingWeight   = $activeWeights['recycling_and_rng'];

        // Independent stream Z-scores
        $residentialZ = $streams->generateZ('residential_collection', 0.40);
        $commercialZ  = $streams->generateZ('commercial_disposal', 0.20);
        $recyclingZ   = $streams->generateZ('recycling_and_rng', 0.10);
        $eventZ       = $streams->generateZ('event', 0.05);

        // --- Macro Demand & Pricing Sensitivities ---
        $macroBoost = $macroState->outputGapEma * 1.5 * $beta; // Affects Commercial/Construction
        $excessInflation = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
        $cpiEscalatorBoost = $excessInflation * self::CPI_ESCALATOR_CAPTURE; // Passive revenue boost

        // Recycling is driven entirely by global commodity, energy, and scrap metal prices
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $recyclingCommodityBoost = ($energyShift * 0.30) + ($metalsShift * 0.30);

        // --- Tail Risk Events ---
        $eventType = null;
        $disasterPenalty = 0.0;

        if ($eventZ < self::ENVIRONMENTAL_DISASTER_Z_SCORE) {
            $eventType = ShockEvent::ENVIRONMENTAL_DISASTER ?? 'environmental_liability';
            $disasterPenalty = self::ENVIRONMENTAL_CLEANUP_PENALTY;
        }

        // --- Clamped Tri-Stream Revenue Calculation ---
        $residentialRevenue = max(0.0, $expectedRevenue * $residentialWeight * (1.0 + ($residentialZ * $baselineVol * self::RESIDENTIAL_VARIANCE_SCALAR) + $cpiEscalatorBoost));
        $commercialRevenue  = max(0.0, $expectedRevenue * $commercialWeight  * (1.0 + ($commercialZ * $baselineVol * self::COMMERCIAL_VARIANCE_SCALAR) + $macroBoost));
        $recyclingRevenue   = max(0.0, $expectedRevenue * $recyclingWeight   * (1.0 + ($recyclingZ * $baselineVol * self::RECYCLING_VARIANCE_SCALAR) + $recyclingCommodityBoost));

        $streamRevenues = [
            'residential_collection' => $residentialRevenue,
            'commercial_disposal'    => $commercialRevenue,
            'recycling_and_rng'      => $recyclingRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics ---
        // Fuel Surcharge Lag: Waste trucks guzzle diesel. If energy prices spike suddenly (> 0), 
        // there is a 30-90 day lag before fuel surcharges pass the cost to the customer.
        $fuelLagDrag = $energyShift > 0.0 ? ($energyShift * self::FUEL_SURCHARGE_LAG_PENALTY * $beta) : 0.0;

        // Apply structurally driven penalties directly to the baseline variable margin
        $rawMargin = $realizedVariableMargin - $fuelLagDrag - $disasterPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = abs($commercialZ) > abs($residentialZ) ? $commercialZ : $residentialZ;
        if (abs($recyclingZ) > abs($primaryShockZ)) $primaryShockZ = $recyclingZ;
        if (abs($eventZ) > abs($primaryShockZ)) $primaryShockZ = $eventZ;

        // Residential is highly visible, Commercial correlates to GDP, Recycling is visible via commodities
        $observableShockZ = ($residentialZ * $residentialWeight * self::RESIDENTIAL_VARIANCE_SCALAR * 0.90) +
            ($commercialZ * $commercialWeight * self::COMMERCIAL_VARIANCE_SCALAR * 0.50) +
            ($recyclingZ * $recyclingWeight * self::RECYCLING_VARIANCE_SCALAR * 0.80);
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

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Fleet aging (maintenance costs spike) and landfill airspace depletion
            $decayRate = self::FLEET_AGING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Fleet automation, transition to cheaper CNG/EV trucks, and RNG gas capture facility builds
            $modGain = self::AUTOMATION_RNG_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
