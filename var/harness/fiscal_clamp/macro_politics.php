<?php
// Arm B: macro + politics only (no board, no fund, no equity cap so the equity-wealth loop is off), modelled on
// polls/lever_means.php. php macro_politics.php <seed> <years> <out.jsonl>: one clamp_row a quarter.
require dirname(__DIR__, 3) . '/tests/bootstrap.php';
require __DIR__ . '/clamp_row.php';

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

[$script, $seed, $years, $out] = $argv;
$seed = (int) $seed;
$tpy = 48;
@unlink($out);
$fh = fopen($out, 'w');
mt_srand($seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
$politics = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
$policy = $politics->liveState()->policy();
for ($i = 1; $i <= (int) $years * $tpy; ++$i) {
    $macro = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
    $m = $politics->updatePolitics($macro, 1.0 / $tpy);
    $policy = $m->policy();
    if ($i % 12 === 0) {
        fwrite($fh, json_encode(fiscalClampRow($macro, $seed, $i / $tpy)) . "\n");
    }
}
fclose($fh);
