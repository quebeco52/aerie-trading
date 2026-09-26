<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Every account the probes keep has to reconstruct the quantity it explains, on the engine as wired, with every
 * shock live. A breakdown that does not add up is a panel reporting a story the economy did not tell.
 */
class MacroDiagnosticsClosureTest extends TestCase
{
    private const TICKS_PER_YEAR = 72;
    private const QUARTERS = 12;

    /** @var list<array{gap: array<string, mixed>, diagnostics: array<string, mixed>, gaps: list<float>}> */
    private static array $quarters = [];

    public static function setUpBeforeClass(): void
    {
        mt_srand(7);
        $math = new MathUtility();
        $gapProbe = new OutputGapProbe();
        $diagnostics = new MacroDiagnosticsProbe();
        $gapProbe->enable();
        $diagnostics->enable();

        $engine = new MacroEngine(
            $math,
            self::inMemoryRedis(),
            new MacroSnapshotRecorder(),
            new MonetaryPolicySubsystem($math, $diagnostics),
            new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($math, $gapProbe, $diagnostics),
            new CommodityLogisticsSubsystem($math, $diagnostics),
            new AssetMarketSubsystem($math),
            new CreditFiscalSubsystem($math, $diagnostics),
            null,
            $diagnostics,
        );

        $perQuarter = intdiv(self::TICKS_PER_YEAR, 4);
        for ($quarter = 0; $quarter < self::QUARTERS; ++$quarter) {
            $gaps = [];
            for ($tick = 0; $tick < $perQuarter; ++$tick) {
                $gaps[] = $engine->updateMacroState(1.0 / self::TICKS_PER_YEAR)->outputGap;
            }
            $gapProbe->rollWindow();
            $diagnostics->rollWindow();
            self::$quarters[] = [
                'gap' => $gapProbe->snapshot()['previous'],
                'diagnostics' => $diagnostics->snapshot()['previous'],
                'gaps' => $gaps,
            ];
        }
    }

    public function testInflationTermsSumToTheQuartersAverageInflation(): void
    {
        foreach (self::$quarters as $index => $quarter) {
            $inflation = $quarter['diagnostics']['inflation'];
            $this->assertEqualsWithDelta($inflation['average'], array_sum($inflation['terms']), 1e-12, "Quarter {$index}");
            $this->assertEqualsWithDelta($quarter['diagnostics']['averages']['inflation'], $inflation['average'], 1e-12, "Quarter {$index}");
        }
    }

    public function testTargetTermsSumToTheQuartersAverageTarget(): void
    {
        foreach (self::$quarters as $index => $quarter) {
            $policy = $quarter['diagnostics']['policy'];
            $this->assertEqualsWithDelta($policy['averageTarget'], array_sum($policy['terms']), 1e-12, "Quarter {$index}");
            $this->assertEqualsWithDelta($quarter['diagnostics']['averages']['targetRate'], $policy['averageTarget'], 1e-12, "Quarter {$index}");
            $this->assertEqualsWithDelta($quarter['diagnostics']['averages']['policyRate'], $policy['averageRate'], 1e-12, "Quarter {$index}");
            foreach ($policy['constraints'] as $name => $share) {
                $this->assertGreaterThanOrEqual(0.0, $share, $name);
                $this->assertLessThanOrEqual(1.0 + 1e-12, $share, $name);
            }
        }
    }

    public function testTheGapAccountClosesWithNothingMovingItBetweenTicks(): void
    {
        foreach (self::$quarters as $index => $quarter) {
            $gap = $quarter['gap'];
            $this->assertEqualsWithDelta(
                $gap['change'],
                $gap['drift'] + $gap['diffusion'] + $gap['clamp'] + $gap['external'] + $gap['unexplained'],
                1e-12,
                "Quarter {$index}"
            );
            $this->assertSame(0.0, $gap['external'], "Quarter {$index}: the drift equation is the gap's only writer.");
        }
    }

    /** The averaged gap is the mean of the gaps the ticks produced, the quarter-average a CBO comparison needs. */
    public function testTheAveragedGapIsTheMeanOfTheQuartersTicks(): void
    {
        foreach (self::$quarters as $index => $quarter) {
            $mean = array_sum($quarter['gaps']) / count($quarter['gaps']);
            $this->assertEqualsWithDelta($mean, $quarter['diagnostics']['averages']['outputGap'], 1e-12, "Quarter {$index}");
        }
    }

    /** The compensators and the disasters are reported as channels of their own on the wired engine. */
    public function testTheSplitChannelsAreReported(): void
    {
        foreach (self::$quarters as $index => $quarter) {
            $contributions = $quarter['gap']['contributions'];
            $this->assertArrayHasKey('demandDisaster', $contributions);
            $this->assertArrayHasKey('disasterCompensator', $contributions);
            $this->assertArrayHasKey('premiumCompensator', $contributions);
        }
    }

    /** The bootstrap's Redis stand-in forgets everything; the engine needs its state back each tick. */
    private static function inMemoryRedis(): \Redis
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
