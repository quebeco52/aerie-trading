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

    public function testABeliefWithNoConvictionScoresNothing(): void
    {
        // Being right in the abstract earns nothing: a belief whose agents were flat into a rally scores zero.
        $fitness = $this->population->updateFitness(
            ['fundamentalist' => 0.0, 'momentum' => 0.0],
            ['fundamentalist' => 1.0, 'momentum' => 0.0],
            0.01,
            1.0 / 14400.0,
            0.0,
            0.0
        );

        $this->assertGreaterThan(0.0, $fitness['fundamentalist']);
        $this->assertSame(0.0, $fitness['momentum']);
    }

    public function testABeliefIsScoredOnWhatOneOfItsAgentsHeldNotOnHowManyAgentsItHas(): void
    {
        // Brock & Hommes score the profit of ONE agent of each type; the population share only enters
        // market clearing. Two beliefs at the same conviction into the same return score the same, however
        // much capital each happens to hold. Scored on the aggregate book, the minority's fitness was
        // compressed toward zero whatever it believed and the majority gained share whenever both were right.
        $fitness = $this->population->updateFitness(
            ['majority' => 0.0, 'minority' => 0.0],
            ['majority' => 0.8, 'minority' => 0.8],
            0.01,
            1.0 / 14400.0,
            0.0,
            0.09
        );

        $this->assertEqualsWithDelta($fitness['majority'], $fitness['minority'], 1e-15);
    }

    public function testBeingLongIntoAFallScoresNegatively(): void
    {
        $fitness = $this->population->updateFitness(
            ['momentum' => 0.0],
            ['momentum' => 1.0],
            -0.01,
            1.0 / 14400.0,
            0.0,
            0.0
        );

        $this->assertLessThan(0.0, $fitness['momentum']);
    }

    public function testAShortPositionProfitsFromAFall(): void
    {
        $fitness = $this->population->updateFitness(
            ['fundamentalist' => 0.0],
            ['fundamentalist' => -1.0],
            -0.01,
            1.0 / 14400.0,
            0.0,
            0.0
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
            ['fundamentalist' => 1.0],
            0.002,
            1.0 / 14400.0,
            0.0,
            0.0
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
            $fine = $this->population->updateFitness($fine, ['f' => 1.0], 0.002, 1.0 / 14400.0, 0.0, 0.0);
        }

        $coarse = ['f' => 0.0];
        for ($tick = 0; $tick < 7200; $tick++) {
            $coarse = $this->population->updateFitness($coarse, ['f' => 1.0], 0.004, 2.0 / 14400.0, 0.0, 0.0);
        }

        $this->assertEqualsWithDelta($fine['f'], $coarse['f'], abs($fine['f']) * 0.02);
    }

    public function testTheScoreIsSmoothedSoOneGoodTickCannotEmptyTheOtherSide(): void
    {
        $once = $this->population->updateFitness(['f' => 0.0], ['f' => 1.0], 0.01, 1.0 / 14400.0, 0.0, 0.0);

        $sustained = ['f' => 0.0];
        for ($tick = 0; $tick < 2000; $tick++) {
            $sustained = $this->population->updateFitness($sustained, ['f' => 1.0], 0.01, 1.0 / 14400.0, 0.0, 0.0);
        }

        $this->assertGreaterThan($once['f'] * 10.0, $sustained['f'], 'A persistent edge must accumulate.');
    }

    public function testAStaleScoreDecaysOnceTheEdgeIsGone(): void
    {
        $fitness = ['f' => 5.0];

        for ($tick = 0; $tick < 14400; $tick++) {
            $fitness = $this->population->updateFitness($fitness, ['f' => 0.0], 0.0, 1.0 / 14400.0, 0.0, 0.0);
        }

        $this->assertLessThan(1.0, $fitness['f'], 'A belief cannot coast on a year-old edge.');
    }

    // --- Risk-adjusted excess return (Brock & Hommes 1998) ---

    public function testEarningEqualToTheRiskFreeRateScoresNothing(): void
    {
        // Cash would have made the same. A long book that returns the policy rate has no edge to be
        // rewarded for, and scoring raw returns would hand every long belief a free edge equal to the rate.
        $dt = 1.0 / 14400.0;
        $riskFree = 0.04;

        $fitness = $this->population->updateFitness(['f' => 0.0], ['f' => 1.0], $riskFree * $dt, $dt, $riskFree, 0.0);

        $this->assertEqualsWithDelta(0.0, $fitness['f'], 1e-12);
    }

    public function testAShortBookEarnsTheRateOnItsProceedsInAFlatMarket(): void
    {
        // Brock & Hommes: pi = (p_{t+1} - R p_t) z. A short has sold and sits in cash, so a flat market
        // pays it the rate — and a market rising exactly at the rate pays it nothing.
        $dt = 1.0 / 14400.0;

        $flat = $this->population->updateFitness(['f' => 0.0], ['f' => -1.0], 0.0, $dt, 0.04, 0.0);
        $atRate = $this->population->updateFitness(['f' => 0.0], ['f' => -1.0], 0.04 * $dt, $dt, 0.04, 0.0);

        $this->assertGreaterThan(0.0, $flat['f']);
        $this->assertEqualsWithDelta(0.0, $atRate['f'], 1e-12);
    }

    public function testExposureIsChargedForRiskSoAFlatMarketPunishesTheBiggerBook(): void
    {
        // Same return, no edge either way: the belief carrying more exposure must score lower, not the same.
        // Without this, whichever signal saturates more often holds a structural fitness edge unrelated to
        // being right.
        $dt = 1.0 / 14400.0;
        $variance = 0.30 ** 2;

        $fitness = $this->population->updateFitness(
            ['small' => 0.0, 'large' => 0.0],
            ['small' => 0.25, 'large' => 1.0],
            0.0,
            $dt,
            0.0,
            $variance
        );

        $this->assertLessThan(0.0, $fitness['large']);
        $this->assertLessThan($fitness['small'], $fitness['large']);
    }

    public function testTheRiskChargeIsQuadraticInExposure(): void
    {
        // (a/2) sigma^2 z^2: doubling the book quadruples the charge. Long and short pay the same.
        $dt = 1.0 / 14400.0;

        $fitness = $this->population->updateFitness(
            ['half' => 0.0, 'full' => 0.0, 'short' => 0.0],
            ['half' => 0.5, 'full' => 1.0, 'short' => -1.0],
            0.0,
            $dt,
            0.0,
            0.09
        );

        $this->assertEqualsWithDelta($fitness['half'] * 4.0, $fitness['full'], 1e-12);
        $this->assertEqualsWithDelta($fitness['full'], $fitness['short'], 1e-12);
    }

    // --- Style crowding (Barberis & Shleifer 2003) ---

    public function testANameWithNoStyleHistoryIsJudgedOnItselfAlone(): void
    {
        // Against a missing style score, not against zero: zero would mean "every style is losing".
        $local = ['fundamentalist' => 0.4, 'momentum' => -0.1];

        $this->assertSame($local, $this->population->crowdedFitness($local, []));
    }

    public function testTheCrowdedScoreIsTheWeightedBlendOfNameAndMarket(): void
    {
        $weight = FinancialConstants::AGENT_STYLE_CROWDING_WEIGHT;

        $crowded = $this->population->crowdedFitness(
            ['fundamentalist' => 0.4, 'momentum' => -0.2],
            ['fundamentalist' => -0.1, 'momentum' => 0.6]
        );

        $this->assertEqualsWithDelta(((1.0 - $weight) * 0.4) + ($weight * -0.1), $crowded['fundamentalist'], 1e-12);
        $this->assertEqualsWithDelta(((1.0 - $weight) * -0.2) + ($weight * 0.6), $crowded['momentum'], 1e-12);
    }

    public function testAStyleThatHasPaidEverywhereTiltsANameWhereItHasNot(): void
    {
        // The whole point: momentum has lost on this name but won across the market, and the name's
        // population still leans toward it. A per-name population could never do this.
        $local = ['fundamentalist' => 0.3, 'momentum' => -0.3];
        $style = ['fundamentalist' => -0.8, 'momentum' => 0.8];

        $alone = $this->population->shares($local);
        $crowded = $this->population->shares($this->population->crowdedFitness($local, $style));

        $this->assertLessThan(0.5, $alone['momentum']);
        $this->assertGreaterThan($alone['momentum'], $crowded['momentum']);
    }

    public function testABeliefAbsentFromTheStyleRecordFallsBackToItsOwnScore(): void
    {
        // A strategy added after the style record was written has no market history; it must not be
        // scored as if the market had judged it a total loss.
        $crowded = $this->population->crowdedFitness(['fundamentalist' => 0.4, 'new' => 0.2], ['fundamentalist' => 0.4]);

        $this->assertEqualsWithDelta(0.2, $crowded['new'], 1e-12);
    }

    public function testARealEdgeStillBeatsTheRiskCharge(): void
    {
        // The charge is second order: a fully committed belief earning a 30% annual rate at 30% volatility
        // and a 4% rate is still rewarded, so the penalty tempers exposure rather than forbidding it.
        $dt = 1.0 / 14400.0;

        $fitness = $this->population->updateFitness(['f' => 0.0], ['f' => 1.0], 0.30 * $dt, $dt, 0.04, 0.09);

        $this->assertGreaterThan(0.0, $fitness['f']);
    }

    public function testZeroElapsedTimeChangesNothingRatherThanDividingByIt(): void
    {
        $fitness = ['f' => 0.25];

        $this->assertSame($fitness, $this->population->updateFitness($fitness, ['f' => 1.0], 0.01, 0.0, 0.0, 0.0));
    }
    // --- The volatility the agents see ---

    public function testAJumpRaisesTheRealizedVarianceInTheTickItPrints(): void
    {
        $dt = 1.0 / 14400.0;

        $calm = $this->population->realizedVariance(0.25 ** 2, 0.0, $dt);
        $jumped = $this->population->realizedVariance(0.25 ** 2, -0.10, $dt);

        $this->assertLessThan(0.25 ** 2, $calm, 'A flat tick lets the estimate decay.');
        $this->assertGreaterThan(0.25 ** 2, $jumped, 'A ten percent print raises it.');
    }

    public function testTheSameJumpMovesTheEstimateTheSameAtAnyTickRate(): void
    {
        // An annualized r^2/dt weighted over a horizon in time: a jump is worth the same whatever the
        // simulation is stepping at, and the estimate settles at the same level under the same volatility.
        $fineJump = $this->population->realizedVariance(0.04, -0.10, 1.0 / 14400.0);
        $coarseJump = $this->population->realizedVariance(0.04, -0.10, 1.0 / 720.0);

        // (1 - exp(-dt/H)) / dt is 1/H to first order; the coarser step is short of it by dt/2H, about 1%.
        $this->assertEqualsWithDelta($fineJump - 0.04, $coarseJump - 0.04, 0.03 * abs($fineJump - 0.04));

        // Steady returns of the same annualized size converge on the same variance.
        $fine = 0.0;
        for ($tick = 0; $tick < 14400; $tick++) {
            $fine = $this->population->realizedVariance($fine, 0.30 * sqrt(1.0 / 14400.0), 1.0 / 14400.0);
        }

        $coarse = 0.0;
        for ($tick = 0; $tick < 720; $tick++) {
            $coarse = $this->population->realizedVariance($coarse, 0.30 * sqrt(1.0 / 720.0), 1.0 / 720.0);
        }

        $this->assertEqualsWithDelta(0.09, $fine, 0.09 * 0.02);
        $this->assertEqualsWithDelta($fine, $coarse, $fine * 0.02);
    }

    public function testTheRealizedVarianceForgetsAShockOverItsWindow(): void
    {
        $dt = 1.0 / 14400.0;
        $variance = $this->population->realizedVariance(0.04, -0.10, $dt);
        $spiked = $variance;

        // Six months of flat tape: the window is about a month, so the spike is long gone.
        for ($tick = 0; $tick < 7200; $tick++) {
            $variance = $this->population->realizedVariance($variance, 0.0, $dt);
        }

        $this->assertLessThan($spiked * 0.01, $variance);
    }

    public function testZeroElapsedTimeLeavesTheVarianceAlone(): void
    {
        $this->assertSame(0.04, $this->population->realizedVariance(0.04, 0.10, 0.0));
    }
}
