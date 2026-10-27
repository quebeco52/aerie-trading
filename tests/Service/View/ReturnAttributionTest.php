<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Service\View\MoveAttributionBuilder;
use App\Service\View\ReturnAttribution;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A share price's move split into the market, the peers beyond the market and the company itself.
 */
#[Group('unit')]
final class ReturnAttributionTest extends TestCase
{
    /** A company built as 1.3 times the market plus 0.8 times its peers' own move is read back as just that. */
    public function testTheBetasAndPartsAreReadBack(): void
    {
        [$market, $peerOwn, $company] = $this->factors(104);
        $peers = array_map(static fn(float $m, float $e): float => 0.9 * $m + $e, $market, $peerOwn);
        $own = array_map(static fn(float $m, float $e, float $c): float => 1.3 * $m + 0.8 * $e + $c, $market, $peerOwn, $company);

        $split = ReturnAttribution::attribute($own, $market, $peers, 13);

        $this->assertNotNull($split);
        $this->assertEqualsWithDelta(1.3, $split['beta'], 1e-9);
        $total = array_sum(array_slice($own, -13));
        $scale = (exp($total) - 1.0) / $total;
        $this->assertEqualsWithDelta($scale * 1.3 * array_sum(array_slice($market, -13)), $split['market'], 1e-9);
        $this->assertEqualsWithDelta($scale * 0.8 * array_sum(array_slice($peerOwn, -13)), $split['peers'], 1e-9);
    }

    /** The three parts add up to the simple return shown as the total. */
    public function testThePartsAddUpToTheTotal(): void
    {
        [$market, $peers, $own] = $this->factors(80);
        $split = ReturnAttribution::attribute($own, $market, $peers, 52);

        $this->assertNotNull($split);
        $this->assertEqualsWithDelta(exp(array_sum(array_slice($own, -52))) - 1.0, $split['total'], 1e-12);
        $this->assertEqualsWithDelta($split['total'], $split['market'] + $split['peers'] + $split['company'], 1e-12);
    }

    /** History is not restated for splits: a 2-for-1 that halves the price is no fall, a 1-for-10 no tenfold rise. */
    public function testASplitIsTakenBackOutOfItsWeek(): void
    {
        $rows = [
            ['stock_id' => 7, 'sim_time' => 1.00, 'price' => 100.0],
            ['stock_id' => 7, 'sim_time' => 1.02, 'price' => 51.0],
            ['stock_id' => 7, 'sim_time' => 1.04, 'price' => 52.0],
            ['stock_id' => 9, 'sim_time' => 1.00, 'price' => 2.0],
            ['stock_id' => 9, 'sim_time' => 1.02, 'price' => 19.0],
        ];
        $returns = MoveAttributionBuilder::weeklyLogReturns($rows, [7 => [[1.01, log(2.0)]], 9 => [[1.015, log(0.1)]]]);

        $this->assertEqualsWithDelta(log(1.02), $returns[7][(int) floor(1.02 * 52)], 1e-12);
        $this->assertEqualsWithDelta(log(52.0 / 51.0), $returns[7][(int) floor(1.04 * 52)], 1e-12, 'Only the week that holds the split.');
        $this->assertEqualsWithDelta(log(0.95), $returns[9][(int) floor(1.02 * 52)], 1e-12);
    }

    public function testTooFewWeeksGiveNoReading(): void
    {
        [$market, $peers, $own] = $this->factors(ReturnAttribution::MIN_WEEKS - 1);

        $this->assertNull(ReturnAttribution::attribute($own, $market, $peers, 13));
    }

    /**
     * Three uncorrelated weekly series from a fixed recurrence, orthogonalised so a regression reads them back exactly.
     *
     * @return array{list<float>, list<float>, list<float>}
     */
    private function factors(int $weeks): array
    {
        $series = [[], [], []];
        for ($w = 0; $w < $weeks; ++$w) {
            $series[0][] = 0.03 * sin(1.7 * $w + 0.3);
            $series[1][] = 0.02 * sin(2.9 * $w + 1.1);
            $series[2][] = 0.025 * sin(4.3 * $w + 2.0);
        }
        // Gram-Schmidt against the earlier series, with means removed, so each slope is exact.
        $demean = static function (array $x): array {
            $mean = array_sum($x) / count($x);

            return array_values(array_map(static fn(float $v): float => $v - $mean, $x));
        };
        $project = static function (array $y, array $x): array {
            $b = array_sum(array_map(static fn(float $a, float $c): float => $a * $c, $y, $x)) / array_sum(array_map(static fn(float $a): float => $a * $a, $x));

            return array_values(array_map(static fn(float $a, float $c): float => $a - $b * $c, $y, $x));
        };
        $a = $demean($series[0]);
        $b = $project($demean($series[1]), $a);
        $c = $project($project($demean($series[2]), $a), $b);

        return [$a, $b, $c];
    }
}
