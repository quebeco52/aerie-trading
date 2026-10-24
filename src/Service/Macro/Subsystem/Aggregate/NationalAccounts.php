<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Math\MacroConstants;
use App\Service\Math\TimeSeries;

/**
 * The statistics office's levels: potential and nominal GDP, the GDP deflator and the consumer price level built from
 * their rates, the month-end CPI record and its year-on-year rate, and the quarterly real GDP print with its recession
 * call.
 */
final class NationalAccounts
{
    // --- The Statistics Office's Recession Call ---
    /** Consecutive quarters of falling real GDP that declare a recession, and of growth that end one. */
    public const RECESSION_CALL_QUARTERS = 2;

    // --- Consumer Price Index, Year on Year ---
    /**
     * Month-end price levels kept: this month's and the twelve before, for the year-on-year rate the CPI release
     * reports.
     */
    public const CPI_MONTHS_KEPT = 13;

    /**
     * Solow-Swan (1956) Potential Output Capacity & Price Deflator Accumulation.
     *
     * Expands constant-dollar real potential GDP capacity via demographic growth and TFP,
     * accumulates the GDP price deflator via headline inflation, and computes nominal GDP.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $dt            Time increment in years.
     * @param float      $tfpGrowthRate Productivity growth potential output is built on.
     */
    public static function calculatePotentialAndNominalGdp(MacroState $state, float $dt, float $tfpGrowthRate): void
    {
        $realPotentialGrowth = $state->laborForceGrowthRate + $state->cyclicalMigrationRate + $tfpGrowthRate;
        $currentPotential = $state->potentialGdpIndex > 0.0 ? $state->potentialGdpIndex : 1.0;
        $state->potentialGdpIndex = max(0.10, $currentPotential * exp($realPotentialGrowth * $dt));
        // The population an immigration regime has added over the structural path (log), which bids for housing.
        $state->immigrationPopulationShift += ($state->laborForceGrowthRate + $state->cyclicalMigrationRate - MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE) * $dt;

        $currentDeflator = $state->gdpDeflator > 0.0 ? $state->gdpDeflator : 1.0;
        $state->gdpDeflator = max(0.01, $currentDeflator * exp($state->domesticInflation * $dt));
        $state->consumerPriceLevel = max(0.01, ($state->consumerPriceLevel > 0.0 ? $state->consumerPriceLevel : $currentDeflator) * exp($state->inflation * $dt));
        if (TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, 1.0 / 12.0)) {
            $state->consumerPriceMonthly = array_slice([...$state->consumerPriceMonthly, $state->consumerPriceLevel], -self::CPI_MONTHS_KEPT);
        }

        $priorNominalGdp = $state->nominalGdpIndex;
        $state->nominalGdpIndex = max(0.10, $state->potentialGdpIndex * (1.0 + $state->outputGap) * $state->gdpDeflator);
        $state->nominalGdpGrowth = $priorNominalGdp > 0.0 ? log($state->nominalGdpIndex / $priorNominalGdp) / $dt : $state->nominalGdpGrowth;
    }

    /**
     * Consumer price inflation over the twelve months to the last month end, in logs: the year-on-year rate the CPI
     * release reports and households answer surveys on.
     *
     * @param list<float> $consumerPriceMonthly Month-end consumer price levels, oldest first.
     * @return float|null Null until a year of month ends is on record.
     */
    public static function consumerPriceInflationYearOnYear(array $consumerPriceMonthly): ?float
    {
        $count = count($consumerPriceMonthly);
        if ($count < self::CPI_MONTHS_KEPT || $consumerPriceMonthly[$count - self::CPI_MONTHS_KEPT] <= 0.0) {
            return null;
        }

        return log($consumerPriceMonthly[$count - 1] / $consumerPriceMonthly[$count - self::CPI_MONTHS_KEPT]);
    }

    /**
     * The statistics office's quarterly accounts: real GDP averaged over each calendar quarter, its change on the
     * quarter before, and the recession call. Two quarters of falling output declare a recession and two of growth end
     * it, a rule anyone reading the quarterly print can apply.
     */
    public static function recordQuarterlyRealGdp(MacroState $state, float $dt): void
    {
        $state->realGdpQuarterAccrued += $state->potentialGdpIndex * (1.0 + $state->outputGap) * $dt;
        $state->realGdpQuarterElapsed += $dt;
        if (!TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, 0.25) || $state->realGdpQuarterElapsed <= 0.0) {
            return;
        }

        $quarter = $state->realGdpQuarterAccrued / $state->realGdpQuarterElapsed;
        $state->realGdpQuarterAccrued = 0.0;
        $state->realGdpQuarterElapsed = 0.0;
        if ($state->realGdpLastQuarter > 0.0) {
            $state->realGdpQuarterlyGrowth = ($quarter / $state->realGdpLastQuarter) - 1.0;
            $falling = $state->realGdpQuarterlyGrowth < 0.0;
            $state->gdpQuarterStreak = $falling
                ? min(0.0, $state->gdpQuarterStreak) - 1.0
                : max(0.0, $state->gdpQuarterStreak) + 1.0;
            if ($state->gdpQuarterStreak <= -self::RECESSION_CALL_QUARTERS) {
                $state->recessionDeclared = true;
            } elseif ($state->gdpQuarterStreak >= self::RECESSION_CALL_QUARTERS) {
                $state->recessionDeclared = false;
            }
        }
        $state->realGdpLastQuarter = $quarter;
    }
}
