<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Global Advertising Agencies & MarTech Networks.
 * 
 * Financial Physics:
 * - Asset light, human-capital intensive.
 * - Tri-Stream Agency Architecture:
 *      1. Media Buying Commissions: Programmatic take-rates on gross client ad spend; pro-cyclical to corporate earnings.
 *      2. Creative Brand Retainers: High-margin, multi-year Agency of Record (AOR) brand management fees.
 *      3. MarTech & Data Consulting: High-value enterprise marketing automation and customer analytics consulting.
 * - High Operating Leverage: During economic expansions, programmatic ad spend surges rapidly over fixed creative payroll.
 */
class AdvertisingAgencyBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Marketing budgets are cut first and restored last. */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.60;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['labor' => 0.70];

    // --- Services Pricing ---
    /** Elasticity of fee and rate pricing to services (supercore) inflation. Retainer and fee schedules reprice with services inflation. */
    public const PRICING_ELASTICITY = 0.80;
    /** Holding-company fees face client procurement review and a commoditized media-buying alternative, so wage inflation is largely absorbed rather than billed on. */
    public const PRICING_POWER_INDEX = 0.35;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 holiday campaign spend, Q1 post-holiday lull.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.90, 1.00, 0.95, 1.15];
    }

    // --- Balance Sheet Realism ---
    /** Stock-based compensation as a fraction of revenue (ASC 718): non-cash, added back to FCF, settled in new shares. Creative leadership retention grants. */
    public const STOCK_COMPENSATION_INTENSITY = 0.03;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Creative and account payroll dominates an agency's overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.80;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.25;
    public const BASE_COVERAGE_ERROR = 0.06;

        public function getMinIcr(): float { return 3.0; }
    public function getWholesaleLeverageLimit(): float { return 1.5; }
    public function getDividendCrisisIcr(): float { return 2.0; }
    public function getBuybackMinIcr(): float { return 3.0; }
    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.05; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.2; }

    public function getSecularGrowthRate(Stock $stock): float { return 0.015; }
    
    public function getCapexCyclicality(): float { return 0.10; }
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.70, 'revenue_weight' => 0.30]; }

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from programmatic media buying commissions. */
    public const MEDIA_BUYING_WEIGHT      = 0.50;
    /** Baseline fraction of revenue derived from creative Agency of Record brand retainers. */
    public const BRAND_RETAINER_WEIGHT     = 0.35;
    /** Baseline fraction of revenue derived from marketing technology and data consulting. */
    public const MARTECH_CONSULTING_WEIGHT = 0.15;

    // --- Physics & Variances ---
    public const MEDIA_VARIANCE_SCALAR   = 0.35; // Pro-cyclical to corporate ad spend
    public const BRAND_VARIANCE_SCALAR   = 0.10; // Sticky multi-year retainers
    public const MARTECH_VARIANCE_SCALAR = 0.20; // B2B technology consulting

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);


        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::MediaBuyingWeight->value       => self::MEDIA_BUYING_WEIGHT,
            ModelParam::BrandRetainerWeight->value      => self::BRAND_RETAINER_WEIGHT,
            ModelParam::MartechConsultingWeight->value => self::MARTECH_CONSULTING_WEIGHT,
        ]);

        $mediaWeight   = $params[ModelParam::MediaBuyingWeight];
        $brandWeight   = $params[ModelParam::BrandRetainerWeight];
        $martechWeight = $params[ModelParam::MartechConsultingWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta     = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'media_buying_commissions' => $params[ModelParam::MediaBuyingWeight],
            'creative_brand_retainers' => $params[ModelParam::BrandRetainerWeight],
            'martech_consulting'       => $params[ModelParam::MartechConsultingWeight],
        ]);

        $mediaWeight   = $activeWeights['media_buying_commissions'];
        $brandWeight   = $activeWeights['creative_brand_retainers'];
        $martechWeight = $activeWeights['martech_consulting'];

        // Ad budgets expand aggressively during GDP booms and contract sharply during recessions and consumer sentiment drops
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $macroAdSpendShift = ($macroState->outputGapEma * 1.5 * $beta) + ($sentimentShift * 0.50 * $beta);

        $mediaZ   = $streams->generateZ('media_buying_commissions', 0.25);
        $brandZ   = $streams->generateZ('creative_brand_retainers', 0.50);
        $martechZ = $streams->generateZ('martech_consulting', 0.40);

        $mediaRevenue   = max(0.0, $expectedRevenue * $mediaWeight   * (1.0 + ($mediaZ * ($baselineVol * self::MEDIA_VARIANCE_SCALAR)) + $macroAdSpendShift));
        $brandRevenue   = max(0.0, $expectedRevenue * $brandWeight   * (1.0 + ($brandZ * ($baselineVol * self::BRAND_VARIANCE_SCALAR))));
        $martechRevenue = max(0.0, $expectedRevenue * $martechWeight * (1.0 + ($martechZ * ($baselineVol * self::MARTECH_VARIANCE_SCALAR)) + ($macroAdSpendShift * 0.4)));

        $streamRevenues = [
            'media_buying_commissions' => $mediaRevenue,
            'creative_brand_retainers' => $brandRevenue,
            'martech_consulting'       => $martechRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Creative and account payroll is the variable cost base; retainer repricing recovers part of wage growth.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag);

        $primaryShockZ = $streams->resolveDominantShockZ([$mediaZ, $brandZ, $martechZ]);

        $observableShockZ = ($mediaZ * $mediaWeight * self::MEDIA_VARIANCE_SCALAR * $baselineVol) +
            ($brandZ * $brandWeight * self::BRAND_VARIANCE_SCALAR * $baselineVol) +
            ($macroAdSpendShift * $mediaWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
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
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
