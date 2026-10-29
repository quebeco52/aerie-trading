<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Global Advertising Agencies & MarTech Networks.
 *
 * Financial Physics:
 * - Asset light, human-capital intensive.
 * - Tri-Stream Agency Architecture:
 *      1. Media Buying Commissions: Programmatic take-rates on gross client ad spend; pro-cyclical to corporate
 *         earnings.
 *      2. Creative Brand Retainers: High-margin, multi-year Agency of Record (AOR) brand management fees.
 *      3. MarTech & Data Consulting: High-value enterprise marketing automation and customer analytics consulting.
 * - High Operating Leverage: During economic expansions, programmatic ad spend surges rapidly over fixed creative
 *   payroll.
 */
class AdvertisingAgencyBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Marketing budgets are
     * cut first and restored last.
     */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.60;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::ADVERTISING_AGENCY;

    // --- Services Pricing ---
    /**
     * Elasticity of fee and rate pricing to services (supercore) inflation. Retainer and fee schedules reprice with
     * services inflation.
     */
    public const PRICING_ELASTICITY = 0.80;
    /**
     * Holding-company fees face client procurement review and a commoditized media-buying alternative, so wage
     * inflation is largely absorbed rather than billed on.
     */
    public const PRICING_POWER_INDEX = 0.35;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Agency networks
     * lease their studio and office footprint in every market they serve.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.12;
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Advertising, 3.23% of revenue, 52 firms (Damodaran,
     * Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0323;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.25;
    public const BASE_COVERAGE_ERROR = 0.06;

    // --- Secular Demand ---
    /**
     * Miscellaneous professional, scientific and technical services (advertising) value added as a share of US nominal
     * GDP in 1997 (BEA GDP by Industry, value added).
     */
    public const SECULAR_SHARE_1997 = 0.0373;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0457;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.15 a year). */
    public const ROIC_REVERSION_SPEED = 0.15;
    /** Excess return over the required return the moat sustains (1.5pp). */
    public const MOAT_SPREAD = 0.015;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.1). */
    public const CAPEX_CYCLICALITY = 0.10;
    /** Share of construction in progress completed into plant each quarter (20%). */
    public const CAPEX_COMPLETION_RATE = 0.2;
    /** Net working capital as a share of revenue (5%). */
    public const WORKING_CAPITAL_INTENSITY = 0.05;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 holiday campaign spend, Q1 post-holiday
     * lull.
     */
    public const SEASONALITY_FACTORS = [0.90, 1.00, 0.95, 1.15];

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (3x). */
    public const MIN_ICR = 3.0;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (2x). */
    public const DIVIDEND_CRISIS_ICR = 2.0;
    /** Interest coverage below which the firm stops repurchasing stock (3x). */
    public const BUYBACK_MIN_ICR = 3.0;

    /**
     * Trend real growth plus the sector's measured drift in its share of GDP, and its lines' drifts on how far the
     * agency's mix leans from the industry's typical one.
     */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS)
            + TimeSeries::lineMixExcessGrowth($this->resolveLineWeights($stock), self::typicalLineWeights(), self::LINE_DRIFTS);
    }

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

    // --- Ad Spend Cycle ---
    /**
     * Fraction of the client ad-spend cycle that reaches MarTech and data consulting engagements (0.4 of media's
     * swing).
     */
    public const MARTECH_AD_SPEND_SHARE = 0.40;

    // --- Line Drifts ---
    /**
     * Media buying's drift against the industry (-2% a year): clients take buying in-house and onto self-serve tools.
     */
    public const MEDIA_BUYING_DRIFT = -0.02;
    /**
     * Data and technology consulting's drift against the industry (+4% a year): the agencies' growth has run through
     * their data, commerce and technology work.
     */
    public const MARTECH_CONSULTING_DRIFT = 0.04;
    /** Each line's drift against the industry; creative retainers have none. */
    public const LINE_DRIFTS = [
        'media_buying_commissions' => self::MEDIA_BUYING_DRIFT,
        'martech_consulting'       => self::MARTECH_CONSULTING_DRIFT,
    ];

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify the generic demand shift: ad budgets follow the cycle and household confidence per stream in
        // calculateSectorPhysics, so leaving the parent's shift in place would count the output gap twice.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /**
     * The client work the agency is staffed to run: the mix-weighted cycle shift of media billings and MarTech
     * engagements. Commissions are a take rate on gross spend, so the spend moves volume, not price; creative
     * retainers carry no macro term and add only to the weight.
     */
    public function resolveSectorActivityShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $shares = $this->resolveLineShares($stock, $macroState->totalTime);
        $adSpendShift = $this->resolveAdSpendShift($stock, $macroState);

        return ($shares['media_buying_commissions'] * $adSpendShift)
            + ($shares['martech_consulting'] * $adSpendShift * self::MARTECH_AD_SPEND_SHARE);
    }

    /**
     * Each line's share of the plan: the agency's opening mix with each line compounding its faded drift against the
     * industry.
     *
     * @return array<string, float>
     */
    public function resolveLineShares(Stock $stock, float $simYears): array
    {
        return TimeSeries::driftedLineShares($this->resolveLineWeights($stock), self::LINE_DRIFTS, $simYears, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS) + [
            'media_buying_commissions' => 0.0,
            'creative_brand_retainers' => 0.0,
            'martech_consulting'       => 0.0,
        ];
    }

    /** @return array{media_buying_commissions: float, creative_brand_retainers: float, martech_consulting: float} */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::MediaBuyingWeight->value       => self::MEDIA_BUYING_WEIGHT,
            ModelParam::BrandRetainerWeight->value      => self::BRAND_RETAINER_WEIGHT,
            ModelParam::MartechConsultingWeight->value => self::MARTECH_CONSULTING_WEIGHT,
        ]);

        return [
            'media_buying_commissions' => max(0.0, (float) $params[ModelParam::MediaBuyingWeight]),
            'creative_brand_retainers' => max(0.0, (float) $params[ModelParam::BrandRetainerWeight]),
            'martech_consulting'       => max(0.0, (float) $params[ModelParam::MartechConsultingWeight]),
        ];
    }

    /**
     * The industry's typical agency: the model's own weights, before any firm's lore.
     *
     * @return array<string, float>
     */
    private static function typicalLineWeights(): array
    {
        return [
            'media_buying_commissions' => self::MEDIA_BUYING_WEIGHT,
            'creative_brand_retainers' => self::BRAND_RETAINER_WEIGHT,
            'martech_consulting'       => self::MARTECH_CONSULTING_WEIGHT,
        ];
    }

    /** Client ad budgets on the output gap and the confidence residual, at the firm's cyclicality. */
    private function resolveAdSpendShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        return MacroTransmission::advertisingSpendShift($macroState->outputGapEma, $macroState->sentimentResidual(), $this->getOperatingCyclicality($stock));
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Revenue Mix ---
        $activeWeights = $streams->resolveActiveStreamWeights($this->resolveLineShares($stock, $macroState->totalTime));

        $mediaWeight   = $activeWeights['media_buying_commissions'];
        $brandWeight   = $activeWeights['creative_brand_retainers'];
        $martechWeight = $activeWeights['martech_consulting'];

        // Ad budgets expand aggressively during GDP booms and contract sharply during recessions and consumer sentiment
        // drops
        $macroAdSpendShift = $this->resolveAdSpendShift($stock, $macroState);

        $mediaZ   = $streams->generateZ('media_buying_commissions', 0.25);
        $brandZ   = $streams->generateZ('creative_brand_retainers', 0.50);
        $martechZ = $streams->generateZ('martech_consulting', 0.40);

        $mediaRevenue   = max(0.0, $expectedRevenue * $mediaWeight   * (1.0 + ($mediaZ * ($baselineVol * self::MEDIA_VARIANCE_SCALAR)) + $macroAdSpendShift));
        $brandRevenue   = max(0.0, $expectedRevenue * $brandWeight   * (1.0 + ($brandZ * ($baselineVol * self::BRAND_VARIANCE_SCALAR))));
        $martechRevenue = max(0.0, $expectedRevenue * $martechWeight * (1.0 + ($martechZ * ($baselineVol * self::MARTECH_VARIANCE_SCALAR)) + ($macroAdSpendShift * self::MARTECH_AD_SPEND_SHARE)));

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

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
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
            'import_price_level',
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
