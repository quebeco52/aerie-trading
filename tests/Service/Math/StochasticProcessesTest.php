<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\MathUtility;
use App\Service\Math\OptionPricing;
use App\Service\Math\StochasticProcesses;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\StochasticProcesses.
 */
class StochasticProcessesTest extends TestCase
{
    public function testKouTruncatedSecondMomentMatchesTheUncappedMomentWhenTheCapIsFarOut(): void
    {
        // With the cap many mean jump sizes away, virtually no mass is truncated and the closed form must
        // collapse to the plain exponential second moment, 2 / eta^2.
        $etaUp = 400.0;
        $etaDown = 280.0;

        $moment = StochasticProcesses::kouTruncatedSecondMoment(0.35, $etaUp, $etaDown, 0.2624, 0.3567);
        $uncapped = (0.35 * 2.0 / ($etaUp * $etaUp)) + (0.65 * 2.0 / ($etaDown * $etaDown));

        $this->assertEqualsWithDelta($uncapped, $moment, $uncapped * 1.0e-6);
    }

    public function testKouTruncatedSecondMomentIsStrictlyBelowTheUncappedMomentWhenTheCapBites(): void
    {
        // A jump scale of 0.13 puts the +/-30% caps only about two mean sizes out, so a material share of
        // the distribution is truncated. Budgeting against the uncapped figure would reclaim variance the
        // jump never delivered and leave the diffusion too quiet.
        $etaUp = 1.0 / 0.13;
        $etaDown = 1.0 / (0.13 * 1.25);

        $moment = StochasticProcesses::kouTruncatedSecondMoment(0.40, $etaUp, $etaDown, 0.2624, 0.3567);
        $uncapped = (0.40 * 2.0 / ($etaUp * $etaUp)) + (0.60 * 2.0 / ($etaDown * $etaDown));

        $this->assertLessThan($uncapped, $moment);
        $this->assertGreaterThan($uncapped * 0.5, $moment);
    }

    public function testKouTruncatedCompensatorIsNegativeForADownSkewedJump(): void
    {
        // Kou's jump here is skewed down: more likely to fall, and further when it does. E[e^J - 1] is
        // therefore negative, which is exactly the drift the engine has to give back.
        $compensator = StochasticProcesses::kouTruncatedCompensator(0.40, 1.0 / 0.10, 1.0 / 0.125, 0.2624, 0.3567);

        $this->assertLessThan(0.0, $compensator);
    }

    /**
     * The OU step is exact: a year taken in one step or in 360 has the same decay and the same conditional variance, so
     * the factor does not depend on the tick length. The variance is summed from each draw's loading, the step being
     * linear in the draws.
     */
    public function testOrnsteinUhlenbeckStepIsTimestepNeutral(): void
    {
        $kappa = 0.37;
        $sd = 0.23;
        $horizon = 1.0;
        $exact = $sd * $sd * (1.0 - exp(-2.0 * $kappa * $horizon));

        foreach ([1, 4, 360] as $steps) {
            $dt = $horizon / $steps;
            $x = 1.0;
            for ($i = 0; $i < $steps; $i++) {
                $x = StochasticProcesses::calculateOrnsteinUhlenbeckStep($x, $kappa, $sd, $dt, 0.0);
            }
            $this->assertEqualsWithDelta(exp(-$kappa * $horizon), $x, 1e-12, "decay over {$steps} steps");

            $variance = 0.0;
            for ($shocked = 0; $shocked < $steps; $shocked++) {
                $x = 0.0;
                for ($i = 0; $i < $steps; $i++) {
                    $x = StochasticProcesses::calculateOrnsteinUhlenbeckStep($x, $kappa, $sd, $dt, $i === $shocked ? 1.0 : 0.0);
                }
                $variance += $x * $x;
            }
            $this->assertEqualsWithDelta($exact, $variance, 1e-12, "variance over {$steps} steps");
        }
    }

