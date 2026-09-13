<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Service\Market\MarketEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the common factors that reach an individual stock's price.
 *
 * The macro engine has long advanced a per-sector shock and a district-wide jump every tick, and the GBM
 * helper has long accepted a sector loading, but none of it was plumbed into the price: sector rotations were
 * invisible between earnings dates and the whole market could never gap at once. These tests fail if that
 * wiring is removed again, and they pin the direction and magnitude of each channel rather than merely its
 * presence.
 *
 * Randomness is stubbed to zero so every price here is deterministic: two runs that differ in exactly one
 * input differ in the price by exactly that input's contribution.
 */
#[AllowMockObjectsWithoutExpectations]
final class PriceFactorTransmissionTest extends TestCase
{
    private MathUtility&MockObject $mathUtility;
    private MarketEngine $engine;

    protected function setUp(): void
    {
        $this->mathUtility = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'checkProbability'])
            ->getMock();

        // No idiosyncratic diffusion shock and no idiosyncratic jump, so only the common factors move price.
        $this->mathUtility->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtility->method('checkProbability')->willReturn(false);

        $this->engine = new MarketEngine($this->mathUtility);
    }

    /**
     * A fairly valued name on a daily step, so mean reversion barely bites and the diffusion dominates.
     */
    private function priceOf(
        float $sectorZ = 0.0,
        float $marketJumpMultiplier = 1.0,
        float $beta = 1.0,
        float $recentPriceTrend = 0.0,
        float $currentPrice = 60.0
    ): float {
        $ctx = new MarketPricingContext(
            currentPrice: $currentPrice,
            currentVolatility: 0.25,
            longTermVolatility: 0.25,
            earningsPerShare: 5.0,
            dt: 1.0 / 252.0,
            lambda: 0.0,
            jumpVol: 0.10,
            beta: $beta,
            marketZ: 0.0,
            sectorZ: $sectorZ,
            marketJumpMultiplier: $marketJumpMultiplier,
            marketVol: 0.15,
            macroState: new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.045),
            fcfPerShare: 4.0,
            bookValuePerShare: 40.0,
            currentRoic: 0.15,
            roicTtm: 0.15,
            liveWacc: 0.08,
            revenuePerShare: 50.0,
            liveCostOfEquity: 0.10,
            recentPriceTrend: $recentPriceTrend,
            baselineRoic: 0.15
        );

        return $this->engine->calculateNextPrice($ctx)['price'];
    }

    public function testSectorShockMovesThePriceAndScalesLinearlyWithTheShock(): void
    {
        $up = $this->priceOf(sectorZ: 2.0);
        $flat = $this->priceOf(sectorZ: 0.0);
        $down = $this->priceOf(sectorZ: -2.0);

        // If the sector factor is not wired through, all three prices are identical.
        $this->assertGreaterThan($flat, $up, 'A positive sector shock must lift the price.');
        $this->assertLessThan($flat, $down, 'A negative sector shock must depress the price.');

        // Fair value does not depend on the sector shock, so the reversion weight is identical across the
        // three runs and the sector contribution is exactly linear in the shock.
        $this->assertEqualsWithDelta(
            log($up / $flat),
            log($flat / $down),
            1e-9,
            'The sector shock enters the diffusion linearly, so its effect must be symmetric.'
        );

        // Magnitude guard: at a 20% share of the non-market residual a four-sigma sector swing is a move of
        // roughly two percent on a daily step. A share of zero would collapse this to nothing.
        $this->assertGreaterThan(0.005, log($up / $down));
        $this->assertLessThan(0.05, log($up / $down));
    }

    public function testMarketWideJumpGapsThePriceByItsBetaExposure(): void
    {
        $crash = 0.90;

        $withJump = $this->priceOf(marketJumpMultiplier: $crash, beta: 1.0);
        $withoutJump = $this->priceOf(marketJumpMultiplier: 1.0, beta: 1.0);

        // The jump is applied outside the diffusion, so at unit beta the price gaps by exactly the multiplier.
        $this->assertEqualsWithDelta($crash, $withJump / $withoutJump, 1e-9);
    }

    public function testInverseBetaHedgeGainsOnAMarketWideCrash(): void
    {
        $crash = 0.90;
        $beta = -0.50;

        $withJump = $this->priceOf(marketJumpMultiplier: $crash, beta: $beta);
        $withoutJump = $this->priceOf(marketJumpMultiplier: 1.0, beta: $beta);

        $this->assertGreaterThan(
            1.0,
            $withJump / $withoutJump,
            'An inverse-beta hedge must rise when the district gaps down, not merely fall less.'
        );

        // Exposure is the beta applied to the common log return.
        $this->assertEqualsWithDelta(exp(log($crash) * $beta), $withJump / $withoutJump, 1e-9);
    }

    public function testSystemicJumpIsBoundedByThePerJumpCap(): void
    {
        $crash = 0.70;
        $beta = 3.0;

        $withJump = $this->priceOf(marketJumpMultiplier: $crash, beta: $beta);
        $withoutJump = $this->priceOf(marketJumpMultiplier: 1.0, beta: $beta);

        // Unclamped this would be 0.70^3, a 66% single-tick collapse. A high-beta name gaps hardest but
        // still obeys the same per-jump floor every other jump in the system obeys.
        $this->assertEqualsWithDelta(
            exp(FinancialConstants::MIN_JUMP_LOG_RETURN),
            $withJump / $withoutJump,
            1e-9
        );
    }

    public function testMomentumResistsFundamentalReversionInEitherDirection(): void
    {
        // Deeply undervalued, so the fair value anchor is pulling the price up hard.
        $undervalued = 20.0;

        $noTrend = $this->priceOf(recentPriceTrend: 0.0, currentPrice: $undervalued);
        $rising = $this->priceOf(recentPriceTrend: 0.40, currentPrice: $undervalued);
        $falling = $this->priceOf(recentPriceTrend: -0.40, currentPrice: $undervalued);

        $this->assertLessThan(
            $noTrend,
            $rising,
            'A trending stock must resist the pull to fair value, so it ends nearer its own path.'
        );

        // Only the magnitude of the trend matters. Subtracting a signed trend, as the dead code did, would
        // have made a rising stock revert FASTER than a flat one and a falling stock revert slower.
        $this->assertEqualsWithDelta($rising, $falling, 1e-9, 'Momentum resistance must be direction neutral.');
    }

    /**
     * Momentum must slow fundamental reversion, never abolish it, at any tick rate.
     *
     * Resistance divides the reversion rate. Adding it to the reversion WEIGHT instead looks equivalent on a
     * coarse step and is catastrophic on a fine one: at the district's 3600 ticks a year the weight is already
     * 0.9999, so any constant offset saturates the clamp and cuts the price loose from fair value entirely.
     */
    public function testMomentumSlowsReversionByTheSameFractionAtAnyTickRate(): void
    {
        $undervalued = 20.0;
        $maxTrend = 0.50;

        $retainedFraction = function (float $dt) use ($undervalued, $maxTrend): float {
            $withMomentum = $this->priceOfAtStep($dt, $maxTrend, $undervalued);
            $without = $this->priceOfAtStep($dt, 0.0, $undervalued);

            // How much of the pull toward fair value survives the momentum resistance.
            return log($withMomentum / $undervalued) / log($without / $undervalued);
        };

        $daily = $retainedFraction(1.0 / 252.0);
        $live = $retainedFraction(1.0 / 3600.0);

        // Dividing the rate retains 1 / (1 + trend x resistance) of the reversion, independent of the step.
        $this->assertGreaterThan(0.50, $live, 'Reversion must survive the maximum trend at the live tick rate.');
        $this->assertLessThan(0.90, $live, 'Momentum must still visibly slow reversion.');
        $this->assertEqualsWithDelta($daily, $live, 0.05, 'The momentum effect must not depend on the tick rate.');
    }

    private function priceOfAtStep(float $dt, float $trend, float $currentPrice): float
    {
        $ctx = new MarketPricingContext(
            currentPrice: $currentPrice,
            currentVolatility: 0.25,
            longTermVolatility: 0.25,
            earningsPerShare: 5.0,
            dt: $dt,
            lambda: 0.0,
            beta: 1.0,
            macroState: new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.045),
            fcfPerShare: 4.0,
            bookValuePerShare: 40.0,
            currentRoic: 0.15,
            roicTtm: 0.15,
            liveWacc: 0.08,
            revenuePerShare: 50.0,
            liveCostOfEquity: 0.10,
            recentPriceTrend: $trend,
            baselineRoic: 0.15
        );

        return $this->engine->calculateNextPrice($ctx)['price'];
    }

    /**
     * The market-wide jump supplies part of a stock's return variance, so the diffusion must give up the same
     * amount. Without the budget, connecting the jump stacked a second source of risk on an already
     * calibrated baseline and every name in the district became roughly four points more volatile.
     */
    public function testMarketWideJumpVarianceIsReclaimedFromTheDiffusion(): void
    {
        $baselineVol = 0.26;

        $settledVolatility = function (float $beta) use ($baselineVol): float {
            $ctx = new MarketPricingContext(
                currentPrice: 60.0,
                currentVolatility: $baselineVol,
                longTermVolatility: $baselineVol,
                earningsPerShare: 5.0,
                dt: 0.25,
                lambda: 0.0, // No idiosyncratic jumps, so only the systemic budget moves the anchor.
                beta: $beta,
                macroState: new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.045),
                fcfPerShare: 4.0,
                bookValuePerShare: 40.0,
                currentRoic: 0.15,
                roicTtm: 0.15,
                liveWacc: 0.08,
                revenuePerShare: 50.0,
                liveCostOfEquity: 0.10,
                baselineRoic: 0.15
            );

            return $this->engine->calculateNextPrice($ctx)['next_volatility'];
        };

        $highBeta = $settledVolatility(2.0);
        $lowBeta = $settledVolatility(0.2);

        // A name that takes more of the district's jump gives up more of its own diffusion.
        $this->assertLessThan($lowBeta, $highBeta);

        // And the diffusion anchor sits below the configured baseline, because part of that budget is now
        // delivered as jumps rather than as continuous diffusion.
        $this->assertLessThan($baselineVol, $highBeta);
    }
}
