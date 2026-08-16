<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\DTO\SectorCoverageProfile;

/**
 * Earnings strategy for Integrated Resorts & Casinos.
 *
 * Financial Physics:
 * - Ultra-Discretionary: Extremely sensitive to consumer sentiment and the wealth effect.
 * - High Operating Leverage: Massive physical infrastructure (hotels, arenas, gaming floors) creates high fixed costs.
 * - Dual-Stream Engine:
 *     1. Gaming / GGR: High margin, but subject to table hold percentage volatility ("Whale Luck").
 *     2. Non-Gaming (Hotels, F&B, Conventions): Sticky B2B convention backlog + high-margin rooms.
 * - Energy Intensive: 24/7 power, HVAC, and lighting cause energy price spikes to compress margins.
 */
class ResortsCasinosBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from Gross Gaming Revenue (GGR). */
    public const GAMING_REVENUE_WEIGHT = 0.55;
    /** Baseline fraction of revenue derived from Non-Gaming (Rooms, F&B, Entertainment, Conventions). */
    public const NON_GAMING_REVENUE_WEIGHT = 0.45;

    // --- Macro & Sentiment Physics ---
    /** Hard floor on pricing power given ultra-discretionary nature. */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.40;
    
    // --- Macro Sensitivities ---
    /** Scalar for how aggressively consumer sentiment shifts drive macro demand. */
    public const SENTIMENT_SENSITIVITY_SCALAR = 0.25;
    /** Scalar for how much variable margins compress via promotional comps when sentiment drops. */
    public const PROMOTIONAL_COMP_DRAG_SCALAR = 0.15;
    /** Sensitivity scalar for supply chain inflation cost penalties. */
    public const INFLATION_PENALTY_SCALAR = 0.80;
    /** Variable margin penalty scalar from 24/7 casino floor power, HVAC, and mega-resort utility costs during energy spikes. */
    public const ENERGY_UTILITY_DRAG_SCALAR = 0.20;

    // --- Revenue Volatility & Stream Physics ---
    /** Volatility multiplier for top-line revenue shocks reflecting gaming hold and tourism swings. */
    public const REVENUE_VARIANCE_SCALAR = 0.30;
    /** The structural intensity of non-gaming variable costs relative to gaming costs. */
    public const NON_GAMING_COST_INTENSITY = 2.0;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating a severe gaming regulatory crackdown or VIP junket ban. */
    public const GAMING_REGULATION_CRACKDOWN_Z = -2.20;
    /** Revenue haircut applied to gaming operations during regulatory crackdowns. */
    public const GAMING_REGULATION_HAIRCUT = 0.25;
    /** Positive z-score threshold indicating extraordinary VIP "Whale" luck / hold percentage surge. */
    public const WHALE_LUCK_SURGE_Z = 2.40;
    /** Top-line gaming revenue multiplier when the house holds significantly above statistical average. */
    public const WHALE_LUCK_MULT = 1.18;

    // --- Property Reinvestment & Asset Decay ---
    /** Quarterly margin decay rate per unit of underinvestment in resort remodels and attractions. */
    public const RESORT_AGING_DECAY_RATE = 0.008;
    /** Quarterly margin gain scalar per unit of mega-resort expansion and modernization. */
    public const RESORT_MODERNIZATION_GAIN_RATE = 0.005;
    /** Structural minimum operating margin floor under severe property aging. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.10;
    /** Structural maximum operating margin ceiling for flagship premier Strip resorts. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.36;

    // --- Commercial Real Estate (CRE) Physics ---
    /** Fraction of excess inflation captured by CRE lease rent escalators. */
    public const CRE_RENT_ESCALATOR_CAPTURE = 0.50;
    /** Penalty applied to CRE margins when 10Y yield rises, simulating refinancing friction on property debt. */
    public const CRE_REFINANCING_WALL_DRAG  = 0.15;
    /** Fallback safe 10Y yield before refinancing drag kicks in. */
    public const DEFAULT_10Y_YIELD_FALLBACK = 0.04;

    public function getModelThresholds(): array
    {
        $thresholds = parent::getModelThresholds();
        $thresholds['moat_spread'] = 0.015; // Gaming licenses and prime locations create moat
        $thresholds['reversion_speed'] = 0.15; // Moderately fast reversion due to travel cycles
        $thresholds['nwc_intensity'] = 0.02; // Cash-heavy operations, minimal working capital delay
        $thresholds['capex_completion_rate'] = 0.15; // Multi-year mega-resort development cycles
        return $thresholds;
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.03; // ~3% secular growth aligned with global GDP and leisure travel
    }

    public function getCapexCyclicality(): float
    {
        return 2.5; // High CapEx required for ongoing room renovations and new property builds
    }

    public function getCoverageProfile(\App\Entity\Stock $stock): \App\DTO\SectorCoverageProfile
    {
        // Monthly Gaming Control Board reports (Nevada / Macau) give high base visibility (~70%).
        // Regulatory crackdowns and travel restrictions are public events.
        return new SectorCoverageProfile(
            baseVisibility: 0.70,
            errorStdDev: 0.06,
            minVisibility: 0.40,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.75
        );
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Highly elastic to consumer sentiment and leisure travel budgets
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $beta = (float) $stock->getBeta();

        // Combine GDP output gap and Consumer Sentiment shift
        $physics['macro_demand_shift'] += $sentimentShift * $beta * self::SENTIMENT_SENSITIVITY_SCALAR;

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
            ModelParam::PricingPowerIndex->value             => self::MIN_BETA_PRICING_POWER_FLOOR,
            ModelParam::GamingRevenueWeight->value           => self::GAMING_REVENUE_WEIGHT,
            ModelParam::NonGamingRevenueWeight->value       => self::NON_GAMING_REVENUE_WEIGHT,
            ModelParam::CommercialRealEstateWeight->value   => 0.00,
        ]);

        $rawCreWeight = $params[ModelParam::CommercialRealEstateWeight];
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        $targetWeights = [
            'gaming'     => $params[ModelParam::GamingRevenueWeight],
            'non_gaming' => $params[ModelParam::NonGamingRevenueWeight],
        ];
        if ($rawCreWeight > 0.0) {
            $targetWeights['cre'] = $rawCreWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $gamingWeight    = $activeWeights['gaming'];
        $nonGamingWeight = $activeWeights['non_gaming'];
        $creWeight       = $activeWeights['cre'] ?? 0.0;

        // Independent stream Z-scores with AR(1) persistence
        $gamingZ    = $streams->generateZ('gaming', 0.15); // Table hold / win volatility (i.i.d. luck)
        $nonGamingZ = $streams->generateZ('non_gaming', 0.35); // Hotel occupancy & convention backlog
        $eventZ     = $streams->generateZ('event', 0.10);

        // --- Tail Risk Events ---
        $whaleMultiplier = 1.0;
        $gamingHaircut = 1.0;
        $eventType = null;

        if ($eventZ < self::GAMING_REGULATION_CRACKDOWN_Z) {
            $gamingHaircut = (1.0 - self::GAMING_REGULATION_HAIRCUT);
            $eventType = ShockEvent::REGULATORY_FINE; // Proxy for gaming regulatory crackdown
        } elseif ($eventZ > self::WHALE_LUCK_SURGE_Z) {
            $whaleMultiplier = self::WHALE_LUCK_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH; // Proxy for landmark high-roller hold quarter
        }

        // --- Dual-Stream Revenue Calculation ---
        // (Macro Sentiment Volume Shock is now handled globally in getMacroPhysics to avoid double-dipping)
        $gamingRevenue = max(0.0, $expectedRevenue * $gamingWeight)
            * (1.0 + ($gamingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)))
            * $whaleMultiplier * $gamingHaircut;

        $nonGamingRevenue = max(0.0, $expectedRevenue * $nonGamingWeight)
            * (1.0 + ($nonGamingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.5)));

        $streamRevenues = [
            'gaming'     => $gamingRevenue,
            'non_gaming' => $nonGamingRevenue,
        ];

        $creRevenue = 0.0;
        $creZ = 0.0;
        $creVacancyShock = 0.0;
        $creRefinancingDrag = 0.0;

        if ($creWeight > 0.0) {
            $creZ = $streams->generateZ('cre', 0.50); // Real estate leases are highly persistent

            // 1. GDP output gap affects commercial real estate leasing demand
            $creDemandShock = $macroState->outputGap * abs((float) $stock->getBeta()) * 0.5;

            // 2. CPI Rent Escalators (Inflation Hedge)
            $excessInflation = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
            $rentEscalator = $excessInflation * self::CRE_RENT_ESCALATOR_CAPTURE;

            $creRevenue = max(0.0, $expectedRevenue * $creWeight * (1.0 + ($creZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.2) + $creDemandShock + $rentEscalator));
            $streamRevenues['cre'] = $creRevenue;

            // 3. Refinancing Drag on CRE debt (10Y Yield sensitivity)
            $yield10y = $macroState->yield10yEma;
            $creRefinancingDrag = max(0.0, ($yield10y - self::DEFAULT_10Y_YIELD_FALLBACK) * self::CRE_REFINANCING_WALL_DRAG);

            // 4. Vacancy Shock (if creZ drops too low, tenants default/leave)
            if ($creZ < -1.5) {
                $creVacancyShock = abs($creZ) * 0.05; // 5% margin hit per Z-score of distress
            }
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics ---
        // 1. Inflation Penalty (F&B and labor costs)
        $inflation = $macroState->inflationEma;
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);
        $baseInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR
            : 0.0;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // 2. Promotional Comps Drag (Margin compression during weak consumer sentiment)
        // When sentiment drops below baseline, casinos aggressively increase comps (free rooms, F&B) to drive foot traffic.
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $promotionalDrag = $sentimentShift < 0.0 ? abs($sentimentShift) * abs((float) $stock->getBeta()) * self::PROMOTIONAL_COMP_DRAG_SCALAR : 0.0;

        // 3. Energy & Utility Cost Drag (24/7 power, HVAC, lighting)
        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);
        $energyDrag = $energyShift * self::ENERGY_UTILITY_DRAG_SCALAR;

        // 4. Structural Margin Blending
        // Gaming is high margin (low variable cost). Non-Gaming (F&B, Hotel, Leases) has higher variable cost.
        // We dynamically scale their costs against the total realizedVariableMargin to guarantee neither is ever negative.
        // CRE is assumed to have very low variable costs (similar to gaming)
        $baseGamingMargin = $realizedVariableMargin / ($gamingWeight + (self::NON_GAMING_COST_INTENSITY * $nonGamingWeight) + $creWeight);

        $gamingVariableMargin = $baseGamingMargin + $promotionalDrag;
        $nonGamingVariableMargin = ($baseGamingMargin * self::NON_GAMING_COST_INTENSITY) + $promotionalDrag;

        // CRE is unaffected by casino promotions, but faces vacancy and refinancing drags
        $creVariableMargin = $baseGamingMargin + $creVacancyShock + $creRefinancingDrag;

        $actualVariableCosts = ($nonGamingRevenue * $nonGamingVariableMargin) + ($gamingRevenue * $gamingVariableMargin) + ($creRevenue * $creVariableMargin);

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $inflationPenalty + $energyDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock Z selection
        $primaryShockZ = abs($gamingZ) > abs($nonGamingZ) ? $gamingZ : $nonGamingZ;
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        $observableShockZ = ($gamingZ * $gamingWeight + $nonGamingZ * $nonGamingWeight) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues
        );
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Resort aging, room fatigue, and loss of attraction appeal
            $decayRate = self::RESORT_AGING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // New flagship attractions, casino floor expansion, and room remodels
            $modGain = self::RESORT_MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
