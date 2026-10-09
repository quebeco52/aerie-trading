<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use PHPUnit\Framework\TestCase;

/**
 * MACRO_VOL_BASE_ANCHOR has to be the level the volatility series actually reverts to.
 *
 * It is not a decorative number. The macro volatility feeds the IG and HY credit spreads, the financial
 * conditions index, the safe-haven FX bid and the flight-to-safety compression of the term premium, so a
 * series resting above its anchor quietly widens spreads and tightens conditions across the whole district.
 *
 * It had drifted, and nothing measured it. The variance process reverts to an anchor the jumps are first
 * subtracted from, and two things went wrong in that subtraction:
 *
 *  - The compensation was divided by a bare 3.0 rather than by the mean-reversion speed. For
 *    dv = kappa*(theta_adj - v)dt + sigma*sqrt(v)dW + dJ the stationary mean is theta_adj + lambda*m/kappa,
 *    so a third of the jump variance was never given back and the series reverted to 16.8% against a 15%
 *    anchor.
 *  - The jumps were sized to fund 73% of long-run variance, which left the diffusion an anchor of 7.75%
 *    volatility -- BELOW this class's own 8% clamp. The stationary density piled onto the clamp, and the
 *    clamp, not the anchor, set the realized level: 18.2% measured over 300 simulated years.
 *
 * The first three tests pin the algebra exactly and cost nothing. The last two run the real process forward
 * and measure what comes out, which is the only thing that proves the algebra was the whole story.
 */
class MacroVolatilityAnchorTest extends TestCase
{
    /** Fixed so the measurement is reproducible; the process consumes only its own draws. */
    private const SEED = 20260918;

    private const YEARS = 100.0;

    private const TICKS_PER_YEAR = 3600.0;

    /** Discarded before measuring: the path opens at the anchor and has to reach its stationary spread. */
    private const WARMUP_SHARE = 0.05;

    /**
     * How far the realized level may sit from the anchor (8%).
     *
     * Loose on purpose. The exact relationship is pinned algebraically above, so this is the end-to-end
     * check, and it only has to be tight enough to catch a real drift: the shipped parameters produced
     * 18.2%, which is 21% over and nowhere near this band.
     */
    private const ANCHOR_TOLERANCE = 0.08;

    /** @var array{rms: float, floorShare: float, leverage: float, jumpTickVarianceRise: float, calmTickVarianceRise: float}|null Memoized: one run serves every measurement. */
    private static ?array $measured = null;

    /**
     * What the jumps contribute to long-run variance, derived here from the theory rather than read off
     * the subsystem.
     *
     * This is the oracle the production compensation is checked against: for
     * dv = kappa*(theta_adj - v)dt + sigma*sqrt(v)dW + dJ with arrivals at lambda and mean size m, the
     * stationary mean is theta_adj + lambda*m/kappa. Stating it independently is the whole point -- a test
     * that called AssetMarketSubsystem::jumpVarianceDrag() for both sides of the comparison would prove
     * only that the method agrees with itself, which is what the first version of this test did and why it
     * passed against the divisor it was written to catch.
     */
    private static function theoreticalJumpContribution(): float
    {
        // Each variance jump is an exponential capped at K times its mean, so its mean is m (1 - e^-K).
        $meanVarianceJump = ((MacroEngine::SYSTEMIC_JUMP_PROBABILITY_UP * MacroEngine::SYSTEMIC_JUMP_VARIANCE_MEAN * StochasticProcesses::VARIANCE_JUMP_UPSIDE_MEAN_SHARE)
            + ((1.0 - MacroEngine::SYSTEMIC_JUMP_PROBABILITY_UP) * MacroEngine::SYSTEMIC_JUMP_VARIANCE_MEAN))
            * (1.0 - exp(-StochasticProcesses::MAX_VARIANCE_JUMP_MEAN_MULTIPLE));

        return (MacroEngine::SYSTEMIC_JUMP_INTENSITY * $meanVarianceJump) / AssetMarketSubsystem::MACRO_VOL_KAPPA;
    }

    /** The anchor the diffusion alone is given, as the subsystem actually computes it. */
    private static function diffusiveAnchorVariance(): float
    {
        return (MacroEngine::MACRO_VOL_BASE_ANCHOR ** 2) - AssetMarketSubsystem::jumpVarianceDrag();
    }

