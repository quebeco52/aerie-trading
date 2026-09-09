<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

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
 *   3. Connected Telematics & Captive Finance: Sticky recurring software tolls, telemetry subscriptions,
 *      and captive automotive financing (subject to CECL credit provisioning and Net Interest Margin curve spreads).
 */
class AutoManufacturerBusinessModel extends HeavyManufacturingBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Big-ticket, credit-financed purchases with model-to-model substitution. */
    public const OPERATING_CYCLICALITY = 1.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.20;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.70;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Vehicles are the archetypal traded good: half the book is exported or meets a landed import on the same forecourt. */
    public const FX_REVENUE_EXPOSURE = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.05, 'metals' => 0.15, 'freight' => 0.05, 'ppi' => 0.35, 'labor' => 0.20];
    /** Tier-one supplier contracts fix component prices for about a quarter before spot moves reach the line. */
    public const INPUT_COST_LAG_YEARS = 0.25;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: spring selling season and model-year launches; weak Q1.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 1.03, 1.00, 1.02];
    }

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for automakers via monthly dealership channel registration data. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;

    /** Base coverage forecasting error given consumer financing and luxury shipment lumpiness. */
    public const BASE_COVERAGE_ERROR = 0.07;

    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.20;

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        return new SectorCoverageProfile(
            baseVisibility: self::BASE_COVERAGE_VISIBILITY,
            errorStdDev: self::BASE_COVERAGE_ERROR,
            minVisibility: self::BASE_COVERAGE_MIN_VISIBILITY,
            eventBaseVisibility: 0.80,
            eventMinVisibility: 0.40
        );
    }

    // --- Tri-Stream Automotive Architecture ---
    /** Baseline fraction of revenue derived from mass-market consumer vehicle manufacturing. */
    public const AUTO_SALES_WEIGHT = 0.60;

    /** Baseline fraction of revenue derived from hyper-exclusive Apex luxury hypercars (Veblen margin cross-subsidy). */
    public const APEX_LUXURY_WEIGHT = 0.20;

    /** Baseline fraction of revenue derived from captive financing and connected telematics/software tolls. */
    public const SOFTWARE_SERVICES_WEIGHT = 0.20;

    /** Baseline pricing power across OEM vehicle model lines. */
    public const PRICING_POWER_INDEX = 0.65;

    // --- Global Supply Chain Pressure (GSCPI) & Capacity Utilization ---
    /** Variable margin cost drag per unit of supply chain bottleneck pressure (NY Fed GSCPI). */
    public const SUPPLY_CHAIN_PRESSURE_COST_SCALAR = 0.04;
    /** Sensitivity of mass-market manufacturing throughput to aggregate industrial capacity utilization (Fed G.17). */
    public const CAPACITY_UTILIZATION_THROUGHPUT_SCALAR = 0.40;


    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for mass-market fleet volume. */
    public const SALES_VARIANCE_SCALAR = 0.30;

    /** Volatility multiplier for ultra-luxury hypercar deliveries. */
    public const APEX_VARIANCE_SCALAR = 0.40;

    /** Volatility multiplier for captive finance and software subscription recurring tolls. */
    public const SOFTWARE_VARIANCE_SCALAR = 0.12;

    // --- Financing Arm & Credit Physics ---
    /** Break-even Net Interest Margin floor for the captive finance arm (~70bps, the neutral 2s10s slope). */
    public const NIM_SPREAD_BUFFER = 0.007;

    /** Linear sensitivity to yield curve spread. */
    public const NIM_LINEAR_SENSITIVITY = 1.00;

    /** Quadratic penalty for yield curve inversion. */
    public const NIM_QUADRATIC_COEFF = 0.15;

    /** Drag on auto loans during negative consumer sentiment regimes. */
    public const MACRO_DEFAULT_SCALAR = 0.25;

    /** Baseline credit spread above which CECL forward provisioning accelerates, the macro through-the-cycle IG spread. */
    public const CECL_BASELINE_CREDIT_SPREAD = MacroEngine::BASE_CREDIT_SPREAD;

    // --- Interest Rate & Sentiment Sensitivity ---
    /** Neutral policy rate (~3.0%). Rates above this destroy consumer auto financing demand. */
    public const NEUTRAL_POLICY_RATE = 0.03;

    /** Scalar for demand destruction per 100bps of policy rate above neutral. */
    public const RATE_SENSITIVITY_SCALAR = 1.25;

    // --- Apex Luxury & Veblen Wealth Effect ---
    /** Sensitivity of ultra-luxury hypercar deliveries to equity risk premium compression (asset wealth expansion). */
    public const APEX_ERP_COMPRESSION_SCALAR = 10.0;

    /** Top-line revenue boost multiplier during central bank Quantitative Easing liquidity surges. */
    public const APEX_QE_LIQUIDITY_BOOST = 0.15;

    /** Veblen pricing power boost scalar when inflation exceeds the central bank target. */
    public const APEX_VEBLEN_INFLATION_SCALAR = 0.80;

    // --- Structural Gross Margin Cost Intensities for Cross-Subsidization ---
    /** Relative variable cost intensity of mass-market commuter fleet (low margin / predatory baseline). */
    public const MASS_MARKET_COST_INTENSITY = 1.15;

    /** Relative variable cost intensity of Apex Division ultra-luxury hypercars (high gross profit margin cross-subsidy). */
    public const APEX_LUXURY_COST_INTENSITY = 0.65;

    /** Relative variable cost intensity of connected telematics and software services (pure digital/financing tollbooth). */
    public const SOFTWARE_SERVICES_COST_INTENSITY = 0.30;

    // --- Tail Risk & Event Physics ---
    /** Negative Z-score threshold indicating a massive vehicle safety recall and litigation liability. */
    public const MASSIVE_RECALL_Z_SCORE = -2.20;

    /** Variable margin penalty applied during safety recalls to cover warranty replacements and legal damages. */
    public const MASSIVE_RECALL_PENALTY = 0.05;

    /** Negative Z-score threshold indicating an auto-worker union labor strike. */
    public const UAW_STRIKE_Z_SCORE = -2.50;

    /** Revenue throughput multiplier applied during factory assembly shutdowns from labor strikes. */
    public const UAW_STRIKE_MULT = 0.80;

        public function getReversionSpeed(): float { return 0.08; }
    public function getMoatSpread(): float { return 0.015; }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $params = $this->resolveModelParameters($stock, [
            ModelParam::RateSensitivityScalar->value => self::RATE_SENSITIVITY_SCALAR,
        ]);
        $rateScalar = $params[ModelParam::RateSensitivityScalar->value];

        $policyRate = $macroState->policyRateEma;
        $beta = $this->getOperatingCyclicality($stock);

        // High policy rates destroy debt-financed consumer auto purchases
        $ratePenalty = 0.0;
        if ($policyRate > self::NEUTRAL_POLICY_RATE) {
            $ratePenalty = ($policyRate - self::NEUTRAL_POLICY_RATE) * $beta * $rateScalar;
        } else {
            // Capped boost when rates are ultra-low
            $ratePenalty = max(-0.10, ($policyRate - self::NEUTRAL_POLICY_RATE) * $beta * ($rateScalar * 0.5));
        }

        $physics['macro_demand_shift'] -= $ratePenalty;

        // FIX: Tamed the sentiment multiplier from 1.50 to 0.40.
        // A -40 point drop in sentiment for a 1.75 beta stock now results in a realistic -28% demand drop.
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $physics['macro_demand_shift'] += ($sentimentShift * $beta * 0.40);

        return $physics;
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::AutoSalesWeight->value        => self::AUTO_SALES_WEIGHT,
            ModelParam::ApexLuxuryWeight->value       => self::APEX_LUXURY_WEIGHT,
            ModelParam::SoftwareServicesWeight->value => self::SOFTWARE_SERVICES_WEIGHT,
            ModelParam::PricingPowerIndex->value      => self::PRICING_POWER_INDEX,
        ]);

        $salesWeight    = $params[ModelParam::AutoSalesWeight];
        $apexWeight     = $params[ModelParam::ApexLuxuryWeight];
        $softwareWeight = $params[ModelParam::SoftwareServicesWeight];
        $pricingPower   = $params[ModelParam::PricingPowerIndex];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'mass_market_sales'   => $params[ModelParam::AutoSalesWeight],
            'apex_luxury'         => $params[ModelParam::ApexLuxuryWeight],
            'software_telematics' => $params[ModelParam::SoftwareServicesWeight],
        ]);

        $salesWeight    = $activeWeights['mass_market_sales'];
        $apexWeight     = $activeWeights['apex_luxury'];
        $softwareWeight = $activeWeights['software_telematics'];

        // Independent stream Z-scores with persistent AR(1) momentum
        $salesZ    = $streams->generateZ('mass_market_sales', 0.25);
        $apexZ     = $streams->generateZ('apex_luxury', 0.35);
        $softwareZ = $streams->generateZ('software_telematics', 0.40);
        $eventZ    = $streams->generateExogenousZ('event', 0.10);

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
        // Hypercar demand decouples from mass-market GDP: driven by equity asset wealth (ERP compression), QE liquidity, and Veblen pricing power
        $erpCompression = max(0.0, MacroEngine::BASE_EQUITY_RISK_PREMIUM - $macroState->equityRiskPremium);
        $wealthEffect = $erpCompression * self::APEX_ERP_COMPRESSION_SCALAR;
        $qeLiquidityBoost = $macroState->qeActive ? ($macroState->qeIntensity * self::APEX_QE_LIQUIDITY_BOOST) : 0.0;
        $veblenInflationPower = $macroState->inflationEma > MacroEngine::TARGET_INFLATION
            ? ($macroState->inflationEma - MacroEngine::TARGET_INFLATION) * self::APEX_VEBLEN_INFLATION_SCALAR
            : 0.0;

        $apexMacroBoost = $wealthEffect + $qeLiquidityBoost + $veblenInflationPower;

        // Input cost basket on physical manufacturing: energy, metals, ocean freight, components and line payroll,
        // recovered in transaction prices at the OEM's pricing power. The drag is struck on the blended cost base
        // and lands entirely on the mass-market fleet, so it is regrossed by that stream's weight below.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
        $gscpiShift = max(0.0, $macroState->supplyChainPressureIndexEma - MacroEngine::GSCPI_BASELINE);
        $inflationCostPenalty = ($inputCostDrag / max(0.05, $salesWeight)) + ($gscpiShift * self::SUPPLY_CHAIN_PRESSURE_COST_SCALAR * (1.0 - ($pricingPower * 0.50)));

        // Captive Finance NIM Squeeze & Subprime Provisioning
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $retailDefaultShift = max(0.0, ($macroState->retailDefaultRateEma - MacroEngine::RETAIL_DEFAULT_BASELINE) / MacroEngine::RETAIL_DEFAULT_BASELINE);
        $corporateDefaultShift = max(0.0, ($macroState->corporateDefaultRateEma - MacroEngine::CORPORATE_DEFAULT_BASELINE) / MacroEngine::CORPORATE_DEFAULT_BASELINE);
        $macroDefaultDrag = ($sentimentShift < 0.0 ? abs($sentimentShift) * self::MACRO_DEFAULT_SCALAR : 0.0) + ($retailDefaultShift * 0.05) + ($corporateDefaultShift * 0.02);

        $creditSpread = $macroState->macroCreditSpread;
        $ceclDrag = $creditSpread > self::CECL_BASELINE_CREDIT_SPREAD
            ? ($creditSpread - self::CECL_BASELINE_CREDIT_SPREAD) * 0.50
            : 0.0;

        $yield10y = $macroState->yield10yEma;
        $yield2y  = $macroState->yield2yEma;
        $bankSpread = $yield10y - $yield2y;

        if ($bankSpread < 0) {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread)
                + pow(abs($bankSpread) * FinancialConstants::YIELD_CURVE_INVERSION_SENSITIVITY, 2) * self::NIM_QUADRATIC_COEFF;
        } else {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread) * self::NIM_LINEAR_SENSITIVITY;
        }

        // --- Tri-Stream Revenue Calculation ---
        $salesShock    = $salesZ    * ($baselineVol * self::SALES_VARIANCE_SCALAR);
        $apexShock     = $apexZ     * ($baselineVol * self::APEX_VARIANCE_SCALAR);
        $softwareShock = $softwareZ * ($baselineVol * self::SOFTWARE_VARIANCE_SCALAR);
        $cuShift = MathUtility::calculateCapacityUtilizationShift($macroState->capacityUtilizationRateEma, MacroEngine::CU_BASELINE, self::CAPACITY_UTILIZATION_THROUGHPUT_SCALAR);

        $salesRevenue    = max(0.0, $expectedRevenue * $salesWeight    * (1.0 + $salesShock + $this->resolveFxDemandShift($macroState) + $cuShift) * $salesMultiplier);
        $apexRevenue     = max(0.0, $expectedRevenue * $apexWeight     * (1.0 + $apexShock + $apexMacroBoost));
        $softwareRevenue = max(0.0, $expectedRevenue * $softwareWeight * (1.0 + $softwareShock));

        $streamRevenues = [
            'mass_market_sales'   => $salesRevenue,
            'apex_luxury'         => $apexRevenue,
            'software_telematics' => $softwareRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cross-Subsidization Variable Cost Architecture ---
        $blendedIntensity = ($salesWeight * self::MASS_MARKET_COST_INTENSITY)
            + ($apexWeight * self::APEX_LUXURY_COST_INTENSITY)
            + ($softwareWeight * self::SOFTWARE_SERVICES_COST_INTENSITY);
        $blendedIntensity = max(0.01, $blendedIntensity);

        $salesBaseMargin    = $realizedVariableMargin * (self::MASS_MARKET_COST_INTENSITY / $blendedIntensity);
        $apexBaseMargin     = $realizedVariableMargin * (self::APEX_LUXURY_COST_INTENSITY / $blendedIntensity);
        $softwareBaseMargin = $realizedVariableMargin * (self::SOFTWARE_SERVICES_COST_INTENSITY / $blendedIntensity);

        $salesCosts    = $salesRevenue    * ($salesBaseMargin + $inflationCostPenalty + $recallPenalty);
        // Veblen price hikes reprice the same hypercar deliveries: no incremental build cost on that slice.
        $apexPriceRevenue = max(0.0, $expectedRevenue * $apexWeight * $veblenInflationPower);
        $apexCosts     = max(0.0, $apexRevenue - $apexPriceRevenue) * $apexBaseMargin;
        $softwareCosts = $softwareRevenue * ($softwareBaseMargin + $macroDefaultDrag + $nimSqueeze + $ceclDrag);

        $totalVariableCosts = $salesCosts + $apexCosts + $softwareCosts;
        // The blended ratio is struck on volume revenue so the template method reproduces these dollar costs exactly.
        $volumeRevenue = max(1.0, $actualRevenue - $apexPriceRevenue);
        $effectiveMargin = $actualRevenue > 0 ? ($totalVariableCosts / $volumeRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$salesZ, $apexZ, $softwareZ], $eventZ);

        // Observable shock blending
        $strikeShock = ($salesMultiplier - 1.0) * $salesWeight;
        $recallShock = -$recallPenalty * $salesWeight;

        $observableShockZ = ($salesShock * $salesWeight * 0.60) +
            ($apexShock * $apexWeight * 0.40) +
            ($softwareShock * $softwareWeight * 0.20) +
            ($strikeShock * 0.80) +
            ($recallShock * 0.70);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            priceRevenue: $apexPriceRevenue,
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
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'industrial_metals_index_ema',
            'inflation_ema',
            'macro_credit_spread',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'policy_rate_ema',
            'producer_price_inflation_ema',
            'qe_active',
            'qe_intensity',
            'retail_default_rate_ema',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
            'yield_10y_ema',
            'yield_2y_ema',
        ];
    }
}
