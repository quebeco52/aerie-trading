<?php

namespace App\Tests\Service\Math;

use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use PHPUnit\Framework\TestCase;

class MathUtilityTest extends TestCase
{
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
    }

    public function testKouTruncatedSecondMomentMatchesASimulatedDraw(): void
    {
        mt_srand(4242);

        $pUp = 0.40;
        $scale = 0.10;
        $etaUp = 1.0 / $scale;
        $etaDown = 1.0 / ($scale * 1.25);

        $draws = 400000;
        $sum = 0.0;
        for ($i = 0; $i < $draws; $i++) {
            $jump = $this->mathUtility->generateUniform() < $pUp
                ? min($this->mathUtility->generateExponential($etaUp), 0.2624)
                : max(-$this->mathUtility->generateExponential($etaDown), -0.3567);
            $sum += $jump * $jump;
        }

        $this->assertEqualsWithDelta(
            StochasticProcesses::kouTruncatedSecondMoment($pUp, $etaUp, $etaDown, 0.2624, 0.3567),
            $sum / $draws,
            0.0005,
            'The closed form must agree with the draws the engine actually takes.'
        );
    }

    public function testKouTruncatedMeanMatchesASimulatedDrawAndTheUncappedMeanFarFromTheCap(): void
    {
        $this->assertEqualsWithDelta((0.35 / 400.0) - (0.65 / 280.0), StochasticProcesses::kouTruncatedMean(0.35, 400.0, 280.0, 0.2624, 0.3567), 1.0e-9);

        mt_srand(910);
        $pUp = 0.40;
        $etaUp = 1.0 / 0.10;
        $etaDown = 1.0 / 0.125;
        $draws = 400000;
        $sum = 0.0;
        for ($i = 0; $i < $draws; $i++) {
            $sum += $this->mathUtility->generateUniform() < $pUp
                ? min($this->mathUtility->generateExponential($etaUp), 0.2624)
                : max(-$this->mathUtility->generateExponential($etaDown), -0.3567);
        }

        $this->assertEqualsWithDelta(StochasticProcesses::kouTruncatedMean($pUp, $etaUp, $etaDown, 0.2624, 0.3567), $sum / $draws, 0.001);
    }

    public function testKouTruncatedCompensatorMatchesASimulatedDraw(): void
    {
        mt_srand(909);

        $pUp = 0.40;
        $scale = 0.10;
        $etaUp = 1.0 / $scale;
        $etaDown = 1.0 / ($scale * 1.25);

        $draws = 400000;
        $sum = 0.0;
        for ($i = 0; $i < $draws; $i++) {
            $jump = $this->mathUtility->generateUniform() < $pUp
                ? min($this->mathUtility->generateExponential($etaUp), 0.2624)
                : max(-$this->mathUtility->generateExponential($etaDown), -0.3567);
            $sum += exp($jump) - 1.0;
        }

        $this->assertEqualsWithDelta(
            StochasticProcesses::kouTruncatedCompensator($pUp, $etaUp, $etaDown, 0.2624, 0.3567),
            $sum / $draws,
            0.001,
            'The compensator must be the mean of exactly the draws the engine takes.'
        );
    }

    public function testCompensatedKouJumpHasZeroMeanOverManyDraws(): void
    {
        // The whole point of the compensator is that adding this to a mean-reverting process bends its
        // shape without moving its mean. Accumulate the increment over many steps: it must average to zero
        // even though the jump itself is heavily skewed down.
        mt_srand(2718);

        $lambda = 0.50;
        $dt = 1.0 / 3600.0;
        $steps = 2000000;

        $sum = 0.0;
        for ($i = 0; $i < $steps; $i++) {
            $sum += $this->mathUtility->calculateCompensatedKouJump($lambda, 0.25, 100.0, 50.0, 0.08, $dt);
        }

        // Over 555 simulated years the residual must be small against the disturbance it is added to.
        $this->assertEqualsWithDelta(0.0, $sum / ($steps * $dt), 0.002, 'Compensated jump must not drift the mean.');
    }

    public function testCompensatedKouJumpIsSkewedDownWhenTheDownBranchIsHeavier(): void
    {
        // pUp below a half with a slower down rate is Kou's asymmetry: rare large drops against frequent
        // small rises. The compensator removes the mean, so what is left must be negatively skewed.
        mt_srand(31415);

        $dt = 1.0 / 3600.0;
        $steps = 2000000;

        // The compensator is on every step and is far smaller than any arrival, so threshold well above it.
        $draws = [];
        for ($i = 0; $i < $steps; $i++) {
            $j = $this->mathUtility->calculateCompensatedKouJump(0.50, 0.25, 100.0, 50.0, 0.08, $dt);
            if (abs($j) > 1.0e-4) {
                $draws[] = $j;
            }
        }

        $this->assertGreaterThan(100, count($draws), 'Jumps must land over 555 simulated years.');
        $negative = count(array_filter($draws, static fn(float $j): bool => $j < 0.0));

        $this->assertGreaterThan(count($draws) * 0.6, $negative, 'The down branch must dominate the arrivals.');
        $this->assertLessThan(0.0, array_sum($draws) / count($draws), 'The mean realised jump must be a fall.');
    }

    public function testCompensatedKouJumpRespectsTheCapAndDegenerateParameters(): void
    {
        mt_srand(161803);

        // A cap at a twentieth of the mean down jump binds on nearly every arrival. The compensator rides
        // on top of the capped draw, and at this intensity it is worth 1.4e-5, so allow one of those.
        $capped = 0;
        for ($i = 0; $i < 20000; $i++) {
            $j = $this->mathUtility->calculateCompensatedKouJump(100.0, 0.25, 100.0, 50.0, 0.001, 1.0 / 3600.0);
            $this->assertLessThanOrEqual(0.00102, abs($j), 'A draw must not exceed the cap.');
            if (abs($j) > 0.0009) {
                $capped++;
            }
        }
        $this->assertGreaterThan(100, $capped, 'The cap must actually bind at this intensity.');

        // Guard rails: a disabled or malformed process contributes nothing rather than a NAN.
        $this->assertSame(0.0, $this->mathUtility->calculateCompensatedKouJump(0.0, 0.25, 100.0, 50.0, 0.08, 0.25));
        $this->assertSame(0.0, $this->mathUtility->calculateCompensatedKouJump(0.5, 0.25, 0.0, 50.0, 0.08, 0.25));
        $this->assertSame(0.0, $this->mathUtility->calculateCompensatedKouJump(0.5, 0.25, 100.0, 50.0, 0.0, 0.25));
        $this->assertSame(0.0, $this->mathUtility->calculateCompensatedKouJump(0.5, 0.25, 100.0, 50.0, 0.08, 0.0));
    }

    public function testCalculateSVJJJumpsSurvivesAZeroVarianceJumpMean(): void
    {
        // The per-name variance jump mean is derived from the stock's own volatility state, which a
        // bankrupt or freshly zeroed name legitimately carries at zero. This used to divide by it.
        $jump = $this->mathUtility->calculateSVJJJumps(
            lambda: 1000.0,
            pUp: 0.4,
            etaUp: 10.0,
            etaDown: 8.0,
            muV: 0.0,
            dt: 1.0
        );

        $this->assertSame(0.0, $jump['var_jump']);
        $this->assertGreaterThan(0.0, $jump['price_multiplier']);
    }

    public function testCalculateCorrelatedGBMWithNoMovement(): void
    {
        // If there is no drift, no volatility, and no shocks, the price should remain exactly the same.
        // Beta is zero as well: a name with a beta carries the market's volatility through it, and would
        // owe the Ito correction on it even with no idiosyncratic volatility of its own.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: 0.0,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 0.0,
            marketVol: 0.15,
            marketZ: 0.0,
            w1: 0.0
        );

        $this->assertEquals(100.0, $price, 'Price should remain unchanged with 0 parameters.');
    }

    public function testCalculateCorrelatedGBMPureDrift(): void
    {
        // With exactly 10% drift over 1 year (dt = 1.0) and no volatility,
        // the geometric expectation is CurrentPrice * exp(Drift).
        // 100 * exp(0.10) ≈ 110.517
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: 0.0,
            drift: 0.10,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 0.0,
            marketVol: 0.15,
            marketZ: 0.0,
            w1: 0.0
        );

        $this->assertEqualsWithDelta(110.517, $price, 0.001, 'Price should match pure geometric drift.');
    }

    public function testCalculateCorrelatedGBMSystematicMarketShock(): void
    {
        // A positive market shock (marketZ = 1.0) with a 1.0 beta should push the price up.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: 0.20,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 1.0,
            marketVol: 0.20,
            marketZ: 1.0, // +1 Standard Deviation Market Shock
            w1: 0.0       // No idiosyncratic shock
        );

        $this->assertGreaterThan(100.0, $price, 'Positive market shock should increase the price.');
    }

    public function testCalculateCorrelatedGBMIdiosyncraticStockShock(): void
    {
        // A negative idiosyncratic shock (w1 = -1.0) on a 0 beta stock should push the price down
        // independently of the broader market.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: 0.20,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 0.0,    // 0 correlation to the market
            marketVol: 0.20,
            marketZ: 1.0, // Positive market shock (should be ignored due to 0 beta)
            w1: -1.0      // -1 Standard Deviation Individual Shock
        );

        $this->assertLessThan(100.0, $price, 'Negative idiosyncratic shock should decrease the price.');
    }

    public function testCalculateCorrelatedGBMDeliversFullBetaRegardlessOfIdiosyncraticVolatility(): void
    {
        // The market loading is beta * marketVol outright. The earlier form derived it from an implied
        // correlation beta * marketVol / sigma clamped below one, so a name whose volatility was small
        // against what its beta demanded silently stopped being that beta — here it would have realized
        // 0.99 * 0.05 / 0.80 = 0.062 of its configured 5.0.
        $beta = 5.0;
        $marketVol = 0.80;

        $up = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: 0.05,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: $beta,
            marketVol: $marketVol,
            marketZ: 1.0,
            w1: 0.0
        );

        $flat = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: 0.05,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: $beta,
            marketVol: $marketVol,
            marketZ: 0.0,
            w1: 0.0
        );

        $this->assertFalse(is_nan($up), 'Price returned NAN.');
        $this->assertEqualsWithDelta(
            $beta * $marketVol,
            log($up / $flat),
            1.0e-9,
            'A one-sigma market move must move the log price by exactly beta * marketVol.'
        );
    }

    public function testCalculateCorrelatedGBMSplitsResidualVarianceWithTheSectorFactor(): void
    {
        // Loadings are square roots of shares that sum to one, so moving variance onto the sector factor
        // must leave the residual's total variance untouched.
        $share = 0.20;
        $idiosyncraticVol = 0.30;

        $sectorOnly = log($this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: $idiosyncraticVol,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 0.0,
            marketVol: 0.15,
            marketZ: 0.0,
            w1: 0.0,
            sectorZ: 1.0,
            sectorVarianceShare: $share
        ) / 100.0) + (0.5 * $idiosyncraticVol * $idiosyncraticVol);

        $idioOnly = log($this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            idiosyncraticVolatility: $idiosyncraticVol,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 0.0,
            marketVol: 0.15,
            marketZ: 0.0,
            w1: 1.0,
            sectorZ: 0.0,
            sectorVarianceShare: $share
        ) / 100.0) + (0.5 * $idiosyncraticVol * $idiosyncraticVol);

        $this->assertEqualsWithDelta(
            $idiosyncraticVol * $idiosyncraticVol,
            ($sectorOnly * $sectorOnly) + ($idioOnly * $idioOnly),
            1.0e-12,
            'Sector and idiosyncratic loadings must carry the residual variance between them.'
        );
    }

    /** Frye (2000): recovery scales with collateral value since origination; LGD stays within [0, 1]. */
    public function testCalculateSchwartz2FactorReturnsPositiveSpot(): void
    {
        $result = $this->mathUtility->calculateSchwartz2Factor(
            chi: 0.0,
            xi: 4.60517, // ln(100)
            kappa: 1.5,
            muXi: 0.01,
            sigChi: 0.30,
            sigXi: 0.08,
            rho: -0.30,
            dt: 0.25,
            dW1: 0.0,
            dW2: 0.0
        );

        $this->assertArrayHasKey('chi', $result);
        $this->assertArrayHasKey('xi', $result);
        $this->assertArrayHasKey('spot', $result);
        $this->assertGreaterThan(0.0, $result['spot']);
        $this->assertEqualsWithDelta(100.0 * exp(0.01 * 0.25), $result['spot'], 0.1);
    }

    public function testCalculateSchwartz2FactorShortTermMeanReverts(): void
    {
        // With a high short-term shock (chi = 0.50) and zero noise, chi should decay toward 0
        $result = $this->mathUtility->calculateSchwartz2Factor(
            chi: 0.50,
            xi: 4.60517,
            kappa: 2.0,
            muXi: 0.0,
            sigChi: 0.20,
            sigXi: 0.05,
            rho: 0.0,
            dt: 0.50,
            dW1: 0.0,
            dW2: 0.0
        );

        $this->assertLessThan(0.50, $result['chi'], 'Short-term deviation should mean-revert toward zero.');
        $this->assertEqualsWithDelta(0.50 * exp(-2.0 * 0.50), $result['chi'], 0.0001);
    }

    public function testCalculateSchwartz2FactorLongTermDrifts(): void
    {
        // With positive muXi and zero noise, xi should drift upwards
        $initialXi = 4.0;
        $muXi = 0.10;
        $dt = 1.0;

        $result = $this->mathUtility->calculateSchwartz2Factor(
            chi: 0.0,
            xi: $initialXi,
            kappa: 1.0,
            muXi: $muXi,
            sigChi: 0.10,
            sigXi: 0.05,
            rho: 0.0,
            dt: $dt,
            dW1: 0.0,
            dW2: 0.0
        );

        $this->assertEqualsWithDelta($initialXi + ($muXi * $dt), $result['xi'], 0.0001, 'Long-term equilibrium should drift with muXi.');
        $this->assertEqualsWithDelta(exp($result['chi'] + $result['xi']), $result['spot'], 0.0001);
    }

    public function testCalculateTwoFactorOUReturnsPositiveSpotAndMeanRevertsBothFactors(): void
    {
        // Factor 1 elevated (chi = 0.50), Factor 2 elevated (xi = 5.20, ~181 price level)
        // Both should mean-revert toward their respective thetas (thetaChi = 0, thetaXi = ln(100) = 4.60517)
        $initialChi = 0.50;
        $initialXi = 5.20;
        $thetaChi = 0.0;
        $thetaXi = 4.60517;
        $kappaChi = 2.0;
        $kappaXi = 0.25;
        $dt = 1.0;

        $result = $this->mathUtility->calculateTwoFactorOU(
            chi: $initialChi,
            xi: $initialXi,
            kappaChi: $kappaChi,
            kappaXi: $kappaXi,
            thetaChi: $thetaChi,
            thetaXi: $thetaXi,
            sigChi: 0.20,
            sigXi: 0.06,
            rho: -0.20,
            dt: $dt,
            dW1: 0.0,
            dW2: 0.0
        );

        $expectedChi = $initialChi * exp(-$kappaChi * $dt) + $thetaChi * (1.0 - exp(-$kappaChi * $dt));
        $expectedXi = $initialXi * exp(-$kappaXi * $dt) + $thetaXi * (1.0 - exp(-$kappaXi * $dt));

        $this->assertArrayHasKey('chi', $result);
        $this->assertArrayHasKey('xi', $result);
        $this->assertArrayHasKey('spot', $result);
        $this->assertLessThan($initialChi, $result['chi'], 'Short-term factor should mean-revert toward zero.');
        $this->assertLessThan($initialXi, $result['xi'], 'Long-term equilibrium factor should mean-revert toward baseline.');
        $this->assertEqualsWithDelta($expectedChi, $result['chi'], 0.0001);
        $this->assertEqualsWithDelta($expectedXi, $result['xi'], 0.0001);
        $this->assertEqualsWithDelta(exp($result['chi'] + $result['xi']), $result['spot'], 0.0001);
    }

    public function testCalculateConvexPenalty(): void
    {
        // Zero or negative shock yields 0 penalty
        $this->assertEquals(0.0, $this->mathUtility->calculateConvexPenalty(0.0, 2.0, 1.5));
        $this->assertEquals(0.0, $this->mathUtility->calculateConvexPenalty(-0.05, 2.0, 1.5));

        // 5% shock (0.05) with quadratic convexity (2.0) and scalar 2.0
        // (0.05)^2 * 2.0 = 0.0025 * 2.0 = 0.005
        $this->assertEqualsWithDelta(0.005, $this->mathUtility->calculateConvexPenalty(0.05, 2.0, 2.0), 0.00001);

        // 10% shock (0.10) with convexity 2.0 and scalar 2.0 -> (0.10)^2 * 2.0 = 0.02
        // Quadruples penalty for doubling shock (convex property)
        $this->assertEqualsWithDelta(0.02, $this->mathUtility->calculateConvexPenalty(0.10, 2.0, 2.0), 0.00001);
    }

    public function testGenerateUniformAndUniformBetween(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $u = $this->mathUtility->generateUniform();
            $this->assertGreaterThanOrEqual(0.0, $u);
            $this->assertLessThanOrEqual(1.0, $u);

            $uBetween = $this->mathUtility->generateUniformBetween(10.0, 20.0);
            $this->assertGreaterThanOrEqual(10.0, $uBetween);
            $this->assertLessThanOrEqual(20.0, $uBetween);
        }
    }

    public function testCheckProbability(): void
    {
        $this->assertFalse($this->mathUtility->checkProbability(0.0), 'Probability of 0.0 must always return false.');
        $this->assertTrue($this->mathUtility->checkProbability(1.0), 'Probability of 1.0 must always return true.');
        $this->assertTrue($this->mathUtility->checkProbability(2.0), 'Probability > 1.0 must always return true.');
    }

    public function testGenerateStandardNormalAndPersistentZ(): void
    {
        $z1 = $this->mathUtility->generateStandardNormal();
        $z2 = $this->mathUtility->generateStandardNormal();
        $this->assertIsFloat($z1);
        $this->assertIsFloat($z2);

        // Persistent AR(1) Z-score
        $zPrev = 1.5;
        $zZeroPhi = $this->mathUtility->generatePersistentZ($zPrev, 0.0);
        $this->assertIsFloat($zZeroPhi);

        // When phi = 1.0 (pure persistence with 0 innovation)
        $zUnitPhi = $this->mathUtility->generatePersistentZ($zPrev, 1.0);
        $this->assertEqualsWithDelta($zPrev, $zUnitPhi, 0.0001, 'Phi = 1.0 must return the previous Z exactly.');
    }

    public function testCalculateLogNormalSynergy(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $synergy = $this->mathUtility->calculateLogNormalSynergy(0.05, 0.02);
            $this->assertGreaterThan(0.0, $synergy, 'LogNormal synergy must always be strictly positive.');
        }
    }

    public function testGenerateStudentsT(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $t = $this->mathUtility->generateStudentsT(4);
            $this->assertIsFloat($t);
            $this->assertTrue(is_finite($t));
        }
    }

    public function testCalculateJumpDiffusion(): void
    {
        // Probability = 0 (lambda = 0) -> no jump
        $noJump = $this->mathUtility->calculateJumpDiffusion(0.0, 0.0, 0.05, 1.0);
        $this->assertSame(1.0, $noJump['multiplier']);
        $this->assertNull($noJump['shock_pct']);
        $this->assertNull($noJump['exponent']);

        // Probability = 1.0 (lambda = 1000, dt = 1.0) -> guaranteed jump
        $jump = $this->mathUtility->calculateJumpDiffusion(1000.0, 0.05, 0.0, 1.0);
        $this->assertNotNull($jump['shock_pct']);
        $this->assertNotNull($jump['exponent']);
        $this->assertEqualsWithDelta(exp(0.05), $jump['multiplier'], 0.0001);
        $this->assertEqualsWithDelta((exp(0.05) - 1.0) * 100.0, $jump['shock_pct'], 0.0001);
    }

    public function testCalculateCIR(): void
    {
        // Exact mean reversion without diffusion (dW = 0)
        $val = $this->mathUtility->calculateCIR(
            currentValue: 0.02,
            kappa: 2.0,
            theta: 0.05,
            sigma: 0.02,
            dt: 1.0,
            dW: 0.0
        );
        $this->assertGreaterThan(0.02, $val, 'Value below theta must revert upwards.');
        $this->assertLessThanOrEqual(0.05, $val, 'Value must not overshoot theta without noise.');

        // Negative diffusion shock with low value must be floored at 0.0001 failsafe
        $floored = $this->mathUtility->calculateCIR(
            currentValue: 0.0001,
            kappa: 2.0,
            theta: 0.01,
            sigma: 0.50,
            dt: 1.0,
            dW: -10.0
        );
        $this->assertGreaterThanOrEqual(0.0001, $floored, 'CIR process must never fall below minimum failsafe floor.');
    }

    public function testGenerateExponential(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $exp = $this->mathUtility->generateExponential(2.0);
            $this->assertGreaterThan(0.0, $exp, 'Exponential random variable must be strictly positive.');
        }
    }

    public function testCalculateQEVarianceStep(): void
    {
        // Low variance of vol (psi <= 1.5, quadratic scheme)
        $nextVarQuad = $this->mathUtility->calculateQEVarianceStep(
            currentVar: 0.04,
            theta: 0.04,
            kappa: 1.5,
            sigma: 0.05,
            dt: 0.25
        );
        $this->assertGreaterThan(0.0, $nextVarQuad);
        $this->assertTrue(is_finite($nextVarQuad));

        // High variance of vol (psi > 1.5, exponential scheme)
        $nextVarExp = $this->mathUtility->calculateQEVarianceStep(
            currentVar: 0.01,
            theta: 0.04,
            kappa: 0.5,
            sigma: 1.50,
            dt: 0.25
        );
        $this->assertGreaterThanOrEqual(0.0000001, $nextVarExp);
        $this->assertTrue(is_finite($nextVarExp));
    }

    public function testCalculateSVJJJumps(): void
    {
        // Zero intensity -> no jump
        $noJump = $this->mathUtility->calculateSVJJJumps(0.0, 0.5, 10.0, 10.0, 0.05, 1.0);
        $this->assertSame(1.0, $noJump['price_multiplier']);
        $this->assertSame(0.0, $noJump['var_jump']);
        $this->assertNull($noJump['shock_pct']);

        // Guaranteed jump with 100% up-jump probability (pUp = 1.0, lambda = 1000)
        $upJump = $this->mathUtility->calculateSVJJJumps(1000.0, 1.0, 10.0, 10.0, 0.05, 1.0);
        $this->assertGreaterThan(1.0, $upJump['price_multiplier']);
        $this->assertGreaterThan(0.0, $upJump['shock_pct']);
        $this->assertGreaterThan(0.0, $upJump['var_jump']);

        // Guaranteed jump with 100% down-jump probability (pUp = 0.0, lambda = 1000)
        $downJump = $this->mathUtility->calculateSVJJJumps(1000.0, 0.0, 10.0, 10.0, 0.05, 1.0);
        $this->assertLessThan(1.0, $downJump['price_multiplier']);
        $this->assertLessThan(0.0, $downJump['shock_pct']);
        $this->assertGreaterThan(0.0, $downJump['var_jump']);
    }

    public function testCalculateDistanceToDefault(): void
    {
        // Zero debt -> safe 10.0 SD default
        $this->assertSame(10.0, $this->mathUtility->calculateDistanceToDefault(100.0, 0.0, 0.20, 0.04));

        // Healthy balance sheet: Assets = $200M, Debt = $50M, Vol = 20%, Rf = 4%, T = 1yr
        $ddHealthy = $this->mathUtility->calculateDistanceToDefault(200.0, 50.0, 0.20, 0.04, 1.0);
        $this->assertGreaterThan(5.0, $ddHealthy, 'Healthy firm should have high distance to default (> 5 SD).');

        // Distressed balance sheet: Assets = $100M, Debt = $120M, Vol = 40%, Rf = 4%, T = 1yr
        $ddDistressed = $this->mathUtility->calculateDistanceToDefault(100.0, 120.0, 0.40, 0.04, 1.0);
        $this->assertLessThan(0.0, $ddDistressed, 'Insolvent firm should have negative distance to default (< 0 SD).');
    }

    public function testCalculateMertonCreditSpread(): void
    {
        // Safe firm (DD = 5.0) -> Spread near 0 bps
        $spreadSafe = $this->mathUtility->calculateMertonCreditSpread(5.0, 0.40, 1.0);
        $this->assertLessThan(0.0001, $spreadSafe, 'High DD must produce negligible credit spread.');

        // Borderline firm (DD = 2.0) -> Moderate spread (~50-150 bps)
        $spreadModerate = $this->mathUtility->calculateMertonCreditSpread(2.0, 0.40, 1.0);
        $this->assertGreaterThan(0.005, $spreadModerate);
        $this->assertLessThan(0.050, $spreadModerate);

        // Distressed firm (DD = -1.0) -> Blown-out spread
        $spreadDistressed = $this->mathUtility->calculateMertonCreditSpread(-1.0, 0.40, 1.0);
        $this->assertGreaterThan(0.20, $spreadDistressed, 'Distressed firm must have high credit spread.');
        $this->assertLessThanOrEqual(1.0, $spreadDistressed, 'Credit spread must be capped at 1.0 (10,000 bps).');
    }

    // --- Contract coverage for formulas whose only other exercise is a higher suite ---

    // --- Shared valuation helpers ---

    public function testGeneratePoissonCountRecoversItsMean(): void
    {
        mt_srand(20260919);
        $this->assertSame(0, $this->mathUtility->generatePoissonCount(0.0));

        $total = 0;
        $draws = 20000;
        for ($i = 0; $i < $draws; $i++) {
            $total += $this->mathUtility->generatePoissonCount(2.0);
        }
        $this->assertEqualsWithDelta(2.0, $total / $draws, 0.05, 'Knuth\'s method delivers the parameterised mean.');

        $anyMultiple = false;
        for ($i = 0; $i < 2000; $i++) {
            if ($this->mathUtility->generatePoissonCount(2.0) > 1) {
                $anyMultiple = true;
                break;
            }
        }
        $this->assertTrue($anyMultiple, 'Unlike the Bernoulli gate, more than one event can land in one interval.');
    }

    public function testGenerateParetoSeverityRecoversItsMeanAndRespectsTheCap(): void
    {
        mt_srand(20260919);
        $scale = 1.0;
        $alpha = 3.0; // finite variance, so the sample mean converges quickly
        $total = 0.0;
        $draws = 20000;
        for ($i = 0; $i < $draws; $i++) {
            $severity = $this->mathUtility->generateParetoSeverity($scale, $alpha, 1000.0);
            $this->assertGreaterThanOrEqual($scale, $severity, 'A Pareto draw never falls below its scale.');
            $this->assertLessThanOrEqual(1000.0, $severity);
            $total += $severity;
        }
        $this->assertEqualsWithDelta($scale * $alpha / ($alpha - 1.0), $total / $draws, 0.03, 'The inverse-CDF draw delivers the Pareto mean scale x alpha / (alpha - 1).');

        for ($i = 0; $i < 2000; $i++) {
            $this->assertLessThanOrEqual(2.0, $this->mathUtility->generateParetoSeverity($scale, 1.1, 2.0), 'The cap truncates the tail.');
        }
    }


    public function testAnOwnStreamIsReproducibleAndLeavesTheGlobalStreamAlone(): void
    {
        mt_srand(4242);
        $global = [mt_rand(), mt_rand()];

        mt_srand(4242);
        $first = MathUtility::ownStream(7);
        $second = MathUtility::ownStream(7);
        $drawn = [$first->generateStandardNormal(), $first->generateUniform()];

        $this->assertSame($drawn, [$second->generateStandardNormal(), $second->generateUniform()], 'The same seed gives the same draws.');
        $this->assertNotSame($drawn, [MathUtility::ownStream(8)->generateStandardNormal(), MathUtility::ownStream(8)->generateUniform()]);
        $this->assertSame($global, [mt_rand(), mt_rand()], 'and none of them came out of the global stream.');
    }
}
