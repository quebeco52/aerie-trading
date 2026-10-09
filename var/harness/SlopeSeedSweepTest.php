<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/** Per-seed mean 2s10s slope with the power draw on vs stubbed out (the pre-power RNG sequence), YieldCurveRealismTest's setup. */
final class SlopeSeedSweepTest extends TestCase
{
    public function testSweep(): void
    {
        $seeds = array_merge([20260909, 20260918, 4711], range(1, (int) (getenv("NSEEDS") ?: 9)));
        $dt = 1.0 / 252;
        $out = [];
        foreach (['power' => false, 'nopower' => true] as $arm => $stubbed) {
            foreach ($seeds as $seed) {
                mt_srand($seed);
                $math = new MathUtility();
                $commodity = $stubbed
                    ? new class ($math) extends CommodityLogisticsSubsystem { public function calculateWholesalePowerIndex(MacroState $state, float $dt): void {} }
                    : new CommodityLogisticsSubsystem($math);
                $engine = new MacroEngine($math, $this->redis(), new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
                    new MacroAggregateSubsystem($math), $commodity, new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
                $sum = 0.0; $n = 0;
                for ($tick = 0; $tick < 40 * 252; $tick++) {
                    $m = $engine->updateMacroState($dt);
                    if ($tick >= 5 * 252) { $sum += $m->yield10y - $m->yield2y; $n++; }
                }
                $out[$arm][$seed] = $sum / $n;
            }
        }
        foreach ($out as $arm => $bySeed) {
            $v = array_values($bySeed);
            $mean = array_sum($v) / count($v);
            $sd = sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $v)) / (count($v) - 1));
            fwrite(STDERR, sprintf("%-8s test-seeds pooled %.4f | all: mean %.4f sd %.4f | per seed %s\n", $arm,
                array_sum(array_slice($v, 0, 3)) / 3, $mean, $sd, implode(' ', array_map(fn ($x) => sprintf('%.4f', $x), $v))));
        }
        $this->assertTrue(true);
    }

    private function redis(): \Redis
    {
        return new class extends \Redis {
            private array $store = [];
            public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
        };
    }
}
