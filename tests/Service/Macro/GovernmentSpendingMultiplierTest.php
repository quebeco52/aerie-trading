<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The cumulative government purchases multiplier the whole engine produces, feedback included, measured as
 * Ramey & Zubairy (2018) measure it: the summed output response over the summed purchases response, here to a
 * shock to purchases themselves (the Blanchard-Perotti analogue, since the engine has no spending news). Each
 * seed runs twice on the same draws, the second with purchases raised after the burn-in.
 *
 * Their 2-year integral is 0.54 under Blanchard-Perotti identification and 0.76 (se 0.10) under news shocks
 * (NBER w20719, Tables 1-2); Ramey (2019) puts aggregate purchases multipliers at 0.6-1.0. Until 2026-09-28 the
 * engine's was 0.18: the purchases leg was a tenth of the drift the spring and the Taylor loop take back.
 */
class GovernmentSpendingMultiplierTest extends TestCase
{
    private const SEEDS = [1, 2, 3, 4];
    private const BURN_IN_YEARS = 8;
    private const TICKS_PER_YEAR = 180;
    private const HORIZON_QUARTERS = 8;
    /** Purchases raised 5%, about 1% of GDP: large against the seed noise, small against the capacity ceiling. */
    private const SHOCK = 0.05;

    public function testTheTwoYearPurchasesMultiplierIsInTheUsRange(): void
    {
        $outputSum = 0.0;
        $purchasesSum = 0.0;
        foreach (self::SEEDS as $seed) {
            $base = $this->quarterlyPath($seed, 0.0);
            $shocked = $this->quarterlyPath($seed, self::SHOCK);
            foreach ($base as $quarter => $row) {
                $outputSum += $shocked[$quarter]['gap'] - $row['gap'];
                // Purchases are PUBLIC_PURCHASES_TO_GDP of GDP at the baseline index.
                $purchasesSum += MacroConstants::PUBLIC_PURCHASES_TO_GDP
                    * ($shocked[$quarter]['gov'] - $row['gov']) / MacroConstants::GOVT_SPENDING_BASELINE;
            }
        }
        $multiplier = $outputSum / $purchasesSum;

        // Spending lifts output, by no more than one for one once the imports it draws in have leaked abroad: across
        // countries multipliers run from about zero in open economies with floating currencies to about one in large
        // closed ones (Ilzetzki, Mendoza & Vegh 2013).
        $leak = 1.0 - MacroAggregateSubsystem::EXCESS_IMPORT_LEAKAGE;
        $this->assertGreaterThan(0.0, $multiplier, 'Purchases lift output.');
        $this->assertLessThanOrEqual(1.0 * $leak, $multiplier, 'By no more than one for one, net of the leak.');
    }

    /** @return list<array{gap: float, gov: float}> */
    private function quarterlyPath(int $seed, float $shock): array
    {
        mt_srand($seed);
        $mathUtility = new MathUtility();
        $engine = MacroEngine::headless($mathUtility);
        $economy = new MacroState();
        $dt = 1.0 / self::TICKS_PER_YEAR;
        for ($tick = 0; $tick < self::BURN_IN_YEARS * self::TICKS_PER_YEAR; $tick++) {
            $engine->step($economy, $dt);
        }

        if ($shock !== 0.0) {
            $economy->governmentSpendingIndex *= 1.0 + $shock;
        }

        $rows = [];
        $sampleEvery = intdiv(self::TICKS_PER_YEAR, 4);
        for ($tick = 1; $tick <= self::HORIZON_QUARTERS * $sampleEvery; $tick++) {
            $macro = $engine->step($economy, $dt);
            if ($tick % $sampleEvery === 0) {
                $rows[] = ['gap' => $macro->outputGap, 'gov' => $macro->governmentSpendingIndex];
            }
        }

        return $rows;
    }

}
