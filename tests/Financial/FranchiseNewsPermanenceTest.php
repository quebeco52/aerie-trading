<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * A name's own news is permanent in its price, and its noise is not. Over many independent paths of the real price
 * step, next year's return is regressed on the two parts of this year's: the franchise news fair value moved with, and
 * the rest. The pull gives the noise back, so its slope is clearly negative; the news stays, so its slope is near zero
 * (a little below it, as the franchise fades and nothing here books the profit it earns meanwhile). The news is also
 * the margin the firm's reports go on to book: a year's news and the franchise power it leaves move together.
 */
final class FranchiseNewsPermanenceTest extends TestCase
{
    private const PATHS = 1200;
    private const TICKS_PER_YEAR = 52;
    /** The fixture's cost of equity: no dividend, so a fairly priced name compounds its fundamentals at it. */
    private const COST_OF_EQUITY = 0.10;

    /** @return array{news: list<float>, noise: list<float>, next: list<float>, power: list<float>} */
    private function simulate(): array
    {
        mt_srand(20261027);
        $engine = new MarketEngine(new MathUtility());
        $macro = new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.045, marketVolatility: 0.0);
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $context = static fn(float $price, float $volatility, float $power, int $tick): MarketPricingContext => new MarketPricingContext(
            currentPrice: $price,
            currentVolatility: $volatility,
            longTermVolatility: 0.30,
            dt: $dt,
            lambda: 0.0,
            beta: 0.0,
            marketVol: 0.0,
            macroState: $macro,
            bookValuePerShare: 40.0 * exp(self::COST_OF_EQUITY * $tick * $dt),
            currentRoic: 0.15,
            trailingReturn: 0.15,
            revenuePerShare: 50.0 * exp(self::COST_OF_EQUITY * $tick * $dt),
            liveCostOfEquity: self::COST_OF_EQUITY,
            baselineRoic: 0.15,
            baselineMargin: 0.20,
            investedCapitalPerShare: 45.0 * exp(self::COST_OF_EQUITY * $tick * $dt),
            franchisePower: $power,
        );

        $series = ['news' => [], 'noise' => [], 'next' => [], 'power' => []];
        for ($path = 0; $path < self::PATHS; $path++) {
            $price = $engine->strikeFairValue($context(1.0, 0.30, 0.0, 0))['perceived_fair_value'];
            $volatility = 0.30;
            $power = 0.0;
            $logPrices = [log($price)];
            $news = 0.0;
            for ($tick = 0; $tick < 2 * self::TICKS_PER_YEAR; $tick++) {
                $ctx = $context($price, $volatility, $power, $tick);
                $before = $engine->strikeFairValue($ctx)['perceived_fair_value'];
                $step = $engine->calculateNextPrice($ctx);
                if ($tick < self::TICKS_PER_YEAR) {
                    $news += log($step['perceived_fair_value'] / $before);
                }
                $price = $step['price'];
                $volatility = $step['next_volatility'];
                $power = $step['franchise_power'];
                if ($tick === self::TICKS_PER_YEAR - 1) {
                    $series['power'][] = $power;
                }
                if (($tick + 1) % self::TICKS_PER_YEAR === 0) {
                    $logPrices[] = log($price);
                }
            }
            $series['news'][] = $news;
            $series['noise'][] = $logPrices[1] - $logPrices[0] - $news;
            $series['next'][] = $logPrices[2] - $logPrices[1];
        }

        return $series;
    }

    /**
     * Slopes of y on x1 and x2 together, by ordinary least squares on demeaned data.
     *
     * @param list<float> $y
     * @param list<float> $x1
     * @param list<float> $x2
     * @return array{0: float, 1: float}
     */
    private static function slopes(array $y, array $x1, array $x2): array
    {
        $demean = static function (array $values): array {
            $mean = array_sum($values) / count($values);

            return array_map(static fn(float $v): float => $v - $mean, $values);
        };
        [$y, $x1, $x2] = [$demean($y), $demean($x1), $demean($x2)];
        $dot = static fn(array $a, array $b): float => array_sum(array_map(static fn(float $p, float $q): float => $p * $q, $a, $b));
        $s11 = $dot($x1, $x1);
        $s22 = $dot($x2, $x2);
        $s12 = $dot($x1, $x2);
        $det = ($s11 * $s22) - ($s12 * $s12);

        return [(($s22 * $dot($x1, $y)) - ($s12 * $dot($x2, $y))) / $det, (($s11 * $dot($x2, $y)) - ($s12 * $dot($x1, $y))) / $det];
    }

    public function testFranchiseNewsStaysInThePriceWhileTheNoiseIsGivenBack(): void
    {
        $series = $this->simulate();
        [$newsSlope, $noiseSlope] = self::slopes($series['next'], $series['news'], $series['noise']);

        $this->assertLessThan(-0.2, $noiseSlope, 'the pull gives back a year of noise');
        $this->assertGreaterThan(-0.12, $newsSlope, 'a year of news is not given back');
        $this->assertLessThan(0.08, $newsSlope);

        $mean = static fn(array $v): float => array_sum($v) / count($v);
        $newsMean = $mean($series['news']);
        $powerMean = $mean($series['power']);
        $cov = $varNews = $varPower = 0.0;
        foreach ($series['news'] as $i => $news) {
            $cov += ($news - $newsMean) * ($series['power'][$i] - $powerMean);
            $varNews += ($news - $newsMean) ** 2;
            $varPower += ($series['power'][$i] - $powerMean) ** 2;
        }
        $this->assertGreaterThan(0.95, $cov / sqrt($varNews * $varPower), 'the year\'s news is the margin its reports will book');
    }
}