    /**
     * The compensation must give back exactly what the jumps add: lambda * m / kappa.
     *
     * This is the divisor stated as an identity. Dividing by anything other than the mean-reversion speed
     * leaves the process reverting above its own anchor.
     */
    public function testJumpCompensationReturnsExactlyWhatTheJumpsAdd(): void
    {
        $this->assertEqualsWithDelta(
            self::theoreticalJumpContribution(),
            AssetMarketSubsystem::jumpVarianceDrag(),
            1e-12,
            'The jump compensation must equal lambda * m / kappa, the variance the jump process adds to the '
            . 'stationary mean. Any other divisor leaves the series reverting off MACRO_VOL_BASE_ANCHOR.'
        );
    }

    /**
     * The diffusion needs an anchor of its own that clears the failsafe clamp.
     *
     * If the jumps are sized so aggressively that what is left for the diffusion sits at or under the floor,
     * the floor stops being a failsafe and becomes the calibration.
     */
    public function testDiffusiveAnchorClearsTheVolatilityFloor(): void
    {
        $diffusiveAnchorVol = sqrt(self::diffusiveAnchorVariance());

        $this->assertGreaterThan(
            AssetMarketSubsystem::MACRO_VOL_FLOOR * 1.25,
            $diffusiveAnchorVol,
            sprintf(
                'The diffusion is left an anchor of %.2f%% volatility against a %.2f%% floor: the jumps are '
                . 'funding too much of the variance budget and the clamp is setting the level.',
                100 * $diffusiveAnchorVol,
                100 * AssetMarketSubsystem::MACRO_VOL_FLOOR
            )
        );
    }

    /**
     * Feller, measured on the anchor the diffusion is actually given.
     *
     * 2 * kappa * theta >= sigma^2 keeps the square-root diffusion strictly positive. Measured against the
     * undiscounted anchor it looks satisfied; the jumps are subtracted first, and it is the remainder the
     * diffusion has to live on. Violated, the stationary density's mode sits at zero and the process spends
     * its time on the clamp.
     */
    public function testVarianceProcessSatisfiesFellerOnTheAdjustedAnchor(): void
    {
        $feller = 2.0 * AssetMarketSubsystem::MACRO_VOL_KAPPA * self::diffusiveAnchorVariance();
        $sigmaSquared = AssetMarketSubsystem::MACRO_VOL_SIGMA ** 2;

        $this->assertGreaterThanOrEqual(
            $sigmaSquared,
            $feller,
            sprintf(
                'Feller fails on the jump-adjusted anchor: 2*kappa*theta_adj = %.5f against sigma^2 = %.5f. '
                . 'Lower MACRO_VOL_SIGMA to at most %.4f, or leave the diffusion more of the budget.',
                $feller,
                $sigmaSquared,
                sqrt($feller)
            )
        );
    }

    public function testRealizedVolatilityRevertsToItsAnchor(): void
    {
        $realized = $this->measure()['rms'];
        $anchor = MacroEngine::MACRO_VOL_BASE_ANCHOR;

        $this->assertEqualsWithDelta(
            $anchor,
            $realized,
            $anchor * self::ANCHOR_TOLERANCE,
            sprintf(
                'Under neutral macro drivers the series realized %.2f%% volatility against a %.2f%% anchor.',
                100 * $realized,
                100 * $anchor
            )
        );
    }

    /**
     * The clamp is there to catch a degenerate draw, not to hold the series up.
     *
     * A process resting on its own floor is one whose parameters no longer describe it, which is exactly how
     * the drift above went unnoticed: the floor absorbed it and the series still printed plausible numbers.
     */
    public function testVolatilityFloorIsAFailsafeNotTheCalibration(): void
    {
        $floorShare = $this->measure()['floorShare'];

        $this->assertLessThan(
            0.01,
            $floorShare,
            sprintf(
                'The volatility floor bound on %.2f%% of ticks: the clamp, not MACRO_VOL_BASE_ANCHOR, is '
                . 'setting the level of the series.',
                100 * $floorShare
            )
        );
    }

