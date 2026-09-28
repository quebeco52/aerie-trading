<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The household credit cycle the whole engine produces, against the US record (FRED CMDEBT / DSPI and GDPC1 / GDPPOT,
 * 1977-2019, var/harness/djk_an.py): credit expansions are booms, and a year of new borrowing is followed by more.
 *
 * The three-year change in debt to income correlates +0.51 with the output gap in the same quarter, and the year-end
 * annual change in leverage carries 0.58 of itself into the next year (Drehmann, Juselius & Korinek 2018 find 0.88
 * across sixteen countries). Until 2026-09-28 the engine read +0.01 and 0.23: house prices had no momentum, so
 * credit growth was noise around them, and borrowing reached demand only as the debt service it later costs.
 */
class HouseholdCreditCycleTest extends TestCase
{
    private const SEEDS = [1, 2, 3];
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
            $yearEnd = array_values(array_filter($leverage, static fn (int $q): bool => $q % 4 === 3, ARRAY_FILTER_USE_KEY));
            for ($y = 2; $y < count($yearEnd); $y++) {
                $annual[] = $yearEnd[$y] - $yearEnd[$y - 1];
                $annualLagged[] = $yearEnd[$y - 1] - $yearEnd[$y - 2];
            }
        }

        // Three seeds read this correlation to about 0.15 either side of the harness's, so it guards the sign; the strength
        // of the borrowing leg is MacroAggregateSubsystemTest's, and its fit is djk_an.py's.
        $this->assertGreaterThan(0.10, $this->correlation($changes, $gaps), 'Three years of rising leverage are a boom (US +0.51; the harness reads +0.44 over 36 seeds).');
        $this->assertGreaterThan(0.25, $this->correlation($annual, $annualLagged), 'A year of new borrowing is followed by more (US 0.58; the harness reads 0.59).');
    }

    /** @return array{0: list<float>, 1: list<float>} Quarter-end debt to income and quarter-average output gap after the burn-in. */
    private function quarterlyPath(int $seed): array
    {
        mt_srand($seed);
        $mathUtility = new MathUtility();
        $engine = new MacroEngine(
            $mathUtility,
            $this->inMemoryRedis(),
            new MacroSnapshotRecorder(),
            new MonetaryPolicySubsystem($mathUtility),
            new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($mathUtility),
            new CommodityLogisticsSubsystem($mathUtility),
            new AssetMarketSubsystem($mathUtility),
            new CreditFiscalSubsystem($mathUtility),
        );
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $perQuarter = intdiv(self::TICKS_PER_YEAR, 4);
        $leverage = [];
        $gap = [];
        $gapSum = 0.0;
        for ($tick = 1; $tick <= self::YEARS * self::TICKS_PER_YEAR; $tick++) {
            $macro = $engine->updateMacroState($dt);
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

    /** The bootstrap's Redis stand-in forgets everything; the engine needs its state back each tick. */
    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }
}
