<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\StockModelTuning;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Pure-Play Investment Banks and M&A Advisory Syndicates.
 *
 * Financial Physics:
 * - Dual-Desk Architecture: Advisory (M&A/underwriting) and Sales & Trading are structurally decorrelated.
 *   A frozen M&A market can coexist with record trading revenues during a volatility panic.
 * - Pro-Cyclical M&A and DCM Deal Flow: Advisory revenue and IPO/debt syndication fees explode during
 *   economic expansions and steep yield curves. A flat or inverted curve kills DCM volumes.
 * - Proprietary Trading Desk Volatility Arbitrage: During market panics or VIX spikes, market-making
 *   desks print massive trading revenue, providing counter-cyclical crash protection.
 * - Compensation-Driven Cost Structure: IB variable costs are dominated by discretionary bonuses,
 *   locked in a tight 40–85% of revenue band that cannot be arbitrarily compressed.
 * - Evaluated on Return on Equity (ROE) with institutional capital requirement rules.
 */
class InvestmentBankBusinessModel extends BrokerageBusinessModel
{
    public function getModelThresholds(): array
    {
        return ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => null, 'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15, 'reversion_speed' => 0.18, 'moat_spread' => 0.005, 'nwc_intensity' => 0.0, 'capex_completion_rate' => 1.0];
    }

    // --- Dual-Desk Revenue Architecture ---
    public const ADVISORY_REVENUE_WEIGHT    = 0.40;
    public const TRADING_REVENUE_WEIGHT     = 0.60;
    public const TRADING_VARIANCE_SCALAR    = 0.35;

    // --- Deal Flow & Macro Sensitivity ---
    public const DEAL_FLOW_BOOM_MULT        = 4.00;
    public const DEAL_FLOW_BUST_MULT        = 2.50;

    // --- DCM Yield Curve Channel ---
    public const DCM_STEEPNESS_FLOOR        = 0.005;
    public const DCM_STEEPNESS_SCALAR       = 2.50;
    public const DCM_STEEPNESS_CLAMP        = 0.40;

    // --- Proprietary Volatility Arbitrage (S&T Desk) ---
    public const VIX_ARBITRAGE_FLOOR        = 0.18;
    public const VIX_ARBITRAGE_SCALAR       = 1.20;
    public const DEFAULT_VIX_FALLBACK       = 0.20;

    // --- Revenue Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR    = 0.25;

    // --- Compensation Ratio Physics ---
    public const MIN_COMPENSATION_RATIO     = 0.40;
    public const MAX_COMPENSATION_RATIO     = 0.85;

    // --- M&A Mega-Deal Tail Events ---
    public const MEGA_DEAL_WIN_Z_SCORE      = 2.80;
    public const MEGA_DEAL_LOSS_Z_SCORE     = -2.80;
    public const MEGA_DEAL_WIN_MULT         = 1.12;
    public const MEGA_DEAL_LOSS_MULT        = 0.92;

    // --- Regulatory Tail Risk ---
    public const REGULATORY_FINE_PROBABILITY = 0.04;
    public const REGULATORY_FINE_SCALAR     = 0.08;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    public const BASE_COVERAGE_ERROR = 0.10;

    public const ADVISORY_ANALYST_VISIBILITY = 0.20;
    public const TRADING_ANALYST_VISIBILITY  = 0.55;

