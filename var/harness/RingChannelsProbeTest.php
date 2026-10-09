<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
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
 * Deterministic ring-down: every draw is zero and no jump lands, so the engine is its own linear-ish loop. Two runs
 * settle for BURN years; one then gets IMPULSE added to the output gap. Their difference is the loop's free response.
 * Each quarter's gap change is split by channel (OutputGapProbe); the output is kicked minus control, per unit kick.
 * Env: BURN YEARS TPY IMPULSE OUT
 */
final class RingChannelsProbeTest extends TestCase
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
            $probe = new OutputGapProbe();
            $probe->enable();
            $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
                new MacroAggregateSubsystem($math, $probe), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
            for ($tick = 0; $tick < $burn * $tpy; $tick++) {
                $engine->updateMacroState($dt);
            }
            $probe->rollWindow();
            if ($kick !== 0.0) {
                $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
                $state[getenv('KICK') ?: 'output_gap'] += $kick;
                $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
            }
            $path = [];
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $m = $engine->updateMacroState($dt);
                if ($tick % intdiv($tpy, 4) === 0) {
                    $probe->rollWindow();
                    $path[] = ['gap' => $m->outputGap, 'c' => $probe->snapshot()['previous']['contributions'], 'x' => [
                        'rate' => $m->policyRate, 'target' => $m->targetRate, 'rstar' => $m->naturalRate, 'infl' => $m->inflation,
                        'core' => (0.55 * $m->supercoreInflation + 0.25 * $m->coreGoodsInflation) / 0.80, 'tips' => $m->tipsBreakeven,
                        'y5' => $m->yield5y, 'stance' => $m->monetaryStanceTransmitted, 'wage' => $m->wageGrowth, 'infl_ema' => $m->inflationEma,
                    ]];
                }
            }
            $paths[] = $path;
        }
        $response = [];
        foreach ($paths[1] as $q => $row) {
            $c = [];
            foreach ($row['c'] as $name => $v) {
                $c[$name] = ($v - ($paths[0][$q]['c'][$name] ?? 0.0)) / $impulse;
            }
            $x = [];
            foreach ($row['x'] as $name => $v) {
                $x[$name] = ($v - $paths[0][$q]['x'][$name]) / $impulse;
            }
            $response[] = ['gap' => ($row['gap'] - $paths[0][$q]['gap']) / $impulse, 'c' => $c, 'x' => $x];
        }
        file_put_contents((string) getenv('OUT'), json_encode($response));
        $this->assertNotEmpty($response);
    }
}
