<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\LinearAlgebra;
use PHPUnit\Framework\TestCase;

class LinearAlgebraTest extends TestCase
{
    public function testSolvesASystemThatNeedsAPivotAndRefusesASingularOne(): void
    {
        $x = LinearAlgebra::solve([[0.0, 2.0, 1.0], [1.0, 1.0, 0.0], [3.0, 0.0, 1.0]], [5.0, 3.0, 4.0]);

        $this->assertNotNull($x);
        foreach ([1.0, 2.0, 1.0] as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $x[$i], 1e-12);
        }
        $this->assertNull(LinearAlgebra::solve([[1.0, 2.0], [2.0, 4.0]], [1.0, 2.0]));
    }

    /** A weight the plain fit would take negative is held at zero, and the residual is the distance to the cone. */
    public function testAWeightThatWouldTurnNegativeIsHeldAtZero(): void
    {
        [$weights, $residual] = LinearAlgebra::nonNegativeLeastSquares([[1.0, 0.0], [0.0, 1.0]], [1.0, -1.0]);

        $this->assertSame([1.0, 0.0], $weights);
        $this->assertEqualsWithDelta(1.0, $residual, 1e-15);

        [$weights, $residual] = LinearAlgebra::nonNegativeLeastSquares([[1.0, 1.0], [1.0, -1.0]], [3.0, 1.0]);
        $this->assertEqualsWithDelta(2.0, $weights[0], 1e-12);
        $this->assertEqualsWithDelta(1.0, $weights[1], 1e-12);
        $this->assertEqualsWithDelta(0.0, $residual, 1e-12, 'A target inside the cone is met exactly.');
    }

    /**
     * The fit is the best of every set of columns whose plain least squares keeps all its weights non-negative, which
     * is where the optimum lies (the conditions Lawson and Hanson's method stops on).
     */
    public function testTheFitIsTheBestNonNegativeOneOverEverySetOfColumns(): void
    {
        mt_srand(11);
        $draw = static fn(): float => (mt_rand() / mt_getrandmax() * 2.0) - 1.0;
        for ($case = 0; $case < 60; $case++) {
            $rows = 3 + ($case % 4);
            $count = 2 + ($case % 4);
            $columns = [];
            for ($j = 0; $j < $count; $j++) {
                $columns[] = array_map(static fn(): float => $draw(), range(1, $rows));
            }
            $target = array_map(static fn(): float => $draw(), range(1, $rows));

            [$weights, $residual] = LinearAlgebra::nonNegativeLeastSquares($columns, $target);

            $best = sqrt(array_sum(array_map(static fn(float $value): float => $value * $value, $target)));
            for ($mask = 1; $mask < (1 << $count); $mask++) {
                $set = array_values(array_filter(range(0, $count - 1), static fn(int $j): bool => ($mask & (1 << $j)) !== 0));
                $dot = static fn(array $a, array $b): float => array_sum(array_map(static fn(float $x, float $y): float => $x * $y, $a, $b));
                $normal = array_map(static fn(int $j): array => array_map(static fn(int $k): float => $dot($columns[$j], $columns[$k]), $set), $set);
                $projected = array_map(static fn(int $j): float => $dot($columns[$j], $target), $set);
                $fit = LinearAlgebra::solve($normal, $projected);
                if ($fit === null || array_filter($fit, static fn(float $weight): bool => $weight < 0.0) !== []) {
                    continue;
                }
                $left = $target;
                foreach ($set as $u => $j) {
                    foreach ($columns[$j] as $i => $value) {
                        $left[$i] -= $value * $fit[$u];
                    }
                }
                $best = min($best, sqrt(array_sum(array_map(static fn(float $value): float => $value * $value, $left))));
            }

            $this->assertSame([], array_filter($weights, static fn(float $weight): bool => $weight < 0.0), "Case {$case}");
            $this->assertEqualsWithDelta($best, $residual, 1e-10, "Case {$case}");
        }
    }
}
