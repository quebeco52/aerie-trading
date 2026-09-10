<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Service\Market\Agent\AgentPopulation;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

/**
 * Discrete choice over beliefs (Brock & Hommes 1997, 1998).
 *
 * The switching is the model. If capital cannot move between beliefs, the market is permanently whatever
 * mixture it started as and none of the dynamics the population exists to produce can happen — while the
 * code still runs, still allocates, and still looks correct from the outside.
 */
class AgentPopulationTest extends TestCase
{
    private AgentPopulation $population;

    protected function setUp(): void
    {
        $this->population = new AgentPopulation();
    }

    public function testSharesAlwaysSumToOne(): void
    {
        foreach ([[0.0, 0.0], [0.5, -0.3], [10.0, -10.0], [-4.0, -4.0]] as [$a, $b]) {
            $shares = $this->population->shares(['fundamentalist' => $a, 'momentum' => $b]);

            $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        }
    }

    public function testEqualFitnessSplitsCapitalEvenly(): void
    {
        $shares = $this->population->shares(['fundamentalist' => 0.2, 'momentum' => 0.2]);

        $this->assertEqualsWithDelta(0.5, $shares['fundamentalist'], 1e-12);
        $this->assertEqualsWithDelta(0.5, $shares['momentum'], 1e-12);
    }

    public function testCapitalMovesTowardWhicheverBeliefHasBeenPaying(): void
    {
        $shares = $this->population->shares(['fundamentalist' => 0.40, 'momentum' => 0.05]);

        $this->assertGreaterThan($shares['momentum'], $shares['fundamentalist']);
    }

    public function testTheAllocationIsMonotonicInFitness(): void
    {
        $previous = 0.0;

        for ($edge = 0.0; $edge <= 1.0; $edge += 0.1) {
            $share = $this->population->shares(['fundamentalist' => $edge, 'momentum' => 0.0])['fundamentalist'];

            $this->assertGreaterThanOrEqual($previous, $share);
            $previous = $share;
        }
    }

    public function testALosingBeliefIsNeverExtinguished(): void
    {
        // A strategy driven to a zero share holds nothing, so it earns nothing, so it never scores again
        // and can never come back. The market would be permanently one-sided, which is not what happens
        // and would remove the very switching this model exists to produce.
        $shares = $this->population->shares(['fundamentalist' => 100.0, 'momentum' => -100.0]);

        $this->assertGreaterThanOrEqual(FinancialConstants::AGENT_MIN_POPULATION_SHARE, $shares['momentum']);
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
    }

    public function testAnEnormousFitnessDoesNotOverflowIntoNaN(): void
    {
        // exp(beta * U) with a large U is INF, and INF/INF is NaN. Every share would become NaN and the
        // market would silently empty itself. The exponentials are taken against the best score for
        // exactly this reason.
        $shares = $this->population->shares(['fundamentalist' => 1.0e6, 'momentum' => -1.0e6]);

        foreach ($shares as $share) {
            $this->assertIsFloat($share);
            $this->assertFalse(is_nan($share));
            $this->assertGreaterThanOrEqual(0.0, $share);
        }

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
    }

    public function testAnEmptyPopulationIsHandledRatherThanDividedBy(): void
    {
        $this->assertSame([], $this->population->shares([]));
    }

    // --- Fitness ---

    public function testABeliefIsOnlyRewardedForHavingHadMoneyOnIt(): void
    {
        // Being right in the abstract earns nothing: a strategy flat into a rally scores zero.
        $fitness = $this->population->updateFitness(
            ['fundamentalist' => 0.0, 'momentum' => 0.0],
            ['fundamentalist' => 1000.0, 'momentum' => 0.0],
            0.01,
            1000.0,
            1.0 / 14400.0
        );

        $this->assertGreaterThan(0.0, $fitness['fundamentalist']);
        $this->assertSame(0.0, $fitness['momentum']);
    }

