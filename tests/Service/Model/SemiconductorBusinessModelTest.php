<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Service\Model\Sector\SemiconductorBusinessModel;
use App\Service\Math\MathUtility;
use App\Entity\Stock;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
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

        // Elevated energy price = 120.0 (20% energy spike)
        // energyShift = 0.20 -> energyDrag = 0.20 * 0.35 * 0.85 = 0.0595
        $macroSpike = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema' => 0.0,
            'energy_price_index_ema' => 120.0,
            'energy_cost_push_lag' => 0.0020,
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
        $this->assertEqualsWithDelta(359.5, $resultSpike->actualVariableCosts, 0.1);
    }
}
