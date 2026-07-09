<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Technology & Software companies.
 * 
 * Financial Physics:
 * - Asset-light, highly scalable revenues. Marginal cost of a new user is near zero.
 * - Mostly immune to physical supply chain inflation, but exposed to wage inflation.
 * - Higher inherent top-line volatility (viral growth or sudden user churn).
 * - Vulnerable to "fat left-tail" risks like regulatory anti-trust fines or data breaches.
 */
class TechBusinessModel extends StandardCorporateBusinessModel
{
    // --- Revenue Volatility & Wage Inflation Rails ---
    /** Volatility multiplier for top-line revenue shocks reflecting rapid software user scaling and churn. */
    public const REVENUE_VARIANCE_SCALAR   = 0.20;
    /** Multiplier scaling target inflation to establish wage inflation threshold buffer. */
    public const WAGE_INFLATION_THRESHOLD  = 2.00;
    /** Multiplier scaling excess wage inflation with stock beta to compute variable cost penalty. */
    public const WAGE_INFLATION_SCALAR     = 0.50;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Fat Tail Event Lore & Shock Thresholds ---
    /** Negative z-score threshold indicating major regulatory anti-trust fines or data breaches. */
    public const REGULATORY_FINE_Z_SCORE   = -2.50;
    /** Variable cost penalty applied during severe regulatory fines and legal compliance mandates. */
    public const REGULATORY_FINE_PENALTY   = 0.15;
    /** Negative z-score threshold indicating severe user churn and platform defection. */
    public const SEVERE_CHURN_Z_SCORE      = -2.00;
    /** Variable cost penalty applied during severe customer churn events. */
    public const SEVERE_CHURN_PENALTY      = 0.05;
    /** Positive z-score threshold indicating breakthrough viral user adoption and network effects. */
    public const VIRAL_GROWTH_Z_SCORE      = 2.50;
    /** Top-line revenue multiplier applied during breakthrough viral user growth. */
    public const VIRAL_GROWTH_REV_MULT     = 1.10;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into user engagement and app store download metrics. */
    public const ANALYST_BASE_VISIBILITY   = 0.20;
    /** Standard deviation of analyst estimation error for quarterly tech revenues. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;

    // --- Innovation Competition Moat & Capital Structure ---
    /** Operating margin mean reversion speed: fast speed reflects rapid technological disruption and competition. */
    public const TECH_REVERSION_SPEED      = 5.0;
    /** Minimum WACC arbitrage spread required before under-leveraged recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD  = 0.03;
    /** Minimum interest coverage ratio required to permit recapitalization for intangible asset software models. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Tech revenues are inherently more volatile (rapid scaling / user churn cycles)
        // We increase the variance multiplier from 15% (standard) to 20%
        $revenueShock = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Supply Chain Immunity vs. Talent Inflation:
        // Tech companies don't buy steel or oil, they pay for engineers and cloud compute.
        // We dramatically reduce the standard supply chain inflation penalty, kicking in only at very high inflation.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        // Tech is immune to standard supply chain inflation, so penalty only kicks in at 2x Target Inflation
        $wageInflationThreshold = MacroEngine::TARGET_INFLATION * self::WAGE_INFLATION_THRESHOLD;
        $wageInflationPenalty = $inflation > $wageInflationThreshold ? ($inflation - $wageInflationThreshold) * abs((float) $stock->getBeta()) * self::WAGE_INFLATION_SCALAR : 0.0;
        
        // Fat Tail Risk: Data Breaches, Anti-Trust, and Viral Breakthroughs
        $eventZ = $mathUtility->generateStandardNormal();
        $regulatoryShock = 0.0;
        $eventType = null;
        
        if ($eventZ < self::REGULATORY_FINE_Z_SCORE) {
            $regulatoryShock = self::REGULATORY_FINE_PENALTY; // Massive fixed cost fine / margin hit
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ < self::SEVERE_CHURN_Z_SCORE) {
            $regulatoryShock = self::SEVERE_CHURN_PENALTY;
            $eventType = ShockEvent::SEVERE_CHURN;
        } elseif ($eventZ > self::VIRAL_GROWTH_Z_SCORE) {
            $actualRevenue *= self::VIRAL_GROWTH_REV_MULT; // 10% instant revenue bump
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $wageInflationPenalty + $regulatoryShock));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Tech usage/engagement data is partially public via 3rd party trackers (~20% visibility).
        // Fat tail regulatory events are mostly surprises.
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + ($revenueShock * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $wageInflationPenalty));

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'analyst_expected_revenue' => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit' => $ebit, 
            'primary_shock_z' => abs($eventZ) > abs($revenueZ) ? $eventZ : $revenueZ,
            'event_type' => $eventType
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        return self::TECH_REVERSION_SPEED; // Rapid innovation cycles and intense technological competition erode excess margins quickly
    }

    public function isUnderLeveraged(bool $isFinancial, float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // Intangible asset-heavy businesses (Tech) have a natural optimal capital structure near zero debt
        // due to high financial distress costs. They should only recapitalize under extreme WACC arbitrage
        // (Ke > Kd + 3.0%) and extraordinary cash flow safety (ICR > 15.0).
        if ($costOfEquity <= ($effectiveCostOfDebt + self::WACC_ARBITRAGE_THRESHOLD)) {
            return false;
        }
        if ($interestCoverage < self::MIN_RECAP_ICR_FLOOR) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * self::UNDERLEVERAGED_DEBT_RATIO);
    }
}