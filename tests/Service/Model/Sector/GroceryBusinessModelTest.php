<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\Sector\GroceryBusinessModel;
use App\Service\Model\StreamContext;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class GroceryBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.86;
    /** A price war three quarters in. */
    private const AT_WAR = [StreamContext::REGIME_STATE_PREFIX . GroceryBusinessModel::REGIME_PRICE_WAR => 3.0];

    private GroceryBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new GroceryBusinessModel();
    }

    private static function macro(float $domesticGap = 0.0, float $agri = 100.0, float $energy = 0.0): MacroStateDTO
    {
        return new MacroStateDTO(
            domesticDemandGapEma: $domesticGap,
            agriculturalCommodityIndexEma: $agri,
            energyCostPushLag: $energy * MacroConstants::ENERGY_COST_PUSH_TRANSMISSION,
        );
    }

    /**
     * One quarter with every draw at zero and no regime starting or ending.
     *
     * @param array<string, float> $momentum
     */
    private function runQuarter(MacroStateDTO $macro, array $momentum = []): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('GROCERY_TEST')->setBeta('1.00');
        $stock->setEarningsMomentumZ($momentum);

        return $this->model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            10_000_000.0,
            0.0,
            $macro,
            new ScriptedDraws(generatePersistentZ: fn(float $prev): float => 0.0),
        );
    }

    /** Shelf prices in peace: what the price wars give up, carried between them. */
    private static function peacePrice(): float
    {
        $share = StochasticProcesses::twoStateStationaryShare(GroceryBusinessModel::PRICE_WAR_ONSET_HAZARD, GroceryBusinessModel::PRICE_WAR_EXIT_HAZARD);

        return 1.0 + (GroceryBusinessModel::PRICE_WAR_DISCOUNT * $share);
    }

    /** Store running costs as a share of plan revenue: the variable costs less the planned merchandise. */
    private static function storeCostRatio(): float
    {
        return self::VARIABLE_COST_RATIO - (0.90 * GroceryBusinessModel::GROCERY_MERCHANDISE_COST_SHARE) - (0.10 * (1.0 - GroceryBusinessModel::FORECOURT_MARGIN));
    }

    public function testACalmQuarterSellsThePlanAtThePlannedCostRatio(): void
    {
        $result = $this->runQuarter(self::macro());

        $this->assertEqualsWithDelta(90_000_000.0 * self::peacePrice(), $result->streamRevenue['groceries'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0, $result->streamRevenue['fuel_forecourts'], 1.0);
        // The peace premium is price: the suppliers and the stores cost the plan.
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * self::VARIABLE_COST_RATIO, $result->actualVariableCosts, 1.0);
        $this->assertNull($result->eventType);
    }

    public function testADownturnGrowsTheBasketsAndShrinksTheLitres(): void
    {
        $gap = -0.03;
        $result = $this->runQuarter(self::macro(domesticGap: $gap));
        $basketShift = $gap * GroceryBusinessModel::BASKET_DEMAND_GAP_SENSITIVITY;
        $fuelShift = $gap * GroceryBusinessModel::FUEL_VOLUME_DEMAND_SENSITIVITY;

        $this->assertGreaterThan(0.0, $basketShift);
        $this->assertEqualsWithDelta(90_000_000.0 * (1.0 + $basketShift) * self::peacePrice(), $result->streamRevenue['groceries'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0 * (1.0 + $fuelShift), $result->streamRevenue['fuel_forecourts'], 1.0);
        $this->assertEqualsWithDelta(1.0 + $basketShift, $result->kpis['basket_volume_index'], 1e-12);

        // The stores are staffed to the baskets and litres sold.
        $stock = (new Stock())->setTicker('GROCERY_TEST');
        $this->assertEqualsWithDelta((0.90 * $basketShift) + (0.10 * $fuelShift), $this->model->resolveSectorActivityShift($stock, self::macro(domesticGap: $gap)), 1e-12);
    }

    public function testDearerFoodLiftsSalesAndGrossProfitTogether(): void
    {
        $calm = $this->runQuarter(self::macro());
        $dear = $this->runQuarter(self::macro(agri: 140.0));
        $shelf = 1.0 + (GroceryBusinessModel::FARM_SHARE_OF_SHELF_PRICE * 0.40);

        $this->assertEqualsWithDelta($shelf, $dear->streamRevenue['groceries'] / $calm->streamRevenue['groceries'], 1e-12);
        // A percentage markup: the suppliers' bill rises with the shelf price, the stores cost what they did, so the
        // shelves' gross profit rises in proportion too.
        $merchandiseRise = 90_000_000.0 * ($shelf - 1.0) * GroceryBusinessModel::GROCERY_MERCHANDISE_COST_SHARE;
        $this->assertEqualsWithDelta($merchandiseRise, $dear->actualVariableCosts - $calm->actualVariableCosts, 1.0);
        $grossProfit = static fn(ActualFinancialsDTO $result, float $shelfPrice): float => $result->streamRevenue['groceries'] - (90_000_000.0 * $shelfPrice * GroceryBusinessModel::GROCERY_MERCHANDISE_COST_SHARE);
        $this->assertEqualsWithDelta($shelf, $grossProfit($dear, $shelf) / $grossProfit($calm, 1.0), 1e-9);
    }

    public function testFuelMovesSalesButNotTheMarginOnALitre(): void
    {
        $calm = $this->runQuarter(self::macro());
        $dear = $this->runQuarter(self::macro(energy: 0.40));
        $pump = 1.0 + (GroceryBusinessModel::PUMP_ENERGY_SHARE * 0.40);

        $this->assertEqualsWithDelta(10_000_000.0 * $pump, $dear->streamRevenue['fuel_forecourts'], 1.0);
        // Sales rise by the pump price; costs rise by the same dollars, so profit is where it was.
        $this->assertEqualsWithDelta($dear->actualRevenue - $calm->actualRevenue, $dear->actualVariableCosts - $calm->actualVariableCosts, 1.0);
    }

    public function testAPriceWarCutsTheShelfAndPaysTheSuppliersTheSame(): void
    {
        $calm = $this->runQuarter(self::macro());
        $war = $this->runQuarter(self::macro(), self::AT_WAR);
        $share = StochasticProcesses::twoStateStationaryShare(GroceryBusinessModel::PRICE_WAR_ONSET_HAZARD, GroceryBusinessModel::PRICE_WAR_EXIT_HAZARD);
        $warPrice = 1.0 - (GroceryBusinessModel::PRICE_WAR_DISCOUNT * (1.0 - $share));

        $this->assertEqualsWithDelta(90_000_000.0 * $warPrice, $war->streamRevenue['groceries'], 1.0);
        $this->assertEqualsWithDelta($calm->actualVariableCosts, $war->actualVariableCosts, 1.0);
        $this->assertEqualsWithDelta(4.0, $war->streamZ[StreamContext::REGIME_STATE_PREFIX . GroceryBusinessModel::REGIME_PRICE_WAR], 1e-12);

        // Over the regime's stationary distribution the shelf price averages the plan.
        $this->assertEqualsWithDelta(1.0, ($share * $warPrice) + ((1.0 - $share) * self::peacePrice()), 1e-12);
    }

    public function testTheStoreRunningCostsTakeTheWageDragNotTheMerchandise(): void
    {
        $this->assertGreaterThan(0.05, self::storeCostRatio());
        $this->assertLessThan(0.25, self::storeCostRatio());
        $this->assertArrayNotHasKey('agri', GroceryBusinessModel::INPUT_COST_EXPOSURES);
    }
}
