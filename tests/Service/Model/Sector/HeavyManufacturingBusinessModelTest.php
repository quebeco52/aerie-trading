<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\HeavyManufacturingBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class HeavyManufacturingBusinessModelTest extends TestCase
{
    private HeavyManufacturingBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new HeavyManufacturingBusinessModel();
        $this->mathUtility = MathUtility::ownStream(20261024);
    }

    public function testDualStreamEmissionAndBacklogDamping(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO(outputGapEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility,
        );

        $this->assertArrayHasKey('oem_equipment', $result->streamRevenue);
        $this->assertArrayHasKey('aftermarket_mro', $result->streamRevenue);
        $this->assertArrayHasKey('oem_equipment', $result->streamZ);
        $this->assertArrayHasKey('aftermarket_mro', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['oem_equipment']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['aftermarket_mro']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['oem_equipment'] + $result->streamRevenue['aftermarket_mro'],
            1.0,
        );
    }

    /**
     * A recession reaches equipment revenue through the order book: none of it lands on expected revenue, orders take
     * the swing in full while revenue takes only the burn share of it at first, and the aftermarket takes its loading
     * at once.
     */
    public function testARecessionReachesEquipmentRevenueThroughTheOrderBook(): void
    {
        $stock = (new Stock())->setTicker('CATP');
        $recession = $this->recession();
        $this->assertSame(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift']);

        $orders = $this->orderShift($stock, $recession);
        $this->assertLessThan(-0.05, $orders);
        $result = $this->model->computeActualFinancials($stock, 100.0, 0.35, 0.0, 0.0, $recession, $this->zeroDraws());

        $burn = HeavyManufacturingBusinessModel::OEM_BACKLOG_BURN_RATE;
        $this->assertEqualsWithDelta(100.0 * HeavyManufacturingBusinessModel::OEM_EQUIPMENT_WEIGHT * (1.0 + ($burn * $orders)), $result->streamRevenue['oem_equipment'], 1e-9);
        $this->assertEqualsWithDelta((1.0 + $orders) / (1.0 + ($burn * $orders)), $result->kpis['book_to_bill'], 1e-9);
        $this->assertEqualsWithDelta(100.0 * HeavyManufacturingBusinessModel::AFTERMARKET_MRO_WEIGHT * (1.0 + (HeavyManufacturingBusinessModel::MRO_CYCLE_LOADING * $orders)), $result->streamRevenue['aftermarket_mro'], 1e-9);
    }

    /**
     * Held, a recession takes equipment revenue down by the whole order swing and the aftermarket by its loading, so
     * the firm moves by its mix: an aftermarket-heavy maker is less cyclical than an equipment maker.
     */
    public function testTheAftermarketMixSetsTheFirmsCycle(): void
    {
        $stock = (new Stock())->setTicker('CATP');
        $recession = $this->recession();
        $orders = $this->orderShift($stock, $recession);
        for ($quarter = 0; $quarter < 40; ++$quarter) {
            $result = $this->model->computeActualFinancials($stock, 100.0, 0.35, 0.0, 0.0, $recession, $this->zeroDraws());
            $stock->setEarningsMomentumZ($result->streamZ);
        }

        // The mix the firm has drifted to, as the streams record it.
        $oemWeight = $result->streamZ['weight:oem_equipment'];
        $mroWeight = $result->streamZ['weight:aftermarket_mro'];
        $this->assertEqualsWithDelta(1.0 + $orders, $result->streamRevenue['oem_equipment'] / (100.0 * $oemWeight), 1e-4);
        $this->assertEqualsWithDelta(1.0 + (HeavyManufacturingBusinessModel::MRO_CYCLE_LOADING * $orders), $result->streamRevenue['aftermarket_mro'] / (100.0 * $mroWeight), 1e-4);
        $this->assertEqualsWithDelta(1.0 + (($oemWeight + ($mroWeight * HeavyManufacturingBusinessModel::MRO_CYCLE_LOADING)) * $orders), $result->actualRevenue / 100.0, 1e-4);
    }

    private function recession(): MacroStateDTO
    {
        return new MacroStateDTO(outputGapEma: -0.03, manufacturingPmiEma: 45.0, exchangeRateIndexEma: 100.0);
    }

    private function orderShift(Stock $stock, MacroStateDTO $macro): float
    {
        return (new \ReflectionMethod($this->model, 'resolveEquipmentOrderShift'))->invoke($this->model, $stock, $macro);
    }

    private function zeroDraws(): MathUtility
    {
        return new ScriptedDraws(generatePersistentZ: 0.0);
    }

    public function testExchangeRateExportDragAndMetalsCostPenalty(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.2');
        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);
        $run = fn(MacroStateDTO $macro) => $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $macro, $mathMock);

        $baseResult = $run(new MacroStateDTO(exchangeRateIndexEma: 100.0, industrialMetalsIndexEma: 100.0));
        $strongCurrency = $run(new MacroStateDTO(exchangeRateIndexEma: 120.0, industrialMetalsIndexEma: 100.0));
        $metalsSpike = $run(new MacroStateDTO(exchangeRateIndexEma: 100.0, industrialMetalsIndexEma: 140.0));

        $this->assertLessThan($baseResult->streamRevenue['oem_equipment'], $strongCurrency->streamRevenue['oem_equipment']);
        // BEA 2017: steel and other metals are 19% of the variable cost base of HVAC, machinery and
        // electrical-equipment makers.
        $this->assertGreaterThan($baseResult->clampedMargin, $metalsSpike->clampedMargin);
    }

    public function testCapitalStockOverhangDampensOemEquipmentDemand(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.0');

        $scarcityMacro = new MacroStateDTO(capitalStockOverhangEma: -0.10);
        $overhangMacro = new MacroStateDTO(capitalStockOverhangEma: 0.10);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $scarcityResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $scarcityMacro, $mathMock);
        $overhangResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $overhangMacro, $mathMock);

        $this->assertGreaterThan(
            $overhangResult->streamRevenue['oem_equipment'],
            $scarcityResult->streamRevenue['oem_equipment'],
            'Industrial capital capacity overhang must dampen OEM equipment demand relative to capital scarcity.',
        );
    }

    /**
     * A fuller plant spreads the fixed cost base over more units; the variable cost of each unit does not move.
     * The model used to cut the variable ratio on high utilisation as well, counting operating leverage twice.
     */
    public function testCapacityUtilizationLeavesTheUnitCostToTheFixedCostBase(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.0');

        $lowCuMacro = new MacroStateDTO(capacityUtilizationRateEma: 0.70); // 70% low utilization
        $highCuMacro = new MacroStateDTO(capacityUtilizationRateEma: 0.85); // 85% high utilization

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $lowCuResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $lowCuMacro, $mathMock);
        $highCuResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $highCuMacro, $mathMock);

        $this->assertEqualsWithDelta($lowCuResult->clampedMargin, $highCuResult->clampedMargin, 1e-12);
    }

    public function testGscpiBottlenecksIncreaseVariableCostDrag(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.2');

        $normalMacro = new MacroStateDTO(supplyChainPressureIndexEma: 0.0);
        $chokedMacro = new MacroStateDTO(supplyChainPressureIndexEma: 2.5);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $normalResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $normalMacro, $mathMock);
        $chokedResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, $chokedMacro, $mathMock);

        $this->assertGreaterThan(
            $normalResult->clampedMargin,
            $chokedResult->clampedMargin,
            'Global supply chain bottlenecks (GSCPI) must increase component procurement costs and variable margin drag.',
        );
    }
}
