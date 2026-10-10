<?php
// php crisis_probe.php new|off <seed> [years=100] [tpy=252]: bare macro exactly as BusinessCycleRealismTest::simulateGapPath
// builds it; writes runs_macro/<arm>-<seed>.jsonl: one 'q' row a quarter (gap, gross debt, tax) and one 'c' row per crisis tick.
// off: rescues zeroed via bankRescueShock() = -INF (exp(-INF) = 0); the rescue's own keyed stream keeps paths identical until then.
require dirname(__DIR__, 3) . '/tests/bootstrap.php';
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
$arm = $argv[1];
$seed = (int) $argv[2];
$years = (int) ($argv[3] ?? 100);
$tpy = (int) ($argv[4] ?? 252);
mt_srand($seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$credit = $arm === 'off'
    ? new class($math) extends CreditFiscalSubsystem { protected function bankRescueShock(MacroState $state): float { return -INF; } }
    : new CreditFiscalSubsystem($math);
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), $credit);
@mkdir(__DIR__ . '/runs_macro');
$out = fopen(__DIR__ . "/runs_macro/$arm-$seed.jsonl", 'w');
$lastCrisis = -1.0;
$every = intdiv($tpy, 4);
for ($i = 1; $i <= $years * $tpy; ++$i) {
    $m = $engine->updateMacroState(1.0 / $tpy);
    if ($m->lastCreditCrisisAt !== $lastCrisis) {
        $lastCrisis = $m->lastCreditCrisisAt;
        fwrite($out, json_encode(['k' => 'c', 't' => $i / $tpy, 'rescue' => $m->bankRescueCostToGdp, 'cgap' => $m->creditToGdpGapEma, 'debt' => $m->sovereignDebtToGdp]) . "\n");
    }
    if ($i % $every === 0) {
        fwrite($out, json_encode(['k' => 'q', 't' => $i / $tpy, 'gap' => $m->outputGap, 'debt' => $m->sovereignDebtToGdp, 'tax' => $m->corporateTaxRate]) . "\n");
    }
}
fclose($out);
