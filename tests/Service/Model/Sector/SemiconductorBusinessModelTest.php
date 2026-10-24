<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Model\AbstractBusinessModel;
use App\Service\Model\Sector\SemiconductorBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

class SemiconductorBusinessModelTest extends TestCase
{
    private SemiconductorBusinessModel $model;
    private ScriptedDraws $mathUtilityMock;

    protected function setUp(): void
    {
        $this->model = new SemiconductorBusinessModel();
        $this->mathUtilityMock = new ScriptedDraws();
    }

    /**
     * The wafer cost ratio does not read the seeded reinvestment rate: under-investment costs a fab its capacity and
     * its return as the plant ages, and a mature-node fab spending below depreciation runs the best yields it has.
     */
    public function testTheWaferCostRatioDoesNotReadTheReinvestmentRate(): void
    {
        $costs = function (string $capexRatio): float {
            $stock = new Stock();
            $stock->setTicker('SEMI');
            $stock->setCapexRatio($capexRatio);

            return $this->model->computeActualFinancials($stock, 1000.0, 0.30, 200.0, 0.20, MacroStateDTO::fromArray(['output_gap_ema' => 0.0]), $this->mathUtilityMock)->actualVariableCosts;
        };

        $this->assertEqualsWithDelta(300.0, $costs('0.80'), 1e-9);
        $this->assertEqualsWithDelta($costs('0.80'), $costs('0.20'), 1e-9);
    }

    public function testMetalsAreTheMeasuredInputAndCleanroomPowerIsImmaterial(): void
    {
        $stock = new Stock();
        $stock->setTicker('TSMC');
        $stock->setCapexRatio('0.80');
        $run = fn(array $macro) => $this->model->computeActualFinancials($stock, 1000.0, 0.30, 200.0, 0.20, MacroStateDTO::fromArray(['output_gap_ema' => 0.0] + $macro), $this->mathUtilityMock);

        $baseline = $run([]);
        // Fab power is 2.3% of output, but industrial tariffs pass a wholesale move through at only 0.106 (EIA), which
        // puts it under InputOutputExposures::MATERIALITY_FLOOR; oil is under 0.5%.
        $powerSpike = $run(['wholesale_power_price_index_ema' => 150.0, 'energy_price_index_ema' => 120.0, 'energy_cost_push_lag' => 0.20 * MacroConstants::ENERGY_COST_PUSH_TRANSMISSION]);
        $this->assertEqualsWithDelta($baseline->clampedMargin, $powerSpike->clampedMargin, 1e-12);

        // Metals (copper, gold, specialty metals) through the supply chain are the device maker's material commodity
        // input. The basket buys at spot and scarce wafer capacity recovers pricingPower x MAX_INPUT_COST_PASS_THROUGH
        // with the lag.
        $metalsSpike = $run(['industrial_metals_index_ema' => 120.0]);
        $metalsDeviation = 0.20 * SemiconductorBusinessModel::INPUT_COST_EXPOSURES['metals'];
        $recoveryWeight = 1.0 - exp(-FinancialConstants::QUARTER_YEARS / AbstractBusinessModel::INPUT_PASS_THROUGH_LAG_YEARS);
        $recovered = SemiconductorBusinessModel::PRICING_POWER_INDEX * SemiconductorBusinessModel::MAX_INPUT_COST_PASS_THROUGH * $recoveryWeight;
        $this->assertEqualsWithDelta(1000.0 * 0.30 * $metalsDeviation * (1.0 - $recovered), $metalsSpike->actualVariableCosts - $baseline->actualVariableCosts, 0.1);
        $this->assertLessThan($baseline->ebit, $metalsSpike->ebit);
    }

    public function testCapacityUtilizationDrivesFoundryLeverage(): void
    {
        $stock = new Stock();
        $stock->setTicker('FOUNDRY');
        $stock->setCapexRatio('0.80');

        $macroNormal = MacroStateDTO::fromArray([
            'output_gap_ema' => 0.0,
            'capacity_utilization_rate_ema' => 0.785,
        ]);

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 200.0,
            baselineVol: 0.0,
            macroState: $macroNormal,
            mathUtility: $this->mathUtilityMock,
        );

        $macroBoom = MacroStateDTO::fromArray([
            'output_gap_ema' => 0.0,
            'capacity_utilization_rate_ema' => 0.835, // +5% above baseline
        ]);

        $resultBoom = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 200.0,
            baselineVol: 0.0,
            macroState: $macroBoom,
            mathUtility: $this->mathUtilityMock,
        );

        $this->assertGreaterThan($resultNormal->streamRevenue['foundry'], $resultBoom->streamRevenue['foundry'], 'Elevated industrial capacity utilization must expand foundry throughput.');
    }
    public function testChannelInventoryOverhangCutsWaferOrdersAndShortfallRestocks(): void
    {
        $model = new SemiconductorBusinessModel();
        $run = function (float $inventoryGap) use ($model) {
            $stock = new Stock();
            $stock->setTicker('FAB');
            $stock->setBeta('1.2');
            $math = new ScriptedDraws(generatePersistentZ: 0.0);
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, new MacroStateDTO(inventoryStockGapEma: $inventoryGap), $math);
        };

        $neutral = $run(0.0);
        $overhang = $run(0.10);   // distributors sit on excess chips: destocking
        $shortfall = $run(-0.10); // channel is empty: restocking

        // Orders move first; recognized foundry revenue follows at the wafer-out burn rate.
        $this->assertLessThan($neutral->kpis['book_to_bill'], $overhang->kpis['book_to_bill']);
        $this->assertGreaterThan($neutral->kpis['book_to_bill'], $shortfall->kpis['book_to_bill']);
        $this->assertLessThan($neutral->streamRevenue['foundry'], $overhang->streamRevenue['foundry']);
        $this->assertGreaterThan($neutral->streamRevenue['foundry'], $shortfall->streamRevenue['foundry']);
    }

}
