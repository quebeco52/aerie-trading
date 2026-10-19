<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Dense linear algebra for systems of a handful of unknowns: a linear solve and non-negative least squares. Pure
 * functions of their arguments.
 */
final class LinearAlgebra
{
    // --- Non-Negative Least Squares ---
    /** Gain in fit, per unit of a column's weight, below which the column is not brought in. */
    private const NNLS_GAIN_TOLERANCE = 1e-14;
    /** Weight at or below which a column is dropped from the fit. */
    private const NNLS_ZERO_WEIGHT = 1e-15;
    /**
     * Columns brought in before the fit is taken as found; Lawson and Hanson's method needs a few times the columns.
     */
    private const NNLS_MAX_PASSES = 100;

    /**
     * Solves A x = b by Gaussian elimination with partial pivoting.
     *
     * @param array<int, array<int, float>> $matrix A, by rows.
     * @param array<int, float>             $vector b.
     * @return array<int, float>|null x, or null when A is singular.
     */
    public static function solve(array $matrix, array $vector): ?array
    {
        $n = count($vector);
        for ($column = 0; $column < $n; $column++) {
            $pivot = $column;
            for ($row = $column + 1; $row < $n; $row++) {
                if (abs($matrix[$row][$column]) > abs($matrix[$pivot][$column])) {
                    $pivot = $row;
                }
            }
            if ($matrix[$pivot][$column] == 0.0) {
                return null;
            }
            [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];
            [$vector[$column], $vector[$pivot]] = [$vector[$pivot], $vector[$column]];
            for ($row = $column + 1; $row < $n; $row++) {
                $factor = $matrix[$row][$column] / $matrix[$column][$column];
                for ($k = $column; $k < $n; $k++) {
                    $matrix[$row][$k] -= $factor * $matrix[$column][$k];
                }
                $vector[$row] -= $factor * $vector[$column];
            }
        }

        $x = array_fill(0, $n, 0.0);
        for ($row = $n - 1; $row >= 0; $row--) {
            $sum = $vector[$row];
            for ($k = $row + 1; $k < $n; $k++) {
                $sum -= $matrix[$row][$k] * $x[$k];
            }
            $x[$row] = $sum / $matrix[$row][$row];
            if (!is_finite($x[$row])) {
                return null;
            }
        }

        return $x;
    }

    /**
     * The non-negative weights on the columns that bring their sum nearest the target (Lawson & Hanson 1974, ch. 23):
     * the column the residual points along most is brought in, the least squares on the columns in is solved, and a
     * column whose weight would turn negative is stepped back to zero and dropped.
     *
     * @param array<int, array<int, float>> $columns Each column, of the target's length.
     * @param array<int, float>             $target
     * @return array{0: array<int, float>, 1: float} The weights, and the length of what is left of the target.
     */
    public static function nonNegativeLeastSquares(array $columns, array $target): array
    {
        $weights = array_fill(0, count($columns), 0.0);
        $in = [];
        for ($pass = 0; $pass < self::NNLS_MAX_PASSES; $pass++) {
            $residual = self::residual($columns, $weights, $target);
            $entering = null;
            $bestGain = self::NNLS_GAIN_TOLERANCE;
            foreach ($columns as $j => $column) {
                $gain = self::dot($column, $residual);
                if (!isset($in[$j]) && $gain > $bestGain) {
                    $entering = $j;
                    $bestGain = $gain;
                }
            }
            if ($entering === null) {
                break;
            }
            $in[$entering] = true;

            // Least squares on the columns in; any weight it would take below zero is stepped back to zero instead,
            // as far along the way as the first of them allows, and its column dropped.
            while (true) {
                $set = array_keys($in);
                $normal = [];
                $projected = [];
                foreach ($set as $u => $j) {
                    foreach ($set as $v => $k) {
                        $normal[$u][$v] = self::dot($columns[$j], $columns[$k]);
                    }
                    $projected[$u] = self::dot($columns[$j], $target);
                }
                $fit = self::solve($normal, $projected);
                if ($fit === null) {
                    unset($in[$entering]);

                    break 2;
                }
                $trial = array_fill(0, count($columns), 0.0);
                $positive = true;
                foreach ($set as $u => $j) {
                    $trial[$j] = $fit[$u];
                    $positive = $positive && $fit[$u] > 0.0;
                }
                if ($positive) {
                    $weights = $trial;

                    break;
                }
                $step = 1.0;
                $blocking = null;
                foreach ($set as $j) {
                    if ($trial[$j] <= 0.0) {
                        $ratio = $weights[$j] > 0.0 ? $weights[$j] / ($weights[$j] - $trial[$j]) : 0.0;
                        if ($blocking === null || $ratio < $step) {
                            $step = $ratio;
                            $blocking = $j;
                        }
                    }
                }
                foreach ($set as $j) {
                    $weights[$j] += $step * ($trial[$j] - $weights[$j]);
                    if ($j === $blocking || $weights[$j] <= self::NNLS_ZERO_WEIGHT) {
                        $weights[$j] = 0.0;
                        unset($in[$j]);
                    }
                }
                if ($in === []) {
                    break;
                }
            }
        }

        $residual = self::residual($columns, $weights, $target);

        return [$weights, sqrt(self::dot($residual, $residual))];
    }

    /**
     * @param array<int, array<int, float>> $columns
     * @param array<int, float>             $weights
     * @param array<int, float>             $target
     * @return array<int, float>
     */
    private static function residual(array $columns, array $weights, array $target): array
    {
        foreach ($columns as $j => $column) {
            if ($weights[$j] != 0.0) {
                foreach ($column as $i => $value) {
                    $target[$i] -= $value * $weights[$j];
                }
            }
        }

        return $target;
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }
}
