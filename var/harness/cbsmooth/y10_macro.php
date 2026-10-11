<?php
// Macro loop alone (no board, no fund), politics beside it, at a chosen tick rate: does the breakeven's variance depend
// on the step? php y10_macro.php <seed> <years> <tpy> <out.jsonl>: one row a quarter (be10, noncore, endpoint gap, y10, tp10).
require (getenv('BASE') ?: dirname(__DIR__, 3)) . '/tests/bootstrap.php';
// A BASE tree shares this checkout's vendor through a symlink, whose composer map resolves App\\ to THIS src: put the
// tree's own classes in front (as var/harness/bootstrap.php does), then the OVR copies in front of those.
if (getenv('BASE')) {
    spl_autoload_register(static function (string $class): void {
        $file = getenv('BASE') . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (str_starts_with($class, 'App\\') && is_file($file)) { require $file; }
    }, true, true);
}
if (getenv('OVR')) {
    spl_autoload_register(static function (string $class): void {
        $file = getenv('OVR') . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (str_starts_with($class, 'App\\') && is_file($file)) { require $file; }
    }, true, true);
}

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

[$script, $seed, $years, $tpy, $out] = $argv;
$tpy = (int) $tpy;
mt_srand((int) $seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
$politics = new PoliticsEngine(MathUtility::ownStream((int) $seed), $redis);
$policy = $politics->liveState()->policy();
$fh = fopen($out, 'w');
$quarter = intdiv($tpy, 4);
for ($i = 1; $i <= (int) round((float) $years * $tpy); ++$i) {
    $m = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
    $policy = $politics->updatePolitics($m, 1.0 / $tpy)->policy();
    if ($i % $quarter === 0) {
        fwrite($fh, json_encode(['t' => $i / $tpy, 'be10' => $m->tipsBreakeven, 'noncore' => $m->noncorePassThroughEma, 'infl' => $m->inflation,
            'endpoint_gap' => property_exists($m, 'inflationEndpointGap') ? $m->inflationEndpointGap : 0.0, 'y10' => $m->yield10y, 'tp10' => $m->termPremium10y,
            'gap' => $m->outputGap, 'policy' => $m->policyRate, 'oil' => $m->energyPriceIndex,
            'rn10' => $m->riskNeutral10y, 'ig' => $m->macroCreditSpread, 'crisis_at' => $m->lastCreditCrisisAt]) . "\n");
    }
}
