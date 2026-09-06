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

    public function testCalculateKalmanSmoothedEps(): void
    {
        // 1. Stable environment: High asset volatility (noisy measurement) + Low macro uncertainty (trust prior)
        $structuralEps = 5.00;
        $noisyQuarterlyEps = 8.00;
        $smoothedStable = $this->mathUtility->calculateKalmanSmoothedEps(
            structuralEps: $structuralEps,
            quarterlyEps: $noisyQuarterlyEps,
            assetVolatility: 0.50, // Measurement variance = 1.0
            macroUncertainty: 0.01  // Prior variance = 0.01 -> Kalman Gain ~ 0.01 / 1.01 ~ 0.01
        );
        // Should heavily weight towards structural EPS (5.00)
        $this->assertLessThan(5.50, $smoothedStable);
        $this->assertGreaterThan(5.00, $smoothedStable);

        // 2. Volatile crisis environment: Low asset volatility (trusted measurement) + High macro uncertainty (distrusted prior)
        $smoothedCrisis = $this->mathUtility->calculateKalmanSmoothedEps(
            structuralEps: $structuralEps,
            quarterlyEps: $noisyQuarterlyEps,
            assetVolatility: 0.01, // Measurement variance = 0.02
            macroUncertainty: 0.50  // Prior variance = 0.50 -> Kalman Gain ~ 0.50 / 0.52 ~ 0.96
        );
        // Should heavily weight towards new measurement (8.00)
        $this->assertGreaterThan(7.50, $smoothedCrisis);
        $this->assertLessThanOrEqual(8.00, $smoothedCrisis);
    }

    public function testCalculateWACC(): void
    {
        // 100% Equity
        $wacc100Eq = $this->mathUtility->calculateWACC(1.0, 0.10, 0.0, 0.05);
        $this->assertEqualsWithDelta(0.10, $wacc100Eq, 0.0001);

        // 100% Debt
        $wacc100Debt = $this->mathUtility->calculateWACC(0.0, 0.10, 1.0, 0.05);
        $this->assertEqualsWithDelta(0.05, $wacc100Debt, 0.0001);

        // 60% Equity / 40% Debt (Cost of Equity 10%, Post-Tax Cost of Debt 4%) -> 0.6 * 0.10 + 0.4 * 0.04 = 0.076 (7.6%)
        $waccMix = $this->mathUtility->calculateWACC(0.60, 0.10, 0.40, 0.04);
        $this->assertEqualsWithDelta(0.076, $waccMix, 0.0001);
    }

    public function testCalculateCAPM(): void
    {
        $rf = 0.04;
        $erp = 0.05;

        // Beta = 1.0 -> Rf + ERP = 0.09
        $coe1 = $this->mathUtility->calculateCAPM($rf, 1.0, $erp);
        $this->assertEqualsWithDelta(0.09, $coe1, 0.0001);

        // Beta = 0.0 -> Rf = 0.04
        $coe0 = $this->mathUtility->calculateCAPM($rf, 0.0, $erp);
        $this->assertEqualsWithDelta(0.04, $coe0, 0.0001);

        // Beta = 1.5 -> 0.04 + 1.5 * 0.05 = 0.115
        $coe15 = $this->mathUtility->calculateCAPM($rf, 1.5, $erp);
        $this->assertEqualsWithDelta(0.115, $coe15, 0.0001);

        // Negative Beta = -0.5 -> 0.04 - 0.025 = 0.015
        $coeNeg = $this->mathUtility->calculateCAPM($rf, -0.5, $erp);
        $this->assertEqualsWithDelta(0.015, $coeNeg, 0.0001);
    }

    public function testCalculateLeveredBeta(): void
    {
        $unleveredBeta = 1.0;
        $taxRate = 0.20; // (1 - 0.20) = 0.80

        // Zero debt -> levered beta = unlevered beta
        $betaZeroDebt = $this->mathUtility->calculateLeveredBeta($unleveredBeta, $taxRate, 0.0);
        $this->assertEqualsWithDelta(1.0, $betaZeroDebt, 0.0001);

        // D/E = 1.0, dampening = 1.0 -> 1.0 * (1 + 0.8 * 1.0) = 1.80
        $betaStandard = $this->mathUtility->calculateLeveredBeta($unleveredBeta, $taxRate, 1.0, 1.0);
        $this->assertEqualsWithDelta(1.80, $betaStandard, 0.0001);

        // D/E = 1.0, dampening = 0.5 -> 1.0 * (1 + 0.8 * 0.5) = 1.40
        $betaDampened = $this->mathUtility->calculateLeveredBeta($unleveredBeta, $taxRate, 1.0, 0.5);
        $this->assertEqualsWithDelta(1.40, $betaDampened, 0.0001);
    }

    public function testCalculateNormalCDF(): void
    {
        // Standard normal symmetry: N(0) = 0.5
        $this->assertEqualsWithDelta(0.50, $this->mathUtility->calculateNormalCDF(0.0), 0.0001);

        // N(1.96) ≈ 0.9750
        $this->assertEqualsWithDelta(0.9750, $this->mathUtility->calculateNormalCDF(1.96), 0.0005);

        // N(-1.96) ≈ 0.0250
        $this->assertEqualsWithDelta(0.0250, $this->mathUtility->calculateNormalCDF(-1.96), 0.0005);

        // Extreme bounds
        $this->assertGreaterThan(0.9999, $this->mathUtility->calculateNormalCDF(6.0));
        $this->assertLessThan(0.0001, $this->mathUtility->calculateNormalCDF(-6.0));
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

    public function testCalculateSchwartz1Factor(): void
    {
        // Mean reversion without noise (dW = 0)
        $currentPrice = 50.0;
        $theta = 100.0;
        $nextPrice = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $currentPrice,
            kappa: 1.0,
            theta: $theta,
            sigma: 0.20,
            dt: 1.0,
            dW: 0.0
        );

        $this->assertGreaterThan($currentPrice, $nextPrice, 'Price below theta must revert upwards.');
        $this->assertLessThanOrEqual($theta, $nextPrice);
    }

    public function testCalculateEarningsResponseCoefficient(): void
    {
        // Moderate SUE (+5% surprise)
        $erc = $this->mathUtility->calculateEarningsResponseCoefficient(0.05, 1.0, 0.0);
        $this->assertGreaterThan(0.0, $erc, 'Positive SUE should yield positive ERC price reaction.');

        // High growth premium amplifies the reaction
        $ercHighGrowth = $this->mathUtility->calculateEarningsResponseCoefficient(0.05, 1.0, 2.0);
        $this->assertGreaterThan($erc, $ercHighGrowth, 'High growth premium must amplify ERC.');
    }

    public function testCalculateBayesianAnalystUpdate(): void
    {
        // Equal uncertainty -> straight average
        $updated = $this->mathUtility->calculateBayesianAnalystUpdate(
            priorEstimate: 100.0,
            priorVariance: 1.0,
            newSignal: 120.0,
            signalVariance: 1.0
        );
        $this->assertEqualsWithDelta(110.0, $updated, 0.0001);

        // Highly confident prior (low variance) -> stays close to prior
        $updatedConfidentPrior = $this->mathUtility->calculateBayesianAnalystUpdate(
            priorEstimate: 100.0,
            priorVariance: 0.01,
            newSignal: 150.0,
            signalVariance: 1.00
        );
        $this->assertLessThan(101.0, $updatedConfidentPrior);

        // Highly confident signal (low variance) -> moves close to signal
        $updatedConfidentSignal = $this->mathUtility->calculateBayesianAnalystUpdate(
            priorEstimate: 100.0,
            priorVariance: 1.00,
            newSignal: 150.0,
            signalVariance: 0.01
        );
        $this->assertGreaterThan(149.0, $updatedConfidentSignal);
    }

    public function testCalculateEstrellaMishkinProbability(): void
    {
        // Normal upward-sloping yield curve (+150bps slope), normal term premium (+30bps), neutral FCI (0.0)
        $normalProb = $this->mathUtility->calculateEstrellaMishkinProbability(
            slope: 0.015,
            termPremium: 0.003,
            fci: 0.0
        );
        $this->assertLessThan(0.20, $normalProb, 'Normal steep yield curve must indicate low recession risk.');
        $this->assertGreaterThan(0.001, $normalProb);

        // Inverted yield curve (-150bps slope), negative term premium (-50bps), tightened FCI (+1.50)
        $invertedProb = $this->mathUtility->calculateEstrellaMishkinProbability(
            slope: -0.015,
            termPremium: -0.005,
            fci: 1.50
        );
        $this->assertGreaterThan(0.70, $invertedProb, 'Deeply inverted yield curve with tight financial conditions must predict high recession probability.');
        $this->assertLessThanOrEqual(0.999, $invertedProb);
    }

    public function testCalculateCapacityUtilization(): void
    {
        // Neutral conditions (0 output gap, 0 overhang)
        $baselineCu = $this->mathUtility->calculateCapacityUtilization(
            outputGap: 0.0,
            capitalStockOverhang: 0.0
        );
        $this->assertEqualsWithDelta(0.785, $baselineCu, 0.0001, 'Neutral conditions must match Fed G.17 baseline utilization.');

        // Economic boom (+3% output gap, 0 overhang)
        $boomCu = $this->mathUtility->calculateCapacityUtilization(
            outputGap: 0.03,
            capitalStockOverhang: 0.0
        );
        $this->assertGreaterThan($baselineCu, $boomCu, 'Positive output gap must increase capacity utilization.');

        // Heavy capital overhang (+10% overhang, 0 output gap)
        $slackCu = $this->mathUtility->calculateCapacityUtilization(
            outputGap: 0.0,
            capitalStockOverhang: 0.10
        );
        $this->assertLessThan($baselineCu, $slackCu, 'Excess capital stock overhang must depress capacity utilization.');

        // Extreme bounds check
        $extremeBoom = $this->mathUtility->calculateCapacityUtilization(0.50, 0.0);
        $this->assertLessThanOrEqual(0.92, $extremeBoom);

        $extremeBust = $this->mathUtility->calculateCapacityUtilization(-0.50, 0.50);
        $this->assertGreaterThanOrEqual(0.60, $extremeBust);
    }

    public function testCalculateCorporateDefaultRate(): void
    {
        // Neutral macroeconomic conditions (0 macro Z: median conditional default is ~0.95% due to Vasicek skewness)
        $baselineDefault = $this->mathUtility->calculateCorporateDefaultRate(
            macroZ: 0.0
        );
        $this->assertEqualsWithDelta(0.0095, $baselineDefault, 0.001);

        // Crisis conditions (deep recession macro Z = -2.5)
        $crisisDefault = $this->mathUtility->calculateCorporateDefaultRate(
            macroZ: -2.5
        );
        $this->assertGreaterThan(0.06, $crisisDefault, 'Severe recession credit shock must sharply elevate corporate defaults.');
        $this->assertLessThanOrEqual(0.18, $crisisDefault);
    }

    public function testCalculateSloosCreditStandards(): void
    {
        // Reversion toward target without diffusion (dW = 0)
        $tightened = $this->mathUtility->calculateSloosCreditStandards(
            currentSloos: 0.0,
            outputGap: -0.03,             // Recession
            excessCreditSpread: 0.040,   // Wide credit spread (+400bps above baseline 0.02)
            dt: 0.25,
            dW: 0.0
        );
        $this->assertGreaterThan(0.10, $tightened, 'Recession and wide credit spreads must drive bank lending standards to tighten.');
        $this->assertLessThanOrEqual(0.85, $tightened);

        // Easing regime (negative excess credit spread -0.005, positive output gap +0.02)
        $eased = $this->mathUtility->calculateSloosCreditStandards(
            currentSloos: 0.20,
            outputGap: 0.02,
            excessCreditSpread: -0.005,
            dt: 0.50,
            dW: 0.0
        );
        $this->assertLessThan(0.20, $eased, 'Benign conditions must cause bank lending standards to ease.');
    }

    public function testCalculateRefiningCrackSpreadStep(): void
    {
        // Neutral equilibrium (dW = 0)
        $nextSpread = $this->mathUtility->calculateRefiningCrackSpreadStep(
            currentCrack: 15.0,
            outputGap: 0.0,
            energyInventoryIndex: 100.0,
            dt: 0.25,
            dW: 0.0,
            baselineCrack: 22.0
        );
        $this->assertGreaterThan(15.0, $nextSpread, 'Crack spread below equilibrium must revert upwards.');
        $this->assertLessThanOrEqual(22.0, $nextSpread);

        // Positive demand shock (+3% output gap, tight inventory at 70)
        $boomSpread = $this->mathUtility->calculateRefiningCrackSpreadStep(
            currentCrack: 22.0,
            outputGap: 0.03,
            energyInventoryIndex: 70.0,
            dt: 0.25,
            dW: 0.0,
            baselineCrack: 22.0
        );
        $this->assertGreaterThan(22.0, $boomSpread, 'Strong distillate demand with tight energy inventory expands crack spread.');

        // Floor constraint
        $floored = $this->mathUtility->calculateRefiningCrackSpreadStep(
            currentCrack: 2.0,
            outputGap: -0.10,
            energyInventoryIndex: 150.0,
            dt: 0.25,
            dW: -5.0,
            baselineCrack: 22.0
        );
        $this->assertGreaterThanOrEqual(4.0, $floored, 'Refining crack spread must not fall below $4.00/bbl floor.');
    }

    public function testCalculateGscpiComposite(): void
    {
        // Baseline shipping conditions
        $neutralGscpi = $this->mathUtility->calculateGscpiComposite(
            freightRateIndex: 100.0,
            inventoryStockGap: 0.0,
            industrialMetalsIndex: 100.0
        );
        $this->assertEqualsWithDelta(0.0, $neutralGscpi, 0.0001, 'Baseline freight and logistics must yield 0 GSCPI.');

        // Global supply chain bottleneck crisis (freight +150, inventory stock shortage -0.05, metals +50)
        $bottleneckGscpi = $this->mathUtility->calculateGscpiComposite(
            freightRateIndex: 250.0,
            inventoryStockGap: -0.05,
            industrialMetalsIndex: 150.0
        );
        $this->assertGreaterThan(2.0, $bottleneckGscpi, 'Severe supply chain congestion must yield high positive GSCPI index.');

        // Slack logistics capacity (freight 70, inventory glut +0.05, metals 80)
        $slackGscpi = $this->mathUtility->calculateGscpiComposite(
            freightRateIndex: 70.0,
            inventoryStockGap: 0.05,
            industrialMetalsIndex: 80.0
        );
        $this->assertLessThan(0.0, $slackGscpi, 'Excess shipping capacity and loose inventories must yield negative GSCPI index.');
    }

    public function testCalculateCapitalMarketsDealIndexStep(): void
    {
        // Mean reversion toward baseline (dW = 0)
        $revertingIndex = $this->mathUtility->calculateCapitalMarketsDealIndexStep(
            currentDealIndex: 80.0,
            equityRiskPremium: 0.045,
            hyCreditSpread: 0.048,
            marketVolatility: 0.15,
            dt: 0.25,
            dW: 0.0
        );
        $this->assertGreaterThan(80.0, $revertingIndex, 'Index below baseline must mean-revert upwards.');

        // Golden era: Low ERP (3.0%), tight credit spreads (2.5%), low VIX (10%)
        $goldenEra = $this->mathUtility->calculateCapitalMarketsDealIndexStep(
            currentDealIndex: 100.0,
            equityRiskPremium: 0.030,
            hyCreditSpread: 0.025,
            marketVolatility: 0.10,
            dt: 0.25,
            dW: 0.0
        );
        $this->assertGreaterThan(100.0, $goldenEra, 'High equity multiples, tight spreads, and low VIX must stimulate M&A deal activity.');

        // Credit freeze & market panic: Elevated ERP (7.0%), wide credit spread (9.0%), VIX spike (40%)
        $panicIndex = $this->mathUtility->calculateCapitalMarketsDealIndexStep(
            currentDealIndex: 100.0,
            equityRiskPremium: 0.070,
            hyCreditSpread: 0.090,
            marketVolatility: 0.40,
            dt: 0.25,
            dW: 0.0
        );
        $this->assertLessThan(100.0, $panicIndex, 'Market panic and credit freeze must collapse deal activity index.');
        $this->assertGreaterThanOrEqual(20.0, $panicIndex);
    }

    public function testCalculateDiffusionIndex(): void
    {
        // 1. Neutral baseline (no deviations)
        $neutral = $this->mathUtility->calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [
                ['deviation' => 0.0, 'sensitivity' => 120.0],
                ['deviation' => 0.0, 'sensitivity' => 80.0],
            ]
        );
        $this->assertEqualsWithDelta(50.0, $neutral, 0.001);

        // 2. Expansionary drivers (positive deviations)
        $expansion = $this->mathUtility->calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [
                ['deviation' => 0.03, 'sensitivity' => 150.0], // +4.5
                ['deviation' => 0.02, 'sensitivity' => 50.0],  // +1.0
            ]
        );
        $this->assertEqualsWithDelta(55.5, $expansion, 0.001);
        $this->assertGreaterThan(50.0, $expansion);

        // 3. Contractionary drivers (negative deviations)
        $contraction = $this->mathUtility->calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [
                ['deviation' => -0.04, 'sensitivity' => 150.0], // -6.0
                ['deviation' => -0.02, 'sensitivity' => 50.0],  // -1.0
            ]
        );
        $this->assertEqualsWithDelta(43.0, $contraction, 0.001);
        $this->assertLessThan(50.0, $contraction);

        // 4. Clamping bounds
        $clampedHigh = $this->mathUtility->calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [['deviation' => 1.0, 'sensitivity' => 100.0]],
            min: 30.0,
            max: 70.0
        );
        $this->assertEqualsWithDelta(70.0, $clampedHigh, 0.001);

        $clampedLow = $this->mathUtility->calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [['deviation' => -1.0, 'sensitivity' => 100.0]],
            min: 30.0,
            max: 70.0
        );
        $this->assertEqualsWithDelta(30.0, $clampedLow, 0.001);
    }

    public function testCalculateStageOfProcessingPpi(): void
    {
        $weights = [
            'metals' => 0.15,
            'energy' => 0.20,
            'agri' => 0.15,
            'gscpi' => 0.005,
            'ulc' => 0.35,
            'demand' => 0.15,
        ];

        // Neutral wholesale pricing
        $neutralPpi = $this->mathUtility->calculateStageOfProcessingPpi(
            metalsInflation: 0.02,
            energyInflation: 0.02,
            agriInflation: 0.02,
            gscpiZ: 0.0,
            unitLaborCost: 0.02,
            outputGap: 0.0,
            weights: $weights
        );
        // (0.15*0.02) + (0.20*0.02) + (0.15*0.02) + 0 + (0.35*0.02) + 0 = 0.003 + 0.004 + 0.003 + 0.007 = 0.017
        $this->assertEqualsWithDelta(0.017, $neutralPpi, 0.0001);

        // Commodity & supply chain shock
        $shockPpi = $this->mathUtility->calculateStageOfProcessingPpi(
            metalsInflation: 0.20, // +20%
            energyInflation: 0.40, // +40%
            agriInflation: 0.10,   // +10%
            gscpiZ: 2.5,           // Supply bottleneck
            unitLaborCost: 0.05,   // Elevated ULC
            outputGap: 0.02,
            weights: $weights
        );
        $this->assertGreaterThan($neutralPpi, $shockPpi);
        $this->assertGreaterThan(0.10, $shockPpi);

        // Clamping to bounds
        $clampedMax = $this->mathUtility->calculateStageOfProcessingPpi(
            metalsInflation: 2.0,
            energyInflation: 2.0,
            agriInflation: 2.0,
            gscpiZ: 10.0,
            unitLaborCost: 1.0,
            outputGap: 1.0,
            weights: $weights,
            min: -0.06,
            max: 0.25
        );
        $this->assertEqualsWithDelta(0.25, $clampedMax, 0.0001);
    }

    public function testCalculateTobinsQHousingStarts(): void
    {
        $params = [
            'baseline' => 100.0,
            'qSens' => 45.0,
            'costSens' => 550.0,
            'sloosSens' => 30.0,
            'kappa' => 1.20,
            'sigma' => 0.03,
            'min' => 40.0,
            'max' => 180.0,
        ];

        // Boom scenario: high house prices (Q > 1), low mortgage user cost
        $boomStarts = $this->mathUtility->calculateTobinsQHousingStarts(
            currentStarts: 100.0,
            residentialPriceRatio: 1.20,
            replacementCostRatio: 1.00,
            userCost: 0.035,
            neutralUserCost: 0.050,
            sloosTightening: 0.0,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertGreaterThan(100.0, $boomStarts, 'High Tobin Q and cheap mortgage financing must stimulate housing starts.');

        // Housing slump: high user cost, bank tightening, falling price/cost ratio
        $slumpStarts = $this->mathUtility->calculateTobinsQHousingStarts(
            currentStarts: 100.0,
            residentialPriceRatio: 0.85,
            replacementCostRatio: 1.10,
            userCost: 0.075,
            neutralUserCost: 0.050,
            sloosTightening: 0.35,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertLessThan(100.0, $slumpStarts, 'High mortgage rates and tight bank lending must depress housing starts.');
        $this->assertGreaterThanOrEqual(40.0, $slumpStarts);
    }

    public function testCalculateBroadMoneyGrowth(): void
    {
        $params = [
            'qeSens' => 0.08,
            'sloosSens' => 0.06,
            'gapSens' => 0.30,
            'kappa' => 1.00,
            'sigma' => 0.004,
            'min' => -0.04,
            'max' => 0.25,
        ];

        // Expansionary QE and loose credit
        $qeGrowth = $this->mathUtility->calculateBroadMoneyGrowth(
            currentM2Growth: 0.055,
            baseGrowth: 0.055,
            balanceSheetIntensity: 0.50, // Active QE
            sloosTightening: -0.10,      // Easing standards
            outputGap: 0.02,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertGreaterThan(0.055, $qeGrowth, 'Central bank balance sheet expansion must accelerate M2 broad money growth.');

        // Quantitative tightening & banking credit crunch
        $qtGrowth = $this->mathUtility->calculateBroadMoneyGrowth(
            currentM2Growth: 0.055,
            baseGrowth: 0.055,
            balanceSheetIntensity: -0.50, // Active QT runoff
            sloosTightening: 0.40,        // 40% net banks tightening
            outputGap: -0.03,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertLessThan(0.055, $qtGrowth, 'QT runoff and commercial bank lending standards tightening must decelerate M2 growth.');
        $this->assertGreaterThanOrEqual(-0.04, $qtGrowth);
    }

    public function testCalculateDiminishingDistressMultiplier(): void
    {
        // Zero or negative distress signal yields 0.0
        $this->assertSame(0.0, $this->mathUtility->calculateDiminishingDistressMultiplier(0.0));
        $this->assertSame(0.0, $this->mathUtility->calculateDiminishingDistressMultiplier(-0.5));

        // At half-saturation S = K_s = 1.0, multiplier is exactly half of M_max (1.25 / 2 = 0.625)
        $half = $this->mathUtility->calculateDiminishingDistressMultiplier(1.0, 1.25, 1.0);
        $this->assertEqualsWithDelta(0.625, $half, 0.0001);

        // Under an extreme shock S = 100.0, multiplier approaches M_max without exceeding it
        $extreme = $this->mathUtility->calculateDiminishingDistressMultiplier(100.0, 1.25, 1.0);
        $this->assertLessThanOrEqual(1.25, $extreme);
        $this->assertGreaterThan(1.20, $extreme);

        // Infinite shock remains strictly bounded by M_max
        $infinite = $this->mathUtility->calculateDiminishingDistressMultiplier(1_000_000.0, 1.25, 1.0);
        $this->assertLessThanOrEqual(1.25, $infinite);
    }

    public function testMacroTransmissionHelpers(): void
    {
        // 1. calculatePmiDemandShift
        // Neutral 50.0 -> 0.0 shift
        $neutralPmi = $this->mathUtility->calculatePmiDemandShift(50.0);
        $this->assertEqualsWithDelta(0.0, $neutralPmi, 0.0001);

        // Expansion 55.0 -> (55-50)/50 * 0.50 = +0.05
        $expansionPmi = $this->mathUtility->calculatePmiDemandShift(55.0, 50.0, 0.50);
        $this->assertEqualsWithDelta(0.05, $expansionPmi, 0.0001);

        // Contraction 45.0 -> (45-50)/50 * 0.50 = -0.05
        $contractionPmi = $this->mathUtility->calculatePmiDemandShift(45.0, 50.0, 0.50);
        $this->assertEqualsWithDelta(-0.05, $contractionPmi, 0.0001);

        // Clamping bounds [-0.30, 0.30]
        $extremePmi = $this->mathUtility->calculatePmiDemandShift(90.0, 50.0, 1.0);
        $this->assertLessThanOrEqual(0.30, $extremePmi);

        // 2. calculatePpiCostDrag
        // PPI <= target yields 0.0 drag
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculatePpiCostDrag(0.02, 0.02), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculatePpiCostDrag(0.01, 0.02), 0.0001);

        // High PPI (0.06 vs 0.02 target), pricing power 0.50, sensitivity 0.50 -> (0.06-0.02) * (1 - 0.5) * 0.5 = 0.01
        $ppiDrag = $this->mathUtility->calculatePpiCostDrag(0.06, 0.02, 0.50, 0.50);
        $this->assertEqualsWithDelta(0.01, $ppiDrag, 0.0001);

        // Perfect pricing power (1.0) eliminates PPI cost drag
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculatePpiCostDrag(0.08, 0.02, 1.0), 0.0001);

        // 3. calculateHousingStartsShift
        // Neutral 100.0 -> 0.0 shift
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculateHousingStartsShift(100.0), 0.0001);
        // Boom 120.0 -> (120-100)/100 * 0.30 = +0.06
        $this->assertEqualsWithDelta(0.06, $this->mathUtility->calculateHousingStartsShift(120.0, 100.0, 0.30), 0.0001);

        // 4. calculateTradeBalanceShift
        // Baseline -0.028 -> 0.0
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculateTradeBalanceShift(-0.028, -0.028), 0.0001);
        // Improvement to -0.018 (+0.01) * 2.0 = +0.02
        $this->assertEqualsWithDelta(0.02, $this->mathUtility->calculateTradeBalanceShift(-0.018, -0.028, 2.0), 0.0001);

        // 5. calculateBroadMoneyLiquidityShift
        // Neutral 0.055 -> 0.0
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculateBroadMoneyLiquidityShift(0.055, 0.055), 0.0001);
        // Expansion 0.085 (+0.03) * 0.50 = +0.015
        $this->assertEqualsWithDelta(0.015, $this->mathUtility->calculateBroadMoneyLiquidityShift(0.085, 0.055, 0.50), 0.0001);

        // 6. calculateCapacityUtilizationShift
        // Neutral 78.5 -> 0.0
        $this->assertEqualsWithDelta(0.0, $this->mathUtility->calculateCapacityUtilizationShift(78.5, 78.5), 0.0001);
        // Shift to 80.5 (+2.0 points) -> (2.0 / 100) * 0.40 = 0.008 (+0.8%)
        $this->assertEqualsWithDelta(0.008, $this->mathUtility->calculateCapacityUtilizationShift(80.5, 78.5, 0.40), 0.0001);
    }
}



