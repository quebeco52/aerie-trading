<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;

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
    public function getMacroPhysics(Stock $stock, array $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        // Tech companies have massive structural operating leverage. 
        // The marginal cost of adding an additional software user is practically zero.
        $physics['operating_leverage_rate'] = FinancialConstants::TECH_OPERATING_LEVERAGE;
        
        return $physics;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Tech revenues are inherently more volatile (rapid scaling / user churn cycles)
        // We increase the variance multiplier from 15% (standard) to 20%
        $revenueShock = $revenueZ * ($baselineVol * 0.20);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Supply Chain Immunity vs. Talent Inflation:
        // Tech companies don't buy steel or oil, they pay for engineers and cloud compute.
        // We dramatically reduce the standard supply chain inflation penalty, kicking in only at very high inflation.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $wageInflationPenalty = $inflation > 0.04 ? ($inflation - 0.04) * abs((float) $stock->getBeta()) * 0.5 : 0.0;
        
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

        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin + $wageInflationPenalty + $regulatoryShock));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            // Pass whichever Z-Score was more extreme so Volatility logic scales accordingly
            'primary_shock_z' => abs($eventZ) > abs($revenueZ) ? $eventZ : $revenueZ,
            'event_type' => $eventType
        ];
    }
}