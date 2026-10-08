<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The single-name variance state: stored as a TOTAL variance, stepped as an idiosyncratic one.
 *
 * Two invariants. The round trip from total to idiosyncratic and back must not leak a move in market vol
 * into the idiosyncratic state, and the variance jumps must not lift the stationary variance above the
 * level the name was calibrated to. Either failure makes every name realize more volatility than it was
 * configured with.
 */
final class StockVarianceStateTest extends TestCase
{
    private const LONG_TERM_VOLATILITY = 0.30;
    private const BETA = 1.2;
    private const CALM_MARKET_VOL = 0.15;
    private const DAILY = 1.0 / 252.0;

    private function context(float $volatility, float $marketVol, ?float $priorMarketVol, float $lambda, float $volOfVol, float $dt, float $announcementVariance = 0.0): MarketPricingContext
    {
        return new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: $volatility,
            longTermVolatility: self::LONG_TERM_VOLATILITY,
            dt: $dt,
            lambda: $lambda,
            jumpVol: 0.05,
            beta: self::BETA,
            marketVol: $marketVol,
            volOfVol: $volOfVol,
            macroState: new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.045, outputGap: 0.0),
            bookValuePerShare: 40.0,
            currentRoic: 0.12,
            roicTtm: 0.12,
            baselineIndustryPE: 15.0,
            revenuePerShare: 50.0,
            priorMarketVol: $priorMarketVol,
            announcementVariance: $announcementVariance,
        );
    }

    /** Steps the variance state two years from the target with no vol-of-vol and no jumps, and returns where it settles. */
    private function settledVariance(float $announcementVariance): float
    {
        mt_srand(20261008);
        $engine = new MarketEngine(new MathUtility());
        $volatility = sqrt($this->targetVariance(self::CALM_MARKET_VOL));

        for ($tick = 0; $tick < 504; $tick++) {
            $volatility = $engine->calculateNextPrice(
                $this->context($volatility, self::CALM_MARKET_VOL, self::CALM_MARKET_VOL, 0.0, 1.0e-6, self::DAILY, $announcementVariance)
            )['next_volatility'];
        }

        return $volatility * $volatility;
    }

    /**
     * A name's announcements are scheduled jumps, so the diffusion gives back the variance they measurably
     * supplied, and no more than the minority bound its own jumps are held to.
     */
    public function testTheDiffusionGivesBackTheMeasuredAnnouncementVariance(): void
    {
        $idiosyncraticTarget = MarketEngine::longTermIdiosyncraticVariance(self::LONG_TERM_VOLATILITY, self::BETA);

        self::assertEqualsWithDelta($this->targetVariance(self::CALM_MARKET_VOL), $this->settledVariance(0.0), 1.0e-5);
        self::assertEqualsWithDelta($this->targetVariance(self::CALM_MARKET_VOL) - 0.004, $this->settledVariance(0.004), 1.0e-5);
        self::assertEqualsWithDelta(
            $this->targetVariance(self::CALM_MARKET_VOL) - ($idiosyncraticTarget * 0.25),
            $this->settledVariance(1.0),
            1.0e-5,
            'An outsized announcement record is held to a quarter of the idiosyncratic budget.'
        );
    }

    /** Where the total variance should sit at a given market vol: the idiosyncratic target plus the loading. */
    private function targetVariance(float $marketVol): float
    {
        return MarketEngine::longTermIdiosyncraticVariance(self::LONG_TERM_VOLATILITY, self::BETA)
            + ((self::BETA * $marketVol) ** 2);
    }

    /**
     * A market-vol spike reaches the name through its beta at once, and through the common idiosyncratic factor
     * only as fast as the residual variance reverts to its moved target: with no vol-of-vol and no jumps the
     * residual state follows theta_t + (v - theta_t) e^(-kappa dt) exactly. Stripping the systematic part at this
     * tick's market vol instead of last tick's leaked the spike into the residual state (a 40% spike printed 45%).
     */
    public function testAMarketVolSpikeReachesTheResidualOnlyThroughTheCommonFactor(): void
    {
        mt_srand(20261007);
        $engine = new MarketEngine(new MathUtility());
        $longRunResidual = MarketEngine::longTermIdiosyncraticVariance(self::LONG_TERM_VOLATILITY, self::BETA);
        $decay = exp(-MarketEngine::varianceReversionSpeed(0.0) * self::DAILY);

        $marketVol = self::CALM_MARKET_VOL;
        $residual = $longRunResidual * MarketEngine::commonIdiosyncraticVarianceScale($marketVol);
        $volatility = sqrt($residual + ((self::BETA * $marketVol) ** 2));
        $worst = 0.0;

        for ($tick = 0; $tick < 252; $tick++) {
            $prior = $marketVol;
            $marketVol = $tick === 20
                ? 0.40
                : self::CALM_MARKET_VOL + (($marketVol - self::CALM_MARKET_VOL) * exp(-2.0 * self::DAILY));

            $volatility = $engine->calculateNextPrice(
                $this->context($volatility, $marketVol, $prior, 0.0, 1.0e-6, self::DAILY)
            )['next_volatility'];

            $target = $longRunResidual * MarketEngine::commonIdiosyncraticVarianceScale($marketVol);
            $residual = $target + (($residual - $target) * $decay);
            $worst = max($worst, abs($volatility - sqrt($residual + ((self::BETA * $marketVol) ** 2))));
        }

        // QE noise at a 1e-6 vol-of-vol is a few 1e-4 at these levels; the leak this guards was five points.
        self::assertLessThan(1.0e-3, $worst, 'Total vol drifted off the residual path plus (beta x market vol)^2.');
    }

    /**
     * The common idiosyncratic factor (Herskovic, Kelly, Lustig & Van Nieuwerburgh 2016): residual variance scales
     * with market variance over its settle level at the firm loading, and is untouched where the market settles.
     */
    public function testResidualVarianceLoadsOnMarketVarianceAroundItsSettleLevel(): void
    {
        $anchor = \App\Service\Macro\MacroEngine::MACRO_VOL_BASE_ANCHOR;
        $loading = MarketEngine::IDIOSYNCRATIC_COMMON_FACTOR_LOADING;

        self::assertEqualsWithDelta(1.0, MarketEngine::commonIdiosyncraticVarianceScale($anchor), 1e-12);
        self::assertEqualsWithDelta(1.0 + ($loading * 3.0), MarketEngine::commonIdiosyncraticVarianceScale(2.0 * $anchor), 1e-12);
        self::assertGreaterThanOrEqual(0.0, MarketEngine::commonIdiosyncraticVarianceScale(0.0));
        // Read against a measured trend, a market at its own trend leaves the residual alone wherever that trend sits.
        self::assertEqualsWithDelta(1.0, MarketEngine::commonIdiosyncraticVarianceScale(0.18, 0.18 * 0.18), 1e-12);
        self::assertEqualsWithDelta(1.0 + ($loading * 3.0), MarketEngine::commonIdiosyncraticVarianceScale(0.36, 0.18 * 0.18), 1e-12);

        // Held at a turbulent market vol, the residual settles on the scaled target.
        mt_srand(20261008);
        $engine = new MarketEngine(new MathUtility());
        $turbulent = 2.0 * $anchor;
        $volatility = sqrt($this->targetVariance($turbulent));
        for ($tick = 0; $tick < 504; $tick++) {
            $volatility = $engine->calculateNextPrice($this->context($volatility, $turbulent, $turbulent, 0.0, 1.0e-6, self::DAILY))['next_volatility'];
        }
        $residualTarget = MarketEngine::longTermIdiosyncraticVariance(self::LONG_TERM_VOLATILITY, self::BETA) * MarketEngine::commonIdiosyncraticVarianceScale($turbulent);
        self::assertEqualsWithDelta($residualTarget + ((self::BETA * $turbulent) ** 2), $volatility * $volatility, 1e-3 * $residualTarget);

        // A market that has been this turbulent for a whole cycle is at its trend: the residual is back at its own level.
        for ($tick = 0; $tick < 504; $tick++) {
            $context = $this->context($volatility, $turbulent, $turbulent, 0.0, 1.0e-6, self::DAILY);
            $context->marketVarianceTrend = $turbulent * $turbulent;
            $volatility = $engine->calculateNextPrice($context)['next_volatility'];
        }
        self::assertEqualsWithDelta($this->targetVariance($turbulent), $volatility * $volatility, 1e-3 * $residualTarget);
    }

    /**
     * The variance jumps are compensated: over a long run the mean variance is the calibrated one, at a
     * typical and at the board's highest jump intensity. Uncompensated it sat 4% and 8% high.
     *
     * The jump terms cancel out of the target: the budget takes them out of the diffusion and the round
     * trip adds them back, so the total settles on the idiosyncratic target plus the systematic loading.
     */
    public function testVarianceJumpsLeaveTheStationaryVarianceAtItsTarget(): void
    {
        foreach ([0.6, 1.5] as $lambda) {
            mt_srand(20261007);
            $engine = new MarketEngine(new MathUtility());

            $volatility = sqrt($this->targetVariance(self::CALM_MARKET_VOL));
            $ticks = 252 * 200;
            $batches = 40;
            $perBatch = intdiv($ticks, $batches);
            $batchMeans = [];
            $sum = 0.0;

            for ($tick = 0; $tick < $ticks; $tick++) {
                $volatility = $engine->calculateNextPrice(
                    $this->context($volatility, self::CALM_MARKET_VOL, self::CALM_MARKET_VOL, $lambda, 1.0e-6, self::DAILY)
                )['next_volatility'];
                $sum += $volatility * $volatility;

                if (($tick + 1) % $perBatch === 0) {
                    $batchMeans[] = $sum / $perBatch;
                    $sum = 0.0;
                }
            }

            $mean = array_sum($batchMeans) / count($batchMeans);
            $spread = array_sum(array_map(static fn (float $m): float => ($m - $mean) ** 2, $batchMeans)) / (count($batchMeans) - 1);
            $standardError = sqrt($spread / count($batchMeans));

            self::assertEqualsWithDelta(
                $this->targetVariance(self::CALM_MARKET_VOL),
                $mean,
                4.0 * $standardError,
                sprintf('lambda %.1f: stationary variance off target.', $lambda)
            );
        }
    }

    /** The load stays well inside one across the board's intensities, so the target can never be struck at or below zero. */
    public function testTheVarianceJumpLoadIsBoundedAcrossTheBoard(): void
    {
        foreach ([0.1, 0.6, 1.5, 3.0] as $lambda) {
            $kappa = MarketEngine::varianceReversionSpeed($lambda);
            foreach ([self::DAILY, 1.0 / 14400.0] as $dt) {
                self::assertLessThan(0.25, MarketEngine::varianceJumpLoad($lambda, $kappa, $dt));
            }
        }

        self::assertSame(0.0, MarketEngine::varianceJumpLoad(0.0, 6.0, self::DAILY));
    }

    /** At a fine tick the discrete load is the continuous-time one, lambda E[J] / kappa per unit variance. */
    public function testTheVarianceJumpLoadConvergesToContinuousTime(): void
    {
        $lambda = 1.0;
        $kappa = MarketEngine::varianceReversionSpeed($lambda);
        $perUnitJump = MathUtility::meanVarianceJump(MarketEngine::jumpProbabilityUp(), 0.50);

        self::assertEqualsWithDelta(
            $lambda * $perUnitJump / $kappa,
            MarketEngine::varianceJumpLoad($lambda, $kappa, 1.0e-7),
            1.0e-6
        );
    }
}
