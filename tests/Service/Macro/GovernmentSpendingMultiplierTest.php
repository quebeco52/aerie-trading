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
                // Purchases are TARGET_CORPORATE_TAX_RATE of GDP at the baseline index.
                $purchasesSum += MacroEngine::TARGET_CORPORATE_TAX_RATE
                    * ($shocked[$quarter]['gov'] - $row['gov']) / MacroEngine::GOVT_SPENDING_BASELINE;
            }
        }
        $multiplier = $outputSum / $purchasesSum;

        $this->assertGreaterThanOrEqual(0.54, $multiplier, 'At least Ramey-Zubairy\'s Blanchard-Perotti 2-year integral.');
        $this->assertLessThanOrEqual(1.0, $multiplier, 'Within Ramey\'s (2019) 0.6-1.0 range for aggregate purchases.');
    }

    /** @return list<array{gap: float, gov: float}> */
    private function quarterlyPath(int $seed, float $shock): array
    {
        mt_srand($seed);
        $mathUtility = new MathUtility();
        $redis = $this->inMemoryRedis();
        $engine = new MacroEngine(
            $mathUtility,
            $redis,
            new MacroSnapshotRecorder(),
            new MonetaryPolicySubsystem($mathUtility),
            new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($mathUtility),
            new CommodityLogisticsSubsystem($mathUtility),
            new AssetMarketSubsystem($mathUtility),
            new CreditFiscalSubsystem($mathUtility),
        );
        $dt = 1.0 / self::TICKS_PER_YEAR;
        for ($tick = 0; $tick < self::BURN_IN_YEARS * self::TICKS_PER_YEAR; $tick++) {
            $engine->updateMacroState($dt);
        }

        if ($shock !== 0.0) {
            $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
            $this->assertIsArray($state);
            $this->assertArrayHasKey('government_spending_index', $state);
            $state['government_spending_index'] *= 1.0 + $shock;
            $redis->set(MacroEngine::REDIS_MACRO_STATE, (string) json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
        }

        $rows = [];
        $sampleEvery = intdiv(self::TICKS_PER_YEAR, 4);
        for ($tick = 1; $tick <= self::HORIZON_QUARTERS * $sampleEvery; $tick++) {
            $macro = $engine->updateMacroState($dt);
            if ($tick % $sampleEvery === 0) {
                $rows[] = ['gap' => $macro->outputGap, 'gov' => $macro->governmentSpendingIndex];
            }
        }

        return $rows;
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
