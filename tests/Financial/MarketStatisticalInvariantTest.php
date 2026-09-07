<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Emergent market statistics invariant tests.
 *
 * The existing Financial suite pins the macro engine's internal relationships. These tests pin the
 * properties a player actually experiences, which no single formula owns: how volatile the market is over a
 * year, how fat its tails are, and how stocks move relative to one another. They exist to catch the failure
 * mode where each component is individually defensible but the market they compose is not.
 *
 * The PRNG is seeded, so these are deterministic rather than statistical flakes; bounds are still written
 * wide enough that a legitimate recalibration does not have to fight them.
 */
class MarketStatisticalInvariantTest extends TestCase
{
    private const TICKS_PER_YEAR = 1200;
    private const YEARS = 25;
    private const MARKET_VOL = 0.15;
    private const STOCK_VOL = 0.25;

    private MathUtility $math;
    private float $dt;
    private float $regimePhi;
    private float $regimeScale;
    private float $regimeWeight;
    private float $tailWeight;

    protected function setUp(): void
    {
        mt_srand(20260907);

        $this->math = new MathUtility();
        $this->dt = 1.0 / self::TICKS_PER_YEAR;
        $this->regimePhi = exp(-$this->dt / MacroEngine::MARKET_FACTOR_DECAY_TAU_YEARS);
        $this->regimeScale = $this->math->calculatePersistenceVarianceScale($this->regimePhi);
        $this->regimeWeight = sqrt(MacroEngine::MARKET_FACTOR_REGIME_VARIANCE_SHARE);
        $this->tailWeight = sqrt(1.0 - MacroEngine::MARKET_FACTOR_REGIME_VARIANCE_SHARE);
    }

    /** Advances the two-leg market factor exactly as MacroEngine::updateMacroState() does. */
    private function nextMarketShock(float &$latent): float
    {
        $latent = $this->math->generatePersistentZ($latent, $this->regimePhi);

        return ($this->regimeWeight * $latent * $this->regimeScale)
            + ($this->tailWeight * $this->math->generateStudentsT(MacroEngine::MARKET_FACTOR_TAIL_DF));
    }

    private function nextMarketJump(): float
    {
        return $this->math->calculateSVJJJumps(
            MacroEngine::SYSTEMIC_JUMP_INTENSITY,
            MacroEngine::SYSTEMIC_JUMP_PROBABILITY_UP,
            MacroEngine::SYSTEMIC_JUMP_ETA_UP,
            MacroEngine::SYSTEMIC_JUMP_ETA_DOWN,
            MacroEngine::SYSTEMIC_JUMP_VARIANCE_MEAN,
            $this->dt
        )['price_multiplier'];
    }

    /** @param list<float> $values */
    private static function stdDev(array $values): float
    {
        $n = count($values);
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }

    /** @param list<float> $values */
    private static function kurtosis(array $values): float
    {
        $n = count($values);
        $mean = array_sum($values) / $n;
        $m2 = 0.0;
        $m4 = 0.0;
        foreach ($values as $value) {
            $d = $value - $mean;
            $m2 += $d * $d;
            $m4 += $d ** 4;
        }

        return ($m4 / $n) / (($m2 / $n) ** 2);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function correlation(array $a, array $b): float
    {
        $n = count($a);
        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;
        $cov = 0.0;
        $varA = 0.0;
        $varB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $meanA;
            $db = $b[$i] - $meanB;
            $cov += $da * $db;
            $varA += $da * $da;
            $varB += $db * $db;
        }

        return $cov / sqrt($varA * $varB);
    }

    /**
     * @param list<float> $returns
     * @return list<float>
     */
    private static function aggregate(array $returns, int $window): array
    {
        $out = [];
        for ($i = 0; $i + $window <= count($returns); $i += $window) {
            $out[] = array_sum(array_slice($returns, $i, $window));
        }

        return $out;
    }

    /**
     * Runs a miniature market: four names split across two sectors, sharing one market factor.
     *
     * @return array{index: list<float>, stocks: array<string, list<float>>}
     */
    private function simulateMarket(bool $withSystemicJump): array
    {
        $prices = ['A1' => 100.0, 'A2' => 100.0, 'B1' => 100.0, 'B2' => 100.0];
        $stockReturns = ['A1' => [], 'A2' => [], 'B1' => [], 'B2' => []];
        $indexReturns = [];
        $latent = 0.0;

        $totalTicks = self::TICKS_PER_YEAR * self::YEARS;
        for ($tick = 0; $tick < $totalTicks; $tick++) {
            $marketShock = $this->nextMarketShock($latent);
            $sectorShocks = [
                'A' => $this->math->generateStandardNormal(),
                'B' => $this->math->generateStandardNormal(),
            ];
            $jump = $withSystemicJump ? $this->nextMarketJump() : 1.0;

            $indexBefore = array_sum($prices);
            foreach ($prices as $ticker => $price) {
                $diffused = $this->math->calculateCorrelatedGBM(
                    currentPrice: $price,
                    currentVolatility: self::STOCK_VOL,
                    drift: 0.0,
                    gravityDrift: 0.0,
                    dt: $this->dt,
                    beta: 1.0,
                    marketVol: self::MARKET_VOL,
                    marketZ: $marketShock,
                    w1: $this->math->generateStandardNormal(),
                    sectorZ: $sectorShocks[$ticker[0]],
                    sectorVarianceShare: MacroEngine::SECTOR_FACTOR_VARIANCE_SHARE
                );

                $next = $diffused * $jump;
                $stockReturns[$ticker][] = log($next / $price);
                $prices[$ticker] = $next;
            }
            $indexReturns[] = log(array_sum($prices) / $indexBefore);
        }

        return ['index' => $indexReturns, 'stocks' => $stockReturns];
    }