    /** The compensator is the part calculateCompensatedKouJump subtracts, so adding it back recovers the jump drawn. */
    /**
     * A stationary OU factor stepped over a horizon by its exact transition changes by the sd the sigma was solved for.
     */
    public function testOuSigmaForIncrementSdGivesTheHorizonChangeTheExactTransitionDelivers(): void
    {
        foreach ([[0.6, 0.25, 0.02], [2.0, 1.0, 0.05], [0.05, 0.25, 0.03]] as [$kappa, $horizon, $incrementSd]) {
            $sigma = StochasticProcesses::ouSigmaForIncrementSd($incrementSd, $kappa, $horizon);
            $stationarySd = $sigma / sqrt(2.0 * $kappa);
            // The transition's own pieces: the decay of a unit factor, and the noise a unit draw adds from zero.
            $decay = StochasticProcesses::calculateOrnsteinUhlenbeckStep(1.0, $kappa, $stationarySd, $horizon, 0.0);
            $noise = StochasticProcesses::calculateOrnsteinUhlenbeckStep(0.0, $kappa, $stationarySd, $horizon, 1.0);
            // x' - x = (decay - 1) x + noise dW, with x at its stationary variance.
            $changeVariance = ((($decay - 1.0) ** 2) * ($stationarySd ** 2)) + ($noise ** 2);
            $this->assertEqualsWithDelta($incrementSd ** 2, $changeVariance, 1e-12);
        }
        $this->assertEqualsWithDelta(0.03 / sqrt(0.25), StochasticProcesses::ouSigmaForIncrementSd(0.03, 0.0, 0.25), 1e-9, 'Without reversion it is the random walk\'s sigma.');
    }

    public function testKouCompensatorIsWhatTheCompensatedJumpSubtracts(): void
    {
        // The gate opens and a zero uniform sends the jump up.
        $jumping = new ScriptedDraws(checkProbability: true, generateExponential: 0.05);
        $args = [0.2, 0.25, 13.6, 12.4, 0.175, 1.0 / 360.0];

        $compensator = StochasticProcesses::calculateKouCompensator(...$args);
        $this->assertLessThan(0.0, $compensator, 'Down-heavy disasters: the expected jump is negative.');
        $this->assertEqualsWithDelta(0.05, $jumping->calculateCompensatedKouJump(...$args) + $compensator, 1e-15);

        $quiet = new ScriptedDraws();
        $this->assertSame(-StochasticProcesses::calculateKouCompensator(...$args), $quiet->calculateCompensatedKouJump(...$args));
        $this->assertSame(0.0, StochasticProcesses::calculateKouCompensator(0.0, 0.25, 13.6, 12.4, 0.175, 0.25));
    }

    public function testCalculateSchwartz1Factor(): void
    {
        // Mean reversion without noise (dW = 0)
        $currentPrice = 50.0;
        $theta = 100.0;
        $nextPrice = StochasticProcesses::calculateSchwartz1Factor(
            currentPrice: $currentPrice,
            kappa: 1.0,
            theta: $theta,
            sigma: 0.20,
            dt: 1.0,
            dW: 0.0,
        );

        $this->assertGreaterThan($currentPrice, $nextPrice, 'Price below theta must revert upwards.');
        $this->assertLessThanOrEqual($theta, $nextPrice);
    }

    /** The step to a mean is the Schwartz step on the theta for that mean, read off one kappa and sigma. */
    public function testSchwartzStepToMeanIsTheStepOnTheThetaForThatMean(): void
    {
        foreach ([[100.0, 0.8, 140.0, 0.25, 1.0 / 3600.0, 1.3], [1.0, 52.0, 1.0, 0.9, 1.0 / 252.0, -2.1], [35.0, 0.3, 20.0, 0.18, 0.25, 0.0]] as [$current, $kappa, $mean, $sigma, $dt, $dW]) {
            $this->assertSame(
                StochasticProcesses::calculateSchwartz1Factor($current, $kappa, StochasticProcesses::schwartzThetaForMean($mean, $kappa, $sigma), $sigma, $dt, $dW),
                StochasticProcesses::schwartzStepToMean($current, $kappa, $mean, $sigma, $dt, $dW),
            );
        }
    }

