<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Service\Macro\MacroEngine;
use App\Service\Model\Sector\SemiconductorBusinessModel;
use App\Service\Math\FinancialConstants;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\MathUtility;
use App\Entity\Stock;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SemiconductorBusinessModelTest extends TestCase
{
    private SemiconductorBusinessModel $model;
    private MathUtility&MockObject $mathUtilityMock;

    protected function setUp(): void
    {
        $this->model = new SemiconductorBusinessModel();
        $this->mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
    }

    public function testUnderinvestingInCapexIncursYieldPenalty(): void
    {
        $stock = new Stock();
        $stock->setTicker('SEMI');
        $stock->setCapexRatio('0.20'); // Below 0.40 table stakes threshold

        // Ensure normal distribution rolls return 0.0 for deterministic revenue/cycle checks
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $expectedRevenue = 1000.0;
        $realizedVariableMargin = 0.30; // 30% baseline variable cost
        $fixedCosts = 200.0;
        $baselineVol = 0.20;
        $macroState = \App\DTO\MacroStateDTO::fromArray(['output_gap_ema' => 0.0]);

        $result = $this->model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $realizedVariableMargin,
            $fixedCosts,
            $baselineVol,
            $macroState,
            $this->mathUtilityMock
        );

        // With yieldModifier = 0.08 * 0.85 = 0.068, actualVariableCosts = 1000.0 * (0.30 + 0.068) = 368.0
        $this->assertEquals(368.0, $result->actualVariableCosts);
        // EBIT = 1000.0 - 200.0 - 368.0 = 432.0
        $this->assertEquals(432.0, $result->ebit);
    }

    public function testMeetingCapexTableStakesResultsInNormalYields(): void
    {
        $stock = new Stock();
        $stock->setTicker('SEMI');
        $stock->setCapexRatio('0.80'); // Meets/exceeds 0.40 table stakes threshold

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $expectedRevenue = 1000.0;
        $realizedVariableMargin = 0.30;
        $fixedCosts = 200.0;
        $baselineVol = 0.20;
        $macroState = \App\DTO\MacroStateDTO::fromArray(['output_gap_ema' => 0.0]);

        $result = $this->model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $realizedVariableMargin,
            $fixedCosts,
            $baselineVol,
            $macroState,
            $this->mathUtilityMock
        );

        // With yieldModifier = 0.00, actualVariableCosts = 1000.0 * 0.30 = 300.0
        $this->assertEquals(300.0, $result->actualVariableCosts);
        // EBIT = 1000.0 - 200.0 - 300.0 = 500.0
        $this->assertEquals(500.0, $result->ebit);
    }

    public function testCleanroomEnergySpikeIncreasesFoundryVariableCosts(): void
    {
        $stock = new Stock();
        $stock->setTicker('TSMC');
        $stock->setCapexRatio('0.80');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        // Baseline energy price = 100.0
        $macroBaseline = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema' => 0.0,
            'energy_price_index_ema' => 100.0,
        ]);

        $resultBaseline = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 200.0,
            baselineVol: 0.20,
            macroState: $macroBaseline,
            mathUtility: $this->mathUtilityMock
        );

        // Elevated energy price = 120.0 (20% energy spike). Cleanroom power is bought at spot, so the basket
        // lands 0.20 x energy exposure in the cost base this quarter, and scarce wafer capacity recovers
        // pricingPower x MAX_INPUT_COST_PASS_THROUGH of it with the pass-through lag.
        $macroSpike = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema' => 0.0,
            'energy_price_index_ema' => 120.0,
            'energy_cost_push_lag' => 0.20 * MacroEngine::ENERGY_COST_PUSH_TRANSMISSION,
        ]);

        $resultSpike = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 200.0,
            baselineVol: 0.20,
            macroState: $macroSpike,
            mathUtility: $this->mathUtilityMock
        );

        $this->assertGreaterThan($resultBaseline->clampedMargin, $resultSpike->clampedMargin);
        $this->assertLessThan($resultBaseline->ebit, $resultSpike->ebit);
        $energyDeviation = 0.20 * SemiconductorBusinessModel::INPUT_COST_EXPOSURES['energy'];
        $recoveryWeight = 1.0 - exp(-EarningsEngine::QUARTERLY_TIME_STEP / FinancialConstants::DEFAULT_INPUT_PASS_THROUGH_LAG_YEARS);
        $recovered = SemiconductorBusinessModel::PRICING_POWER_INDEX * SemiconductorBusinessModel::MAX_INPUT_COST_PASS_THROUGH * $recoveryWeight;
        $expectedDrag = 0.30 * $energyDeviation * (1.0 - $recovered);
        $this->assertEqualsWithDelta(1000.0 * (0.30 + $expectedDrag), $resultSpike->actualVariableCosts, 0.1);
    }

    public function testCapacityUtilizationDrivesFoundryLeverage(): void
    {
        $stock = new Stock();
        $stock->setTicker('FOUNDRY');
        $stock->setCapexRatio('0.80');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $macroNormal = \App\DTO\MacroStateDTO::fromArray([
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
            mathUtility: $this->mathUtilityMock
        );

        $macroBoom = \App\DTO\MacroStateDTO::fromArray([
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
            mathUtility: $this->mathUtilityMock
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
            $math = $this->createStub(MathUtility::class);
            $math->method('generatePersistentZ')->willReturn(0.0);
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
