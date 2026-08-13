<?php declare(strict_types=1);

namespace App\Service\Model;

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
    /** Multiplier scaling consumer sentiment sensitivity (casinos are hyper-elastic). */
    public const SENTIMENT_SENSITIVITY_SCALAR = 2.00;
    /** Margin drag when casinos increase promotional comps (free rooms/food) to attract weak consumers. */
    public const PROMOTIONAL_COMP_DRAG_SCALAR = 0.15;
    /** Sensitivity scalar for supply chain inflation cost penalties. */
    public const INFLATION_PENALTY_SCALAR = 0.80;

    // --- Revenue Volatility & Stream Physics ---
    /** Volatility multiplier for top-line revenue shocks reflecting gaming hold and tourism swings. */
    public const REVENUE_VARIANCE_SCALAR = 0.30;
    /** Multiplier indicating how much more expensive non-gaming variable costs are compared to gaming. */
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
    public const RESORT_AGING_DECAY_RATE = 0.022;
    /** Quarterly margin gain scalar per unit of mega-resort expansion and modernization. */
    public const RESORT_MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under severe property aging. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.06;
    /** Structural maximum operating margin ceiling for flagship premier Strip resorts. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.36;

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

    public function getCoverageProfile(): SectorCoverageProfile
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
            'pricing_power_index' => self::MIN_BETA_PRICING_POWER_FLOOR,
            'gaming_revenue_weight' => self::GAMING_REVENUE_WEIGHT,
            'non_gaming_revenue_weight' => self::NON_GAMING_REVENUE_WEIGHT,
        ]);

        $gamingWeight = $params['gaming_revenue_weight'];
        $nonGamingWeight = $params['non_gaming_revenue_weight'];
        $pricingPower = max(0.0, min(1.0, $params['pricing_power_index']));

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores with AR(1) persistence
        $gamingZ = $mathUtility->generatePersistentZ($momentum['gaming'] ?? 0.0, 0.15); // Table hold / win volatility (i.i.d. luck)
        $nonGamingZ = $mathUtility->generatePersistentZ($momentum['non_gaming'] ?? 0.0, 0.35); // Hotel occupancy & convention backlog
        $eventZ = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.10);

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
        $gamingRevenue = $expectedRevenue * $gamingWeight 
            * (1.0 + ($gamingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))) 
            * $whaleMultiplier * $gamingHaircut;

        $nonGamingRevenue = $expectedRevenue * $nonGamingWeight 
            * (1.0 + ($nonGamingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.5)));

        $actualRevenue = max(0.0, $gamingRevenue + $nonGamingRevenue);

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

        // 3. Structural Margin Blending
        // Gaming is high margin (low variable cost). Non-Gaming (F&B, Hotel, Leases) has higher variable cost.
        // We dynamically scale their costs against the total realizedVariableMargin to guarantee neither is ever negative.
        $gamingVariableMargin = $realizedVariableMargin / ($gamingWeight + (self::NON_GAMING_COST_INTENSITY * $nonGamingWeight));
        $nonGamingVariableMargin = $gamingVariableMargin * self::NON_GAMING_COST_INTENSITY;

        $actualVariableCosts = ($nonGamingRevenue * $nonGamingVariableMargin) + ($gamingRevenue * $gamingVariableMargin);

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $inflationPenalty + $promotionalDrag;
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
            streamZ: [
                'gaming' => $gamingZ,
                'non_gaming' => $nonGamingZ,
                'event' => $eventZ,
            ],
            streamRevenue: [
                'Gaming (GGR)' => $gamingRevenue,
                'Non-Gaming (Hotels/F&B)' => $nonGamingRevenue,
            ]
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
