<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Class 1 Freight Railroads & Rail Infrastructure.
 * 
 * Financial Physics:
 * - High Barrier to Entry / Geographic Duopolies: Captive track networks grant immense pricing power.
 * - Tri-Stream Freight Architecture:
 *      1. Intermodal Freight (Containers, Consumer Goods): Highly GDP & trade cyclical.
 *      2. Bulk Commodities (Grain, Coal, Fertilizer): Inelastic agricultural & energy harvest cycles.
 *      3. Industrial Carloads (Automotive, Steel, Chemicals): Heavy manufacturing volume.
 * - Dynamic Operating Ratio (OR) & Fuel Surcharge Lag: Diesel fuel price spikes cause temporary
 *   margin compression before contractual fuel surcharges pass the cost through.
 * - High Capital Intensity: Massive rail line, locomotive, and switching yard maintenance CapEx.
 */
class RailroadBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for Class 1 railroad analysts. */
    public const BASE_COVERAGE_VISIBILITY = 0.60;
    /** Base coverage forecasting error given weather and harvest seasonality. */
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from cyclical intermodal container shipping. */
    public const INTERMODAL_WEIGHT = 0.40;
    /** Baseline fraction of revenue derived from inelastic agricultural and energy bulk carloads. */
    public const BULK_COMMODITIES_WEIGHT = 0.40;
    /** Baseline fraction of revenue derived from heavy industrial and automotive carloads. */
    public const INDUSTRIAL_CARLOAD_WEIGHT = 0.20;

    // --- Physics & Variances ---
    /** Idiosyncratic revenue variance scalar for cyclical intermodal container shipping. */
    public const INTERMODAL_VARIANCE_SCALAR = 0.30;
    /** Idiosyncratic revenue variance scalar for bulk agricultural and energy carloads. */
    public const BULK_VARIANCE_SCALAR       = 0.15;
    /** Idiosyncratic revenue variance scalar for industrial and automotive carloads. */
    public const INDUSTRIAL_VARIANCE_SCALAR = 0.25;

    // --- Fuel Surcharge & Operating Ratio ---
    /** Variable margin cost drag scalar from diesel fuel price spikes before fuel surcharges take effect. */
    public const FUEL_SURCHARGE_LAG_PENALTY = 0.06;

    // --- Manufacturing PMI & Trade Transmission ---
    /** Sensitivity of industrial carload volumes to manufacturing PMI shifts. */
    public const PMI_CARLOAD_SENSITIVITY = 0.50;
    /** Sensitivity of intermodal container rail traffic to international merchandise trade balance. */
    public const TRADE_BALANCE_SENSITIVITY = 1.20;

    // --- Rolling Stock & Track Infrastructure Reinvestment Physics ---
    /** Quarterly margin decay rate per unit of underinvestment below track and locomotive replacement CapEx. */
    public const TRACK_AGING_DECAY_RATE = 0.015;
    /** Quarterly margin gain scalar per unit of precision scheduled railroading (PSR) and automated yard overinvestment. */
    public const PSR_EFFICIENCY_GAIN_RATE = 0.008;
    /** Structural minimum operating margin floor under severe track slow orders and derailment risk. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.15;
    /** Structural maximum operating margin ceiling for optimized precision freight rail duopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.45;

    public function getWholesaleLeverageLimit(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.02; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.15; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.3; }

    public function getSecularGrowthRate(Stock $stock): float { return 0.015; }
    
    public function getCapexCyclicality(): float { return 0.70; } // Heavy rail maintenance CapEx
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::IntermodalFreightWeight->value  => self::INTERMODAL_WEIGHT,
            ModelParam::BulkCommoditiesWeight->value    => self::BULK_COMMODITIES_WEIGHT,
            ModelParam::IndustrialCarloadsWeight->value => self::INDUSTRIAL_CARLOAD_WEIGHT,
        ]);

        $intermodalWeight = $params[ModelParam::IntermodalFreightWeight];
        $bulkWeight       = $params[ModelParam::BulkCommoditiesWeight];
        $industrialWeight = $params[ModelParam::IndustrialCarloadsWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta     = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'intermodal_freight'  => $params[ModelParam::IntermodalFreightWeight],
            'bulk_commodities'    => $params[ModelParam::BulkCommoditiesWeight],
            'industrial_carloads' => $params[ModelParam::IndustrialCarloadsWeight],
        ]);

        $intermodalWeight = $activeWeights['intermodal_freight'];
        $bulkWeight       = $activeWeights['bulk_commodities'];
        $industrialWeight = $activeWeights['industrial_carloads'];

        // Independent stream Z-scores
        $intermodalZ = $streams->generateZ('intermodal_freight', 0.20);
        $bulkZ       = $streams->generateZ('bulk_commodities', 0.40);
        $industrialZ = $streams->generateZ('industrial_carloads', 0.30);

        // Macro cyclicality
        $freightShift = ($macroState->freightRateIndexEma - 100.0) / 100.0;
        $agriShift = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, sensitivity: self::PMI_CARLOAD_SENSITIVITY);

        $intermodalMacroShift = ($macroState->outputGapEma * 1.6 * $beta) + ($freightShift * 0.20) + $tradeShift;
        $industrialMacroShift = ($macroState->outputGapEma * 1.2 * $beta) + $pmiShift;
        $bulkMacroShift = $agriShift * 0.30;

        $intermodalRevenue = max(0.0, $expectedRevenue * $intermodalWeight * (1.0 + ($intermodalZ * ($baselineVol * self::INTERMODAL_VARIANCE_SCALAR)) + $intermodalMacroShift));
        $bulkRevenue       = max(0.0, $expectedRevenue * $bulkWeight       * (1.0 + ($bulkZ * ($baselineVol * self::BULK_VARIANCE_SCALAR)) + $bulkMacroShift));
        $industrialRevenue = max(0.0, $expectedRevenue * $industrialWeight * (1.0 + ($industrialZ * ($baselineVol * self::INDUSTRIAL_VARIANCE_SCALAR)) + $industrialMacroShift));

        $streamRevenues = [
            'intermodal_freight'  => $intermodalRevenue,
            'bulk_commodities'    => $bulkRevenue,
            'industrial_carloads' => $industrialRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Diesel fuel surcharge lag: Railroads consume massive quantities of diesel.
        // Spikes in energy price index create temporary margin compression before fuel surcharges adjust.
        $energyShift = $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $fuelLagDrag = $energyShift > 0 ? $energyShift * self::FUEL_SURCHARGE_LAG_PENALTY : 0.0;

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $fuelLagDrag);

        // Max magnitude shock
        $primaryShockZ = $streams->resolveDominantShockZ([$intermodalZ, $bulkZ, $industrialZ]);

        $observableShockZ = ($intermodalZ * $intermodalWeight * self::INTERMODAL_VARIANCE_SCALAR) +
            ($bulkZ * $bulkWeight * self::BULK_VARIANCE_SCALAR) +
            ($intermodalMacroShift * $intermodalWeight);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Track slow orders & locomotive breakdown drag toward floor
            $decayRate = self::TRACK_AGING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Precision Scheduled Railroading (PSR) efficiency expands margin ceiling
            $modGain = self::PSR_EFFICIENCY_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return array_unique(array_merge(parent::getOperatingMacroFields(), [
            'agricultural_commodity_index_ema',
            'energy_cost_push_lag',
            'freight_rate_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'trade_balance_to_gdp_ema',
        ]));
    }
}
