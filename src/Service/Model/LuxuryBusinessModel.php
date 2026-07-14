<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Luxury Goods & Elite Brand Conglomerates.
 * 
 * Financial Physics:
 * - Veblen Pricing Power: Complete immunity to supply chain inflation penalties. When inflation rises, luxury brands hike prices aggressively without losing sales volume, expanding operating margins.
 * - Ultra-High Gross Margins: Brand equity allows pricing far above physical cost of goods sold.
 * - Bifurcated Demand Physics: Resilient to middle-class consumer recessions, but exposed to severe global liquidity freezes or wealth tax shocks among ultra-high-net-worth individuals.
 */
class LuxuryBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Luxury Brand Architecture ---
    /** Baseline fraction of revenue derived from ultra-high-net-worth Maison leather goods and haute couture. */
    public const HAUTE_COUTURE_WEIGHT     = 0.45;
    /** Baseline fraction of revenue derived from accessible luxury (perfume, eyewear, cosmetics, accessories). */
    public const ACCESSIBLE_LUXURY_WEIGHT = 0.55;

    // --- Veblen Pricing & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to global output gaps for elite luxury goods. */
    public const MACRO_DEMAND_SCALAR       = 0.70;
    /** Multiplier scaling excess inflation into Veblen pricing power bonuses. */
    public const VEBLEN_INFLATION_SCALAR   = 1.50;
    /** Variable margin improvement scalar capturing aggressive Veblen price hikes during inflation. */
    public const VEBLEN_MARGIN_BENEFIT     = 0.30;

    // --- Brand Lore & Shock Thresholds ---
    /** Volatility multiplier for top-line revenue shocks in resilient luxury conglomerates. */
    public const REVENUE_VARIANCE_SCALAR   = 0.12;
    /** Negative z-score threshold indicating creative direction failure and brand dilution. */
    public const BRAND_DILUTION_Z_SCORE    = -2.40;
    /** Variable margin penalty applied during inventory write-downs and brand dilution. */
    public const BRAND_DILUTION_PENALTY    = 0.06;
    /** Positive z-score threshold indicating a culturally dominant fashion super-cycle. */
    public const BRAND_BOOM_Z_SCORE        = 2.40;
    /** Top-line revenue multiplier applied during iconic viral fashion collections. */
    public const BRAND_BOOM_REV_MULT       = 1.15;
    /** Variable margin bonus applied during viral luxury collection sell-outs. */
    public const BRAND_BOOM_MARGIN_BONUS   = -0.04;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Brand Equity Moat ---
    /** Operating margin mean reversion speed: slower speed reflects sticky multi-decade brand equity. */
    public const LUXURY_REVERSION_SPEED    = 2.0;

    // --- Veblen Brand Cachet & Boutique Reinvestment Physics ---
    /** Variable margin sensitivity to haute couture brand desirability and Veblen pricing cachet. */
    public const BRAND_CACHET_ELASTICITY   = 0.018;
    /** Quarterly margin decay rate per unit of underinvestment below boutique craftsmanship replacement. */
    public const BOUTIQUE_CRAFT_DECAY_RATE    = 0.018;
    /** Quarterly margin gain scalar per unit of heritage exclusivity overinvestment. */
    public const HERITAGE_EXCLUSIVITY_GAIN_RATE = 0.009;
    /** Structural minimum operating margin floor under accessible apparel dilution. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.15;
    /** Structural maximum operating margin ceiling for ultra-exclusive Veblen leather monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.45;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $macroState->outputGapEma;
        $inflation = $macroState->inflationEma;
        $beta = (float) $stock->getBeta();

        // Luxury goods benefit from Veblen pricing power during inflation
        $inflationBonus = $inflation > MacroEngine::TARGET_INFLATION ? ($inflation - MacroEngine::TARGET_INFLATION) * self::VEBLEN_INFLATION_SCALAR : 0.0;

        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR,
            'pricing_power_multiplier' => 1.0 + $inflationBonus,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'haute_couture_weight'     => self::HAUTE_COUTURE_WEIGHT,
            'accessible_luxury_weight' => self::ACCESSIBLE_LUXURY_WEIGHT,
        ]);

        $hauteWeight      = $params['haute_couture_weight'];
        $accessibleWeight = $params['accessible_luxury_weight'];

        // Independent stream Z-scores
        $hauteZ      = $mathUtility->generateStandardNormal(); // UHNW leather goods / couture demand
        $accessibleZ = $mathUtility->generateStandardNormal(); // Fragrance & cosmetics retail volume

        $hauteRevenue      = $expectedRevenue * $hauteWeight * (1.0 + ($hauteZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $accessibleRevenue = $expectedRevenue * $accessibleWeight * (1.0 + ($accessibleZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // Veblen Inflation Benefit vs. Standard Supply Chain Penalty:
        $inflation = $macroState->inflationEma;
        $veblenMarginBenefit = $inflation > MacroEngine::TARGET_INFLATION ? -($inflation - MacroEngine::TARGET_INFLATION) * self::VEBLEN_MARGIN_BENEFIT * $hauteWeight : 0.0;

        // Tail Risk: Brand Dilution vs. Viral Fashion Super-Cycle
        $eventZ = $mathUtility->generateStandardNormal();
        $eventType = null;
        $brandModifier = 0.0;

        if ($eventZ < self::BRAND_DILUTION_Z_SCORE) {
            $brandModifier = self::BRAND_DILUTION_PENALTY * $hauteWeight;
            $eventType = ShockEvent::LUXURY_BRAND_DILUTION;
        } elseif ($eventZ > self::BRAND_BOOM_Z_SCORE) {
            $hauteRevenue *= self::BRAND_BOOM_REV_MULT;
            $brandModifier = self::BRAND_BOOM_MARGIN_BONUS * $hauteWeight;
            $eventType = ShockEvent::LUXURY_CULTURAL_DOMINANCE;
        }

        $actualRevenue = max(0.0, $hauteRevenue + $accessibleRevenue);

        // Continuous Veblen Brand Cachet Elasticity:
        // Strong haute couture desirability ($hauteZ > 0) continuously expands pricing cachet and improves gross margin.
        $brandCachetShift = -self::BRAND_CACHET_ELASTICITY * $hauteZ * $hauteWeight;

        $clampedMargin = min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $veblenMarginBenefit + $brandModifier + $brandCachetShift));

        // Analyst Visibility
        $primaryShockZ = abs($eventZ) > abs($hauteZ) ? $eventZ : $hauteZ;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $hauteZ * $hauteWeight + $accessibleZ * $accessibleWeight,
            eventType: $eventType,
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Fashion cycles tracked by retail data (~50% visibility, 30% floor).
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 0.50, errorStdDev: 0.05, minVisibility: 0.30);
    }

    public function getMarginReversionSpeed(): float
    {
        // Brand equity is highly sticky over decades
        return self::LUXURY_REVERSION_SPEED;
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.14; // Haute couture finished leather goods & boutique inventory holding
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Boutique craftsmanship decay toward accessible apparel floor
            $decayRate = self::BOUTIQUE_CRAFT_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Heritage exclusivity overinvestment expands Veblen pricing cachet
            $modGain = self::HERITAGE_EXCLUSIVITY_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}

