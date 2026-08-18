<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
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
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for automakers via monthly dealership channel registration data. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;

    /** Base coverage forecasting error given consumer financing and luxury shipment lumpiness. */
    public const BASE_COVERAGE_ERROR = 0.07;

    // --- Tri-Stream Automotive Architecture ---
    /** Baseline fraction of revenue derived from mass-market consumer vehicle manufacturing. */
    public const AUTO_SALES_WEIGHT = 0.60;

    /** Baseline fraction of revenue derived from hyper-exclusive Apex luxury hypercars (Veblen margin cross-subsidy). */
    public const APEX_LUXURY_WEIGHT = 0.20;

    /** Baseline fraction of revenue derived from captive financing and connected telematics/software tolls. */
    public const SOFTWARE_SERVICES_WEIGHT = 0.20;

    /** Baseline pricing power across OEM vehicle model lines. */
    public const PRICING_POWER_INDEX = 0.65;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for mass-market fleet volume. */
    public const SALES_VARIANCE_SCALAR = 0.30;

    /** Volatility multiplier for ultra-luxury hypercar deliveries. */
    public const APEX_VARIANCE_SCALAR = 0.40;

    /** Volatility multiplier for captive finance and software subscription recurring tolls. */
    public const SOFTWARE_VARIANCE_SCALAR = 0.12;

    // --- Financing Arm & Credit Physics ---
    /** Break-even Net Interest Margin floor (~50bps). */
    public const NIM_SPREAD_BUFFER = 0.005;

    /** Linear sensitivity to yield curve spread. */
    public const NIM_LINEAR_SENSITIVITY = 1.00;

    /** Quadratic penalty for yield curve inversion. */
    public const NIM_QUADRATIC_COEFF = 0.15;

    /** Drag on auto loans during negative consumer sentiment regimes. */
    public const MACRO_DEFAULT_SCALAR = 0.25;

    /** Baseline credit spread above which CECL forward provisioning accelerates. */
    public const CECL_BASELINE_CREDIT_SPREAD = 0.02;

    // --- Interest Rate & Sentiment Sensitivity ---
    /** Neutral policy rate (~3.0%). Rates above this destroy consumer auto financing demand. */
    public const NEUTRAL_POLICY_RATE = 0.03;

    /** Scalar for demand destruction per 100bps of policy rate above neutral. */
    public const RATE_SENSITIVITY_SCALAR = 2.50;

    // --- Apex Luxury & Veblen Wealth Effect ---
    /** Sensitivity of ultra-luxury hypercar deliveries to equity risk premium compression (asset wealth expansion). */
    public const APEX_ERP_COMPRESSION_SCALAR = 35.0;

    /** Top-line revenue boost multiplier during central bank Quantitative Easing liquidity surges. */
    public const APEX_QE_LIQUIDITY_BOOST = 0.15;

    /** Veblen pricing power boost scalar when inflation exceeds the central bank target. */
    public const APEX_VEBLEN_INFLATION_SCALAR = 0.80;

    // --- Structural Gross Margin Cost Intensities for Cross-Subsidization ---
    /** Relative variable cost intensity of mass-market commuter fleet (low margin / predatory baseline). */
    public const MASS_MARKET_COST_INTENSITY = 1.35;

    /** Relative variable cost intensity of Apex Division ultra-luxury hypercars (astronomical gross profit margin cross-subsidy). */
    public const APEX_LUXURY_COST_INTENSITY = 0.50;

    /** Relative variable cost intensity of connected telematics and software services (pure digital/financing tollbooth). */
    public const SOFTWARE_SERVICES_COST_INTENSITY = 0.25;

    // --- Tail Risk & Event Physics ---
    /** Negative Z-score threshold indicating a massive vehicle safety recall and litigation liability. */
    public const MASSIVE_RECALL_Z_SCORE = -2.20;

    /** Variable margin penalty applied during safety recalls to cover warranty replacements and legal damages. */
    public const MASSIVE_RECALL_PENALTY = 0.05;

    /** Negative Z-score threshold indicating an auto-worker union labor strike. */
    public const UAW_STRIKE_Z_SCORE = -2.50;

    /** Revenue throughput multiplier applied during factory assembly shutdowns from labor strikes. */
    public const UAW_STRIKE_MULT = 0.80;

    public function getModelThresholds(): array
    {
        return [
            'min_icr'                  => 2.00,
            'bankrupt_equity'          => 0.0,
            'distress_equity'          => 0.0,
            'warning_equity'           => 0.0,
            'wholesale_leverage_limit' => 1.0,
            'dividend_crisis_icr'      => 1.50,
            'buyback_min_icr'          => 2.00,
            'reversion_speed'          => 0.08,
            'moat_spread'              => 0.015,
            'nwc_intensity'            => 0.15,
            'capex_completion_rate'    => 0.125,
        ];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $params = $this->resolveModelParameters($stock, [
            ModelParam::RateSensitivityScalar->value => self::RATE_SENSITIVITY_SCALAR,
        ]);
        $rateScalar = $params[ModelParam::RateSensitivityScalar->value];

        $policyRate = $macroState->policyRateEma;
        $beta = (float) $stock->getBeta();

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
        $physics['macro_demand_shift'] += $sentimentShift * $beta * 0.40;

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
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

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
        $eventZ    = $streams->generateZ('event', 0.10);

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

        // Supply chain inflation & energy cost penalty on physical manufacturing
        $inflation = $macroState->inflationEma;
        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);
        $baseInflationPenalty = max(0.0, $inflation - MacroEngine::TARGET_INFLATION) * $beta * self::INFLATION_PENALTY_SCALAR;
        $inflationCostPenalty = ($baseInflationPenalty + ($energyShift * 0.05)) * (1.0 - ($pricingPower * 0.50));

        // Captive Finance NIM Squeeze & Subprime Provisioning
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $macroDefaultDrag = $sentimentShift < 0.0 ? abs($sentimentShift) * self::MACRO_DEFAULT_SCALAR : 0.0;

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

        $salesRevenue    = max(0.0, $expectedRevenue * $salesWeight    * (1.0 + $salesShock) * $salesMultiplier);
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
        $apexCosts     = $apexRevenue     * $apexBaseMargin;
        $softwareCosts = $softwareRevenue * ($softwareBaseMargin + $macroDefaultDrag + $nimSqueeze + $ceclDrag);

        $totalVariableCosts = $salesCosts + $apexCosts + $softwareCosts;
        $effectiveMargin = $actualRevenue > 0 ? ($totalVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin);

        // Determine dominant shock driver
        $streamAbs = [
            'mass_market_sales'   => abs($salesZ),
            'apex_luxury'         => abs($apexZ),
            'software_telematics' => abs($softwareZ),
        ];
        arsort($streamAbs);
        $dominantKey = array_key_first($streamAbs);
        $primaryShockZ = match ($dominantKey) {
            'apex_luxury'         => $apexZ,
            'software_telematics' => $softwareZ,
            default               => $salesZ,
        };

        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // Observable shock blending
        $strikeShock = ($salesMultiplier - 1.0) * $salesWeight;

        $observableShockZ = ($salesShock * $salesWeight * 0.60) +
            ($apexShock * $apexWeight * 0.40) +
            ($softwareShock * $softwareWeight * 0.20) +
            ($strikeShock * 0.80);

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
}
