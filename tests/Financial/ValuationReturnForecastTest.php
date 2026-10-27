<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Corporate\EarningsEngine;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\AbstractBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The return a firm is valued on forecasts what it goes on to earn: a firm's filed returns revert to a level of its
 * own at an operating firm's persistence, and the valuation fades its recent year (EarningsEngine::
 * TRAILING_RETURN_EMA_YEARS) toward the long average of what it filed (ANCHOR_RETURN_EMA_YEARS).
 */
#[Group('unit')]
final class ValuationReturnForecastTest extends TestCase
{
    private const RATE = 0.08;

    /**
     * Across firms whose own levels range from 5% to 35%, the valuation return's forecast of the constant return worth
     * what each goes on to earn is unbiased and one for one; a level pulled toward the cost of capital, as the
     * operating return state is, undervalues the persistent earners.
     */
    public function testTheValuationReturnForecastsWhatAFirmGoesOnToEarn(): void
    {
        [$filedFit, $pulledFit] = $this->fits();

        $this->assertEqualsWithDelta(0.0, $filedFit['bias'], 0.003, 'unbiased');
        $this->assertEqualsWithDelta(1.0, $filedFit['slope'], 0.10, 'one for one');
        $this->assertGreaterThan(0.01, $pulledFit['bias'], 'a level pulled toward the cost of capital undervalues them');
    }

    /** @return array{0: array{bias: float, slope: float}, 1: array{bias: float, slope: float}} */
    private function fits(): array
    {
        $stream = MathUtility::ownStream(20261027);
        $quarterPersistence = AbstractBusinessModel::RETURN_PERSISTENCE ** 0.25;
        $horizon = 160;
        $filed = [];
        $pulled = [];
        for ($firm = 0; $firm < 60; $firm++) {
            $level = 0.05 + (0.30 * $stream->generateUniform());
            $returns = [$level];
            for ($quarter = 1; $quarter < 400 + $horizon; $quarter++) {
                $returns[] = $level + ($quarterPersistence * ($returns[$quarter - 1] - $level)) + (0.02 * $stream->generateStandardNormal());
            }
            $anchor = $level;
            $trailing = null;
            $state = $level;
            for ($quarter = 0; $quarter < 400; $quarter++) {
                $anchor = TimeSeries::ewmaLevel($anchor, $returns[$quarter], 0.25, EarningsEngine::ANCHOR_RETURN_EMA_YEARS);
                $trailing = TimeSeries::ewmaLevel($trailing, $returns[$quarter], 0.25, EarningsEngine::TRAILING_RETURN_EMA_YEARS);
                // The operating return state: the filed return blended in, pulled toward the cost of capital.
                $state += 0.25 * ($returns[$quarter] - $state) - (0.05 * ($state - self::RATE));
                if ($quarter >= 120 && $quarter % 8 === 0) {
                    $earned = self::constantEquivalent(array_slice($returns, $quarter + 1, $horizon));
                    $filed[] = [TimeSeries::persistentEquivalentReturn($trailing, $anchor, self::RATE, AbstractBusinessModel::RETURN_PERSISTENCE), $earned];
                    $pulled[] = [TimeSeries::persistentEquivalentReturn($trailing, $state, self::RATE, AbstractBusinessModel::RETURN_PERSISTENCE), $earned];
                }
            }
        }

        return [self::fit($filed), self::fit($pulled)];
    }

    /**
     * The constant annual return worth what a run of quarterly annual returns is, discounted at the rate.
     *
     * @param list<float> $returns
     */
    private static function constantEquivalent(array $returns): float
    {
        $worth = 0.0;
        $weight = 0.0;
        foreach ($returns as $index => $return) {
            $discount = (1.0 + self::RATE) ** (-($index + 1) / 4.0);
            $worth += $return * $discount;
            $weight += $discount;
        }

        return $worth / $weight;
    }

    /**
     * The realized return less the forecast on average, and the slope of the realized return on the forecast.
     *
     * @param list<array{0: float, 1: float}> $pairs Forecast, realized.
     * @return array{bias: float, slope: float}
     */
    private static function fit(array $pairs): array
    {
        $count = count($pairs);
        $meanForecast = array_sum(array_column($pairs, 0)) / $count;
        $meanRealized = array_sum(array_column($pairs, 1)) / $count;
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($pairs as [$forecast, $realized]) {
            $covariance += ($forecast - $meanForecast) * ($realized - $meanRealized);
            $variance += ($forecast - $meanForecast) ** 2;
        }

        return ['bias' => $meanRealized - $meanForecast, 'slope' => $covariance / $variance];
    }
}
