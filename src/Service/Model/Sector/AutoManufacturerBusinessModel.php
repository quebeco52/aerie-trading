<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Automobile Manufacturers & Vertically Integrated Mobility Syndicates.
 *
 * Financial Physics:
 * - Operating Leverage & Predatory Fleet Pricing: Mass-market commuter fleets are priced aggressively low
 *   as a Trojan horse, bearing heavy fixed-cost operating leverage and high sensitivity to policy interest rates.
 * - Tri-Stream Mobility Architecture:
 *   1. Mass-Market Fleet Sales: High-volume commuter vehicle manufacturing (interest-rate sensitive, consumer
 *      sentiment driven, exposed to UAW labor strikes and supply chain inflation).
 *   2. Apex Division Luxury Hypercars: Hyper-exclusive Veblen hypercars sold to corporate oligarchs and the elite
 *      at astronomical profit margins, cross-subsidizing the mass-market fleet.
 *   3. Connected Telematics: Sticky recurring software tolls and telemetry subscriptions on the fleet on the road.
 *   4. Captive Finance: interest on the dealer and retail loan book, subject to CECL credit provisioning and Net
 *      Interest Margin curve spreads.
 */
class AutoManufacturerBusinessModel extends HeavyManufacturingBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Big-ticket,
     * credit-financed purchases with model-to-model substitution.
     */
    public const OPERATING_CYCLICALITY = 1.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.20;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.70;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Vehicles are the archetypal
     * traded good: half the book is exported or meets a landed import on the same forecourt.
     */
    public const FX_REVENUE_EXPOSURE = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::AUTO_MANUFACTURER;
    /** Tier-one supplier contracts fix component prices for about a quarter before spot moves reach the line. */
    public const INPUT_COST_LAG_YEARS = 0.25;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: spring selling season and model-year
     * launches; weak Q1.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 1.03, 1.00, 1.02];
    }

    // --- Capital Cycle & Secular Demand ---
    // Carmakers keep heavy machinery's capital cycle and secular drift, declared here so retuning one leaves the other.

    /** Working capital per unit of revenue: dealer lots and parts inventory (0.15, against the standard 0.10). */
    public const WORKING_CAPITAL_INTENSITY = 0.15;

    /** Share of construction in progress placed in service each quarter: plants and retooling take years (0.125). */
    public const CAPEX_COMPLETION_RATE = 0.125;

    /** How hard capex swings with the cycle, against the standard 1.5: model programmes are cut deep in a downturn. */
    public const CAPEX_CYCLICALITY = 4.0;

    /**
     * Business investment in industrial equipment as a share of US nominal GDP in 1997 (BEA NIPA Table 5.3.5), the
     * same series heavy machinery drifts on. Its fall to 2019 puts carmakers ~1.3%/yr below trend growth.
     */
    public const SECULAR_SHARE_1997 = 0.0164;

    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0122;

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return self::WORKING_CAPITAL_INTENSITY;
    }

    public function getCapExCompletionRate(Stock $stock): float
    {
        return self::CAPEX_COMPLETION_RATE;
    }

    public function getCapexCyclicality(): float
    {
        return self::CAPEX_CYCLICALITY;
    }

    /** Trend real growth plus the measured drift in the share of GDP above. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for automakers via monthly dealership channel registration data. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;

    /** Base coverage forecasting error given consumer financing and luxury shipment lumpiness. */
    public const BASE_COVERAGE_ERROR = 0.07;

    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.20;

    // --- Analyst Coverage ---
    // Recalls and strikes make the national news.
    /** Visibility of a quarter with an announced event (80%). */
    public const EVENT_BASE_VISIBILITY = 0.80;
    /** Floor on visibility in an announced-event quarter (40%). */
    public const EVENT_MIN_VISIBILITY = 0.40;

    // --- Tri-Stream Automotive Architecture ---
    /** Baseline fraction of revenue derived from mass-market consumer vehicle manufacturing. */
    public const AUTO_SALES_WEIGHT = 0.60;

    /**
     * Baseline fraction of revenue derived from hyper-exclusive Apex luxury hypercars (Veblen margin cross-subsidy).
     */
    public const APEX_LUXURY_WEIGHT = 0.20;

    /** Baseline fraction of revenue derived from connected telematics and software tolls. */
    public const SOFTWARE_SERVICES_WEIGHT = 0.10;

    /** Baseline fraction of revenue the captive finance arm earns as interest on its dealer and retail loan book. */
    public const CAPTIVE_FINANCE_WEIGHT = 0.10;

    /** Baseline pricing power across OEM vehicle model lines. */
    public const PRICING_POWER_INDEX = 0.65;

    // --- Global Supply Chain Pressure (GSCPI) & Capacity Utilization ---
    /** Variable margin cost per standard deviation of supply-chain pressure about normal, both ways (1.9pp). */
    public const SUPPLY_CHAIN_PRESSURE_COST_SCALAR = 0.019;
    /** Share of the expediting cost a maker recovers in its prices per unit of pricing power: half at full power. */
    public const SUPPLY_CHAIN_COST_RECOVERY_SHARE = 0.50;
    /**
     * Smallest mass-market share the input-cost drag is spread over, so a luxury-only maker never divides by nothing.
     */
    public const MIN_INPUT_COST_ALLOCATION_WEIGHT = 0.05;
    /** Sensitivity of mass-market manufacturing throughput to aggregate industrial capacity utilization (Fed G.17). */
    public const CAPACITY_UTILIZATION_THROUGHPUT_SCALAR = 0.40;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for mass-market fleet volume. */
    public const SALES_VARIANCE_SCALAR = 0.30;

    /** Volatility multiplier for ultra-luxury hypercar deliveries. */
    public const APEX_VARIANCE_SCALAR = 0.40;

    /** Volatility multiplier for software subscription recurring tolls. */
    public const SOFTWARE_VARIANCE_SCALAR = 0.12;

    /** Volatility multiplier for the interest a seasoned loan book earns. */
    public const CAPTIVE_FINANCE_VARIANCE_SCALAR = 0.10;

    // --- Cycle Loadings by Stream ---
    /**
     * Telematics subscriptions follow the fleet on the road, a stock about a dozen years of sales deep: 0.20 of the
     * purchase swing.
     */
    public const SOFTWARE_CYCLE_LOADING = 0.20;
    /** The loan book is about three years of originations, so it carries about a third of the purchase swing. */
    public const CAPTIVE_FINANCE_CYCLE_LOADING = 0.30;
    /**
     * The luxury line's share of the purchase swing: supply held below demand leaves an order book that absorbs an
     * ordinary slowdown (Ferrari shipped -5% in 2009 while sports cars fell 35%); a financial crisis reaches it through
     * the wealth term instead.
     */
    public const APEX_CYCLE_LOADING = 0.30;

    // --- Financing Arm & Credit Physics ---
    /**
     * Through-the-cycle retail and lease losses as a share of the book's interest income, which move in proportion to
     * the household default rate: ~0.75% of receivables on a yield of about 7.5% (Ford Credit US retail and lease
     * losses 0.78% to 1.52% from Q3 2007 to Q3 2008, about +10pp of interest income).
     */
    public const CAPTIVE_RETAIL_LOSS_SHARE = 0.10;

    /**
     * Dealer floor-plan provisions per unit of the corporate default rate over its measured trend, relative, both ways.
     */
    public const CORPORATE_DEFAULT_PROVISION_SCALAR = 0.02;

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach the assembly line: dealer stock runs about two to three months of
     * sales, so build schedules follow showroom demand within a quarter.
     */
    public const DEMAND_LAG_YEARS = 0.25;

    // --- Durable Demand, Interest Rate & Sentiment Sensitivity ---
    /**
     * Vehicle purchases per unit of the gap beyond cyclicality: purchases are the flow that adjusts a durable stock, so
     * they swing a multiple of income (Chow 1957 stock adjustment); ~3.75x the gap at cyclicality 1.5.
     */
    public const DURABLE_STOCK_ADJUSTMENT_MULTIPLIER = 2.50;

    /** Scalar for demand destruction per 100bps of real policy rate above r*. */
    public const RATE_SENSITIVITY_SCALAR = 1.25;

    /** Ceiling on the volume an easy real-rate stance can add (10%). */
    public const MAX_RATE_EASING_BOOST = 0.10;

    /**
     * Vehicle volume per unit of confidence beyond what the output gap explains, before cyclicality (Lemmon &
     * Portniaguina 2006 residual).
     */
    public const CONSUMER_SENTIMENT_SENSITIVITY = 0.40;

    // --- Apex Luxury & Veblen Wealth Effect ---
    /** Hypercar deliveries per unit of equity wealth over its trend, both ways: 20% above trend adds 8%. */
    public const APEX_EQUITY_WEALTH_ELASTICITY = 0.40;

    // --- Structural Gross Margin Cost Intensities for Cross-Subsidization ---
    /** Relative variable cost intensity of mass-market commuter fleet (low margin / predatory baseline). */
    public const MASS_MARKET_COST_INTENSITY = 1.15;

    /**
     * Relative variable cost intensity of Apex Division ultra-luxury hypercars (high gross profit margin
     * cross-subsidy).
     */
    public const APEX_LUXURY_COST_INTENSITY = 0.65;

    /** Relative variable cost intensity of connected telematics and software services (pure digital tollbooth). */
    public const SOFTWARE_SERVICES_COST_INTENSITY = 0.30;

    /**
     * Relative variable cost intensity of the captive finance arm (funding and servicing), the rate the combined stream
     * carried.
     */
    public const CAPTIVE_FINANCE_COST_INTENSITY = 0.30;

    // --- Tail Risk & Event Physics ---
    /** Negative Z-score threshold indicating a massive vehicle safety recall and litigation liability. */
    public const MASSIVE_RECALL_Z_SCORE = -2.20;

    /** Variable margin penalty applied during safety recalls to cover warranty replacements and legal damages. */
    public const MASSIVE_RECALL_PENALTY = 0.05;

    /** Negative Z-score threshold indicating an auto-worker union labor strike. */
    public const UAW_STRIKE_Z_SCORE = -2.50;

    /** Revenue throughput multiplier applied during factory assembly shutdowns from labor strikes. */
    public const UAW_STRIKE_MULT = 0.80;

    public function getReversionSpeed(): float
    {
        return 0.08;
    }
    public function getMoatSpread(): float
    {
        return 0.015;
    }

    /**
     * A vehicle is sold from stock, not built to an order book: the purchase swing lands on expected revenue, which the
     * physics allocates across the streams.
     */
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift' => $this->resolveCycleDemandShift($stock, $macroState),
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    /** The purchase swing is in macro_demand_shift, so the engine's utilisation already carries it. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return 0.0;
    }

    /** Vehicle purchases: the durable stock-adjustment response to the gap, the real rate stance and confidence. */
    protected function resolveCycleDemandShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::RateSensitivityScalar->value => self::RATE_SENSITIVITY_SCALAR,
        ]);
        $rateScalar = $params[ModelParam::RateSensitivityScalar->value];
        $beta = $this->getOperatingCyclicality($stock);

        // A vehicle is a durable: the gap reaches purchases once, through the stock-adjustment multiplier.
        $shift = ($this->resolveLaggedOutputGap($macroState) * $beta * self::DURABLE_STOCK_ADJUSTMENT_MULTIPLIER)
            + $this->resolveFxDemandShift($macroState);

        // Debt-financed purchases read the real policy stance, nominal policy less expected inflation less r*
        // (Laubach & Williams 2003); easing gives back less than tightening takes.
        $realRateGap = $macroState->policyRateEma - $macroState->tipsBreakevenEma - $macroState->naturalRateEma;
        $ratePenalty = $realRateGap > 0.0
            ? $realRateGap * $beta * $rateScalar
            : max(-self::MAX_RATE_EASING_BOOST, $realRateGap * $beta * $rateScalar * FinancialConstants::RATE_EASING_SLOPE_SHARE);

        // The gap is priced above, so confidence enters as its residual over the gap.
        return $shift - $ratePenalty + ($macroState->sentimentResidual() * $beta * self::CONSUMER_SENTIMENT_SENSITIVITY);
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility,
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::AutoSalesWeight->value        => self::AUTO_SALES_WEIGHT,
            ModelParam::ApexLuxuryWeight->value       => self::APEX_LUXURY_WEIGHT,
            ModelParam::SoftwareServicesWeight->value => self::SOFTWARE_SERVICES_WEIGHT,
            ModelParam::CaptiveFinanceWeight->value   => self::CAPTIVE_FINANCE_WEIGHT,
        ]);

        $pricingPower = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $targetWeights = [
            'mass_market_sales'   => $params[ModelParam::AutoSalesWeight],
            'apex_luxury'         => $params[ModelParam::ApexLuxuryWeight],
            'software_telematics' => $params[ModelParam::SoftwareServicesWeight],
        ];
        if ($params[ModelParam::CaptiveFinanceWeight] > 0.0) {
            $targetWeights['captive_finance'] = $params[ModelParam::CaptiveFinanceWeight];
        }
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $salesWeight    = $activeWeights['mass_market_sales'];
        $apexWeight     = $activeWeights['apex_luxury'];
        $softwareWeight = $activeWeights['software_telematics'];
        $captiveWeight  = $activeWeights['captive_finance'] ?? 0.0;

        // Independent stream Z-scores with persistent AR(1) momentum
        $salesZ    = $streams->generateZ('mass_market_sales', 0.25);
        $apexZ     = $streams->generateZ('apex_luxury', 0.35);
        $softwareZ = $streams->generateZ('software_telematics', 0.40);
        $eventZ    = $streams->generateExogenousZ('event', 0.10);
        $captiveZ  = $captiveWeight > 0.0 ? $streams->generateZ('captive_finance', 0.40) : 0.0;

        // --- Tail Risk & Labor Events ---
        $eventType = null;
        $salesMultiplier = 1.0;
        $recallPenalty = 0.0;

        if ($eventZ < self::UAW_STRIKE_Z_SCORE) {
            $eventType = ShockEvent::LABOR_STRIKE;
            $salesMultiplier = self::UAW_STRIKE_MULT;
        } elseif ($eventZ < self::MASSIVE_RECALL_Z_SCORE) {
            $eventType = ShockEvent::PRODUCT_RECALL;
            $recallPenalty = self::MASSIVE_RECALL_PENALTY;
        }

        // --- Apex Luxury Veblen & Asset Wealth Physics ---
        // Hypercar demand decouples from mass-market GDP: it follows equity wealth against its trend, both ways
        // (Ait-Sahalia, Parker & Yogo 2004), which already carries what QE does to asset prices.
        $apexMacroBoost = $macroState->equityWealthDeviation() * self::APEX_EQUITY_WEALTH_ELASTICITY;

        // Input cost basket drag on manufacturing recovered at pricing power, allocated to mass-market volume.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
        $gscpiShift = ($macroState->supplyChainPressureIndexEma - MacroConstants::GSCPI_BASELINE);
        $inflationCostPenalty = ($inputCostDrag / max(self::MIN_INPUT_COST_ALLOCATION_WEIGHT, $salesWeight)) + ($gscpiShift * self::SUPPLY_CHAIN_PRESSURE_COST_SCALAR * (1.0 - ($pricingPower * self::SUPPLY_CHAIN_COST_RECOVERY_SHARE)));

        // Captive credit losses, charged to the loan book that earns the interest: the losses over the
        // through-the-cycle rate the cost base carries, both ways, so a calm credit year releases what a bad one
        // builds. A book lent at the population's default risk loses in proportion to its default rate; a match-funded
        // captive runs no curve gap, and the default rates already carry what spreads and debt service forecast.
        $creditLossShift = ($macroState->retailDefaultDeviation() * self::CAPTIVE_RETAIL_LOSS_SHARE)
            + ($macroState->corporateDefaultDeviation() * self::CORPORATE_DEFAULT_PROVISION_SCALAR);

        // --- Tri-Stream Revenue Calculation ---
        $salesShock    = $salesZ    * ($baselineVol * self::SALES_VARIANCE_SCALAR);
        $apexShock     = $apexZ     * ($baselineVol * self::APEX_VARIANCE_SCALAR);
        $softwareShock = $softwareZ * ($baselineVol * self::SOFTWARE_VARIANCE_SCALAR);
        $captiveShock  = $captiveZ  * ($baselineVol * self::CAPTIVE_FINANCE_VARIANCE_SCALAR);
        $cuShift = MacroTransmission::calculateCapacityUtilizationShift($macroState->capacityUtilizationRateEma, MacroConstants::CU_BASELINE, self::CAPACITY_UTILIZATION_THROUGHPUT_SCALAR);

        // The firm-wide purchase swing lands on all of expected revenue; vehicle sales carry it, while the fleet's
        // subscriptions and the loan book, stocks built from years of sales, carry their loading of it.
        $cycleShares = MacroTransmission::allocateDemandShift(
            ['mass_market_sales' => $salesWeight, 'apex_luxury' => $apexWeight, 'software_telematics' => $softwareWeight, 'captive_finance' => $captiveWeight],
            ['mass_market_sales' => 1.0, 'apex_luxury' => self::APEX_CYCLE_LOADING, 'software_telematics' => self::SOFTWARE_CYCLE_LOADING, 'captive_finance' => self::CAPTIVE_FINANCE_CYCLE_LOADING],
            $this->resolveCycleDemandShift($stock, $macroState),
        );

        $salesRevenue    = max(0.0, $expectedRevenue * $salesWeight    * (1.0 + $salesShock + $cuShift + $cycleShares['mass_market_sales']) * $salesMultiplier);
        $apexRevenue     = max(0.0, $expectedRevenue * $apexWeight     * (1.0 + $apexShock + $apexMacroBoost + $cycleShares['apex_luxury']));
        $softwareRevenue = max(0.0, $expectedRevenue * $softwareWeight * (1.0 + $softwareShock + $cycleShares['software_telematics']));
        $captiveRevenue  = max(0.0, $expectedRevenue * $captiveWeight  * (1.0 + $captiveShock + $cycleShares['captive_finance']));

        $streamRevenues = [
            'mass_market_sales'   => $salesRevenue,
            'apex_luxury'         => $apexRevenue,
            'software_telematics' => $softwareRevenue,
        ];
        if ($captiveWeight > 0.0) {
            $streamRevenues['captive_finance'] = $captiveRevenue;
        }

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cross-Subsidization Variable Cost Architecture ---
        [
            'mass_market_sales' => $salesBaseMargin,
            'apex_luxury' => $apexBaseMargin,
            'software_telematics' => $softwareBaseMargin,
            'captive_finance' => $captiveBaseMargin,
        ] = FirmEconomics::streamCostRatios(
            $realizedVariableMargin,
            ['mass_market_sales' => $salesWeight, 'apex_luxury' => $apexWeight, 'software_telematics' => $softwareWeight, 'captive_finance' => $captiveWeight],
            [
                'mass_market_sales' => self::MASS_MARKET_COST_INTENSITY,
                'apex_luxury' => self::APEX_LUXURY_COST_INTENSITY,
                'software_telematics' => self::SOFTWARE_SERVICES_COST_INTENSITY,
                'captive_finance' => self::CAPTIVE_FINANCE_COST_INTENSITY,
            ],
        );

        $salesCosts    = $salesRevenue    * ($salesBaseMargin + $inflationCostPenalty);
        $apexCosts     = $apexRevenue * $apexBaseMargin;
        $softwareCosts = $softwareRevenue * $softwareBaseMargin;
        $captiveCosts  = $captiveRevenue * ($captiveBaseMargin + $creditLossShift);

        $totalVariableCosts = $salesCosts + $apexCosts + $softwareCosts + $captiveCosts;
        // The blended ratio is struck on volume revenue so the template method reproduces these dollar costs exactly.
        $volumeRevenue = max(1.0, $actualRevenue);
        $effectiveMargin = $actualRevenue > 0 ? ($totalVariableCosts / $volumeRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin);

        // The one-off charge is booked as a special item: in EBIT, out of the run rate, the Street figure and the cost
        // ratio.
        $specialCharge = $recallPenalty * $salesRevenue;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            specialCharge: $specialCharge,
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
            'capacity_utilization_rate_ema',
            'consumer_sentiment_index_ema',
            'corporate_default_rate_ema',
            'equity_wealth_ratio',
            'equity_wealth_trend',
            'exchange_rate_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'natural_rate_ema',
            'output_gap_lag_3m',
            'policy_rate_ema',
            'retail_default_rate_ema',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
