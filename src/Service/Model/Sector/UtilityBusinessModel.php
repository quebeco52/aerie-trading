<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\ModelParameters;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for Pure-Play Regulated Utilities (Water, Electric, Gas).
 *
 * Financial Physics:
 * - Legal Monopolies: "Rate Base" regulation guarantees ROIC, but caps upside.
 * - Weather-Driven Volume: Demand is highly inelastic to the economy but highly elastic to severe weather
 *   (heatwaves/freezes).
 * - Regulatory Lag: Authorized rate hikes lag behind inflation, causing temporary margin compression during high CPI
 *   regimes.
 * - The Bond Proxy: Massive debt loads make them highly vulnerable to rising 10Y Treasury yields (refinancing
 *   friction).
 * - Tail Risk: Aging infrastructure and climate events lead to catastrophic liability shocks (wildfires, grid
 *   failures).
 */
class UtilityBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Essential service
     * under regulated monopoly: no peer to take share from.
     */
    public const OPERATING_CYCLICALITY = 0.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.10;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.00;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::UTILITY;
    /**
     * Fuel adjustment clauses and rate cases eventually recover costs in full: unit elasticity, but only after the
     * regulatory lag.
     */
    public const PRICING_ELASTICITY = 1.00;
    /** Rate cases take 12 to 24 months: authorized tariffs follow costs with a long lag. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.50;
    /**
     * Fuel and purchased-power costs are recoverable under fuel adjustment clauses, so pricing power on the basket is
     * high.
     */
    public const PRICING_POWER_INDEX = 0.90;
    /** Fuel adjustment clauses true up quarterly to annually. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.75;

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Rate-base assets are
     * owned; leases are immaterial.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.03;

    // --- Reporting Incentives ---
    /**
     * Propensity to steer reported earnings toward consensus with accruals. Cost-of-service regulation puts the books
     * in front of a rate regulator every cycle, and the allowed return caps what a managed beat is even worth.
     */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.30;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Seasonality (calendar-quarter revenue over its annual mean, ratio to a centred 4-quarter moving average) ---
    /**
     * Calendar-quarter seasonality of an electric utility, summing to 4.0: US retail electricity revenue, all sectors,
     * 2015-2019 (EIA-861M, the data behind Electric Power Monthly Table 5.3); summer cooling load at summer tariffs, Q3
     * +18%.
     */
    public const SEASONALITY_FACTORS = [0.953, 0.938, 1.183, 0.926];
    /**
     * Water: American Water Works operating revenues, 2015-2019 (10-K/10-Q via SEC XBRL); outdoor irrigation lifts
     * Q2-Q3 with no winter heating load, Q3 +12%, Q1 -9%.
     */
    public const WATER_SEASONALITY = [0.908, 0.998, 1.121, 0.973];
    /** Industries on this model whose calendar is not the electric one. */
    public const SEASONALITY_BY_INDUSTRY = ['Utilities - Regulated Water' => self::WATER_SEASONALITY];

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.15 a year). */
    public const ROIC_REVERSION_SPEED = 0.15;
    /** Excess return over the required return the moat sustains (1.5pp). */
    public const MOAT_SPREAD = 0.015;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.5). */
    public const CAPEX_CYCLICALITY = 0.5;
    /** Share of construction in progress completed into plant each quarter (12.5%). */
    public const CAPEX_COMPLETION_RATE = 0.125;
    /** Net working capital as a share of revenue (12%). */
    public const WORKING_CAPITAL_INTENSITY = 0.12;

    /** Water and electric utilities share the rate-base physics but not the weather that drives their volumes. */
    public function resolveSeasonalityFactors(Stock $stock): array
    {
        return self::SEASONALITY_BY_INDUSTRY[$stock->getIndustry() ?? ''] ?? $this->getSeasonalityFactors();
    }

    // --- Secular Demand ---
    /** Utilities value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value added). */
    public const SECULAR_SHARE_1997 = 0.0200;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0154;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    // --- Dual-Stream Utility Rate Architecture ---
    /** Baseline fraction of revenue derived from regulated rate-base monopoly tariff distribution. */
    public const REGULATED_BASE_WEIGHT       = 0.85;
    /** Baseline fraction of revenue derived from unregulated merchant power generation and wholesale grid sales. */
    public const UNREGULATED_MERCHANT_WEIGHT = 0.15;

    // --- Merchant Generation Economics (merit-order pricing) ---
    /**
     * Share of the unregulated stream sold at the wholesale power price; the rest is market-based contract work priced
     * like the regulated book.
     */
    public const MERCHANT_POWER_SHARE = 1.00;
    /**
     * Share of merchant generation whose fuel is gas bought at spot: 43.1% of U.S. utility-scale generation in 2023
     * (EIA). Nuclear, renewables, hydro and contracted coal make up the rest, whose fuel does not move with gas.
     */
    public const MERCHANT_GAS_FLEET_SHARE = 0.431;

    // --- Merchant Hedge Book ---
    /**
     * Share of the merchant fleet's expected output sold forward on the ladder: an equal-tranche three-year ladder at
     * 0.9 holds about 79% of the coming year, 49% of the next and 19% of the third, near what integrated generators
     * report (Fortum 80-90% / 60-70% / ~45%, CEZ 90% / 60% / 28%, PSEG nuclear 95-100% / 85-90%).
     */
    public const POWER_HEDGE_RATIO = 0.90;
    /** Quarterly tranches in the merchant ladder: twelve, each quarter locking one for each of the next three years. */
    public const POWER_HEDGE_LADDER_TRANCHES = 12;

    // --- Regulatory Lag & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to output gap (industrial power usage). */
    public const MACRO_DEMAND_SCALAR       = 0.15;

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for weather-driven regulated volume (heatwaves/polar vortex). */
    public const WEATHER_VARIANCE_SCALAR   = 0.04;
    /** Volatility multiplier for unregulated wholesale merchant power pricing. */
    public const MERCHANT_VARIANCE_SCALAR  = 0.15;

    // --- Tail Risk & Refinancing Physics ---
    /**
     * Z-score threshold for a catastrophic failure of the utility's own, a wildfire its lines start or a pipeline
     * explosion: about 1% a year, near the US record of roughly a dozen such liabilities among some fifty large
     * investor-owned utilities over 2005-2024 (PG&E, Edison, SDG&E, PacifiCorp, Hawaiian Electric, Xcel, Columbia Gas).
     */
    public const GRID_FAILURE_Z_SCORE       = -2.80;
    /**
     * District storm losses incurred since the last report (burden units; an average year incurs two) at which the
     * grid is damaged badly enough to open the liability and hardening regime without any failure of its own: the
     * one-in-fifty-year quarter.
     */
    public const CATASTROPHE_GRID_DAMAGE_INCURRED = 4.7;
    /** A network's exposure to district storm damage by default: an overhead power grid's, in full. */
    public const STORM_DAMAGE_EXPOSURE = 1.0;
    /** The liability and rebuild a network's own failure carries by default: an overhead power grid's, in full. */
    public const NETWORK_FAILURE_EXPOSURE = 1.0;
    /** Stream-state key: the share of the full liability regime this one carries, set at its onset. */
    public const STATE_INFRASTRUCTURE_LIABILITY_SCALE = 'state:infrastructure_liability_scale';
    /** Variable cost penalty applied to fund massive environmental liabilities or emergency grid repairs. */
    public const GRID_FAILURE_PENALTY       = 0.15;
    /** Regime key for the multi-year liability, litigation and rebuild period after a grid failure or wildfire. */
    public const REGIME_INFRASTRUCTURE_LIABILITY = 'infrastructure_liability';
    /** Quarterly probability the liability overhang is settled (~8 quarter expected duration). */
    public const INFRASTRUCTURE_LIABILITY_EXIT_HAZARD = 0.125;
    /** Ongoing quarterly claims, litigation and hardening cost while the liability regime persists. */
    public const INFRASTRUCTURE_LIABILITY_ONGOING_PENALTY = 0.04;
    /**
     * Quarterly system-hardening and rebuild CapEx as a fraction of annual revenue while the liability regime persists.
     */
    public const GRID_HARDENING_CAPEX_RATIO = 0.03;

    // --- Rate Case Cycle (regulatory lag and earnings attrition) ---
    /** Regime key for a general rate case filed with the commission and awaiting an order. */
    public const REGIME_RATE_CASE = 'rate_case';
    /**
     * Persisted state key: how far the authorized tariff lags the non-fuel cost base it is chasing, as a fraction of
     * the tariff.
     */
    public const STATE_TARIFF_SHORTFALL = 'state:tariff_shortfall';
    /**
     * Allowed return on equity with long Treasuries at zero: RRA average authorised electric ROE on the 30-year
     * Treasury, 1992Q1-2023Q2 (Bulkley, SD PUC EL23-020 Schedule 8: risk premium 8.60% - 0.562 x T30).
     */
    public const ALLOWED_ROE_INTERCEPT = 0.0860;
    /**
     * Allowed return on equity per unit of 30-year Treasury yield: 1 - 0.562 from the same regression (slope s.e.
     * 0.023, R-squared 0.82).
     */
    public const ALLOWED_ROE_YIELD_SLOPE = 0.438;
    /**
     * Persisted state key: the allowed return on equity the current tariff was set on, moved toward the market-implied
     * one at each order.
     */
    public const STATE_AUTHORISED_ROE = 'state:authorised_roe';
    /**
     * Persisted state key: the authorised return the seeded margin was built on, the level the capital charge is
     * measured against.
     */
    public const STATE_OPENING_ROE = 'state:opening_roe';

    // --- Bond Proxy Capital Structure ---
    /**
     * Quarterly share of the fixed-rate bond stock that matures and reprices (~12-year average tenor of first mortgage
     * bonds), so a rate shock reaches interest expense over a decade rather than a year.
     */
    public const DEBT_MATURITY_ROLLOVER_RATE = 0.02;

    // --- Margin Reversion ---
    public const MARGIN_REVERSION_SPEED    = 2.0;

    // --- Rate-Base CapEx & Capital Structure Rails ---
    /**
     * Interest coverage a regulated utility needs before it recapitalizes: rate-base cash flows carry thin coverage.
     */
    public const MIN_RECAP_ICR_FLOOR          = 2.5;
    /** Target leverage as a share of the debt tolerance; below it the firm is under-levered. */
    public const UNDERLEVERAGED_DEBT_RATIO    = 0.70;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Regulated Utilities are virtually immune to economic output gaps (essential service).
        // Only industrial/commercial power load fluctuates slightly with GDP.
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);
        $physics['macro_demand_shift'] = $outputGap * $beta * self::MACRO_DEMAND_SCALAR;

        // Regulatory lag: authorised tariffs lag cumulative input cost inflation. Only the tariffed book lags; the
        // merchant book sells at the market, so the firm-wide price carries the shortfall on its tariffed share.
        $physics['pricing_power_multiplier'] = $physics['input_cost_multiplier'] * $this->resolveTariffBookLevel($stock);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveRevenueMixParameters($stock);

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
        // Merchant generation: wind and solar resource, availability
        $unregulatedZ = $streams->generateZ('unregulated_merchant', 0.20);
        $eventZ       = $streams->generateExogenousZ('event', 0.10); // Infrastructure tail risks

        // --- Clamped Revenue Streams --- Weather deviations drive regulated volume. High Z = Heatwaves/Freezes (high
        // load). Low Z = Mild weather (low load). Merchant generation is sold at the wholesale price the market clears,
        // gas at the margin in most hours.
        $regulatedVolumeRevenue = max(0.0, $expectedRevenue * $regulatedWeight * (1.0 + ($weatherZ * ($baselineVol * self::WEATHER_VARIANCE_SCALAR))));
        $merchantVolume = max(0.0, 1.0 + ($unregulatedZ * ($baselineVol * self::MERCHANT_VARIANCE_SCALAR)));
        $merchantVolumeRevenue = $expectedRevenue * $unregulatedWeight * $merchantVolume;
        [$powerPrice, $gasPrice] = $this->resolveMerchantPrices($streams, $params, $unregulatedWeight, $merchantVolume, $macroState);
        $merchantPriceRelative = FirmEconomics::merchantPowerPriceRelative($params[ModelParam::MerchantPowerShare], $powerPrice);
        // The engine priced the tariff lag over the whole book; it belongs to the tariffed revenue alone, so each
        // stream is handed back from the firm-wide level to its own.
        $tariffShortfall = $this->resolveTariffShortfall($stock);
        $bookLevel = max(0.01, $this->resolveTariffBookLevel($stock));
        $regulatedRevenue = $regulatedVolumeRevenue * (1.0 - $tariffShortfall) / $bookLevel;
        $unregulatedRevenue = $merchantVolumeRevenue * $merchantPriceRelative / $bookLevel;

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
        $gridFailureCharge = 0.0;

        // A storm damages the network once, in the window its losses arrive in, and as far as the network is exposed
        // to it: overhead lines take the wind, buried mains mostly the flooding of their plant. A failure of the
        // utility's own carries what its kind of network carries: a wildfire its power lines start in full, a water
        // main's collapse far less.
        $stormLosses = $this->resolveDistrictCatastropheIncurred($streams, $macroState);
        $districtStormDamage = $stormLosses !== null && $stormLosses['incurred'] >= self::CATASTROPHE_GRID_DAMAGE_INCURRED;
        $liabilityScale = $streams->getPersistedState(self::STATE_INFRASTRUCTURE_LIABILITY_SCALE, 1.0);
        if (($eventZ < self::GRID_FAILURE_Z_SCORE || $districtStormDamage) && $liabilityElapsed === 0) {
            $liabilityElapsed = $streams->startRegime(self::REGIME_INFRASTRUCTURE_LIABILITY);
            $exposure = $eventZ < self::GRID_FAILURE_Z_SCORE ? ModelParam::NetworkFailureExposure : ModelParam::StormDamageExposure;
            $liabilityScale = max(0.0, $this->resolveRevenueMixParameters($stock)[$exposure]);
            $eventType = ShockEvent::INFRASTRUCTURE_FAILURE;
            $gridFailureCharge = self::GRID_FAILURE_PENALTY * $liabilityScale;
        } elseif ($liabilityElapsed > 1) {
            $disasterPenalty = self::INFRASTRUCTURE_LIABILITY_ONGOING_PENALTY * $liabilityScale;
        }
        $streams->registerState(self::STATE_INFRASTRUCTURE_LIABILITY_SCALE, $liabilityScale);

        // Rebuilding and hardening the damaged system is mandatory rate-base CapEx for as long as the
        // liability regime runs; it is queued as construction-in-progress and burns FCF now.
        $scheduledCapex = $liabilityElapsed > 0 ? $expectedRevenue * FinancialConstants::QUARTERS_PER_YEAR * self::GRID_HARDENING_CAPEX_RATIO * $liabilityScale : 0.0;

        // Revenue at the prices the cost base was sized for, so the ratio below is per MWh.
        $volumeRevenue = $regulatedVolumeRevenue + $merchantVolumeRevenue;
        $priceRelative = $volumeRevenue > 0.0 ? $actualRevenue / $volumeRevenue : 1.0;
        $regulatedCostShare = $volumeRevenue > 0.0 ? $regulatedVolumeRevenue / $volumeRevenue : 1.0;

        // --- Regulatory Lag & Input Costs ---
        // Fuel, purchased power and crew payroll reach the regulated cost base at spot; fuel adjustment clauses
        // recover most of it, but only after the true-up lag. The gap between the two is the regulatory-lag squeeze.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin * $regulatedCostShare);

        // Rate case cycle: tariffs remain frozen between regulatory rate cases, resulting in regulatory attrition.
        $authorisedRoeChange = $this->advanceRateCase($streams, $macroState);
        // The tariff recovers a return on the equity rate base: an order that authorises a higher return adds it
        // to the regulated revenue requirement, as a share of revenue at the book's equity to revenue.
        $equityToRevenue = max(0.0, (float) $stock->getTotalEquity()) / max(1.0, $expectedRevenue / FinancialConstants::QUARTER_YEARS);
        $capitalChargeShift = $authorisedRoeChange * $equityToRevenue * $regulatedCostShare;

        // --- Merchant Fuel ---
        // The gas-fired share of the merchant fleet buys its fuel at spot and recovers it only through the power
        // price, so it earns the spark spread; the zero-fuel remainder keeps the whole price.
        $merchantFuelDrag = $volumeRevenue > 0.0
            ? $merchantVolumeRevenue * $this->resolveMerchantFuelCostChange($params[ModelParam::MerchantPowerShare], $params[ModelParam::MerchantGasFleetShare], $gasPrice, $macroState) / $volumeRevenue
            : 0.0;
        // What the carbon price's power uplift adds to earnings, per unit of it: the price on the share sold at the
        // wholesale price, less the carbon its gas-fired part pays, which the uplift carries at its pass-through.
        $streams->registerState(
            FinancialConstants::STATE_CARBON_POWER_EARNINGS_SHARE,
            $actualRevenue > 0.0
                ? $merchantVolumeRevenue * $params[ModelParam::MerchantPowerShare] * FirmEconomics::carbonUpliftEarningsPerUnit($params[ModelParam::MerchantGasFleetShare]) / $actualRevenue
                : 0.0,
        );

        // --- Margin Aggregation ---
        // Costs are per MWh: re-expressed against the realized price, they fall as a share of revenue when the
        // wholesale price rises. The rate-base debt load reaches earnings through DebtEngine's maturity wall
        // (getDebtMaturityRolloverRate), below EBIT.
        $perUnitCostRatio = $realizedVariableMargin + $inputCostDrag + $merchantFuelDrag + $disasterPenalty - $capitalChargeShift;
        $clampedMargin = $this->clampMargin(FirmEconomics::calculatePerUnitCostRatio($perUnitCostRatio, $priceRelative));

        $specialCharge = $gridFailureCharge * $volumeRevenue;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            scheduledCapex: $scheduledCapex,
            kpis: [
                'power_price_index' => $merchantPriceRelative,
            ],
            specialCharge: $specialCharge,
        );
    }

    /**
     * Share of revenue at baseline prices sold at the wholesale power price: the merchant stream's target share of the
     * mix times the share of its output sold into the market.
     */
    public function resolveWholesalePowerRevenueShare(Stock $stock): float
    {
        $params = $this->resolveRevenueMixParameters($stock);
        $merchant = max(0.0, $params[ModelParam::UnregulatedMerchantWeight]);
        $total = max(0.0, $params[ModelParam::RegulatedBaseWeight]) + $merchant;

        return $total > 0.0 ? ($merchant / $total) * max(0.0, min(1.0, $params[ModelParam::MerchantPowerShare])) : 0.0;
    }

    /** Revenue mix, merchant fleet and hedge parameters, ticker override first. */
    private function resolveRevenueMixParameters(Stock $stock): ModelParameters
    {
        return $this->resolveModelParameters($stock, [
            ModelParam::RegulatedBaseWeight->value       => self::REGULATED_BASE_WEIGHT,
            ModelParam::UnregulatedMerchantWeight->value => self::UNREGULATED_MERCHANT_WEIGHT,
            ModelParam::MerchantPowerShare->value        => self::MERCHANT_POWER_SHARE,
            ModelParam::MerchantGasFleetShare->value     => self::MERCHANT_GAS_FLEET_SHARE,
            ModelParam::PowerHedgeRatio->value           => self::POWER_HEDGE_RATIO,
            ModelParam::StormDamageExposure->value       => self::STORM_DAMAGE_EXPOSURE,
            ModelParam::NetworkFailureExposure->value    => self::NETWORK_FAILURE_EXPOSURE,
        ]);
    }

    /**
     * The power and gas prices the merchant fleet realises, relative to baseline: what it sold forward on the ladder
     * is delivered at its strike, and output above or below the hedge settles at spot, so a shortfall is power bought
     * back at today's price. The gas for the gas-fired part was bought forward alongside it. A book that sells no
     * power into the market rolls no ladder.
     *
     * @return array{0: float, 1: float} Realised power and gas prices, relative to baseline.
     */
    private function resolveMerchantPrices(StreamContext $streams, ModelParameters $params, float $merchantWeight, float $merchantVolume, MacroStateDTO $macroState): array
    {
        $powerSpot = max(0.0, $macroState->wholesalePowerPriceIndexEma) / MacroConstants::WHOLESALE_POWER_BASELINE;
        $gasSpot = max(0.0, $macroState->naturalGasPriceIndexEma) / MacroConstants::NATURAL_GAS_BASELINE;
        $hedge = max(0.0, min(1.0, $params[ModelParam::PowerHedgeRatio]));
        if ($merchantWeight * $params[ModelParam::MerchantPowerShare] <= 0.0 || $hedge <= 0.0 || $merchantVolume <= 0.0) {
            return [$powerSpot, $gasSpot];
        }

        $powerStrike = $streams->rollHedgeLadder(FinancialConstants::STATE_POWER_STRIKE_SUM_PREFIX, self::POWER_HEDGE_LADDER_TRANCHES, static fn(int $quarters): float => CommodityLogisticsSubsystem::powerForwardRelative($macroState, $quarters * FinancialConstants::QUARTER_YEARS));
        $power = (($hedge * $powerStrike) + (($merchantVolume - $hedge) * $powerSpot)) / $merchantVolume;
        if ($params[ModelParam::MerchantGasFleetShare] <= 0.0) {
            return [$power, $gasSpot];
        }

        $gasStrike = $streams->rollHedgeLadder(FinancialConstants::STATE_GAS_STRIKE_SUM_PREFIX, self::POWER_HEDGE_LADDER_TRANCHES, static fn(int $quarters): float => CommodityLogisticsSubsystem::gasForwardRelative($macroState, $quarters * FinancialConstants::QUARTER_YEARS));

        return [$power, (($hedge * $gasStrike) + (($merchantVolume - $hedge) * $gasSpot)) / $merchantVolume];
    }

    /**
     * Change in merchant fuel cost per unit of merchant revenue at baseline prices: the gas-fired share burns the
     * fleet heat rate of gas per MWh, which at baseline prices is this share of what the MWh sells for, and pays the
     * carbon price on what it burns.
     *
     * @param float $gasPrice The gas price the fleet pays, relative to baseline.
     */
    private function resolveMerchantFuelCostChange(float $powerShare, float $gasFleetShare, float $gasPrice, MacroStateDTO $macroState): float
    {
        return $powerShare * $gasFleetShare * FirmEconomics::gasFiredFuelCostChange($gasPrice, $macroState->carbonPrice);
    }

    /**
     * What the wholesale power market adds to or takes from the merchant stream's margin this quarter, per unit of
     * merchant revenue at baseline prices: the price on the MWh less the gas the gas-fired share burns to make them,
     * each at the price the ladder delivers this quarter, as the physics books them.
     */
    public function describeMerchantPowerImpact(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveRevenueMixParameters($stock);
        $streams = $this->createStreamContext($stock->getEarningsMomentumZ() ?? [], new MathUtility(), $macroState, $stock);
        [$powerPrice, $gasPrice] = $this->resolveMerchantPrices($streams, $params, $params[ModelParam::UnregulatedMerchantWeight], 1.0, $macroState);
        $powerShare = $params[ModelParam::MerchantPowerShare];

        return (FirmEconomics::merchantPowerPriceRelative($powerShare, $powerPrice) - 1.0)
            - $this->resolveMerchantFuelCostChange($powerShare, $params[ModelParam::MerchantGasFleetShare], $gasPrice, $macroState);
    }

    /** The tariffed share of revenue at baseline prices: the regulated stream's target share of the mix. */
    private function resolveRegulatedRevenueShare(Stock $stock): float
    {
        $params = $this->resolveRevenueMixParameters($stock);
        $regulated = max(0.0, $params[ModelParam::RegulatedBaseWeight]);
        $total = $regulated + max(0.0, $params[ModelParam::UnregulatedMerchantWeight]);

        return $total > 0.0 ? $regulated / $total : 1.0;
    }

    /** The firm-wide price level the tariff lag leaves: the shortfall on the tariffed share of the book. */
    private function resolveTariffBookLevel(Stock $stock): float
    {
        return 1.0 - ($this->resolveRegulatedRevenueShare($stock) * $this->resolveTariffShortfall($stock));
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
     * Advances the rate-case cycle: accumulate what the frozen tariff is not recovering, file when it is
     * material, and step the authorized tariff and its allowed return when the commission rules.
     *
     * @return float The authorised return on equity against the one the seeded margin was built on.
     */
    private function advanceRateCase(StreamContext $streams, MacroStateDTO $macroState): float
    {
        $shortfall = $streams->getPersistedState(self::STATE_TARIFF_SHORTFALL, 0.0);

        // Commissions set the allowed return off long Treasuries; the tariff carries the one the last order authorised.
        $allowedRoe = self::ALLOWED_ROE_INTERCEPT + (self::ALLOWED_ROE_YIELD_SLOPE * $macroState->yield30yEma);
        $authorisedRoe = $streams->getPersistedState(self::STATE_AUTHORISED_ROE, $allowedRoe);
        $openingRoe = $streams->getPersistedState(self::STATE_OPENING_ROE, $authorisedRoe);

        // Non-fuel costs escalate with general inflation while the tariff sits still, so the tariff falls
        // that much further behind the cost base every quarter it is frozen.
        $shortfall = max(0.0, $shortfall + max(0.0, $macroState->inflationEma * FinancialConstants::QUARTER_YEARS));

        $pendingBefore = (int) round($streams->getPersistedState(StreamContext::REGIME_STATE_PREFIX . self::REGIME_RATE_CASE, 0.0));
        $pending = $streams->evolveRegime(self::REGIME_RATE_CASE, 0.0, FirmEconomics::RATE_CASE_EXIT_HAZARD);

        if ($pendingBefore > 0 && $pending === 0) {
            // The order lands and closes part of the gap. The disallowed remainder is a cost the utility is
            // still incurring, so it stays in the books and is in the next filing's ask — what regulatory lag
            // permanently costs a utility is the revenue it went without while the case ran, not the price
            // level forever.
            $shortfall -= $shortfall * $macroState->utilityRateCaseGrantShare;
            // The order also resets the return, granting the same share of the move as of the request.
            $authorisedRoe += ($allowedRoe - $authorisedRoe) * $macroState->utilityRateCaseGrantShare;
        } elseif ($pending === 0 && $shortfall >= FirmEconomics::RATE_CASE_FILING_THRESHOLD) {
            $streams->startRegime(self::REGIME_RATE_CASE);
        }

        // A commission that would otherwise leave the utility earning below its cost of service grants
        // interim rates rather than push a monopoly with a mandatory service obligation into distress.
        $streams->registerState(self::STATE_TARIFF_SHORTFALL, min(FirmEconomics::MAX_TARIFF_SHORTFALL, $shortfall));
        $streams->registerState(self::STATE_AUTHORISED_ROE, $authorisedRoe);
        $streams->registerState(self::STATE_OPENING_ROE, $openingRoe);

        return $authorisedRoe - $openingRoe;
    }

    /**
     * The utility's tariffed revenue a year: what a point of average tariff shortfall takes from earnings before tax,
     * per point. The merchant book sells at the market and loses nothing to the lag.
     */
    public function annualUtilityTariffBase(Stock $stock): float
    {
        return max(0.0, (float) $stock->getTotalRevenue()) * $this->resolveRegulatedRevenueShare($stock);
    }

    /** How far the authorized tariff lags its cost base, read from the persisted state the physics advances. */
    private function resolveTariffShortfall(Stock $stock): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];

        return max(0.0, min(FirmEconomics::MAX_TARIFF_SHORTFALL, (float) ($momentum[self::STATE_TARIFF_SHORTFALL] ?? 0.0)));
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
            'carbon_price',
            'catastrophe_loss_index',
            'energy_base_price',
            'energy_cost_push_lag',
            'energy_price_index_ema',
            'energy_supply_ema',
            'exchange_rate_index_ema',
            'gas_oil_ratio_log',
            'global_demand_gap_ema',
            'import_price_level',
            'inflation_ema',
            'natural_gas_price_index_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
            'utility_rate_case_grant_share',
            'wholesale_power_price_index_ema',
            'yield_30y_ema',
        ];
    }
}
