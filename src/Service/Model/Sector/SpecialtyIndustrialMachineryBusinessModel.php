<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Specialty Industrial Machinery.
 * 
 * Financial Physics:
 * - High Moat: Specialty products mean higher margins and pricing power.
 * - Backlog-Buffered Cyclicality: Equipment sales are driven by the macro output gap, cushioned by multi-quarter order backlogs.
 * - Sticky Demand: Once integrated, customers rarely switch, reducing idiosyncratic volatility.
 * - The Razor/Razorblade: High-margin aftermarket services cushion recessions organically.
 */
class SpecialtyIndustrialMachineryBusinessModel extends HeavyManufacturingBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Backlog-cushioned capital equipment demand. */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.06, 'metals' => 0.20, 'freight' => 0.03, 'ppi' => 0.25, 'labor' => 0.25];

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Application engineering and the field service network are carried between order cycles to defend the installed base. */
    public const FIXED_COST_LABOR_SHARE = 0.55;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Capital equipment is quoted internationally and competes with European and Japanese builders on price. */
    public const FX_REVENUE_EXPOSURE = 0.10;
    /** An installed base tied to consumable and spare-parts revenue prices close to a monopoly on the aftermarket, which is where the margin sits. */
    public const PRICING_POWER_INDEX = 0.70;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 customer capex budget flush.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.97, 1.01, 0.99, 1.03];
    }

    // --- Dual-Stream Revenue Weights ---
    /** Baseline fraction of revenue from heavy equipment and automated machinery sales. */
    public const EQUIPMENT_WEIGHT = 0.70;
    /** Baseline fraction of revenue from high-margin aftermarket parts, consumables, and maintenance. */
    public const SERVICES_WEIGHT  = 0.30;

    // --- Razor/Razorblade Margin Architecture ---
    /** Structural variable cost ratio of the high-margin aftermarket parts and services. */
    public const SERVICES_VARIABLE_COST_RATIO = 0.15;

    // --- Macro & Backlog Physics ---
    /** Macro sensitivity multiplier for equipment demand to the broader output gap. */
    public const MACRO_GDP_SENSITIVITY = 1.50;
    /** Fraction of the equipment order backlog (opening backlog plus new orders) executed and recognized each quarter (~1.9 quarters of coverage). */
    public const EQUIPMENT_BACKLOG_BURN_RATE = 0.35;
    /** Volatility multiplier for equipment sales variance. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Volatility reduction factor for aftermarket services relative to equipment sales. */
    public const SERVICES_VARIANCE_RATIO = 0.20;
    /** Low scalar sensitivity of machinery equipment orders to aggregate industrial capital capacity overhang. */
    public const CAPITAL_OVERHANG_SCALAR = 0.15;

    // --- Capacity Utilization & Supply Chain Physics ---
    /** Sensitivity of precision equipment sales expansion to industrial capacity utilization deviations. */
    public const CU_EQUIPMENT_EXPANSION_SENSITIVITY = 0.50;
    /** Sensitivity of precision equipment sales expansion to manufacturing PMI diffusion shifts. */
    public const PMI_EQUIPMENT_SENSITIVITY          = 0.40;
    /** Variable margin penalty per standard deviation of global supply chain friction (GSCPI). */
    public const GSCPI_MARGIN_PENALTY_SCALAR        = 0.015;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating a severe specialty supply chain or parts disruption. */
    public const SUPPLY_CHAIN_DISRUPTION_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited sourcing for disrupted supply chains. */
    public const SUPPLY_CHAIN_DISRUPTION_PENALTY = 0.06;
    /** Revenue multiplier applied during supply chain delays. */
    public const SUPPLY_CHAIN_REVENUE_DROP_MULT   = 0.90;
    /** Positive z-score threshold indicating a massive multi-year infrastructure or factory project win. */
    public const MEGA_INFRASTRUCTURE_DEAL_Z_SCORE = 2.40;
    /** Top-line equipment revenue multiplier for a mega infrastructure deal win. */
    public const MEGA_INFRASTRUCTURE_DEAL_MULT    = 1.20;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in precision manufacturing IP. */
    public const PRECISION_TOOLING_DECAY_RATE = 0.020;
    /** Quarterly margin gain scalar per unit of next-gen CNC and R&D automation overinvestment. */
    public const AUTOMATION_RND_GAIN_RATE     = 0.010;
    /** Structural minimum operating margin floor under severe manufacturing tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.10;
    /** Structural maximum operating margin ceiling for automated precision monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.28;

        public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.025; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.18; }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    public function getCapexCyclicality(): float
    {
        return 2.5;
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.03;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift so macro cyclicality is isolated 
        // exclusively to equipment sales, keeping aftermarket services defensive.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => 0.70,
            ModelParam::EquipmentWeight->value    => self::EQUIPMENT_WEIGHT,
            ModelParam::ServicesWeight->value     => self::SERVICES_WEIGHT,
        ]);

        $pricingPower    = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));
        $equipmentWeight = $params[ModelParam::EquipmentWeight];
        $servicesWeight  = $params[ModelParam::ServicesWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'equipment_sales'      => $params[ModelParam::EquipmentWeight],
            'aftermarket_services' => $params[ModelParam::ServicesWeight],
        ]);

        $equipmentWeight = $activeWeights['equipment_sales'];
        $servicesWeight  = $activeWeights['aftermarket_services'];

        // Independent stream Z-scores
        $equipmentZ = $streams->generateZ('equipment_sales', 0.20);
        $servicesZ  = $streams->generateZ('aftermarket_services', 0.05);
        $eventZ     = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Sensitivities ---
        // Equipment is highly exposed to GDP, but cushioned by the backlog
        // High industrial capacity utilization triggers capex expansion for factory automation
        // Macro demand hits ORDERS in full; the order backlog below is what cushions recognized revenue.
        $overhangDrag = $macroState->capitalStockOverhangEma * self::CAPITAL_OVERHANG_SCALAR;
        $cuEquipmentBoost = MathUtility::calculateCapacityUtilizationShift($macroState->capacityUtilizationRateEma, MacroEngine::CU_BASELINE, self::CU_EQUIPMENT_EXPANSION_SENSITIVITY);
        $pmiEquipmentBoost = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, MacroEngine::PMI_BASELINE, self::PMI_EQUIPMENT_SENSITIVITY);
        $macroEquipmentBoost = ($macroState->outputGapEma * self::MACRO_GDP_SENSITIVITY * $beta) - $overhangDrag + $cuEquipmentBoost + $pmiEquipmentBoost;

        // --- Tail Risk Events ---
        $dealMultiplier = 1.0;
        $eventType = null;
        $supplyChainPenalty = 0.0;

        if ($eventZ < self::SUPPLY_CHAIN_DISRUPTION_Z_SCORE) {
            $supplyChainPenalty = self::SUPPLY_CHAIN_DISRUPTION_PENALTY;
            $eventType = ShockEvent::AUTO_SUPPLY_CHAIN_DISRUPTION;
            $dealMultiplier = self::SUPPLY_CHAIN_REVENUE_DROP_MULT;
        } elseif ($eventZ > self::MEGA_INFRASTRUCTURE_DEAL_Z_SCORE) {
            $dealMultiplier = self::MEGA_INFRASTRUCTURE_DEAL_MULT;
            $eventType = ShockEvent::INFRASTRUCTURE_BILL_WIN;
        }

        // --- Clamped Stream Revenue Calculation ---
        // Equipment orders enter a multi-quarter backlog and are recognized at the burn rate (percentage of completion).
        $equipmentOrderMultiplier = max(0.0, (1.0 + ($equipmentZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $macroEquipmentBoost + $this->resolveFxDemandShift($macroState)) * $dealMultiplier);
        $equipmentBook = $streams->recognizeBacklog('equipment_sales', $expectedRevenue * $equipmentWeight, $equipmentOrderMultiplier, self::EQUIPMENT_BACKLOG_BURN_RATE);
        $equipmentRevenue = $equipmentBook['revenue'];
        $servicesRevenue  = max(0.0, $expectedRevenue * $servicesWeight * (1.0 + ($servicesZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::SERVICES_VARIANCE_RATIO))));

        $streamRevenues = [
            'equipment_sales'      => $equipmentRevenue,
            'aftermarket_services' => $servicesRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Razor/Razorblade Margin Blending ---
        $expectedServicesRevenue  = $expectedRevenue * $servicesWeight;
        $expectedEquipmentRevenue = $expectedRevenue * $equipmentWeight;

        $servicesBaselineCosts  = $expectedServicesRevenue * self::SERVICES_VARIABLE_COST_RATIO;
        $targetTotalCosts       = $expectedRevenue * $realizedVariableMargin;
        $equipmentBaselineCosts = max(0.0, $targetTotalCosts - $servicesBaselineCosts);

        $equipmentVariableMargin = $expectedEquipmentRevenue > 0 ? ($equipmentBaselineCosts / $expectedEquipmentRevenue) : $realizedVariableMargin;
        $actualVariableCosts = ($servicesRevenue * self::SERVICES_VARIABLE_COST_RATIO) + ($equipmentRevenue * $equipmentVariableMargin);

        // --- Input Cost Basket ---
        // Energy, metals, wholesale components and payroll, recovered in specialty pricing at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $gscpiShift = max(0.0, $macroState->supplyChainPressureIndexEma);
        $gscpiCostDrag = $gscpiShift * self::GSCPI_MARGIN_PENALTY_SCALAR;

        // Effective blended variable cost ratio
        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $rawMargin = $effectiveMargin + $supplyChainPenalty + $inputCostDrag + $gscpiCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$equipmentZ, $servicesZ], $eventZ);

        $observableShockZ = $primaryShockZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
                    kpis: ['book_to_bill' => $equipmentBook['book_to_bill'], 'backlog_quarters' => $equipmentBook['backlog_quarters']],
        );
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        return new SectorCoverageProfile(
            baseVisibility: 0.15,
            errorStdDev: 0.05,
            minVisibility: 0.0,
            eventBaseVisibility: 0.60,
            eventMinVisibility: 0.30
        );
    }

    /** Under-investment below replacement CapEx erodes operating margin toward the sector floor. */
    public function getDepreciationDecayRate(): float
    {
        return self::PRECISION_TOOLING_DECAY_RATE;
    }

    /** Over-investment above replacement CapEx compounds margin toward the sector ceiling. */
    public function getModernizationGainRate(): float
    {
        return self::AUTOMATION_RND_GAIN_RATE;
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
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
