<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Heavy Manufacturing and Heavy Industry (e.g. Autos, Heavy Machinery, Specialty Chemicals, Steel).
 * 
 * Financial Physics:
 * - High Operating Leverage: Massive fixed costs mean margins compress violently during recessions but explode during booms.
 * - Pro-Cyclical: Highly sensitive to the macro output gap (GDP).
 * - High CapEx Cyclicality: Huge reinvestment requirements that scale with cycles.
 */
class HeavyManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    public const BASE_COVERAGE_ERROR = 0.08;
        public function getReversionSpeed(): float { return 0.12; }
    public function getMoatSpread(): float { return 0.01; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.15; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.125; }
    // --- CapEx & Asset Physics ---
    public function getCapexCyclicality(): float
    {
        return 4.0;
    } // Much higher than standard 1.5
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.015;
    } // Slightly lower secular growth, highly cyclical

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from large-ticket OEM capital equipment manufacturing. */
    public const OEM_EQUIPMENT_WEIGHT = 0.65;
    /** Baseline fraction of revenue derived from high-margin aftermarket MRO parts and service contracts. */
    public const AFTERMARKET_MRO_WEIGHT = 0.35;

    // --- Backlog & Variance Physics ---
    /** Fraction of OEM revenue shocks absorbed by multi-quarter order backlogs. */
    public const BACKLOG_DAMPING_FACTOR = 0.50;
    /** Volatility multiplier for OEM capital equipment sales shocks. */
    public const OEM_VARIANCE_SCALAR = 0.40;
    /** Volatility multiplier for defensive aftermarket MRO consumables and repairs. */
    public const MRO_VARIANCE_SCALAR = 0.10;
    /** Structural variable cost ratio of high-margin aftermarket MRO parts. */
    public const MRO_VARIABLE_COST_RATIO = 0.20;

    // --- Pricing Power & Macro Physics ---
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.80; // Highly sensitive to macro shifts
    /** Sensitivity of OEM capital equipment orders to aggregate industrial capital capacity overhang. */
    public const CAPITAL_OVERHANG_SCALAR = 0.15;
    /** Sensitivity of OEM heavy equipment orders to manufacturing PMI survey shifts. */
    public const PMI_DEMAND_SENSITIVITY = 0.50;
    /** Sensitivity of heavy industrial variable margins to wholesale producer price index (PPI) inflation. */
    public const PPI_COST_DRAG_SENSITIVITY = 0.40;

    // --- Revenue & Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR = 0.35; // Volatile sales
    public const INFLATION_PENALTY_SCALAR = 0.80; // Input costs hit hard

    // --- Capacity Utilization & Global Supply Chain Physics ---
    /** Sensitivity of heavy factory fixed overhead absorption and variable margin to capacity utilization deviations. */
    public const CU_MARGIN_ABSORPTION_SENSITIVITY = 0.15;
    /** Variable margin cost drag per standard deviation of global supply chain pressure (GSCPI). */
    public const GSCPI_COST_PENALTY_SCALAR        = 0.020;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Heavy manufacturing is extremely sensitive to the output gap and manufacturing PMI
        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, MacroEngine::PMI_BASELINE, self::PMI_DEMAND_SENSITIVITY);

        // Amplify the output gap impact with leading ISM manufacturing PMI activity
        $physics['macro_demand_shift'] = ($outputGap * $beta * 1.50) + ($pmiShift * $beta);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value   => self::MIN_BETA_PRICING_POWER_FLOOR,
            ModelParam::OemEquipmentWeight->value  => self::OEM_EQUIPMENT_WEIGHT,
            ModelParam::AftermarketMroWeight->value => self::AFTERMARKET_MRO_WEIGHT,
        ]);

        $oemWeight     = $params[ModelParam::OemEquipmentWeight];
        $mroWeight     = $params[ModelParam::AftermarketMroWeight];
        $pricingPower  = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'oem_equipment'   => $params[ModelParam::OemEquipmentWeight],
            'aftermarket_mro' => $params[ModelParam::AftermarketMroWeight],
        ]);

        $oemWeight = $activeWeights['oem_equipment'];
        $mroWeight = $activeWeights['aftermarket_mro'];

        // Independent stream Z-scores
        $oemZ = $streams->generateZ('oem_equipment', 0.20);
        $mroZ = $streams->generateZ('aftermarket_mro', 0.40);

        $dampedOemShock = ($oemZ * ($baselineVol * self::OEM_VARIANCE_SCALAR)) * (1.0 - self::BACKLOG_DAMPING_FACTOR);
        $mroShock       = $mroZ * ($baselineVol * self::MRO_VARIANCE_SCALAR);

        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        $overhangDrag = $macroState->capitalStockOverhangEma * self::CAPITAL_OVERHANG_SCALAR;

        $oemRevenue = max(0.0, $expectedRevenue * $oemWeight * (1.0 + $dampedOemShock - ($fxShift * 0.15) - $overhangDrag));
        $mroRevenue = max(0.0, $expectedRevenue * $mroWeight * (1.0 + $mroShock));
        $streamRevenues = [
            'oem_equipment'   => $oemRevenue,
            'aftermarket_mro' => $mroRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Structural Margin Blending:
        // MRO consumables operate at structurally low variable cost (high margin).
        // OEM equipment carries the heavy direct manufacturing and metal/assembly cost.
        $expectedMroRevenue = $expectedRevenue * $mroWeight;
        $expectedOemRevenue = $expectedRevenue * $oemWeight;
        $mroBaselineCosts   = $expectedMroRevenue * self::MRO_VARIABLE_COST_RATIO;
        $targetTotalCosts   = $expectedRevenue * $realizedVariableMargin;
        $oemBaselineCosts   = max(0.0, $targetTotalCosts - $mroBaselineCosts);
        $oemVariableMargin  = $expectedOemRevenue > 0 ? ($oemBaselineCosts / $expectedOemRevenue) : $realizedVariableMargin;

        $actualVariableCosts = ($mroRevenue * self::MRO_VARIABLE_COST_RATIO) + ($oemRevenue * $oemVariableMargin);

        // Supply Chain Energy & Freight Penalty: Heavy industry relies heavily on energy, metals, and transit logistics.
        $energyShift = $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $freightShift = max(0.0, ($macroState->freightRateIndexEma - 100.0) / 100.0);
        $gscpiShift = max(0.0, $macroState->supplyChainPressureIndexEma);
        $gscpiCostDrag = $gscpiShift * self::GSCPI_COST_PENALTY_SCALAR;

        $combinedCommodityDrag = max(0.0, $energyShift + $metalsShift + ($freightShift * 0.30)) + $gscpiCostDrag;
        $baseInflationPenalty = $combinedCommodityDrag > 0 ? $combinedCommodityDrag * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // Capacity Utilization Overhead Absorption:
        // High industrial capacity utilization improves factory fixed overhead absorption, expanding margins.
        $cuDeviation = ($macroState->capacityUtilizationRateEma - MacroEngine::CU_BASELINE) / 100.0;
        $cuMarginAdjustment = - ($cuDeviation * self::CU_MARGIN_ABSORPTION_SENSITIVITY);

        // Wholesale Producer Price Inflation (PPI) Cost Drag:
        $ppiCostDrag = MathUtility::calculatePpiCostDrag(
            $macroState->producerPriceInflationEma,
            MacroEngine::TARGET_INFLATION,
            $pricingPower,
            self::PPI_COST_DRAG_SENSITIVITY
        );

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin + $inflationPenalty + $cuMarginAdjustment + $ppiCostDrag);

        $primaryShockZ = $streams->resolveDominantShockZ([$oemZ, $mroZ]);
        $observableShockZ = ($oemZ * $oemWeight * self::OEM_VARIANCE_SCALAR * (1.0 - self::BACKLOG_DAMPING_FACTOR)) +
            ($mroZ * $mroWeight * self::MRO_VARIANCE_SCALAR);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Cyclical heavy manufacturing anchors to Book Value (replacement cost) during trough earnings and mid-cycle earnings during expansions
        $bookWeight = $normalizedEps < 0 ? 0.70 : 0.40;
        $earningsWeight = 1.0 - $bookWeight;

        $baseConsensus = ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * FinancialConstants::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
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
            'capacity_utilization_rate_ema',
            'capital_stock_overhang_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'industrial_metals_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'supply_chain_pressure_index_ema',
        ]));
    }
}
