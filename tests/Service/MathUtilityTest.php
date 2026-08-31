<?php

namespace App\Tests\Service;

use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class MathUtilityTest extends TestCase
{
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
    }

    public function testCalculateCorrelatedGBMWithNoMovement(): void
    {
        // If there is no drift, no volatility, and no shocks, the price should remain exactly the same.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            currentVolatility: 0.0,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 1.0,
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
            currentVolatility: 0.0,
            drift: 0.10,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 1.0,
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
            currentVolatility: 0.20,
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
            currentVolatility: 0.20,
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

    public function testCalculateCorrelatedGBMCorrelationCappingPreventsNaN(): void
    {
        // Without the `max(-0.99, min(0.99, ...))` logic, an extreme beta and market vol
        // would cause $impliedRho to exceed 1.0. 
        // This would cause `sqrt(1 - (rho * rho))` to attempt to calculate the square root 
        // of a negative number, returning `NAN` and breaking the engine.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            currentVolatility: 0.05,
            drift: 0.05,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 5.0,        // Massive Beta
            marketVol: 0.80,  // Massive Market Volatility -> Uncapped Rho would be 80.0!
            marketZ: 0.0,
            w1: 1.0
        );

        $this->assertFalse(is_nan($price), 'Price returned NAN! The correlation cap failed.');
        $this->assertGreaterThan(0, $price, 'Price should be a valid positive float.');
    }

    public function testCalculateIntrinsicFairValuePEStandardValuation(): void
    {
        // COE = 10%, ROIC = 15%, Growth = 3%
        // b = 0.03 / 0.15 = 0.20 -> Payout Ratio = 0.80
        // Denominator = 0.10 - 0.03 = 0.07 -> PE = 0.80 / 0.07 ≈ 11.42857
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.15, 0.03);

        $this->assertEqualsWithDelta(11.42857, $pe, 0.001, 'Standard Gordon Growth PE calculation failed.');
    }

    public function testCalculateIntrinsicFairValuePEClampsToMinWhenValueDestroying(): void
    {
        // COE = 10%, ROIC = 2%, Growth = 5%
        // ROIC < Growth -> b > 1.0 -> Capped at 1.0 -> Payout Ratio = 0.0 -> PE = 0.0 -> Clamped to MIN_INTRINSIC_PE
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.02, 0.05);

        $this->assertEqualsWithDelta(FinancialConstants::MIN_INTRINSIC_PE, $pe, 0.0001, 'Value-destroying growth must clamp to MIN_INTRINSIC_PE.');
    }

    public function testCalculateIntrinsicFairValuePEConstrainsGrowthBelowCOE(): void
    {
        // COE = 8%, ROIC = 20%, Growth = 15% (Growth >= COE)
        // Growth constrained to 8% - 0.5% = 7.5%
        // b = 0.075 / 0.20 = 0.375 -> Payout Ratio = 0.625 -> PE = 0.625 / 0.005 = 125.0 -> Clamped to MAX_INTRINSIC_PE
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.08, 0.20, 0.15);

        $this->assertEqualsWithDelta(FinancialConstants::MAX_INTRINSIC_PE, $pe, 0.0001, 'Growth >= COE must constrain growth and clamp to MAX_INTRINSIC_PE.');
    }

    public function testCalculateDcfMultiplier(): void
    {
        // WACC = 8%, Growth = 2% -> Spread = 6% -> Multiplier = 1.02 / 0.06 = 17.0
        $multiplier = $this->mathUtility->calculateDcfMultiplier(0.08, 0.02);

        $this->assertEqualsWithDelta(17.0, $multiplier, 0.001, 'DCF terminal multiplier calculation failed.');
    }

    public function testCalculateDividendDiscountModel(): void
    {
        // Dividend = 2.0, COE = 10%, Growth = 5% -> Denominator = 0.05 -> Fair Value = 2.0 / 0.05 = 40.0
        $fairValue = $this->mathUtility->calculateDividendDiscountModel(2.0, 0.10, 0.05);

        $this->assertEqualsWithDelta(40.0, $fairValue, 0.001, 'DDM fair value calculation failed.');
    }

    public function testCalculateIntrinsicFairValuePEWithExtremeDistressAndNegativeGrowth(): void
    {
        // Extreme negative growth (-20%) and extreme low COE (1%) should be floored
        // to MIN_COST_OF_EQUITY (0.04) and MIN_PERPETUAL_GROWTH_RATE (-0.05)
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.01, 0.10, -0.20);

        $this->assertGreaterThanOrEqual(FinancialConstants::MIN_INTRINSIC_PE, $pe);
        $this->assertLessThanOrEqual(FinancialConstants::MAX_INTRINSIC_PE, $pe);
    }

    public function testCalculateDcfMultiplierAbsoluteBounds(): void
    {
        // WACC below 4% should be floored to MIN_COST_OF_EQUITY (0.04)
        $multiplier = $this->mathUtility->calculateDcfMultiplier(0.02, 0.02);

        $this->assertGreaterThanOrEqual(1.0, $multiplier);
        $this->assertLessThanOrEqual(FinancialConstants::MAX_DCF_MULTIPLIER, $multiplier);
    }

    public function testCalculateMeanRevertingWeight(): void
    {
        // Target = 0.20, Current = 0.20, Realized Share = 0.50 (boom)
        // Drift = 0.15 * (0.50 - 0.20) = +0.045
        // Reversion = 0.08 * (0.20 - 0.20) = 0.0
        // Result = 0.20 + 0.045 = 0.245
        $updated = $this->mathUtility->calculateMeanRevertingWeight(
            0.20,
            0.50,
            0.20,
            0.15,
            0.08,
            0.05,
            0.85
        );
        $this->assertEqualsWithDelta(0.245, $updated, 0.001);

        // Reversion pull when weight has drifted far above target:
        // Target = 0.20, Current = 0.40, Realized Share = 0.20 (cooled down)
        // Drift = 0.15 * (0.20 - 0.40) = -0.030
        // Reversion = 0.08 * (0.20 - 0.40) = -0.016
        // Result = 0.40 - 0.030 - 0.016 = 0.354 (pulling back toward 0.20)
        $reverting = $this->mathUtility->calculateMeanRevertingWeight(
            0.40,
            0.20,
            0.20,
            0.15,
            0.08,
            0.05,
            0.85
        );
        $this->assertEqualsWithDelta(0.354, $reverting, 0.001);

        // Clamping bounds
        $clampedCeiling = $this->mathUtility->calculateMeanRevertingWeight(0.80, 1.0, 0.80, 0.50, 0.0, 0.05, 0.85);
        $this->assertEqualsWithDelta(0.85, $clampedCeiling, 0.001);

        $clampedFloor = $this->mathUtility->calculateMeanRevertingWeight(0.10, 0.0, 0.10, 0.50, 0.0, 0.05, 0.85);
        $this->assertEqualsWithDelta(0.05, $clampedFloor, 0.001);
    }

    public function testNormalizeWeightsSimplex(): void
    {
        $weights = ['a' => 0.60, 'b' => 0.20, 'c' => 0.20];
        $norm = $this->mathUtility->normalizeWeightsSimplex($weights);
        $this->assertEqualsWithDelta(1.0, array_sum($norm), 0.0001);
        $this->assertEqualsWithDelta(0.60, $norm['a'], 0.0001);

        $unnormalized = ['a' => 0.90, 'b' => 0.30, 'c' => 0.30];
        $norm2 = $this->mathUtility->normalizeWeightsSimplex($unnormalized);
        $this->assertEqualsWithDelta(1.0, array_sum($norm2), 0.0001);
        $this->assertEqualsWithDelta(0.60, $norm2['a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $norm2['b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $norm2['c'], 0.0001);
    }

    public function testCalculateInverseNormalCDF(): void
    {
        // Symmetry around 0.5
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculateInverseNormalCDF(0.50), 0.0001);

        // Standard normal quantiles
        $this->assertEqualsWithDelta(-2.3263, $this->mathUtility->calculateInverseNormalCDF(0.01), 0.001);
        $this->assertEqualsWithDelta(-1.6449, $this->mathUtility->calculateInverseNormalCDF(0.05), 0.001);
        $this->assertEqualsWithDelta(-1.2816, $this->mathUtility->calculateInverseNormalCDF(0.10), 0.001);
        $this->assertEqualsWithDelta(1.2816, $this->mathUtility->calculateInverseNormalCDF(0.90), 0.001);
        $this->assertEqualsWithDelta(1.6449, $this->mathUtility->calculateInverseNormalCDF(0.95), 0.001);
        $this->assertEqualsWithDelta(2.3263, $this->mathUtility->calculateInverseNormalCDF(0.99), 0.001);

        // Invariance round-trip: Phi(Phi^-1(p)) = p
        $probabilities = [0.001, 0.01, 0.05, 0.20, 0.50, 0.80, 0.95, 0.99, 0.999];
        foreach ($probabilities as $p) {
            $z = $this->mathUtility->calculateInverseNormalCDF($p);
            $recoveredP = $this->mathUtility->calculateNormalCDF($z);
            $this->assertEqualsWithDelta($p, $recoveredP, 0.0005, "Round trip failed for p = $p");
        }

        // Boundary safety clamps
        $this->assertFalse(is_nan($this->mathUtility->calculateInverseNormalCDF(0.0)));
        $this->assertFalse(is_nan($this->mathUtility->calculateInverseNormalCDF(1.0)));
        $this->assertFalse(is_infinite($this->mathUtility->calculateInverseNormalCDF(0.0)));
        $this->assertFalse(is_infinite($this->mathUtility->calculateInverseNormalCDF(1.0)));
    }

    public function testCalculateVasicekExpectedLoss(): void
    {
        $pdLra = 0.015; // 1.5% through-the-cycle PD
        $rho = 0.15;   // 15% asset correlation
        $lgd = 0.45;   // 45% LGD

        // Neutral macro shock (Z = 0) -> Conditional PD is Phi(Phi^-1(PD_LRA) / sqrt(1 - rho))
        // Due to convexity (Jensen's inequality), conditional median at Z=0 is lower than TTC mean
        $baselineEl = $this->mathUtility->calculateVasicekExpectedLoss(0.0, $pdLra, $rho, $lgd);
        $invPd = $this->mathUtility->calculateInverseNormalCDF($pdLra);
        $expectedConditionalPd = $this->mathUtility->calculateNormalCDF($invPd / sqrt(1.0 - $rho));
        $this->assertEqualsWithDelta($expectedConditionalPd * $lgd, $baselineEl, 0.00001);

        // Severe economic downturn / credit crunch (Z = -3.0) -> Tail risk non-linear surge
        $stressedEl = $this->mathUtility->calculateVasicekExpectedLoss(-3.0, $pdLra, $rho, $lgd);
        $this->assertGreaterThan($baselineEl * 5.0, $stressedEl, 'Stressed loss should be non-linearly higher than baseline.');

        // Benign economic boom (Z = +2.0) -> Defaults fall below TTC baseline
        $boomEl = $this->mathUtility->calculateVasicekExpectedLoss(2.0, $pdLra, $rho, $lgd);
        $this->assertLessThan($baselineEl, $boomEl, 'Boom loss should be lower than baseline.');
        $this->assertGreaterThan(0.0, $boomEl);

        // Higher asset correlation increases tail loss under stress
        $highRhoEl = $this->mathUtility->calculateVasicekExpectedLoss(-3.0, $pdLra, 0.30, $lgd);
        $this->assertGreaterThan($stressedEl, $highRhoEl, 'Higher asset correlation should amplify stressed tail losses.');
    }

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

    public function testCalculateReversionPullWithCustomDt(): void
    {
        $currentReturn = 0.20;
        $wacc = 0.08;
        $moatSpread = 0.02;
        $baseKappa = 0.40;
        $dtQuarterly = 0.25;

        $pullQuarter = $this->mathUtility->calculateReversionPull(
            currentReturn: $currentReturn,
            wacc: $wacc,
            baseKappa: $baseKappa,
            moatSpread: $moatSpread,
            dt: $dtQuarterly
        );

        $equilibrium = $wacc + $moatSpread; // 0.10
        $excessRatio = ($currentReturn - $equilibrium) / $equilibrium; // (0.20 - 0.10) / 0.10 = 1.0
        $effectiveKappa = $baseKappa * (1.0 + 0.50 * $excessRatio); // 0.40 * 1.50 = 0.60
        $expectedWeight = 1.0 - exp(-$effectiveKappa * $dtQuarterly); // 1 - exp(-0.15) ≈ 0.139292
        $expectedPull = ($equilibrium - $currentReturn) * $expectedWeight;

        $this->assertEqualsWithDelta($expectedPull, $pullQuarter, 0.00001);
        $this->assertLessThan(0.0, $pullQuarter, 'Excess return above equilibrium should pull return downwards.');
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

    public function testCalculateNelsonSiegelAndSvenssonYieldZeroTau(): void
    {
        $level = 0.04;
        $slope = -0.01;
        $curv1 = 0.02;
        $curv2 = 0.01;

        $ns = $this->mathUtility->calculateNelsonSiegelYield($level, $slope, $curv1, 0.0);
        $this->assertEquals($level + $slope, $ns, 'Zero maturity should return level + slope (short rate).');

        $sv = $this->mathUtility->calculateSvenssonYield($level, $slope, $curv1, $curv2, 0.0);
        $this->assertEquals($level + $slope, $sv, 'Svensson zero maturity should return level + slope.');
    }

    public function testCalculateSvenssonYieldMatchesNelsonSiegelWhenCurvature2IsZero(): void
    {
        $level = 0.045;
        $slope = -0.015;
        $curvature1 = 0.02;
        $lambda1 = 0.50;

        foreach ([1.0, 2.0, 5.0, 10.0, 30.0] as $tau) {
            $nsYield = $this->mathUtility->calculateNelsonSiegelYield($level, $slope, $curvature1, $tau, $lambda1);
            $svenssonYield = $this->mathUtility->calculateSvenssonYield(
                level: $level,
                slope: $slope,
                curvature1: $curvature1,
                curvature2: 0.0,
                tau: $tau,
                lambda1: $lambda1,
                lambda2: 0.15
            );

            $this->assertEqualsWithDelta($nsYield, $svenssonYield, 0.00001, "Svensson must equal Nelson-Siegel when beta3=0 for tau=$tau");
        }
    }

    public function testCalculateSvenssonYieldSecondaryCurvatureHump(): void
    {
        $level = 0.04;
        $slope = 0.0;
        $curvature1 = 0.0;
        $curvature2 = 0.03; // Long-end hump
        $lambda2 = 0.15; // Peak around 1 / 0.15 ≈ 6.67 to 10 years

        $yieldShort = $this->mathUtility->calculateSvenssonYield($level, $slope, $curvature1, $curvature2, 0.1, 0.5, $lambda2);
        $yield10y = $this->mathUtility->calculateSvenssonYield($level, $slope, $curvature1, $curvature2, 10.0, 0.5, $lambda2);
        $yield100y = $this->mathUtility->calculateSvenssonYield($level, $slope, $curvature1, $curvature2, 100.0, 0.5, $lambda2);

        // Curvature 2 should be small near 0, reach hump in intermediate tenors, and decay asymptotically to level at infinite maturity
        $this->assertGreaterThan($yieldShort, $yield10y, 'Secondary curvature should create a hump in the yield curve.');
        $this->assertEqualsWithDelta($level, $yield100y, 0.005, 'Very long maturities should decay back toward long-term level.');
    }

    public function testCalculateDistributedLagSmoothsTransitions(): void
    {
        $current = 0.0;
        $target = 10.0;
        $timeConstant = 1.0; // 1 year lag

        // Step of dt = 0.25 (1 quarter)
        $nextQuarter = $this->mathUtility->calculateDistributedLag($current, $target, 0.25, $timeConstant);
        $expected = 0.0 + (1.0 - exp(-0.25 / 1.0)) * 10.0; // ≈ 2.21199
        $this->assertEqualsWithDelta($expected, $nextQuarter, 0.0001);

        // Immediate transition when time constant is 0
        $immediate = $this->mathUtility->calculateDistributedLag($current, $target, 0.25, 0.0);
        $this->assertEquals($target, $immediate);
    }

    public function testCalculateAsymmetricCostStickinessCompressesMarginsOnRevenueDecline(): void
    {
        $baselineVariableCostRatio = 0.70; // 70% variable cost ratio (30% gross margin)

        // Case 1: Revenue grows by 20% (log change = ln(1.20) ≈ +0.1823)
        $growthLogChange = log(1.20);
        $marginOnGrowth = $this->mathUtility->calculateAsymmetricCostStickiness($baselineVariableCostRatio, $growthLogChange);
        // On growth, variable costs expand with beta = 0.85, so cost ratio drops (margin expands)
        $this->assertLessThan($baselineVariableCostRatio, $marginOnGrowth, 'Variable cost ratio should drop slightly on revenue growth.');

        // Case 2: Revenue contracts by 20% (log change = ln(0.80) ≈ -0.2231)
        $contractionLogChange = log(0.80);
        $marginOnContraction = $this->mathUtility->calculateAsymmetricCostStickiness($baselineVariableCostRatio, $contractionLogChange);
        // On contraction, costs drop sluggishly due to stickiness penalty (beta1 + beta2 = 0.85 - 0.40 = 0.45 < 1.0),
        // causing cost ratio to increase (margin compresses sharply).
        $this->assertGreaterThan($baselineVariableCostRatio, $marginOnContraction, 'Variable cost ratio should increase sharply on revenue contraction.');
        
        // Contraction margin shift magnitude should be significantly larger than growth shift due to asymmetry
        $growthDelta = abs($baselineVariableCostRatio - $marginOnGrowth);
        $contractionDelta = abs($marginOnContraction - $baselineVariableCostRatio);
        $this->assertGreaterThan($growthDelta, $contractionDelta, 'Downward cost stickiness should create larger margin squeeze than upward expansion.');
    }

    public function testCalculateDynamicWorkingCapitalIntensityExpandsUnderStress(): void
    {
        $baseIntensity = 0.15; // 15% NWC / Revenue

        // Normal neutral baseline conditions
        $neutralIntensity = $this->mathUtility->calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $baseIntensity,
            creditSpread: 0.02,
            capacityUtilization: 1.0,
            interbankLiquiditySpread: 0.0015
        );
        $this->assertEqualsWithDelta($baseIntensity, $neutralIntensity, 0.0001, 'Under neutral conditions, intensity should equal baseline.');

        // Distressed recession conditions: Credit spread +300bps, Capacity utilization 70%, Interbank spread 100bps
        $stressedIntensity = $this->mathUtility->calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $baseIntensity,
            creditSpread: 0.05, // +300bps DSO stretch
            capacityUtilization: 0.70, // 30% idle capacity DIO stretch
            interbankLiquiditySpread: 0.0100 // +85bps liquidity crunch DPO drain
        );
        $this->assertGreaterThan($baseIntensity, $stressedIntensity, 'Stressed conditions must expand working capital intensity.');
    }

    public function testCalculateDynamicWorkingCapitalIntensityCompressesNegativeFloatUnderStress(): void
    {
        $negativeFloat = -0.05; // -5% NWC / Revenue float (e.g. Consumer Staples / Fast Food)

        // Normal neutral baseline conditions
        $neutralIntensity = $this->mathUtility->calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $negativeFloat,
            creditSpread: 0.02,
            capacityUtilization: 1.0,
            interbankLiquiditySpread: 0.0015
        );
        $this->assertEqualsWithDelta($negativeFloat, $neutralIntensity, 0.0001, 'Under neutral conditions, negative float should be preserved.');

        // Distressed recession conditions: Credit spread +300bps, Capacity utilization 70%, Interbank spread 100bps
        $stressedIntensity = $this->mathUtility->calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $negativeFloat,
            creditSpread: 0.05,
            capacityUtilization: 0.70,
            interbankLiquiditySpread: 0.0100
        );
        // Under stress, negative float shrinks toward zero (becomes less negative, i.e., -0.05 -> -0.045)
        $this->assertGreaterThan($negativeFloat, $stressedIntensity, 'Stressed conditions must compress negative float toward zero.');
        $this->assertLessThan(0.0, $stressedIntensity, 'Float remains negative during stress.');
    }

    public function testCalculateDynamicWorkingCapitalIntensityTightensDuringBoom(): void
    {
        $baseIntensity = 0.15;

        // Roaring boom: tight credit spreads (-50bps), high interbank liquidity (-50bps), 100% capacity utilization
        $boomIntensity = $this->mathUtility->calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $baseIntensity,
            creditSpread: 0.015,
            capacityUtilization: 1.0,
            interbankLiquiditySpread: 0.0010
        );

        $this->assertLessThan($baseIntensity, $boomIntensity, 'Boom conditions should tighten working capital intensity below baseline.');
    }
}



