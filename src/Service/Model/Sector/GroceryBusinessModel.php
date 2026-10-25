<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Grocery Stores (supermarket chains with fuel forecourts).
 *
 * Financial Physics:
 * - A Reseller's Markup: the chain buys food at wholesale and sells it on a percentage markup, so dearer food lifts
 *   sales and gross profit together; what squeezes it is the cost of running the stores.
 * - Eating In: a household that cuts back eats out less and cooks more, so baskets grow a little in a downturn and
 *   shrink a little in a boom.
 * - Food Prices: shelf prices follow the farm price of food at the farm's share of the shelf price.
 * - Fuel Forecourts: pump prices follow oil, and the margin on a litre is about fixed, so fuel moves sales far more
 *   than profit.
 * - Price Wars: a rival's discount drive cuts shelf prices for a couple of years at a time; the suppliers are paid the
 *   same, so the discount comes out of the chain's margin.
 * - Store Running Costs: payroll, power and supplies follow the baskets and litres sold, and wages above trend are won
 *   back only in part.
 */
class GroceryBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (0.3): food is bought whatever the economy does. */
    public const OPERATING_CYCLICALITY = 0.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. Shoppers compare baskets. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers: shoppers are won from rival chains. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.80;

    // --- Input Cost Basket ---
    /**
     * Shares of the store running costs by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own). Resold food and fuel are priced in the model.
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::GROCERY;

    // --- Cost Efficiency ---
    /**
     * Share of the variable costs the chain's own efficiency moves (13%): the store running costs, about an eighth of
     * them; suppliers and fuel are bought at market prices and passed on.
     */
    public const VARIABLE_COST_NOISE_SHARE = 0.13;

    // --- Pricing ---
    /** Shelf-price leverage: the discounters set the price of a basket. */
    public const PRICING_POWER_INDEX = 0.35;

    // --- Balance Sheet Realism ---
    /** Capitalized store and warehouse leases as a fraction of annual revenue (10%): most stores are leased. */
    public const LEASE_LIABILITY_INTENSITY = 0.10;

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (40%): like-for-like sales and the fuel price are public. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    /** Standard deviation of the consensus forecast error (0.03): food sales are the steadiest on the board. */
    public const BASE_COVERAGE_ERROR = 0.03;

    // --- Lines of Business ---
    /** Baseline fraction of revenue from groceries: food, drink and household goods. */
    public const GROCERIES_WEIGHT = 0.90;
    /** Baseline fraction of revenue from the fuel forecourts at the large stores. */
    public const FUEL_FORECOURTS_WEIGHT = 0.10;

    // --- Gross Margins ---
    /** Share of grocery sales paid to suppliers (73%): a gross margin of 27%. */
    public const GROCERY_MERCHANDISE_COST_SHARE = 0.73;
    /** Margin on a litre of fuel as a share of the planned pump price (7%): about fixed whatever oil costs. */
    public const FORECOURT_MARGIN = 0.07;

    // --- Baskets ---
    /**
     * Basket volume per unit of the domestic demand gap, both ways: households cutting back cook at home more than they
     * cut their food bill, so a -3% gap adds 0.9%.
     */
    public const BASKET_DEMAND_GAP_SENSITIVITY = -0.30;
    /** Basket volume per unit of the confidence residual over the cycle, both ways: gloomy households eat in. */
    public const BASKET_MOOD_SENSITIVITY = -0.20;
    /** Share of the shelf price that is the farm price of the food (15%). */
    public const FARM_SHARE_OF_SHELF_PRICE = 0.15;

    // --- Fuel ---
    /** Litres sold per unit of the domestic demand gap, both ways: driving follows spending. */
    public const FUEL_VOLUME_DEMAND_SENSITIVITY = 0.30;
    /** Share of the pump price that moves with the energy price (55%): crude and refining, before duty and margin. */
    public const PUMP_ENERGY_SHARE = 0.55;

    // --- Price Wars ---
    /** Regime key for a price war (two-state Markov). */
    public const REGIME_PRICE_WAR = 'price_war';
    /** Quarterly probability a rival starts a discount drive (2.5%): about one a decade. */
    public const PRICE_WAR_ONSET_HAZARD = 0.025;
    /** Quarterly probability a price war ends (10%): about two and a half years. */
    public const PRICE_WAR_EXIT_HAZARD = 0.10;
    /** Shelf-price cut while a price war lasts (2%). */
    public const PRICE_WAR_DISCOUNT = 0.02;

    // --- Revenue Volatility ---
    /** Volatility multiplier on grocery sales: a new store, a lost one, the weather. */
    public const GROCERY_VARIANCE_SCALAR = 0.06;
    /** Volatility multiplier on fuel volume: a rival forecourt opening down the road. */
    public const FUEL_VARIANCE_SCALAR = 0.10;

    // --- Return Reversion ---
    /** Excess return over the required return the moat sustains (1pp): the best store sites. */
    public const MOAT_SPREAD = 0.010;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.5): store refits and openings. */
    public const CAPEX_CYCLICALITY = 0.50;
    /** Net working capital as a share of revenue (-3%): stock sells before the suppliers are paid. */
    public const WORKING_CAPITAL_INTENSITY = -0.03;

    // --- Seasonality ---
    /** Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: the holiday quarter is the biggest. */
    public const SEASONALITY_FACTORS = [0.98, 1.00, 0.99, 1.03];

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (3x). */
    public const MIN_ICR = 3.0;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (2x). */
    public const DIVIDEND_CRISIS_ICR = 2.0;
    /** Interest coverage below which the firm stops repurchasing stock (3x). */
    public const BUYBACK_MIN_ICR = 3.0;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Baskets and litres follow households in calculateSectorPhysics(), against the cycle for the baskets.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /** The store work the chain is staffed for: baskets and litres over plan, at the line weights. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return 0.0;
        }

        return (($weights['groceries'] * $this->resolveBasketShift($macroState)) + ($weights['fuel_forecourts'] * $this->resolveFuelVolumeShift($macroState))) / $total;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        $plan = $total > 0.0 ? $expectedRevenue / $total : 0.0;

        $groceryZ = $streams->generateZ('groceries', 0.60);
        $fuelZ = $streams->generateZ('fuel_forecourts', 0.30);

        // --- Price war: measured against its stationary share, so a war costs what the peace between them carries ---
        $warElapsed = $streams->evolveRegime(self::REGIME_PRICE_WAR, self::PRICE_WAR_ONSET_HAZARD, self::PRICE_WAR_EXIT_HAZARD);
        $warShare = StochasticProcesses::twoStateStationaryShare(self::PRICE_WAR_ONSET_HAZARD, self::PRICE_WAR_EXIT_HAZARD);
        $warPrice = 1.0 - (self::PRICE_WAR_DISCOUNT * (($warElapsed > 0 ? 1.0 : 0.0) - $warShare));

        // --- Groceries: baskets at the shelf price the farm price sets ---
        $farmPrice = ($macroState->agriculturalCommodityIndexEma - MacroConstants::AGRI_BASELINE) / MacroConstants::AGRI_BASELINE;
        $shelfPrice = 1.0 + (self::FARM_SHARE_OF_SHELF_PRICE * $farmPrice);
        $baskets = $plan * $weights['groceries'] * max(0.0, 1.0 + $this->resolveBasketShift($macroState) + ($groceryZ * $baselineVol * self::GROCERY_VARIANCE_SCALAR));

        // --- Fuel: litres at the pump price oil sets, the energy price the cost basket reads ---
        $energyPrice = $macroState->energyCostPushLag / MacroConstants::ENERGY_COST_PUSH_TRANSMISSION;
        $pumpPrice = max(0.0, 1.0 + (self::PUMP_ENERGY_SHARE * $energyPrice));
        $litres = $plan * $weights['fuel_forecourts'] * max(0.0, 1.0 + $this->resolveFuelVolumeShift($macroState) + ($fuelZ * $baselineVol * self::FUEL_VARIANCE_SCALAR));

        $streamRevenues = [
            'groceries'       => $baskets * $shelfPrice * $warPrice,
            'fuel_forecourts' => $litres * $pumpPrice,
        ];
        $actualRevenue = array_sum($streamRevenues);

        // --- Costs: suppliers at the shelf price before any discount, fuel at the pump price less the litre's margin,
        // and the store running costs on the baskets and litres sold ---
        $plannedMerchandise = $plan * (($weights['groceries'] * self::GROCERY_MERCHANDISE_COST_SHARE) + ($weights['fuel_forecourts'] * (1.0 - self::FORECOURT_MARGIN)));
        $storeCostRatio = $expectedRevenue > 0.0 ? max(0.0, $realizedVariableMargin - ($plannedMerchandise / $expectedRevenue)) : 0.0;
        $variableCosts = ($baskets * $shelfPrice * self::GROCERY_MERCHANDISE_COST_SHARE)
            + ($litres * ($pumpPrice - self::FORECOURT_MARGIN))
            + ($storeCostRatio * ($baskets + $litres));

        // Wages above trend reach the store running costs alone, won back at the chain's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $storeCostRatio);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $this->clampMargin(($actualRevenue > 0.0 ? $variableCosts / $actualRevenue : $realizedVariableMargin) + $inputCostDrag),
            eventType: $warElapsed === 1 ? ShockEvent::GROCERY_PRICE_WAR : null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'basket_volume_index' => 1.0 + $this->resolveBasketShift($macroState),
                'shelf_price_index'   => $shelfPrice * $warPrice,
            ],
        );
    }

    /** Baskets over plan: households cutting back, or gloomy, eat in. */
    private function resolveBasketShift(MacroStateDTO $macroState): float
    {
        return ($macroState->domesticDemandGapEma * self::BASKET_DEMAND_GAP_SENSITIVITY) + ($macroState->sentimentResidual() * self::BASKET_MOOD_SENSITIVITY);
    }

    /** Litres over plan: driving follows household spending. */
    private function resolveFuelVolumeShift(MacroStateDTO $macroState): float
    {
        return $macroState->domesticDemandGapEma * self::FUEL_VOLUME_DEMAND_SENSITIVITY;
    }

    /**
     * @return array{groceries: float, fuel_forecourts: float}
     */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GroceriesWeight->value      => self::GROCERIES_WEIGHT,
            ModelParam::FuelForecourtsWeight->value => self::FUEL_FORECOURTS_WEIGHT,
        ]);

        return [
            'groceries'       => (float) $params[ModelParam::GroceriesWeight],
            'fuel_forecourts' => (float) $params[ModelParam::FuelForecourtsWeight],
        ];
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
            'consumer_sentiment_index_ema',
            'domestic_demand_gap_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'import_price_level',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