    /**
     * The leverage effect (Heston 1993 rho; Ait-Sahalia, Fan & Li 2013): a falling market lifts variance. Before the
     * variance innovation loaded on the market shock the two were independent and the correlation sat at zero.
     */
    public function testVarianceRisesWhenTheMarketFalls(): void
    {
        $leverage = $this->measure()['leverage'];

        $this->assertLessThan(-0.5, $leverage, sprintf('corr(market shock, variance change) is %.3f; the leverage effect is missing.', $leverage));
        $this->assertGreaterThan(-1.0, $leverage);
    }

    /**
     * Contemporaneous jumps (Duffie, Pan & Singleton 2000; Eraker, Johannes & Polson 2003): the variance jumps on the
     * tick the market jumps, not on a clock of its own.
     */
    public function testVarianceJumpsLandWithTheMarketJump(): void
    {
        $measured = $this->measure();

        $this->assertGreaterThan(
            $measured['calmTickVarianceRise'] + (0.5 * MacroEngine::SYSTEMIC_JUMP_VARIANCE_MEAN),
            $measured['jumpTickVarianceRise'],
            'A market-wide jump must carry its variance jump on the same tick.'
        );
    }

    /**
     * Runs the variance process forward under neutral macro drivers and measures what it delivers.
     *
     * Neutral matters: with a zero output gap, a baseline credit spread and an upward-sloping curve the
     * macro driver is exactly zero, so the long-run target IS the anchor and nothing else can be blamed for
     * a gap between them.
     *
     * @return array{rms: float, floorShare: float, leverage: float, jumpTickVarianceRise: float, calmTickVarianceRise: float}
     */
    private function measure(): array
    {
        if (self::$measured !== null) {
            return self::$measured;
        }

        mt_srand(self::SEED);
        $subsystem = new AssetMarketSubsystem(new MathUtility());

        $state = new MacroState();
        $state->outputGap = 0.0;
        $state->macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD;
        $state->structuralSlope = MacroEngine::NS_BASE_TERM_PREMIUM;
        $state->marketVolatility = MacroEngine::MACRO_VOL_BASE_ANCHOR;

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $ticks = (int) (self::YEARS * self::TICKS_PER_YEAR);
        $warmup = (int) ($ticks * self::WARMUP_SHARE);

        $varianceSum = 0.0;
        $measured = 0;
        $onFloor = 0;
        $sumZ = $sumDv = $sumZz = $sumDvDv = $sumZDv = 0.0;
        $jumpRise = $calmRise = 0.0;
        $jumpTicks = 0;

        for ($tick = 0; $tick < $ticks; $tick++) {
            $previousVariance = $state->marketVolatility ** 2;
            $shock = $subsystem->updateSystemicMarketFactor($state, $dt);
            $state->marketVolatility = $subsystem->calculateMarketVolatility($state, $dt, $shock['varianceJump'], $shock['returnInnovation']);

            if ($tick < $warmup) {
                continue;
            }

            $varianceSum += $state->marketVolatility ** 2;
            $measured++;

            if ($state->marketVolatility <= AssetMarketSubsystem::MACRO_VOL_FLOOR) {
                $onFloor++;
            }

            $dv = ($state->marketVolatility ** 2) - $previousVariance;
            $z = $state->marketZ;
            $sumZ += $z;
            $sumDv += $dv;
            $sumZz += $z * $z;
            $sumDvDv += $dv * $dv;
            $sumZDv += $z * $dv;
            if ($state->marketJumpMultiplier !== 1.0) {
                $jumpRise += $dv;
                $jumpTicks++;
            } else {
                $calmRise += $dv;
            }
        }

        $covariance = ($sumZDv / $measured) - (($sumZ / $measured) * ($sumDv / $measured));
        $varZ = ($sumZz / $measured) - (($sumZ / $measured) ** 2);
        $varDv = ($sumDvDv / $measured) - (($sumDv / $measured) ** 2);

        // The anchor is a variance target, so the series is scored on root-mean-square volatility. The plain
        // mean of a right-skewed variance process sits below it by Jensen and would score a correct process
        // as too low.
        return self::$measured = [
            'rms' => sqrt($varianceSum / $measured),
            'floorShare' => $onFloor / $measured,
            'leverage' => $covariance / sqrt($varZ * $varDv),
            'jumpTickVarianceRise' => $jumpRise / max(1, $jumpTicks),
            'calmTickVarianceRise' => $calmRise / max(1, $measured - $jumpTicks),
        ];
    }
}
