<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Financial Data & Stock Exchanges (Rating Agencies, Market Data).
 * 
 * Financial Physics:
 * - Asset-light, ultra-high margin monopolies.
 * - Revenue is incredibly sticky due to mandatory recurring subscriptions and licensing fees.
 * - Immune to supply chain inflation (they sell digital data, not physical goods).
 * - Exceptionally low idiosyncratic variance.
 */
class FinancialDataBusinessModel extends StandardCorporateBusinessModel
{
    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in subscription data models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.05;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into recurring subscription revenues prior to quarterly earnings. */
    public const ANALYST_BASE_VISIBILITY   = 0.80;
    /** Standard deviation of analyst estimation error for subscription additions and churn. */
    public const ANALYST_ERROR_STD_DEV     = 0.10;

    // --- Monopoly Valuation Moat ---
    /** Operating margin mean reversion speed: slower speed reflects high switching costs and data monopoly moat. */
    public const MONOPOLY_REVERSION_SPEED  = 2.0;

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Subscription Stickiness:
        // Revenue variance is drastically reduced because institutions are locked into multi-year data contracts.
        // Drops the volatility impact to just 5% of standard variance.
        $revenueShock = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Pricing Power (Immunity to Inflation):
        // Unlike physical corporates, data monopolies have zero supply chain costs.
        // They pass inflation directly to consumers without margin compression, so we omit the inflation penalty entirely.
        
        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Financial Data subscriptions are highly visible via quarterly subscriber count reporting (~80% visibility).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + ($revenueShock * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin));

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'analyst_expected_revenue' => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        return self::MONOPOLY_REVERSION_SPEED; // High switching costs and data monopoly moat
    }
}