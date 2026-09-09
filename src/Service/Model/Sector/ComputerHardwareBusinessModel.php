<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Computer Hardware (Supercomputers, PCs, Peripherals).
 * 
 * Financial Physics:
 * - Enterprise Model: High-margin B2B hardware sales. Less sensitive to macro output gap.
 * - Consumer Model: Highly cyclical, deeply sensitive to consumer discretionary spending.
 * - Inventory Obsolescence: Physical hardware deprecates quickly, creating higher baseline variance.
 */
class ComputerHardwareBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Refresh cycles are deferred in downturns; devices substitute readily. */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.00;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['ppi' => 0.40, 'metals' => 0.08, 'freight' => 0.04, 'labor' => 0.15, 'energy' => 0.02];
    /** Boxes are specified on a common component bill and bid against near-identical rivals; component cost moves reach street prices, but little else does. */
    public const PRICING_POWER_INDEX = 0.45;

    // --- Inventory Cycle ---
    /** Order sensitivity to the economy-wide inventory-to-sales gap (Metzler cycle): overhangs trigger destocking, shortfalls restocking. Channel inventory whipsaws PC and server shipments hardest. */
    public const INVENTORY_CYCLE_SENSITIVITY = 1.00;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: back-to-school and holiday device cycles.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.92, 0.97, 1.03, 1.08];
    }

    // --- Balance Sheet Realism ---
    /** Stock-based compensation as a fraction of revenue (ASC 718): non-cash, added back to FCF, settled in new shares. Hardware engineering talent paid partly in equity. */
    public const STOCK_COMPENSATION_INTENSITY = 0.04;

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from high-margin enterprise B2B sales. */
    public const ENTERPRISE_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from cyclical consumer retail hardware. */
    public const CONSUMER_WEIGHT   = 0.40;

    // --- Pricing Power & Macro Physics ---
    /** Hard floor on pricing power given consumer dependency. */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.80; 

    // --- Revenue & Shock Physics ---
    /** Inventory obsolescence increases variance. */
    public const REVENUE_VARIANCE_SCALAR = 0.35;
    /** Structural variable cost ratio of enterprise hardware sales (high margin). */
    public const ENTERPRISE_VARIABLE_COST_RATIO = 0.35;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating a severe semiconductor fab shortage. */
    public const SEMICONDUCTOR_SHORTAGE_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited component sourcing. */
    public const SEMICONDUCTOR_SHORTAGE_PENALTY = 0.07;
    /** Positive z-score threshold indicating a massive enterprise/AI hardware super-cycle. */
    public const AI_SUPER_CYCLE_Z_SCORE = 2.40;
    /** Top-line enterprise revenue multiplier for an AI/data center super-cycle. */
    public const AI_SUPER_CYCLE_MULT = 1.20;

    // --- Continuous Elasticity ---
    /** Variable margin sensitivity to enterprise hardware sales pulling in high-margin software/support attach rates. */
    public const ENTERPRISE_SOFTWARE_ATTACH_ELASTICITY = 0.015;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in hardware architecture R&D. */
    public const HARDWARE_RND_DECAY_RATE = 0.025;
    /** Quarterly margin gain scalar per unit of next-gen silicon design overinvestment. */
    public const SILICON_MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under severe engineering tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.08;
    /** Structural maximum operating margin ceiling for advanced silicon monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;

    // --- Trade Balance & PPI Transmission ---
    /** Sensitivity of global IT hardware trade flows to merchandise trade balance shifts. */
    public const TRADE_BALANCE_SENSITIVITY = 1.20;
    /** Component supply agreements fix bill-of-materials prices for about a quarter before spot moves reach the line. */
    public const INPUT_COST_LAG_YEARS = 0.25;

        public function getReversionSpeed(): float { return 0.25; }
    public function getMoatSpread(): float { return 0.02; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.045;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Set to 0.0 to prevent double-dipping, as macro volume shocks 
        // are handled discretely per-stream in calculateSectorPhysics.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EnterpriseWeight->value => self::ENTERPRISE_WEIGHT,
            ModelParam::ConsumerWeight->value   => self::CONSUMER_WEIGHT,
        ]);

        $enterpriseWeight = $params[ModelParam::EnterpriseWeight];
        $consumerWeight   = $params[ModelParam::ConsumerWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'enterprise_hardware' => $params[ModelParam::EnterpriseWeight],
            'consumer_hardware'   => $params[ModelParam::ConsumerWeight],
        ]);

        $enterpriseWeight = $activeWeights['enterprise_hardware'];
        $consumerWeight   = $activeWeights['consumer_hardware'];

        // Consumer hardware is volatile, enterprise hardware is stickier
        $enterpriseZ = $streams->generateZ('enterprise_hardware', 0.15);
        $consumerZ   = $streams->generateZ('consumer_hardware', 0.05);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        $pricingPower = $this->resolvePricingPower($stock);
        $macroSensitivityMultiplier = self::MIN_BETA_PRICING_POWER_FLOOR + $pricingPower;

        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;

        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        // Metzler inventory cycle: a channel overhang (positive gap) means distributors destock before reordering.
        $inventoryCycleShift = -$macroState->inventoryStockGapEma * self::INVENTORY_CYCLE_SENSITIVITY;
        $enterpriseMacroVolumeShock = ($macroState->outputGapEma * $macroSensitivityMultiplier * $this->getOperatingCyclicality($stock)) - ($fxShift * 0.10) + ($tradeShift * 0.50) + $inventoryCycleShift;
        $consumerMacroVolumeShock = ($sentimentShift * $macroSensitivityMultiplier * $this->getOperatingCyclicality($stock)) - ($fxShift * 0.15) + ($tradeShift * 0.50) + $inventoryCycleShift;

        // Tail Risk Events
        $enterpriseMultiplier = 1.0;
        $consumerMultiplier = 1.0;
        $eventType = null;
        $shortagePenalty = 0.0;

        if ($eventZ < self::SEMICONDUCTOR_SHORTAGE_Z_SCORE) {
            $shortagePenalty = self::SEMICONDUCTOR_SHORTAGE_PENALTY;
            $eventType = ShockEvent::SEMICONDUCTOR_FAB_SHORTAGE;
            $enterpriseMultiplier = 0.85; // Severe volume constraint
            $consumerMultiplier = 0.80; // Even worse for consumer
        } elseif ($eventZ > self::AI_SUPER_CYCLE_Z_SCORE) {
            $enterpriseMultiplier = self::AI_SUPER_CYCLE_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH; // Proxy for AI boom
        }

        // Consumer revenue gets a massive variance scalar (boom/bust upgrade cycles)
        // Enterprise revenue gets a dampened variance scalar (sticky B2B contracts)
        $enterpriseRevenue = max(0.0, $expectedRevenue * $enterpriseWeight * (1.0 + ($enterpriseZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.5)) + $enterpriseMacroVolumeShock) * $enterpriseMultiplier);
        $consumerRevenue   = max(0.0, $expectedRevenue * $consumerWeight * (1.0 + ($consumerZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 1.5)) + $consumerMacroVolumeShock) * $consumerMultiplier);

        $streamRevenues = [
            'enterprise_hardware' => $enterpriseRevenue,
            'consumer_hardware'   => $consumerRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending ---
        // Enterprise B2B hardware operates at a structurally lower variable cost (higher margin).
        // Consumer hardware is a higher volume, lower margin business.
        $expectedEnterpriseRevenue = $expectedRevenue * $enterpriseWeight;
        $expectedConsumerRevenue = $expectedRevenue * $consumerWeight;

        $enterpriseBaselineCosts = $expectedEnterpriseRevenue * self::ENTERPRISE_VARIABLE_COST_RATIO;

        // Derive required consumer cost ratio to hit the engine's target margin at baseline
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $consumerBaselineCosts = max(0.0, $targetTotalCosts - $enterpriseBaselineCosts);
        $consumerVariableMargin = $expectedConsumerRevenue > 0 ? $consumerBaselineCosts / $expectedConsumerRevenue : $realizedVariableMargin;

        // Apply derived distinct margins to actual shocked revenues
        $actualVariableCosts = ($enterpriseRevenue * self::ENTERPRISE_VARIABLE_COST_RATIO) + ($consumerRevenue * $consumerVariableMargin);

        // Input cost basket: components and wholesale goods, metals, freight and assembly payroll, recovered
        // in list prices at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        // Continuous Elasticity
        $elasticityShift = -self::ENTERPRISE_SOFTWARE_ATTACH_ELASTICITY * $enterpriseZ * $enterpriseWeight;

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $rawMargin = $effectiveMargin + $shortagePenalty + $inputCostDrag + $elasticityShift;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Blended primary shock for standard model integration
        $primaryShockZ = $streams->resolveDominantShockZ([
            ($enterpriseZ * $enterpriseWeight) + ($consumerZ * $consumerWeight),
        ], $eventZ);

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
        );
    }

    public function getCoverageProfile(\App\Entity\Stock $stock): \App\DTO\SectorCoverageProfile
    {
        // Consumer tech supply chains are heavily scrutinized and scraped.
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.35,
            errorStdDev: 0.05,
            minVisibility: 0.10,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.50
        );
    }

    /** R&D tech debt and architecture lag */
    public function getDepreciationDecayRate(): float
    {
        return self::HARDWARE_RND_DECAY_RATE;
    }

    /** Next-gen silicon design modernization */
    public function getModernizationGainRate(): float
    {
        return self::SILICON_MODERNIZATION_GAIN_RATE;
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
            'consumer_sentiment_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'industrial_metals_index_ema',
            'inventory_stock_gap_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'trade_balance_to_gdp_ema',
            'wage_growth_ema',
        ];
    }
}