    /** The jumps' log drift held by the reversion is lambda mu / kappa, evaluated as (lambda mu) / kappa. */
    public function testLogOuJumpLogShiftIsTheMertonLogDriftOverTheReversionSpeed(): void
    {
        $this->assertSame(0.50 * 0.20 / 1.5, StochasticProcesses::logOuJumpLogShift(0.50, 1.5, 0.20));
        $this->assertSame(0.029 * 0.5716 / 0.489, StochasticProcesses::logOuJumpLogShift(0.029, 0.489, 0.5716));
        $this->assertSame(-(0.10 * 0.18) / 1.80, -StochasticProcesses::logOuJumpLogShift(0.10, 1.80, 0.18));
        $this->assertEqualsWithDelta(0.2, StochasticProcesses::logOuJumpLogShift(1.0, 0.5, 0.1), 1e-15);
        $this->assertSame(0.0, StochasticProcesses::logOuJumpLogShift(0.0, 0.5, 0.1));
    }

    public function testSchwartzForwardIsTheExpectedSpotOfTheSameExactTransition(): void
    {
        $spot = 150.0;
        $kappa = 0.8;
        $theta = 100.0;
        $sigma = 0.25;

        // Delivery now is the spot itself.
        $this->assertEqualsWithDelta($spot, StochasticProcesses::calculateSchwartzForwardPrice($spot, $kappa, $theta, $sigma, 0.0), 1e-9);

        // Any horizon: E[S_T] = exp(m + v/2) of the log-normal transition calculateSchwartz1Factor steps with.
        foreach ([0.25, 0.5, 1.0, 3.0] as $horizon) {
            $alpha = log($theta) - ($sigma * $sigma) / (2.0 * $kappa);
            $mean = (exp(-$kappa * $horizon) * log($spot)) + ((1.0 - exp(-$kappa * $horizon)) * $alpha);
            $variance = ($sigma * $sigma / (2.0 * $kappa)) * (1.0 - exp(-2.0 * $kappa * $horizon));

            $this->assertEqualsWithDelta(
                exp($mean + ($variance / 2.0)),
                StochasticProcesses::calculateSchwartzForwardPrice($spot, $kappa, $theta, $sigma, $horizon),
                1e-9,
            );
        }

        // A spike above equilibrium prices a backwardated curve: each later delivery is cheaper.
        $oneQuarter = StochasticProcesses::calculateSchwartzForwardPrice($spot, $kappa, $theta, $sigma, 0.25);
        $oneYear = StochasticProcesses::calculateSchwartzForwardPrice($spot, $kappa, $theta, $sigma, 1.0);
        $this->assertLessThan($spot, $oneQuarter);
        $this->assertLessThan($oneQuarter, $oneYear);
        $this->assertGreaterThan($theta * 0.9, $oneYear);

        // The curve's far end is where the transition's stationary mean sits.
        $this->assertEqualsWithDelta(
            exp(log($theta) - ($sigma * $sigma) / (4.0 * $kappa)),
            StochasticProcesses::calculateSchwartzForwardPrice($spot, $kappa, $theta, $sigma, 100.0),
            1e-6,
        );
    }

