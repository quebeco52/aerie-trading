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


    public function testUnemploymentNeverBreachesFrictionalFloorInExtremeBoom(): void
    {
        $state = new MacroState();
        $state->unemploymentRate = 0.04;
        $state->unemploymentRateEma = 0.04;
        $state->outputGap = 0.08;

        for ($i = 0; $i < 40; $i++) {
            $this->subsystem->calculateUnemployment($state, 0.25);
        }

        $this->assertGreaterThanOrEqual(\App\Service\Macro\MacroEngine::MIN_FRICTIONAL_UNEMPLOYMENT, $state->unemploymentRate);
        $this->assertGreaterThan(0.03, $state->unemploymentRate, 'Search friction keeps unemployment above 3% even at an 8% output gap.');
    }
}
