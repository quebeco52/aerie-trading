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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Essential service under regulated monopoly: no peer to take share from. */
    public const OPERATING_CYCLICALITY = 0.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.10;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.00;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.40, 'labor' => 0.15, 'ppi' => 0.05];
    /** Fuel adjustment clauses and rate cases eventually recover costs in full: unit elasticity, but only after the regulatory lag. */
    public const PRICING_ELASTICITY = 1.00;
    /** Rate cases take 12 to 24 months: authorized tariffs follow costs with a long lag. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.50;
    /** Fuel and purchased-power costs are recoverable under fuel adjustment clauses, so pricing power on the basket is high. */
    public const PRICING_POWER_INDEX = 0.90;
    /** Fuel adjustment clauses true up quarterly to annually. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.75;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Rate-base assets are owned; leases are immaterial. */
    public const LEASE_LIABILITY_INTENSITY = 0.03;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Rate-base assets, fuel and purchased power dominate utility overhead; field crews are a minority. */
    public const FIXED_COST_LABOR_SHARE = 0.35;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.05;

        public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.12; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.125; }

    public function getSeasonalityFactors(): array
    {
        return [1.15, 0.85, 1.15, 0.85]; // Twin peaks: Q1 winter heating and Q3 summer air conditioning
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
    /** Regime key for the multi-year liability, litigation and rebuild period after a grid failure or wildfire. */
    public const REGIME_INFRASTRUCTURE_LIABILITY = 'infrastructure_liability';
    /** Quarterly probability the liability overhang is settled (~8 quarter expected duration). */
    public const INFRASTRUCTURE_LIABILITY_EXIT_HAZARD = 0.125;
    /** Ongoing quarterly claims, litigation and hardening cost while the liability regime persists. */
    public const INFRASTRUCTURE_LIABILITY_ONGOING_PENALTY = 0.04;
    /** Quarterly system-hardening and rebuild CapEx as a fraction of annual revenue while the liability regime persists. */
    public const GRID_HARDENING_CAPEX_RATIO = 0.03;

    // --- Bond Proxy Capital Structure ---
    /** Quarterly share of the fixed-rate bond stock that matures and reprices (~12-year average tenor of first mortgage bonds). */
    public const DEBT_MATURITY_ROLLOVER_RATE = 0.02;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    public const REGULATED_REVERSION_SPEED    = 2.0;
    public const DEPRECIATION_DECAY_RATE      = 0.020;
    public const MODERNIZATION_GAIN_RATE      = 0.010;
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.02;
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;

    // --- Rate-Base CapEx & Capital Structure Rails ---
    /** Valuation discount on the earnings multiple while rate-base T&D expansion CapEx keeps FCF negative. */
    public const NEGATIVE_FCF_VAL_DISCOUNT    = 0.92;
    public const MIN_RECAP_ICR_FLOOR          = 2.5;
    public const WACC_ARBITRAGE_THRESHOLD     = 0.01;
    public const UNDERLEVERAGED_DEBT_RATIO    = 0.70;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Regulated Utilities are virtually immune to economic output gaps (essential service).
        // Only industrial/commercial power load fluctuates slightly with GDP.
        $outputGap = $macroState->outputGapEma;
        $beta = $this->getOperatingCyclicality($stock);
        $physics['macro_demand_shift'] = $outputGap * $beta * self::MACRO_DEMAND_SCALAR;

        // Regulatory lag: authorized tariffs follow costs in full, but only after the rate-case cycle (see PRICE_PASS_THROUGH_LAG_YEARS).

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
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

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
        $eventZ       = $streams->generateExogenousZ('event', 0.10); // Infrastructure tail risks

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
        // A wildfire or grid collapse is an onset-quarter emergency charge followed by years of claims,
        // litigation and system hardening (the PG&E pattern) until the liability regime is settled.
        $liabilityElapsed = $streams->evolveRegime(self::REGIME_INFRASTRUCTURE_LIABILITY, 0.0, self::INFRASTRUCTURE_LIABILITY_EXIT_HAZARD);
        $eventType = null;
        $disasterPenalty = 0.0;

        if ($eventZ < self::GRID_FAILURE_Z_SCORE && $liabilityElapsed === 0) {
            $liabilityElapsed = $streams->startRegime(self::REGIME_INFRASTRUCTURE_LIABILITY);
            $eventType = ShockEvent::INFRASTRUCTURE_FAILURE;
            $disasterPenalty = self::GRID_FAILURE_PENALTY;
        } elseif ($liabilityElapsed > 1) {
            $disasterPenalty = self::INFRASTRUCTURE_LIABILITY_ONGOING_PENALTY;
        }

        // Rebuilding and hardening the damaged system is mandatory rate-base CapEx for as long as the
        // liability regime runs; it is queued as construction-in-progress and burns FCF now.
        $scheduledCapex = $liabilityElapsed > 0 ? $expectedRevenue * 4.0 * self::GRID_HARDENING_CAPEX_RATIO : 0.0;

        // --- Regulatory Lag & Input Costs ---
        // Fuel, purchased power and crew payroll reach the cost base at spot; fuel adjustment clauses recover
        // most of it, but only after the true-up lag. The gap between the two is the regulatory-lag squeeze.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        // --- Merchant Spark Spread Crush ---
        // Unregulated merchant power relies on the "spark spread" (wholesale electricity price minus fuel input cost).
        // If the energy index spikes violently, the spark spread collapses.
        $energyShift = max(0.0, $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION);
        $sparkSpreadCrush = $energyShift * 0.20 * $unregulatedWeight;

        // --- Margin Aggregation ---
        // Apply all structurally driven operating cost penalties to the baseline margin. The rate-base debt
        // load reaches earnings through DebtEngine's maturity wall (getDebtMaturityRolloverRate), below EBIT.
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag + $sparkSpreadCrush + $disasterPenalty);

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
            scheduledCapex: $scheduledCapex,
        );
    }

    public function getMarginReversionSpeed(): float
    {
        return self::REGULATED_REVERSION_SPEED;
    }

    /**
     * Bond proxy: regulated utilities issue 10 to 30 year first mortgage bonds, so the fixed-rate book
     * reprices very slowly. A rate shock reaches interest expense over a decade rather than a year.
     */
    public function getDebtMaturityRolloverRate(): float
    {
        return self::DEBT_MATURITY_ROLLOVER_RATE;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Regulated utilities trade primarily on Dividend Discount Model yields and Rate Base (Book Value).
        if ($dividendSupportValue > 0.0) {
            return ($dividendSupportValue * 0.50) + ($pbFairValue * 0.35) + ($earningsValue * 0.15);
        }
        return ($pbFairValue * 0.70) + ($earningsValue * 0.30);
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
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