    /**
     * The Theory of Storage curve: flat in contango, rising asymptotically as inventory nears the buffer floor.
     */
    public function testConvenienceYieldIsZeroInContangoAndRisesAsInventoryDepletes(): void
    {
        $this->assertSame(0.0, StochasticProcesses::calculateConvenienceYield(100.0), 'At neutral inventory the market is in contango.');
        $this->assertSame(0.0, StochasticProcesses::calculateConvenienceYield(140.0), 'Ample inventory stays in contango.');

        // Closed form below the neutral point: yieldScale * ((neutralSlack / bufferSlack)^exponent - 1).
        $this->assertEqualsWithDelta(
            0.10 * (pow(50.0 / 25.0, 1.8) - 1.0),
            StochasticProcesses::calculateConvenienceYield(75.0),
            0.0000001,
            'Backwardation yield must follow the storage curve exactly.',
        );

        $previous = -1.0;
        for ($level = 99.0; $level >= 51.0; $level -= 1.0) {
            $yield = StochasticProcesses::calculateConvenienceYield($level);
            $this->assertGreaterThan($previous, $yield, "Convenience yield must rise as inventory falls to {$level}.");
            $previous = $yield;
        }

        // The buffer slack floors at 1.0, so the curve saturates rather than diverging to infinity.
        $this->assertTrue(is_finite(StochasticProcesses::calculateConvenienceYield(0.0)), 'A depleted inventory must not produce a non-finite yield.');
        $this->assertSame(
            StochasticProcesses::calculateConvenienceYield(51.0),
            StochasticProcesses::calculateConvenienceYield(10.0),
            'Below the buffer floor the curve saturates at its clamped value.',
        );
    }

    /**
     * Persistence inflates integrated variance; the scale factor must give it back exactly.
     */
    public function testPersistenceVarianceScaleRestoresIntegratedVariance(): void
    {
        $this->assertSame(1.0, StochasticProcesses::calculatePersistenceVarianceScale(0.0), 'An i.i.d. driver needs no rescaling.');

        // sqrt((1 - phi) / (1 + phi)) inverts the (1 + phi) / (1 - phi) variance inflation of an AR(1) sum.
        foreach ([0.10, 0.50, 0.90, 0.99] as $phi) {
            $scale = StochasticProcesses::calculatePersistenceVarianceScale($phi);
            $inflation = (1.0 + $phi) / (1.0 - $phi);
            $this->assertEqualsWithDelta(1.0, $scale ** 2 * $inflation, 0.0000001, "Scale must neutralise the variance inflation at phi={$phi}.");
            $this->assertLessThan(1.0, $scale, "A persistent driver must be damped, not amplified, at phi={$phi}.");
        }

        // phi is bounded below 1 so the scale never collapses to zero or goes imaginary.
        $this->assertGreaterThan(0.0, StochasticProcesses::calculatePersistenceVarianceScale(1.0), 'A unit root must clamp rather than annihilate the shock.');
        $this->assertSame(1.0, StochasticProcesses::calculatePersistenceVarianceScale(-0.5), 'Negative persistence clamps to the i.i.d. case.');
    }

    /**
     * Over a vanishing horizon there is no time to revert, so the horizon volatility is spot volatility.
     */
    public function testHorizonVolatilityCollapsesToSpotOverAVanishingHorizon(): void
    {
        $this->assertEqualsWithDelta(
            1.05,
            StochasticProcesses::averageMeanRevertingVolatility(1.05, 0.35, 1.1507, 0.0001),
            0.005,
        );
    }

    /**
     * Over a long horizon almost the whole path is spent at the long-run level, so that is what the
     * average converges to. This is the property that stops a transient shock from being priced as if it
     * lasted for the entire life of the debt.
     */
    public function testHorizonVolatilityConvergesToTheLongRunLevelOverALongHorizon(): void
    {
        $this->assertEqualsWithDelta(
            0.35,
            StochasticProcesses::averageMeanRevertingVolatility(1.05, 0.35, 1.1507, 400.0),
            0.01,
        );
    }

    /**
     * The closed form for the Heston / GARCH family, sigmaBar^2 = theta + (v0 - theta)(1 - e^-kT)/(kT),
     * evaluated against a hand-computed case so a regression in the algebra cannot pass silently.
     */
    public function testHorizonVolatilityMatchesTheIntegratedVarianceClosedForm(): void
    {
        $spot = 1.05;
        $longRun = 0.35;
        $kappa = 1.1507;
        $horizon = 5.0;

        $decay = $kappa * $horizon;
        $expected = sqrt(($longRun ** 2) + ((($spot ** 2) - ($longRun ** 2)) * ((1.0 - exp(-$decay)) / $decay)));

        $this->assertEqualsWithDelta(
            $expected,
            StochasticProcesses::averageMeanRevertingVolatility($spot, $longRun, $kappa, $horizon),
            1.0e-9,
        );
        $this->assertEqualsWithDelta(0.5406, $expected, 0.001, 'the closed form itself must not drift');
    }

