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
 * Earnings strategy for Integrated Freight & Contract Logistics (3PL).
 * 
 * Financial Physics:
 * - Extremely sensitive to global GDP, supply chain flow, and physical trade volumes.
 * - Tri-Stream Logistics Engine:
 *      1. Dedicated Fleet Contracts: Long-term committed freight volume with fuel surcharges.
 *      2. Spot Freight Brokerage: Highly volatile market-clearing rates; explodes during supply chain crunches.
 *      3. Value-Added Warehousing & 3PL: High-margin cross-docking, fulfillment, and automated inventory storage.
 * - Operating Leverage: Spot freight surges expand margins non-linearly over fixed fleet/warehouse overhead.
 */
class LogisticsBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Freight volumes lead the industrial cycle. */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.80;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.25, 'labor' => 0.35, 'ppi' => 0.05];
    /** Fuel surcharges on dedicated contracts reprice within a quarter or two. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.25;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Terminal management, dispatch and network staff are fixed; line-haul drivers and fuel move with freight volume. */
    public const FIXED_COST_LABOR_SHARE = 0.55;
    /** Dedicated contracts carry surcharges; spot brokerage does not. */
    public const PRICING_POWER_INDEX = 0.60;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Fulfilment centres, cross-docks and truck fleets are largely leased. */
    public const LEASE_LIABILITY_INTENSITY = 0.40;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50;
    public const BASE_COVERAGE_ERROR = 0.08;

        public function getMinIcr(): float { return 2.5; }
    public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getDividendCrisisIcr(): float { return 1.75; }
    public function getBuybackMinIcr(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.15; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.25; }

    public function getSeasonalityFactors(): array
    {
        return [0.85, 0.95, 1.10, 1.10]; // Q3-Q4 peak freight shipping & holiday logistics surge
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.025; }
    
    public function getCapexCyclicality(): float { return 0.60; } // Fleets, sorting hubs, and automated fulfillment centers
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from long-term dedicated fleet contracts. */
    public const DEDICATED_FLEET_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from volatile spot freight brokerage. */
    public const SPOT_BROKERAGE_WEIGHT  = 0.30;
    /** Baseline fraction of revenue derived from 3PL warehousing and automated cross-docking. */
    public const WAREHOUSING_3PL_WEIGHT = 0.10;

    // --- Macroeconomic & Pricing Rails ---
    /** Macroeconomic demand shift sensitivity to global trade and domestic GDP output gap. */
    public const MACRO_DEMAND_SCALAR = 1.40;

    // --- Stream Physics & Spot Freight Sensitivity ---
    /** Sensitivity of spot freight brokerage revenue to market-clearing ocean and overland spot freight rates. */
    public const SPOT_FREIGHT_SENSITIVITY = 0.60;
    /** Idiosyncratic revenue variance scalar for stable contracted dedicated fleets. */
    public const DEDICATED_VARIANCE_SCALAR = 0.20;
    /** Idiosyncratic revenue variance scalar for volatile spot freight brokerage. */
    public const SPOT_VARIANCE_SCALAR = 0.60;
    /** Idiosyncratic revenue variance scalar for sticky 3PL warehousing and storage. */
    public const WAREHOUSING_VARIANCE_SCALAR = 0.10;
    /** Z-score threshold required to trigger logistics surge pricing shock event. */
    public const SPOT_SURGE_Z_SCORE = 1.75;
    /** Multiplier applied to spot brokerage revenue during surge pricing crunches. */
    public const SPOT_SURGE_MULT = 1.40;

    // --- Fuel Surcharge & Energy Physics ---
    /** Weight of variable margin drag attributed to energy/diesel fuel lag in observable shock Z. */
    public const OBSERVABLE_FUEL_DRAG_WEIGHT = 0.50;

    // --- Manufacturing PMI, Trade & Supply Chain Transmission ---
    /** Sensitivity of contract freight and 3PL warehousing volumes to manufacturing PMI shifts. */
    public const PMI_FREIGHT_SENSITIVITY = 0.45;
    /** Sensitivity of overland and intermodal freight to merchandise trade balance shifts. */
    public const TRADE_BALANCE_SENSITIVITY = 1.00;
    /** Sensitivity of spot freight brokerage surge demand to global supply chain pressure. */
    public const GSCPI_SPOT_SURGE_SENSITIVITY = 0.08;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $this->resolveLaggedOutputGap($stock, $macroState);
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, sensitivity: self::PMI_FREIGHT_SENSITIVITY);
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        $beta = $this->getOperatingCyclicality($stock);

        return [
            'macro_demand_shift' => ($outputGap * $beta * self::MACRO_DEMAND_SCALAR) + $pmiShift + $tradeShift,
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::DedicatedFleetWeight->value => self::DEDICATED_FLEET_WEIGHT,
            ModelParam::SpotBrokerageWeight->value  => self::SPOT_BROKERAGE_WEIGHT,
            ModelParam::Warehousing3plWeight->value => self::WAREHOUSING_3PL_WEIGHT,
        ]);

        $fleetWeight = $params[ModelParam::DedicatedFleetWeight];
        $spotWeight  = $params[ModelParam::SpotBrokerageWeight];
        $whWeight    = $params[ModelParam::Warehousing3plWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'dedicated_fleet_contracts' => $params[ModelParam::DedicatedFleetWeight],
            'spot_freight_brokerage'    => $params[ModelParam::SpotBrokerageWeight],
            'value_added_warehousing'   => $params[ModelParam::Warehousing3plWeight],
        ]);

        $fleetWeight = $activeWeights['dedicated_fleet_contracts'];
        $spotWeight  = $activeWeights['spot_freight_brokerage'];
        $whWeight    = $activeWeights['value_added_warehousing'];

        // Spot Freight Rate pricing power & supply chain pressure (applied strictly to market-clearing spot brokerage)
        $freightShift = ($macroState->freightRateIndexEma - 100.0) / 100.0;
        $gscpiShift = max(0.0, $macroState->supplyChainPressureIndexEma - MacroEngine::GSCPI_BASELINE);
        $spotFreightBoost = ($freightShift * self::SPOT_FREIGHT_SENSITIVITY) + ($gscpiShift * self::GSCPI_SPOT_SURGE_SENSITIVITY);

        $fleetZ = $streams->generateZ('dedicated_fleet_contracts', 0.40);
        $spotZ  = $streams->generateZ('spot_freight_brokerage', 0.15);
        $whZ    = $streams->generateZ('value_added_warehousing', 0.50);

        $spotMultiplier = 1.0;
        $eventType = null;
        if ($spotZ > self::SPOT_SURGE_Z_SCORE) {
            $spotMultiplier = self::SPOT_SURGE_MULT;
            $eventType = ShockEvent::LOGISTICS_SURGE_PRICING ?? 'logistics_surge_pricing';
        }

        $fleetRevenue = max(0.0, $expectedRevenue * $fleetWeight * (1.0 + ($fleetZ * ($baselineVol * self::DEDICATED_VARIANCE_SCALAR))));
        $spotRevenue  = max(0.0, $expectedRevenue * $spotWeight  * (1.0 + ($spotZ  * ($baselineVol * self::SPOT_VARIANCE_SCALAR)) + $spotFreightBoost) * $spotMultiplier);
        $whRevenue    = max(0.0, $expectedRevenue * $whWeight    * (1.0 + ($whZ    * ($baselineVol * self::WAREHOUSING_VARIANCE_SCALAR))));

        $streamRevenues = [
            'dedicated_fleet_contracts' => $fleetRevenue,
            'spot_freight_brokerage'    => $spotRevenue,
            'value_added_warehousing'   => $whRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Fuel Surcharge Lag: diesel and driver payroll reach fleet operations at spot and are surcharged
        // through to shippers with a lag; warehousing carries no fuel.
        $fuelLagDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $fuelLagDrag);

        $primaryShockZ = $streams->resolveDominantShockZ([$spotZ, $fleetZ, $whZ]);

        $observableShockZ = ($fleetZ * $fleetWeight * self::DEDICATED_VARIANCE_SCALAR * $baselineVol) +
            ($spotZ * $spotWeight * self::SPOT_VARIANCE_SCALAR * $baselineVol) +
            ($spotFreightBoost * $spotWeight) -
            ($fuelLagDrag * self::OBSERVABLE_FUEL_DRAG_WEIGHT);

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

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'energy_cost_push_lag',
            'freight_rate_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'trade_balance_to_gdp_ema',
            'wage_growth_ema',
        ];
    }
}
