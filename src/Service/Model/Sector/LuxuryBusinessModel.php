<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Wealth-effect demand with Veblen pricing; maisons are weak substitutes. */
    public const OPERATING_CYCLICALITY = 1.10;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.20;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.40;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['labor' => 0.25, 'ppi' => 0.15, 'freight' => 0.03];

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Boutique staffing, atelier artisans and brand marketing are carried through the cycle to protect the brand, not flexed with sales. */
    public const FIXED_COST_LABOR_SHARE = 0.65;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Tourist spend and cross-border arbitrage make luxury demand unusually sensitive to the currency. */
    public const FX_REVENUE_EXPOSURE = 0.15;
    /** Veblen pricing: maisons raise prices one and a half times expected inflation without losing volume. */
    public const PRICING_ELASTICITY = 1.50;
    /** Leather, workshop payroll and logistics are recovered in full through list prices. */
    public const PRICING_POWER_INDEX = 1.00;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Flagship boutiques on prime retail streets are leased on long terms. */
    public const LEASE_LIABILITY_INTENSITY = 0.45;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50;
    public const BASE_COVERAGE_ERROR = 0.05;
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.30;
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.02; }
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.04;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.55, 'revenue_weight' => 0.45];
    }

    // --- Dual-Stream Luxury Brand Architecture ---
    /** Baseline fraction of revenue derived from ultra-high-net-worth Maison leather goods and haute couture. */
    public const HAUTE_COUTURE_WEIGHT     = 0.45;
    /** Baseline fraction of revenue derived from accessible luxury (perfume, eyewear, cosmetics, accessories). */
    public const ACCESSIBLE_LUXURY_WEIGHT = 0.55;

    // --- Veblen Pricing & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to global output gaps for elite luxury goods. */
    public const MACRO_DEMAND_SCALAR       = 0.70;
    /** Sensitivity of high-net-worth luxury demand to broad money supply (M2) growth liquidity. */
    public const M2_LIQUIDITY_SENSITIVITY  = 0.40;

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
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $beta = $this->getOperatingCyclicality($stock);

        $resShift = ($macroState->residentialPropertyIndexEma - 100.0) / 100.0; // Wealth effect from property
        $m2Shift = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, MacroEngine::M2_BASE_GROWTH, self::M2_LIQUIDITY_SENSITIVITY);

        $blendedMacroShift = ($outputGap * 0.35) + ($sentimentShift * 0.45) + ($resShift * 0.20) + $m2Shift + $this->resolveFxDemandShift($macroState);

        // Luxury goods benefit from Veblen pricing power: list prices outrun expected inflation (PRICING_ELASTICITY),
        // and the engine books the gap between price and input cost as margin.
        return [
            'macro_demand_shift' => $blendedMacroShift * $beta * self::MACRO_DEMAND_SCALAR,
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::HauteCoutureWeight->value     => self::HAUTE_COUTURE_WEIGHT,
            ModelParam::AccessibleLuxuryWeight->value => self::ACCESSIBLE_LUXURY_WEIGHT,
        ]);

        $hauteWeight      = $params[ModelParam::HauteCoutureWeight];
        $accessibleWeight = $params[ModelParam::AccessibleLuxuryWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'haute_couture'     => $params[ModelParam::HauteCoutureWeight],
            'accessible_luxury' => $params[ModelParam::AccessibleLuxuryWeight],
        ]);

        $hauteWeight      = $activeWeights['haute_couture'];
        $accessibleWeight = $activeWeights['accessible_luxury'];

        // Independent stream Z-scores with AR(1) persistence
        $hauteZ      = $streams->generateZ('haute_couture', 0.40); // UHNW leather goods / couture demand
        $accessibleZ = $streams->generateZ('accessible_luxury', 0.15); // Fragrance & cosmetics retail volume
        $eventZ      = $streams->generateExogenousZ('event', 0.05);

        $hauteRevenue      = $expectedRevenue * $hauteWeight * (1.0 + ($hauteZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $accessibleRevenue = $expectedRevenue * $accessibleWeight * (1.0 + ($accessibleZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // Input costs (leather, workshop payroll, logistics) are recovered in full through list prices; the
        // Veblen margin gain itself is booked by the engine from the price/input-cost gap.
        $veblenMarginBenefit = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        // Tail Risk: Brand Dilution vs. Viral Fashion Super-Cycle
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

        $streamRevenues = [
            'haute_couture'     => $hauteRevenue,
            'accessible_luxury' => $accessibleRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Continuous Veblen Brand Cachet Elasticity:
        // Strong haute couture desirability ($hauteZ > 0) continuously expands pricing cachet and improves gross margin.
        $brandCachetShift = -self::BRAND_CACHET_ELASTICITY * $hauteZ * $hauteWeight;

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $veblenMarginBenefit + $brandModifier + $brandCachetShift);

        // Analyst Visibility
        $primaryShockZ = $streams->resolveDominantShockZ([$hauteZ], $eventZ);
        $hauteBase = max(1.0, $expectedRevenue * $hauteWeight);
        $hauteShock = ($hauteRevenue - $hauteBase) / $hauteBase;
        $accessibleShock = $accessibleZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $observableShockZ = ($hauteShock * $hauteWeight) + ($accessibleShock * $accessibleWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
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

    public function getSeasonalityFactors(): array
    {
        return [0.85, 0.90, 0.95, 1.30]; // Q4 holiday gift & festive collection surge
    }

    /** Boutique craftsmanship decay toward accessible apparel floor */
    public function getDepreciationDecayRate(): float
    {
        return self::BOUTIQUE_CRAFT_DECAY_RATE;
    }

    /** Heritage exclusivity overinvestment expands Veblen pricing cachet */
    public function getModernizationGainRate(): float
    {
        return self::HERITAGE_EXCLUSIVITY_GAIN_RATE;
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'consumer_sentiment_index_ema',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'money_supply_growth_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'residential_property_index_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