    /**
     * A shock is damped toward the long-run level, never below it and never above the shock itself.
     */
    public function testHorizonVolatilityStaysBetweenTheLongRunLevelAndTheShock(): void
    {
        $horizonVol = StochasticProcesses::averageMeanRevertingVolatility(1.05, 0.35, 1.1507, 5.0);

        $this->assertGreaterThan(0.35, $horizonVol);
        $this->assertLessThan(1.05, $horizonVol);
    }

    /**
     * A firm sitting at its structural volatility has nothing to revert from, so the horizon average is
     * that same level whatever the horizon.
     */
    public function testHorizonVolatilityIsUnchangedWhenSpotAlreadySitsAtTheLongRunLevel(): void
    {
        $this->assertEqualsWithDelta(
            0.28,
            StochasticProcesses::averageMeanRevertingVolatility(0.28, 0.28, 1.1507, 5.0),
            1.0e-9,
        );
    }

    /**
     * A volatility below its long-run level reverts upward, which is the same formula read the other way.
     */
    public function testHorizonVolatilityPullsAQuietFirmBackUpTowardItsLongRunLevel(): void
    {
        $horizonVol = StochasticProcesses::averageMeanRevertingVolatility(0.10, 0.35, 1.1507, 5.0);

        $this->assertGreaterThan(0.10, $horizonVol);
        $this->assertLessThan(0.35, $horizonVol);
    }

    /**
     * A degenerate reversion speed or horizon cannot divide by zero; it falls back to spot.
     */
    public function testHorizonVolatilityFallsBackToSpotOnADegenerateProcess(): void
    {
        $this->assertEqualsWithDelta(0.42, StochasticProcesses::averageMeanRevertingVolatility(0.42, 0.20, 0.0, 5.0), 1.0e-9);
        $this->assertEqualsWithDelta(0.42, StochasticProcesses::averageMeanRevertingVolatility(0.42, 0.20, 1.15, 0.0), 1.0e-9);
    }

    /** The closed form agrees with the jumps calculateSVJJJumps() actually draws, cap included. */
    public function testMeanVarianceJumpMatchesTheDrawnJumps(): void
    {
        mt_srand(20261007);
        $math = new MathUtility();
        $pUp = 0.40;
        $muV = 0.05;
        $n = 40000;
        $sum = 0.0;
        $sumSq = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $jump = $math->calculateSVJJJumps(lambda: 1.0, pUp: $pUp, etaUp: 20.0, etaDown: 10.0, muV: $muV, dt: 1.0)['var_jump'];
            $sum += $jump;
            $sumSq += $jump * $jump;
        }
        $mean = $sum / $n;
        $se = sqrt((($sumSq / $n) - ($mean * $mean)) / $n);

