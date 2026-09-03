<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class MacroAggregateSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private MacroAggregateSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $this->subsystem = new MacroAggregateSubsystem($this->mathUtility);
    }

    public function testTfpAndNaturalRateEvolveContinuously(): void
    {
        $state = new MacroState();
        $state->totalFactorProductivityIndex = 100.0;
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $state->outputGapEma = 0.01;

        $tfpGrowth = $this->subsystem->calculateTotalFactorProductivity($state, 0.25);
        $this->subsystem->calculateNaturalRate($state, $tfpGrowth, 0.25);

        $this->assertGreaterThan(0.0, $state->totalFactorProductivityIndex);
        $this->assertGreaterThan(0.0, $state->naturalRate);
    }

    public function testOutputGapAndInflationRespondToShocks(): void
    {
        $state = new MacroState();
        $state->outputGap = 0.0;
        $state->inflation = 0.02;
        $state->policyRate = 0.02;
        $state->wageGrowth = 0.035;

        $newGap = $this->subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $newInflation = $this->subsystem->calculateInflation($state, MacroEngine::TARGET_INFLATION, 1.0, 0.25);

        $this->assertGreaterThan(-0.12, $newGap);
        $this->assertLessThan(0.10, $newGap);
        $this->assertGreaterThan(-0.02, $newInflation);
    }
}
