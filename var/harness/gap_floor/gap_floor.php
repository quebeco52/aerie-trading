<?php
// How often the output-gap bounds bind: macro + politics loop (no equity cap, so no equity-wealth loop), the engine's
// own OutputGapProbe for the clamp. php gap_floor.php <seed> <years> <tpy> <out.jsonl>: one line per quarter.
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

[$script, $seed, $years, $tpy, $out] = $argv;
$seed = (int) $seed; $tpy = (int) $tpy; $q = intdiv($tpy, 4);
mt_srand($seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$probe = new OutputGapProbe();
$probe->enable();
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math, $probe), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
$politics = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
$policy = $politics->liveState()->policy();
$fh = fopen($out, 'w');
$prevClamp = 0.0; $minPre = INF; $maxPre = -INF; $up = 0.0; $down = 0.0; $minDisaster = 0.0;
for ($i = 1; $i <= (int) $years * $tpy; ++$i) {
    $m = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
    $policy = $politics->updatePolitics($m, 1.0 / $tpy)->policy();
    $cur = $probe->snapshot()['current'];
    $c = $cur === null ? 0.0 : (float) $cur['clamp'];
    $d = $c - $prevClamp; $prevClamp = $c;
    $pre = $m->outputGap - $d;
    $minPre = min($minPre, $pre); $maxPre = max($maxPre, $pre);
    if ($d > 0) { $up += $d; } else { $down += $d; }
    $minDisaster = min($minDisaster, $m->demandDisasterShock);
    if ($i % $q === 0) {
        fwrite($fh, json_encode(['seed' => $seed, 't' => $i / $tpy, 'gap' => $m->outputGap, 'floor' => $up, 'ceil' => $down,
            'minpre' => $minPre, 'maxpre' => $maxPre, 'crisis_at' => $m->lastCreditCrisisAt, 'crisis_drag' => $m->creditCrisisDrag,
            'disaster' => $minDisaster]) . "\n");
        $probe->rollWindow(); $prevClamp = 0.0; $minPre = INF; $maxPre = -INF; $up = 0.0; $down = 0.0; $minDisaster = 0.0;
    }
}
fclose($fh);