        self::assertEqualsWithDelta(StochasticProcesses::meanVarianceJump($pUp, $muV), $mean, 4.0 * $se);
        self::assertEqualsWithDelta(
            $muV * (1.0 - exp(-StochasticProcesses::MAX_VARIANCE_JUMP_MEAN_MULTIPLE)),
            StochasticProcesses::meanVarianceJump(0.0, $muV),
            1e-15,
        );
    }

    /**
     * The capped Kou moment the option desk shapes its smile from: the second moment is the one the price engine's
     * variance budget uses, the moments approach the uncapped ones as the cap recedes, and the third matches the
     * integral it stands for, taken numerically.
     */
    public function testTheCappedKouMomentIsTheMomentOfTheCappedDraw(): void
    {
        [$pUp, $etaUp, $etaDown, $capUp, $capDown] = [0.35, 12.0, 8.0, 0.25, 0.30];

        $this->assertEqualsWithDelta(
            StochasticProcesses::kouTruncatedSecondMoment($pUp, $etaUp, $etaDown, $capUp, $capDown),
            StochasticProcesses::kouTruncatedMoment(2, $pUp, $etaUp, $etaDown, $capUp, $capDown),
            1e-15,
        );
        foreach ([3, 4] as $order) {
            $this->assertEqualsWithDelta(
                OptionPricing::calculateKouJumpMoment($order, $pUp, $etaUp, $etaDown),
                StochasticProcesses::kouTruncatedMoment($order, $pUp, $etaUp, $etaDown, 50.0, 50.0),
                1e-12,
            );
        }

        $cappedTail = static function (float $rate, float $cap): float {
            $steps = 200_000;
            $width = $cap / $steps;
            $integral = 0.0;
            for ($i = 0; $i < $steps; $i++) {
                $x = ($i + 0.5) * $width;
                $integral += ($x ** 3) * $rate * exp(-$rate * $x) * $width;
            }

            return $integral + (($cap ** 3) * exp(-$rate * $cap));
        };
        $this->assertEqualsWithDelta(
            ($pUp * $cappedTail($etaUp, $capUp)) - ((1.0 - $pUp) * $cappedTail($etaDown, $capDown)),
            StochasticProcesses::kouTruncatedMoment(3, $pUp, $etaUp, $etaDown, $capUp, $capDown),
            1e-9,
        );
    }

    /**
     * The exact OU transition: the gap to the mean decays at e^(-kappa dt), the step's variance is the continuous
     * process's over dt, and two half steps compose to one.
     */
    public function testTheDiffusionOuStepIsTheExactTransition(): void
    {
        [$x, $mean, $kappa, $sigma, $dt] = [0.30, 0.10, 2.0, 0.25, 0.5];
        $step = static fn(float $from, float $over, float $dW): float => StochasticProcesses::ornsteinUhlenbeckDiffusionStep($from, $mean, $kappa, $sigma, $over, $dW);

        $centre = $step($x, $dt, 0.0);
        $this->assertEqualsWithDelta($mean + (($x - $mean) * exp(-$kappa * $dt)), $centre, 1e-15);
        $variance = ($step($x, $dt, 1.0) - $centre) ** 2;
        $this->assertEqualsWithDelta($sigma ** 2 * (1.0 - exp(-2.0 * $kappa * $dt)) / (2.0 * $kappa), $variance, 1e-15);

        $half = $dt / 2.0;
        $this->assertEqualsWithDelta($centre, $step($step($x, $half, 0.0), $half, 0.0), 1e-15);
        $halfVariance = ($step($x, $half, 1.0) - $step($x, $half, 0.0)) ** 2;
        $this->assertEqualsWithDelta($variance, ($halfVariance * exp(-2.0 * $kappa * $half)) + $halfVariance, 1e-15);
    }

    /**
     * As reversion vanishes the step becomes a random walk, sigma sqrt(dt) a draw, and keeps its noise; so does the
     * log-OU commodity step built on it, whose variance must not divide by a floored kappa while decaying at the raw
     * one.
     */
    public function testTheOuStepBecomesARandomWalkAsReversionVanishes(): void
    {
        foreach ([0.0, 1e-9, 1e-6] as $kappa) {
            $move = StochasticProcesses::ornsteinUhlenbeckDiffusionStep(0.5, 0.0, $kappa, 0.2, 0.25, 1.0)
                - StochasticProcesses::ornsteinUhlenbeckDiffusionStep(0.5, 0.0, $kappa, 0.2, 0.25, 0.0);
            $this->assertEqualsWithDelta(0.2 * sqrt(0.25), $move, 1e-6, "kappa {$kappa}");
        }

        $up = log(StochasticProcesses::calculateSchwartz1Factor(10.0, 1e-6, 10.0, 0.2, 0.25, 1.0));
        $flat = log(StochasticProcesses::calculateSchwartz1Factor(10.0, 1e-6, 10.0, 0.2, 0.25, 0.0));
        $this->assertEqualsWithDelta(0.2 * sqrt(0.25), $up - $flat, 1e-6);
    }
}
