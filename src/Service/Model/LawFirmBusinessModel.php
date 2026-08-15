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
 * Earnings strategy for Elite Law Firms, White-Shoe Practices & Legal Services.
 * 
 * Financial Physics:
 * - Asset Light & Human Capital Driven: Ultra-low CapEx, very high cash flow conversion.
 * - Tri-Stream Legal Architecture:
 *   1. Corporate Retainers & Governance: Sticky recurring retainer fees for corporate governance, regulatory compliance,
 *      and M&A transactions (pro-cyclical with corporate boardroom activity).
 *   2. High-Stakes Litigation & Contingency Fees: Extremely volatile, high-variance corporate warfare, antitrust battles,
 *      and patent infringement contingency windfalls.
 *   3. Restructuring & Bankruptcy Workouts: **Counter-cyclical legal surge**—when credit spreads blow out and corporate
 *      defaults surge during recessions, Chapter 11 and restructuring billing explodes.
 * - Inflation Dynamics: Immune to physical supply chains, but exposed to top-tier associate wage inflation (mitigated by PricingPowerIndex).
 */
class LawFirmBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for elite private partnerships and legal firms. */
    public const BASE_COVERAGE_VISIBILITY = 0.20;

    /** Base coverage forecasting error given the lumpiness of litigation payouts. */
    public const BASE_COVERAGE_ERROR = 0.06;

    // --- Tri-Stream Legal Architecture ---
    /** Baseline fraction of revenue derived from sticky B2B corporate retainers and M&A compliance. */
    public const CORPORATE_RETAINER_WEIGHT = 0.45;

    /** Baseline fraction of revenue derived from high-stakes corporate litigation and contingency fees. */
    public const LITIGATION_CONTINGENCY_WEIGHT = 0.35;

    /** Baseline fraction of revenue derived from counter-cyclical restructuring and bankruptcy workouts. */
    public const RESTRUCTURING_ADVISORY_WEIGHT = 0.20;

    /** Baseline pricing power and rate-card leverage of elite white-shoe legal counsel. */
    public const PRICING_POWER_INDEX = 0.85;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for steady corporate retainer and advisory billing. */
    public const RETAINER_VARIANCE_SCALAR = 0.10;

    /** Volatility multiplier for lumpy litigation settlement payouts. */
    public const LITIGATION_VARIANCE_SCALAR = 2.50;

    /** Volatility multiplier for bankruptcy restructuring mandates. */
    public const RESTRUCTURING_VARIANCE_SCALAR = 0.45;

    // --- Macro Physics & Counter-Cyclical Restructuring ---
    /** Sensitivity of corporate M&A and advisory billing to economic output gap. */
    public const RETAINER_MACRO_SCALAR = 0.60;

    /** Sensitivity multiplier translating economic contraction depth into bankruptcy advisory surge. */
    public const RESTRUCTURING_RECESSION_SCALAR = 3.00;

    /** Sensitivity multiplier translating corporate credit spread spikes into restructuring billing surge. */
    public const RESTRUCTURING_SPREAD_SCALAR = 15.00;

    /** Baseline credit spread above which corporate bankruptcy workouts accelerate. */
    public const DEFAULT_CREDIT_SPREAD_BASELINE = 0.015;

    // --- Associate Wage Inflation ---
    /** Sensitivity of law firm variable margin to legal talent and associate wage inflation. */
    public const ASSOCIATE_WAGE_INFLATION_SCALAR = 0.60;

    // --- Tail Risk & Event Physics ---
    /** Positive Z-score threshold indicating a landmark corporate litigation or antitrust victory. */
    public const LITIGATION_WIN_Z_SCORE = 2.00;

    /** Revenue multiplier for a major corporate litigation or contingency settlement victory. */
    public const LITIGATION_WIN_MULT = 1.50;

    /** Negative Z-score threshold indicating a major trial defeat or lost contingency verdict. */
    public const LITIGATION_LOSS_Z_SCORE = -2.00;

    /** Revenue multiplier for a lost major trial or forfeited contingency engagement. */
    public const LITIGATION_LOSS_MULT = 0.75;

    public function getModelThresholds(): array
    {
        return [
            'min_icr'                  => 3.00,
            'bankrupt_equity'          => 0.0,
            'distress_equity'          => 0.0,
            'warning_equity'           => 0.0,
            'wholesale_leverage_limit' => 1.5,
            'dividend_crisis_icr'      => 2.00,
            'buyback_min_icr'          => 3.00,
            'reversion_speed'          => 0.15,
            'moat_spread'              => 0.015,
            'nwc_intensity'            => 0.05,
            'capex_completion_rate'    => 0.20,
        ];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02;
    }

    public function getCapexCyclicality(): float
    {
        return 0.10; // Virtually no physical capex
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global generic demand shifts; cyclicality is handled per-stream.
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
            ModelParam::CorporateRetainerWeight->value     => self::CORPORATE_RETAINER_WEIGHT,
            ModelParam::LitigationContingencyWeight->value  => self::LITIGATION_CONTINGENCY_WEIGHT,
            ModelParam::RestructuringAdvisoryWeight->value  => self::RESTRUCTURING_ADVISORY_WEIGHT,
            ModelParam::PricingPowerIndex->value            => self::PRICING_POWER_INDEX,
        ]);

        $retainerWeight      = $params[ModelParam::CorporateRetainerWeight];
        $litigationWeight    = $params[ModelParam::LitigationContingencyWeight];
        $restructuringWeight = $params[ModelParam::RestructuringAdvisoryWeight];
        $pricingPower        = $params[ModelParam::PricingPowerIndex];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'corporate_retainers'    => $params[ModelParam::CorporateRetainerWeight],
            'litigation_settlements' => $params[ModelParam::LitigationContingencyWeight],
            'restructuring_advisory' => $params[ModelParam::RestructuringAdvisoryWeight],
        ]);

        $retainerWeight      = $activeWeights['corporate_retainers'];
        $litigationWeight    = $activeWeights['litigation_settlements'];
        $restructuringWeight = $activeWeights['restructuring_advisory'];

        // Independent stream Z-scores with persistent AR(1) momentum
        $retainerZ      = $streams->generateZ('corporate_retainers', 0.40);
        $litigationZ    = $streams->generateZ('litigation_settlements', 0.10);
        $restructuringZ = $streams->generateZ('restructuring_advisory', 0.30);
        $eventZ         = $streams->generateZ('event', 0.10);

        // --- Macro Sensitivities & Restructuring Surge ---
        $outputGap = $macroState->outputGapEma;
        $creditSpread = $macroState->macroCreditSpread;

        // Retainers expand slightly during corporate booms
        $retainerMacroBoost = max(0.0, $outputGap * self::RETAINER_MACRO_SCALAR * $beta);

        // Restructuring surges counter-cyclically during economic recessions and credit default waves
        $recessionDepth = max(0.0, -$outputGap);
        $excessSpread   = max(0.0, $creditSpread - self::DEFAULT_CREDIT_SPREAD_BASELINE);
        $restructuringSurge = ($recessionDepth * self::RESTRUCTURING_RECESSION_SCALAR * $beta)
            + ($excessSpread * self::RESTRUCTURING_SPREAD_SCALAR);

        // --- Tail Risk & Settlement Events ---
        $litigationMult = 1.0;
        $eventType = null;

        if ($litigationZ > self::LITIGATION_WIN_Z_SCORE || $eventZ > self::LITIGATION_WIN_Z_SCORE) {
            $litigationMult = self::LITIGATION_WIN_MULT;
            $eventType = ShockEvent::LITIGATION_SETTLEMENT_WIN;
        } elseif ($litigationZ < self::LITIGATION_LOSS_Z_SCORE || $eventZ < self::LITIGATION_LOSS_Z_SCORE) {
            $litigationMult = self::LITIGATION_LOSS_MULT;
            $eventType = ShockEvent::LITIGATION_SETTLEMENT_LOSS;
        }

        // --- Tri-Stream Revenue Calculation ---
        $retainerShock      = $retainerZ      * ($baselineVol * self::RETAINER_VARIANCE_SCALAR);
        $litigationShock    = $litigationZ    * ($baselineVol * self::LITIGATION_VARIANCE_SCALAR);
        $restructuringShock = $restructuringZ * ($baselineVol * self::RESTRUCTURING_VARIANCE_SCALAR);

        $retainerRevenue      = max(0.0, $expectedRevenue * $retainerWeight      * (1.0 + $retainerShock + $retainerMacroBoost));
        $litigationRevenue    = max(0.0, $expectedRevenue * $litigationWeight    * (1.0 + $litigationShock) * $litigationMult);
        $restructuringRevenue = max(0.0, $expectedRevenue * $restructuringWeight * (1.0 + $restructuringShock + $restructuringSurge));

        $streamRevenues = [
            'corporate_retainers'    => $retainerRevenue,
            'litigation_settlements' => $litigationRevenue,
            'restructuring_advisory' => $restructuringRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Associate Wage Inflation Squeeze (mitigated by pricing power)
        $excessInflation = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
        $wageDrag = $excessInflation * self::ASSOCIATE_WAGE_INFLATION_SCALAR * (1.0 - ($pricingPower * 0.60));

        $rawMargin = $realizedVariableMargin + $wageDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Determine dominant shock driver
        $streamAbs = [
            'corporate_retainers'    => abs($retainerZ),
            'litigation_settlements' => abs($litigationZ),
            'restructuring_advisory' => abs($restructuringZ),
        ];
        arsort($streamAbs);
        $dominantKey = array_key_first($streamAbs);
        $primaryShockZ = match ($dominantKey) {
            'litigation_settlements' => $litigationZ,
            'restructuring_advisory' => $restructuringZ,
            default                  => $retainerZ,
        };

        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // Blended observable shock
        $observableShockZ = ($retainerShock * $retainerWeight) +
            ($litigationShock * $litigationWeight * 0.50) +
            ($restructuringShock * $restructuringWeight * 0.50) +
            ($restructuringSurge * $restructuringWeight * 0.40);

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
