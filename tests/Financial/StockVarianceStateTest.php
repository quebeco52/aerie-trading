<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Service\Market\MarketEngine;
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

    private function context(float $volatility, float $marketVol, ?float $priorMarketVol, float $lambda, float $volOfVol, float $dt): MarketPricingContext
    {
        return new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: $volatility,
            longTermVolatility: self::LONG_TERM_VOLATILITY,
            earningsPerShare: 5.0,
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
        );
    }

    /** Where the total variance should sit at a given market vol: the idiosyncratic target plus the loading. */
    private function targetVariance(float $marketVol): float
    {
        return MarketEngine::longTermIdiosyncraticVariance(self::LONG_TERM_VOLATILITY, self::BETA)
            + ((self::BETA * $marketVol) ** 2);
    }

    /**
     * A market-vol spike reaches the name through its beta and nowhere else: with no vol-of-vol and no
     * jumps, the idiosyncratic state is already at its target and must stay there through the spike and
     * the decay. Stripping at this tick's market vol instead of last tick's printed 45% for a 40% spike.
     */
    public function testAMarketVolSpikeDoesNotLeakIntoTheIdiosyncraticState(): void
    {
        mt_srand(20261007);
        $engine = new MarketEngine(new MathUtility());

        $marketVol = self::CALM_MARKET_VOL;
        $volatility = sqrt($this->targetVariance($marketVol));
        $worst = 0.0;

        for ($tick = 0; $tick < 252; $tick++) {
            $prior = $marketVol;
            $marketVol = $tick === 20
                ? 0.40
                : self::CALM_MARKET_VOL + (($marketVol - self::CALM_MARKET_VOL) * exp(-2.0 * self::DAILY));

            $volatility = $engine->calculateNextPrice(
                $this->context($volatility, $marketVol, $prior, 0.0, 1.0e-6, self::DAILY)
            )['next_volatility'];

            $worst = max($worst, abs($volatility - sqrt($this->targetVariance($marketVol))));
        }

        self::assertLessThan(1.0e-4, $worst, 'Total vol drifted off sqrt(idiosyncratic target + (beta x market vol)^2).');
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