    public function testPersistenceScalePreservesIntegratedVarianceOfTheMarketFactor(): void
    {
        // Autocorrelating a shock sequence inflates the variance of its sum by (1 + phi) / (1 - phi).
        // Without the correction, market volatility silently multiplies; this pins the correction itself.
        $inflation = (1.0 + $this->regimePhi) / (1.0 - $this->regimePhi);

        $this->assertGreaterThan(
            10.0,
            $inflation,
            'At market-factor persistence the uncorrected variance inflation must be large, or this guard is pointless.'
        );

        $this->assertEqualsWithDelta(
            1.0,
            ($this->regimeScale ** 2) * $inflation,
            1e-9,
            'The persistence scale must exactly cancel the variance inflation it corrects for.'
        );
    }

    public function testAnnualisedMarketVolatilityMatchesItsNominalCalibration(): void
    {
        $market = $this->simulateMarket(withSystemicJump: true);
        $annual = self::aggregate($market['index'], self::TICKS_PER_YEAR);
        $realised = self::stdDev($annual);

        // The index is beta-1 against the market factor, so it should reproduce the market's own volatility.
        $this->assertGreaterThan(
            self::MARKET_VOL * 0.60,
            $realised,
            sprintf('Realised index volatility collapsed to %.4f against a %.4f calibration.', $realised, self::MARKET_VOL)
        );
        $this->assertLessThan(
            self::MARKET_VOL * 1.60,
            $realised,
            sprintf('Realised index volatility exploded to %.4f against a %.4f calibration.', $realised, self::MARKET_VOL)
        );
    }

    public function testSameSectorNamesCorrelateMoreStronglyThanCrossSectorNames(): void
    {
        $market = $this->simulateMarket(withSystemicJump: false);
        $daily = [];
        foreach ($market['stocks'] as $ticker => $returns) {
            $daily[$ticker] = self::aggregate($returns, 5);
        }

        $sameSector = 0.5 * (
            self::correlation($daily['A1'], $daily['A2'])
            + self::correlation($daily['B1'], $daily['B2'])
        );
        $crossSector = 0.5 * (
            self::correlation($daily['A1'], $daily['B1'])
            + self::correlation($daily['A2'], $daily['B2'])
        );

        $this->assertGreaterThan(
            0.0,
            $crossSector,
            'Every name shares the market factor, so cross-sector correlation must still be positive.'
        );
        $this->assertGreaterThan(
            $crossSector + 0.05,
            $sameSector,
            sprintf(
                'Sector factor is not separating names: same-sector %.3f vs cross-sector %.3f.',
                $sameSector,
                $crossSector
            )
        );
    }

    public function testSystemicJumpGivesTheIndexTailsADiffusionCannotProduce(): void
    {
        $withoutJump = self::aggregate($this->simulateMarket(withSystemicJump: false)['index'], 5);

        mt_srand(20260907);
        $withJump = self::aggregate($this->simulateMarket(withSystemicJump: true)['index'], 5);

        $diffusionKurtosis = self::kurtosis($withoutJump);
        $jumpKurtosis = self::kurtosis($withJump);

        // A pure diffusion aggregates back toward the Gaussian value of 3 no matter how fat the tick-level
        // innovation is; only a jump survives aggregation.
        $this->assertLessThan(
            6.0,
            $diffusionKurtosis,
            'Diffusion-only index returns should stay near-Gaussian once aggregated.'
        );
        $this->assertGreaterThan(
            $diffusionKurtosis * 1.5,
            $jumpKurtosis,
            sprintf(
                'Systemic jump must materially fatten index tails: %.2f with jumps vs %.2f without.',
                $jumpKurtosis,
                $diffusionKurtosis
            )
        );

        sort($withJump);
        $this->assertLessThan(
            -0.04,
            $withJump[0],
            'A quarter century of trading must contain at least one sharp district-wide down move.'
        );
    }

    public function testNoPathProducesNonFiniteOrNonPositivePrices(): void
    {
        $market = $this->simulateMarket(withSystemicJump: true);

        foreach ($market['stocks'] as $ticker => $returns) {
            foreach ($returns as $index => $return) {
                $this->assertTrue(
                    is_finite($return),
                    sprintf('Non-finite return for %s at tick %d.', $ticker, $index)
                );
            }
        }

        foreach ($market['index'] as $index => $return) {
            $this->assertTrue(is_finite($return), sprintf('Non-finite index return at tick %d.', $index));
        }
    }
}
