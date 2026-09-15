<?php

namespace App\Tests\Service\Math;

use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class MathUtilityTest extends TestCase
{
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
    }

    public function testKouTruncatedSecondMomentMatchesTheUncappedMomentWhenTheCapIsFarOut(): void
    {
        // With the cap many mean jump sizes away, virtually no mass is truncated and the closed form must
        // collapse to the plain exponential second moment, 2 / eta^2.
        $etaUp = 400.0;
        $etaDown = 280.0;

        $moment = $this->mathUtility->kouTruncatedSecondMoment(0.35, $etaUp, $etaDown, 0.2624, 0.3567);
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

        $moment = $this->mathUtility->kouTruncatedSecondMoment(0.40, $etaUp, $etaDown, 0.2624, 0.3567);
        $uncapped = (0.40 * 2.0 / ($etaUp * $etaUp)) + (0.60 * 2.0 / ($etaDown * $etaDown));

        $this->assertLessThan($uncapped, $moment);
        $this->assertGreaterThan($uncapped * 0.5, $moment);
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
            $this->mathUtility->kouTruncatedSecondMoment($pUp, $etaUp, $etaDown, 0.2624, 0.3567),
            $sum / $draws,
            0.0005,
            'The closed form must agree with the draws the engine actually takes.'
        );
    }

    public function testKouTruncatedCompensatorIsNegativeForADownSkewedJump(): void
    {
        // Kou's jump here is skewed down: more likely to fall, and further when it does. E[e^J - 1] is
        // therefore negative, which is exactly the drift the engine has to give back.
        $compensator = $this->mathUtility->kouTruncatedCompensator(0.40, 1.0 / 0.10, 1.0 / 0.125, 0.2624, 0.3567);

        $this->assertLessThan(0.0, $compensator);
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
            $this->mathUtility->kouTruncatedCompensator($pUp, $etaUp, $etaDown, 0.2624, 0.3567),
            $sum / $draws,
            0.001,
            'The compensator must be the mean of exactly the draws the engine takes.'
        );
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

    /**
     * With a sector prior supplied, a well-conditioned firm keeps most of its own Gordon multiple. At a 7%
     * spread the firm's precision (spread^2) dominates the prior's, so it carries ~84% of the weight and the
     * result sits close to the raw 11.43x rather than the 20x sector.
     */
    public function testIntrinsicFairValuePEKeepsFirmEstimateWhenWellConditioned(): void
    {
        $raw = $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.15, 0.03);
        $shrunk = $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.15, 0.03, 20.0);

        $this->assertEqualsWithDelta(11.42857, $raw, 0.001);
        $this->assertEqualsWithDelta(12.75862, $shrunk, 0.001, 'A 7% spread must leave the firm estimate dominant.');
        $this->assertLessThan(abs(20.0 - $raw), abs(20.0 - $shrunk), 'Shrinkage must move the estimate toward its sector.');
    }

    /**
     * The case the shrinkage exists for. A 4.5% cost of equity against 6% growth leaves the denominator on
     * its 50bp floor and a raw multiple of 160x, which previously pinned to the 35x ceiling and stayed there
     * for every such firm. The firm's weight falls as spread^2, so its contribution collapses and the
     * estimate lands near its sector instead of on a shared ceiling.
     */
    public function testIntrinsicFairValuePEFallsBackToSectorWhenDenominatorCollapses(): void
    {
        $raw = $this->mathUtility->calculateIntrinsicFairValuePE(0.045, 0.20, 0.06);
        $shrunk = $this->mathUtility->calculateIntrinsicFairValuePE(0.045, 0.20, 0.06, 16.0);

        $this->assertEqualsWithDelta(FinancialConstants::MAX_INTRINSIC_PE, $raw, 0.0001, 'Unshrunk, this diverges to the ceiling.');
        $this->assertEqualsWithDelta(19.89189, $shrunk, 0.001);
        $this->assertLessThan(FinancialConstants::MAX_INTRINSIC_PE, $shrunk, 'The ceiling must stop being what sets the multiple.');

        // Two firms that both pinned to the ceiling must now separate by their own spreads rather than
        // sharing a single capped multiple.
        $wider = $this->mathUtility->calculateIntrinsicFairValuePE(0.08, 0.20, 0.15, 16.0);
        $this->assertEqualsWithDelta(FinancialConstants::MAX_INTRINSIC_PE, $this->mathUtility->calculateIntrinsicFairValuePE(0.08, 0.20, 0.15), 0.0001);
        $this->assertGreaterThan($shrunk, $wider, 'The better-conditioned of two ceiling-pinned firms must now price higher.');
    }

    /**
     * The firm keeps more of its own multiple the wider its spread. Asserted on the implied weight rather
     * than on distance from the prior: the raw multiple falls as the spread widens and crosses the sector on
     * the way, so distance is not monotonic even though the weight is.
     */
    public function testIntrinsicFairValuePEShrinksHarderAsTheSpreadNarrows(): void
    {
        $sector = 12.0;
        $previousWeight = null;

        // Spreads of 2.5%, 4%, 6% and 10%, all clear of the 4% cost-of-equity floor that would collapse them.
        foreach ([0.045, 0.06, 0.08, 0.12] as $costOfEquity) {
            $raw = $this->mathUtility->calculateIntrinsicFairValuePE($costOfEquity, 0.20, 0.02);
            $shrunk = $this->mathUtility->calculateIntrinsicFairValuePE($costOfEquity, 0.20, 0.02, $sector);

            $this->assertNotEqualsWithDelta($sector, $raw, 0.01, 'Test inputs must keep the raw multiple off the prior.');
            $impliedWeight = ($shrunk - $sector) / ($raw - $sector);

            $this->assertGreaterThanOrEqual(0.0, $impliedWeight);
            $this->assertLessThanOrEqual(1.0, $impliedWeight, 'The result must lie between the firm estimate and its sector.');

            if ($previousWeight !== null) {
                $this->assertGreaterThan(
                    $previousWeight,
                    $impliedWeight,
                    'A wider spread is a more reliable estimate and must keep more of the firm-level multiple.'
                );
            }
            $previousWeight = $impliedWeight;
        }
    }

    /** Omitting the prior must reproduce the unshrunk Gordon multiple exactly, for callers without a sector. */
    public function testIntrinsicFairValuePEIsUnchangedWithoutASectorPrior(): void
    {
        foreach ([[0.10, 0.15, 0.03], [0.12, 0.25, 0.05], [0.06, 0.08, 0.01]] as [$coe, $roic, $growth]) {
            $this->assertSame(
                $this->mathUtility->calculateIntrinsicFairValuePE($coe, $roic, $growth),
                $this->mathUtility->calculateIntrinsicFairValuePE($coe, $roic, $growth, null)
            );
        }
    }

    /** A non-positive sector multiple is not a usable prior and must be ignored rather than dragging the estimate to zero. */
    public function testIntrinsicFairValuePEIgnoresAnUnusableSectorPrior(): void
    {
        $raw = $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.15, 0.03);

        $this->assertSame($raw, $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.15, 0.03, 0.0));
        $this->assertSame($raw, $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.15, 0.03, -5.0));
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
            creditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            capacityUtilization: 1.0,
            interbankLiquiditySpread: MacroEngine::INTERBANK_BASELINE_SPREAD
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
            creditSpread: MacroEngine::BASE_CREDIT_SPREAD,
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

        // Roaring boom: credit spreads 50 bps inside baseline, high interbank liquidity (-50bps), 100% capacity utilization
        $boomIntensity = $this->mathUtility->calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $baseIntensity,
            creditSpread: MacroEngine::BASE_CREDIT_SPREAD - 0.005,
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

        // The full cycle is bounded at ~2x either way: iterate each regime to its target with no noise.
        $trough = 100.0;
        $peak = 100.0;
        for ($i = 0; $i < 40; $i++) {
            $trough = $this->mathUtility->calculateCapitalMarketsDealIndexStep($trough, 0.070, 0.150, 0.60, 0.25, 0.0);
            $peak = $this->mathUtility->calculateCapitalMarketsDealIndexStep($peak, 0.025, 0.020, 0.09, 0.25, 0.0);
        }
        $floor = MacroEngine::DEAL_ACTIVITY_BASELINE * exp(-MacroEngine::DEAL_ACTIVITY_LOG_RANGE);
        $ceiling = MacroEngine::DEAL_ACTIVITY_BASELINE * exp(MacroEngine::DEAL_ACTIVITY_LOG_RANGE);
        $this->assertEqualsWithDelta($floor, $trough, 0.5, 'A 2008-type freeze bottoms at the capped log range, not at the hard floor.');
        $this->assertGreaterThan(140.0, $peak, 'A 2007/2021-type boom runs about 1.5x baseline.');
        $this->assertLessThan($ceiling, $peak, 'and does not need the cap to stay within a recorded cycle.');
        $this->assertLessThan(2.8, $peak / $trough, 'Peak-to-trough deal volume stays near the 2.2x of the widest recorded cycle.');
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

    public function testBlissSlopeDecaySeparatesSlopeLoadingFromCurvatureHump(): void
    {
        $level = 0.045;
        $slope = -0.03;
        $lambda1 = 0.73;

        // With no slope decay given, Bliss collapses to plain Svensson.
        $plain = $this->mathUtility->calculateSvenssonYield($level, $slope, 0.0, 0.0, 10.0, $lambda1, 0.15);
        $collapsed = $this->mathUtility->calculateSvenssonYield($level, $slope, 0.0, 0.0, 10.0, $lambda1, 0.15, null);
        $this->assertEqualsWithDelta($plain, $collapsed, 0.0000001);

        // A slower slope decay keeps more of the short-rate gap alive at ten years: 0.32 versus 0.14 loading.
        $bliss = $this->mathUtility->calculateSvenssonYield($level, $slope, 0.0, 0.0, 10.0, $lambda1, 0.15, 0.30);
        $expectedLoad = (1.0 - exp(-3.0)) / 3.0;
        $this->assertEqualsWithDelta($level + $slope * $expectedLoad, $bliss, 0.0000001, 'Slope must load on its own decay.');
        $this->assertLessThan($plain, $bliss, 'A negative slope with slower decay pulls the ten-year lower.');

        // The curvature hump is untouched by the slope decay: same yield difference for a curvature shock either way.
        $humpPlain = $this->mathUtility->calculateSvenssonYield($level, $slope, 0.02, 0.0, 2.5, $lambda1, 0.15)
            - $this->mathUtility->calculateSvenssonYield($level, $slope, 0.0, 0.0, 2.5, $lambda1, 0.15);
        $humpBliss = $this->mathUtility->calculateSvenssonYield($level, $slope, 0.02, 0.0, 2.5, $lambda1, 0.15, 0.30)
            - $this->mathUtility->calculateSvenssonYield($level, $slope, 0.0, 0.0, 2.5, $lambda1, 0.15, 0.30);
        $this->assertEqualsWithDelta($humpPlain, $humpBliss, 0.0000001, 'Curvature loading must depend only on lambda1.');
    }

    // --- Contract coverage for formulas whose only other exercise is a higher suite ---

    /**
     * The Theory of Storage curve: flat in contango, rising asymptotically as inventory nears the buffer floor.
     */
    public function testConvenienceYieldIsZeroInContangoAndRisesAsInventoryDepletes(): void
    {
        $this->assertSame(0.0, $this->mathUtility->calculateConvenienceYield(100.0), 'At neutral inventory the market is in contango.');
        $this->assertSame(0.0, $this->mathUtility->calculateConvenienceYield(140.0), 'Ample inventory stays in contango.');

        // Closed form below the neutral point: yieldScale * ((neutralSlack / bufferSlack)^exponent - 1).
        $this->assertEqualsWithDelta(
            0.10 * (pow(50.0 / 25.0, 1.8) - 1.0),
            $this->mathUtility->calculateConvenienceYield(75.0),
            0.0000001,
            'Backwardation yield must follow the storage curve exactly.'
        );

        $previous = -1.0;
        for ($level = 99.0; $level >= 51.0; $level -= 1.0) {
            $yield = $this->mathUtility->calculateConvenienceYield($level);
            $this->assertGreaterThan($previous, $yield, "Convenience yield must rise as inventory falls to {$level}.");
            $previous = $yield;
        }

        // The buffer slack floors at 1.0, so the curve saturates rather than diverging to infinity.
        $this->assertTrue(is_finite($this->mathUtility->calculateConvenienceYield(0.0)), 'A depleted inventory must not produce a non-finite yield.');
        $this->assertSame(
            $this->mathUtility->calculateConvenienceYield(51.0),
            $this->mathUtility->calculateConvenienceYield(10.0),
            'Below the buffer floor the curve saturates at its clamped value.'
        );
    }

    /**
     * The convex Phillips curve is piecewise: asymptotic in expansion, linearly rigid in contraction.
     */
    public function testConvexPhillipsCurvePiecewiseFormAndContinuityAtZero(): void
    {
        $this->assertSame(0.0, $this->mathUtility->calculateConvexPhillipsCurve(0.0), 'A closed output gap exerts no demand-pull pressure.');

        // Expansion branch: kappa * (y / (yMax - y)).
        $this->assertEqualsWithDelta(
            0.020 * (0.04 / (0.08 - 0.04)),
            $this->mathUtility->calculateConvexPhillipsCurve(0.04),
            0.0000001,
            'The expansion branch must follow the asymptotic form.'
        );

        // Contraction branch: (kappa / yMax) * rigidity * y — linear, so halving the gap halves the pressure.
        $this->assertEqualsWithDelta(
            (0.020 / 0.08) * 0.35 * -0.04,
            $this->mathUtility->calculateConvexPhillipsCurve(-0.04),
            0.0000001,
            'The contraction branch must follow the rigid linear form.'
        );
        $this->assertEqualsWithDelta(
            2.0 * $this->mathUtility->calculateConvexPhillipsCurve(-0.02),
            $this->mathUtility->calculateConvexPhillipsCurve(-0.04),
            0.0000001,
            'Downward rigidity is linear: no curvature below the closed gap.'
        );

        // The ceiling denominator floors at 0.005, so the gap may exceed capacity without dividing by zero.
        $this->assertTrue(is_finite($this->mathUtility->calculateConvexPhillipsCurve(0.08)), 'Reaching capacity must not divide by zero.');
        $this->assertTrue(is_finite($this->mathUtility->calculateConvexPhillipsCurve(0.20)), 'Overheating past capacity must stay finite.');

        // A stiffer rigidity factor deepens disinflation; it has no effect above the closed gap.
        $this->assertLessThan(
            $this->mathUtility->calculateConvexPhillipsCurve(-0.04, 0.08, 0.020, 0.35),
            $this->mathUtility->calculateConvexPhillipsCurve(-0.04, 0.08, 0.020, 0.70),
            'A weaker rigidity factor must let prices fall further.'
        );
        $this->assertSame(
            $this->mathUtility->calculateConvexPhillipsCurve(0.04, 0.08, 0.020, 0.35),
            $this->mathUtility->calculateConvexPhillipsCurve(0.04, 0.08, 0.020, 0.70),
            'Downward rigidity must not touch the expansion branch.'
        );
    }

    /**
     * Mean absolute deviation recovers sigma without letting one outlier dominate, unlike a sum of squares.
     */
    public function testMeanAbsoluteScaleRecoversSigmaAndResistsOutliers(): void
    {
        $this->assertSame(0.0, $this->mathUtility->calculateMeanAbsoluteScale([]), 'An empty sample has nothing to estimate from.');
        $this->assertSame(0.0, $this->mathUtility->calculateMeanAbsoluteScale([0.0, 0.0, 0.0]), 'A sample of exact forecasts has zero scale.');

        // E|X| = sigma * sqrt(2/pi), so a constant absolute deviation recovers that deviation over the constant.
        $this->assertEqualsWithDelta(
            1.0 / MathUtility::MEAN_ABSOLUTE_DEVIATION_TO_SIGMA,
            $this->mathUtility->calculateMeanAbsoluteScale([1.0, -1.0, 1.0, -1.0]),
            0.0000001,
            'The estimator must invert the normal mean-absolute-deviation constant.'
        );

        // Sign is discarded: only the magnitude of the surprise carries scale.
        $this->assertSame(
            $this->mathUtility->calculateMeanAbsoluteScale([0.4, -0.2, 0.6]),
            $this->mathUtility->calculateMeanAbsoluteScale([-0.4, 0.2, -0.6]),
            'The scale of a surprise must not depend on its direction.'
        );

        // The point of the estimator: one blow-up quarter must not dominate the other nine.
        $quiet = array_fill(0, 9, 0.02);
        $withOutlier = $quiet;
        $withOutlier[] = 2.0;

        $sumOfSquares = sqrt(array_sum(array_map(static fn (float $x): float => $x ** 2, $withOutlier)) / count($withOutlier));
        $robust = $this->mathUtility->calculateMeanAbsoluteScale($withOutlier);

        $this->assertLessThan($sumOfSquares, $robust, 'A fat tail must move the mean absolute estimate less than a root-mean-square.');
    }

    /**
     * Persistence inflates integrated variance; the scale factor must give it back exactly.
     */
    public function testPersistenceVarianceScaleRestoresIntegratedVariance(): void
    {
        $this->assertSame(1.0, $this->mathUtility->calculatePersistenceVarianceScale(0.0), 'An i.i.d. driver needs no rescaling.');

        // sqrt((1 - phi) / (1 + phi)) inverts the (1 + phi) / (1 - phi) variance inflation of an AR(1) sum.
        foreach ([0.10, 0.50, 0.90, 0.99] as $phi) {
            $scale = $this->mathUtility->calculatePersistenceVarianceScale($phi);
            $inflation = (1.0 + $phi) / (1.0 - $phi);
            $this->assertEqualsWithDelta(1.0, $scale ** 2 * $inflation, 0.0000001, "Scale must neutralise the variance inflation at phi={$phi}.");
            $this->assertLessThan(1.0, $scale, "A persistent driver must be damped, not amplified, at phi={$phi}.");
        }

        // phi is bounded below 1 so the scale never collapses to zero or goes imaginary.
        $this->assertGreaterThan(0.0, $this->mathUtility->calculatePersistenceVarianceScale(1.0), 'A unit root must clamp rather than annihilate the shock.');
        $this->assertSame(1.0, $this->mathUtility->calculatePersistenceVarianceScale(-0.5), 'Negative persistence clamps to the i.i.d. case.');
    }

    /**
     * Vayanos-Vila duration extraction: the premium shift is linear in tenor and flips sign between QE and QT.
     */
    public function testPreferredHabitatShiftScalesWithTenorAndFlipsBetweenQeAndQt(): void
    {
        // Delta TP(tau) = -lambda * (tau / 10) * intensity, so the ten-year is the unit of measure.
        $this->assertEqualsWithDelta(-0.005, $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.005, 10.0), 0.0000001, 'QE must suppress the ten-year premium one-for-one with intensity.');
        $this->assertEqualsWithDelta(-0.001, $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.005, 2.0), 0.0000001, 'The two-year absorbs a fifth of the ten-year shift.');
        $this->assertEqualsWithDelta(-0.015, $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.005, 30.0), 0.0000001, 'The thirty-year absorbs three times the ten-year shift.');

        // QT extracts negative duration: the same magnitude steepens instead of compressing.
        $this->assertEqualsWithDelta(
            -$this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.005, 10.0),
            $this->mathUtility->calculatePreferredHabitatTermPremiumShift(-0.005, 10.0),
            0.0000001,
            'QT must mirror QE of the same intensity.'
        );

        $this->assertSame(0.0, $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.0, 10.0), 'A flat balance sheet shifts nothing.');
        $this->assertSame(0.0, $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.005, 0.0), 'Overnight paper carries no duration to extract.');

        // Because the shift grows with tenor, QE always compresses the 2s30s slope.
        $twos = $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.004, 2.0);
        $thirties = $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.004, 30.0);
        $this->assertLessThan($twos, $thirties, 'QE must bite hardest at the long end.');
    }

    /**
     * The three cash-conversion-cycle legs move on separate drivers and are not interchangeable.
     */
    public function testWorkingCapitalDayShiftsRespondToTheirOwnDriverOnly(): void
    {
        $neutral = $this->mathUtility->calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD,
            1.0,
            MacroEngine::INTERBANK_BASELINE_SPREAD
        );

        $this->assertEqualsWithDelta(0.0, $neutral['dso'], 0.0000001, 'At the baseline spread receivables do not age.');
        $this->assertEqualsWithDelta(0.0, $neutral['dio'], 0.0000001, 'At full capacity inventory does not build.');
        $this->assertEqualsWithDelta(0.0, $neutral['dpo'], 0.0000001, 'At baseline liquidity payables do not contract.');

        // Dear credit stretches customer payment: DSO rises with the spread over baseline.
        $creditStress = $this->mathUtility->calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD + 0.010,
            1.0,
            MacroEngine::INTERBANK_BASELINE_SPREAD
        );
        $this->assertEqualsWithDelta(0.010 * FinancialConstants::CCC_DSO_CREDIT_SPREAD_SENSITIVITY, $creditStress['dso'], 0.0000001, 'DSO must track the credit spread.');
        $this->assertEqualsWithDelta(0.0, $creditStress['dio'], 0.0000001, 'A credit shock must not move inventory days.');
        $this->assertEqualsWithDelta(0.0, $creditStress['dpo'], 0.0000001, 'A credit shock must not move payable days.');

        // Slack plants pile up unsold goods: DIO rises as utilisation falls.
        $slack = $this->mathUtility->calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD,
            0.80,
            MacroEngine::INTERBANK_BASELINE_SPREAD
        );
        $this->assertEqualsWithDelta(0.20 * FinancialConstants::CCC_DIO_CAPACITY_SENSITIVITY, $slack['dio'], 0.0000001, 'DIO must track idle capacity.');
        $this->assertEqualsWithDelta(0.0, $slack['dso'], 0.0000001, 'Idle capacity must not move receivable days.');

        // Interbank stress makes vendors demand cash sooner, so DPO contracts (negative shift).
        $liquidityStress = $this->mathUtility->calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD,
            1.0,
            MacroEngine::INTERBANK_BASELINE_SPREAD + 0.004
        );
        $this->assertEqualsWithDelta(-0.004 * FinancialConstants::CCC_DPO_LIQUIDITY_SENSITIVITY, $liquidityStress['dpo'], 0.0000001, 'DPO must contract under interbank stress.');
        $this->assertLessThan(0.0, $liquidityStress['dpo'], 'Vendors demanding faster payment shortens the payable cycle.');
    }

    /**
     * Jarrow-Lando-Turnbull: the HY tranche gaps away from IG in a contraction rather than tracking it.
     */
    public function testDualTrancheCreditSpreadsWidenAsymmetricallyAndRespectTheirClamps(): void
    {
        // Neutral cycle, vol exactly at the threshold, no interbank stress: IG is the base spread untouched.
        $neutral = $this->mathUtility->calculateDualTrancheCreditSpreads(
            MacroEngine::BASE_CREDIT_SPREAD,
            0.0,
            MacroEngine::CREDIT_SPREAD_EXCESS_VOL_THRESHOLD,
            0.0
        );
        $this->assertEqualsWithDelta(MacroEngine::BASE_CREDIT_SPREAD, $neutral['ig'], 0.0000001, 'A closed gap leaves the IG spread at its base.');
        $this->assertEqualsWithDelta(
            MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
            $neutral['hy'],
            0.0000001,
            'With no contraction the HY tranche is a flat multiple of IG.'
        );

        // Vol below the threshold contributes nothing: the excess term is one-sided.
        $lowVol = $this->mathUtility->calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, 0.0, 0.05, 0.0);
        $this->assertEqualsWithDelta($neutral['ig'], $lowVol['ig'], 0.0000001, 'Calm markets must not tighten spreads below base.');

        // Excess vol and interbank stress are additive on the IG leg.
        $stressed = $this->mathUtility->calculateDualTrancheCreditSpreads(
            MacroEngine::BASE_CREDIT_SPREAD,
            0.0,
            MacroEngine::CREDIT_SPREAD_EXCESS_VOL_THRESHOLD + 0.10,
            0.05
        );
        $this->assertEqualsWithDelta(
            MacroEngine::BASE_CREDIT_SPREAD
                + 0.10 * MacroEngine::MERTON_VOL_SENSITIVITY
                + 0.05 * MacroEngine::INTERBANK_CREDIT_CONTAGION_SENSITIVITY,
            $stressed['ig'],
            0.0000001,
            'Vol and contagion must add linearly onto the cycle spread.'
        );

        // The fallen-angel cliff: in a contraction HY widens by proportionally more than IG.
        $recession = $this->mathUtility->calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, -0.04, 0.10, 0.0);
        $this->assertGreaterThan($neutral['ig'], $recession['ig'], 'A contraction must widen investment grade.');
        $this->assertGreaterThan(
            $recession['ig'] / $neutral['ig'],
            $recession['hy'] / $neutral['hy'],
            'High yield must gap away from investment grade, not track it.'
        );

        // Both legs clamp: a depression cannot produce an unbounded spread.
        $depression = $this->mathUtility->calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, -1.0, 2.0, 1.0);
        $this->assertSame(MacroEngine::MAX_CREDIT_SPREAD, $depression['ig'], 'The IG spread must clamp at its ceiling.');
        $this->assertSame(MacroEngine::MAX_HY_CREDIT_SPREAD, $depression['hy'], 'The HY spread must clamp at its ceiling.');

        // A boom floors IG, and HY never compresses inside its minimum multiple of IG.
        $boom = $this->mathUtility->calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, 0.50, 0.0, 0.0);
        $this->assertSame(MacroEngine::MIN_CREDIT_SPREAD, $boom['ig'], 'The IG spread must floor at its minimum.');
        $this->assertGreaterThanOrEqual(
            $boom['ig'] * MacroEngine::HY_MIN_SPREAD_MULTIPLIER,
            $boom['hy'],
            'HY must never compress inside its minimum multiple of IG.'
        );
    }

    /**
     * Metzler inventory dynamics: an involuntary build on a demand miss, then correction toward a cyclical target.
     */
    public function testInventoryCycleStepBuildsOnDemandMissesAndRevertsToTheCyclicalTarget(): void
    {
        // Closed form: (surpriseSens * (ema - gap) + -speed * (current - -cyclicalSens * gap)) * dt, added to current.
        $this->assertEqualsWithDelta(
            0.006,
            $this->mathUtility->calculateInventoryCycleStep(0.0, -0.03, 0.0, 0.5, 0.4, 0.25),
            0.0000001,
            'An unexpected demand miss must build inventory by the closed form.'
        );

        // A demand surprise to the upside draws inventory down instead.
        $this->assertLessThan(
            0.0,
            $this->mathUtility->calculateInventoryCycleStep(0.0, 0.03, 0.0, 0.5, 0.4, 0.25),
            'Unexpectedly strong demand must deplete inventory.'
        );

        // With no surprise and a closed gap the target is zero, so any overhang decays toward it.
        $decayed = $this->mathUtility->calculateInventoryCycleStep(0.10, 0.0, 0.0, 0.5, 0.4, 0.25);
        $this->assertLessThan(0.10, $decayed, 'An overhang must dissipate when demand is at trend.');
        $this->assertGreaterThan(0.0, $decayed, 'Dissipation must be gradual, not instant.');

        // The cyclical target is signed against the output gap: contractions raise the desired inventory-to-sales ratio.
        $contraction = $this->mathUtility->calculateInventoryCycleStep(0.0, -0.05, -0.05, 0.5, 0.4, 0.25);
        $expansion = $this->mathUtility->calculateInventoryCycleStep(0.0, 0.05, 0.05, 0.5, 0.4, 0.25);
        $this->assertGreaterThan(0.0, $contraction, 'A contraction must lift the inventory target.');
        $this->assertLessThan(0.0, $expansion, 'An expansion must lean inventories out.');

        // The gap is hard-clamped so a violent step cannot run away.
        $this->assertSame(0.15, $this->mathUtility->calculateInventoryCycleStep(0.14, -0.20, 0.20, 0.5, 1.0, 1.0), 'The overhang must clamp at its ceiling.');
        $this->assertSame(-0.15, $this->mathUtility->calculateInventoryCycleStep(-0.14, 0.20, -0.20, 0.5, 1.0, 1.0), 'The shortage must clamp at its floor.');
    }

    /**
     * Over a vanishing horizon there is no time to revert, so the horizon volatility is spot volatility.
     */
    public function testHorizonVolatilityCollapsesToSpotOverAVanishingHorizon(): void
    {
        $this->assertEqualsWithDelta(
            1.05,
            $this->mathUtility->averageMeanRevertingVolatility(1.05, 0.35, 1.1507, 0.0001),
            0.005
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
            $this->mathUtility->averageMeanRevertingVolatility(1.05, 0.35, 1.1507, 400.0),
            0.01
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
            $this->mathUtility->averageMeanRevertingVolatility($spot, $longRun, $kappa, $horizon),
            1.0e-9
        );
        $this->assertEqualsWithDelta(0.5406, $expected, 0.001, 'the closed form itself must not drift');
    }

    /**
     * A shock is damped toward the long-run level, never below it and never above the shock itself.
     */
    public function testHorizonVolatilityStaysBetweenTheLongRunLevelAndTheShock(): void
    {
        $horizonVol = $this->mathUtility->averageMeanRevertingVolatility(1.05, 0.35, 1.1507, 5.0);

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
            $this->mathUtility->averageMeanRevertingVolatility(0.28, 0.28, 1.1507, 5.0),
            1.0e-9
        );
    }

    /**
     * A volatility below its long-run level reverts upward, which is the same formula read the other way.
     */
    public function testHorizonVolatilityPullsAQuietFirmBackUpTowardItsLongRunLevel(): void
    {
        $horizonVol = $this->mathUtility->averageMeanRevertingVolatility(0.10, 0.35, 1.1507, 5.0);

        $this->assertGreaterThan(0.10, $horizonVol);
        $this->assertLessThan(0.35, $horizonVol);
    }

    /**
     * A degenerate reversion speed or horizon cannot divide by zero; it falls back to spot.
     */
    public function testHorizonVolatilityFallsBackToSpotOnADegenerateProcess(): void
    {
        $this->assertEqualsWithDelta(0.42, $this->mathUtility->averageMeanRevertingVolatility(0.42, 0.20, 0.0, 5.0), 1.0e-9);
        $this->assertEqualsWithDelta(0.42, $this->mathUtility->averageMeanRevertingVolatility(0.42, 0.20, 1.15, 0.0), 1.0e-9);
    }

    // --- Shared valuation helpers ---

    public function testExcessOverBaselineIsTheFlooredRelativeExcess(): void
    {
        $this->assertEqualsWithDelta(0.5, MathUtility::excessOverBaseline(0.03, 0.02), 1e-12);
        $this->assertSame(0.0, MathUtility::excessOverBaseline(0.01, 0.02));
        $this->assertSame(0.0, MathUtility::excessOverBaseline(0.02, 0.02));
    }

    public function testExpectedNominalGrowthCarriesTheCycleAndIsCapped(): void
    {
        // Boom: secular 2% + half of a 2% gap at beta 1 = 3% real, plus half of 2% inflation = 4% nominal.
        $this->assertEqualsWithDelta(0.04, $this->mathUtility->calculateExpectedNominalGrowth(0.02, 0.02, 1.0, 0.02, 0.0), 1e-12);
        // Bust at the same beta takes the same amount off; a flat 2% is what the corporate engines used to assume everywhere.
        $this->assertEqualsWithDelta(0.02, $this->mathUtility->calculateExpectedNominalGrowth(0.02, -0.02, 1.0, 0.02, 0.0), 1e-12);
        // Stagflation drag reaches a firm with no moat and is offset by pricing power.
        $this->assertLessThan(
            $this->mathUtility->calculateExpectedNominalGrowth(0.02, 0.0, 1.0, 0.06, 1.0),
            $this->mathUtility->calculateExpectedNominalGrowth(0.02, 0.0, 1.0, 0.06, 0.0)
        );
        $this->assertSame(FinancialConstants::MAX_EXPECTED_GROWTH, $this->mathUtility->calculateExpectedNominalGrowth(0.10, 0.05, 2.0, 0.02, 0.0));
        $this->assertSame(0.0, $this->mathUtility->calculateExpectedNominalGrowth(-0.10, 0.0, 1.0, 0.0, 0.0));
    }

    public function testManagementAndTheMarketStrikeTheSameFairValueMultiple(): void
    {
        $growth = $this->mathUtility->calculateExpectedNominalGrowth(0.03, 0.01, 1.2, 0.025, 0.02);
        $market = $this->mathUtility->calculateQualityAdjustedFairValuePE(0.09, 0.18, $growth, 22.0, 0.04);
        $management = $this->mathUtility->calculateManagementFairValuePE(0.09, 0.18, 0.03, 0.01, 1.2, 0.025, 0.02, 22.0, 0.04);

        $this->assertSame($market, $management);
        // The Sloan discount is inside the shared figure, floored at the distressed multiple.
        $clean = $this->mathUtility->calculateQualityAdjustedFairValuePE(0.09, 0.18, $growth, 22.0, 0.0);
        $this->assertEqualsWithDelta($clean - 0.04 * FinancialConstants::ACCRUALS_ANOMALY_PE_PENALTY_SCALE, $market, 1e-12);
        $this->assertSame(FinancialConstants::MIN_INTRINSIC_PE, $this->mathUtility->calculateQualityAdjustedFairValuePE(0.09, 0.18, $growth, 22.0, 10.0));
    }

    public function testFringeAdjustedPriceLevelReducesToCournotWithNoFringeResponseAndSoftensItOtherwise(): void
    {
        $cournot = $this->mathUtility->calculateCournotPriceLevel(1.2, 1.25);

        // No fringe elasticity, or no fringe at all, is the plain Cournot level.
        $this->assertEqualsWithDelta($cournot, $this->mathUtility->calculateFringeAdjustedPriceLevel(1.2, 0.4, 1.25, 0.0), 1e-12);
        $this->assertEqualsWithDelta($cournot, $this->mathUtility->calculateFringeAdjustedPriceLevel(1.2, 1.0, 1.25, 1.0), 1e-12);
        // A balanced industry clears at one whatever the fringe does.
        $this->assertEqualsWithDelta(1.0, $this->mathUtility->calculateFringeAdjustedPriceLevel(1.0, 0.4, 1.25, 1.0), 1e-9);

        // With a responsive fringe the overbuild is partly absorbed by fringe exit: the price sits between
        // the Cournot level and one, and the solution satisfies the market-clearing identity exactly.
        $adjusted = $this->mathUtility->calculateFringeAdjustedPriceLevel(1.2, 0.4, 1.25, 1.0);
        $this->assertGreaterThan($cournot, $adjusted);
        $this->assertLessThan(1.0, $adjusted);
        $this->assertEqualsWithDelta(0.4 + 0.2 + 0.6 * $adjusted, $adjusted ** -1.25, 1e-9, 'roster + excess + fringe supply = demand');

        // A more elastic fringe absorbs more; a larger fringe absorbs more.
        $this->assertGreaterThan($adjusted, $this->mathUtility->calculateFringeAdjustedPriceLevel(1.2, 0.4, 1.25, 2.0));
        $this->assertGreaterThan($adjusted, $this->mathUtility->calculateFringeAdjustedPriceLevel(1.2, 0.2, 1.25, 1.0));
        // A hole (capacity short of trend) is likewise partly refilled: dearer than one, cheaper than Cournot.
        $short = $this->mathUtility->calculateFringeAdjustedPriceLevel(0.8, 0.4, 1.25, 1.0);
        $this->assertGreaterThan(1.0, $short);
        $this->assertLessThan($this->mathUtility->calculateCournotPriceLevel(0.8, 1.25), $short);
        $this->assertSame(1.0, $this->mathUtility->calculateFringeAdjustedPriceLevel(0.0, 0.4, 1.25, 1.0));
    }

    public function testCournotMarginalRevenueFactorIsTheLernerConditionScaledBySubstitutability(): void
    {
        // MR/P = 1 - s/e for a firm selling its whole output into one price.
        $this->assertEqualsWithDelta(1.0 - 0.4 / 1.25, $this->mathUtility->calculateCournotMarginalRevenueFactor(0.4, 1.25, 1.0), 1e-12);
        // Output that is only partly substitutable moves the industry price only partly.
        $this->assertEqualsWithDelta(1.0 - 0.4 * 0.5 / 1.25, $this->mathUtility->calculateCournotMarginalRevenueFactor(0.4, 1.25, 0.5), 1e-12);
        // Non-substitutable output, or a firm with no share, is a price-taker.
        $this->assertSame(1.0, $this->mathUtility->calculateCournotMarginalRevenueFactor(0.4, 1.25, 0.0));
        $this->assertSame(1.0, $this->mathUtility->calculateCournotMarginalRevenueFactor(0.0, 1.25, 1.0));
        // A closed loop with inelastic demand can go no lower than zero; share is capped at the whole market.
        $this->assertSame(0.0, $this->mathUtility->calculateCournotMarginalRevenueFactor(3.0, 0.8, 1.0));
        $this->assertSame(1.0, $this->mathUtility->calculateCournotMarginalRevenueFactor(0.4, 0.0, 1.0));
    }

    public function testCournotPriceLevelFallsWithExcessCapacityAtTheInverseElasticity(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->mathUtility->calculateCournotPriceLevel(1.0, 1.25), 1e-12);
        $this->assertEqualsWithDelta(1.2 ** (-0.8), $this->mathUtility->calculateCournotPriceLevel(1.2, 1.25), 1e-12);
        $this->assertGreaterThan(1.0, $this->mathUtility->calculateCournotPriceLevel(0.8, 1.25));
        $this->assertSame(1.0, $this->mathUtility->calculateCournotPriceLevel(0.0, 1.25));
        $this->assertSame(1.0, $this->mathUtility->calculateCournotPriceLevel(1.2, 0.0));
    }
}
