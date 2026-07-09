<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Consumer Staples (Food, Tobacco, Household Goods).
 * 
 * Financial Physics:
 * - Inelastic demand: Consumers must buy these products regardless of the economic cycle.
 * - High pricing power: They can pass supply chain inflation directly to consumers without losing sales volume.
 * - Extremely low top-line volatility compared to discretionary retail.
 */
class ConsumerStaplesBusinessModel extends StandardCorporateBusinessModel
{
    // --- Inelastic Demand & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in stable consumer staples models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.05;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Product Recall & Regulatory Lore Thresholds ---
    /** Negative z-score threshold indicating severe supply chain contamination and massive product recall. */
    public const RECALL_SEVERE_Z_SCORE     = -2.50;
    /** Variable cost penalty applied during massive product recalls and inventory write-offs. */
    public const RECALL_SEVERE_PENALTY     = 0.08;
    /** Negative z-score threshold indicating sudden health regulatory scrutiny and fines. */
    public const RECALL_MODERATE_Z_SCORE   = -2.00;
    /** Variable cost penalty applied during moderate regulatory fines and legal fees. */
    public const RECALL_MODERATE_PENALTY   = 0.03;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into routine top-line consumer staples revenue shocks. */
    public const REV_VISIBILITY_BASE       = 0.20;
    /** Standard deviation of analyst estimation error for quarterly retail revenue shocks. */
    public const REV_ERROR_STD_DEV         = 0.05;
    /** Base analyst visibility into public product recalls and health regulatory scrutiny. */
    public const RECALL_VISIBILITY_BASE    = 0.80;
    /** Standard deviation of analyst estimation error for product recall financial impacts. */
    public const RECALL_ERROR_STD_DEV      = 0.10;

    // --- Retail Competition Moat ---
    /** Operating margin mean reversion speed: fast speed reflects intense retail price competition. */
    public const STAPLES_REVERSION_SPEED   = 5.0;

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Inelastic Demand:
        // Top-line variance is heavily muted because people always buy groceries and tobacco.
        // Volatility impact is sliced to just 5% of standard variance.
        $revenueShock = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR); 
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Absolute Pricing Power:
        // Unlike standard corporates, consumer staples do not suffer an inflation penalty.
        // They simply raise prices at the grocery store, preserving their margins perfectly.
        
        // Tail Risk: Product Recalls and Health Regulations
        $eventZ = $mathUtility->generateStandardNormal();
        $eventLore = null;
        $recallPenalty = 0.0;
        
        if ($eventZ < self::RECALL_SEVERE_Z_SCORE) {
            $recallPenalty = self::RECALL_SEVERE_PENALTY; // 8% margin hit for massive product recall and write-offs
            $eventLore = "Suffered a massive product recall due to severe supply chain contamination.";
        } elseif ($eventZ < self::RECALL_MODERATE_Z_SCORE) {
            $recallPenalty = self::RECALL_MODERATE_PENALTY; // 3% margin hit for fines and legal fees
            $eventLore = "Faced sudden regulatory scrutiny and fines over product health concerns.";
        }

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $recallPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Recalls and regulatory fines are massive public news events (~80% visibility).
        $revenueAnalystError = $mathUtility->generateStandardNormal() * self::REV_ERROR_STD_DEV;
        $revenueVisibility = min(1.0, max(0.0, self::REV_VISIBILITY_BASE + $revenueAnalystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + ($revenueShock * $revenueVisibility));
        
        $recallAnalystError = $mathUtility->generateStandardNormal() * self::RECALL_ERROR_STD_DEV;
        $recallVisibility = min(1.0, max(0.0, self::RECALL_VISIBILITY_BASE + $recallAnalystError));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + ($recallPenalty * $recallVisibility)));

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'analyst_expected_revenue' => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit' => $ebit, 
            'primary_shock_z' => abs($eventZ) > abs($revenueZ) ? $eventZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        return self::STAPLES_REVERSION_SPEED; // High retail competition and consumer price sensitivity cause rapid margin mean reversion
    }
}

