<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;

use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\AbstractBusinessModel;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Internet Retail & Digital Marketplace Megacorporations.
 *
 * Financial Physics:
 * - Tri-Stream Architecture:
 *      1. 1st-Party Retail: Buys and sells physical inventory. Low margin, highly exposed to supply chain inflation and
 *         recessions.
 *      2. 3rd-Party Fulfillment (The Tollbooth): Charges independent vendors to use their logistics. High margin, high
 *         volume.
 *      3. Digital Advertising / Cloud: Monetizes consumer data. Near-100% margin, zero physical overhead.
 * - Margin Cross-Subsidization: The retail division runs at a near loss to dominate market share, subsidized by Ads and
 *   3P fees.
 * - Tail Risk: Labor unionization strikes in fulfillment centers, or sovereign antitrust breakups.
 */
class InternetRetailBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Discretionary baskets
     * with near-perfect price comparison.
     */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.20;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.70;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::INTERNET_RETAIL;

    // --- Consumer Demand ---
    /**
     * Elasticity of first-party retail volume to the consumer sentiment gap (index points above baseline / 100).
     * Discretionary baskets follow household confidence ahead of the output gap.
     */
    public const CONSUMER_SENTIMENT_SCALAR = 0.60;

    // --- FX Exposure ---
    /**
     * Merchandise is bought abroad and sold at home, so the exchange rate reaches this model through landed cost, not
     * demand: a strong domestic currency cheapens the first-party cost of goods.
     */
    public const IMPORT_SOURCING_FX_SCALAR = 0.15;

    // --- Balance Sheet Realism ---
    /**
     * First-party retail's capitalized leases over its revenue (IFRS 16 / ASC 842): leased fulfilment centres at a
     * warehouse retailer's 0.30.
     */
    public const FIRST_PARTY_LEASE_INTENSITY = 0.30;
    /**
     * The ad and cloud book's leases over its revenue: leased data-centre halls at a platform's 0.10. The marketplace
     * leases offices only, at the default.
     */
    public const DIGITAL_ADS_LEASE_INTENSITY = 0.10;
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: 4.18% at the lead US marketplace, $24.0B of $574.8B
     * (Amazon 10-K, FY2023); Damodaran files online retail inside Retail (General).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0418;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.40; // 1P sales are visible, but 3P/Ads are a black box
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Secular Demand ---
    /** Online sales as a share of the District's retail at Year 1 (4%). */
    public const ONLINE_SHARE_YEAR_1 = 0.040;
    /**
     * The same share a decade on (6.5%): a dense financial centre with short delivery runs and a strong high street
     * takes to online shopping at about half the mainland's pace (whose share went from 4% to 10.6% in its 2010s).
     */
    public const ONLINE_SHARE_DECADE_ON = 0.065;
    /** Years between those two shares. */
    public const ONLINE_ADOPTION_WINDOW_YEARS = 10.0;
    /** Retail trade value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value added). */
    public const RETAIL_SHARE_1997 = 0.0676;
    /** The same share in 2019. */
    public const RETAIL_SHARE_2019 = 0.0593;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.15 a year). */
    public const ROIC_REVERSION_SPEED = 0.15;
    /** Excess return over the required return the moat sustains (2pp). */
    public const MOAT_SPREAD = 0.02;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (1.5). Massive warehouse and server farm buildouts. */
    public const CAPEX_CYCLICALITY = 1.5;
    /** Share of construction in progress completed into plant each quarter (50%). */
    public const CAPEX_COMPLETION_RATE = 0.5;

    // --- Seasonality ---
    /** Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0. Q4 holiday shopping surge. */
    public const SEASONALITY_FACTORS = [0.85, 0.90, 0.90, 1.35];

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (2.5x). */
    public const MIN_ICR = 2.5;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (2x). */
    public const DIVIDEND_CRISIS_ICR = 2.0;
    /** Interest coverage below which the firm stops repurchasing stock (2.5x). */
    public const BUYBACK_MIN_ICR = 2.5;

    /**
     * Trend real growth plus online's drift within retail and retail's drift within GDP; the adoption fades like any
     * drift.
     */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::ONLINE_SHARE_YEAR_1, self::ONLINE_SHARE_DECADE_ON, self::ONLINE_ADOPTION_WINDOW_YEARS)
            + MacroTransmission::gdpShareDrift(self::RETAIL_SHARE_1997, self::RETAIL_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    // --- Tri-Stream Architecture Weights ---
    public const FIRST_PARTY_WEIGHT  = 0.45;
    public const THIRD_PARTY_WEIGHT  = 0.40;
    public const DIGITAL_ADS_WEIGHT  = 0.15;

    // --- Working Capital by Stream (shares of the stream's revenue) ---
    /** First-party retail: card takings in ~7 days, ~44 days of sales in stock, suppliers paid in ~73 days of sales. */
    public const FIRST_PARTY_WORKING_CAPITAL = ['receivables' => 0.02, 'inventory' => 0.12, 'payables' => 0.20];
    /** Marketplace: no stock; the sellers' takings held about two weeks, ~44 days of the fees it keeps. */
    public const THIRD_PARTY_WORKING_CAPITAL = ['receivables' => 0.02, 'inventory' => 0.0, 'payables' => 0.12];
    /** Ads and cloud: advertisers and tenants billed on ~55-day terms, suppliers paid in ~18 days of sales. */
    public const DIGITAL_ADS_WORKING_CAPITAL = ['receivables' => 0.15, 'inventory' => 0.0, 'payables' => 0.05];

    /**
     * The shop holds stock and owes its suppliers, the marketplace owes its sellers, and the ad book is owed by
     * advertisers.
     */
    protected function resolveWorkingCapitalShares(Stock $stock): array
    {
        return FirmEconomics::blendWorkingCapitalShares(
            $this->resolveTargetStreamWeights($stock),
            [self::FIRST_PARTY_WORKING_CAPITAL, self::THIRD_PARTY_WORKING_CAPITAL, self::DIGITAL_ADS_WORKING_CAPITAL],
        );
    }

    /**
     * Leases follow the streams that run on leased space: the first-party warehouses and the data halls, not the
     * marketplace.
     */
    public function getLeaseIntensity(Stock $stock): float
    {
        return FirmEconomics::revenueWeightedRatio(
            $this->resolveTargetStreamWeights($stock),
            [self::FIRST_PARTY_LEASE_INTENSITY, AbstractBusinessModel::LEASE_LIABILITY_INTENSITY, self::DIGITAL_ADS_LEASE_INTENSITY],
        );
    }

    /**
     * The firm's target mix, first party, marketplace and ads in that order.
     *
     * @return list<float>
     */
    private function resolveTargetStreamWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FirstPartyWeight->value => self::FIRST_PARTY_WEIGHT,
            ModelParam::ThirdPartyWeight->value => self::THIRD_PARTY_WEIGHT,
            ModelParam::DigitalAdsWeight->value => self::DIGITAL_ADS_WEIGHT,
        ]);

        return [$params[ModelParam::FirstPartyWeight], $params[ModelParam::ThirdPartyWeight], $params[ModelParam::DigitalAdsWeight]];
    }

    // --- Stream Variance Scalars ---
    public const FIRST_PARTY_VARIANCE = 0.35; // Highly cyclical (consumers stop buying TVs in a recession)
    public const THIRD_PARTY_VARIANCE = 0.15; // Sticky (vendors must pay the toll to survive)
    public const DIGITAL_ADS_VARIANCE = 0.25; // Scales aggressively with platform traffic

    // --- Margin Architecture ---
    public const THIRD_PARTY_COST_RATIO = 0.40; // Moderate cost (logistics, server compute)
    public const DIGITAL_ADS_COST_RATIO = 0.10; // Pure profit (algorithmic placement)

    // --- Supply Chain & Labor Physics ---
    /**
     * Internet retailers compete on the lowest price: merchandise, freight and warehouse wage moves are barely
     * recovered.
     */
    public const PRICING_POWER_INDEX = 0.10;

    // --- Tail Risk Events ---
    public const WAREHOUSE_STRIKE_Z_SCORE = -2.20;
    public const WAREHOUSE_STRIKE_PENALTY = 0.08; // Margin hit from crippled logistics/overtime pay

    public const ANTITRUST_FINE_Z_SCORE   = -2.60;
    public const ANTITRUST_FINE_MULT      = 0.90; // Top-line haircut from forced breakups or regulatory bans

    public const VIRAL_HOLIDAY_SURGE_Z    = 2.40;
    public const VIRAL_HOLIDAY_MULT       = 1.15; // Prime Day / Holiday super-cycle

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift. We process output gap cyclically per-stream to prevent double-dipping.
        $physics['macro_demand_shift'] = 0.0;
        // Inflation is absorbed as a cost penalty, not passed on (Internet Retailers compete on lowest price).
        $physics['pricing_power_multiplier'] = 1.0;
        // Inflation is carried inside this model's own stream physics: neither price nor cost base inflates at the
        // engine level.
        $physics['input_cost_multiplier'] = 1.0;

        return $physics;
    }

    /**
     * The orders the fulfilment network is staffed to ship: the target-mix weighted cycle shift of first-party
     * units and third-party GMV through the tollbooth. Ads carry no macro term and add only to the weight.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FirstPartyWeight->value => self::FIRST_PARTY_WEIGHT,
            ModelParam::ThirdPartyWeight->value => self::THIRD_PARTY_WEIGHT,
            ModelParam::DigitalAdsWeight->value => self::DIGITAL_ADS_WEIGHT,
        ]);
        $totalWeight = $params[ModelParam::FirstPartyWeight] + $params[ModelParam::ThirdPartyWeight] + $params[ModelParam::DigitalAdsWeight];
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $shifts = $this->resolveStreamMacroShifts($stock, $macroState);

        return (($params[ModelParam::FirstPartyWeight] * $shifts['first_party_retail'])
            + ($params[ModelParam::ThirdPartyWeight] * $shifts['third_party_seller'])) / $totalWeight;
    }

    /**
     * Each stream's macro volume shift: first-party baskets on twice the gap plus the confidence residual over
     * it (Lemmon & Portniaguina 2006); third-party GMV on half the gap, the tollbooth vendors keep paying.
     *
     * @return array{first_party_retail: float, third_party_seller: float}
     */
    private function resolveStreamMacroShifts(Stock $stock, MacroStateDTO $macroState): array
    {
        $beta = $this->getOperatingCyclicality($stock);
        $outputGap = $macroState->outputGapEma;

        return [
            'first_party_retail' => (($outputGap * 2.0) + ($macroState->sentimentResidual() * self::CONSUMER_SENTIMENT_SCALAR)) * $beta,
            'third_party_seller' => $outputGap * 0.5 * $beta,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FirstPartyWeight->value => self::FIRST_PARTY_WEIGHT,
            ModelParam::ThirdPartyWeight->value => self::THIRD_PARTY_WEIGHT,
            ModelParam::DigitalAdsWeight->value => self::DIGITAL_ADS_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'first_party_retail' => $params[ModelParam::FirstPartyWeight],
            'third_party_seller' => $params[ModelParam::ThirdPartyWeight],
            'digital_ads_cloud'  => $params[ModelParam::DigitalAdsWeight],
        ]);

        $fpWeight  = $activeWeights['first_party_retail'];
        $tpWeight  = $activeWeights['third_party_seller'];
        $adsWeight = $activeWeights['digital_ads_cloud'];

        // Independent stream Z-scores
        $fpZ  = $streams->generateZ('first_party_retail', 0.25);
        $tpZ  = $streams->generateZ('third_party_seller', 0.40); // High persistence tollbooth
        $adsZ = $streams->generateZ('digital_ads_cloud', 0.20);
        $eventZ = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Sensitivities ---
        // 1P Retail bears the absolute brunt of consumer recessions, and household confidence moves the basket
        // before the output gap does: a shopper who fears for their job trades down while GDP is still growing.
        // 3P and Ads are partially insulated, acting as a structural tollbooth.
        $macroShifts = $this->resolveStreamMacroShifts($stock, $macroState);
        $fpMacroShift = $macroShifts['first_party_retail'];
        $tpMacroShift = $macroShifts['third_party_seller'];

        // --- Tail Risk Events ---
        $revenueMultiplier = 1.0;
        $eventType = null;
        $strikePenalty = 0.0;

        if ($eventZ < self::ANTITRUST_FINE_Z_SCORE) {
            $revenueMultiplier = self::ANTITRUST_FINE_MULT;
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ < self::WAREHOUSE_STRIKE_Z_SCORE) {
            $strikePenalty = self::WAREHOUSE_STRIKE_PENALTY;
            $eventType = ShockEvent::LABOR_STRIKE;
        } elseif ($eventZ > self::VIRAL_HOLIDAY_SURGE_Z) {
            $revenueMultiplier = self::VIRAL_HOLIDAY_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        // --- Clamped Tri-Stream Revenue Calculation ---
        $fpRevenue  = max(0.0, $expectedRevenue * $fpWeight * (1.0 + ($fpZ * $baselineVol * self::FIRST_PARTY_VARIANCE) + $fpMacroShift) * $revenueMultiplier);
        $tpRevenue  = max(0.0, $expectedRevenue * $tpWeight * (1.0 + ($tpZ * $baselineVol * self::THIRD_PARTY_VARIANCE) + $tpMacroShift) * $revenueMultiplier);

        // Digital Ads scale exponentially with underlying platform traffic (blending FP and TP Z-scores)
        $platformTrafficBonus = ($fpZ * 0.5) + ($tpZ * 0.5);
        $adsRevenue = max(0.0, $expectedRevenue * $adsWeight * (1.0 + ($adsZ * $baselineVol * self::DIGITAL_ADS_VARIANCE) + ($platformTrafficBonus * 0.10)) * $revenueMultiplier);

        $streamRevenues = [
            'first_party_retail' => $fpRevenue,
            'third_party_seller' => $tpRevenue,
            'digital_ads_cloud'  => $adsRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending ---
        // The 1P cost ratio is what the plan leaves after 3P and ads at their planned revenue: struck on realized
        // 3P/ads costs, a 3P surge would cut the 1P ratio by exactly its own cost and come out costless.
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $plannedTpAdsCosts = $expectedRevenue * (($tpWeight * self::THIRD_PARTY_COST_RATIO) + ($adsWeight * self::DIGITAL_ADS_COST_RATIO));
        $fpBaselineCosts = max(0.0, $targetTotalCosts - $plannedTpAdsCosts);
        $fpVariableMargin = $expectedRevenue * $fpWeight > 0 ? $fpBaselineCosts / ($expectedRevenue * $fpWeight) : $realizedVariableMargin;
        // A marketplace with no first-party line has no line left to absorb the engine's cost ratio, so its own lines
        // carry it: the quarter's margin noise, the cycle and the plant's overtime reach it as they reach any firm.
        $tpAdsScale = ($expectedRevenue * $fpWeight > 0.0 || $plannedTpAdsCosts <= 0.0) ? 1.0 : $targetTotalCosts / $plannedTpAdsCosts;
        $tpCosts = $tpRevenue * self::THIRD_PARTY_COST_RATIO * $tpAdsScale;
        $adsCosts = $adsRevenue * self::DIGITAL_ADS_COST_RATIO * $tpAdsScale;

        $fxShift = $macroState->exchangeRateGap();
        $fpCostSavings = $fpRevenue * $fxShift * self::IMPORT_SOURCING_FX_SCALAR;

        // Re-blend actual costs based on shocked revenue
        $actualVariableCosts = $tpCosts + $adsCosts + ($fpRevenue * $fpVariableMargin) - $fpCostSavings;

        // --- Input Cost Basket ---
        // Merchandise (wholesale goods), ocean and last-mile freight and warehouse payroll reach the cost base at
        // spot; a lowest-price retailer recovers almost none of it in its own prices.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // Apply penalties directly to the baseline margin
        $rawMargin = $effectiveMargin + $inputCostDrag + $strikePenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
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
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
