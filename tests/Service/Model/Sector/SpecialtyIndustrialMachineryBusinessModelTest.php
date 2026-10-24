<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector\SpecialtyIndustrialMachineryBusinessModel;
use App\Service\Model\StreamContext;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SpecialtyIndustrialMachineryBusinessModelTest extends TestCase
{
    private SpecialtyIndustrialMachineryBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new SpecialtyIndustrialMachineryBusinessModel();
    }

    public function testDualStreamEmissionAndFxMetalsSensitivity(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPEC_MACH');
        $stock->setBeta('1.3');

        $baseMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            exchangeRateIndexEma: 100.0,
            industrialMetalsIndexEma: 100.0,
            energyPriceIndexEma: 100.0,
        );

        $shockMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            exchangeRateIndexEma: 120.0, // Strong currency export headwind
            industrialMetalsIndexEma: 140.0, // Precision alloy cost drag
            energyPriceIndexEma: 100.0,
        );

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $baseMacro,
            mathUtility: $mathMock,
        );

        $shockResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $shockMacro,
            mathUtility: $mathMock,
        );

        $this->assertArrayHasKey('equipment_sales', $baseResult->streamRevenue);
        $this->assertArrayHasKey('aftermarket_services', $baseResult->streamRevenue);

        // Equipment sales drop under strong dollar export headwind
        $this->assertLessThan(
            $baseResult->streamRevenue['equipment_sales'],
            $shockResult->streamRevenue['equipment_sales'],
        );

        // Variable cost margin expands (clampedMargin increases) under metals cost drag
        $this->assertGreaterThan(
            $baseResult->clampedMargin,
            $shockResult->clampedMargin,
        );
    }

    public function testCapitalStockOverhangDampensEquipmentSales(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPEC_MACH');
        $stock->setBeta('1.0');

        $scarcityMacro = new MacroStateDTO(capitalStockOverhangEma: -0.10);
        $overhangMacro = new MacroStateDTO(capitalStockOverhangEma: 0.10);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $scarcityResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $scarcityMacro, $mathMock);
        $overhangResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $overhangMacro, $mathMock);

        $this->assertGreaterThan(
            $overhangResult->streamRevenue['equipment_sales'],
            $scarcityResult->streamRevenue['equipment_sales'],
            'Excess capital capacity overhang must dampen specialty equipment orders relative to capital scarcity.',
        );
    }

    /**
     * The services the installed fleet buys follow it at the aftermarket's loading of the order swing, at once, while
     * equipment takes the swing through its order book: the services mix is what makes a specialty maker less cyclical.
     */
    public function testAftermarketServicesFollowTheFleetAtTheirLoading(): void
    {
        $stock = (new Stock())->setTicker('SPEC_MACH');
        $recession = new MacroStateDTO(outputGapEma: -0.03, manufacturingPmiEma: 45.0, exchangeRateIndexEma: 100.0);
        $orders = (new \ReflectionMethod($this->model, 'resolveEquipmentOrderShift'))->invoke($this->model, $stock, $recession);
        $this->assertLessThan(-0.05, $orders);

        $math = new ScriptedDraws(generatePersistentZ: 0.0);
        $result = $this->model->computeActualFinancials($stock, 100.0, 0.30, 0.0, 0.0, $recession, $math);

        $burn = SpecialtyIndustrialMachineryBusinessModel::EQUIPMENT_BACKLOG_BURN_RATE;
        $this->assertEqualsWithDelta(100.0 * SpecialtyIndustrialMachineryBusinessModel::SERVICES_WEIGHT * (1.0 + (SpecialtyIndustrialMachineryBusinessModel::MRO_CYCLE_LOADING * $orders)), $result->streamRevenue['aftermarket_services'], 1e-9);
        $this->assertEqualsWithDelta(100.0 * SpecialtyIndustrialMachineryBusinessModel::EQUIPMENT_WEIGHT * (1.0 + ($burn * $orders)), $result->streamRevenue['equipment_sales'], 1e-9);
    }

    public function testGscpiDisruptionAddsVariableCostPenalty(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPEC_MACH');
        $stock->setBeta('1.0');

        $normalMacro = new MacroStateDTO(supplyChainPressureIndexEma: 0.0);
        $delayedMacro = new MacroStateDTO(supplyChainPressureIndexEma: 2.0);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $normalResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $normalMacro, $mathMock);
        $delayedResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $delayedMacro, $mathMock);

        $this->assertGreaterThan(
            $normalResult->clampedMargin,
            $delayedResult->clampedMargin,
            'Global supply chain bottlenecks (GSCPI) delay precision parts and increase variable margin drag.',
        );
    }

    public function testTheCostBaseStaffsToTheEquipmentBacklog(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPEC_MACH');
        $recession = new MacroStateDTO(outputGapEma: -0.03, exchangeRateIndexEma: 100.0);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);
        // The services the fleet buys fall at once, at their loading of the order swing and their 0.30 of the mix.
        $services = SpecialtyIndustrialMachineryBusinessModel::SERVICES_WEIGHT * SpecialtyIndustrialMachineryBusinessModel::MRO_CYCLE_LOADING
            * (new \ReflectionMethod($this->model, 'resolveEquipmentOrderShift'))->invoke($this->model, $stock, $recession);
        $this->assertLessThan(0.0, $services);
        $this->assertEqualsWithDelta($services, $this->model->resolveSectorActivityShift($stock, $recession), 1e-12, 'A book at steady state is normal equipment work.');

        // One quarter of equipment backlog in hand: 0.35 x (1 + 1) = 0.70 of normal work, at 0.70 of the mix = -0.21.
        $stock->setEarningsMomentumZ([StreamContext::BACKLOG_STATE_PREFIX . 'equipment_sales' => 1.0]);
        $this->assertEqualsWithDelta(-0.21 + $services, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }
}
