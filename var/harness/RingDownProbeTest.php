<?php

declare(strict_types=1);

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
 * Deterministic ring-down: every draw is zero and no jump lands, so the engine is its own linear-ish loop. Two runs
 * settle for BURN years; one then gets IMPULSE added to the output gap. Their difference is the loop's free response.
 * Env: BURN YEARS TPY IMPULSE OUT
 */
final class RingDownProbeTest extends TestCase
{
    public function testRun(): void
    {
        $burn = (int) (getenv('BURN') ?: 20);
        $years = (int) (getenv('YEARS') ?: 20);
        $tpy = (int) (getenv('TPY') ?: 360);
        $impulse = (float) (getenv('IMPULSE') ?: 0.016);
        $dt = 1.0 / $tpy;
        $paths = [];
        foreach ([0.0, $impulse] as $kick) {
            // Common random numbers: any draw the zeroed methods do not cover is identical in both runs.
            mt_srand((int) (getenv('SEED') ?: 42));
            $math = new class extends MathUtility {
                public function generateStandardNormal(): float { return 0.0; }
                public function checkProbability(float $probability): bool { return false; }
            };
            $redis = new class extends \Redis {
                /** @var array<string, string> */
                private array $store = [];
                public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
                public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
            };
            $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
                new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
            for ($tick = 0; $tick < $burn * $tpy; $tick++) {
                $engine->updateMacroState($dt);
            }
            if ($kick !== 0.0) {
                $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
                $state['output_gap'] += $kick;
                $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
            }
            $path = [];
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $m = $engine->updateMacroState($dt);
                if ($tick % intdiv($tpy, 4) === 0) {
                    $path[] = $m->outputGap;
                }
            }
            $paths[] = $path;
        }
        $response = array_map(static fn (float $a, float $b): float => ($a - $b) / $impulse, $paths[1], $paths[0]);
        file_put_contents((string) getenv('OUT'), json_encode($response));
        $this->assertNotEmpty($response);
    }
}
