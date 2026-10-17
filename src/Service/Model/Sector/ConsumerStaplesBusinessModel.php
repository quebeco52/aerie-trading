<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroTransmission;

use App\Service\Model\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Consumer Staples (Food, Beverage, Tobacco, Household Goods).
 *
 * Financial Physics:
 * - Engel's law: pantry demand follows income at a low elasticity, so a recession barely dents volume.
 * - Pass-Through Pricing Power: brands recover expected inflation on the shelf, and more than recover it the stronger they are.
 * - Cost-Push Squeeze (COGS): farm commodities, energy, imports and payroll reach the cost base at spot and are recovered with the repricing lag.
 * - Commodity desk: a firm that buys its commodities forward meets a spot move late but in full.
 * - Grower's windfall: bulk output sold at the exchange price gains from a dear harvest that squeezes a processor.
 * - Dynamic Working Capital Float: Branded goods generate float (-5% NWC) while bulk commodity storage ties up cash (+5% NWC).
 */
class ConsumerStaplesBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Income elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Inelastic pantry demand; brands substitute on the shelf. */
    public const OPERATING_CYCLICALITY = 0.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::CONSUMER_STAPLES;
    /** Branded staples recover input moves on the shelf within a couple of quarters. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.50;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Staples are made and sold close to the shelf; only a thin import-competing slice reprices with the currency. */
    public const FX_REVENUE_EXPOSURE = 0.05;
    /** Brand equity is shelf-price power: a branded FMCG list price rises with input costs and the volume loss is small. Bulk commodity producers are tuned down per ticker. */
    public const PRICING_POWER_INDEX = 0.75;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for consumer staples analysts. */
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    /** Base coverage forecasting error given steady, predictable cash flows. */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Core Sector Structural Constants ---
    /** Mild CapEx cyclicality; staples upgrade facilities but avoid heavy industrial boom/bust cycles. */
    public const STAPLES_CAPEX_CYCLICALITY = 0.80;
    /** Operating margin mean reversion speed: fast speed reflects intense retail shelf-space price competition. */
    public const STAPLES_REVERSION_SPEED = 5.0;

    // --- Working Capital by Stream (shares of the stream's revenue) ---
    /** Branded goods: retailers pay in ~29 days, ~37 days of sales in stock, suppliers paid on long terms in ~84 days of sales. */
    public const BRANDED_WORKING_CAPITAL = ['receivables' => 0.08, 'inventory' => 0.10, 'payables' => 0.23];
    /** Bulk processing: buyers pay in ~22 days, grain and meat in storage ~29 days of sales, growers paid in ~33. */
    public const VOLUME_WORKING_CAPITAL = ['receivables' => 0.06, 'inventory' => 0.08, 'payables' => 0.09];
    /** The trading desk: physical stocks held behind its positions, ~91 days of its sales. */
    public const COMMODITY_TRADING_WORKING_CAPITAL = ['receivables' => 0.06, 'inventory' => 0.25, 'payables' => 0.06];

    // --- Dual-Stream Consumer Staples Architecture ---
    /** Baseline fraction of revenue derived from premium packaged branded staples and inelastic consumer goods. */
    public const BRANDED_STAPLES_WEIGHT = 0.65;
    /** Baseline fraction of revenue derived from bulk commodity food processing and agricultural volume. */
    public const VOLUME_COMMODITY_WEIGHT = 0.35;

    // --- Revenue Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in stable consumer staples models. */
    public const REVENUE_VARIANCE_SCALAR = 0.05;
    /** Revenue volatility amplifier for commodity trading desks. */
    public const COMMODITY_TRADING_VOL_SCALAR = 2.50;

    // --- Commodity Desk Hedge ---
    /** Share of the commodity bill bought forward per unit of desk revenue weight: a 20% desk covers half the bill. */
    public const COMMODITY_HEDGE_MULTIPLIER = 2.50;
    /** Most of the commodity bill a desk covers forward (80%); the rest is always bought at spot. */
    public const MAX_COMMODITY_HEDGE_COVER = 0.80;
    /** Mean age of a layered hedge book rolling a year of forward cover (~6 months): the lag at which covered input costs reach spot. */
    public const HEDGE_BOOK_MEAN_AGE_YEARS = 0.50;

    // --- Product Recall & Regulatory Lore Thresholds ---
    /** Negative z-score threshold indicating severe supply chain contamination and massive product recall. */
    public const RECALL_SEVERE_Z_SCORE = -2.50;
    /** One-off charge on the quarter's branded sales for a massive recall: stock destroyed, logistics and refunds (8%). */
    public const RECALL_SEVERE_PENALTY = 0.08;
    /** Negative z-score threshold indicating sudden health regulatory scrutiny and fines. */
    public const RECALL_MODERATE_Z_SCORE = -2.00;
    /** One-off charge on the quarter's branded sales for a regulator's fine and legal fees (3%). */
    public const RECALL_MODERATE_PENALTY = 0.03;

    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }

    // --- Secular Demand ---
    /** Spending on food and beverages for off-premises consumption as a share of US nominal GDP in 1997 (BEA NIPA Table 2.4.5, personal consumption expenditures). */
    public const SECULAR_SHARE_1997 = 0.0554;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0503;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroEngine::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    public function getCapexCyclicality(): float
    {
        return self::STAPLES_CAPEX_CYCLICALITY;
    }

    public function getSeasonalityFactors(): array
    {
        return [0.95, 1.00, 1.00, 1.05]; // Slight Q4 holiday pantry load
    }

    public function getMarginReversionSpeed(): float
    {
        return self::STAPLES_REVERSION_SPEED;
    }

    /**
     * A brand owner's suppliers fund its stock and more; bulk processing and the trading desk tie cash up in what they
     * store, and a brand whose goods mature in cask carries that stock on top of what it ships.
     */
    protected function resolveWorkingCapitalShares(Stock $stock): array
    {
        [$weights, $maturing] = $this->resolveWorkingCapitalMix($stock);
        $branded = self::BRANDED_WORKING_CAPITAL;
        $branded['inventory'] += $maturing;

        return FirmEconomics::blendWorkingCapitalShares($weights, [$branded, self::VOLUME_WORKING_CAPITAL, self::COMMODITY_TRADING_WORKING_CAPITAL]);
    }

    /** The maturing stock's share of all the stock the firm holds. */
    public function getMaturingInventoryShare(Stock $stock): float
    {
        [$weights, $maturing] = $this->resolveWorkingCapitalMix($stock);
        $inventory = $this->resolveWorkingCapitalShares($stock)['inventory'];

        return $inventory > 0.0 ? FirmEconomics::revenueWeightedRatio($weights, [$maturing, 0.0, 0.0]) / $inventory : 0.0;
    }

    /**
     * The target mix, branded, bulk and desk in that order, and the branded stream's maturing stock over its revenue.
     *
     * @return array{0: list<float>, 1: float}
     */
    private function resolveWorkingCapitalMix(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BrandedStaplesWeight->value   => self::BRANDED_STAPLES_WEIGHT,
            ModelParam::VolumeCommodityWeight->value  => self::VOLUME_COMMODITY_WEIGHT,
            ModelParam::CommodityTradingWeight->value => 0.00,
            ModelParam::MaturingStockShare->value     => 0.00,
        ]);

        return [
            [$params[ModelParam::BrandedStaplesWeight], $params[ModelParam::VolumeCommodityWeight], $params[ModelParam::CommodityTradingWeight]],
            max(0.0, $params[ModelParam::MaturingStockShare]),
        ];
    }

    /**
     * A desk covering part of the commodity bill forward meets a spot move only as its layered contracts roll, so its
     * costs follow the market late but in full: hedging smooths the bill, it does not lower it.
     */
    protected function resolveInputCostLagYears(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [ModelParam::CommodityTradingWeight->value => 0.0]);
        $cover = min(self::MAX_COMMODITY_HEDGE_COVER, max(0.0, $params[ModelParam::CommodityTradingWeight]) * self::COMMODITY_HEDGE_MULTIPLIER);

        return parent::resolveInputCostLagYears($stock) + ($cover * self::HEDGE_BOOK_MEAN_AGE_YEARS);
    }

    /** Engel's law: pantry demand follows income at the sector's own low elasticity, whatever the brand charges for it. */
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift' => ($this->resolveLaggedOutputGap($macroState) * $this->getOperatingCyclicality($stock)) + $this->resolveFxDemandShift($macroState),
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
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
            ModelParam::BrandedStaplesWeight->value    => self::BRANDED_STAPLES_WEIGHT,
            ModelParam::VolumeCommodityWeight->value   => self::VOLUME_COMMODITY_WEIGHT,
            ModelParam::CommodityTradingWeight->value  => 0.00,
            ModelParam::AgriculturalOutputShare->value => 0.00,
        ]);
        $pricingPower = $this->resolvePricingPower($stock);

        $rawCommodityWeight = $params[ModelParam::CommodityTradingWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $targetWeights = [
            'branded' => $params[ModelParam::BrandedStaplesWeight],
            'volume'  => $params[ModelParam::VolumeCommodityWeight],
        ];
        if ($rawCommodityWeight > 0.0) {
            $targetWeights['commodity_trading'] = $rawCommodityWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $brandedWeight   = $activeWeights['branded'];
        $volumeWeight    = $activeWeights['volume'];
        $commodityWeight = $activeWeights['commodity_trading'] ?? 0.0;

        // Independent stream Z-scores with AR(1) persistence
        $brandedZ = $streams->generateZ('branded', 0.15);
        $volumeZ  = $streams->generateZ('volume', 0.15);
        $eventZ   = $streams->generateExogenousZ('event', 0.05);

        $brandedRevenue = max(0.0, $expectedRevenue * $brandedWeight * (1.0 + ($brandedZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))));
        // A grower sells part of its bulk output at the exchange price, struck on the same baseline as its input basket:
        // a dear harvest is its windfall as it is a processor's squeeze.
        $volumeAtBasePrice = max(0.0, $expectedRevenue * $volumeWeight * (1.0 + ($volumeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))));
        $volumeRevenue = max(0.0, $volumeAtBasePrice * (1.0 + ($params[ModelParam::AgriculturalOutputShare] * $this->resolveInputPriceDeviations($macroState)['agri'])));

        // Tail Risk: a recall or a fine is a one-off charge on the quarter's branded sales, booked as a special item.
        $eventType = null;
        $chargeShare = 0.0;

        if ($eventZ < self::RECALL_SEVERE_Z_SCORE) {
            $chargeShare = self::RECALL_SEVERE_PENALTY;
            $eventType = ShockEvent::PRODUCT_RECALL;
        } elseif ($eventZ < self::RECALL_MODERATE_Z_SCORE) {
            $chargeShare = self::RECALL_MODERATE_PENALTY;
            $eventType = ShockEvent::REGULATORY_FINE;
        }

        $streamRevenues = [
            'branded' => $brandedRevenue,
            'volume'  => $volumeRevenue,
        ];

        $commodityZ = 0.0;
        if ($commodityWeight > 0.0) {
            $commodityZ = $streams->generateZ('commodity_trading', 0.20);
            $streamRevenues['commodity_trading'] = max(0.0, $expectedRevenue * $commodityWeight * (1.0 + ($commodityZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::COMMODITY_TRADING_VOL_SCALAR)));
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Cost-Push Inflation & COGS Squeeze ---
        // Farm commodities, plant energy, imported packaging and line payroll reach COGS with the buying lag (longer for a
        // firm whose desk buys forward) and are recovered on the shelf with the repricing lag. The cost ratio is per unit,
        // so it is re-expressed against the price a grower realized on its bulk output.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
        $revenueAtBasePrice = $actualRevenue - ($volumeRevenue - $volumeAtBasePrice);
        $priceRelative = $revenueAtBasePrice > 0.0 ? $actualRevenue / $revenueAtBasePrice : 1.0;
        $clampedMargin = $this->clampMargin(FirmEconomics::calculatePerUnitCostRatio($realizedVariableMargin + $inputCostDrag, $priceRelative));

        // The exchange price is public and read in full; the firm's own volumes are seen at coverage visibility.
        $observableShockZ = ($brandedZ * $brandedWeight * $baselineVol * self::REVENUE_VARIANCE_SCALAR)
            + ($volumeZ * $volumeWeight * $baselineVol * self::REVENUE_VARIANCE_SCALAR)
            + ($commodityZ * $commodityWeight * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::COMMODITY_TRADING_VOL_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            observableShockZ: $observableShockZ,
            publishedRevenueShift: $priceRelative - 1.0,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: $params[ModelParam::AgriculturalOutputShare] > 0.0 ? ['realized_price_index' => $priceRelative] : [],
            specialCharge: $chargeShare * $brandedRevenue,
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
            'agricultural_commodity_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'import_price_level',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
