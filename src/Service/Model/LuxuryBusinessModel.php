<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
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
    /** Base analyst visibility into retail foot traffic and fashion cycle momentum. */
    public const ANALYST_BASE_VISIBILITY   = 0.50;
    /** Minimum allowable analyst visibility floor for retail luxury brand tracking. */
    public const MIN_ANALYST_VISIBILITY    = 0.30;
    /** Standard deviation of analyst estimation error for quarterly luxury sales. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;

    // --- Brand Equity Moat ---
    /** Operating margin mean reversion speed: slower speed reflects sticky multi-decade brand equity. */
    public const LUXURY_REVERSION_SPEED    = 2.0;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $beta = (float) $stock->getBeta();

        // Luxury goods benefit from Veblen pricing power during inflation
        $inflationBonus = $inflation > MacroEngine::TARGET_INFLATION ? ($inflation - MacroEngine::TARGET_INFLATION) * self::VEBLEN_INFLATION_SCALAR : 0.0;

        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR,
            'pricing_power_multiplier' => 1.0 + $inflationBonus,
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
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
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $veblenMarginBenefit = $inflation > MacroEngine::TARGET_INFLATION ? -($inflation - MacroEngine::TARGET_INFLATION) * self::VEBLEN_MARGIN_BENEFIT * $hauteWeight : 0.0;

        // Tail Risk: Brand Dilution vs. Viral Fashion Super-Cycle
        $eventZ = $mathUtility->generateStandardNormal();
        $eventLore = null;
        $brandModifier = 0.0;

        if ($eventZ < self::BRAND_DILUTION_Z_SCORE) {
            $brandModifier = self::BRAND_DILUTION_PENALTY * $hauteWeight;
            $eventLore = "Suffered brand dilution and inventory write-downs following a poorly received creative direction.";
        } elseif ($eventZ > self::BRAND_BOOM_Z_SCORE) {
            $hauteRevenue *= self::BRAND_BOOM_REV_MULT;
            $brandModifier = self::BRAND_BOOM_MARGIN_BONUS * $hauteWeight;
            $eventLore = "Captured immense global demand with a culturally dominant fashion collection.";
        }

        $actualRevenue = max(0.0, $hauteRevenue + $accessibleRevenue);

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $veblenMarginBenefit + $brandModifier));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Fashion cycles and brand momentum are heavily tracked by retail data (~50% visibility).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(self::MIN_ANALYST_VISIBILITY, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + (($hauteZ * $hauteWeight + $accessibleZ * $accessibleWeight) * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + (($veblenMarginBenefit + $brandModifier) * $dynamicVisibility)));

        $primaryShockZ = abs($eventZ) > abs($hauteZ) ? $eventZ : $hauteZ;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $ebit,
            'primary_shock_z'                 => $primaryShockZ,
            'event_lore'                      => $eventLore
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        // Brand equity is highly sticky over decades
        return self::LUXURY_REVERSION_SPEED;
    }
}

