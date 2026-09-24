<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class LaborMarketSubsystemTest extends TestCase
{
    private LaborMarketSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->subsystem = new LaborMarketSubsystem(new MathUtility());
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
        $state->inflation = $expectedInflation;
        $state->wageGrowth = 0.0;

        // Partial adjustment at WAGE_ADJUSTMENT_SPEED closes half the growth gap each quarter; the level the start-up
        // leaves behind error-corrects on a ~7 year half-life, so a century settles both.
        for ($i = 0; $i < 400; $i++) {
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
    /**
     * Scarring has to be reversible. The re-absorption term used to be the product of two deviations,
     * which is a rounding error at every reachable state: measured over 960 simulated years NAIRU only
     * ever ratcheted up, +0.58pp per 80 years in all twelve seeds, its maximum always equal to its final
     * value. The old test only asserted the sign of one year's move from a hand-built 6% NAIRU, so an
     * inert recovery passed it. This pins the rate, and therefore the closure of the ratchet.
     */
    public function testScarringUnwindsOnceTheLabourMarketTightensAgain(): void
    {
        $state = new MacroState();
        $state->nairu = MacroEngine::NATURAL_UNEMPLOYMENT;
        $state->unemploymentRate = 0.060;
        $state->outputGap = -0.04;

        // Two years held 2pp above NAIRU. The EMA is pinned by hand so this exercises the NAIRU law
        // rather than Okun's Law feeding back into it.
        for ($quarter = 0; $quarter < 8; $quarter++) {
            $state->unemploymentRateEma = 0.060;
            $this->subsystem->calculateUnemployment($state, 0.25);
        }
        $scarred = $state->nairu;

        // 0.10/yr x (6.0% - 4.0% - 0.5%) x 2yr ~ +0.29pp, shrinking slightly as NAIRU itself rises.
        $this->assertGreaterThan(
            MacroEngine::NATURAL_UNEMPLOYMENT + 0.0020,
            $scarred,
            'Two years of sustained slack must leave structural scarring behind.'
        );

        // Ten years with the market tight. Re-absorption is linear in the scarring, so the excess decays
        // geometrically: (1 - 0.10 x 0.25)^40 of it survives.
        $state->unemploymentRate = 0.035;
        for ($quarter = 0; $quarter < 40; $quarter++) {
            $state->unemploymentRateEma = 0.035;
            $this->subsystem->calculateUnemployment($state, 0.25);
        }

        $surviving = (1.0 - (LaborMarketSubsystem::NAIRU_REABSORPTION_SPEED * 0.25)) ** 40;
        $expected = MacroEngine::NATURAL_UNEMPLOYMENT
            + (($scarred - MacroEngine::NATURAL_UNEMPLOYMENT) * $surviving);

        $this->assertEqualsWithDelta(
            $expected,
            $state->nairu,
            1e-6,
            'A tight labour market must re-absorb scarring at its own speed, not at the product of two deviations.'
        );
        $this->assertLessThan(
            MacroEngine::NATURAL_UNEMPLOYMENT + (($scarred - MacroEngine::NATURAL_UNEMPLOYMENT) * 0.40),
            $state->nairu,
            'Most of the scarring must be gone after a decade of tight labour markets: the ratchet has to close.'
        );
    }

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


    // --- Wage Error Correction ---

    /** A labour market at rest: natural unemployment and tightness, wages already growing at their target. */
    private function restingLabourMarket(float $realWageGap): MacroState
    {
        $state = new MacroState();
        $state->unemploymentRate = MacroEngine::NATURAL_UNEMPLOYMENT;
        $state->nairu = MacroEngine::NATURAL_UNEMPLOYMENT;
        $state->tipsBreakevenEma = 0.02;
        $state->inflation = 0.02;
        $state->wageGrowth = 0.02 + MacroEngine::TFP_DRIFT;
        $state->realWageGap = $realWageGap;

        return $state;
    }

    public function testTheRealWageGapIntegratesWagesLessPricesAndTrendProductivity(): void
    {
        $state = $this->restingLabourMarket(0.0);
        $state->inflation = 0.03;

        $this->subsystem->calculateLaborMarketAndWages($state, MacroEngine::TFP_DRIFT, 0.25);

        // Wages held at 3.5% while prices ran at 3% and productivity at 1.5%: a quarter of a one-point real squeeze.
        $this->assertEqualsWithDelta(0.035, $state->wageGrowth, 1e-12);
        $this->assertEqualsWithDelta((0.035 - 0.03 - MacroEngine::TFP_DRIFT) * 0.25, $state->realWageGap, 1e-12);
    }

    public function testARealWageAboveItsProductivityPathSlowsWageGrowth(): void
    {
        $atTrend = $this->restingLabourMarket(0.0);
        $above = $this->restingLabourMarket(0.02);

        $this->subsystem->calculateLaborMarketAndWages($atTrend, MacroEngine::TFP_DRIFT, 0.25);
        $this->subsystem->calculateLaborMarketAndWages($above, MacroEngine::TFP_DRIFT, 0.25);

        // The target falls by the correction speed times the gap; wages move down toward it at the rigid speed.
        $expected = -LaborMarketSubsystem::WAGE_ERROR_CORRECTION_SPEED * 0.02
            * LaborMarketSubsystem::WAGE_ADJUSTMENT_SPEED * LaborMarketSubsystem::WAGE_DOWNWARD_RIGIDITY_FACTOR * 0.25;
        $this->assertEqualsWithDelta($expected, $above->wageGrowth - $atTrend->wageGrowth, 1e-12);
    }

    /**
     * The level is bounded: a real wage 2% above its path closes without overshooting, never faster than the
     * correction speed alone allows (the partial adjustment of the growth rate only slows it), and at the same
     * pace whatever the tick.
     */
    public function testTheGapClosesAtTheCorrectionSpeedAndIsTimestepNeutral(): void
    {
        $halfLives = [];
        foreach ([1 / 360, 1 / 90] as $dt) {
            $state = $this->restingLabourMarket(0.02);
            $halfLife = null;
            $lowest = 1.0;
            for ($i = 1; $i <= (int) round(40 / $dt); $i++) {
                $this->subsystem->calculateLaborMarketAndWages($state, MacroEngine::TFP_DRIFT, $dt);
                $lowest = min($lowest, $state->realWageGap);
                if ($halfLife === null && $state->realWageGap <= 0.01) {
                    $halfLife = $i * $dt;
                }
            }

            $this->assertNotNull($halfLife, 'The gap never halved.');
            $this->assertGreaterThanOrEqual(0.0, $lowest, 'The correction overshot into a real wage below trend.');
            $this->assertLessThan(0.02 * 0.01, $state->realWageGap, 'Forty years leave under 1% of the gap.');
            $halfLives[] = $halfLife;
        }

        $floor = log(2.0) / LaborMarketSubsystem::WAGE_ERROR_CORRECTION_SPEED;
        $this->assertGreaterThanOrEqual($floor, $halfLives[0]);
        $this->assertLessThan(1.5 * $floor, $halfLives[0]);
        $this->assertEqualsWithDelta($halfLives[0], $halfLives[1], 0.02, 'The half-life moved with the tick.');
    }


    // --- Work Stoppages ---

    private function scriptedStoppages(bool $arrives, float $uniform, float $duration): LaborMarketSubsystem
    {
        $math = new class ($arrives, $uniform, $duration) extends MathUtility {
            public function __construct(private readonly bool $arrives, private readonly float $uniform, private readonly float $duration) { parent::__construct(); }
            public function checkProbability(float $probability): bool { return $this->arrives; }
            public function generateUniform(): float { return $this->uniform; }
            public function generateExponential(float $rate = 1.0): float { return $this->duration; }
        };

        return new LaborMarketSubsystem($math);
    }

    public function testAStoppageStrikesOneMacroSectorAndRunsItsDrawnDuration(): void
    {
        $subsystem = $this->scriptedStoppages(arrives: true, uniform: 0.5, duration: 0.10);
        $state = new MacroState();
        $state->totalTime = 2.0;

        $subsystem->advanceWorkStoppages($state, 0.01);

        $this->assertNotNull($state->strikeSector);
        $this->assertArrayHasKey($state->strikeSector, \App\Data\Sectors::MACRO_SECTORS, 'The struck sector is one of the macro sectors the sector factor is drawn for.');
        $this->assertEqualsWithDelta(0.10, $state->strikeRemainingYears, 1e-9);
        $this->assertSame(2.0, $state->strikeStartedAt, 'The start tick is recorded so the district event fires once.');

        // Runs down with the clock, and no second stoppage starts while one is on.
        $subsystem->advanceWorkStoppages($state, 0.05);
        $this->assertEqualsWithDelta(0.05, $state->strikeRemainingYears, 1e-9);
        $this->assertNotNull($state->strikeSector);

        $subsystem->advanceWorkStoppages($state, 0.05);
        $this->assertNull($state->strikeSector, 'The stoppage ends when its duration is spent.');
        $this->assertSame(0.0, $state->strikeRemainingYears);
    }

    public function testNoStoppageStartsWithoutAnArrival(): void
    {
        $subsystem = $this->scriptedStoppages(arrives: false, uniform: 0.5, duration: 0.10);
        $state = new MacroState();

        $subsystem->advanceWorkStoppages($state, 0.25);

        $this->assertNull($state->strikeSector);
    }

    public function testAHeadlineSettlementLiftsTheWageTargetWhileTheStoppageRuns(): void
    {
        $calm = new MacroState();
        $calm->unemploymentRate = MacroEngine::NATURAL_UNEMPLOYMENT;
        $calm->wageGrowth = 0.035;

        $struck = new MacroState();
        $struck->unemploymentRate = MacroEngine::NATURAL_UNEMPLOYMENT;
        $struck->wageGrowth = 0.035;
        $struck->strikeSector = 'Industrials';

        $this->subsystem->calculateLaborMarketAndWages($calm, 0.015, 0.25);
        $this->subsystem->calculateLaborMarketAndWages($struck, 0.015, 0.25);

        $this->assertGreaterThan($calm->wageGrowth, $struck->wageGrowth, 'A settlement above trend spills into the economy-wide wage target.');
        $this->assertLessThan(
            LaborMarketSubsystem::STRIKE_WAGE_SETTLEMENT_BUMP,
            $struck->wageGrowth - $calm->wageGrowth,
            'One quarter of a stoppage moves wages by only part of the settlement premium: the target adjusts at the wage speed, not at once.'
        );
    }
}
