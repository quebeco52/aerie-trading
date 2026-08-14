<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Telecommunications (Wireless, Broadband, Fiber).
 * 
 * Financial Physics:
 * - High Barrier to Entry / Oligopoly: Revenue is dominated by ultra-sticky recurring subscriptions.
 * - The Churn Wars: Because the market is saturated, growth only comes from stealing competitors' customers via margin-crushing promotions.
 * - Bond Proxies: Astronomical debt loads make their net margins highly sensitive to the 10-year Treasury yield.
 * - Generational CapEx: Every ~10 years, they are forced to participate in massive government spectrum auctions (e.g., 5G), draining free cash flow.
 */
class TelecomBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50; // Subscription metrics are reported quarterly
    public const BASE_COVERAGE_ERROR = 0.05;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.05, 'capex_completion_rate' => 0.25];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02;
    } // Mature, saturated market
    public function getCapexCyclicality(): float
    {
        return 1.8;
    } // Lumpy spectrum and fiber rollout cycles
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from recurring network subscriptions (Wireless, Broadband). */
    public const SUBSCRIPTION_WEIGHT = 0.85;
    /** Baseline fraction of revenue derived from hardware (Smartphones, Routers) and B2B enterprise setups. */
    public const EQUIPMENT_WEIGHT    = 0.15;

    // --- Physics & Variances ---
    public const SUBSCRIPTION_VARIANCE_SCALAR = 0.05; // Highly defensive, sticky contracts
    public const EQUIPMENT_VARIANCE_SCALAR    = 0.30; // Highly cyclical (hardware upgrades get delayed in recessions)

    // --- The Churn & Price War Physics ---
    /** Continuous margin elasticity: High churn/customer acquisition costs directly erode variable margins. */
    public const SUBSCRIBER_ACQUISITION_COST_ELASTICITY = 0.025;
    /** Z-score threshold indicating a brutal sector-wide price war to steal market share. */
    public const PRICE_WAR_Z_SCORE   = -2.00;
    /** Fat-tail margin penalty when intense promotional pricing (e.g., "Free iPhones") destroys profitability. */
    public const PRICE_WAR_PENALTY   = 0.08;

    // --- Generational CapEx / Spectrum Tail Risks ---
    /** Z-score threshold indicating a massive government spectrum auction or generational infrastructure leap. */
    public const SPECTRUM_AUCTION_Z_SCORE = 2.20;

    // --- The Refinancing Wall (Bond Proxy) ---
    /** Sensitivity of telecom margins to 10Y Treasury yields (debt refinancing friction). */
    public const REFINANCING_WALL_DRAG      = 0.20;
    /** Fallback safe 10Y yield before refinancing drag kicks in. */
    public const DEFAULT_10Y_YIELD_FALLBACK = 0.04;

    // --- Asset Depreciation & Reinvestment ---
    public const NETWORK_DECAY_RATE           = 0.025; // 3G/4G becomes obsolete without continuous fiber/5G upgrades
    public const NETWORK_MODERNIZATION_RATE   = 0.010;
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.05;
    public const MAX_OPERATING_MARGIN_CEILING = 0.35;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift. We will apply macro deviations discretely per-stream.
        // People don't cancel their phone plans during a recession, but they do stop buying $1,200 iPhones.
        $physics['macro_demand_shift'] = 0.0;

        // Telecoms generally pass inflation through via annual bill hikes, 
        // but face mild regulatory/consumer pushback.
        $inflation = $macroState->inflationEma;
        $physics['pricing_power_multiplier'] = 1.0 + ($inflation * 0.50);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'subscription_weight' => self::SUBSCRIPTION_WEIGHT,
            'equipment_weight'    => self::EQUIPMENT_WEIGHT,
        ]);

        $subscriptionWeight = $params['subscription_weight'];
        $equipmentWeight    = $params['equipment_weight'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // Independent stream Z-scores
        $subscriptionZ = $streams->generateZ('wireless_subscriptions', 0.40); // High persistence
        $equipmentZ    = $streams->generateZ('equipment_sales', 0.15); // Low persistence, driven by hardware cycles
        $eventZ        = $streams->generateZ('event', 0.10);

        // --- Macro Demand Sensitivities ---
        // Subscriptions are highly defensive (0.2x multiplier). Equipment is highly cyclical (1.5x multiplier).
        $macroDefensiveShift = $macroState->outputGapEma * 0.2 * $beta;
        $macroCyclicalShift  = $macroState->outputGapEma * 1.5 * $beta;

        // --- Event Tail Risks ---
        $eventType = null;
        $priceWarPenalty = 0.0;

        if ($eventZ < self::PRICE_WAR_Z_SCORE) {
            $eventType = ShockEvent::TELECOM_PRICE_WAR ?? 'price_war';
            $priceWarPenalty = self::PRICE_WAR_PENALTY;
        } elseif ($eventZ > self::SPECTRUM_AUCTION_Z_SCORE) {
            $eventType = ShockEvent::SPECTRUM_AUCTION ?? 'spectrum_auction';
            // Note: A spectrum auction is a CapEx event, not a revenue event. 
            // It will naturally trigger massive FCF burn via the Engine's CapEx logic later.
        }

        // --- Clamped Dual-Stream Revenue Calculation ---
        $subscriptionRevenue = max(0.0, $expectedRevenue * $subscriptionWeight * (1.0 + ($subscriptionZ * $baselineVol * self::SUBSCRIPTION_VARIANCE_SCALAR) + $macroDefensiveShift));
        $equipmentRevenue    = max(0.0, $expectedRevenue * $equipmentWeight * (1.0 + ($equipmentZ * $baselineVol * self::EQUIPMENT_VARIANCE_SCALAR) + $macroCyclicalShift));

        $actualRevenue = $subscriptionRevenue + $equipmentRevenue;

        // --- Cost & Margin Physics ---

        // 1. Subscriber Acquisition Cost (SAC) Elasticity
        // If $subscriptionZ is negative, the telecom is experiencing churn. To stop the bleeding, 
        // they must spend heavily on marketing and subsidized phones, structurally compressing margins.
        $sacDrag = -self::SUBSCRIBER_ACQUISITION_COST_ELASTICITY * $subscriptionZ * $subscriptionWeight;

        // 2. The Refinancing Wall (Bond Proxy)
        // Telecoms carry massive debt to fund network infrastructure. Rising 10Y yields erode their profitability.
        $yield10y = $macroState->yield10yEma;
        $refinancingDrag = max(0.0, ($yield10y - self::DEFAULT_10Y_YIELD_FALLBACK) * self::REFINANCING_WALL_DRAG);

        // Apply structurally driven penalties directly to the baseline margin
        $rawMargin = $realizedVariableMargin - $priceWarPenalty + $sacDrag - $refinancingDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = abs($equipmentZ) > abs($subscriptionZ) ? $equipmentZ : $subscriptionZ;
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // Subscriber additions/churn are heavily tracked by analysts, equipment sales are standard retail visibility.
        $observableShockZ = ($subscriptionZ * $subscriptionWeight * self::SUBSCRIPTION_VARIANCE_SCALAR * 0.90) +
            ($equipmentZ * $equipmentWeight * self::EQUIPMENT_VARIANCE_SCALAR * 0.40);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'wireless_subscriptions' => $subscriptionRevenue,
                'equipment_sales'        => $equipmentRevenue,
            ],
        );
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Network aging (e.g., failing to keep up with 5G/fiber deployment) leads to higher churn and margin decay
            $decayRate = self::NETWORK_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Fiber-to-the-home and 5G modernization expands pricing power and capacity ceilings
            $modGain = self::NETWORK_MODERNIZATION_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
