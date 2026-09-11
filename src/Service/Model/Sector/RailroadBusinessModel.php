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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Carloads track industrial output; captive track networks limit switching. */
    public const OPERATING_CYCLICALITY = 1.00;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.20;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.20, 'labor' => 0.30, 'ppi' => 0.05, 'metals' => 0.03];
    /** Fuel surcharge programs reprice within a quarter or two of the diesel move. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.25;
    /** Captive track networks: nearly all fuel and wage moves are surcharged through. */
    public const PRICING_POWER_INDEX = 0.80;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: harvest grain carloads in the second half, winter weather in Q1.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 1.00, 1.02, 1.03];
    }

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Leased locomotives and rolling stock. */
    public const LEASE_LIABILITY_INTENSITY = 0.10;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Crew and maintenance-of-way payroll shares overhead with track, locomotive and fuel costs. */
    public const FIXED_COST_LABOR_SHARE = 0.45;

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
    /** Baseline fraction of revenue from commuter transit subscriptions; zero for a pure freight hauler. */
    public const TRANSIT_SUBSCRIPTION_WEIGHT = 0.00;

    // --- Physics & Variances ---
    /** Idiosyncratic revenue variance scalar for cyclical intermodal container shipping. */
    public const INTERMODAL_VARIANCE_SCALAR = 0.30;
    /** Idiosyncratic revenue variance scalar for bulk agricultural and energy carloads. */
    public const BULK_VARIANCE_SCALAR       = 0.15;
    /** Idiosyncratic revenue variance scalar for industrial and automotive carloads. */
    public const INDUSTRIAL_VARIANCE_SCALAR = 0.25;
    /** Idiosyncratic revenue variance scalar for commuter subscriptions: the steadiest line on the network. */
    public const TRANSIT_VARIANCE_SCALAR    = 0.08;
    /**
     * Elasticity of commuter ridership to the output gap. Employment drives journeys, so a recession thins
     * the carriages, but an auto-renewing season ticket is cancelled long after the commute stops, which is
     * why this is a fraction of the freight betas rather than a peer of them.
     */
    public const TRANSIT_EMPLOYMENT_ELASTICITY = 0.25;


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
            ModelParam::SubscriptionWeight->value       => self::TRANSIT_SUBSCRIPTION_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta     = $this->getOperatingCyclicality($stock);

        // A passenger operator is a different business wearing the same track. Freight sells capacity to
        // shippers and rises and falls with trade and manufacturing; a commuter network sells an
        // auto-renewing season ticket to people who have to get to work. The stream only exists for
        // operators that carry passengers, so a pure freight hauler never sees it.
        $rawTransitWeight = $params[ModelParam::SubscriptionWeight];

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $targetWeights = [
            'intermodal_freight'  => $params[ModelParam::IntermodalFreightWeight],
            'bulk_commodities'    => $params[ModelParam::BulkCommoditiesWeight],
            'industrial_carloads' => $params[ModelParam::IndustrialCarloadsWeight],
        ];
        if ($rawTransitWeight > 0.0) {
            $targetWeights['transit_subscriptions'] = $rawTransitWeight;
        }

        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $intermodalWeight = $activeWeights['intermodal_freight'];
        $bulkWeight       = $activeWeights['bulk_commodities'];
        $industrialWeight = $activeWeights['industrial_carloads'];
        $transitWeight    = $activeWeights['transit_subscriptions'] ?? 0.0;

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

        if ($transitWeight > 0.0) {
            // Ridership follows employment rather than trade, and a subscription lags the decision to stop
            // commuting, so the output gap reaches this stream at a fraction of the freight elasticity.
            $transitZ = $streams->generateZ('transit_subscriptions', 0.55);
            $transitMacroShift = $macroState->outputGapEma * self::TRANSIT_EMPLOYMENT_ELASTICITY * $beta;

            $streamRevenues['transit_subscriptions'] = max(0.0, $expectedRevenue * $transitWeight
                * (1.0 + ($transitZ * ($baselineVol * self::TRANSIT_VARIANCE_SCALAR)) + $transitMacroShift));
        }

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Diesel fuel surcharge lag: locomotive diesel and crew payroll reach the cost base at spot and are
        // surcharged through with a lag, so a spike compresses the operating ratio for a quarter or two.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag);

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

    /** Track slow orders & locomotive breakdown drag toward floor */
    public function getDepreciationDecayRate(): float
    {
        return self::TRACK_AGING_DECAY_RATE;
    }

    /** Precision Scheduled Railroading (PSR) efficiency expands margin ceiling */
    public function getModernizationGainRate(): float
    {
        return self::PSR_EFFICIENCY_GAIN_RATE;
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
            'agricultural_commodity_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'industrial_metals_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'trade_balance_to_gdp_ema',
            'wage_growth_ema',
        ];
    }
}