    public function testBeingLongIntoAFallScoresNegatively(): void
    {
        $fitness = $this->population->updateFitness(
            ['momentum' => 0.0],
            ['momentum' => 1000.0],
            -0.01,
            1000.0,
            1.0 / 14400.0
        );

        $this->assertLessThan(0.0, $fitness['momentum']);
    }

    public function testAShortPositionProfitsFromAFall(): void
    {
        $fitness = $this->population->updateFitness(
            ['fundamentalist' => 0.0],
            ['fundamentalist' => -1000.0],
            -0.01,
            1000.0,
            1.0 / 14400.0
        );

        $this->assertGreaterThan(0.0, $fitness['fundamentalist']);
    }

    /**
     * The bug that made the whole mechanism inert on the first pass.
     *
     * A raw per-tick profit at a 14,400-tick year is on the order of 1e-5, and no sane intensity of choice
     * can tell two such numbers apart: the population sat at its initial split forever while the code ran
     * and allocated and looked entirely correct.
     */
    public function testFitnessIsAnAnnualizedRateAndNotAPerTickCrumb(): void
    {
        $fitness = $this->population->updateFitness(
            ['fundamentalist' => 0.0],
            ['fundamentalist' => 1000.0],
            0.002,
            1000.0,
            1.0 / 14400.0
        );

        // Fully committed, earning 0.2% in a tick: an annualized rate on the order of tens, not 1e-5.
        $this->assertGreaterThan(0.001, $fitness['fundamentalist']);
    }

    public function testTheSameEconomicsScoreTheSameAtAnyTickRate(): void
    {
        // Annualizing is what makes a configured intensity of choice mean the same thing whatever the
        // simulation steps at. Compared over the same span of simulated time rather than over one step: a
        // longer step legitimately takes in more of the new information per update, and the two only have
        // to agree on where they converge.
        $fine = ['f' => 0.0];
        for ($tick = 0; $tick < 14400; $tick++) {
            $fine = $this->population->updateFitness($fine, ['f' => 1000.0], 0.002, 1000.0, 1.0 / 14400.0);
        }

        $coarse = ['f' => 0.0];
        for ($tick = 0; $tick < 7200; $tick++) {
            $coarse = $this->population->updateFitness($coarse, ['f' => 1000.0], 0.004, 1000.0, 2.0 / 14400.0);
        }

        $this->assertEqualsWithDelta($fine['f'], $coarse['f'], abs($fine['f']) * 0.02);
    }

    public function testTheScoreIsSmoothedSoOneGoodTickCannotEmptyTheOtherSide(): void
    {
        $once = $this->population->updateFitness(['f' => 0.0], ['f' => 1000.0], 0.01, 1000.0, 1.0 / 14400.0);

        $sustained = ['f' => 0.0];
        for ($tick = 0; $tick < 2000; $tick++) {
            $sustained = $this->population->updateFitness($sustained, ['f' => 1000.0], 0.01, 1000.0, 1.0 / 14400.0);
        }

        $this->assertGreaterThan($once['f'] * 10.0, $sustained['f'], 'A persistent edge must accumulate.');
    }

    public function testAStaleScoreDecaysOnceTheEdgeIsGone(): void
    {
        $fitness = ['f' => 5.0];

        for ($tick = 0; $tick < 14400; $tick++) {
            $fitness = $this->population->updateFitness($fitness, ['f' => 0.0], 0.0, 1000.0, 1.0 / 14400.0);
        }

        $this->assertLessThan(1.0, $fitness['f'], 'A belief cannot coast on a year-old edge.');
    }

    public function testZeroElapsedTimeChangesNothingRatherThanDividingByIt(): void
    {
        $fitness = ['f' => 0.25];

        $this->assertSame($fitness, $this->population->updateFitness($fitness, ['f' => 1000.0], 0.01, 1000.0, 0.0));
    }
}
