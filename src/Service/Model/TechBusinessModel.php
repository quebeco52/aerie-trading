<?php

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

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Tech revenues are inherently more volatile (rapid scaling / user churn cycles)
        // We increase the variance multiplier from 15% (standard) to 20%
        $revenueShock = $revenueZ * ($baselineVol * 0.20);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Supply Chain Immunity vs. Talent Inflation:
        // Tech companies don't buy steel or oil, they pay for engineers and cloud compute.
        // We dramatically reduce the standard supply chain inflation penalty, kicking in only at very high inflation.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        // Tech is immune to standard supply chain inflation, so penalty only kicks in at 2x Target Inflation
        $wageInflationThreshold = MacroEngine::TARGET_INFLATION * 2.0;
        $wageInflationPenalty = $inflation > $wageInflationThreshold ? ($inflation - $wageInflationThreshold) * abs((float) $stock->getBeta()) * 0.5 : 0.0;
        
        // Fat Tail Risk: Data Breaches, Anti-Trust, and Viral Breakthroughs
        $eventZ = $mathUtility->generateStandardNormal();
        $regulatoryShock = 0.0;
        $eventType = null;
        
        if ($eventZ < -2.5) {
            $regulatoryShock = 0.15; // Massive fixed cost fine / margin hit
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ < -2.0) {
            $regulatoryShock = 0.05;
            $eventType = ShockEvent::SEVERE_CHURN;
        } elseif ($eventZ > 2.5) {
            $actualRevenue *= 1.10; // 10% instant revenue bump
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin + $wageInflationPenalty + $regulatoryShock));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Tech usage/engagement data is partially public via 3rd party trackers (~20% visibility).
        // Fat tail regulatory events are mostly surprises.
        $analystError = $mathUtility->generateStandardNormal() * 0.05;
        $dynamicVisibility = min(1.0, max(0.0, 0.20 + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + ($revenueShock * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(1.50, max(0.01, $realizedVariableMargin + $wageInflationPenalty));

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
        return 5.0; // Rapid innovation cycles and intense technological competition erode excess margins quickly
    }
}