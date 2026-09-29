<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\InputOutputExposures;
use App\Service\Corporate\EarningsEngine;
use App\DTO\StreamContext;
use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;

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
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::UTILITY;
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

    // --- Reporting Incentives ---
    /** Propensity to steer reported earnings toward consensus with accruals. Cost-of-service regulation puts the books in front of a rate regulator every cycle, and the allowed return caps what a managed beat is even worth. */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.30;

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

    // --- Merchant Generation Economics (merit-order pricing) ---
    /** Share of the unregulated stream sold at the wholesale power price; the rest is market-based contract work priced like the regulated book. */
    public const MERCHANT_POWER_SHARE = 1.00;
    /** Share of merchant generation whose fuel is gas bought at spot: 43.1% of U.S. utility-scale generation in 2023 (EIA). Nuclear, renewables, hydro and contracted coal make up the rest, whose fuel does not move with gas. */
    public const MERCHANT_GAS_FLEET_SHARE = 0.431;
    /** Heat rate of the merchant gas fleet (MMBtu/MWh): the U.S. gas-fired operating average, 2017-2024 (EIA Electric Power Annual, Table 8.1). */
    public const MERCHANT_GAS_HEAT_RATE = 7.74;

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
    /** District catastrophe burden (average-year units, smoothed) at which the grid is damaged badly enough to open the liability and hardening regime without any failure of the utility's own. */
    public const CATASTROPHE_GRID_DAMAGE_THRESHOLD = 4.0;
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

    // --- Rate Case Cycle (regulatory lag and earnings attrition) ---
    /** Regime key for a general rate case filed with the commission and awaiting an order. */
    public const REGIME_RATE_CASE = 'rate_case';
    /** Persisted state key: how far the authorized tariff lags the non-fuel cost base it is chasing, as a fraction of the tariff. */
    public const STATE_TARIFF_SHORTFALL = 'state:tariff_shortfall';
    /** Unrecovered non-fuel cost at which management files a general rate case rather than absorb further attrition. */
    public const RATE_CASE_FILING_THRESHOLD = 0.02;
    /** Quarterly probability a pending case is decided (~5 quarters, the 12 to 18 month regulatory lag). */
    public const RATE_CASE_EXIT_HAZARD = 0.20;
    /** Share of the request a commission grants; the disallowed remainder is still a real cost, so it rolls into the next filing. */
    public const RATE_CASE_GRANTED_SHARE = 0.70;
    /** Lag at which interim relief is granted rather than leave the utility earning below its cost of service. */
    public const MAX_TARIFF_SHORTFALL = 0.10;

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
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);
        $physics['macro_demand_shift'] = $outputGap * $beta * self::MACRO_DEMAND_SCALAR;

        // Regulatory lag: pricing power reflects authorized tariffs lagging cumulative input cost inflation.
        $physics['pricing_power_multiplier'] = $physics['input_cost_multiplier'] * (1.0 - $this->resolveTariffShortfall($stock));

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::RegulatedBaseWeight->value       => self::REGULATED_BASE_WEIGHT,
            ModelParam::UnregulatedMerchantWeight->value => self::UNREGULATED_MERCHANT_WEIGHT,
            ModelParam::MerchantPowerShare->value        => self::MERCHANT_POWER_SHARE,
            ModelParam::MerchantGasFleetShare->value     => self::MERCHANT_GAS_FLEET_SHARE,
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
        $unregulatedZ = $streams->generateZ('unregulated_merchant', 0.20); // Merchant generation: wind and solar resource, availability
        $eventZ       = $streams->generateExogenousZ('event', 0.10); // Infrastructure tail risks

        // --- Clamped Revenue Streams ---
        // Weather deviations drive regulated volume. High Z = Heatwaves/Freezes (high load). Low Z = Mild weather (low load).
        // Merchant generation is sold at the wholesale price the market clears, gas at the margin in most hours.
        $regulatedRevenue = max(0.0, $expectedRevenue * $regulatedWeight * (1.0 + ($weatherZ * ($baselineVol * self::WEATHER_VARIANCE_SCALAR))));
        $merchantVolumeRevenue = max(0.0, $expectedRevenue * $unregulatedWeight * (1.0 + ($unregulatedZ * ($baselineVol * self::MERCHANT_VARIANCE_SCALAR))));
        $merchantPriceRelative = $this->resolveMerchantPriceRelative($params[ModelParam::MerchantPowerShare], $macroState);
        $unregulatedRevenue = $merchantVolumeRevenue * $merchantPriceRelative;

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

        $districtStormDamage = $macroState->catastropheLossIndexEma >= self::CATASTROPHE_GRID_DAMAGE_THRESHOLD;
        if (($eventZ < self::GRID_FAILURE_Z_SCORE || $districtStormDamage) && $liabilityElapsed === 0) {
            $liabilityElapsed = $streams->startRegime(self::REGIME_INFRASTRUCTURE_LIABILITY);
            $eventType = ShockEvent::INFRASTRUCTURE_FAILURE;
            $disasterPenalty = self::GRID_FAILURE_PENALTY;
        } elseif ($liabilityElapsed > 1) {
            $disasterPenalty = self::INFRASTRUCTURE_LIABILITY_ONGOING_PENALTY;
        }

        // Rebuilding and hardening the damaged system is mandatory rate-base CapEx for as long as the
        // liability regime runs; it is queued as construction-in-progress and burns FCF now.
        $scheduledCapex = $liabilityElapsed > 0 ? $expectedRevenue * 4.0 * self::GRID_HARDENING_CAPEX_RATIO : 0.0;

        // Revenue at the prices the cost base was sized for, so the ratio below is per MWh.
        $volumeRevenue = $regulatedRevenue + $merchantVolumeRevenue;
        $priceRelative = $volumeRevenue > 0.0 ? $actualRevenue / $volumeRevenue : 1.0;
        $regulatedCostShare = $volumeRevenue > 0.0 ? $regulatedRevenue / $volumeRevenue : 1.0;

        // --- Regulatory Lag & Input Costs ---
        // Fuel, purchased power and crew payroll reach the regulated cost base at spot; fuel adjustment clauses
        // recover most of it, but only after the true-up lag. The gap between the two is the regulatory-lag squeeze.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin * $regulatedCostShare);

        // Rate case cycle: tariffs remain frozen between regulatory rate cases, resulting in regulatory attrition.
        $this->advanceRateCase($streams, $macroState);

        // --- Merchant Fuel ---
        // The gas-fired share of the merchant fleet buys its fuel at spot and recovers it only through the power
        // price, so it earns the spark spread; the zero-fuel remainder keeps the whole price.
        $merchantFuelDrag = $volumeRevenue > 0.0
            ? $merchantVolumeRevenue * $this->resolveMerchantFuelCostChange($params[ModelParam::MerchantPowerShare], $params[ModelParam::MerchantGasFleetShare], $macroState) / $volumeRevenue
            : 0.0;

        // --- Margin Aggregation ---
        // Costs are per MWh: re-expressed against the realized price, they fall as a share of revenue when the
        // wholesale price rises. The rate-base debt load reaches earnings through DebtEngine's maturity wall
        // (getDebtMaturityRolloverRate), below EBIT.
        $perUnitCostRatio = $realizedVariableMargin + $inputCostDrag + $merchantFuelDrag + $disasterPenalty;
        $clampedMargin = $this->clampMargin(MathUtility::getInstance()->calculatePerUnitCostRatio($perUnitCostRatio, $priceRelative));

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$unregulatedZ, $weatherZ], $eventZ);

        // Regulated weather volume is perfectly visible via meter data, merchant generation is opaque, and the
        // wholesale price is published daily. Dividing the price leg by the base visibility lets a persistent
        // price level converge to an unbiased consensus.
        $observableShockZ = ($weatherZ * $regulatedWeight * self::WEATHER_VARIANCE_SCALAR) +
            ($unregulatedZ * $unregulatedWeight * self::MERCHANT_VARIANCE_SCALAR * 0.2);
        $observableShockZ *= $baselineVol;
        $observableShockZ += ($priceRelative - 1.0) / self::BASE_COVERAGE_VISIBILITY;

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
            kpis: [
                'power_price_index' => $merchantPriceRelative,
            ],
        );
    }

    /**
     * Price of the unregulated stream relative to the one its cost base was sized at: the wholesale power index on
     * the share sold into the market, flat on market-based contract work.
     */
    private function resolveMerchantPriceRelative(float $powerShare, MacroStateDTO $macroState): float
    {
        $powerRelative = max(0.0, $macroState->wholesalePowerPriceIndexEma) / MacroEngine::WHOLESALE_POWER_BASELINE;

        return (1.0 - $powerShare) + ($powerShare * $powerRelative);
    }

    /**
     * Change in merchant fuel cost per unit of merchant revenue at baseline prices: the gas-fired share burns the
     * fleet heat rate of gas per MWh, which at baseline prices is this share of what the MWh sells for.
     */
    private function resolveMerchantFuelCostChange(float $powerShare, float $gasFleetShare, MacroStateDTO $macroState): float
    {
        $baselineFuelShare = self::MERCHANT_GAS_HEAT_RATE * CommodityLogisticsSubsystem::REFERENCE_GAS_PRICE / CommodityLogisticsSubsystem::REFERENCE_POWER_PRICE;
        $gasRelative = max(0.0, $macroState->naturalGasPriceIndexEma) / MacroEngine::NATURAL_GAS_BASELINE;

        return $powerShare * $gasFleetShare * $baselineFuelShare * ($gasRelative - 1.0);
    }

    /**
     * What the wholesale power market adds to or takes from the merchant stream's margin this quarter, per unit of
     * merchant revenue at baseline prices: the price on the MWh less the gas the gas-fired share burns to make them.
     */
    public function describeMerchantPowerImpact(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::MerchantPowerShare->value    => self::MERCHANT_POWER_SHARE,
            ModelParam::MerchantGasFleetShare->value => self::MERCHANT_GAS_FLEET_SHARE,
        ]);
        $powerShare = $params[ModelParam::MerchantPowerShare];

        return ($this->resolveMerchantPriceRelative($powerShare, $macroState) - 1.0)
            - $this->resolveMerchantFuelCostChange($powerShare, $params[ModelParam::MerchantGasFleetShare], $macroState);
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
    /**
     * Advances the rate-case cycle: accumulate what the frozen tariff is not recovering, file when it is
     * material, and step the authorized tariff when the commission rules.
     */
    private function advanceRateCase(StreamContext $streams, MacroStateDTO $macroState): void
    {
        $shortfall = $streams->getPersistedState(self::STATE_TARIFF_SHORTFALL, 0.0);

        // Non-fuel costs escalate with general inflation while the tariff sits still, so the tariff falls
        // that much further behind the cost base every quarter it is frozen.
        $shortfall = max(0.0, $shortfall + max(0.0, $macroState->inflationEma * EarningsEngine::QUARTERLY_TIME_STEP));

        $pendingBefore = (int) round($streams->getPersistedState(StreamContext::REGIME_STATE_PREFIX . self::REGIME_RATE_CASE, 0.0));
        $pending = $streams->evolveRegime(self::REGIME_RATE_CASE, 0.0, self::RATE_CASE_EXIT_HAZARD);

        if ($pendingBefore > 0 && $pending === 0) {
            // The order lands and closes part of the gap. The disallowed remainder is a cost the utility is
            // still incurring, so it stays in the books and is in the next filing's ask — what regulatory lag
            // permanently costs a utility is the revenue it went without while the case ran, not the price
            // level forever. Clearing the whole request on every order instead let the tariff catch its cost
            // base completely, which is the one thing a lagging regulated tariff never does.
            $shortfall -= $shortfall * self::RATE_CASE_GRANTED_SHARE;
        } elseif ($pending === 0 && $shortfall >= self::RATE_CASE_FILING_THRESHOLD) {
            $streams->startRegime(self::REGIME_RATE_CASE);
        }

        // A commission that would otherwise leave the utility earning below its cost of service grants
        // interim rates rather than push a monopoly with a mandatory service obligation into distress.
        $streams->registerState(self::STATE_TARIFF_SHORTFALL, min(self::MAX_TARIFF_SHORTFALL, $shortfall));
    }

    /** How far the authorized tariff lags its cost base, read from the persisted state the physics advances. */
    private function resolveTariffShortfall(Stock $stock): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];

        return max(0.0, min(self::MAX_TARIFF_SHORTFALL, (float) ($momentum[self::STATE_TARIFF_SHORTFALL] ?? 0.0)));
    }

    public function getOperatingMacroFields(): array
    {
        return [
            'catastrophe_loss_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'inflation_ema',
            'natural_gas_price_index_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
            'wholesale_power_price_index_ema',
        ];
    }
}
