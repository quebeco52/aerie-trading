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
 * Earnings strategy for Tools & Accessories (Precision Tooling, Hardware).
 * 
 * Financial Physics:
 * - Premium B2B: Ultra-high precision industrial tooling. Extremely sticky, low variance, massive margins.
 * - Retail Liquidations: Manufacturing scrap sold to prosumers. Highly cyclical and volatile.
 */
class ToolsAndAccessoriesBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Industrial tooling and prosumer hardware. */
    public const OPERATING_CYCLICALITY = 1.00;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.60;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['metals' => 0.20, 'ppi' => 0.25, 'energy' => 0.05, 'labor' => 0.20, 'freight' => 0.03];

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: holiday and year-end promotional volumes.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.92, 1.00, 0.98, 1.10];
    }

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Leased plants and distribution warehouses. */
    public const LEASE_LIABILITY_INTENSITY = 0.10;

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from high-margin commercial B2B precision tooling. */
    public const COMMERCIAL_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from highly cyclical consumer retail scrap. */
    public const CONSUMER_WEIGHT = 0.40;

    // --- Pricing Power & Macro Physics ---
    /** "Structural ransom" pricing power for mission-critical industrial tools. */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.90; 

    // --- Revenue & Shock Physics ---
    /** Very insulated from typical manufacturing boom/bust. */
    public const REVENUE_VARIANCE_SCALAR = 0.20;
    /** Structural variable cost ratio of premium B2B tooling (high margin). */
    public const COMMERCIAL_VARIABLE_COST_RATIO = 0.25;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating severe supply chain or shipping congestion. */
    public const SUPPLY_CHAIN_CONGESTION_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited shipping for disrupted supply chains. */
    public const SUPPLY_CHAIN_CONGESTION_PENALTY = 0.05;
    /** Positive z-score threshold indicating a massive industrial/manufacturing super-cycle. */
    public const MANUFACTURING_SUPER_CYCLE_Z_SCORE = 2.40;
    /** Top-line commercial revenue multiplier for an industrial super-cycle. */
    public const MANUFACTURING_SUPER_CYCLE_MULT = 1.15;

    // --- Continuous Elasticity ---
    /** Variable margin sensitivity to commercial precision tooling scale economies. */
    public const PRECISION_SCALE_ELASTICITY = 0.012;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in precision manufacturing IP. */
    public const PRECISION_TOOLING_DECAY_RATE = 0.020;
    /** Quarterly margin gain scalar per unit of next-gen CNC and R&D automation overinvestment. */
    public const AUTOMATION_RND_GAIN_RATE = 0.010;
    /** Structural minimum operating margin floor under severe manufacturing tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.12;
    /** Structural maximum operating margin ceiling for automated precision monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.34;

    // --- Manufacturing PMI, Housing & PPI Transmission ---
    /** Sensitivity of commercial B2B precision tooling demand to manufacturing PMI shifts. */
    public const PMI_COMMERCIAL_SENSITIVITY = 0.40;
    /** Sensitivity of retail and prosumer tool sales to residential housing starts. */
    public const HOUSING_STARTS_SENSITIVITY = 0.35;
    /** Alloy and carbide supply contracts fix input prices for about a quarter. */
    public const INPUT_COST_LAG_YEARS = 0.25;

        public function getReversionSpeed(): float { return 0.08; }
    public function getMoatSpread(): float { return 0.03; }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Extremely insulated from typical manufacturing boom/bust, but tied to industrial tooling & construction
        $outputGap = $macroState->outputGapEma;
        $beta = $this->getOperatingCyclicality($stock);
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, sensitivity: self::PMI_COMMERCIAL_SENSITIVITY);
        $housingShift = MathUtility::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_STARTS_SENSITIVITY);

        $physics['macro_demand_shift'] = ($outputGap * $beta * 0.50) + ($pmiShift * 0.60) + ($housingShift * 0.40);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CommercialWeight->value => self::COMMERCIAL_WEIGHT,
            ModelParam::ConsumerWeight->value   => self::CONSUMER_WEIGHT,
        ]);

        $commercialWeight = $params[ModelParam::CommercialWeight];
        $consumerWeight   = $params[ModelParam::ConsumerWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'commercial' => $params[ModelParam::CommercialWeight],
            'consumer'   => $params[ModelParam::ConsumerWeight],
        ]);

        $commercialWeight = $activeWeights['commercial'];
        $consumerWeight   = $activeWeights['consumer'];

        // Commercial is highly sticky, Consumer is volatile
        $commercialZ = $streams->generateZ('commercial', 0.02);
        $consumerZ   = $streams->generateZ('consumer', 0.30);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        $standardParams = $this->resolveModelParameters($stock, [ModelParam::PricingPowerIndex->value => 0.5]);
        $pricingPower = max(0.0, min(1.0, $standardParams[ModelParam::PricingPowerIndex]));
        $macroSensitivityMultiplier = 0.5 + $pricingPower;

        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmiEma, sensitivity: self::PMI_COMMERCIAL_SENSITIVITY);
        $housingShift = MathUtility::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_STARTS_SENSITIVITY);

        $commercialMacroVolumeShock = ($macroState->outputGapEma * $macroSensitivityMultiplier * $this->getOperatingCyclicality($stock)) - ($fxShift * 0.10) + $pmiShift;
        $consumerMacroVolumeShock = ($sentimentShift * $macroSensitivityMultiplier * $this->getOperatingCyclicality($stock)) - ($fxShift * 0.10) + $housingShift;

        // Tail Risk Events
        $cycleMultiplier = 1.0;
        $eventType = null;
        $supplyChainPenalty = 0.0;

        if ($eventZ < self::SUPPLY_CHAIN_CONGESTION_Z_SCORE) {
            $supplyChainPenalty = self::SUPPLY_CHAIN_CONGESTION_PENALTY;
            $eventType = ShockEvent::SHIPPING_PORT_CONGESTION;
            $cycleMultiplier = 0.90; // Add revenue drop
        } elseif ($eventZ > self::MANUFACTURING_SUPER_CYCLE_Z_SCORE) {
            $cycleMultiplier = self::MANUFACTURING_SUPER_CYCLE_MULT;
            $eventType = ShockEvent::DEFENSE_CONTRACT_WIN; // Proxy for mega industrial boom
        }

        $commercialRevenue = max(0.0, $expectedRevenue * $commercialWeight * (1.0 + ($commercialZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.1)) + $commercialMacroVolumeShock) * $cycleMultiplier);
        $consumerRevenue   = max(0.0, $expectedRevenue * $consumerWeight * (1.0 + ($consumerZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 2.5)) + $consumerMacroVolumeShock));

        $streamRevenues = [
            'commercial' => $commercialRevenue,
            'consumer'   => $consumerRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending ---
        // Commercial B2B precision tooling operates at a structurally lower variable cost (higher margin).
        // Consumer retail scrap is a lower margin business.
        $expectedCommercialRevenue = $expectedRevenue * $commercialWeight;
        $expectedConsumerRevenue = $expectedRevenue * $consumerWeight;

        $commercialBaselineCosts = $expectedCommercialRevenue * self::COMMERCIAL_VARIABLE_COST_RATIO;

        // Derive required consumer cost ratio to hit the engine's target margin at baseline
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $consumerBaselineCosts = $targetTotalCosts - $commercialBaselineCosts;
        $consumerVariableMargin = $expectedConsumerRevenue > 0 ? $consumerBaselineCosts / $expectedConsumerRevenue : $realizedVariableMargin;

        // Apply derived distinct margins to actual shocked revenues
        $actualVariableCosts = ($commercialRevenue * self::COMMERCIAL_VARIABLE_COST_RATIO) + ($consumerRevenue * $consumerVariableMargin);

        // Input cost basket: alloys and carbide, wholesale components, energy and shop-floor payroll,
        // recovered in list prices at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        // Continuous Elasticity
        $elasticityShift = -self::PRECISION_SCALE_ELASTICITY * $commercialZ * $commercialWeight;

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $supplyChainPenalty + $inputCostDrag + $elasticityShift;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Blended primary shock for standard model integration
        $primaryShockZ = $streams->resolveDominantShockZ([
            ($commercialZ * $commercialWeight) + ($consumerZ * $consumerWeight),
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
        // B2B precision tooling orders are opaque and hard for retail analysts to track.
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.15,
            errorStdDev: 0.05,
            minVisibility: 0.0,
            eventBaseVisibility: 0.50,
            eventMinVisibility: 0.20
        );
    }

    /** Precision tooling tech debt and loss of manufacturing edge */
    public function getDepreciationDecayRate(): float
    {
        return self::PRECISION_TOOLING_DECAY_RATE;
    }

    /** Investment in next-gen CNC and R&D automation expands margin */
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
            'consumer_sentiment_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'housing_starts_index_ema',
            'industrial_metals_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
