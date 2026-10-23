<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The household credit cycle the whole engine produces: credit expansions are booms, and a year of new borrowing is
 * followed by more, as leverage growth carries most of itself into the next year across sixteen countries (Drehmann,
 * Juselius & Korinek 2018). An engine whose house prices have no momentum makes credit growth noise around them.
 */
class HouseholdCreditCycleTest extends TestCase
{
    private const SEEDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
    private const YEARS = 50;
    private const BURN_IN_YEARS = 10;
    private const TICKS_PER_YEAR = 90;

    public function testCreditExpansionsAreBoomsAndBorrowingPersists(): void
    {
        $changes = [];
        $gaps = [];
        $annual = [];
        $annualLagged = [];
        foreach (self::SEEDS as $seed) {
            [$leverage, $gap] = $this->quarterlyPath($seed);
            for ($q = 12; $q < count($leverage); $q++) {
                $changes[] = $leverage[$q] - $leverage[$q - 12];
                $gaps[] = $gap[$q];
            }
            $yearEnd = array_values(array_filter($leverage, static fn(int $q): bool => $q % 4 === 3, ARRAY_FILTER_USE_KEY));
            for ($y = 2; $y < count($yearEnd); $y++) {
                $annual[] = $yearEnd[$y] - $yearEnd[$y - 1];
                $annualLagged[] = $yearEnd[$y - 1] - $yearEnd[$y - 2];
            }
        }

        // A crisis or two decides a seed's reading (single seeds run from about zero to 0.7), so twelve pooled guard
        // the signs; the strength of the borrowing leg is MacroAggregateSubsystemTest's.
        $this->assertGreaterThan(0.10, $this->correlation($changes, $gaps), 'Three years of rising leverage are a boom.');
        $this->assertGreaterThan(0.10, $this->correlation($annual, $annualLagged), 'A year of new borrowing is followed by more.');
    }

    /**
     * @return array{0: list<float>, 1: list<float>} Quarter-end debt to income and quarter-average output gap after the
     *                                               burn-in.
     */
    private function quarterlyPath(int $seed): array
    {
        mt_srand($seed);
        $mathUtility = new MathUtility();
        $engine = MacroEngine::headless($mathUtility);
        $economy = new MacroState();
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $perQuarter = intdiv(self::TICKS_PER_YEAR, 4);
        $leverage = [];
        $gap = [];
        $gapSum = 0.0;
        for ($tick = 1; $tick <= self::YEARS * self::TICKS_PER_YEAR; $tick++) {
            $macro = $engine->step($economy, $dt);
            $gapSum += $macro->outputGap;
            if ($tick % $perQuarter === 0) {
                if ($tick > self::BURN_IN_YEARS * self::TICKS_PER_YEAR) {
                    $leverage[] = $macro->householdDebtToIncome;
                    $gap[] = $gapSum / $perQuarter;
                }
                $gapSum = 0.0;
            }
        }

        return [$leverage, $gap];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function correlation(array $a, array $b): float
    {
        $meanA = array_sum($a) / count($a);
        $meanB = array_sum($b) / count($b);
        $cov = 0.0;
        $varA = 0.0;
        $varB = 0.0;
        foreach ($a as $i => $x) {
            $cov += ($x - $meanA) * ($b[$i] - $meanB);
            $varA += ($x - $meanA) ** 2;
            $varB += ($b[$i] - $meanB) ** 2;
        }

        return $cov / sqrt($varA * $varB);
    }

}
