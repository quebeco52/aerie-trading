<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a stock's price path has to be true of, measured by running the real engine for simulated decades.
 *
 * Three properties, each of which was silently false before:
 *
 *  - A name realizes the BETA it is configured with. The market loading used to be derived from an implied
 *    correlation clamped below one, so any name whose volatility fell short of what its beta demanded was
 *    quietly made less sensitive to the market than the CAPM drift was paying it for.
 *  - A name realizes the VOLATILITY it is configured with. The jump processes stacked variance on top of a
 *    calibrated diffusion without the diffusion giving any of it back.
 *  - A name's expected return does not depend on how jumpy it is. Both Kou processes are skewed down, so an
 *    uncompensated drift lost lambda * E[e^J - 1] a year and the price settled below its own fair value by
 *    an amount that widened with beta.
 *
 * The bands are wide on purpose: these pin the accounting, not a calibration. A legitimate retune should not
 * have to fight them, but dropping a variance source or a compensator again will.
 */
final class PriceFormationInvariantTest extends TestCase
{
    private const TICKS_PER_YEAR = 1200;
    private const YEARS = 60;
    private const BURN_IN_YEARS = 2;

    private MathUtility $math;
    private MarketEngine $engine;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
        $this->engine = new MarketEngine($this->math);
    }

    /**
     * @return array<string, array{beta: float, volatility: float, lambda: float, jumpVol: float}>
     */
    public static function seededProfiles(): array
    {
        // Shapes taken from the seeded district: a defensive low-beta name, a broad-market name, a jumpy
        // cyclical, and an inverse hedge whose negative beta the systemic jump has to reach correctly.
        return [
            'defensive'   => ['beta' => 0.30, 'volatility' => 0.12, 'lambda' => 0.20, 'jumpVol' => 0.08],
            'market'      => ['beta' => 0.50, 'volatility' => 0.16, 'lambda' => 0.30, 'jumpVol' => 0.09],
            'cyclical'    => ['beta' => 0.85, 'volatility' => 0.18, 'lambda' => 0.45, 'jumpVol' => 0.09],
            'inverse'     => ['beta' => -0.60, 'volatility' => 0.24, 'lambda' => 1.25, 'jumpVol' => 0.13],
        ];
    }

    /**
     * Runs one name against a market factor for the full horizon.
     *
     * @return array{returns: list<float>, market: list<float>, ratios: list<float>}
     */
    private function simulate(float $beta, float $volatility, float $lambda, float $jumpVol, float $marketVol): array
    {
        mt_srand(20260913);

        $macro = new MacroStateDTO(
            outputGap: 0.0,
            inflation: 0.02,
            policyRate: 0.04,
            marketVolatility: $marketVol,
            equityRiskPremium: 0.045,
        );

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $price = 71.6;
        $currentVol = $volatility;

        $returns = [];
        $market = [];
        $ratios = [];

        $burnIn = self::TICKS_PER_YEAR * self::BURN_IN_YEARS;
        $total = self::TICKS_PER_YEAR * self::YEARS;

        for ($tick = 0; $tick < $total; $tick++) {
            $marketZ = $this->math->generateStandardNormal();
            $sectorZ = $this->math->generateStandardNormal();

            $systemic = $this->math->calculateSVJJJumps(
                lambda: MacroEngine::SYSTEMIC_JUMP_INTENSITY,
                pUp: MacroEngine::SYSTEMIC_JUMP_PROBABILITY_UP,
                etaUp: MacroEngine::SYSTEMIC_JUMP_ETA_UP,
                etaDown: MacroEngine::SYSTEMIC_JUMP_ETA_DOWN,
                muV: MacroEngine::SVJJ_MU_V,
                dt: $dt,
            );
            $jumpMultiplier = $systemic['price_multiplier'];

            $result = $this->engine->calculateNextPrice(new MarketPricingContext(
                currentPrice: $price,
                currentVolatility: $currentVol,
                longTermVolatility: $volatility,
                earningsPerShare: 5.0,
                dt: $dt,
                lambda: $lambda,
                jumpVol: $jumpVol,
                beta: $beta,
                marketZ: $marketZ,
                sectorZ: $sectorZ,
                marketJumpMultiplier: $jumpMultiplier,
                marketVol: $marketVol,
                macroState: $macro,
                fcfPerShare: 4.0,
                bookValuePerShare: 40.0,
                currentRoic: 0.125,
                roicTtm: 0.125,
                liveWacc: 0.085,
                baselineIndustryPE: 20.0,
                revenuePerShare: 50.0,
                liveCostOfEquity: 0.10,
                netDebtPerShare: 5.0,
                secularGrowth: 0.02,
                baselineRoic: 0.125,
                baselineMargin: 0.10,
                investedCapitalPerShare: 45.0,
            ));

            $next = $result['price'];

            if ($tick >= $burnIn) {
                $returns[] = log($next / $price);
                $market[] = ($marketVol * $marketZ * sqrt($dt)) + log($jumpMultiplier);
                $ratios[] = $next / max(0.01, $result['perceived_fair_value']);
            }

            $price = $next;
            $currentVol = $result['next_volatility'];
        }

        return ['returns' => $returns, 'market' => $market, 'ratios' => $ratios];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function covariance(array $a, array $b): float
    {
        $n = count($a);
        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += ($a[$i] - $meanA) * ($b[$i] - $meanB);
        }

        return $sum / $n;
    }

    /**
     * @param list<float> $values
     */
    private static function variance(array $values): float
    {
        return self::covariance($values, $values);
    }

    #[DataProvider('seededProfiles')]
    public function testRealisedBetaMatchesTheConfiguredBeta(float $beta, float $volatility, float $lambda, float $jumpVol): void
    {
        $path = $this->simulate($beta, $volatility, $lambda, $jumpVol, MacroEngine::MACRO_VOL_BASE_ANCHOR);

        $realised = self::covariance($path['returns'], $path['market']) / self::variance($path['market']);

        $this->assertEqualsWithDelta(
            $beta,
            $realised,
            0.05 * max(1.0, abs($beta)),
            sprintf('Configured beta %.2f realised as %.3f.', $beta, $realised)
        );
    }

    #[DataProvider('seededProfiles')]
    public function testRealisedBetaSurvivesACrisisLevelMarketVolatility(float $beta, float $volatility, float $lambda, float $jumpVol): void
    {
        // The clamp this replaced bound hardest exactly here: at a tripled market volatility every name's
        // loading was truncated at once and the cross-section of beta collapsed.
        $path = $this->simulate($beta, $volatility, $lambda, $jumpVol, 0.45);

        $realised = self::covariance($path['returns'], $path['market']) / self::variance($path['market']);

        $this->assertEqualsWithDelta(
            $beta,
            $realised,
            0.10 * max(1.0, abs($beta)),
            sprintf('In a crisis, configured beta %.2f realised as %.3f.', $beta, $realised)
        );
    }

    #[DataProvider('seededProfiles')]
    public function testRealisedVolatilityMatchesTheConfiguredVolatility(float $beta, float $volatility, float $lambda, float $jumpVol): void
    {
        $path = $this->simulate($beta, $volatility, $lambda, $jumpVol, MacroEngine::MACRO_VOL_BASE_ANCHOR);

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $realised = sqrt(self::variance($path['returns']) / $dt);

        // Every variance source a name is exposed to is budgeted against the configured figure, so the
        // realised total has to land on it. Left unbudgeted, the name's own jump alone pushed the median
        // seeded name 14% over and the worst of them 58% over.
        $this->assertEqualsWithDelta(
            $volatility,
            $realised,
            $volatility * 0.15,
            sprintf('Configured volatility %.2f realised as %.4f.', $volatility, $realised)
        );
    }

    public function testExpectedReturnDoesNotDependOnHowJumpyANameIs(): void
    {
        // Same beta, same volatility, wildly different jump processes. An uncompensated jump is a return
        // tax, so the jumpy name settled at a permanently lower price against the same fair value.
        $calm = $this->simulate(0.85, 0.18, 0.05, 0.05, MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $jumpy = $this->simulate(0.85, 0.18, 1.50, 0.14, MacroEngine::MACRO_VOL_BASE_ANCHOR);

        $calmRatio = array_sum($calm['ratios']) / count($calm['ratios']);
        $jumpyRatio = array_sum($jumpy['ratios']) / count($jumpy['ratios']);

        $this->assertEqualsWithDelta(
            $calmRatio,
            $jumpyRatio,
            0.06,
            sprintf(
                'Jump intensity is moving the level a name trades at against fair value: calm %.4f vs jumpy %.4f.',
                $calmRatio,
                $jumpyRatio
            )
        );
    }

    public function testPriceDoesNotSettleSystematicallyBelowFairValueAsBetaRises(): void
    {
        // The uncompensated systemic jump reached the stock through its beta, so the discount to fair value
        // grew monotonically with it: 0.983 at beta 0.3, 0.940 at 1.3, 0.915 at 2.8.
        $ratios = [];
        foreach ([0.30, 1.30, 2.40] as $beta) {
            $path = $this->simulate($beta, 0.20 + (0.06 * $beta), 0.60, 0.10, MacroEngine::MACRO_VOL_BASE_ANCHOR);
            $ratios[] = array_sum($path['ratios']) / count($path['ratios']);
        }

        $spread = max($ratios) - min($ratios);

        $this->assertLessThan(
            0.08,
            $spread,
            sprintf('Price to fair value still trends with beta: %s.', implode(', ', array_map(
                static fn (float $r): string => sprintf('%.4f', $r),
                $ratios
            )))
        );
    }
}
