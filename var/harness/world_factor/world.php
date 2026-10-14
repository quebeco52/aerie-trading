<?php
// Macro loop alone (no board, no fund flows), politics beside it: one row a simulated month with the world demand
// gaps and the commodity and freight prices they clear, for the world-activity factor fit.
// php world.php <seed> <years> <tpy> <out.jsonl>. BASE = another tree (HEAD), OVR = dir of src-relative class copies.
require (getenv('BASE') ?: dirname(__DIR__, 3)) . '/tests/bootstrap.php';
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
$month = intdiv($tpy, 12);
for ($i = 1; $i <= (int) round((float) $years * $tpy); ++$i) {
    $m = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
    $policy = $politics->updatePolitics($m, 1.0 / $tpy)->policy();
    if ($i % $month === 0) {
        fwrite($fh, json_encode([
            't' => $i / $tpy, 'og' => $m->outputGap, 'fg' => $m->foreignOutputGap, 'wg' => $m->worldActivityGap ?? null,
            'gg' => $m->globalDemandGapEma, 'metals' => $m->industrialMetalsIndex, 'oil' => $m->energyPriceIndex,
            'freight' => $m->freightRateIndex, 'sc' => $m->supplyChainPressureIndex, 'sce' => $m->supplyChainPressureIndexEma,
            'infl' => $m->inflation, 'cg' => $m->coreGoodsInflation, 'ppi' => $m->producerPriceInflation,
        ]) . "\n");
    }
}
