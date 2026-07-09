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
    // --- Dual-Stream Financial Data Architecture ---
    /** Baseline fraction of revenue derived from recurring multi-year terminal & rating subscriptions. */
    public const SUBSCRIPTION_REVENUE_WEIGHT = 0.85;
    /** Baseline fraction of revenue derived from capital markets issuance ratings & transaction feed volume. */
    public const TRANSACTION_REVENUE_WEIGHT  = 0.15;

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
        $params = $this->resolveModelParameters($stock, [
            'subscription_revenue_weight' => self::SUBSCRIPTION_REVENUE_WEIGHT,
            'transaction_revenue_weight'  => self::TRANSACTION_REVENUE_WEIGHT,
        ]);

        $subscriptionWeight = $params['subscription_revenue_weight'];
        $transactionWeight  = $params['transaction_revenue_weight'];

        // Independent stream Z-scores
        $subscriptionZ = $mathUtility->generateStandardNormal(); // Recurring seat subscriptions & data licenses
        $transactionZ  = $mathUtility->generateStandardNormal(); // Debt issuance credit rating mandates & API usage

        $subscriptionRevenue = $expectedRevenue * $subscriptionWeight * (1.0 + ($subscriptionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $transactionRevenue  = $expectedRevenue * $transactionWeight * (1.0 + ($transactionZ * ($baselineVol * (self::REVENUE_VARIANCE_SCALAR * 5.0))));
        $actualRevenue       = max(0.0, $subscriptionRevenue + $transactionRevenue);

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Financial Data subscriptions are highly visible via quarterly subscriber count reporting (~80% visibility).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + (($subscriptionZ * $subscriptionWeight + $transactionZ * $transactionWeight) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR) * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin));

        $primaryShockZ = abs($transactionZ) > abs($subscriptionZ) ? $transactionZ : $subscriptionZ;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $ebit,
            'primary_shock_z'                 => $primaryShockZ
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        return self::MONOPOLY_REVERSION_SPEED; // High switching costs and data monopoly moat
    }
}