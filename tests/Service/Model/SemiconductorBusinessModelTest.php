<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use PHPUnit\Framework\TestCase;
use App\Service\Model\SemiconductorBusinessModel;
use App\Service\Math\MathUtility;
use App\Entity\Stock;
use PHPUnit\Framework\MockObject\MockObject;

class SemiconductorBusinessModelTest extends TestCase
{
    private SemiconductorBusinessModel $model;
    private MathUtility|MockObject $mathUtilityMock;

    protected function setUp(): void
    {
        $this->model = new SemiconductorBusinessModel();
        $this->mathUtilityMock = $this->createMock(MathUtility::class);
    }

    public function testUnderinvestingInCapexIncursYieldPenalty(): void
    {
        $stock = new Stock();
        $stock->setCapexRatio('0.20'); // Below 0.40 table stakes threshold

        // Ensure normal distribution rolls return 0.0 for deterministic revenue/cycle checks
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $expectedRevenue = 1000.0;
        $realizedVariableMargin = 0.30; // 30% baseline variable cost
        $fixedCosts = 200.0;
        $baselineVol = 0.20;
        $macroState = ['output_gap_ema' => 0.0];

        $result = $this->model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $realizedVariableMargin,
            $fixedCosts,
            $baselineVol,
            $macroState,
            $this->mathUtilityMock
        );

        // With yieldModifier = 0.08, actualVariableCosts = 1000.0 * (0.30 + 0.08) = 380.0
        $this->assertEquals(380.0, $result->actualVariableCosts);
        // EBIT = 1000.0 - 200.0 - 380.0 = 420.0
        $this->assertEquals(420.0, $result->ebit);
    }

    public function testMeetingCapexTableStakesResultsInNormalYields(): void
    {
        $stock = new Stock();
        $stock->setCapexRatio('0.80'); // Meets/exceeds 0.40 table stakes threshold

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $expectedRevenue = 1000.0;
        $realizedVariableMargin = 0.30;
        $fixedCosts = 200.0;
        $baselineVol = 0.20;
        $macroState = ['output_gap_ema' => 0.0];

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
}
