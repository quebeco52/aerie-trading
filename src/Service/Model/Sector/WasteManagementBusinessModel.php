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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Residential collection is contracted; commercial roll-offs follow construction. */
    public const OPERATING_CYCLICALITY = 0.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.15, 'labor' => 0.35, 'ppi' => 0.05];
    /** Municipal contracts carry CPI escalators: pricing tracks most of expected inflation. */
    public const PRICING_ELASTICITY = 0.85;
    /** Fuel surcharges reprice within a quarter or two. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.25;
    /** Landfill permitting oligopolies dictate price; diesel and crew wages are largely surcharged through. */
    public const PRICING_POWER_INDEX = 0.70;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: spring and summer construction and yard volumes.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.94, 1.03, 1.05, 0.98];
    }

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50; // High visibility due to steady municipal contracts
    public const BASE_COVERAGE_ERROR = 0.05;

        public function getMinIcr(): float { return 2.5; }
    public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getDividendCrisisIcr(): float { return 1.75; }
    public function getBuybackMinIcr(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.12; }
    public function getMoatSpread(): float { return 0.02; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.08; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.25; }

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

    // --- Housing & Construction Waste Transmission ---
    /** Sensitivity of commercial roll-off construction and demolition (C&D) waste volume to housing starts. */
    public const HOUSING_STARTS_WASTE_SENSITIVITY = 0.30;

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
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

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
        $eventZ       = $streams->generateExogenousZ('event', 0.05);

        // --- Macro Demand & Pricing Sensitivities ---
        $housingWasteShift = MathUtility::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_STARTS_WASTE_SENSITIVITY);
        $macroBoost = ($macroState->outputGapEma * 1.5 * $beta) + $housingWasteShift; // Affects Commercial/Construction
        $excessInflation = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
        $cpiEscalatorBoost = $excessInflation * self::CPI_ESCALATOR_CAPTURE; // Passive revenue boost

        // Recycling is driven entirely by global commodity, energy, and scrap metal prices
        $energyShift = $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
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
        // Municipal CPI escalators reprice the same collection routes: pure price revenue.
        $priceRevenue       = max(0.0, $expectedRevenue * $residentialWeight * $cpiEscalatorBoost);
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
        // Fuel Surcharge Lag: diesel and crew wages reach the cost base at spot and are surcharged through
        // to customers with a lag, so a spike squeezes margin for a quarter or two and a collapse pays a dividend.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        // Apply structurally driven penalties directly to the baseline variable margin
        $rawMargin = $realizedVariableMargin + $inputCostDrag + $disasterPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$commercialZ, $residentialZ, $recyclingZ], $eventZ);

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
            priceRevenue: $priceRevenue,
        );
    }

    /** Fleet aging (maintenance costs spike) and landfill airspace depletion */
    public function getDepreciationDecayRate(): float
    {
        return self::FLEET_AGING_DECAY_RATE;
    }

    /** Fleet automation, transition to cheaper CNG/EV trucks, and RNG gas capture facility builds */
    public function getModernizationGainRate(): float
    {
        return self::AUTOMATION_RNG_GAIN_RATE;
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
            'exchange_rate_index_ema',
            'housing_starts_index_ema',
            'industrial_metals_index_ema',
            'inflation_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
