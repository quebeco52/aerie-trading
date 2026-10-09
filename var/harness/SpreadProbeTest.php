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
 * 2s10s slope distribution on YieldCurveRealismTest's harness (40y, 5y burn, 120 tpy) for any seed list.
 * Env: SEEDS (comma list) OUT. Writes per-tick spread, policy, 2y, 10y and the policy target, quarterly.
 */
final class SpreadProbeTest extends TestCase
{
    public function testRun(): void
    {
        $tpy = 120;
        $rows = [];
        foreach (explode(',', (string) getenv('SEEDS')) as $seed) {
            mt_srand((int) $seed);
            $math = new MathUtility();
            $redis = new class extends \Redis {
                /** @var array<string, string> */
                private array $store = [];
                public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
                public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
            };
            $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
                new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
            for ($tick = 0; $tick < 40 * $tpy; $tick++) {
                $m = $engine->updateMacroState(1.0 / $tpy);
                if ($tick >= 5 * $tpy) {
                    $rows[] = [(int) $seed, $m->yield10y - $m->yield2y, $m->policyRate, $m->yield2y, $m->yield10y, $m->targetRate, $m->outputGap, $m->naturalRate, $m->inflation];
                }
            }
        }
        file_put_contents((string) getenv('OUT'), json_encode($rows));
        $this->assertNotEmpty($rows);
    }
}
