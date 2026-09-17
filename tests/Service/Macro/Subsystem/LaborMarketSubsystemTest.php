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

        $this->assertGreaterThanOrEqual(LaborMarketSubsystem::MIN_FRICTIONAL_UNEMPLOYMENT, $state->unemploymentRate);
        $this->assertGreaterThan(0.03, $state->unemploymentRate, 'Search friction keeps unemployment above 3% even at an 8% output gap.');
    }

    /**
     * Settles wage growth against a fixed labour market, varying only what inflation is expected to be.
     */
    private function convergedWageGrowth(float $expectedInflation): float
    {
        $state = new MacroState();
        $state->unemploymentRate = \App\Service\Macro\MacroEngine::NATURAL_UNEMPLOYMENT;
        $state->nairu = \App\Service\Macro\MacroEngine::NATURAL_UNEMPLOYMENT;
        $state->tipsBreakeven = $expectedInflation;
        $state->tipsBreakevenEma = $expectedInflation;
        $state->wageGrowth = 0.0;

        // Partial adjustment at WAGE_ADJUSTMENT_SPEED closes half the gap each quarter; 80 of them settle it.
        for ($i = 0; $i < 80; $i++) {
            $this->subsystem->calculateLaborMarketAndWages($state, \App\Service\Macro\MacroEngine::TFP_DRIFT, 0.25);
        }

        return $state->wageGrowth;
    }

    /**
     * Friedman (1968) / Phelps (1967): the coefficient on expected inflation in a wage Phillips curve is
     * one, or the long-run curve is not vertical and the economy carries permanent money illusion.
     *
     * Anchored to the fixed target instead, wage demands ignored inflation entirely: a sustained energy
     * shock lifted headline inflation by ~195bps and nominal wage growth by 2bps, so real pay fell by the
     * whole of the shock and the second round of the spiral never happened.
     */
    public function testWageDemandsIndexToExpectedInflationOneForOne(): void
    {
        $anchored = $this->convergedWageGrowth(0.02);
        $unanchored = $this->convergedWageGrowth(0.05);

        $this->assertEqualsWithDelta(
            0.03,
            $unanchored - $anchored,
            1e-6,
            'Three points more expected inflation must become three points more nominal wage growth.'
        );
    }

    /**
     * The same restriction read as its consequence: what workers settle for in REAL terms is productivity
     * plus whatever the labour market is worth, and is not moved by the inflation rate they expect.
     */
    public function testRealWageGrowthIsIndependentOfTheExpectedInflationRate(): void
    {
        foreach ([0.00, 0.02, 0.04, 0.06] as $expectedInflation) {
            $real = $this->convergedWageGrowth($expectedInflation) - $expectedInflation;

            $this->assertEqualsWithDelta(
                $this->convergedWageGrowth(0.02) - 0.02,
                $real,
                1e-6,
                sprintf('Real wage growth moved when only expected inflation changed (%.0f%%).', $expectedInflation * 100)
            );
            $this->assertEqualsWithDelta(
                \App\Service\Macro\MacroEngine::TFP_DRIFT,
                $real,
                1e-9,
                'At its natural rate the tightness term vanishes, so real pay is exactly productivity.'
            );
        }
    }

    /**
     * The indexation runs into the standing 8% ceiling on nominal wage growth once expected inflation
     * passes 6.5%, and real pay falls from there however tight the labour market is.
     *
     * The ceiling predates the indexation and used to be unreachable, because demands were anchored to a
     * 2% target and settled near 3.5%. It is now the binding constraint on how far a wage-price spiral can
     * run, so it is pinned here rather than left to be discovered inside a stagflation scenario.
     */
    public function testNominalWageGrowthIsCappedOnceExpectedInflationPassesTheCeiling(): void
    {
        $headroom = 0.08 - \App\Service\Macro\MacroEngine::TFP_DRIFT;

        $this->assertEqualsWithDelta(0.08, $this->convergedWageGrowth($headroom), 1e-6, 'The ceiling is reached exactly at its headroom.');
        $this->assertEqualsWithDelta(0.08, $this->convergedWageGrowth(0.12), 1e-6, 'Beyond it, nominal wage growth stops rising.');
        $this->assertLessThan(0.0, $this->convergedWageGrowth(0.12) - 0.12, 'Past the ceiling, real pay falls.');
    }
}