    // --- Event Lore Thresholds ---
    public const LORE_BOOM_OUTPUT_GAP       = 0.015;
    public const LORE_BOOM_Z_SCORE          = 1.20;
    public const LORE_PANIC_VIX             = 0.32;
    public const LORE_DCM_BOOM_CURVE        = 0.015;
    public const LORE_DROUGHT_OUTPUT_GAP    = -0.020;
    public const LORE_DROUGHT_Z_SCORE       = -1.50;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift to avoid double-dipping, as macro volume shocks 
        // (Deal flow) are handled discretely per-stream in calculateSectorPhysics.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'advisory_revenue_weight'       => self::ADVISORY_REVENUE_WEIGHT,
            'trading_revenue_weight'        => self::TRADING_REVENUE_WEIGHT,
            'options_premium_income_weight' => 0.00,
            'vix_arbitrage_scalar'          => self::VIX_ARBITRAGE_SCALAR,
        ]);

        $advisoryWeight = $params['advisory_revenue_weight'];
        $tradingWeight  = $params['trading_revenue_weight'];
        $optionsWeight  = $params['options_premium_income_weight'];
        $vixScalar      = $params['vix_arbitrage_scalar'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores with AR(1) persistence
        $advisoryZ = $mathUtility->generatePersistentZ($momentum['advisory'] ?? 0.0, 0.40);
        $tradingZ  = $mathUtility->generatePersistentZ($momentum['trading'] ?? 0.0, 0.15);
        $eventZ    = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.05);

        // --- Pro-Cyclical M&A Deal Flow ---
        $outputGap = $macroState->outputGapEma;
        $dealFlowMultiplier = $outputGap > 0.0
            ? ($outputGap * self::DEAL_FLOW_BOOM_MULT)
            : ($outputGap * self::DEAL_FLOW_BUST_MULT);

        // --- DCM Yield Curve Channel ---
        $policyRate = $macroState->policyRateEma;
        $yield5y    = $macroState->yield5yEma;
        $curveSlope = $yield5y - $policyRate;

        $dcmBonus   = max(
            -0.20,
            min(self::DCM_STEEPNESS_CLAMP, ($curveSlope - self::DCM_STEEPNESS_FLOOR) * self::DCM_STEEPNESS_SCALAR)
        );

        // --- S&T Volatility Arbitrage ---
        $vixEma = $macroState->marketVolatilityEma;
        $vixGap = $vixEma - self::VIX_ARBITRAGE_FLOOR;

        $volatilityArbitrage = $vixGap >= 0.0
            ? $vixGap * $vixScalar
            : max(-0.15, $vixGap * ($vixScalar * 0.5));

        // --- Event Modifiers & Penalties ---
        $eventType = null;
        $advisoryEventMultiplier = 1.0;
        $regulatoryPenalty = 0.0;

        // Regulatory check happens first, but can be overridden by M&A mega-deal
        if ($mathUtility->generateUniform() <= self::REGULATORY_FINE_PROBABILITY) {
            $eventType = ShockEvent::IB_REGULATORY_SETTLEMENT;
            $regulatoryPenalty = self::REGULATORY_FINE_SCALAR; // Apply the actual cost penalty
        }

        if ($eventZ > self::MEGA_DEAL_WIN_Z_SCORE) {
            $advisoryEventMultiplier = self::MEGA_DEAL_WIN_MULT;
            $eventType = ShockEvent::IB_MNA_LANDMARK_MANDATE;
        } elseif ($eventZ < self::MEGA_DEAL_LOSS_Z_SCORE) {
            $advisoryEventMultiplier = self::MEGA_DEAL_LOSS_MULT;
            $eventType = ShockEvent::IB_MNA_MANDATE_LOSS;
        }

        // --- Clamped Stream Revenue Calculation ---
        $advisoryRevenue = max(0.0, $expectedRevenue * $advisoryWeight * (1.0 + ($advisoryZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR) + $dealFlowMultiplier + $dcmBonus) * $advisoryEventMultiplier);
        $tradingRevenue  = max(0.0, $expectedRevenue * $tradingWeight * (1.0 + ($tradingZ * $baselineVol * self::TRADING_VARIANCE_SCALAR) + $volatilityArbitrage));

        $optionsRevenue = 0.0;
        $optionsZ = 0.0;
        if ($optionsWeight > 0.0) {
            $optionsZ = $mathUtility->generatePersistentZ($momentum['options'] ?? 0.0, 0.20);
            $optionsVixPenalty = $vixGap > 0.0 ? - ($vixGap * 1.5) : abs($vixGap * 0.5);
            $optionsRevenue = max(0.0, $expectedRevenue * $optionsWeight * (1.0 + ($optionsZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR) + $optionsVixPenalty));
        }

        $actualRevenue = $advisoryRevenue + $tradingRevenue + $optionsRevenue;

        // --- Compensation Ratio Physics & Cost Penalties ---
        // Add the regulatory penalty to the base variable margin before clamping
        $rawMargin = $realizedVariableMargin + $regulatoryPenalty;

        $minCompRatio = max(0.01, self::MIN_COMPENSATION_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedVariableMargin = $this->clampMargin($rawMargin, $minCompRatio, self::MAX_COMPENSATION_RATIO);

        // --- Standard macro event lore ---
        if ($eventType === null) {
            if ($outputGap > self::LORE_BOOM_OUTPUT_GAP && $advisoryZ > self::LORE_BOOM_Z_SCORE) {
                $eventType = ShockEvent::IB_MNA_SYNDICATION_BOOM;
            } elseif ($curveSlope > self::LORE_DCM_BOOM_CURVE && $dcmBonus > 0.05) {
                $eventType = ShockEvent::IB_DCM_UNDERWRITING_BOOM;
            } elseif ($vixEma > self::LORE_PANIC_VIX) {
                $eventType = ShockEvent::IB_PROP_TRADING_SURGE;
            } elseif ($outputGap < self::LORE_DROUGHT_OUTPUT_GAP && $advisoryZ < self::LORE_DROUGHT_Z_SCORE) {
                $eventType = ShockEvent::ADVISORY_CRASH;
            }
        }

        $primaryShockZ = $advisoryZ;
        if (abs($tradingZ) > abs($primaryShockZ)) $primaryShockZ = $tradingZ;
        if (abs($eventZ)   > abs($primaryShockZ)) $primaryShockZ = $eventZ;

        $observableShockZ = ($advisoryZ * $advisoryWeight * self::ADVISORY_ANALYST_VISIBILITY
            + $tradingZ * $tradingWeight * self::TRADING_ANALYST_VISIBILITY)
            * $baselineVol * self::REVENUE_VARIANCE_SCALAR;

        $streamZ = [
            'advisory' => $advisoryZ,
            'trading'  => $tradingZ,
            'event'    => $eventZ,
        ];

        $streamRevenue = [
            'advisory' => $advisoryRevenue,
            'trading'  => $tradingRevenue,
        ];

        if ($optionsWeight > 0.0) {
            $streamZ['options'] = $optionsZ;
            $streamRevenue['options_premium_income'] = $optionsRevenue;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedVariableMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streamZ,
            streamRevenue: $streamRevenue,
        );
    }
}
