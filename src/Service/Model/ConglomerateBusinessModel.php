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
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Multi-Industry Conglomerates & Industrial Holding Trusts.
 * 
 * Financial Physics:
 * - Natural Hedging: Diversified operating subsidiaries across industrial, consumer, and financial verticals
 *   dramatically dampen baseline earnings volatility.
 * - Tri-Stream Architecture:
 *   1. Industrial Manufacturing: Heavy engineering, specialized chemicals, and B2B manufacturing components
 *      (pro-cyclical, exposed to the macroeconomic output gap and industrial CapEx cycles).
 *   2. Defensive Staples & Infrastructure: Inelastic consumer household goods, civic utilities, and infrastructure
 *      tollbooths (highly stable recurring cash flows immune to macro cycles).
 *   3. Contrarian Float & Financial Investments: Corporate treasury cash float, high-yield catastrophe bonds,
 *      and value investing dry powder. **Surges counter-cyclically during recessions and credit spread blowouts**
 *      when panic creates asymmetric buyout and preferred equity yield opportunities.
 */
class ConglomerateBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for multi-industry conglomerates with complex multi-segment reporting. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;

    /** Base coverage forecasting error given diversification across unlisted subsidiaries. */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Tri-Stream Conglomerate Architecture ---
    /** Baseline fraction of revenue derived from cyclical industrial manufacturing subsidiaries. */
    public const INDUSTRIAL_CONGLOMERATE_WEIGHT = 0.40;

    /** Baseline fraction of revenue derived from defensive consumer staples & infrastructure tollbooths. */
    public const DEFENSIVE_STAPLES_WEIGHT = 0.40;

    /** Baseline fraction of revenue derived from financial float, investments, and contrarian dry powder. */
    public const CONTRARIAN_FLOAT_WEIGHT = 0.20;

    /** Baseline pricing power and monopoly leverage across conglomerate product lines. */
    public const PRICING_POWER_INDEX = 0.70;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for cyclical industrial manufacturing throughput. */
    public const INDUSTRIAL_VARIANCE_SCALAR = 0.35;

    /** Volatility multiplier for defensive consumer staples and contracted infrastructure assets. */
    public const DEFENSIVE_VARIANCE_SCALAR = 0.08;

    /** Volatility multiplier for financial investment returns and mark-to-market float gains. */
    public const FLOAT_VARIANCE_SCALAR = 0.60;

    // --- Macro Physics & Contrarian Float Deployment ---
    /** Sensitivity of industrial manufacturing to broader GDP output gap expansion. */
    public const INDUSTRIAL_MACRO_SCALAR = 1.20;

    /** Sensitivity multiplier translating economic contraction into contrarian buyout alpha. */
    public const FLOAT_RECESSION_ALPHA_SCALAR = 2.50;

    /** Sensitivity multiplier translating corporate credit spread spikes into distressed float yield. */
    public const FLOAT_SPREAD_BLOWOUT_SCALAR = 12.00;

    /** Baseline credit spread above which contrarian capital deployment generates high-yield returns. */
    public const DEFAULT_CREDIT_SPREAD_BASELINE = 0.015;

    // --- Tail Risk & Shock Events ---
    /** Positive Z-score threshold indicating a landmark corporate acquisition or major subsidiary spin-off. */
    public const STRATEGIC_DIVESTITURE_Z_SCORE = 2.30;

    /** Top-line revenue multiplier from strategic acquisitions or restructuring dividends. */
    public const STRATEGIC_DIVESTITURE_MULT = 1.15;

    /** Negative Z-score threshold indicating an operational bottleneck or subsidiary restructuring drag. */
    public const RESTRUCTURING_DRAG_Z_SCORE = -2.30;

    /** Variable cost penalty applied during multi-subsidiary restructuring or supply chain write-offs. */
    public const RESTRUCTURING_DRAG_PENALTY = 0.04;

    public function getModelThresholds(): array
    {
        return [
            'min_icr'                  => 2.50,
            'bankrupt_equity'          => 0.0,
            'distress_equity'          => 0.0,
            'warning_equity'           => 0.0,
            'wholesale_leverage_limit' => 2.0,
            'dividend_crisis_icr'      => 1.75,
            'buyback_min_icr'          => 2.50,
            'reversion_speed'          => 0.10,
            'moat_spread'              => 0.015,
            'nwc_intensity'            => 0.10,
            'capex_completion_rate'    => 0.15,
        ];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02; // Stable mature holding company growth
    }

    public function getCapexCyclicality(): float
    {
        return 0.50; // Blended across heavy industrial CapEx and asset-light investment float
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.55, 'revenue_weight' => 0.45];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shifts to handle cyclicality discretely per stream.
        $physics['macro_demand_shift'] = 0.0;
        $physics['pricing_power_multiplier'] = 1.0;

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
            ModelParam::IndustrialConglomerateWeight->value => self::INDUSTRIAL_CONGLOMERATE_WEIGHT,
            ModelParam::DefensiveStaplesWeight->value       => self::DEFENSIVE_STAPLES_WEIGHT,
            ModelParam::ContrarianFloatWeight->value        => self::CONTRARIAN_FLOAT_WEIGHT,
            ModelParam::PricingPowerIndex->value            => self::PRICING_POWER_INDEX,
        ]);

        $industrialWeight = $params[ModelParam::IndustrialConglomerateWeight];
        $defensiveWeight  = $params[ModelParam::DefensiveStaplesWeight];
        $floatWeight      = $params[ModelParam::ContrarianFloatWeight];
        $pricingPower     = $params[ModelParam::PricingPowerIndex];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'industrial_manufacturing' => $params[ModelParam::IndustrialConglomerateWeight],
            'defensive_staples'        => $params[ModelParam::DefensiveStaplesWeight],
            'financial_investments'    => $params[ModelParam::ContrarianFloatWeight],
        ]);

        $industrialWeight = $activeWeights['industrial_manufacturing'];
        $defensiveWeight  = $activeWeights['defensive_staples'];
        $floatWeight      = $activeWeights['financial_investments'];

        // Independent stream Z-scores with persistent AR(1) momentum
        $industrialZ = $streams->generateZ('industrial_manufacturing', 0.25);
        $defensiveZ  = $streams->generateZ('defensive_staples', 0.45);
        $floatZ      = $streams->generateZ('financial_investments', 0.15);
        $eventZ      = $streams->generateZ('event', 0.10);

        // --- Macro Sensitivities & Contrarian Float Mechanics ---
        $outputGap = $macroState->outputGapEma;
        $creditSpread = $macroState->macroCreditSpread;

        // Industrial manufacturing is pro-cyclical with GDP output gap
        $industrialMacroBoost = $outputGap * self::INDUSTRIAL_MACRO_SCALAR * $beta;

        // Contrarian Float: Surges counter-cyclically during economic distress & credit spread blowouts
        $recessionDepth = max(0.0, -$outputGap);
        $excessSpread   = max(0.0, $creditSpread - self::DEFAULT_CREDIT_SPREAD_BASELINE);
        $contrarianSurge = ($recessionDepth * self::FLOAT_RECESSION_ALPHA_SCALAR * $beta)
            + ($excessSpread * self::FLOAT_SPREAD_BLOWOUT_SCALAR);

        // --- Tail Risk & Event Physics ---
        $acquisitionMult = 1.0;
        $eventType = null;
        $restructuringPenalty = 0.0;

        if ($eventZ > self::STRATEGIC_DIVESTITURE_Z_SCORE) {
            $acquisitionMult = self::STRATEGIC_DIVESTITURE_MULT;
            $eventType = ShockEvent::PERFORMANCE_FEE_SURGE;
        } elseif ($eventZ < self::RESTRUCTURING_DRAG_Z_SCORE) {
            $restructuringPenalty = self::RESTRUCTURING_DRAG_PENALTY * (1.0 - ($pricingPower * 0.50));
            $eventType = ShockEvent::PROJECT_DELAY;
        }

        // --- Tri-Stream Revenue Calculation ---
        $industrialShock = $industrialZ * ($baselineVol * self::INDUSTRIAL_VARIANCE_SCALAR);
        $defensiveShock  = $defensiveZ  * ($baselineVol * self::DEFENSIVE_VARIANCE_SCALAR);
        $floatShock      = $floatZ      * ($baselineVol * self::FLOAT_VARIANCE_SCALAR);

        $industrialRevenue = max(0.0, $expectedRevenue * $industrialWeight * (1.0 + $industrialShock + $industrialMacroBoost) * $acquisitionMult);
        $defensiveRevenue  = max(0.0, $expectedRevenue * $defensiveWeight  * (1.0 + $defensiveShock));
        $floatRevenue      = max(0.0, $expectedRevenue * $floatWeight      * (1.0 + $floatShock + $contrarianSurge));

        $streamRevenues = [
            'industrial_manufacturing' => $industrialRevenue,
            'defensive_staples'        => $defensiveRevenue,
            'financial_investments'    => $floatRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Realized variable margin scaling
        $rawMargin = $realizedVariableMargin + $restructuringPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$industrialZ, $defensiveZ, $floatZ], $eventZ);

        // Blended observable shock
        $observableShockZ = ($industrialShock * $industrialWeight) +
            ($defensiveShock * $defensiveWeight) +
            ($floatShock * $floatWeight * 0.40) +
            ($industrialMacroBoost * $industrialWeight) +
            ($contrarianSurge * $floatWeight * 0.50);

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
