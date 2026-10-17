<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for homebuilders: firms that buy land, build houses on it and sell them at the market price.
 *
 * Financial Physics:
 * - Orders are the firm's share of the District's housing starts and close through a backlog once the home is built.
 * - The selling price is the market's, the real house price over its measured trend. Construction is paid at the
 *   input prices of the day and recovered only as far as that market price moves.
 * - Land is carried at what it cost. The owned lots turn over in LAND_BANK_YEARS, and each purchase is struck at the
 *   residual land value, the price less the cost to build less the builder's normal margin, which moves several times
 *   as far as the house price (Bostic, Longhofer & Redfearn 2007, land leverage). A move in house prices lands on margin
 *   at once and is bid away as the bank turns over.
 * - The land bank is the builder's capacity, carried on the plant ledger and grown through the capital budget; the
 *   trade cycle holds only the homes under construction. Lots follow closings, so carrying them as working capital
 *   would let capacity, which is struck on invested capital, feed on its own inventory.
 * - A lot whose home can no longer recover its carrying cost fails the ASC 360-10 recoverability test, and the bank is
 *   written down to its residual value.
 */
class HomebuilderBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle. A builder's cycle reaches it through the housing starts and house price this model reads, so the engine's output-gap terms would count it twice (band floor). */
    public const OPERATING_CYCLICALITY = 0.20;

    // --- Input Cost Basket ---
    /** Shares of the construction cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::HOMEBUILDER;
    /** The house price is the market's: construction inflation is recovered only as far as house prices move. */
    public const PRICING_POWER_INDEX = 0.00;
    /** The house price index is real, so nominal selling prices carry expected inflation one for one; only the index's own move is a real price change. */
    public const PRICING_ELASTICITY = 1.00;

    // --- FX Exposure ---
    /** A house is not traded across the border. */
    public const FX_REVENUE_EXPOSURE = 0.00;

    // --- Orders & Closings ---
    /** Months from an order to its closing, the build: 7.0 months from permit to completion for homes built for sale (Census Survey of Construction, 2018, via NAHB). */
    public const BUILD_TIME_MONTHS = 7.0;
    /** Share of the backlog closed each quarter: the backlog holds BUILD_TIME_MONTHS / 3 quarters of closings at steady state, (1 - b) / b. */
    public const BACKLOG_BURN_RATE = 1.0 / (1.0 + (self::BUILD_TIME_MONTHS / 3.0));
    /** Quarterly persistence of the firm's own order shock (the standard corporate default). */
    public const ORDER_SHOCK_PERSISTENCE = 0.25;

    // --- Land Bank ---
    /** Finished lot's share of a new home's sale price: 17.8-21.5% over 2013-2022 and 20.3% in 2009 (NAHB, Cost of Constructing a Home 2024, table 2). */
    public const LAND_SHARE_OF_PRICE = 0.20;
    /** Default years of owned lots per year of closings: D.R. Horton FY2005, 156,000 lots owned on 51,172 closings (10-K); 3.6 in FY2006, 1.7 in FY2023 after the shift to optioned lots. */
    public const LAND_BANK_YEARS = 3.0;
    /** Stream-state key: the carrying cost of the owned lots per home, as a share of the price at trend. */
    public const STATE_LAND_COST = 'state:land_cost';

    // --- Analyst Visibility & Error ---
    /** Share of a quarter's closings already in the backlog reported as it opens, 1 - b at steady state. */
    public const BASE_COVERAGE_VISIBILITY = 1.0 - self::BACKLOG_BURN_RATE;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        // Volume reaches the firm through housing starts inside the physics; the engine applies inflation only.
        return [
            'macro_demand_shift' => 0.0,
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    /** The crews are staffed to the homes already sold and under construction as the quarter opens. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return StreamContext::openingWorkload($stock->getEarningsMomentumZ() ?? [], 'homes', self::BACKLOG_BURN_RATE) - 1.0;
    }

    /** Owned lots in years of closings, ticker override first. */
    public function resolveLandBankYears(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [ModelParam::LandBankYears->value => self::LAND_BANK_YEARS]);

        return max(EarningsEngine::QUARTERLY_TIME_STEP, (float) $params[ModelParam::LandBankYears]);
    }

    /** Market housing starts over the level they revert to: the firm keeps its share of a market of this size. */
    public function resolveStartsRelative(MacroStateDTO $macroState): float
    {
        return max(0.0, $macroState->housingStartsIndexEma) / MacroEngine::HOUSING_STARTS_BASELINE;
    }

    /** The real house price over the trend it has measurably settled at. */
    public function resolveHousePriceRelative(MacroStateDTO $macroState): float
    {
        return max(0.0, $macroState->residentialPropertyIndexEma) / max(1.0, $macroState->residentialWealthTrend);
    }

    /**
     * Residual land value per home as a share of the price at trend: what a builder pays for a lot so that the home
     * built on it earns the normal margin, the price times the variable cost ratio less the cost to build.
     */
    public static function residualLandValue(float $priceRelative, float $variableCostRatio, float $constructionCost): float
    {
        return max(0.0, ($priceRelative * $variableCostRatio) - $constructionCost);
    }

    /** Inventory over revenue: the homes under construction, half built on average over the build. The lots are plant. */
    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return (self::BUILD_TIME_MONTHS / 12.0) * $this->resolveSeededConstructionShare($stock) / 2.0;
    }

    /**
     * The cycle is the homes under construction: a home is paid for at closing and the trades as they work. Inventory
     * days are struck on total cost, so the intensity is grossed up by revenue over cost.
     */
    public function getWorkingCapitalDays(Stock $stock): array
    {
        $costRatio = max(0.01, 1.0 - (float) $stock->getOperatingMargin());

        return [
            'dso' => 0.0,
            'dio' => $this->getWorkingCapitalIntensity($stock) * FinancialConstants::DAYS_PER_YEAR / $costRatio,
            'dpo' => 0.0,
        ];
    }

    /** Construction's share of the price at the seeded cost base: the variable cost ratio less the land. */
    private function resolveSeededConstructionShare(Stock $stock): float
    {
        $variableCostRatio = $stock->getStructuralVariableMargin() !== null
            ? (float) $stock->getStructuralVariableMargin()
            : (1.0 - (float) $stock->getOperatingMargin()) * (1.0 - $stock->getFixedCostRatio());

        return max(0.0, $variableCostRatio - self::LAND_SHARE_OF_PRICE);
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
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $orderZ = $streams->generateZ('homes', self::ORDER_SHOCK_PERSISTENCE);

        // --- Orders and Closings ---
        $orderShock = $orderZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR;
        $book = $streams->recognizeBacklog('homes', $expectedRevenue, max(0.0, $this->resolveStartsRelative($macroState) * (1.0 + $orderShock)), self::BACKLOG_BURN_RATE);
        $closings = $expectedRevenue > 0.0 ? $book['revenue'] / $expectedRevenue : 1.0;
        $price = $this->resolveHousePriceRelative($macroState);
        $actualRevenue = $book['revenue'] * $price;
        $streams->recordStreamShares(['homes' => $actualRevenue]);

        // --- Cost per Home ---
        // Construction at today's input prices; land at what the lots in the bank cost.
        $constructionShare = max(0.0, $realizedVariableMargin - self::LAND_SHARE_OF_PRICE);
        $constructionCost = $constructionShare + $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $constructionShare);
        $landCost = $streams->getPersistedState(self::STATE_LAND_COST, self::LAND_SHARE_OF_PRICE);
        $costRatio = $this->clampMargin(FirmEconomics::calculatePerUnitCostRatio($constructionCost + $landCost, $price));

        // --- Land Bank: Recoverability, then Replenishment ---
        $landBankYears = $this->resolveLandBankYears($stock);
        $residualValue = self::residualLandValue($price, $realizedVariableMargin, $constructionCost);
        $impairment = 0.0;
        if ($landCost > $price - $constructionCost) {
            // The bank is valued per home at the price at trend, so it is the years of closings it holds times the
            // quarter's expected revenue, four quarters a year.
            $landBankScale = $landBankYears * FinancialConstants::QUARTERS_PER_YEAR * $expectedRevenue;
            $impairment = ($landCost - $residualValue) * $landBankScale;
            $landCost = $residualValue;
        }
        // Lots are used at the closing rate and replaced at the residual value.
        $turnover = 1.0 - exp(-EarningsEngine::QUARTERLY_TIME_STEP * $closings / $landBankYears);
        $streams->registerState(self::STATE_LAND_COST, $landCost + ($turnover * ($residualValue - $landCost)));

        // The house price is published and read in full; the backlog shows the visible share of the closings.
        $observableShockZ = $closings - 1.0;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $costRatio,
            observableShockZ: $observableShockZ,
            publishedRevenueShift: $price - 1.0,
            streamZ: $streams->getStreamZ(),
            streamRevenue: ['homes' => $actualRevenue],
            kpis: [
                'book_to_bill' => $book['book_to_bill'],
                'backlog_quarters' => $book['backlog_quarters'],
                'realized_price_index' => $price,
                'home_sales_margin' => 1.0 - $costRatio,
                'land_cost_share' => $price > 0.0 ? $landCost / $price : 0.0,
                'land_write_down' => $actualRevenue > 0.0 ? $impairment / $actualRevenue : 0.0,
            ],
            plantImpairment: $impairment,
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
            'energy_cost_push_lag',
            'housing_starts_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'real_wage_gap',
            'residential_property_index_ema',
            'residential_wealth_trend',
            'tips_breakeven_ema',
        ];
    }
}
