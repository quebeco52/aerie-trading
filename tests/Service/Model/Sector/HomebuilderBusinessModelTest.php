<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\HomebuilderBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

final class HomebuilderBusinessModelTest extends TestCase
{
    /** The variable cost ratio the fixtures run at: land 0.20 and construction 0.58 of the price at trend. */
    private const VARIABLE_COST_RATIO = 0.78;
    private const CONSTRUCTION_SHARE = self::VARIABLE_COST_RATIO - HomebuilderBusinessModel::LAND_SHARE_OF_PRICE;
    private const EXPECTED_REVENUE = 100.0;

    private HomebuilderBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new HomebuilderBusinessModel();
    }

    /** Every order draw at zero, so housing starts and the house price are the only things moving. */
    private function quietDraws(): MathUtility
    {
        return new ScriptedDraws(generatePersistentZ: 0.0);
    }

    private function builder(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('GEN_BUILDER');

        return $stock;
    }

    /** House price over its trend and housing starts against their level; every input price at baseline. */
    private function macro(float $price = 1.0, float $starts = 100.0): MacroStateDTO
    {
        return new MacroStateDTO(residentialPropertyIndexEma: 100.0 * $price, residentialWealthTrend: 100.0, housingStartsIndexEma: $starts);
    }

    /** Land cost per unit of house price that the builder carries forward on the stock. */
    private function landCost(Stock $stock): float
    {
        $state = $stock->getEarningsMomentumZ();
        $this->assertNotNull($state);
        $this->assertArrayHasKey(HomebuilderBusinessModel::STATE_LAND_COST, $state);

        return $state[HomebuilderBusinessModel::STATE_LAND_COST];
    }

    /** One report, carrying the stream state forward on the stock the way the engine does. */
    private function report(Stock $stock, MacroStateDTO $macro): ActualFinancialsDTO
    {
        $actuals = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: self::EXPECTED_REVENUE,
            realizedVariableMargin: self::VARIABLE_COST_RATIO,
            fixedCosts: 0.0,
            baselineVol: 0.35,
            macroState: $macro,
            mathUtility: $this->quietDraws(),
        );
        $stock->setEarningsMomentumZ($actuals->streamZ);

        return $actuals;
    }

    public function testAtTrendTheBuilderEarnsItsSeededMarginAndImpairsNothing(): void
    {
        $actuals = $this->report($this->builder(), $this->macro());

        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE, $actuals->actualRevenue, 1e-9);
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO, $actuals->clampedMargin, 1e-9);
        $this->assertSame(0.0, $actuals->plantImpairment, 'land at its normal share recovers its cost');
        $this->assertEqualsWithDelta(HomebuilderBusinessModel::LAND_SHARE_OF_PRICE, $actuals->streamZ[HomebuilderBusinessModel::STATE_LAND_COST], 1e-12);
    }

    public function testResidualLandValueMovesSeveralTimesAsFarAsTheHousePrice(): void
    {
        // Land leverage: the lot is the residual claim, so its value moves by (variable cost ratio / land share) per
        // unit of house price, 3.9 here, and a 10% price move is a 39% move in what land is worth.
        $atTrend = HomebuilderBusinessModel::residualLandValue(1.0, self::VARIABLE_COST_RATIO, self::CONSTRUCTION_SHARE);
        $rise = HomebuilderBusinessModel::residualLandValue(1.1, self::VARIABLE_COST_RATIO, self::CONSTRUCTION_SHARE);
        $fall = HomebuilderBusinessModel::residualLandValue(0.9, self::VARIABLE_COST_RATIO, self::CONSTRUCTION_SHARE);

        $this->assertEqualsWithDelta(HomebuilderBusinessModel::LAND_SHARE_OF_PRICE, $atTrend, 1e-12);
        $this->assertEqualsWithDelta(0.39, $rise / $atTrend - 1.0, 1e-9);
        $this->assertEqualsWithDelta(-0.39, $fall / $atTrend - 1.0, 1e-9);
        $this->assertSame(0.0, HomebuilderBusinessModel::residualLandValue(0.7, self::VARIABLE_COST_RATIO, self::CONSTRUCTION_SHARE), 'land is never worth less than nothing');
    }

    public function testAHousePriceRiseLandsOnMarginAndIsBidAwayAsTheLandBankTurns(): void
    {
        $stock = $this->builder();
        $boom = $this->macro(1.10);

        // The first quarter sells homes on land bought at trend: the whole price rise is margin.
        $first = $this->report($stock, $boom);
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO / 1.10, $first->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta(1.10 * self::EXPECTED_REVENUE, $first->actualRevenue, 1e-9);

        // New lots are bought at the residual value, so once the bank has turned the margin is back to normal.
        for ($quarter = 0; $quarter < 80; $quarter++) {
            $last = $this->report($stock, $boom);
        }
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO, $last->clampedMargin, 1e-4);

        // The bank turns at the closing rate over LAND_BANK_YEARS: a quarter replaces 1 - exp(-0.25 / 3) of it.
        $fresh = $this->builder();
        $this->report($fresh, $boom);
        $residual = HomebuilderBusinessModel::residualLandValue(1.10, self::VARIABLE_COST_RATIO, self::CONSTRUCTION_SHARE);
        $turnover = 1.0 - exp(-FinancialConstants::QUARTER_YEARS / HomebuilderBusinessModel::LAND_BANK_YEARS);
        $expected = HomebuilderBusinessModel::LAND_SHARE_OF_PRICE + ($turnover * ($residual - HomebuilderBusinessModel::LAND_SHARE_OF_PRICE));
        $this->assertEqualsWithDelta($expected, $this->landCost($fresh), 1e-12);
    }

    public function testLandIsWrittenDownOnlyWhenTheHomeCannotRecoverIt(): void
    {
        // A 15% fall squeezes the margin but every lot still recovers its cost: ASC 360 books nothing.
        $squeezed = $this->report($this->builder(), $this->macro(0.85));
        $this->assertSame(0.0, $squeezed->plantImpairment);
        $this->assertGreaterThan(self::VARIABLE_COST_RATIO, $squeezed->clampedMargin);

        // At 75% of trend the home fetches 0.75 against 0.58 to build and 0.20 of land: the lot fails the test and
        // the whole bank is written down to its residual value.
        $stock = $this->builder();
        $bust = $this->report($stock, $this->macro(0.75));
        $residual = HomebuilderBusinessModel::residualLandValue(0.75, self::VARIABLE_COST_RATIO, self::CONSTRUCTION_SHARE);
        $bankScale = HomebuilderBusinessModel::LAND_BANK_YEARS * 4.0 * self::EXPECTED_REVENUE;
        $this->assertEqualsWithDelta((HomebuilderBusinessModel::LAND_SHARE_OF_PRICE - $residual) * $bankScale, $bust->plantImpairment, 1e-9);
        $this->assertEqualsWithDelta($residual, $this->landCost($stock), 1e-12);

        // The lots sold that quarter still went out at what they cost; written-down land prices the next one.
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO / 0.75, $bust->clampedMargin, 1e-9);
        $next = $this->report($stock, $this->macro(0.75));
        $this->assertSame(0.0, $next->plantImpairment, 'land already at fair value is not written down again');
        $this->assertEqualsWithDelta((self::CONSTRUCTION_SHARE + $residual) / 0.75, $next->clampedMargin, 1e-9);
    }

    public function testClosingsFollowHousingStartsThroughTheBacklog(): void
    {
        $stock = $this->builder();
        $slump = $this->macro(1.0, 70.0);

        // Orders fall 30% at once, but only the burn rate of them closes in the quarter they are taken.
        $first = $this->report($stock, $slump);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * (1.0 - (0.30 * HomebuilderBusinessModel::BACKLOG_BURN_RATE)), $first->actualRevenue, 1e-9);
        $this->assertLessThan(1.0, $first->kpis['book_to_bill']);

        for ($quarter = 0; $quarter < 40; $quarter++) {
            $last = $this->report($stock, $slump);
        }
        $this->assertEqualsWithDelta(70.0, $last->actualRevenue, 1e-3, 'at steady state the firm closes its share of the starts');

        // The crews are staffed to the book in hand, so a run-down backlog is what the cost base sees.
        // The book holds 70% of its normal quarters; the quarter's own orders are not known yet and count at normal.
        $burn = HomebuilderBusinessModel::BACKLOG_BURN_RATE;
        $openingQuarters = 0.70 * (1.0 - $burn) / $burn;
        $this->assertEqualsWithDelta(($burn * ($openingQuarters + 1.0)) - 1.0, $this->model->resolveSectorActivityShift($stock, $slump), 1e-4);
        $this->assertEqualsWithDelta(0.0, $this->model->resolveSectorActivityShift($this->builder(), $slump), 1e-12, 'a fresh book reads normal whatever the cycle');
    }

    public function testBacklogHoldsTheBuildTime(): void
    {
        $quarters = (1.0 - HomebuilderBusinessModel::BACKLOG_BURN_RATE) / HomebuilderBusinessModel::BACKLOG_BURN_RATE;

        $this->assertEqualsWithDelta(HomebuilderBusinessModel::BUILD_TIME_MONTHS / 3.0, $quarters, 1e-12);
        $this->assertEqualsWithDelta($quarters, $this->report($this->builder(), $this->macro())->kpis['backlog_quarters'], 1e-9);
    }

    public function testConstructionInflationSqueezesTheBuilderUntilLandAbsorbsIt(): void
    {
        $stock = $this->builder();
        $dearer = new MacroStateDTO(industrialMetalsIndexEma: 140.0, realWageGap: 0.05);
        $exposures = HomebuilderBusinessModel::INPUT_COST_EXPOSURES;
        $deviation = ($exposures['metals'] * 0.40) + ($exposures['labor'] * 0.05);

        // The price is the market's, so nothing is passed on: the homes sold now carry the dearer build in full.
        $first = $this->report($stock, $dearer);
        $this->assertEqualsWithDelta((self::CONSTRUCTION_SHARE * (1.0 + $deviation)) + HomebuilderBusinessModel::LAND_SHARE_OF_PRICE, $first->clampedMargin, 1e-9);

        // Land is the residual claim: lots bought after the rise cost that much less, so once the bank has turned
        // the builder is back at its normal margin and the land price has taken the whole increase.
        for ($quarter = 0; $quarter < 80; $quarter++) {
            $last = $this->report($stock, $dearer);
        }
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO, $last->clampedMargin, 1e-4);
        $this->assertEqualsWithDelta(HomebuilderBusinessModel::LAND_SHARE_OF_PRICE - (self::CONSTRUCTION_SHARE * $deviation), $this->landCost($stock), 1e-4);
    }

    public function testTheTradeCycleIsTheHomesUnderConstruction(): void
    {
        $stock = $this->builder();
        $stock->setOperatingMargin('0.13');
        $stock->setFixedCostRatio(0.10);

        // The lots are plant: only the homes being built, half built on average over the build, are inventory.
        $construction = (1.0 - 0.13) * (1.0 - 0.10) - HomebuilderBusinessModel::LAND_SHARE_OF_PRICE;
        $intensity = (HomebuilderBusinessModel::BUILD_TIME_MONTHS / 12.0) * $construction / 2.0;
        $this->assertEqualsWithDelta($intensity, $this->model->getWorkingCapitalIntensity($stock), 1e-12);

        $days = $this->model->getWorkingCapitalDays($stock);
        $this->assertSame(0.0, $days['dso']);
        $this->assertSame(0.0, $days['dpo']);
        // Inventory days are struck on total cost, so at the seeded margin they hold the intensity's share of revenue.
        $this->assertEqualsWithDelta($intensity, $days['dio'] / 365.0 * (1.0 - 0.13), 1e-12);
    }
}
