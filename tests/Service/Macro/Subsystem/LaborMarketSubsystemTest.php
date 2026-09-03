<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use PHPUnit\Framework\TestCase;

class LaborMarketSubsystemTest extends TestCase
{
    private LaborMarketSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->subsystem = new LaborMarketSubsystem();
    }

    public function testOkunUnemploymentRisesInRecession(): void
    {
        $state = new MacroState();
        $state->unemploymentRate = 0.04;
        $state->outputGap = -0.04;

        $this->subsystem->calculateUnemployment($state, 0.25);
        $this->assertGreaterThan(0.04, $state->unemploymentRate);
    }

    public function testBeveridgeMatchingDeterminesVacanciesAndWageGrowth(): void
    {
        $state = new MacroState();
        $state->unemploymentRate = 0.04;
        $state->wageGrowth = 0.035;

        $this->subsystem->calculateLaborMarketAndWages($state, 0.015, 0.25);

        $this->assertGreaterThan(0.0, $state->jobVacanciesRate);
        $this->assertGreaterThan(0.0, $state->laborTightness);
        $this->assertGreaterThan(0.0, $state->wageGrowth);
    }
}
